> **15 October 2026 architecture update:** Central NOEVA CORE is being retired ([decision](https://github.com/MayaSamuel2026/noeva-systems-web/issues/204)). This website's current publishing source still includes a CORE bridge for catalogue and requirements. Preserve the current qualified website and migrate those operations to an independent Aurenoeva-owned backend before removing the bridge. No CORE-first release approval is required for unrelated website updates.

# Aurenoeva website deployment source

Canonical release path: `main` -> GitHub Actions qualification -> clean `production` -> Hostinger Git auto-deploy -> `public_html` -> live qualification.

The `site/` directory is the approved Aurenoeva V7 runtime payload. The production branch is generated automatically and contains website runtime files only.
