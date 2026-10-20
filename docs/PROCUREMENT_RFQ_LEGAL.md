# AURENOEVA — customer upload, RFQ legality, and direct buyer–vendor brokerage

October 2026. App-owned, no CORE dependencies or new subscriptions.

## Implemented now (source)

* Reuse `tools/procurement_intake.py` (PR #9) for Excel/CSV import including alternatives.
* `tools/procurement_inbox.py` creates idempotent private case drafts and attached legal-review/RFQ drafts from an existing private incoming-files directory; independent of any public catalogue or CRM gateway.
* `tools/procurement_rfq.py` creates evidence-required pre-RFQ checks, preserves the buyer–vendor contract roles, and **never emails** a supplier or represents an export licence/clearance.
* `tests/test_procurement_rfq.py` covers holds/blocks, evidence freshness, direct buyer–vendor roles and repeated import.

## App operating sequence (existing Hostinger VPS)

In a **private** directory outside any web document root, e.g. paths selected by the system administrator, place a customer request XLSX or CSV. The input filename does not define the end-user, origin or legality. Run:

```bash
python3 tools/procurement_inbox.py \
  --inbox /private/aurenoeva/inbox/uz \
  --private-dir /private/aurenoeva/case-data \
  --country UZ
```

Results are stored privately under `case-data/cases/` and `case-data/rfq-drafts/`. No customer document is uploaded to GitHub. Matching source hashes are idempotent and will not duplicate a case. The same command may be executed from an **existing Hostinger timer** if configured; no new GitHub Actions scheduler is required. The result **is not a live CRM import**, nor is it supplier outreach. Authorised integration with the existing AURENOEVA case backend is still needed.

For one existing case, load verified legal evidence and optionally a supplier identity:

```bash
python3 tools/procurement_rfq.py /private/aurenoeva/case-data/cases/CASE.json \
  --evidence /private/aurenoeva/evidence/CASE.json \
  --supplier /private/aurenoeva/suppliers/vendor.json \
  --output /private/aurenoeva/case-data/rfq-drafts/CASE.json
```

`CLEAR_FOR_RFQ` only means that the preliminary documented checks have passed for **requesting information**. It does not release sales, payments, shipment or procurement. Supplier-send and pricing remain explicit human actions. The system does not currently provide live automated restricted-party-list matching: screening evidence must be independently acquired, dated, and reviewed before marking a check verified.

## Preliminary legal checks (for each actual transaction)

- Identify seller, importer, purchasing entity, beneficial owners, hospital/end-user and the exact installation address and final end-use.
- Validate equipment classification (including relevant export and dual-use control entries), origin jurisdiction, any third-country transfer, sanctions restrictions and contractual re-export conditions.
- Obtain and document fresh restricted-party screening results; if a prohibition, denial or likely sanctions diversion is found, block. **No transshipment, sham alternate buyer, relabelling, or false declared destination to evade controls.**
- For applicable Uzbekistan medical equipment, verify model-specific registration/authorisations and in-country installation/service responsibilities.
- A genuine alternative product, authorised exception or export licence **may** be a lawful route only if independently evaluated and documented for the real goods, parties and end-use. Never auto-assert that a "medical" product is exempt.
- Where the legal position is uncertain, request a binding review by BAFA/appropriate competent authority or qualified counsel. Fresh check again before contract/shipment.

Sources (verify current consolidated text for any transaction):

* EU dual-use Regulation 2021/821: https://eur-lex.europa.eu/eli/reg/2021/821/oj
* EU restrictive measures and due diligence: https://finance.ec.europa.eu/eu-and-world/sanctions-restrictive-measures/overview-sanctions-and-related-resources_en
* EU third-country diversion red flags: https://finance.ec.europa.eu/publications/guidance-due-diligence_en
* Regulation 833/2014 Article 12g, where applicable: https://eur-lex.europa.eu/eli/reg/2014/833/2026-07-24/eng
* Uzbekistan medical device registration Resolution 738, 2025: https://lex.uz/en/docs/7861699?ONDATE=26.02.2026

## Contracting and fees

The supplier issues the sale contract, equipment invoice, warranties and shipping documents to the buyer/importer directly, subject to relevant export law. Customer pays supplier; AURENOEVA is separately retained as **disclosed sourcing agent/broker**, with fee billed per broker agreement to the party agreed in that document. The brokerage fee does not give AURENOEVA title to goods or custody of customer purchase money.

**Brokerage does not remove the German/EU broker's own export-control, sanctions, or intermediary-specific obligations.**

## Next runtime binding (not yet deployed)

An authenticated AURENOEVA administration page should accept XLSX/CSV files, preview the normalized case/options, store evidence in the existing case authority (not a second CRM) and expose the RFQ/legal review result. Do not publish the private inbox, file data, supplier contacts or economics under the public website. Deploy only with a verified private data directory, proper app-auth, current backup and real write/readback test. This source change is deliberately not an automatic public-site deployment.
