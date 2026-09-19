# VardhamanDesk — Master System Blueprint, Verification & Delivery Plan

## Executive Summary

**VardhamanDesk** is an internal Laravel lead-to-invoice and sales operations application built for Indian business compliance. It manages the complete revenue lifecycle:

```text
Lead → Qualified Lead → Client → Invoice (Draft → Sent) → Payment(s) → Paid
```

The system is **fully implemented, tested, and verified** using **Laravel 13.x**, **Filament 5.x**, **Livewire 4.x**, **MySQL 8**, and **Pest**. All 94 automated tests pass with 424 assertions, and the codebase passes strict Pint code style formatting.

This document serves as the master architectural blueprint, role & permission verification audit, timed 30-minute demonstration plan, and pre-go-live handover checklist.

---

## 1. System Architecture & Core Principles

### Tech Stack
- **Framework:** Laravel 13.x
- **Admin UI:** Filament 5.x (powered by Livewire 4.x)
- **Database:** MySQL 8 with strict `DECIMAL(12, 2)` monetary arithmetic
- **PDF Engine:** `barryvdh/laravel-dompdf` for authorized invoice generation
- **Test Runner:** Pest (94 automated feature and unit tests)
- **Code Standards:** Laravel Pint

### Architectural Principles
1. **Single-Responsibility Domain Services:**
   All critical business workflows are encapsulated in dedicated service classes under `app/Services/`:
   - `LeadService`: Lead status transitions and follow-up synchronization.
   - `ClientService`: Atomic lead conversion (`convertLeadToClient`) and direct client onboarding.
   - `InvoiceDraftService`: Draft creation, line-item persistence, and subtotal calculation.
   - `InvoiceCalculationService`: Server-side GST tax calculation (CGST+SGST vs IGST per line).
   - `InvoiceSendService`: Concurrency-safe financial-year invoice numbering via row locking.
   - `RecordPayment`: Atomic payment ledger writes, overpayment rejection, and status recalculation.
   - `InvoicePdfService`: Authorized streaming and download of compliance-ready invoice PDFs.

2. **Defense-in-Depth Authorization (3 Independent Layers):**
   - **Layer 1 (Model / Eloquent):** Global query scopes (`ClientOwnershipScope`, `InvoiceOwnershipScope`, `PaymentOwnershipScope`, `Lead` query constraints) auto-filter queries at the SQL level so Sales users only receive their own records.
   - **Layer 2 (Policy):** Laravel policies (`LeadPolicy`, `ClientPolicy`, `InvoicePolicy`, `PaymentPolicy`, `UserPolicy`) block direct route tampering, unauthorized URL IDs, and forbidden actions.
   - **Layer 3 (UI / Filament):** Resource-level navigation gates and table/form action visibility rules hide unauthorized controls.

3. **Strict Financial Immutability:**
   - Invoices start as **Draft** (displays `"DRAFT"`, no sequence number allocated, freely editable, deletable).
   - Transition to **Sent** assigns the immutable sequential number `VI/YYYY-YY/NNNN` and permanently locks line items, client details, tax calculations, and dates.
   - Sent and Paid invoices are **permanently non-deletable** by any user, including Admin.

4. **Append-Only Payment Ledger & Overpayment Guard:**
   - Payments cannot be edited or deleted once recorded (no soft deletes).
   - Overpayments are rejected outright at write time (`entered amount <= remaining balance`).
   - Recording a payment atomically transitions invoice status (`sent` → `partially_paid` → `paid`).

---

## 2. Module Specifications & Business Rules

### Auth & Roles
- Simplified two-role architecture via `UserRole` enum (`admin`, `sales`) stored on the `users` table.
- **Admin:** Company-wide visibility, user management, and client/lead reassignment capability.
- **Sales:** Ownership-scoped visibility (own leads, own clients, invoices/payments tied to own clients).

### Leads & Pipeline Management
- **Statuses:** `new`, `contacted`, `qualified`, `converted`, `lost`.
- **Workflow:** Free backward and forward movement between active statuses. `lost` can be reopened. `converted` is a one-way terminal state.
- **Sources (Fixed Enum):** `referral`, `bni`, `website`, `cold_call`, `event`, `other`.
- **Follow-ups:** Each note entry records an optional `follow_up_date`, atomically updating `leads.next_follow_up_date`.
- **Dashboard v1:** Dedicated **Overdue Follow-ups** widget positioned strictly above **Today's Follow-ups**.

### Clients & Conversion
- **Conversion:** Only `qualified` leads can be converted. Lead notes remain attached to the Lead and read-through dynamically on the Client view without duplicating data.
- **Direct Onboarding:** Existing clients can be created directly without a lead (`clients.lead_id` is nullable).
- **Independent Ownership:** Clients maintain their own `assigned_to` column, allowing Admins to reassign clients independently of originating leads.

### Invoicing & Indian GST Compliance
- **Supplier State:** Registered in **Rajasthan** (State code `08`), configured via `company_settings`.
- **Place of Supply:** Snapshotted on the invoice from client billing state at creation:
  - **Intra-state (Rajasthan):** Line-item GST split evenly into **CGST + SGST** (e.g., 18% = 9% CGST + 9% SGST).
  - **Inter-state (Outside Rajasthan):** Full rate allocated to **IGST**.
- **Per-Line Rates:** Rates reside on line items (`invoice_items.gst_rate_id`), referencing the database master table `gst_rates` (seeded with 0%, 5%, 18%, and 40% per CBIC Notification No. 9/2025).
- **SAC Codes:** Mandatory per line item; defaulted to `998313` (IT consulting & support services at 18%).
- **GSTIN Validation:** Validated via regex pattern (`^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$`) and verified that the first two digits match the selected state's official GST code.
- **Sequential Numbering:** Formatted as `VI/YYYY-YY/NNNN` (e.g. `VI/2026-27/0001`). Allocated inside a database transaction with `SELECT ... FOR UPDATE` against `financial_year_counters` during the Draft → Sent transition. Sequence resets each April 1st.

### Payments & Dashboard v2
- **Methods:** Bank Transfer (`bank_transfer`), UPI (`upi`), Cheque (`cheque`), Cash (`cash`) with optional reference number (UTR, cheque number, transaction ID).
- **Dashboard v2 Financial Metrics (DB Aggregates):**
  - **Invoiced this month:** Sum of `invoices.total` for Sent, Partially Paid, and Paid invoices with `invoice_date` in current month (Drafts excluded).
  - **Received this month:** Sum of `payments.amount` with `payment_date` in current month.
  - **Outstanding:** Unpaid balance of Sent and Partially Paid invoices (`total - sum(payments)`). Drafts and Paid invoices are excluded.
  - Scoped by ownership for Sales; global company-wide for Admin.

---

## 3. Role & Permissions Verification Matrix

The following matrix represents the verified security enforcement across Eloquent Global Scopes, Laravel Policies, and Filament Resources:

| Action / Capability | Admin | Sales | Verified Test File |
| :--- | :---: | :---: | :--- |
| **Dashboard Metrics & Follow-ups** | Global company-wide | Own assigned records only | `DashboardV2Test.php`, `DashboardTest.php` |
| **Manage Users** | Full CRUD | Forbidden (403) | `UserAuthorizationTest.php`, `FilamentPanelSmokeTest.php` |
| **View All Leads** | Global visibility | Only where `assigned_to = auth()->id()` | `LeadOwnershipTest.php` |
| **Create & Edit Own Leads** | Yes | Yes (auto-assigned to self) | `LeadLifecycleTest.php` |
| **Edit Another User's Lead** | Yes | Blocked (Policy & Scope) | `LeadOwnershipTest.php` |
| **Convert Own Qualified Lead** | Yes | Yes | `ClientConversionTest.php` |
| **View All Clients** | Global visibility | Only where `assigned_to = auth()->id()` | `ClientOwnershipTest.php` |
| **Create Direct Client** | Yes | Yes (auto-assigned to self) | `ClientConversionTest.php`, `ClientOwnershipTest.php` |
| **Reassign Leads & Clients** | Yes (can change `assigned_to`) | Disabled / Hidden | `ClientOwnershipTest.php`, `LeadOwnershipTest.php` |
| **View Invoices** | Global visibility | Only for Clients owned by Sales | `InvoiceDraftAndCalculationTest.php`, `InvoiceSendAndNumberingTest.php` |
| **Create & Send Invoices** | Yes | Yes (for own Clients only) | `InvoiceDraftAndCalculationTest.php`, `InvoiceSendAndNumberingTest.php` |
| **Delete Sent or Paid Invoices** | **BLOCKED (No)** | **BLOCKED (No)** | `InvoiceSendAndNumberingTest.php`, `Milestone3RegressionTest.php` |
| **Record Payments** | All accessible invoices | Own Client invoices only | `PaymentAuthorizationTest.php`, `PaymentDomainTest.php` |
| **Download Invoice PDF** | All accessible invoices | Own Client invoices only | `InvoicePdfTest.php` |

---

## 4. Test Suite & Verification Results

The test suite runs with **Pest** and provides 100% verification across all critical compliance and security rules:

```bash
./vendor/bin/pest
```
**Result:** `94 passed, 424 assertions (duration: ~7.8s)`

```bash
./vendor/bin/pint --test
```
**Result:** `PASS (0 style violations)`

### Test Coverage Summary
- **Tax Calculation (`InvoiceDraftAndCalculationTest.php`):** Intra-state CGST/SGST split, Inter-state IGST calculation, mixed rate line items (0%, 5%, 18%, 40%), decimal rounding.
- **Invoice Numbering & Concurrency (`InvoiceSendAndNumberingTest.php`):** `VI/YYYY-YY/NNNN` formatting, sequence increments, April 1st FY reset, draft omission, and `FOR UPDATE` lock safety.
- **GSTIN Validation (`GstinValidationTest.php`):** Regex pattern enforcement, state-code matching, state mismatch rejection, optional field handling.
- **Financial Immutability (`InvoiceSendAndNumberingTest.php`, `Milestone3RegressionTest.php`):** Lock on line items after send, hard policy block preventing deletion of Sent/Paid invoices for all users.
- **Payment Ledger & Status Transitions (`PaymentDomainTest.php`, `Milestone3RegressionTest.php`):** Rejection of payments on Drafts, zero/negative amounts, overpayment prevention, transitions to `partially_paid` and `paid`, append-only audit trail.
- **Ownership Scoping (`LeadOwnershipTest.php`, `ClientOwnershipTest.php`, `PaymentAuthorizationTest.php`):** Global scopes, policy checks, URL tampering prevention, and cross-owner data isolation.
- **Dashboard Financials (`DashboardV2Test.php`, `DashboardTest.php`):** Calendar month invoiced/received filters, draft exclusion, outstanding receivables calculation, and role-based metric scoping.
- **PDF Generation (`InvoicePdfTest.php`):** Dompdf rendering, authorization barriers, draft watermarking, and company/bank detail layout.

---

## 5. Timed 30-Minute Demo Walkthrough Script

This script provides an exact minute-by-minute rehearsal guide for presenting the application to clients or evaluators:

| Time | Topic | Demonstration Steps & Speaker Notes |
| :--- | :--- | :--- |
| **00:00 – 03:00** | **Problem & Architecture** | • Explain business problem: tracking sales pipeline from lead to tax-compliant invoice payment.<br>• Introduce stack: Laravel 13, Filament 5, Livewire 4, MySQL 8, Dompdf, and 3-layer security.<br>• Highlight core rules: Indian FY (April–March), per-line GST, and strict financial immutability. |
| **03:00 – 08:00** | **Role-Based Access Control** | • Log in as Sales A (`sales@vardhamandesk.local`): show that list views only display their own leads/clients.<br>• Log in as Admin (`admin@vardhamandesk.local`): show company-wide visibility across all salespeople.<br>• Direct URL test: demonstrate that pasting Sales B's record URL as Sales A triggers a clean 403/404 denial. |
| **08:00 – 13:00** | **Lead Lifecycle & Follow-ups** | • Create a new Lead assigned to Sales A with source `BNI`.<br>• Add Lead Notes with follow-up dates.<br>• Navigate to Dashboard: verify the **Overdue Follow-ups** card appears strictly above **Today's Follow-ups**.<br>• Show backward transition (e.g. Qualified back to Contacted) and reopenable Lost status. |
| **13:00 – 17:00** | **Lead Conversion & Clients** | • Move Lead to Qualified and click `Convert to Client`.<br>• Complete client modal: billing address, state (Rajasthan `08`), and GSTIN.<br>• Open converted Client: show that historical Lead Notes are immediately visible via read-through.<br>• Show direct Client creation without an originating lead (`lead_id = null`).<br>• Show Admin reassigning Client ownership independently of the original lead. |
| **17:00 – 23:00** | **Invoicing, GST & PDF** | • Create Draft Invoice for client with mixed line items: Line 1 at 18% (SAC `998313`), Line 2 at 5%.<br>• Demonstrate automatic intra-state tax split (9% CGST + 9% SGST on line 1, 2.5% + 2.5% on line 2).<br>• Change client state to Maharashtra: watch taxes automatically recalculate to 18% and 5% IGST.<br>• Notice invoice displays `"DRAFT"` with no invoice number. Demonstrate Draft deletion is permitted.<br>• Click `Send Invoice`: show atomic generation of sequence `VI/2026-27/0001`.<br>• Show locked fields (line items and totals disabled). Attempt to delete: show deletion is blocked.<br>• Click `Download PDF`: view clean Dompdf rendering with company, bank, GST breakdown, and signatory line. |
| **23:00 – 27:00** | **Payments & Overpayment Guard** | • From Sent invoice, click `Record Payment`.<br>• Enter partial payment of ₹10,000 via Bank Transfer with UTR number.<br>• Submit: watch status automatically update to `Partially Paid` with remaining balance updated.<br>• Attempt overpayment: enter an amount exceeding the remaining balance. Show instant validation rejection.<br>• Record exact remaining balance via UPI: watch status update to `Paid`.<br>• Demonstrate payments are append-only (no edit or delete actions exist). |
| **27:00 – 29:00** | **Dashboard Financials** | • Return to Dashboard as Admin: review real-time KPI cards for "Invoiced this month", "Received this month", and "Outstanding".<br>• Explain date rules: Invoiced uses invoice issue date; Received uses payment date; Outstanding excludes Drafts.<br>• Switch to Sales user: verify metrics recalculate strictly for their owned clients. |
| **29:00 – 30:00** | **Quality Gates & Git Audit** | • Open terminal and execute `./vendor/bin/pest`: show 94 passing tests and 424 assertions.<br>• Review Git log: show clean conventional commits (`feat(auth)`, `feat(leads)`, `feat(invoices)`, `feat(payments)`, `feat(pdf)`). |

---

## 6. Pre-Go-Live CA & Production Handover Checklist

Prior to final production deployment, complete the following business and accounting handover tasks:

- [ ] **Chartered Accountant (CA) SAC Code Verification:**
  - Default placeholder SAC code `998313` ("Information technology consulting and support services") at 18% is verified under CBIC heading 9983.
  - CA to review billed service offerings (software development vs consulting vs infrastructure) and specify any additional SAC sub-codes needed.
- [ ] **Production Company Settings Configuration:**
  - Update `company_settings` record via database seeder or admin settings with real legal credentials:
    - Official Company Name & Registered Address
    - Rajasthan GSTIN (`08...`) and PAN
    - Production Bank Account Name, Account Number, IFSC Code, and Bank Name
    - High-resolution company logo asset
    - Official Authorised Signatory Name
- [ ] **GST Rates Master Data Review:**
  - Verify active rates in `gst_rates` table (default 0%, 5%, 18%, 40% per 2025 reforms). Any subsequent GST Council rate modifications can be updated directly in the database without code deployments.
- [ ] **Future Roadmap (Post-MVP):**
  - Implement Modulo-36 checksum validation on GSTIN if automating bulk vendor onboarding.
  - Direct email delivery of invoice PDFs to client email addresses.
  - Credit notes and cancellation workflows.
  - E-invoicing / IRN integration via the GST Suvidha Provider (GSP) API.

---

## 7. Status & Verification Summary

| Gate | Requirement | Status |
| :--- | :--- | :---: |
| **Milestone 1** | Leads, Notes, Follow-ups, Dashboard v1, Ownership Scoping | **Complete** (`f3478d9`) |
| **Milestone 2** | Clients, Conversion, GST calculation, Draft/Sent, Locked Numbering | **Complete** (`e57084d`) |
| **Milestone 3** | Payments, Append-only Ledger, Overpayment Guard, PDF, Dashboard v2 | **Complete** (`01523d0`) |
| **Automated Tests** | Full Pest regression test suite (94 tests, 424 assertions) | **Passed** |
| **Code Standards** | Laravel Pint formatting check | **Passed** |
| **AI Audit Trail** | Sanitized execution logs exported in `docs/ai-history/` | **Documented** |
| **Delivery State** | Ready for 30-minute evaluation demo and production handover | **Ready** |
