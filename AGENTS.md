# NOEVA architectural instruction — CORE retirement (15 October 2026)

This is a source-repository instruction for anyone (including AI-assisted development chats) working on this project.

**Owner direction: permanently retire the central NOEVA CORE system, not application independence.** This overrides older CORE-first / Forgejo-first / Google Cloud-first design plans and unfinished central release-controller requirements.

Canonical decision: https://github.com/MayaSamuel2026/noeva-systems-web/issues/204
Programme master: https://github.com/MayaSamuel2026/noeva-systems-web/blob/main/docs/NOEVA_SIMPLIFICATION_CANONICAL_MASTER.md

- Build, maintain, test and deploy each application independently. Never block VANTAGE or another app merely because CORE, its signing hierarchy, a release registry, Forgejo, or Google Cloud is unfinished.
- Preferred stack: ChatGPT for assisted engineering, GitHub Free for useful source control and automation, Hostinger for hosting; no new paid service without approval. No replacement CORE 2.0 platform or mandatory centralized bureaucracy.
- Retain and relocate valuable technology/code, customer records, employee identity, application configuration, contacts, historical research and existing datasets. Avoid unnecessary new repositories and duplicate workflows.
- Before live technical changes, establish a verified current known-good baseline, a usable independent recovery copy and a tested rollback route. Keep least-privilege authentication, privacy, functional checks and proportionate automated tests. Do not overwrite newer CRM/customer records by rolling back code.
- Existing production dependencies must be mapped and migrated before stopping a live service, deleting credentials, archiving shared code or removing production data. A textual CORE reference is not proof that a production process still depends on it.
- A green source-only test, GitHub PR, staging mock or release request is not a live deployment. Claim production success only on verified actual functionality and recoverability.
- GitHub baseline source snapshot: `recovery/pre-core-retirement-20261015`. This Git ref is **not** a substitute for a independently restorable Hostinger/database backup.

**Repository-specific preservation:** Preserve the current qualified website and Hostinger publish branch. The existing .github/workflows/publish.yml still assembles a historic CORE bridge: replace that bridge with an independent tested site-owned adapter before turning off the relevant service.
