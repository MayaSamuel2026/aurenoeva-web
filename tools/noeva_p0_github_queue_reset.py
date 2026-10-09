#!/usr/bin/env python3
"""P0 one-time, scoped GitHub CI/deployment freeze. Never touches CORE or production."""
from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

API = "https://api.github.com"
STOPPED = {"queued", "in_progress", "waiting", "requested", "pending"}
# Safety first: protect background acquisition, backup and site-health pipelines.
PROTECTED = re.compile(
    r"backup|restore|crawler|crawl[-_ ]|discovery|acquisition|watchdog|"
    r"monitor|heartbeat|health[-_ ]|scheduled[-_ ]?enrich|portfolio[-_ ]sync",
    re.I,
)
# Restrict the freeze to explicitly identifiable engineering work.
DEVELOPMENT = re.compile(
    r"qualif|(?:^|[-_ ])ci(?:[-_ .]|$)|build|test|deploy|release|staging|"
    r"candidate|bootstrap|migrat|reconcil|diagnos|source[-_ ]promotion|"
    r"bridge[-_ ]activation|frontend[-_ ]update|integration[-_ ]test",
    re.I,
)
SELF_NAME = "noeva-p0-queue-reset-20261009.yml"


def call(method: str, path: str, token: str) -> dict:
    req = urllib.request.Request(
        API + path,
        method=method,
        headers={
            "Authorization": "Bearer " + token,
            "Accept": "application/vnd.github+json",
            "X-GitHub-Api-Version": "2022-11-28",
            "User-Agent": "NOEVA-P0-Queue-Reset-20261009",
        },
    )
    try:
        with urllib.request.urlopen(req, timeout=35) as response:
            raw = response.read()
            return json.loads(raw) if raw else {}
    except urllib.error.HTTPError as exc:
        payload = exc.read().decode("utf-8", "replace")[:400]
        raise RuntimeError(f"github_api_error:{method}:{path}:http={exc.code}:{payload}") from None


def paginated(path: str, key: str, token: str, max_pages: int = 25) -> list[dict]:
    out: list[dict] = []
    for page in range(1, max_pages + 1):
        sep = "&" if "?" in path else "?"
        data = call("GET", f"{path}{sep}per_page=100&page={page}", token)
        items = data.get(key, [])
        if not isinstance(items, list):
            raise RuntimeError(f"api_result_invalid:{key}")
        out.extend(x for x in items if isinstance(x, dict))
        if len(items) < 100:
            return out
    raise RuntimeError("pagination_exceeds_safe_limit")


def active_runs(base: str, token: str) -> list[dict]:
    """Read only nonterminal runs; never paginate the repository's full historical run archive."""
    result: dict[int, dict] = {}
    for state in ("queued", "in_progress", "waiting", "requested", "pending"):
        for run in paginated(base + "/actions/runs?status=" + state, "workflow_runs", token,
                             max_pages=25):
            if "id" in run:
                result[int(run["id"])] = run
    return list(result.values())


def classify(workflow: dict) -> str:
    name = str(workflow.get("name") or "")
    path = str(workflow.get("path") or "")
    if not path.startswith(".github/workflows/") or not path.endswith((".yml", ".yaml")):
        return "PRESERVE_UNRECOGNIZED"
    if path.endswith(SELF_NAME):
        return "PRESERVE_RESET_CONTROLLER"
    identity = name + " " + path
    if PROTECTED.search(identity):
        return "PRESERVE_OPERATIONS"
    if DEVELOPMENT.search(identity):
        return "FREEZE_DEVELOPMENT"
    return "PRESERVE_UNCLASSIFIED"


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true")
    parser.add_argument("--receipt", default="p0-github-queue-reset-receipt.json")
    options = parser.parse_args()
    token = os.environ.get("GH_TOKEN", "")
    repository = os.environ.get("GITHUB_REPOSITORY", "")
    own_run = str(os.environ.get("GITHUB_RUN_ID", ""))
    if not token or not re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repository):
        raise RuntimeError("missing_github_actions_auth_or_repository")
    base = "/repos/" + repository
    workflows = paginated(base + "/actions/workflows", "workflows", token)
    active = [w for w in workflows if str(w.get("state") or "") == "active"]
    by_class: dict[str, list[dict]] = {}
    for w in active:
        by_class.setdefault(classify(w), []).append(w)
    selected = by_class.get("FREEZE_DEVELOPMENT", [])
    # Idempotent replay is allowed: a prior successful freeze leaves no selected active workflows.
    if not selected and not workflows:
        raise RuntimeError("no_workflows_visible:fail_closed")
    if len(selected) > 240:
        raise RuntimeError("unexpectedly_large_freeze_set:fail_closed")
    # Gather run IDs before mutation, but re-list after freezing too.
    runs_before = active_runs(base, token)
    targeted = {int(w["id"]) for w in selected}
    receipt = {
        "schema": "noeva-p0-github-queue-reset/1.0",
        "at": dt.datetime.now(dt.timezone.utc).isoformat(),
        "repository": repository,
        "mode": "APPLY" if options.apply else "PREVIEW",
        "protected_categories": sorted(
            {k for k in by_class if k != "FREEZE_DEVELOPMENT"}
        ),
        "scanned_active_workflows": len(active),
        "selected_workflow_count": len(selected),
        "preserved_workflow_count": len(active) - len(selected),
        "selected_workflows": [
            {"id": int(w["id"]), "path": w["path"], "name": w["name"]}
            for w in selected
        ],
        "disabled": [],
        "cancelled": [],
        "errors": [],
        "protected_live_applications_untouched": True,
        "core_queue_mutated": False,
    }
    path = Path(options.receipt)

    def save() -> None:
        path.write_text(json.dumps(receipt, indent=2, sort_keys=True) + "\n", encoding="utf-8")

    save()
    if not options.apply:
        print(json.dumps({"mode": "PREVIEW", "selected": len(selected),
                          "protected": receipt["preserved_workflow_count"]}))
        return 0
    # Freeze development intake before cancelling existing runs.
    for w in selected:
        try:
            call("PUT", f"{base}/actions/workflows/{int(w['id'])}/disable", token)
            receipt["disabled"].append({"id": int(w["id"]), "path": w["path"]})
        except Exception as exc:
            receipt["errors"].append({"workflow": w.get("path"), "error": str(exc)})
        save()
    # We do not cancel anything whose workflow was not demonstrably disabled.
    disabled_ids = {item["id"] for item in receipt["disabled"]}
    runs_after = active_runs(base, token)
    seen: set[int] = set()
    for run in runs_before + runs_after:
        try:
            run_id = int(run["id"])
            workflow_id = int(run.get("workflow_id") or 0)
        except (TypeError, ValueError, KeyError):
            continue
        if run_id in seen or str(run_id) == own_run:
            continue
        seen.add(run_id)
        if workflow_id not in targeted or workflow_id not in disabled_ids:
            continue
        if str(run.get("status") or "") not in STOPPED:
            continue
        try:
            call("POST", f"{base}/actions/runs/{run_id}/cancel", token)
            receipt["cancelled"].append(
                {"run_id": run_id, "workflow_id": workflow_id, "prior_state": run.get("status")}
            )
        except Exception as exc:
            receipt["errors"].append({"run_id": run_id, "error": str(exc)})
        save()
    receipt["finished_at"] = dt.datetime.now(dt.timezone.utc).isoformat()
    save()
    print(json.dumps({
        "mode": "APPLY",
        "workflows_disabled": len(receipt["disabled"]),
        "development_runs_cancellation_requested": len(receipt["cancelled"]),
        "preserved_workflows": receipt["preserved_workflow_count"],
        "errors": len(receipt["errors"]),
        "receipt": str(path),
        "note": "Run cancellation API acceptance is not terminal state; verify on readback.",
    }))
    return 1 if receipt["errors"] else 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"P0_QUEUE_RESET_FAILED: {type(exc).__name__}:{exc}", file=sys.stderr)
        raise SystemExit(1)
