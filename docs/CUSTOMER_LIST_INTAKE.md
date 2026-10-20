# AURENOEVA — customer procurement-list intake

This is an app-owned, local-only intake converter, not a second CRM and not a public catalogue writer. It implements the spreadsheet preparation step for the existing AURENOEVA Requirement → Case → Case Item → RFQ model.

Supported input: XLSX or CSV, including numbered requirements and unnumbered alternative options. Python standard library only; no new subscription, API, scheduler, database, or GitHub workflow.

Example command:

    python3 tools/procurement_intake.py /private/customer-request.xlsx \
      --case AUR-UZ-20261020-001 --country UZ \
      --output /private/imports/AUR-UZ-20261020-001.json

Test command:

    python3 -m unittest discover -s tests -p 'test_procurement_intake.py'

The JSON has a stable source SHA-256, a user-supplied case reference, unresolved line items, alternative groups, requested quantities or null, and an explicit PREPARED_NOT_COMMITTED state. Later live intake can detect duplicate submissions by case reference and source SHA. An alternative is not an additional ordered quantity.

Never commit or upload customer names, attachments, pricing, or source files to this public repository. Store them under the private application storage.

## Next native AURENOEVA integration

In the authenticated internal case administration screen: Import customer list → upload XLSX/CSV → server-side conversion and preview → optional reviewer corrections → atomic case and request-line creation in the existing AURENOEVA case authority, with original-document hash and evidence. Reject idempotency conflicts. Do not create dummy canonical products from incomplete buyer descriptions. Only link verified product IDs. Maintain the public Coming Soon landing page independently.

PDF ingestion may be added later via document extraction and review, but the converter does not claim PDF support.

Supplier discovery and watch can run from the existing Hostinger server. Supplier RFQ dispatch and customer quotation approval must remain controlled human actions. Public catalogue remains free of prices, vendor URLs and margins.

On 2026-10-20, the Uzbekistan customer source sheet was tested: 39 numbered requirements, 44 equipment options, including five alternatives. The actual file and private prepared import payload must remain outside the source repository. This change does not deploy an upload page or make a live database write.
