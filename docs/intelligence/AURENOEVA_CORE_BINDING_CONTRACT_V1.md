# Aurenoeva → NOEVA CORE Binding Contract v1

**Status:** IMPLEMENTED AT AURENOEVA ADAPTER LAYER  
**Version:** 1.0  
**Date:** 2026-09-18

## Purpose

This contract binds the finished Aurenoeva public procurement journey to canonical NOEVA CORE authorities without duplicating shared logic in the website.

The intended path is:

```
Aurenoeva public website
  → server-side Aurenoeva CORE bridge
  → CORE canonical catalogue/search
  → selected canonical product(s)
  → Aurenoeva Requirement Contract
  → CORE Operations / Project 50 case
  → CORE Compliance / Project 80 applicability (when sufficient context exists)
  → CORE Transactions / Project 90 canonical transaction (after an acquisition route and roles exist)
```

## Separation of authority

The PHP website adapter owns only:
- browser-safe search adaptation;
- requirement-contract construction;
- transport to CORE;
- graceful fail-closed behaviour;
- operational email fallback.

It does **not** own:
- canonical product authority;
- search index authority;
- identity/relationship graph;
- generic cases/work queues;
- compliance rules;
- transaction lifecycle;
- settlement ledger.

Those remain CORE authorities.

## Runtime configuration

Preferred configuration:

```text
AURENOEVA_CORE_BASE_URL=https://<core-origin>
AURENOEVA_CORE_API_TOKEN=<server-side secret>
```

Default paths when only a base URL is supplied:

```text
health      /api/health
catalogue   /api/catalogue
requirement /api/public/v1/aurenoeva/requirements
```

Every endpoint can be overridden independently:

```text
AURENOEVA_CORE_HEALTH_URL=
AURENOEVA_CORE_SEARCH_URL=
AURENOEVA_CORE_REQUIREMENT_URL=
```

or by path:

```text
AURENOEVA_CORE_HEALTH_PATH=
AURENOEVA_CORE_CATALOGUE_PATH=
AURENOEVA_CORE_REQUIREMENT_PATH=
```

Optional API-key header:

```text
AURENOEVA_CORE_API_KEY=
```

Secrets must be configured server-side and must never be committed to Git.

## Public search contract

The website calls same-origin:

```
GET /catalogue-search.php?q=<identifier>&industry=<optional>&category=<optional>
```

The bridge calls CORE server-side. It supports either:
- a ranked search response; or
- a full canonical catalogue response such as `/api/catalogue`, which the public adapter filters by exact part number, aliases, model, manufacturer/name and then returns only safe fields.

Safe output fields:
- canonical_id
- manufacturer_id
- manufacturer
- part_number
- model
- description
- identifier_type
- lifecycle_status
- relationship_type
- confidence
- source_ref

Supplier-private data, offers, internal evidence payloads, credentials and administrative fields are not exposed by this adapter.

If CORE is not configured/reachable, the response is `available:false`; no synthetic result is created.

## Requirement Contract v1

On submission the website creates one structured envelope:

```json
{
  "schema": "aurenoeva-requirement-contract/1.0",
  "contract_id": "AUR-REQ-...",
  "reference": "AUR-...",
  "vertical": "AURENOEVA",
  "source": "PUBLIC_WEBSITE",
  "customer": {
    "organisation_name": "...",
    "contact_name": "...",
    "business_email": "..."
  },
  "requirement": {
    "starting_mode": "exact",
    "industry": "...",
    "category": "...",
    "raw_identifier": "...",
    "description": "...",
    "condition": "...",
    "quantity": "...",
    "delivery_location": "...",
    "need_by": "...",
    "budget": "...",
    "evidence_requested": []
  },
  "selected_products": [],
  "authority": {
    "catalogue": "NOEVA_CORE_CANONICAL_PRODUCTS",
    "operations": "NOEVA_CORE_OPERATIONS_PROJECT50",
    "compliance": "NOEVA_CORE_COMPLIANCE_PROJECT80",
    "transactions": "NOEVA_CORE_TRANSACTIONS_PROJECT90"
  },
  "orchestration": {
    "operations": {"action": "CREATE_OR_BIND_CASE"},
    "compliance": {"action": "DEFER_UNTIL_TRANSACTION_CONTEXT"},
    "transaction": {"action": "DEFER_UNTIL_ACQUISITION_ROUTE"}
  },
  "idempotency_key": "aurenoeva:<sha256>"
}
```

## Correct lifecycle boundary

A public enquiry is **not yet a Project 90 transaction**.

At public submission:
1. Requirement Contract exists.
2. Project 50 / CORE Operations should create or bind the buyer/organisation and procurement case.
3. Search-selected canonical products are attached without re-entry.
4. Project 80 remains `NOT_EVALUATED` until the product/party/jurisdiction/end-use/route context is sufficient.
5. Project 90 remains `NOT_CREATED` until an acquisition route, supplier, buyer/seller/broker roles and commercial context exist.

This prevents the website from manufacturing a transaction or compliance clearance prematurely.

## CORE ingress response

The adapter accepts these safe bindings when returned by CORE:

```json
{
  "status": "BOUND",
  "requirement_id": "...",
  "party_id": "...",
  "case_id": "...",
  "applicability_manifest_ref": null,
  "compliance_state": "NOT_EVALUATED",
  "transaction_id": null,
  "transaction_state": "NOT_CREATED"
}
```

Nested `case`, `operations`, `compliance` and `transaction` response objects are also normalized.

## Failure semantics

- Catalogue unavailable: manual requirement path remains open.
- CORE requirement ingress unavailable: website email fallback remains authoritative for receipt; the customer receives the normal Aurenoeva reference.
- If CORE accepted the Requirement Contract but notification email fails, the customer submission remains accepted.
- If both CORE and email delivery fail, submission fails visibly.
- No Project 80 clearance or Project 90 transaction ID is fabricated.
- No legacy compute is retired by this binding package.

## Qualification required before declaring LIVE_CORE_BOUND

1. CORE public health reachable from Hostinger.
2. Known canonical Aurenoeva part returns from live search.
3. Alias/partial search does not misidentify parts.
4. Selected canonical ID reaches Requirement Contract unchanged.
5. CORE returns a real Operations case ID.
6. Requirement/part binding is visible from CORE Operations.
7. Compliance stays deferred at raw enquiry stage.
8. Later qualified acquisition route can reference a real versioned applicability manifest.
9. Project 90 transaction is created only after supplier/roles/commercial route are defined.
10. Aurenoeva legacy compute remains untouched until all application-specific binding gates pass.
