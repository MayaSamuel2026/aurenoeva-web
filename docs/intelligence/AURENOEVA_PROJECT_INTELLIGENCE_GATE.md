# Aurenoeva Project Intelligence Gate

Status: ACTIVE
Scope: Aurenoeva + NOEVA CORE dependencies
Purpose: prevent website/runtime work from ignoring accepted project architecture, frozen baselines, shared CORE authorities, or downstream compliance/transaction requirements.

## Mandatory retrieval scope

Before any material Aurenoeva architecture, runtime, data-model or customer-journey change, consult:

1. Aurenoeva Project 10 material
   - Local Intelligence Lab
   - Resolution migration
   - Requirement Contract
   - Procurement Decision Journey
   - Acquisition Workspace / Supplier Engagement
   - current website/runtime implementation
2. NOEVA CORE
   - current master context
   - current CORE completion / closure report
   - shared authority registry
   - source/runtime inventory
   - live binding state
3. Shared/downstream authorities when relevant
   - Project 50 / Operations & CRM
   - Project 80 / Compliance & Applicability
   - Project 90 / Transaction, Contract & Settlement
   - Project 70 / Vertical Engine
   - Project 95 / shared market mechanics
4. Current production source
   - GitHub main
   - qualified production branch
   - live website/runtime

## Authority order

When sources conflict, use this order:

1. Current canonical master context and current frozen CORE architecture
2. Final closure / completion / integrated qualification evidence
3. Accepted frozen Aurenoeva baselines
4. Current qualified runtime / production source
5. Historical drafts, logs, RCs and superseded experiments

Historical material may explain lineage but must not silently override a later accepted authority.

## Extraction model

The intelligence pass must extract and reconcile:

- purpose / project boundary
- frozen decisions
- no-regression constraints
- ownership of each capability
- canonical entities and IDs
- lifecycle/state models
- accepted interfaces
- data contracts
- authority / source-of-truth
- dependencies between Aurenoeva and CORE
- Project 80 compliance implications
- Project 90 transaction implications
- Project 50 operations implications
- live-binding state
- unresolved blockers
- user-facing consequences
- deployment / qualification rules

## Hard architecture constraints

- Shared capabilities belong to NOEVA CORE.
- Aurenoeva may configure/adapt CORE capabilities but must not create a second generic implementation.
- Discovery, crawl, watch, change detection, extraction, evidence, canonical product/entity indexing, shared search, compliance/applicability and canonical transaction/settlement primitives are CORE authorities.
- Aurenoeva owns procurement-domain semantics, rules, customer experience, brokerage configuration and vertical-specific adapters.
- Do not retire legacy runtime until live binding is proven end-to-end.
- Do not redesign an accepted/frozen interface unless integrated evidence shows a regression.
- Migration is additive/non-destructive until parity is proven.

## Aurenoeva customer-journey invariant

The selected canonical product identity must persist without re-entry:

Search / Requirement
→ canonical product candidate(s)
→ selected part number(s)
→ Requirement Contract
→ sourcing / supplier engagement
→ Evidence Passport
→ Acquisition Workspace
→ compliance/applicability
→ canonical transaction / contract / settlement
→ fulfilment / acquisition record

A free-text identifier alone is not sufficient when CORE has already resolved a canonical product.

## Project 80 invariant

Compliance/applicability is a shared CORE authority.
Aurenoeva must consume the versioned applicability result / manifest.
It must not duplicate regulatory logic or silently convert UNKNOWN, stale, conflicted or unverified states into CLEAR.

## Project 90 invariant

The canonical TRANSACTION is the system-of-record object.
Role, compliance-manifest reference, contract versions, expected/actual settlement, exceptions and append-only settlement evidence remain explicit.
Aurenoeva must not silently become seller, title holder, importer/exporter, warranty provider, inventory holder or holder of seller funds.

## Intelligence Gate before implementation

For each material change:

1. SCAN — retrieve current Aurenoeva + CORE + dependent authority material.
2. RECONCILE — resolve newer vs older authority and identify conflicts.
3. MAP — map the requested change to canonical entities, lifecycle and owners.
4. IMPACT — identify affected UI, API, data, compliance, transaction and deployment surfaces.
5. IMPLEMENT — change only the correct owner layer.
6. QUALIFY — run deterministic regression / binding / production checks.
7. RECORD — update the current canonical state, not historical drafts.

No Aurenoeva V7.x/V8 runtime feature should bypass this gate.
