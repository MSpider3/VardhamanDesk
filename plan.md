# Plan: Implement Milestone 2 — Clients and Invoices

## Problem
Milestone 1 — Leads + Users is complete, committed, and marked complete in `docs/06-milestones-and-deliverables.md`. The application now has Laravel 13, Filament 5, Pest, role-based authorization, Lead ownership scopes/policies, Lead Notes, Dashboard v1, seed data, and a passing Milestone-1 test suite.

The next approved scope is **Milestone 2 — Clients + Invoices**. It must add the client lifecycle, GST-aware draft invoice creation, and safe Draft → Sent invoice finalization while preserving the existing ownership model and without implementing Milestone 3 Payments, PDF generation, or dashboard financial metrics.

## Proposed Solution

### 1. Follow only the active `docs/` contract
- Read `docs/AGENT.md` and `docs/01` through `docs/06` before editing.
- Treat `docs/05-assumptions-and-open-questions.md` as the source of truth if another active document conflicts.
- Preserve existing Lead functionality and tests. Do not redesign already-working Milestone-1 behavior unless a compatibility issue is demonstrated with a failing test.
- Do not expand scope into Payments, PDF output, emailing, credit notes/cancellation, e-invoicing/IRN, multi-company, multi-GSTIN, or Dashboard v2.
- Implement new rules through migrations, models, policies/scopes, dedicated actions/services, Filament resources, seeders/factories, and Pest tests—not merely through UI visibility.

### 2. Establish shared enums, state data, and fixed precision
- Add PHP enums/value objects where they reduce duplicated string logic:
  - Client/Indian state representation with official two-digit GST state codes.
  - Invoice status: `draft`, `sent`, `partially_paid`, `paid`.
- Use a single authoritative India States/UTs mapping for all Client forms and GSTIN validation; `clients.state` and invoice `place_of_supply` must be validated values from this mapping, never arbitrary free text.
- Use `DECIMAL` database columns and string/decimal-safe arithmetic throughout. Do not use PHP floats for quantity, rate, tax, or totals.
- Define the rounding convention in the implementation and tests before writing calculations: calculate each line’s taxable amount and tax components using decimal-safe math, round the persisted line monetary components to two decimal places, then aggregate those persisted values into invoice totals. This prevents database totals and later PDFs from disagreeing.

### 3. Add Client persistence, relationships, authorization, and the Filament resource
Create a new migration/model/factory/seeder/policy/scope/resource for `clients`, with the documented schema:

- `lead_id` nullable foreign key to `leads`;
- `assigned_to` required foreign key to `users`, the **current** Client owner;
- `created_by` required foreign key to `users`, immutable audit creator;
- client contact fields: name, nullable company/phone/email, billing address, state, nullable 15-character GSTIN;
- timestamps and soft deletes.

Enforce these workflows:
- **Lead conversion:** only a Qualified Lead may convert. Use a transaction that creates exactly one Client, sets `lead_id`, defaults the Client’s `assigned_to` from the Lead, records `created_by`, and changes the Lead to `converted`. Reject repeat conversion and roll back both records on failure.
- **Direct Client:** Admin and Sales may create Clients without a Lead. For Sales, server-side creation must assign the Client to the authenticated Sales user; Admin can choose an assignee.
- **Client ownership:** a Client’s owner is always `clients.assigned_to`, never inferred from a Lead. Sales can only see/create/edit their own Clients; Admin has global access and can reassign any Client independently of the source Lead.
- **Lead Notes on Client:** expose the originating Lead’s existing Notes read-only on the Client view when `lead_id` is present. Do not copy or duplicate note records.
- Enforce the same defense-in-depth pattern as Milestone 1: Eloquent ownership scope, Laravel policy, and Filament capabilities/actions.

### 4. Implement GSTIN validation as a reusable server-side rule
- GSTIN is optional. When supplied, normalize case/whitespace as appropriate and validate the standard 15-character format.
- Validate the GSTIN’s first two characters against the selected Client state’s GST code.
- Checksum validation is explicitly out of scope for v1; do not claim it is implemented.
- Apply validation to direct Client creation, Lead conversion, Client edits, and seeded/factory data.
- Add tests for blank GSTIN acceptance, valid format/state-code acceptance, malformed GSTIN rejection, and state-code mismatch rejection.

### 5. Add reference/configuration tables and seed data
Create migrations/models/factories/seeders for:

1. **`gst_rates`**
   - decimal `rate`, display `label`, `is_active` boolean, timestamps;
   - seed active rates exactly as the specification requires: `0.00`, `5.00`, `18.00`, and `40.00`;
   - historical invoice items retain their rate reference even when a rate becomes inactive; inactive rates must not be selectable for new invoice lines.

2. **`company_settings`**
   - one application/company record with name, address, GSTIN, PAN, state, state code, optional logo path, bank account name/number/IFSC/bank name, authorized signatory, and timestamps;
   - seed a Rajasthan placeholder record with safe fake values only. Never hard-code its values across Services, Resources, or later PDF views;
   - treat this single row as the source for the supplier state used in tax classification.

3. **`financial_year_counters`**
   - `financial_year` unique/primary identifier and unsigned `last_sequence`;
   - this table is accessed only by the invoice-number allocator under a database transaction and row lock.

### 6. Add Invoice and Invoice Item persistence with draft-only editing
Create migrations/models/factories/policies/scopes/Filament resources or pages for `invoices` and `invoice_items`:

**Invoice fields**
- required Client and immutable `created_by` relationship;
- nullable unique `invoice_number`, nullable `financial_year`, nullable `sequence_number`—all must remain null for Drafts;
- snapshot `place_of_supply` copied from the Client billing state at invoice creation (a later Client edit must not rewrite an invoice);
- status default `draft`;
- required `invoice_date` and required `due_date`, defaulting to `invoice_date + 30 days` but editable while Draft;
- persisted decimal `subtotal`, `cgst_amount`, `sgst_amount`, `igst_amount`, and `total`;
- no invoice soft-delete design that could permit hiding a Sent/Partially Paid/Paid invoice. A Draft may be deleted; all post-Sent statuses are never deletable.

**Invoice-item fields**
- description, required SAC code, decimal quantity, decimal unit rate, persisted amount;
- required `gst_rate_id` foreign key;
- persisted per-line CGST, SGST, and IGST amounts.

**Draft user experience and server-side restrictions**
- Only Admin or the Client’s assigned Sales owner can create/view/edit a Client’s Draft Invoice.
- The create/edit flow must support multiple line items with active GST-rate selection, a mandatory SAC, and a live calculated preview; all submitted tax values are ignored/recalculated on the server.
- Drafts show `DRAFT` instead of an invoice number and permit editing of client, date, due date, place of supply snapshot, and line items only while status is `draft`.
- Keep calculation, Draft mutation, and line-item persistence in dedicated invoice action/service classes inside database transactions. Do not bury the financial logic solely in Filament schema callbacks.
- On every Draft create/update, recompute per-line tax and aggregate invoice totals from server-trusted quantity, rate, rate record, and place-of-supply data.

### 7. Implement GST classification and calculations
- Read the supplier state from the `company_settings` row, not a duplicated constant. The milestone seed is Rajasthan.
- Determine tax mode by comparing the Invoice’s snapshotted `place_of_supply` with the supplier state:
  - same state: intra-state; split each line’s GST evenly into CGST and SGST, with IGST zero;
  - different state: inter-state; apply the full line GST as IGST, with CGST/SGST zero.
- Rate is per invoice item via `gst_rate_id`; a mixed-rate invoice must be correct line-by-line before totals are aggregated.
- Formula sequence:
  1. `amount = quantity × rate`;
  2. calculate that line’s rate-based tax using the defined rounding convention;
  3. populate the applicable CGST/SGST or IGST fields;
  4. sum persisted line amount/taxes into the Invoice totals;
  5. `total = subtotal + cgst_amount + sgst_amount + igst_amount`.
- Use one calculation service/action as the sole calculation authority. Never accept or trust client-submitted tax totals.

### 8. Implement safe Draft → Sent finalization and invoice numbering
- Add a dedicated **Send Invoice** action available only to authorized users for a valid Draft.
- Validate that the Draft has at least one valid line item, active/valid GST-rate references, a Client, a valid snapshotted place of supply, an invoice date, a due date, and internally consistent recalculated totals before finalization.
- In one database transaction:
  1. lock the Invoice and confirm it is still Draft;
  2. derive the Indian financial year from the **send date**: April 1–March 31 (`2026-03-15` → `2025-26`, `2026-04-15` → `2026-27`);
  3. create/fetch that FY’s counter and lock it with `SELECT ... FOR UPDATE`;
  4. increment `last_sequence` once;
  5. set `sequence_number`, `financial_year`, and `invoice_number` formatted exactly `VI/YYYY-YY/NNNN`;
  6. change status to `sent` and commit.
- Never allocate numbers during Draft creation or editing. Never use `COUNT()` or `MAX()` to determine the next value.
- A retry/duplicate Send request must not allocate a second number.
- After Sent, block all line-item and financial-field changes at policy/service/model enforcement layers and hide edit actions in Filament. Preserve non-financial status/payment transitions for Milestone 3 only.

### 9. Enforce deletion and permission boundaries
- A Draft can be deleted by an authorized Admin or owning Sales user.
- Sent, Partially Paid, and Paid invoices are not deletable by anyone, including Admin. Enforce via policy/service/model guard and test direct-action paths; hiding a Filament button is not sufficient.
- Sales can access invoices only where the current owning Client has `assigned_to = authenticated user`; direct Clients must work exactly the same way as converted Clients.
- Admin bypasses ownership restrictions and may reassign Clients but must not bypass invoice immutability/deletion restrictions.
- Prevent unauthorized relationship binding: a Sales user must not create an Invoice for another owner’s Client by posting an ID, nor update a Draft’s Client to an inaccessible Client.

### 10. Extend reproducible demo seed data
Update `DatabaseSeeder` and add focused seeders if helpful. After `php artisan migrate:fresh --seed`, include:
- existing Milestone-1 Admin, Sales, Leads, and Notes;
- converted Clients linked to Leads, with Lead Notes visibly available read-only;
- direct Clients with `lead_id = null`;
- Clients owned across Sales users, including a Client whose owner can demonstrate independent reassignment;
- a placeholder `company_settings` record and all four active `gst_rates`;
- Draft and Sent invoices with both intra-state and inter-state examples;
- at least one mixed-GST-rate Draft/Sent invoice;
- valid fake GSTIN values that match each seeded Client state where GSTIN is supplied.

Do not seed Payments in this milestone. To show `partially_paid`/`paid` states, use factories in isolated tests only if necessary; the normal demo flow must wait for Milestone 3 payment recording.

### 11. Add Milestone-2 Pest coverage before checking boxes
Add focused unit/feature tests for at least:

**Clients and conversion**
- Qualified Lead converts transactionally to exactly one Client with Lead ownership defaulted to `clients.assigned_to`;
- non-Qualified conversion and repeat conversion are rejected;
- failed conversion rolls back Client and Lead status changes;
- direct Client creation is allowed with nullable `lead_id` and self-assignment for Sales;
- Admin can independently reassign a Client;
- Lead Notes are visible read-only from the linked Client;
- Sales ownership scope, policies, and direct Filament/HTTP access block access to another owner’s Client.

**GSTIN and reference data**
- GSTIN optional/blank case;
- valid GSTIN format and matching state code accepted;
- malformed GSTIN and state-code mismatch rejected;
- only active GST rates can be selected for new lines;
- company settings and all required GST rates seed cleanly.

**Draft invoices and calculation**
- Draft has null number/FY/sequence and displays or serializes as `DRAFT`;
- due date defaults to invoice date + 30 days;
- quantity/rate/tax calculations are decimal-safe and comply with the selected rounding rule;
- Rajasthan place of supply produces CGST + SGST and no IGST;
- another state produces IGST and no CGST/SGST;
- a mixed-rate invoice aggregates line values correctly;
- client-supplied tax amounts cannot override server calculations;
- Sales cannot create/read/update a Draft for another Sales owner’s Client;
- Draft remains editable and deletable by its owner/Admin.

**Sending, numbering, and locking**
- Sending a valid Draft allocates exactly one `VI/YYYY-YY/NNNN` number;
- number allocation starts from `0001` per FY and uses the April–March boundary;
- different FYs use separate counters;
- an abandoned/deleted Draft consumes no number;
- duplicate/repeated Send attempts do not allocate a second number;
- concurrent Send attempts cannot produce duplicate invoice numbers (use a transaction/parallel-capable strategy appropriate to the test database; if SQLite cannot faithfully demonstrate row locking, add a MySQL integration test or explicitly document and run it against MySQL);
- Sent invoice line items and financial fields cannot be edited through service, policy, or direct request;
- Sent invoice deletion is blocked for both Admin and Sales.

### 12. Verify, document, and commit in logical units
- Run `composer test`/the full Pest suite and `vendor/bin/pint --test` or the project’s configured formatter gate.
- Run `php artisan migrate:fresh --seed` using the configured MySQL development database, then inspect seeded records and foreign keys.
- Manually smoke-test the Filament panel as Admin and as each Sales role:
  - Qualified Lead → Client conversion;
  - direct Client creation;
  - Client reassignment by Admin;
  - Draft invoice with mixed lines;
  - intra-state and inter-state tax display;
  - Draft → Sent number allocation;
  - blocked Sent edit/delete;
  - blocked cross-owner access.
- Update only the completed Milestone-2 boxes in `docs/06-milestones-and-deliverables.md`, in the same logical commits that complete the corresponding functionality.
- Keep commits small and explainable, for example: client domain/conversion, GST/reference tables, draft invoicing/calculation, sending/number allocation, authorization/seed data/tests. Do not manufacture commits.

## Recommended Tool
Antigravity — Milestone 2 requires coordinated multi-file Laravel work, migrations, Composer/Artisan commands, database-backed testing, UI smoke testing, and incremental Git commits.

## Scope
- Files likely affected:
  - `app/Enums/**` — Client-state/GST-code and Invoice-status types;
  - `app/Models/**`, `app/Models/Scopes/**`, `app/Policies/**` — Client, GST rate, Company Settings, Invoice, Invoice Item, financial-year counter, ownership scopes, and policies;
  - `app/Actions/**` or `app/Services/**` — conversion, GSTIN validation, invoice calculations, and transactional Send/number allocation;
  - `app/Filament/Resources/**`, relation managers, and dashboard/navigation registration as required for Clients and Invoices;
  - `database/migrations/**`, factories, and seeders;
  - `tests/Feature/**` and `tests/Unit/**`;
  - `docs/06-milestones-and-deliverables.md` — completed Milestone-2 checkboxes only;
  - `README.md` only if an actually used setup command or demo credential changes.
- Multi-file? Yes.
- Requires running commands/tests? Yes: Composer/PHP dependency checks if a decimal library is needed, Artisan migrations/seeders, Pest, formatter/static checks, MySQL concurrency verification, and Admin/Sales Filament smoke tests.
- Explicitly out of scope:
  - payment recording/reconciliation and status recalculation driven by payment writes;
  - invoice PDF output;
  - Dashboard v2 financial metrics;
  - email delivery, credit notes/cancellation, multi-company/multi-GSTIN, and e-invoicing/IRN;
  - changes to completed Milestone-1 requirements except necessary relationship integration with Leads/Lead Notes.

## Verification
1. `php artisan migrate:fresh --seed` succeeds on MySQL and produces the existing Milestone-1 demo data plus GST rates, one placeholder company settings record, converted/direct Clients, and Draft/Sent invoice examples.
2. Client ownership is based only on `clients.assigned_to`; it remains correct for direct Clients and after Client reassignment. Sales users cannot bypass it through queries, URLs, requests, relation managers, or crafted foreign keys.
3. Only Qualified Leads can convert; a successful conversion is atomic, creates one Client, changes the Lead to terminal Converted, and exposes—not duplicates—the original Lead Notes.
4. GSTIN validation permits empty values, accepts valid state-matching values, and rejects malformed/mismatched values.
5. Draft Invoices have no number, use a snapshot of the Client state as place of supply, calculate per-line tax server-side with `DECIMAL` values, support mixed GST rates, and correctly choose CGST+SGST vs IGST.
6. Sending a Draft issues one unique financial-year invoice number under transaction/row locking, resets correctly across April 1, and cannot issue duplicates under repeated or concurrent send attempts.
7. Sent Invoice financial data and line items cannot be changed or deleted by Admin or Sales. Authorized users may delete only Drafts.
8. All new and existing Pest tests pass, formatter/static checks pass, and manual Admin/Sales Filament smoke tests demonstrate the full Milestone-2 workflow.
9. Only genuinely completed Milestone-2 checkboxes are marked complete in `docs/06-milestones-and-deliverables.md`.

## Status
- [x] Implemented
- [x] Reviewed
- [x] Tested
