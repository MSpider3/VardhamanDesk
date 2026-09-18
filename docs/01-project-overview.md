# VardhamanDesk — Project Overview

## What this is

A small internal Laravel app that tracks the sales pipeline end to end:

```
Lead → Qualified Lead → Client → Invoice → Payment(s) → Paid
```

It is not a generic CRM. It exists to answer three questions for the business:
1. Who do we need to follow up with today?
2. What have we invoiced this month, and what's still outstanding?
3. Is every invoice GST-compliant and correctly numbered?

## Modules

| Module | Purpose |
|---|---|
| **Auth & Roles** | Admin (sees everything) vs Sales (sees only what they own) |
| **Leads** | Capture + pipeline status + notes + follow-up dates |
| **Clients** | What a Lead becomes once Converted; adds billing/tax identity |
| **Invoices** | Line-item billing against a Client, with GST computed per Indian rules |
| **Payments** | Ledger entries against an Invoice; drives invoice status |
| **Dashboard** | Today's follow-ups + this month's invoiced/received/outstanding totals |

## Tech stack

- **Framework:** Laravel 13.x (latest stable release)
- **DB:** MySQL 8
- **UI:** Filament 5.x (admin panel framework on top of Livewire 4.x) for *all* CRUD — see `05-assumptions-and-open-questions.md`
- **PDF:** `barryvdh/laravel-dompdf` (or maintained Laravel 13 compatible PDF package) for invoice PDFs
- **Auth:** Laravel's built-in auth + a `role` enum column, not a full permissions package like Spatie — the brief only needs two roles with simple ownership rules, so a package is more machinery than the problem needs. Flagged as a judgment call in assumptions.
- **Testing:** Pest (PHPUnit-compatible)

## Why the two hard parts are hard

**GST is not a flat rate, and not even one rate per invoice.** Each line item can carry its own rate (confirmed slabs: 0/5/18/40%), and whether a line is intra-state (CGST + SGST) or inter-state (IGST) depends on comparing *our* registered state (Rajasthan) against the invoice's place of supply. Get this wrong and every invoice is non-compliant. See `03-gst-and-invoicing-rules.md`.

**Invoice numbers must never collide or skip unpredictably.** `VI/2026-27/0001` encodes the Indian financial year (Apr 1–Mar 31) into the number itself, and the sequence resets at the start of each FY. Numbers are allocated only when an invoice is marked Sent (a Draft has none), so a deleted Draft never burns a number — but two Sent actions firing at the same instant must still never produce the same number. See `02-database-schema.md` for the locking strategy.

## Build order (matches your deliverables)

1. DB design reviewed with you (this doc set)
2. Leads + Users working
3. Clients + Invoices working
4. Payments + PDF + Dashboard + Tests, then 30-min demo
