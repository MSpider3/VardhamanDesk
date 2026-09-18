# AGENT.md — VardhamanDesk

Instructions for the AI agent (Antigravity) working in this repo. Read `docs/01-project-overview.md` through `docs/06-milestones-and-deliverables.md` before writing code — they are the spec, this file is how to *behave* while executing it. `docs/05-assumptions-and-open-questions.md` holds every client decision; if it and any other doc ever disagree, `05` wins and the other doc has a stale section that needs fixing.

## Ground rules

1. **All previously open questions are now resolved** — see `docs/05-assumptions-and-open-questions.md`. Don't re-litigate them; implement per the decisions there. If a *new* ambiguity comes up that isn't covered, stop and ask rather than guessing.
2. **The GST slab list (0/5/18/40%) is verified**, not just asserted — see the CBIC citation in `docs/03-gst-and-invoicing-rules.md`. Treat it as settled for this build. If the GST Council revises the schedule again after this doc was written, that's a `gst_rates` data update, not a reason to distrust the current data.
3. **Explain every change.** Every commit message and every PR-sized chunk of work must be explainable in plain terms.
4. **Small, meaningful commits.** One logical change per commit. Never batch unrelated changes.
5. **Follow `docs/06-milestones-and-deliverables.md` in order.**
6. **Tests are not optional and not an afterthought.** GST calculation, invoice numbering, GSTIN validation, payment/overpayment logic, deletion rules, and permission scoping each need a Pest test in the same commit as the code they cover.

## Tech stack (do not substitute without asking)

- Laravel 13.x + MySQL 8
- Filament 5.x (powered by Livewire 4.x)
- `barryvdh/laravel-dompdf` (or maintained Laravel 13 compatible PDF package) for invoice PDFs
- Pest for tests
- Laravel's built-in auth; Filament handles the panel UI

## Non-negotiable implementation details

- **GST rate lives per line item** (`invoice_items.gst_rate_id` → `gst_rates`), not per invoice. Never collapse this back to a single invoice-level rate field.
- **GST rates are data, not code.** They live in the `gst_rates` table, seeded from `docs/05` but editable without a deploy.
- **CGST+SGST vs IGST is derived from `place_of_supply` vs the company's registered state (Rajasthan, from `company_settings`), computed server-side per line item, every time.** Never accept tax amounts from client input.
- **GSTIN is validated, not just stored:** format pattern + first-two-digits-match-state's-GST-code. Reject invalid GSTIN at save time, not silently accepted.
- **Invoice numbers are allocated only on the Draft → Sent transition**, using the `SELECT ... FOR UPDATE` counter pattern in `docs/02-database-schema.md`. A Draft has `invoice_number = null` and displays "DRAFT" in the UI. Never allocate a number at creation time — that's the old design and is now wrong.
- **Line items are immutable once the invoice is Sent.** Enforce this at the model/policy layer, not just by hiding the edit button.
- **Deletion:** Draft invoices are deletable. Sent and Paid invoices are **never** deletable by anyone, Admin included — this is a hard policy block, not soft-delete-and-hide. Leads and Clients use ordinary soft deletes.
- **Overpayment is rejected at write time.** A payment amount greater than an invoice's current remaining balance must fail validation, never be silently accepted or clamped.
- **Clients have their own `assigned_to`, separate from `created_by` and separate from any originating Lead's assignee.** Ownership scoping for Sales users, and Admin's reassignment action, both operate on `clients.assigned_to` — don't derive a client's owner from its lead at query time, since a client can exist with no lead or have been reassigned since conversion.
- **Money is decimal, never float**, both in the DB and in PHP.

## Working style

- Before implementing a milestone's checklist item, restate the plan in 2–4 sentences and proceed.
- After implementing, update the relevant checkbox in `docs/06-milestones-and-deliverables.md` in the same commit.
- If an implementation detail turns out to need a decision not covered in `docs/05`, add it there as a new open item and ask, rather than picking silently.

## Definition of done (per milestone)

A milestone is done when: migrations run clean on a fresh DB, seeders populate demo data (including `gst_rates` and a placeholder `company_settings` row), the relevant Pest tests pass, and the feature is reachable and usable end-to-end through the Filament UI by both an Admin and a Sales demo user.
