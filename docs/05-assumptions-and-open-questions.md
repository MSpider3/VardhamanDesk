# Assumptions & Decisions

Everything here has been reviewed and confirmed by the client. This file is the source of truth other docs must agree with — if you change a decision, update it here first, then propagate to the affected doc(s) listed in parentheses.

## Confirmed platform choices
- **Laravel 13.x** + **Filament 5.x** (powered by Livewire 4.x).
- **Auth/roles via a simple `role` enum column** on `users`, not a permissions package.
- **Financial year = April 1–March 31.**
- **Reverse charge = always "No"** on invoices.
- **INR only**, no multi-currency.
- **Soft deletes** as the general default for Leads/Clients — **except Invoices, which have their own stricter rule below.**

## GST
- **Rate is per line item**, not per invoice. Each `invoice_items` row carries its own `gst_rate` and `sac_code`.
- **Valid slabs: 0%, 5%, 18%, 40%. ✅ Independently verified**, not just taken on the client's word — source is **CBIC Notification No. 9/2025-Central Tax (Rate), dated 17 September 2025**, issued on the recommendations of the **56th GST Council meeting (3–4 September 2025)**, effective **22 September 2025**, in supersession of the original 2017 rate notification. This confirmed the client's understanding: the old 12% and 28% general slabs were removed, with most former-12% items shifting to 5% and most former-28% items shifting to 18% or the new 40% luxury/sin-goods slab. Full detail and the caveat about tobacco/pan masala's separate treatment is in `03-gst-and-invoicing-rules.md`.
- Rates are **not hardcoded** — stored in a `gst_rates` lookup table (rate, label, active flag) so a future slab change (the Council has revised the schedule before and can again) is a data update, not a deploy.
- **SAC code required per line item.** **Verified default for this business:** SAC `998313` (IT consulting and support services) at 18% — the IT-services SAC group (9983xx) was unaffected by the 2025 reform and stayed at the standard 18% rate throughout. The client will still confirm the precise SAC code with their CA before go-live, since the exact code depends on the nature of what's billed (consulting vs. development vs. hosting), not just that it's IT-related.
- **Place of supply** is captured on the invoice, derived from the client's billing state, and is what actually decides CGST+SGST vs IGST (not just an informational field).
- **GSTIN is validated, not just stored**: format-checked (15-char pattern) and cross-checked that its first two digits match the client's billing state's official GST state code. Checksum digit validation is a nice-to-have, not required for v1. GSTIN remains optional on a client (unregistered buyers are valid).

## Invoice lifecycle
- **Invoice numbers are allocated at the Draft → Sent transition, not at creation.** A Draft displays "DRAFT" and has no number/sequence value. This means the `FOR UPDATE` counter-locking logic (see `02-database-schema.md`) runs inside the Sent-transition action, not the create action — and a deleted Draft never burns a sequence number.
- **Line items are locked once Sent.** Only status and payments can change after that point. No edits, no exceptions.
- **Deletion: Drafts can be deleted (no number allocated, nothing lost). Sent and Paid invoices can never be deleted, ever** — this supersedes the general soft-delete default above for this one entity; it's not soft-delete-then-restorable, it's a hard block on the delete action itself once Sent.
- **Due date is required**, defaults to 30 days from invoice date, editable per invoice.
- Credit notes / post-Sent corrections remain **out of scope**.

## Payments
- **Overpayments are rejected outright** — any payment amount greater than the invoice's remaining balance fails validation at the point of entry, not just a warning.
- **Payment methods:** Bank Transfer (NEFT/RTGS/IMPS), UPI, Cheque, Cash — a fixed enum, each with an optional reference-number field (UTR, cheque number, UPI txn ID, etc.).

## Leads & clients
- **Lead source is a fixed dropdown:** Referral, BNI, Website, Cold Call, Event, Other. (Not free text — this replaces the earlier "TBD" assumption.)
- **Lead notes stay attached to the Lead after conversion** and are also surfaced on the resulting Client's page (read-through, not copied/duplicated).
- **Clients can exist without a Lead** — needed to onboard existing clients directly. `clients.lead_id` is nullable (already reflected in the schema).
- **Admin can reassign both Leads and Clients** to a different salesperson. This means Clients need their own `assigned_to` (owner) column, not just an inherited/fixed link back to the originating Lead's assignee — see the schema update.
- **Lead status can move backwards freely** (e.g. Qualified → Contacted is allowed), **except Converted, which is a one-way terminal state.** A Lost lead is not terminal — it can be reopened into any earlier status.

## Dashboard
- **"Received this month"** = sum of `payments.amount` where `payment_date` falls in the current calendar month.
- **"Outstanding"** = unpaid balance of Sent and Partially Paid invoices only — **Drafts are excluded** (they have no legal number yet and aren't a real receivable).
- **Overdue follow-ups get their own section**, shown above "today's follow-ups" — anything with `next_follow_up_date` before today, not just equal to today.

## PDF must include
- Company name, address, GSTIN, PAN, state, and state code, plus logo
- Bank details for payment (account name, number, IFSC, bank name)
- An authorised-signatory line
- All of the above sourced from a single `company_settings` table/record with placeholder values at build time — the client will supply real details before go-live, so nothing here should be hardcoded into a Blade/Filament view.

## Still explicitly out of scope
- Emailing invoices directly from the app
- Credit notes / invoice cancellation workflow
- Multi-company / multi-GSTIN support
- E-invoicing / IRN generation via the GST portal API
