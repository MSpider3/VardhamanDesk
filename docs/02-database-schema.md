# Database Design

This is the artifact to share with the client before any code is written.

*Updated to reflect confirmed decisions in `05-assumptions-and-open-questions.md` — this version supersedes the first draft. Key changes: GST moved to per-line-item, invoice numbers allocate on Send (not creation), clients gained their own `assigned_to`, and two new tables (`gst_rates`, `company_settings`) were added. The GST slab list is now independently verified against CBIC Notification No. 9/2025-Central Tax (Rate), not just taken on the client's word.*

## ER summary

```
users (1) ──< leads (assigned_to)
users (1) ──< lead_notes (created_by)
leads (1) ──< lead_notes
leads (1) ──1 clients (nullable, set on conversion; clients can also exist with no lead)
users (1) ──< clients (assigned_to = current owner, reassignable by Admin)
users (1) ──< clients (created_by = original creator, fixed audit trail)
clients (1) ──< invoices
users (1) ──< invoices (created_by)
invoices (1) ──< invoice_items
invoice_items (>1) ──1 gst_rates (each line item references a rate)
invoices (1) ──< payments
users (1) ──< payments (recorded_by)
```

## Tables

### `users`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | varchar | |
| email | varchar unique | |
| password | varchar | hashed |
| role | enum('admin','sales') | drives every ownership check |
| timestamps | | |

### `leads`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | varchar | contact person |
| company | varchar nullable | |
| phone | varchar nullable | |
| email | varchar nullable | |
| source | enum('referral','bni','website','cold_call','event','other') | fixed dropdown, confirmed |
| assigned_to | bigint FK → users.id | current owner; Admin can reassign |
| status | enum('new','contacted','qualified','converted','lost') | any status can move to any other **except** once `converted`, it is terminal — no further changes. `lost` is not terminal and can be reopened. |
| next_follow_up_date | date nullable | denormalized copy of the latest note's follow-up date, updated whenever a note sets one; **overdue** = this date is before today and status isn't `converted`/terminal |
| timestamps | | |
| `deleted_at` | soft delete | |

### `lead_notes`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| lead_id | bigint FK → leads.id | |
| created_by | bigint FK → users.id | |
| note | text | |
| follow_up_date | date nullable | writing this updates `leads.next_follow_up_date` |
| timestamps | | full history, never edited/deleted; **remains visible on the Client's page after conversion** (read-through via `clients.lead_id`, not copied) |

### `clients`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| lead_id | bigint FK → leads.id, **nullable** | set when converted from a lead; **null is a valid, expected case** — existing clients are onboarded directly with no lead |
| assigned_to | bigint FK → users.id | **current owner** — defaults to the lead's `assigned_to` at conversion, or the creating user if added directly; Admin can reassign independently of the original lead |
| created_by | bigint FK → users.id | fixed audit trail of who originally created the record; never changes on reassignment |
| name | varchar | |
| company | varchar nullable | |
| phone | varchar nullable | |
| email | varchar nullable | |
| billing_address | text | |
| state | varchar | constrained to the fixed list of Indian states/UTs with their official 2-digit GST state codes (e.g. Rajasthan = `08`) — needed both for the CGST+SGST/IGST split and for GSTIN validation below |
| gstin | varchar(15) nullable | optional (unregistered buyers are valid); **validated, not just stored** — format-checked against the standard GSTIN pattern, and its first two digits must match `state`'s official GST state code. Checksum digit validation is a nice-to-have, not a v1 requirement. |
| timestamps | | |
| `deleted_at` | soft delete | |

### `gst_rates`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| rate | decimal(5,2) | `0.00`, `5.00`, `18.00`, `40.00` — **verified** against CBIC Notification No. 9/2025-Central Tax (Rate), dated 17 Sep 2025, effective 22 Sep 2025 (see `03-gst-and-invoicing-rules.md`) |
| label | varchar | display label, e.g. "18% GST" |
| is_active | boolean default true | inactive rates stay for historical invoices but drop out of new-invoice pickers |
| timestamps | | |

### `company_settings`
Single-row (or key/value) table holding the business's own details for PDF generation. Ship with placeholder values; the client fills in real ones before go-live.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| company_name | varchar | |
| address | text | |
| gstin | varchar(15) | company's own GSTIN (Rajasthan-registered) |
| pan | varchar(10) | |
| state | varchar | |
| state_code | varchar(2) | |
| logo_path | varchar nullable | |
| bank_account_name | varchar | |
| bank_account_number | varchar | |
| bank_ifsc | varchar | |
| bank_name | varchar | |
| authorised_signatory_name | varchar | |
| timestamps | | |

### `invoices`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| client_id | bigint FK → clients.id | |
| invoice_number | varchar unique, **nullable** | **null while Draft** — a Draft displays "DRAFT" in the UI, not a number. Populated only at the Draft→Sent transition. |
| financial_year | varchar(7) nullable | e.g. `2026-27`; set at the same moment as `invoice_number` |
| sequence_number | unsigned int nullable | the `0001` part, unique **per financial_year**; also set only on Send |
| place_of_supply | varchar | the client's billing state at the time of invoicing (copied, not live-referenced, so a later client address change doesn't rewrite history); this is what actually decides the CGST+SGST/IGST split |
| status | enum('draft','sent','partially_paid','paid') | |
| invoice_date | date | |
| due_date | date, **required** | defaults to `invoice_date + 30 days`, editable |
| subtotal | decimal(12,2) | sum of `invoice_items.amount` |
| cgst_amount | decimal(12,2) | sum of each line item's CGST portion; 0 if inter-state |
| sgst_amount | decimal(12,2) | sum of each line item's SGST portion; 0 if inter-state |
| igst_amount | decimal(12,2) | sum of each line item's IGST portion; 0 if intra-state |
| total | decimal(12,2) | subtotal + all tax columns |
| created_by | bigint FK → users.id | |
| timestamps | | |
| `deleted_at` | **not used for Sent/Paid** — see deletion rule below |

*Tax totals are now aggregated **up from line items** (each of which has its own rate), not computed once at the invoice level — this is the schema-level change from the original flat-rate design.*

**Deletion rule (stricter than the general soft-delete default):** a Draft invoice can be deleted outright — no number was ever allocated, so nothing is lost. Once an invoice is Sent (or Paid), **deletion is blocked entirely at the policy layer** — not soft-deleted-and-hidden, genuinely not deletable by anyone including Admin, because it's a legal financial document.

### `invoice_items`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| invoice_id | bigint FK → invoices.id | |
| description | varchar | |
| sac_code | varchar | required; defaults to a placeholder IT-services code, confirmed with the client's CA before go-live |
| quantity | decimal(10,2) | decimal, not int |
| rate | decimal(12,2) | unit price |
| amount | decimal(12,2) | quantity × rate, stored |
| gst_rate_id | bigint FK → gst_rates.id | **rate lives per line item**, not per invoice |
| cgst_amount | decimal(12,2) | computed at save time from `amount`, `gst_rate`, and whether the invoice is intra-/inter-state |
| sgst_amount | decimal(12,2) | " |
| igst_amount | decimal(12,2) | " |
| timestamps | | locked (no edits) once the parent invoice is Sent |

### `payments`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| invoice_id | bigint FK → invoices.id | |
| amount | decimal(12,2) | **validated at write time: must not exceed the invoice's current remaining balance** — overpayments are rejected, not just flagged |
| payment_date | date | drives the dashboard's "received this month" figure |
| method | enum('bank_transfer','upi','cheque','cash') | fixed list, confirmed |
| reference_note | varchar nullable | UTR / cheque number / UPI txn ID — optional |
| recorded_by | bigint FK → users.id | |
| timestamps | | never edited after creation; corrections are a new (possibly negative-adjustment, if ever needed) entry — auditable ledger |

## Invoice numbering — concurrency strategy

Trigger point: **the Draft → Sent transition**, not invoice creation.

1. A `financial_year_counters` table: `financial_year` (PK), `last_sequence` (unsigned int).
2. Inside the DB transaction that performs the Send action: `SELECT ... FOR UPDATE` the counter row for the current FY (creating it at `0` if it doesn't exist yet), increment it, use that value as `sequence_number`, format it into `invoice_number`, set `financial_year`, flip `status` to `sent`, commit.
3. This guarantees no two invoices in the same FY ever get the same number, and — because Drafts never consume a number — deleting a Draft never leaves a gap in the sequence.

FY boundary: April 1–March 31. A Send action on 2026-03-15 uses FY `2025-26`; on 2026-04-15 it uses FY `2026-27`.

## Ownership / row-level access

Every query against `leads`, `lead_notes`, `clients`, `invoices`, and `payments` is scoped by a global query scope: Admin bypasses it, Sales is filtered to rows where they are the current owner — `leads.assigned_to` for leads, **`clients.assigned_to`** for clients (not the originating lead's assignee, since a client can be reassigned independently or have no lead at all), and the owning client's `assigned_to` for invoices/payments. Enforced in the model layer (Eloquent global scopes), not just hidden in the UI.
