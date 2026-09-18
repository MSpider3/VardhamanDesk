# GST & Invoicing Rules

*Updated per client decisions in `05-assumptions-and-open-questions.md` — supersedes the flat-rate-per-invoice version of this doc.*

## Our registration

The company is GST-registered in **Rajasthan**. This lives in `company_settings` (one row), never hardcoded in multiple places.

## Intra-state vs inter-state

Decided by **place of supply** (copied onto the invoice from the client's billing state at invoice time), compared against our own registered state:

- **Place of supply = Rajasthan** → **intra-state** → split the line's GST rate evenly into **CGST + SGST** (e.g. an 18% line = 9% CGST + 9% SGST).
- **Place of supply ≠ Rajasthan** → **inter-state** → the full rate goes to **IGST**, CGST/SGST are 0.

`clients.state` must be a constrained list (states/UTs with their official 2-digit GST state codes) — never free text.

## GST rate — per line item, not per invoice

Each `invoice_items` row has its own `gst_rate_id` → `gst_rates` table. This supports invoicing a mix of items at different slabs on the same invoice.

**✅ Verified against the official CBIC notification.** Valid slabs: **0%, 5%, 18%, 40%.** Source: **Notification No. 9/2025-Central Tax (Rate), dated 17 September 2025**, issued by the Ministry of Finance (Department of Revenue) on the recommendations of the **56th GST Council meeting (3–4 September 2025)**, in supersession of the original Notification No. 1/2017-Central Tax (Rate). Effective **22 September 2025**. This collapsed the old five-tier structure (0/5/12/18/28%) into four slabs — the 12% and 28% general slabs were removed; most former-12% items moved to 5%, most former-28% items moved to 18% or the new 40% luxury/"sin goods" slab. (State-level GST rates move in lockstep via the equivalent SGST rate notifications, so the same four slabs apply to CGST+SGST as well as IGST.)

One nuance found during verification, noted for completeness though it doesn't affect this business: tobacco and pan masala are a special case — they stay on the old 28%+cess treatment for now rather than moving straight to 40%, pending a separate compensation-cess-loan settlement, per a distinct notification. Not relevant to an IT-services invoicing system, but worth knowing if `gst_rates` is ever extended beyond services.

Rates still live in the `gst_rates` table, not hardcoded — so if the Council revises the schedule again (as it has done before), it's a data update, not a code change.

## SAC code

Every line item requires a **SAC (Services Accounting Code)**. **Verified default for this business:** IT/software services (SAC codes in the 9983 group — e.g. `998313` IT consulting & support, `998314` IT design & development, `998315` hosting/infrastructure, `998316` IT infrastructure & network management, `998319` other IT services n.e.c.) were **not moved by the 2025 reform and remain at the standard 18% rate**, both intra- and inter-state. Ship `998313` ("Information technology (IT) consulting and support services") as the placeholder default at 18% — the client will still confirm the exact code with their CA before go-live, since the right SAC depends on precisely what's being billed (consulting vs. development vs. hosting), not just that it's "IT."

## GSTIN validation

Required to be **validated**, not just stored, whenever present (GSTIN is optional — unregistered buyers are legitimate):
1. **Format check** — the standard 15-character GSTIN pattern (2-digit state code + 10-char PAN + entity code + checksum char).
2. **State-code cross-check** — the first two digits must match the official GST state code of `clients.state` (e.g. a client billed to Rajasthan must have a GSTIN starting `08`).
3. **Checksum digit validation** is a nice-to-have for v1, not a hard requirement.

## Calculation order (now per line item, aggregated up)

1. For each `invoice_items` row: `amount = quantity × rate`
2. Determine intra-/inter-state once for the invoice, from place of supply
3. For each row, apply *its own* `gst_rate` to `amount` → split into that row's CGST+SGST or IGST per the rule above
4. Invoice-level `subtotal`, `cgst_amount`, `sgst_amount`, `igst_amount` are the **sums** of the line-item figures
5. `total = subtotal + cgst_amount + sgst_amount + igst_amount`

All monetary math is decimal, never float; round only at final display/PDF stage.

## Invoice numbering — allocation timing

**Numbers are allocated at the Draft → Sent transition, not at creation.** A Draft shows "DRAFT" and has no `invoice_number`/`financial_year`/`sequence_number` — those stay null until Sent. This means a deleted Draft never burns a sequence number. See `02-database-schema.md` for the locking mechanics.

## Invoice statuses and how they change

| Status | Meaning | Transition trigger |
|---|---|---|
| Draft | Created, no number yet, freely editable | manual creation |
| Sent | Number allocated; **line items now locked** — no further edits, only status/payments can change | manual action; this is also where numbering allocation happens |
| Partially Paid | `sum(payments.amount) > 0` but `< total` | automatic, recalculated on every payment write |
| Paid | `sum(payments.amount) >= total` | automatic |

Overpayment is rejected at the point a payment is recorded — a payment amount greater than the current remaining balance fails validation rather than being accepted and producing a negative balance.

Status recalculation happens in one place (`Invoice::recalculateStatus()`), never duplicated across controllers.

## Deletion

- **Draft:** deletable — no compliance record exists yet.
- **Sent / Partially Paid / Paid:** **never deletable, by anyone, including Admin.** This is enforced at the policy layer, not just hidden in the UI.

## PDF requirements

- Our company name, address, GSTIN, PAN, state, and state code, plus logo — sourced from `company_settings`, shipped with placeholder values
- Invoice number (or "DRAFT" if unsent) and date; due date
- Client's name, billing address, state, and GSTIN (if any); place of supply
- Line items: description, SAC code, quantity, rate, amount, GST rate, CGST/SGST or IGST per line
- Subtotal, tax totals (CGST/SGST or IGST, whichever applies), grand total
- Bank details for payment (account name, number, IFSC, bank name) — from `company_settings`
- Authorised-signatory line
- "Whether tax is payable on reverse charge" — always "No" (confirmed)
