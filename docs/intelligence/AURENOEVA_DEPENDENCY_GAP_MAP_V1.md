# AURENOEVA — Canonical Dependency & Gap Map

**Version:** Intelligence reconciliation v1.0  
**Date:** 2026-09-18  
**Scope:** Aurenoeva Project 10 + NOEVA CORE + Projects 50 / 80 / 90 + current public website/runtime  
**Status:** CANONICAL WORKING MAP

## 1. Reconciliation rule

The current authority order is:

1. NOEVA Master Context (2026-09-18) and frozen CORE OS architecture.
2. NOEVA CORE OS v1.2 completion/authority registry.
3. Frozen/qualified Project 80 and Project 90 baselines.
4. Frozen Aurenoeva Project 10 procurement baselines.
5. Current qualified Aurenoeva production website/runtime.
6. Historical RCs and prototypes only as lineage/evidence.

Historical material may explain how a capability evolved, but it must not override a later accepted authority.

## 2. Canonical ownership

| Capability | Canonical owner | Aurenoeva role | Current authority | Public consequence |
|---|---|---|---|---|
| Requirement semantics / Requirement Lens | Aurenoeva | Owns procurement-domain requirement model and UX | Frozen Project 10 | Public requirement flow may evolve without rebuilding shared infrastructure |
| Discovery / crawl / source discovery | NOEVA CORE | Configure technical markets and source policies | CORE v1.2 | Website must consume CORE results, not a separate crawler |
| Watch / freshness / change detection | NOEVA CORE | Configure demand priority and materiality | CORE v1.2 | A live requirement may trigger priority watch; website does not implement watch itself |
| Extraction / evidence capture | NOEVA CORE | Define component/supplier domain fields and evidence posture | CORE v1.2 | Product and offer facts shown to users must retain provenance |
| Canonical entities / products / IDs | NOEVA CORE | Add Aurenoeva domain extensions | CORE v1.2 | Selected product identity must persist without re-entry |
| Shared search / index | NOEVA CORE | Aurenoeva public-safe adapter | CORE v1.2 | Search box must query a public-safe CORE gateway |
| Product identity resolution | CORE generic authority + Aurenoeva domain rules | Configure part-number/manufacturer semantics | Aurenoeva resolution lineage + CORE | Free text must not replace a resolved canonical part |
| Evidence Passport / procurement evidence UX | Aurenoeva | Owns vertical presentation/rules | Project 10 frozen | Evidence remains attached to the same candidate |
| Decision / acquisition intelligence | Shared CORE decision framework + Aurenoeva rules | Owns technical procurement scoring/conditions | Project 10 + CORE | Fit, evidence, risk and economics remain inspectable |
| Party / Relationship 360 | CORE Operations / Project 50 | Consume party IDs and relationship context | Source authority in CORE; Project 50 operational closure still open | Do not claim a fully closed CRM runtime until separately proven |
| Case / work queue / waiting / approvals / communications | CORE Operations / Project 50 | Configure procurement cases/work items | CORE authority; Project 50 current implementation still open | Enquiry-to-case binding is a remaining runtime gap |
| Consent / communications guardrails | CORE shared service / Operations | Vertical copy/policy | CORE authority | Website may collect required enquiry consent; deeper CRM consent stays shared |
| Compliance / applicability | CORE Compliance / Project 80 | Supply transaction/product/jurisdiction context | Versioned applicability authority | Website may explain the process; runtime must consume the authoritative manifest |
| Canonical transaction | CORE Transactions / Project 90 | Configure brokerage semantics | Project 90 CLOSED/FROZEN | Transaction becomes system-of-record after an acquisition route is formed |
| Role Manifest | CORE Transactions / Project 90 | Aurenoeva = broker unless explicitly documented otherwise | Frozen | Never silently make Aurenoeva seller/title holder/importer/exporter/warranty provider/funds custodian |
| Contract versions | CORE Transactions / Project 90 | Use transaction-specific documents | Frozen | Commercial roles/terms are explicit per transaction |
| Settlement / funding / exceptions | CORE Transactions / Project 90 | Orchestrate permitted brokerage flow | Frozen | Expected vs actual settlement and exceptions remain ledgered |
| Release / qualification / deployment | CORE release/qualification + Git deployment standard | Vertical release configuration | Frozen deployment standard | main → qualification → production → Hostinger |

## 3. Reconciled Aurenoeva lifecycle

The complete customer/domain chain is:

**Need / identifier**  
→ **CORE catalogue search**  
→ **canonical product candidate(s)**  
→ **customer-selected part(s)**  
→ **Requirement Contract**  
→ **supplier discovery / watch**  
→ **identity resolution**  
→ **Evidence Passport**  
→ **risk / economics / Acquisition Workspace**  
→ **decision with conditions**  
→ **Project 80 applicability / clearance**  
→ **Project 90 canonical transaction + Role Manifest**  
→ **contract versions / funding conditions**  
→ **inspection / release / dispatch / delivery / acceptance**  
→ **settlement ledger / exception recovery**  
→ **closed acquisition record / learning**

### Identity invariant

Once a catalogue candidate is selected, the following facts travel forward without re-entry:

- canonical_product_id
- manufacturer_id (when available)
- manufacturer_name
- part_number
- model
- identifier_type
- description
- lifecycle_status
- relationship_type
- match_confidence
- evidence/source reference
- original_search_query
- selected_at

A raw text identifier remains as the customer's input, but it must not replace an already resolved canonical identity.

## 4. Project 50 dependency

CORE v1.2 absorbs generic Relationship 360, cases, work queues, waiting, approvals, consent, communications and improvement trials into shared Operations. The current master context still records CRM & Shared Operations as not fully closed.

**Rule:** the website may create a procurement enquiry and preserve identifiers now. It must not claim that the full Relationship 360 / case / communications runtime is production-bound until the Project 50 live path is separately proven.

**Gap:** enquiry → canonical party/case/work-item creation remains to be bound.

## 5. Project 80 dependency

Project 80 is represented in CORE as a versioned applicability authority with scope, version, market, rules, evidence and publication state.

**Runtime rule:** a real transaction consumes the actual authoritative Transaction Applicability Manifest applicable to that transaction.

**Public UX rule:** explain that relevant product, party, country, end-use, documentary and transaction conditions are checked before the transaction advances. Do not present a generic legal guarantee or hard-coded universal clearance.

**Gap:** public website currently describes evidence/risk but under-explains compliance/applicability and does not expose the later clearance stage in the journey.

## 6. Project 90 dependency

Project 90 is closed/frozen. Canonical TRANSACTION is the system-of-record. Frozen controls include:

- 13-state lifecycle;
- Transaction Role Manifest;
- three-document contract architecture;
- Project 80 applicability dependency;
- expected-vs-actual settlement;
- state-driven payout authority;
- append-only settlement ledger;
- recoverable exception model;
- evidence receipts.

**Public UX rule:** communicate the post-decision phases in buyer language: **Clear → Agree → Fund → Fulfil → Close**.

**Boundary rule:** Aurenoeva does not silently take title, hold inventory, become importer/exporter, assume seller warranty or receive seller funds.

**Gap:** public website currently stops too early and does not explain the controlled transaction/fulfilment path.

## 7. Search-to-enquiry gap

### Current production state before V7.3

- Homepage search accepts a part/model/manufacturer string.
- The requirement wizard receives that string as a free-text identifier.
- The review screen and PHP handler carry the text value.
- No canonical product selection is represented.
- No list of selected part numbers/canonical IDs is sent with the enquiry.

### Required V7.3 state

1. Public search calls a public-safe catalogue gateway.
2. Results are normalized to a minimal public contract.
3. The customer may add one or more matching parts to the requirement.
4. Selected parts are held separately from the raw search text.
5. Review and final enquiry show the selected manufacturer/part number/model.
6. Submission includes structured selected-part JSON plus a readable part list.
7. If no catalogue match is returned, the user can continue with the identifier/description.
8. No fake/static catalogue result is used to imply live CORE binding.

## 8. Public-safe catalogue contract

Request:

`GET catalogue-search.php?q=<query>&industry=<optional>&category=<optional>`

Public response:

```json
{
  "ok": true,
  "available": true,
  "items": [
    {
      "canonical_id": "…",
      "manufacturer_id": "…",
      "manufacturer": "…",
      "part_number": "…",
      "model": "…",
      "description": "…",
      "identifier_type": "MPN",
      "lifecycle_status": "…",
      "relationship_type": "EXACT",
      "confidence": 0.99,
      "source_ref": "…"
    }
  ]
}
```

The gateway exposes no supplier-private data, internal evidence payloads, operational tokens or administrative fields.

## 9. Gap register

| ID | Gap | Severity | Owner | V7.3 action |
|---|---|---|---|---|
| G01 | Public search does not query canonical CORE products | HIGH | CORE binding + Aurenoeva adapter | Add public-safe gateway contract |
| G02 | Selected canonical part IDs/numbers are lost | HIGH | Aurenoeva UX/data contract | Add multi-part selection tray and structured submission |
| G03 | Homepage implies search but primarily prefills form | HIGH | Aurenoeva UX | Make exact identifier mode a real catalogue-search surface |
| G04 | Requirement review only shows one free-text identifier | HIGH | Aurenoeva UX | Show selected products plus raw input |
| G05 | Enquiry email does not carry canonical identities | HIGH | Aurenoeva submission | Add structured + readable selected parts |
| G06 | Public journey stops before compliance/transaction completion | MEDIUM | Aurenoeva content | Add Clear → Agree → Fund → Fulfil → Close |
| G07 | Project 80 is not visible in customer journey | MEDIUM | Aurenoeva content | Explain case-dependent compliance/applicability clearance |
| G08 | Project 90 is not visible in customer journey | MEDIUM | Aurenoeva content | Explain controlled transaction/contract/settlement/fulfilment |
| G09 | Enquiry → Project 50 canonical case is not proven | MEDIUM | CORE Operations binding | Preserve future contract; do not claim closed/live |
| G10 | Public CORE catalogue endpoint not proven live | BLOCKING for live results | CORE live binding | Configure AURENOEVA_CORE_SEARCH_URL and qualify end-to-end |
| G11 | Aurenoeva legacy compute retirement not yet eligible | GUARDED | CORE live binding | Retire only after public/runtime binding qualification |

## 10. V7.3 acceptance gates

- Current accepted visual/brand system preserved.
- Exact-part search can display only real API-returned catalogue candidates.
- Multiple parts can be added/removed.
- Selected canonical IDs/part numbers survive page transition and language switch.
- Requirement preview includes selected parts.
- PHP submission includes selected parts.
- No-result / unavailable gateway still allows manual enquiry.
- Public journey visibly includes compliance and transaction completion stages.
- Project 80/90 roles are described without changing legal role boundaries.
- Existing contact and requirement submission continue to work.
- No new generic discovery/search/compliance/transaction engine is created inside the website.
- Legacy compute is not retired by this website release.

