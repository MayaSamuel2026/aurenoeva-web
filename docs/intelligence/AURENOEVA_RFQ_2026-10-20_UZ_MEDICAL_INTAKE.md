# Aurenoeva — customer-demand priority batch (20 October 2026)

Status: **INTAKE STAGED — NOT A VERIFIED CATALOGUE IMPORT OR AN ACTIVE CRAWL**.
Scope: customer RFQ for medical-equipment procurement in Uzbekistan.
Original supporting attachment: `4_5870938376273666035.xlsx` (uploaded in the Aurenoeva project conversation on 2026-10-20). The original workbook is the source of truth; obtain the original row data before writing any item-level catalogue records.

## Source reconciliation
- 39 numbered requirements, **44 equipment entries including alternatives**.
- Sectors seen in the RFQ: MRI, radiology/X-ray, ultrasound, dental, endoscopy, ophthalmology, sterilization, anaesthesia, hospital infrastructure.
- At intake, supplier quotes confirmed: **0**. No stock, availability, manufacturer match, price, lead time or device registration is implied.
- Evidence leads already identified in initial research: manufacturers Mindray, Anthos and Haag-Streit; a local Uzbekistan listing for **Mindray WATO EX-35** by **Intermed Innovation (Tashkent)**. The listing is an *unverified sourcing lead*, not confirmed inventory or a quotation. Four local supplier leads were noted in the prior research, but full identities/evidence must be loaded from the original research notes, not invented.

## Existing crawler/source lookup priority
1. Parse original workbook preserving its 44 separate equipment rows, item identifiers, Russian technical specifications, alternatives and row references; store RFQ demand separately from validated catalogue products.
2. Match exact manufacturer+model/MPN first; do not conflate alternatives. Prioritise WATO EX-35 lead verification and other manufacturer-named equipment.
3. Search manufacturer documentation, authorized distributors and Uzbekistan-specific suppliers using the existing Aurenoeva-owned service when its production credentials and run path are verified.
4. Keep provenance: manufacturer/model, official specification URL, seller/company URL, source URL, observed UTC time, jurisdiction, evidence quality and match classification.
5. Queue RFQ requests for supplier quotation, with fields for RUB equipment price, installation cost, delivery lead time, warranty/service, local medical-device registration status, export/import compliance and verified stock.
6. Deduplicate by normalized maker + model/MPN + variant, retain every original request row as its own demand record, and never treat an unquoted lead as a sellable offer.
7. Mark records as **internal/unverified** until checked. Public website must not display prices; supplier economics/margins remain internal.
8. Publish to an authenticated catalogue only after checking actual running backend and safe write interface. Do not fabricate 44 records from this metadata alone.

## Operational prerequisite
The current website `catalogue-search.php` still calls a legacy CORE adapter which returns `available=false` if its search URL is unconfigured or unreachable. Rebind the *existing* catalogue/crawler read interface to the Aurenoeva-owned authority; then execute the existing Hostinger-hosted crawler and set bounded overnight refresh using the established VPS timer/cron. Never create a duplicate CORE dependency or duplicate crawler.

## Readiness criteria
- Server crawler start receipt (run ID, start time, source set); at least one completed write with canonical product ID and provenance.
- One scheduled overnight invocation plus last-run status, dedupe counts, failed-source count and next scheduled run.
- Public search returns genuine matching products or a visible no-result/unavailable state.
- Customer RFQ rows safely imported with exact references; no customer personal details in public repository or public site.
