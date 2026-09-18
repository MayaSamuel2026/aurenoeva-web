# AURENOEVA V7.4.1 — NOEVA CORE Binding Adapter Qualification

**Date:** 2026-09-18  
**Production:** Aurenoeva V7.4.1 production 9a8f3c93091e1fdf0f7c8bfc5f1af799b0d0cd6e  
**Result:** ADAPTER/CONTRACT PASS · EXTERNAL CORE ORIGIN NOT YET CONFIGURED

## Implemented

### 1. Server-side CORE bridge
Production now contains:
- `_core_bridge.php`
- `core-health.php`
- `catalogue-search.php` bound through the bridge
- `requirement-submit.php` with Requirement Contract v1 and CORE ingress
- cache-busted V7.4.1 JS/CSS

The bridge supports:
- `AURENOEVA_CORE_BASE_URL`
- exact health/search/requirement endpoint overrides
- Bearer token or X-NOEVA-API-Key server-side auth
- public-safe product normalization
- fail-closed catalogue semantics
- requirement binding response normalization

No secret is stored in Git.

### 2. Canonical catalogue path
The public browser remains same-origin:
`browser → aurenoeva.com/catalogue-search.php → NOEVA CORE`.

The bridge can consume either:
- ranked search results; or
- a canonical catalogue projection such as `/api/catalogue`, with exact identifier/alias/model filtering performed only in the public adapter.

The bridge returns only public-safe canonical identity fields.

### 3. Requirement Contract v1
A successful website enquiry now receives both:
- public enquiry reference `AUR-...`
- requirement record `AUR-REQ-...`

The Requirement Contract contains:
- buyer organisation/contact;
- requirement/constraints/evidence needs;
- raw customer identifier;
- selected canonical products;
- canonical authority declarations;
- deterministic idempotency key;
- downstream orchestration intent.

### 4. Correct Project 50 → 80 → 90 boundary
At raw enquiry:
- Project 50 / CORE Operations: `CREATE_OR_BIND_CASE`
- Project 80 / CORE Compliance: `DEFER_UNTIL_TRANSACTION_CONTEXT`
- Project 90 / CORE Transactions: `DEFER_UNTIL_ACQUISITION_ROUTE`

This is intentional. A raw website enquiry is not prematurely converted into a compliance clearance or canonical transaction.

### 5. Deterministic contract qualification
GitHub Actions runs a local mock NOEVA CORE and proves:
- CORE health mapping: PASS
- canonical exact part search: PASS
- canonical ID preservation: PASS
- part number preservation: PASS
- manufacturer preservation: PASS
- alias → canonical part resolution: PASS
- no-match does not fabricate product: PASS
- Requirement Contract ingress: PASS
- Project 50 case ID mapping: PASS
- party ID mapping: PASS
- Project 80 remains NOT_EVALUATED at raw enquiry: PASS
- Project 90 remains NOT_CREATED before route/roles: PASS
- no premature transaction ID: PASS

### 6. Live production qualification
Live V7.4.1 test submission:
- identifier: `6ES7331-1KF02-0AB0`
- public reference: `AUR-20260918-115348-DB8A75`
- requirement record: `AUR-REQ-20260918-115348-DB8A75`
- submission: PASS
- no catalogue product fabricated while CORE unconfigured: PASS

## Current live binding state

`GET https://aurenoeva.com/core-health.php` reports:
- adapter live: YES
- CORE configured: NO
- CORE reachable: NO

`GET https://aurenoeva.com/catalogue-search.php?q=6ES7331-1KF02-0AB0` reports:
- source: NOEVA_CORE
- binding: UNCONFIGURED
- available: false
- synthetic items: none

This is correct fail-closed behavior.

## Infrastructure discovery

No current public NOEVA CORE hostname/path was discovered on the known public NOEVA domains.

Public DNS evidence:
- ReplaceSense resolves to a VPS address, but no public NOEVA CORE reverse-proxy path was found.
- Aurenoeva remains on its Hostinger web-hosting addresses.

Read-only Hostinger hPanel discovery could not proceed because the automation session is not authenticated.

The existing project evidence also says the canonical NOEVA CORE runtime is local/host-bound and physical live binding/retirement must not be claimed until a public/runtime endpoint is proven.

## Remaining external runtime gate

To move from **adapter-qualified** to **LIVE_CORE_BOUND**, one real CORE origin must be made reachable from the Aurenoeva Hostinger PHP runtime and configured with:

```
AURENOEVA_CORE_BASE_URL=<real CORE origin>
```

plus server-side auth if required.

Then the following can be qualified without further website redesign:
1. real Aurenoeva canonical product returned;
2. selected canonical ID/part number reaches Requirement Contract;
3. real Project 50 Operations case returned;
4. later acquisition route references real Project 80 applicability manifest;
5. Project 90 canonical transaction created only once supplier, roles and commercial route exist.

Legacy Aurenoeva compute remains protected and is NOT eligible for retirement yet.
