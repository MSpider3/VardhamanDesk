# Second-Pass Production Hardening Summary

This document provides a concise, plain-language engineering record of the 5 audit findings identified in `docs/09-second-pass-hardening-findings.md` following the initial hardening pass.

Each item details:
1. **What was actually wrong**: The root cause and observed failure.
2. **What changed**: The minimal, surgical fix implemented.
3. **Architectural rationale & tradeoffs**: Why this approach was selected.
4. **Verification**: Automated test proof across SQLite and MySQL 8 engines.

---

## 1. `app:bootstrap` Production Password Security

- **What was wrong**: When running `php artisan app:bootstrap` in production environments without the `--password` flag or `APP_BOOTSTRAP_ADMIN_PASSWORD` (or `SEED_DEFAULT_PASSWORD`), the command silently fell back to `'password'`. This left production deployments vulnerable to default administrator credentials (`admin@vardhamandesk.local` with password `'password'`).
- **What changed**:
  - Updated `app/Console/Commands/AppBootstrapCommand.php`:
    - Checks `app()->isProduction()`.
    - If in production and password is missing or equals the default `'password'`, execution aborts immediately with a clear error: `"In production, an explicit, secure password must be provided via --password or the APP_BOOTSTRAP_ADMIN_PASSWORD / SEED_DEFAULT_PASSWORD environment variable."` and returns exit code 1.
    - In non-production environments (local/testing), prompts interactively if running in a TTY, or safely defaults for automated CI.
- **Architectural rationale**:
  - Eliminates silent, insecure credential provisioning on production systems.
  - Keeps local dev and CI pipelines convenient without compromising production security boundaries.
- **Verification**:
  - Added test in `tests/Feature/AppBootstrapCommandTest.php` running under `APP_ENV=production` without password input. Asserted that the command fails with exit code 1 and outputs the rejection notice. Passes on SQLite and MySQL 8.

---

## 2. Financial Year Backdating Guard Boundary (Real Open FY Reference)

- **What was wrong**: `InvoiceSendService::send()` previously compared the invoice's derived FY against `FinancialYearCounter::max('financial_year')`. If a user issued or drafted a forward-dated invoice (e.g., dated in `2027-28`), `max('financial_year')` became `2027-28`. Consequently, all subsequent invoices dated in the actual open year (`2026-27`) were incorrectly rejected as "backdating into a closed financial year", causing a permanent lockout of normal operations.
- **What changed**:
  - In `app/Services/InvoiceSendService.php`, changed the boundary check reference from `FinancialYearCounter::max('financial_year')` to `self::deriveFinancialYear(Carbon::now())` (the real financial year derived from current time).
  - An invoice is now rejected only if its derived FY is strictly earlier than today's active financial year (`strcmp($fy, $currentFy) < 0`).
  - Forward-dated invoices remain permitted and will allocate their own sequence when sent, without locking out ongoing billing in the current financial year.
- **Architectural rationale**:
  - The business rule intent is to prevent issuing invoices into historical FYs whose statutory GST returns have already been closed and filed.
  - Comparing against current calendar FY cleanly permits forward-dated billing while permanently closing past years, avoiding artificial state-machine lockouts.
- **Verification**:
  - Extended test in `tests/Feature/InvoiceSendAndNumberingTest.php` with a 4-step sequence:
    1. Send standard 2026-27 invoice -> succeeds (`VI/2026-27/0001`).
    2. Attempt sending backdated 2025-26 invoice -> rejected (`DomainException`).
    3. Send forward-dated 2027-28 invoice -> succeeds (`VI/2027-28/0001`).
    4. Send another standard 2026-27 invoice -> **still succeeds** (`VI/2026-27/0002`). Passes on SQLite and MySQL 8.

---

## 3. Invoices List N+1 Query Scaling on Zero-Payment Invoices

- **What was wrong**: In `InvoicesTable.php`, the query builder used `withSum('payments as paid_amount', 'amount')`. When an invoice has zero payments (the standard initial state for sent invoices), SQL `SUM()` returns `NULL` rather than `0`. In `app/Models/Invoice.php`, `getPaidAmountAttribute()` checked `$this->attributes['paid_amount'] !== null`. When `NULL`, it fell through to `$this->payments()->sum('amount')`, triggering a live query per row. On a page of 50 newly sent invoices with zero payments, 50 extra database queries were executed.
- **What changed**:
  - In `app/Filament/Resources/Invoices/Tables/InvoicesTable.php`:
    - Replaced `withSum` with explicit `COALESCE` subquery: `selectRaw('COALESCE((SELECT SUM(payments.amount) FROM payments WHERE payments.invoice_id = invoices.id), 0) as paid_amount')`.
  - In `app/Models/Invoice.php`:
    - Updated `getPaidAmountAttribute()` to check `array_key_exists('paid_amount', $this->attributes)` and treat `null` as `0.00` rather than falling through to lazy evaluation.
    - Updated `getOutstandingAmountAttribute()` similarly to avoid queries when projected.
- **Architectural rationale**:
  - Pushes default coalescing down to the database engine.
  - Ensures constant query count $O(1)$ regardless of whether invoices have 0, 1, or 50 payments.
- **Verification**:
  - Updated `tests/Feature/InvoiceListQueryTest.php` with a realistic fixture mix (majority zero-payment invoices, some with payments). Asserted query count remains strictly constant (12 queries) whether rendering 5 or 50 invoices. Passes on SQLite and MySQL 8.

---

## 4. Unique Index on `clients.lead_id` with Soft Deletes

- **What was wrong**: A database unique index exists on `clients.lead_id` to prevent duplicate client creation. However, `clients` uses `SoftDeletes`, while `lead.status = Converted` is terminal. If an invoice-free client was soft-deleted, the lead was left permanently orphaned: it could not be reopened, and attempting to re-convert it failed with a raw database `QueryException` or domain lock due to the soft-deleted row retaining the `lead_id`.
- **What changed**:
  - In `app/Services/ClientService.php` (`convertLeadToClient`):
    - Checks for an existing client using `Client::withoutGlobalScopes()->withTrashed()->where('lead_id', $lead->id)->first()`.
    - If an active (non-deleted) client exists, throws `DomainException('This lead has already been converted.')`.
    - If a soft-deleted client exists, cleanly restores it (`$existingClient->restore()`), updates its contact/business details with the provided `$clientData`, synchronizes lead notes, and returns the restored client instance.
- **Architectural rationale**:
  - Preserves data lineage and prevents orphan states without altering schema indices or weakening MySQL unique constraints.
  - Restoring the soft-deleted client recovers the client entity cleanly while satisfying the unique constraint on `lead_id`.
- **Verification**:
  - Added test in `tests/Feature/LeadConversionConcurrencyTest.php` simulating lead conversion, soft deletion of the resulting client, followed by re-conversion. Confirmed the soft-deleted client is successfully restored and updated, lead notes stay synchronized, and exactly one client record exists. Passes on SQLite and MySQL 8.

---

## 5. Sequence Counter Race Guard Optimization

- **What was wrong**: In `InvoiceSendService::getNextSequenceNumber()`, the service unconditionally called `FinancialYearCounter::create(['financial_year' => $fy, ...])` inside a `try ... catch (QueryException)` block on every send. For ordinary operations where the counter already exists, this deliberately triggered and swallowed a duplicate key exception on every single invoice send, producing error noise in database logs and APM monitors.
- **What changed**:
  - Added a lightweight existence check before attempting creation:
    ```php
    if (! FinancialYearCounter::where('financial_year', $fy)->exists()) {
        try {
            FinancialYearCounter::create([
                'financial_year' => $fy,
                'current_sequence' => 0,
            ]);
        } catch (QueryException) {
            // Concurrently created by another transaction; proceed to lock.
        }
    }
    ```
  - Followed immediately by the atomic pessimistic lock: `FinancialYearCounter::where('financial_year', $fy)->lockForUpdate()->firstOrFail()`.
- **Architectural rationale**:
  - In steady state (99.99% of requests), zero exceptions are thrown or logged.
  - In the initial race condition scenario (first invoice of the year), the `try ... catch` continues to cleanly handle concurrent inserts without deadlock or unhandled crashes.
- **Verification**:
  - Validated with `tests/Feature/InvoiceSendConcurrencyTest.php` and `tests/Feature/InvoiceSendAndNumberingTest.php`. Passes cleanly on SQLite and MySQL 8.

---

## Verification Summary

All 5 items were developed test-first using red/green reproduction tests. Both test suites execute with 100% pass rates:

- **SQLite Test Suite**: `125 passed, 563 assertions` (via `./vendor/bin/pest`)
- **MySQL 8 Test Suite**: `125 passed, 563 assertions` (via `composer test:mysql`)
- **Code Style**: `vendor/bin/pint --test` (0 violations)
- **Security & Privacy**: Zero personal identifiers, zero machine-specific local paths, and zero default production secrets across the codebase.
