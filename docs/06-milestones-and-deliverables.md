# Milestones & Deliverables

Mirrors the brief's checkpoints. Updated to include the tables/rules confirmed in `05-assumptions-and-open-questions.md`.

## Milestone 0 — Design review (before any code)
- [x] Share `02-database-schema.md` (tables + relationships) with the client
- [x] Get sign-off on open questions — resolved, see `05-assumptions-and-open-questions.md`
- [x] Independently verify the GST slab schedule (0/5/18/40%) — confirmed against CBIC Notification No. 9/2025-Central Tax (Rate), dated 17 Sep 2025, effective 22 Sep 2025; see `03-gst-and-invoicing-rules.md`

## Milestone 1 — Leads + Users
- [ ] Migrations: `users`, `leads`, `lead_notes`
- [ ] Seeders: 1 Admin, 2–3 Sales users, ~15 demo leads across statuses and all six sources
- [ ] Auth (login/logout), role-based redirect
- [ ] Lead CRUD; status can move to any other status except out of `converted` (terminal); `lost` reopenable
- [ ] Notes + follow-up date; `next_follow_up_date` kept in sync
- [ ] Dashboard v1: **Overdue follow-ups** section (before-today) above **Today's follow-ups**, scoped per-user for Sales / all for Admin
- [ ] Ownership scoping enforced (`leads.assigned_to`) and manually verified
- [ ] Admin can reassign a lead's `assigned_to`

## Milestone 2 — Clients + Invoices
- [ ] Migrations: `clients` (with its own `assigned_to`), `gst_rates`, `company_settings`, `invoices`, `invoice_items` (with `sac_code`, `gst_rate_id`), `financial_year_counters`
- [ ] Seed `gst_rates` (0/5/18/40 — verified, see Milestone 0) and a placeholder `company_settings` row
- [ ] Lead → Client conversion flow (billing address, state, GSTIN validation, `assigned_to` defaulted from the lead)
- [ ] Direct client creation with no lead (`lead_id` nullable) — for onboarding existing clients
- [ ] Admin can reassign a client's `assigned_to` independently of its originating lead
- [ ] GSTIN validation: format + state-code cross-check against `clients.state`
- [ ] Invoice creation as **Draft**: line items with per-item SAC + GST rate, live tax calc, no invoice number yet
- [ ] Draft → Sent action: allocates `invoice_number`/`financial_year`/`sequence_number` via the `FOR UPDATE` counter, locks line items
- [ ] Draft deletion allowed; Sent/Paid deletion blocked at the policy layer
- [ ] Seeders: demo clients + invoices covering intra-state, inter-state, and mixed-rate line items

## Milestone 3 — Payments, PDF, Dashboard, Tests → Demo
- [ ] Payment recording (incl. partial); **reject any amount exceeding the remaining balance**
- [ ] Payment method enum (Bank Transfer / UPI / Cheque / Cash) with optional reference field
- [ ] Status auto-recalculation on every payment write
- [ ] PDF generation (dompdf) pulling from `company_settings` — company details, GSTIN, PAN, logo, bank details, signatory line, place of supply, per-line SAC/rate breakdown
- [ ] Dashboard v2: this month's invoiced total, **received this month** (by `payment_date`), **outstanding** (Sent + Partially Paid only, Drafts excluded)
- [ ] Test suite (Pest):
  - GST calculation: intra-state split, inter-state IGST, per-line-item rates on a mixed invoice, rounding
  - Invoice numbering: allocated only on Send, sequential within FY, resets across FY boundary, no collisions under concurrent Send actions, a deleted Draft leaves no gap
  - GSTIN validation: format rejection, state-code mismatch rejection, valid case acceptance
  - Payments: overpayment rejected, partial payment moves status correctly, full payment marks Paid
  - Deletion: Draft deletable, Sent/Paid deletion blocked for both Sales and Admin
  - Permissions: Sales cannot read/write another Sales user's leads/clients/invoices/payments, via direct route and via Eloquent query; Admin reassignment works for both leads and clients
- [ ] README with setup steps + seeder instructions (fresh clone → runnable demo)
- [ ] Assumptions/incomplete-items note finalized from `05-assumptions-and-open-questions.md`, including the flagged GST-slab verification item
- [ ] AI chat history exported for submission
- [ ] 30-minute demo: New → Contacted → Qualified → Converted → Draft invoice (mixed GST rates) → Sent (number allocated) → Partially Paid → Paid; show dashboard incl. overdue section; show PDF; show a blocked delete attempt on a Sent invoice; show Admin reassigning a client

## Commit discipline
Meaningful, incremental commits per logical change — not a single end-of-project upload. Suggested cadence: commit after each checked box above.
