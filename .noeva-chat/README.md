# NOEVA Chat-to-GitHub Execution (2026-10-09)

**Common gateway installed and canary-qualified** on this repository. All NOEVA project chats with the connected GitHub tool use the same entry point: one new immutable `.noeva-chat/requests/<unique-id>.json` on `main` in its own commit, triggering `.github/workflows/noeva-chat-github-gateway-v1.yml`.

See the master protocol and evidence: https://github.com/MayaSamuel2026/noeva-core/blob/main/docs/NOEVA_GITHUB_CHAT_GATEWAY_RESTORED_20261009.md

This repository: `MayaSamuel2026/aurenoeva-web`.

aurenoeva-release publisher is allowlisted but current source, backup and release contract need verification before actual promotion.

**Request example:**

```json
{
  "schema": "noeva-chat-github-gateway/1.0",
  "request_id": "unique-canary-id-20261009",
  "repository": "MayaSamuel2026/aurenoeva-web",
  "action": "canary"
}
```

Only approved workflow routes may be dispatched. No arbitrary remote shell. A green gateway indicates **transport only**. Read the downstream workflow result and actual production smoke/rollback receipts before claiming deployment. Today's project order: VANTAGE, AEGIS bilingual website, Canadian hospital enrichment; defer other releases.
