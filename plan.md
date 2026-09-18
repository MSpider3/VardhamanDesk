# Plan: Implement Milestone 3 — Payments, PDF, Dashboard v2, Final Verification, and Demo

## Problem
Milestones 1 and 2 are complete and committed. The Laravel 13 / Filament 5 application now includes role-scoped Leads and Clients, Lead conversion, GST master data, company settings, Draft/Sent invoices, server-side per-line GST calculation, and transactional invoice-number allocation.

Milestone 3 is the final delivery milestone. It must add an auditable payment ledger, secure invoice PDF rendering, financial dashboard metrics, complete the required regression tests, produce reproducible setup/demo instructions, preserve an AI-history export, and verify the complete 30-minute walkthrough. It must not weaken any established invoice ownership, numbering, locking, or deletion rule.

## Proposed Solution

### 1. Preserve the canonical contract and pre-existing safeguards
- Read `docs/AGENT.md` and all active numbered documents under `docs/` before implementation. When documents conflict, `docs/05-assumptions-and-open-questions.md` wins.
- Retain all completed Milestone-1 and Milestone-2 behavior and tests. Fix a regression only when reproduced by a failing test.
- Keep the established ownership rule: Sales access to Clients, Invoices, and Payments derives from `clients.assigned_to`; it does not depend on the originating Lead. Admin is globally scoped but does not bypass financial-document immutability or deletion protections.
- Keep all monetary fields/calculations in MySQL `DECIMAL` and decimal-safe PHP logic. Do not use floats.
- Keep invoices immutable after Sent: never permit edits to Client, place of supply, dates, financial totals, or Invoice Items after Sent. Payments are the only new financial lifecycle operation.
- Continue to exclude email sending, credit notes/cancellation, multi-company/multi-GSTIN, e-invoicing/IRN, GSTIN checksum validation, and any external payment gateway integration.

### 2. Add the Payment ledger schema and domain model
Create migration, enum, model, factory, policy, ownership scope, seeder support, Filament resource/relation page, and focused service/action for `payments`.

**Database and model requirements**
- `payments.invoice_id`: required FK to `invoices`.
- `payments.amount`: required `DECIMAL(12,2)`, strictly greater than zero.
- `payments.payment_date`: required date.
- `payments.method`: required fixed enum/value object: `bank_transfer`, `upi`, `cheque`, `cash`; display Bank Transfer as “Bank Transfer (NEFT/RTGS/IMPS)”.
- `payments.reference_note`: nullable string for UTR, cheque number, UPI transaction ID, etc.
- `payments.recorded_by`: required FK to `users`.
- timestamps. Do not add soft deletes; a payment is an auditable ledger entry and must not silently disappear.
- Add indexes/foreign-key behavior appropriate for Invoice lookups, payment-date dashboard aggregation, and authorization queries.

**Mutability policy**
- Payments must be append-only after creation: no ordinary edit or delete UI/action. Do not make correction/reversal workflows now; they are out of scope and must require an explicit new decision.
- A payment can be recorded only against a Sent or Partially Paid Invoice. Reject Draft and Paid invoices at policy, service, and UI layers.
- The database/model/service must reject a zero/negative payment, an amount exceeding the current remaining balance, and a payment against an inaccessible Invoice. Never clamp or silently accept an overpayment.

### 3. Implement an atomic `RecordPayment` service and invoice-status recalculation
Create one dedicated `RecordPayment` action/service. It is the only write path responsible for payment validation, ledger insertion, and Invoice status transitions. Do not calculate balance/status separately in controller, Filament callbacks, observers, or dashboard code.

Within one database transaction:
1. Fetch and `lockForUpdate()` the Invoice without user ownership scopes, then authorize the acting user against its owning Client.
2. Re-read/sum the persisted Payment ledger for that locked Invoice; do not trust a browser-displayed outstanding amount.
3. Confirm status is `sent` or `partially_paid` and calculate `remaining = invoice.total - sum(existing payments)` using decimal-safe arithmetic.
4. Validate `0 < entered amount <= remaining` before creating anything.
5. Create the immutable Payment with `recorded_by = actor`.
6. Recompute paid/outstanding figures from the ledger after insertion and transition the Invoice:
   - `sent` when paid total is `0.00`;
   - `partially_paid` when paid total is greater than zero and lower than Invoice total;
   - `paid` when paid total equals Invoice total;
   - never permit a total greater than Invoice total.
7. Commit both the Payment and Invoice status update together.

Add read-only Invoice accessors/query helpers for `paid_amount` and `outstanding_amount` that derive values from `SUM(payments.amount)`. Do not add independently editable `amount_paid` or `amount_outstanding` columns. Use a single reusable query/scoped aggregate for lists/widgets to avoid N+1 queries.

**Concurrency requirement**
- Two simultaneous payment attempts must not be able to exceed the invoice balance. Test a concurrent/race equivalent against MySQL where feasible; the row lock and inside-transaction fresh payment total must be the source of protection.
- A payment transaction failure must leave neither a Payment record nor an incorrect Invoice status behind.

### 4. Enforce Payment ownership and build the Filament experience
- Add `PaymentOwnershipScope`, `PaymentPolicy`, and Filament resource/page behavior equivalent to existing Client/Invoice defense in depth:
  - Admin can see and record payments for all accessible Invoices.
  - Sales can list/view/record a payment only where the associated Invoice’s Client is currently assigned to them.
  - Sales must not obtain cross-owner access by changing an Invoice ID in a request, route, filter, relation manager, or bulk action.
- Ensure `recorded_by` is always derived server-side from the authenticated user; it is never accepted as client input.
- Present Payment recording from an Invoice and/or a filtered Payments screen. Use only Server-authorized, non-Draft, non-Paid Invoice selections.
- Display Invoice total, paid amount, current balance, payments ledger, date, method, optional reference, and recorder. This is informational UI; the service remains authoritative.
- Do not expose update/delete actions for payments. If a user requires a correction, surface a clear “not supported in this MVP” message rather than permitting destructive behavior.
- On an Invoice, show payment history and computed balance but keep Invoice Items/financial document content locked after Send.

### 5. Install and implement protected PDF generation
- First run Composer compatibility checks and install `barryvdh/laravel-dompdf` at a version compatible with the existing Laravel 13 application. Record the actually installed package/version in `composer.lock` and README; do not claim compatibility without Composer output.
- Implement a dedicated Invoice PDF service/controller/route or authorized Filament action. A request must authorize the Invoice before retrieving/rendering it; scopes, policy checks, and direct URL access must all be protected.
- Generate PDFs only from persisted Invoice, Invoice Items, Client, Payment-independent totals, GST rate relations, and the single `company_settings` record. Never recompute or take values from request input while generating a PDF.
- Build a printable Blade view with the required content:
  - company name, address, GSTIN, PAN, state, state code, logo when present;
  - invoice number (or `DRAFT` only if Draft output is deliberately permitted), invoice date, due date, and reverse charge “No”;
  - client name, address, state, GSTIN if present, and snapshotted place of supply;
  - every item’s description, SAC, quantity, rate, taxable amount, GST rate, and line CGST/SGST or IGST;
  - subtotal, tax totals, grand total, bank account name/number/IFSC/bank, and authorized-signatory line.
- Default policy: allow PDF generation/download for invoices visible to the user (including Drafts if the existing invoice policy allows view); label Draft PDFs clearly as `DRAFT`. Do not generate an official-looking number for an unsent Invoice.
- Ensure absent logo, optional Client GSTIN, and placeholder company settings render without exceptions or private filesystem paths.
- Use an accessible download filename derived from the persisted display number, with safe fallback for Drafts.

### 6. Add Dashboard v2 financial metrics without breaking v1 follow-ups
Keep the overdue and today follow-up widgets and their ordering. Add finance widgets/cards using DB-level aggregates, no in-memory collection totals over all data.

**Metrics and date definitions**
- **This month’s invoiced:** sum `invoices.total` for Sent, Partially Paid, and Paid invoices whose `invoice_date` is in the current calendar month. Exclude Drafts.
- **Received this month:** sum `payments.amount` where `payment_date` falls in the current calendar month, regardless of Invoice issue date.
- **Outstanding:** `invoice.total - SUM(payments.amount)` across Sent and Partially Paid Invoices only. Exclude Drafts and Paid Invoices.
- Admin sees company-wide figures; Sales sees only records attached to Clients currently assigned to them.
- Format all figures as INR with two decimal places, but retain exact DECIMAL precision in queries/calculation.
- Apply Invoice/Payment ownership logic at query level. Do not calculate Sales visibility after company-wide totals are loaded.
- Avoid N+1 queries and ensure null aggregate results display as `₹0.00` rather than erroring.

### 7. Complete and extend realistic demo seeding
Update the normal `DatabaseSeeder` (or dedicated seeders) so `php artisan migrate:fresh --seed` produces a demonstration-ready database:
- existing Admin and Sales users, Leads, Notes, Clients, GST rates, company settings, Draft/Sent and intra/inter-state/mixed-rate Invoice examples;
- at least one Sent Invoice with no payments;
- at least one partially paid Invoice with a valid partial payment;
- at least one Paid Invoice with one or more payments exactly totaling the Invoice value;
- payments across current and prior calendar months so “received this month” is demonstrable;
- Invoices both inside and outside the current month for “invoiced this month” verification;
- Payments recorded by valid Admin/Sales demo users and associated only with invoices their owners can access;
- no seed overpayments, no Payments on Drafts, no fake production credentials, and only safe placeholder company/bank/GST data.

Seed through the same `RecordPayment` service where practical, so demo data proves the real payment-state workflow rather than bypassing it. If seeding requires an explicit privileged context, document it and validate the exact result with tests.

### 8. Finish required Pest coverage and regressions
Retain the Milestone-1 and 2 tests. Add/complete tests covering the entire final acceptance surface.

**Payments and status**
- Payment method validation and optional reference handling.
- Authorized Admin/Sales can record a valid payment only for their own scoped Client Invoice.
- Draft Invoice rejects payment; Sent accepts first payment; partial payment transitions to `partially_paid`; final exact payment transitions to `paid`.
- zero, negative, malformed/over-precision, and overpayment values are rejected at service/validation boundaries.
- Repeated partial payments cannot cumulatively overpay; concurrent/race attempts cannot overpay on MySQL.
- transaction failure rolls back payment and status together.
- Payment `recorded_by` cannot be spoofed; Payments remain immutable/no edit-delete route/action.
- Sales cannot query/view/create a Payment for another Sales user’s Client through policy, Eloquent scope, direct URL, request ID, resource list, or relation manager.

**Dashboard**
- Admin/company totals and Sales-scoped totals differ correctly.
- invoiced metric uses Invoice date and excludes Drafts.
- received metric uses payment date, not Invoice date.
- outstanding includes only Sent/Partially Paid unpaid balances and excludes Draft/Paid.
- no-data metrics return zero.
- overdue follow-ups remain above today’s follow-ups and retain existing role scope.

**PDF**
- authorized Admin/owner Sales receives a successful PDF response with expected persisted Invoice, Client, company, bank, SAC, and tax content.
- unauthorized Sales access to another owner’s Invoice PDF is denied/not found according to existing scope conventions.
- a Draft PDF uses `DRAFT` and cannot expose an allocated invoice number.
- optional Client GSTIN/logo absence is handled safely.
- generated PDF must be valid/non-empty and no Blade/render exception occurs.

**Full financial and authorization regressions**
- rerun or extend GST intra/inter-state/mixed-rate/rounding, GSTIN validation, Draft-to-Sent numbering and FY reset, idempotent/concurrent Send, locked Invoice/InvoiceItem restrictions, and Draft-only deletion tests.
- verify cross-owner access for Leads, Clients, Invoices, and Payments through both policies and query scopes.

### 9. Finalize the repository documentation and submission evidence
- Update `README.md` with only commands tested in this repository:
  - prerequisites and MySQL configuration;
  - install steps (`composer install`, frontend dependencies/build if used, `.env`, key, `php artisan migrate:fresh --seed`);
  - test/format commands;
  - local run command and Filament URL;
  - non-production demo credentials;
  - a concise feature/data note for Clients, Invoices, Payments, PDF, and Dashboard.
- Update `docs/05-assumptions-and-open-questions.md` only to record remaining pre-go-live placeholders/limitations already in the approved specification (CA confirmation of exact SAC; real company/bank/GSTIN/logo/signatory values; optional GSTIN checksum; runtime GST-rate administration if relevant). Do not falsely mark unprovided real-world data complete.
- Export the relevant AI conversation history for submission in a safe, repository-approved location (for example, `docs/ai-history/`), excluding secrets, credentials beyond explicitly documented development demo users, and unrelated/private chats. Include a short index that maps major AI-assisted changes to actual commits and test results.
- Update only completed Milestone-3 checkboxes in `docs/06-milestones-and-deliverables.md`, in the same logical commit as each completed capability.

### 10. Run the final end-to-end demo and quality gates
Use meaningful incremental commits: e.g. payment domain/status transitions; payment authorization/UI; PDF package/rendering/authorization; dashboard/seed data; tests/docs/demo evidence. Never fabricate commits.

Before marking the milestone complete:
1. Run `php artisan migrate:fresh --seed` against configured MySQL 8.
2. Run the full Pest suite and the configured formatting/static-analysis checks (at minimum `vendor/bin/pint --test` if Pint is present).
3. Run/record package and route checks needed to prove the PDF path is registered and Dompdf works.
4. Manually test in Filament as Admin and a Sales user, including cross-owner denial.
5. Execute the complete 30-minute demo rehearsal:
   - New → Contacted → Qualified → Converted;
   - show Lead Notes from the Client;
   - create Draft Invoice with mixed GST rates, show `DRAFT`, Send, show number, and demonstrate locked fields;
   - record partial Payment, show Partially Paid and outstanding amount;
   - demonstrate rejected overpayment;
   - record final Payment, show Paid;
   - download/view authorized PDF;
   - show Dashboard v1 follow-ups and v2 finances;
   - show blocked Sent Invoice deletion;
   - show Admin Client reassignment and Sales cross-owner denial.
6. Capture actual test/command output in the final implementation report. Do not state that a check passed unless it was run successfully.

## Recommended Tool
Antigravity — this milestone requires multi-file Laravel development, Composer package installation, schema/model/service/Filament/PDF work, MySQL transaction testing, full test execution, seed validation, documentation updates, and final end-to-end UI verification.

## Scope
- Files likely affected:
  - `composer.json`, `composer.lock` — add a Laravel-13-compatible Dompdf package only after Composer verification;
  - `database/migrations/**`, `database/factories/**`, `database/seeders/**` — Payment persistence and final demo data;
  - `app/Enums/**`, `app/Models/**`, `app/Models/Scopes/**`, `app/Policies/**`, `app/Services/**` or `app/Actions/**` — payment lifecycle, payment ownership, derived totals, and PDF service;
  - `app/Filament/Resources/**`, relation managers, widgets, and the panel provider — payment UX, Invoice payment/PDF actions, Dashboard v2;
  - `app/Http/Controllers/**`, `routes/**`, and `resources/views/**` — authorized PDF response/rendering;
  - `tests/Feature/**`, `tests/Unit/**` — payment, dashboard, PDF, ownership, and regression coverage;
  - `README.md`, `docs/05-assumptions-and-open-questions.md`, `docs/06-milestones-and-deliverables.md`, and a safe `docs/ai-history/**` export/index.
- Multi-file? Yes.
- Requires running commands/tests? Yes: Composer package checks/install, npm build if assets change, MySQL migrations/seeders, Pest, formatter/static checks, PDF render check, route checks, and Admin/Sales UI smoke tests.
- Explicitly out of scope: email sending, credit notes/cancellation/reversals, external payment gateways, multi-company/multi-GSTIN, GST portal/e-invoicing/IRN, and any behavior that allows a locked financial document or payment ledger entry to be edited/deleted.

## Verification
1. On MySQL 8, `php artisan migrate:fresh --seed` builds a usable demo with Draft, Sent, Partially Paid, and Paid invoices; valid Payments; current/prior-period data; all GST rates; and placeholder company settings.
2. Payment creation is atomic, append-only, and ownership-scoped. Draft/Paid Invoice payments, zero/negative entries, spoofed recorders, and all direct/cumulative/concurrent overpayments are rejected; exact payment total moves an Invoice to Paid.
3. Admin sees all payments/financial metrics; Sales sees only records tied to Clients currently assigned to them. All scope/policy/UI/direct-route checks deny cross-owner access.
4. Financial dashboard metrics use the required date/status rules: issued Invoice date for invoiced, Payment date for received, and only Sent/Partially Paid balances for outstanding. Drafts are excluded where required.
5. PDF generation uses persisted values and `company_settings`, includes all required company/client/invoice/GST/bank/signatory content, works with optional fields absent, and rejects unauthorized access.
6. No Milestone-2 rule regresses: per-line GST, GSTIN validation, Draft → Sent row-locked numbering, invoice immutability, and Draft-only deletion remain covered and passing.
7. The README steps work from a clean clone, test/formatter checks pass, relevant AI history is exported safely, and only actually completed Milestone-3 boxes are checked.
8. The documented 30-minute demo completes end-to-end in the Filament UI for both Admin and Sales, including an explicit blocked action and evidence-backed test results.

## Status
- [x] Implemented
- [x] Reviewed
- [x] Tested
