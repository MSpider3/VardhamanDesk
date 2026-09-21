# AI Conversation History & Implementation Index

This directory provides an auditable, sanitized record of AI-assisted design, architectural decisions, implementation workflows, and test validation across all milestones for VardhamanDesk.

All sensitive secrets, production credentials, and extraneous chatter have been excluded. Only documented development demo credentials (`password` for `@vardhamandesk.local` seeded users) are referenced.

---

## Milestone Execution Roadmap & Mapping

| Milestone | Core Deliverables | Git Commits | Tests | Narrative Summary | Raw JSONL Log | Formatted Transcript |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **Milestone 0** | Schema design review, open questions sign-off, GST slab verification (CBIC Notification 9/2025: 0/5/18/40%) | Repository init | N/A | `docs/01` - `docs/05` | N/A | N/A |
| **Milestone 1** | Auth, Users, Roles (Admin/Sales), Leads CRUD, Lead Notes, Ownership Scoping, Dashboard v1 (Overdue above Today) | `26b34d7`, `f842ac6` | 33 passed | [Milestone 1 History](./milestone-1-leads-users.md) | [milestone-1-2.jsonl](./raw/milestone-1-2-leads-and-invoices.jsonl) | [Milestone 1 & 2 Transcript](./milestone-1-2-leads-and-invoices-transcript.md) |
| **Milestone 2** | Clients, Direct Clients, Admin Client Reassignment, GSTIN Validation, Draft Invoices, Server-side GST (CGST+SGST / IGST), Atomic Send (`VI/YYYY-YY/NNNN`), Financial Immutability, Blocked Deletion | `e57084d`, `8a0bd76` | 66 passed | [Milestone 2 History](./milestone-2-clients-invoices.md) | [milestone-1-2.jsonl](./raw/milestone-1-2-leads-and-invoices.jsonl) | [Milestone 1 & 2 Transcript](./milestone-1-2-leads-and-invoices-transcript.md) |
| **Milestone 3 (Phases 1–6)** | Payments schema, append-only immutability, PaymentMethod enum, atomic `RecordPayment` service with `FOR UPDATE` lock, PDF streaming/download, Dashboard v2 aggregates & widgets, Regression test suite | `671d1ee` - `ea88800` | 94 passed | [Milestone 3 History](./milestone-3-payments-pdf-dashboard.md) | [milestone-3.jsonl](./raw/milestone-3-payments-pdf-dashboard.jsonl) | [Milestone 3 Transcript](./milestone-3-payments-pdf-dashboard-transcript.md) |
| **Git Query** | Git remote verification & push repository query | N/A | N/A | N/A | [git-repo.jsonl](./raw/git-repo-inquiry.jsonl) | [Git Query Transcript](./git-repo-inquiry-transcript.md) |
| **Production Hardening** | Bootstrap command, MySQL sorting/N+1, PHP 8.4+, Concurrency locks (Leads & FY Counter), Settings/GstRate resources, FY boundary validation, CSP hardening, Dual SQLite/MySQL CI | Pending commit | 114+ passed | [`docs/08-hardening-summary.md`](../08-hardening-summary.md) | [hardening.jsonl](./raw/production-hardening-and-fixes.jsonl) | [Hardening Transcript](./production-hardening-and-fixes-transcript.md) |

---

## Architectural Principles Enforced Throughout AI Interactions

1. **Financial Document Immutability**:
   - Sent and Paid invoices are permanently immutable. No edits to client, place of supply, dates, totals, or invoice items are permitted.
   - Deletion of Sent and Paid invoices is permanently blocked at model, policy, and UI levels for all users (including Admin). Only Drafts can be deleted.
2. **Append-Only Payment Ledger**:
   - Payments cannot be updated or deleted. No soft deletes are used on `payments`.
   - Recording a payment is the only post-Send financial lifecycle mutation.
3. **Strict Overpayment Prevention**:
   - Overpayments are rejected outright at write time via `RecordPayment`. Under no circumstances are amounts clamped or excess funds accepted.
4. **Defense in Depth Authorization**:
   - Every read and write operation is guarded at three independent layers:
     1. Global Eloquent Scopes (`ClientOwnershipScope`, `InvoiceOwnershipScope`, `PaymentOwnershipScope`).
     2. Laravel Gate / Policies (`ClientPolicy`, `InvoicePolicy`, `PaymentPolicy`).
     3. Service Layer and UI Actions (`RecordPayment`, `ConvertLeadToClient`, Filament action visibility).
5. **Exact Decimal Arithmetic**:
   - All monetary calculations and database columns use MySQL `DECIMAL(12, 2)` and PHP decimal-safe string arithmetic (`bcsub`, `bccomp`, `round(..., 2)`).
