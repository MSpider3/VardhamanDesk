# Security Hardening & Vulnerability Disclosure Index

This directory contains the security audits, vulnerability writeups, and architectural hardening proposals for VardhamanDesk.

---

## Executive Overview

| Document | Description | Scope | Status |
| :--- | :--- | :--- | :--- |
| [`01-architectural-hardening-proposal.md`](./01-architectural-hardening-proposal.md) | Invariants, systemic defenses, before/after architecture diagrams, and tradeoffs | Architecture & Platform | Implemented & Verified |
| [VULN-01 Report](./VULN-01-mysql-invoices-sort-crash/report.md) | Invoices Table SQL Missing Column Crash on Sort (Denial of Service) | Invoices Resource | Resolved |
| [VULN-02 Report](./VULN-02-lead-conversion-concurrency-race/report.md) | Lead Conversion Concurrency Race Condition & Duplicate Client Creation | Lead Service / Database | Resolved |
| [VULN-03 Report](./VULN-03-fy-counter-bootstrap-race/report.md) | Financial Year Sequence Counter Bootstrap Race Condition | Invoice Send Service | Resolved |
| [VULN-04 Report](./VULN-04-cross-user-lead-conversion-idor/report.md) | Cross-User Lead Conversion IDOR via Direct Service Invocation | Client Service | Resolved |
| [VULN-05 Report](./VULN-05-fy-backdating-closed-period/report.md) | Statutory Financial Year Boundary Bypass via Draft Backdating | Invoicing / GST Compliance | Resolved |
| [VULN-06 Report](./VULN-06-permissive-csp-wildcard/report.md) | Permissive Content-Security-Policy (CSP) Wildcard Bypass | HTTP Security Headers | Resolved |
| [VULN-07 Report](./VULN-07-production-bootstrap-default-password/report.md) | Default Administrator Credentials Provisioning in Production Mode | Console / Deployment | Resolved |
| [VULN-08 Report](./VULN-08-lead-conversion-soft-delete-dead-end/report.md) | Soft-Deleted Client Unique Constraint Dead-End on Lead Re-conversion | Client Service / Lifecycle | Resolved |
| [VULN-09 Report](./VULN-09-fy-forward-dated-premature-lockout/report.md) | Premature Financial Year Lockout via Forward-Dated Invoices | Invoicing / State Machine | Resolved |

---

## Summary of Defense-in-Depth Mechanisms

1. **Triple-Ring Authorization**:
   - Database / Scope level (`ClientOwnershipScope`, `InvoiceOwnershipScope`, `PaymentOwnershipScope`, `LeadOwnershipScope`).
   - Domain Service level (`ClientService`, `InvoiceDraftService`, `RecordPayment`).
   - Policy / Gate level (`ClientPolicy`, `InvoicePolicy`, `PaymentPolicy`, `LeadPolicy`, `UserPolicy`, `GstRatePolicy`, `CompanySettingPolicy`).
2. **Atomic Concurrency & Database Integrity**:
   - `SELECT ... FOR UPDATE` row locks on `Lead`, `Invoice`, and `FinancialYearCounter`.
   - Hard database constraints (`UNIQUE KEY (lead_id)` on `clients`, `UNIQUE KEY (financial_year)` on `financial_year_counters`).
   - Soft-delete aware lifecycle reconciliation restoring prior client records upon re-conversion.
3. **Financial Immutability**:
   - Deletion of non-draft invoices permanently blocked for all users (including Admin).
   - Modification of financial fields on locked invoices blocked at model and policy layers.
   - Payments ledger append-only; update and delete prohibited.
   - Strict overpayment prevention at write time.
   - Real calendar FY reference preventing backdating into closed statutory accounting periods.
4. **Transport, Deployment & Client Security**:
   - Production bootstrap command rejecting missing passwords or weak default credentials.
   - Scoped Content Security Policy (no open `https:` wildcards).
   - Strict HTTP Transport Security (`HSTS`).
   - Frame ancestors set to `'none'` (clickjacking prevention).
