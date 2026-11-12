#!/usr/bin/env python3
"""Qualified self-update of AURENOEVA's existing catalogue crawler.

Invoked before each scheduled run. NEVER changes SQLite products, RFQ cases,
mail credentials, systemd units or the website. A failed update preserves
the current crawler; each fetched source is pinned to one GitHub commit SHA.
"""
from __future__ import annotations

import json
import os
import pwd
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
from datetime import datetime, timezone
from urllib.parse import quote
from urllib.request import Request, urlopen

REPO = "MayaSamuel2026/aurenoeva-web"
API = f"https://api.github.com/repos/{REPO}"
RAW = f"https://raw.githubusercontent.com/{REPO}"
APP = Path("/opt/aurenoeva")
STATE = Path("/var/lib/aurenoeva")
GITLIGHT_RECEIPT = Path("/var/lib/gitlight/logs/auto-aurenoeva-catalogue-tests-latest.json")
FILES = ("tools/catalogue_harvester.py", "tools/catalogue_publish.py",
         "tools/catalogue_prices.py", "tools/catalogue_classification.py",
         "tools/catalogue_auto_update.py", "config/catalogue_harvester.json")
TESTS = ("tests/test_catalogue_classification.py", "tests/test_catalogue_prices.py", "tests/test_catalogue_harvester.py", "tests/test_catalogue_publish.py",
         "tests/test_catalogue_auto_update.py", "tests/test_catalogue_export_cadence.py", "tests/test_catalogue_lifecycle.py", "tests/test_catalogue_onergys_coverage.py", "tests/test_catalogue_telemetry.py",
         "hotfix/catalogue-discovery/catalogue-discovery.js")
SHA_PATTERN = re.compile(r"[a-f0-9]{40}\Z")


def get_bytes(url: str, limit: int = 2_000_000) -> bytes:
    req = Request(url, headers={
        "User-Agent": "AurenoevaQualifiedVPSUpdater/1.0",
        "Accept": "application/vnd.github+json"
                  if url.startswith(API) else "application/octet-stream",
    })
    with urlopen(req, timeout=22) as response:
        data = response.read(limit + 1)
    if not data or len(data) > limit:
        raise ValueError("invalid_or_oversized_release_asset")
    return data


def get_json(url: str) -> dict:
    obj = json.loads(get_bytes(url, limit=1_000_000))
    if not isinstance(obj, dict):
        raise ValueError("invalid_github_api_response")
    return obj


def gitlight_qualified_sha() -> str | None:
    """Accept only the isolated, revision-pinned local GitLight test receipt.

    A GitLight PASS is not a production release by itself: source is still
    fetched by pinned SHA, tested again under the unprivileged VPS account,
    and compared against the installed revision to prevent downgrades.
    """
    path = GITLIGHT_RECEIPT
    try:
        if path.is_symlink() or not path.is_file():
            return None
        st = path.stat()
        if st.st_uid != pwd.getpwnam("gitlight").pw_uid or st.st_mode & 0o077:
            return None
        if not 50 <= st.st_size <= 8192:
            return None
        record = json.loads(path.read_text(encoding="utf-8"))
        if (record.get("job") != "aurenoeva-catalogue-tests"
            or record.get("repo") != REPO
            or record.get("status") != "PASS"
            or record.get("exit_code") != 0):
            return None
        sha = record.get("revision")
        digest = record.get("log_sha256")
        if (not isinstance(sha, str) or not SHA_PATTERN.fullmatch(sha)
            or not isinstance(digest, str)
            or re.fullmatch(r"[a-f0-9]{64}", digest) is None):
            return None
        checked = datetime.fromisoformat(str(record.get("observed_at", "")))
        if checked.tzinfo is None or checked.utcoffset() is None:
            return None
        return sha
    except (OSError, ValueError, TypeError, KeyError, json.JSONDecodeError):
        return None


def qualified_sha() -> str | None:
    """Choose the newest proven forward revision from GitHub CI or GitLight.

    The GitLight receipt unblocks qualification when hosted Actions are queued,
    but it cannot bypass per-revision staging tests or downgrade protection.
    Never use an unqualified main branch HEAD.
    """
    installed_sha = None
    try:
        previous = (STATE / "catalogue-qualified-revision.sha").read_text().strip()
        if SHA_PATTERN.fullmatch(previous):
            installed_sha = previous
    except (OSError, UnicodeError):
        pass
    receipt_sha = None
    try:
        release = get_bytes(f"{RAW}/production/VERSION.txt", limit=512).decode("utf-8")
        matched = re.fullmatch(
            r"Aurenoeva V[^\n]+ production ([a-f0-9]{40})\s*", release)
        if matched:
            receipt_sha = matched.group(1)
    except (OSError, UnicodeError, ValueError):
        pass

    action_sha = None
    try:
        runs = get_json(
            f"{API}/actions/workflows/procurement-private-qualification.yml/"
            "runs?branch=main&per_page=20")
        for record in runs.get("workflow_runs", []):
            if (isinstance(record, dict)
                and record.get("name") == "AURENOEVA private procurement qualification"
                and record.get("head_branch") == "main"
                and record.get("event") == "push"
                and record.get("status") == "completed"
                and record.get("conclusion") == "success"):
                candidate = record.get("head_sha", "")
                if isinstance(candidate, str) and SHA_PATTERN.fullmatch(candidate):
                    action_sha = candidate
                    break
    except (OSError, ValueError, KeyError, TypeError):
        pass

    qualified = (action_sha, gitlight_qualified_sha())
    current = installed_sha or receipt_sha
    improved = False
    for candidate in qualified:
        if not candidate or candidate == current:
            continue
        if current:
            try:
                relation = get_json(f"{API}/compare/{current}...{candidate}")
            except (OSError, ValueError, KeyError, TypeError):
                continue
            if relation.get("status") not in ("ahead", "identical"):
                continue
        current = candidate
        improved = True
    if improved:
        return current
    if installed_sha:
        return installed_sha
    if receipt_sha:
        return receipt_sha
    print("CATALOGUE_UPDATE_DEFERRED=NO_FORWARD_QUALIFIED_RELEASE",
          flush=True)
    return None

def stage_revision(sha: str, work: Path) -> None:
    if not SHA_PATTERN.fullmatch(sha):
        raise ValueError("revision_not_pinned")
    work.chmod(0o755)
    for name in FILES + TESTS:
        dest = work / name
        dest.parent.mkdir(parents=True, exist_ok=True)
        # Every intermediate directory must be traversable by the unprivileged
        # service account running the staged qualification tests.
        parent = dest.parent
        while parent != work:
            parent.chmod(0o755)
            parent = parent.parent
        dest.write_bytes(get_bytes(f"{RAW}/{sha}/{quote(name, safe='/')}"))
        dest.chmod(0o644)
    cfg = json.loads((work / "config/catalogue_harvester.json").read_text())
    if (not isinstance(cfg.get("sources"), list) or
        not isinstance(cfg.get("categories"), dict) or
        sum(len(v) for v in cfg["categories"].values()) < 49):
        raise ValueError("catalogue_configuration_incomplete")
    for name in ("tools/catalogue_prices.py", "tools/catalogue_classification.py", "tools/catalogue_harvester.py", "tools/catalogue_publish.py"):
        compile((work / name).read_bytes(), name, "exec")
    result = subprocess.run(
        ["/usr/sbin/runuser", "-u", "aurenoeva", "--", "/usr/bin/python3",
         "-B", "-m", "unittest", "discover", "-s", "tests",
         "-p", "test_catalogue_*.py", "-v"],
        cwd=work, capture_output=True, text=True, timeout=100, check=False,
        env={"PATH": "/usr/bin:/bin", "PYTHONDONTWRITEBYTECODE": "1",
             "HOME": "/tmp", "PYTHONPATH": str(work / "tools")})
    if result.returncode:
        print((result.stdout + "\n" + result.stderr)[-4500:], flush=True)
        raise RuntimeError("qualified_revision_local_regression_failed")
    print("CATALOGUE_UPDATE_TESTS=PASS", flush=True)


def atomic_write(dest: Path, data: bytes) -> None:
    fd, temp = tempfile.mkstemp(prefix=".catalogue-update-", dir=dest.parent)
    try:
        with os.fdopen(fd, "wb") as out:
            out.write(data)
            out.flush()
            os.fsync(out.fileno())
        os.chmod(temp, 0o644)
        os.replace(temp, dest)
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


def install_revision(sha: str, work: Path, app: Path = APP,
                     state: Path = STATE) -> None:
    files = [(work / name, app / name, name) for name in FILES]
    if not (state / "catalogue.sqlite3").is_file():
        raise RuntimeError("preserved_database_missing")
    if any((dest.exists() and not dest.is_file()) or dest.is_symlink()
           or (not dest.exists() and name not in ("tools/catalogue_prices.py","tools/catalogue_classification.py"))
           for _, dest, name in files):
        raise RuntimeError("unexpected_installed_file_type")
    versions = state / "catalogue-code-releases"
    versions.mkdir(mode=0o700, parents=True, exist_ok=True)
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    backup = versions / ("release-" + stamp + "-" + sha[:10])
    backup.mkdir(mode=0o700)
    for _, dest, name in files:
        path = backup / name
        path.parent.mkdir(parents=True, exist_ok=True)
        if dest.is_file():
            shutil.copy2(dest, path)
            path.chmod(0o600)
    installed = []
    try:
        for candidate, dest, name in files:
            atomic_write(dest, candidate.read_bytes())
            installed.append((dest, backup / name))
        receipt = state / "catalogue-qualified-revision.sha"
        fd, tmp = tempfile.mkstemp(prefix=".revision-", dir=state)
        try:
            with os.fdopen(fd, "w", encoding="ascii") as out:
                out.write(sha + "\n")
                out.flush()
                os.fsync(out.fileno())
            os.chmod(tmp, 0o600)
            os.replace(tmp, receipt)
        finally:
            if os.path.exists(tmp):
                os.unlink(tmp)
    except Exception:
        for dest, old in reversed(installed):
            if old.is_file():
                atomic_write(dest, old.read_bytes())
            else:
                dest.unlink(missing_ok=True)
        print("CATALOGUE_UPDATE_ROLLBACK=PASS", flush=True)
        raise
    entries = sorted((p for p in versions.iterdir()
        if p.is_dir() and p.name.startswith("release-")), reverse=True)
    for item in entries[5:]:
        shutil.rmtree(item)
    print("CATALOGUE_AUTO_UPDATE=INSTALLED sha=" + sha[:12], flush=True)


def main() -> int:
    if os.geteuid() != 0:
        raise PermissionError("catalogue_updater_requires_root_prestart")
    if not (APP / "tools/catalogue_harvester.py").is_file():
        raise RuntimeError("existing_crawler_not_installed")
    sha = qualified_sha()
    if sha is None:
        return 0
    receipt = STATE / "catalogue-qualified-revision.sha"
    if receipt.is_file() and receipt.read_text().strip() == sha:
        print("CATALOGUE_AUTO_UPDATE=UNCHANGED sha=" + sha[:12], flush=True)
        return 0
    with tempfile.TemporaryDirectory(prefix="catalogue-stage-") as tmp:
        stage = Path(tmp)
        stage_revision(sha, stage)
        install_revision(sha, stage)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print("CATALOGUE_AUTO_UPDATE=DEFERRED " + type(exc).__name__ +
              ": " + str(exc)[:150], flush=True)
        raise SystemExit(1)
