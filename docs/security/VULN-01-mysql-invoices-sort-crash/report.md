# Vulnerability Report: VULN-01 — Invoices Table Missing Column Crash on Sort (Denial of Service)

- **Vulnerability ID**: VULN-01
- **Severity**: Medium (CVSS: 5.3 - `CVSS:3.1/AV:N/AC:L/PR:L/UI:N/S:U/C:N/I:N/A:H`)
- **Status**: Fixed in v1.1.0
- **Target Component**: `app/Filament/Resources/Invoices/Tables/InvoicesTable.php` & `app/Models/Invoice.php`
- **Assessed Release**: v1.0.0
- **Fixed Release**: v1.1.0

---

## Summary

In VardhamanDesk v1.0.0, clicking the "Paid" or "Outstanding" column headers on the Invoices list page triggered an unhandled database exception (`SQLSTATE[42S22]: Column not found: 1054 Unknown column 'paid_amount' in 'order clause'`) when running on MySQL 8. This caused an HTTP 500 Internal Server Error and rendered the invoicing management interface completely inaccessible whenever sorting by these columns was requested.

---

## Root Cause Analysis

In Laravel Filament, declaring a table column as `sortable()` causes Filament to append an `ORDER BY <column_name> ASC/DESC` clause to the underlying Eloquent query.

In `InvoicesTable.php`, the columns `paid_amount` and `outstanding_amount` were defined as virtual model accessors (`getPaidAmountAttribute` and `getOutstandingAmountAttribute`) in `app/Models/Invoice.php`, rather than actual database columns on `invoices`.

While SQLite's test engine permitted certain sorting fallbacks or unprojected references, MySQL 8 strictly requires that any column referenced in an `ORDER BY` clause either exists in the table schema or is projected in the `SELECT` list. Because the query issued was `SELECT invoices.* FROM invoices ORDER BY paid_amount ASC`, MySQL aborted query execution with:
```sql
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'paid_amount' in 'order clause'
```

---

## Proof of Concept & Reproduction

1. Seed invoices with associated payments on MySQL 8:
   ```bash
   php artisan migrate:fresh --seed
   ```
2. Navigate to `http://localhost:8000/admin/invoices`.
3. Request sorting by paid amount:
   ```http
   GET /admin/invoices?tableSortColumn=paid_amount&tableSortDirection=asc HTTP/1.1
   Host: localhost:8000
   ```
4. **Observed Output** (Before Fix):
   HTTP 500 Internal Server Error with MySQL exception `Column not found: 1054 Unknown column 'paid_amount' in 'order clause'`.

---

## Remediation

1. In `app/Filament/Resources/Invoices/Tables/InvoicesTable.php`, modified the base query builder via `modifyQueryUsing()` to project `paid_amount` and `outstanding_amount` directly via SQL subqueries:
   ```php
   ->modifyQueryUsing(function (Builder $query) {
       return $query
           ->select('invoices.*')
           ->withSum('payments as paid_amount', 'amount')
           ->selectRaw('(invoices.total - COALESCE((SELECT SUM(payments.amount) FROM payments WHERE payments.invoice_id = invoices.id), 0)) as outstanding_amount');
   })
   ```
2. In `app/Models/Invoice.php`, updated the accessors to use the projected attributes directly, preventing redundant queries and N+1 query scaling:
   ```php
   public function getPaidAmountAttribute(): float
   {
       if (array_key_exists('paid_amount', $this->attributes)) {
           return (float) ($this->attributes['paid_amount'] ?? 0.0);
       }
       return (float) $this->payments()->sum('amount');
   }
   ```

---

## Test Verification

Automated regression coverage added in `tests/Feature/InvoiceListQueryTest.php`:
- `test('invoices table query can be ordered by paid_amount ascending and descending on MySQL')`
- `test('invoices table query can be ordered by outstanding_amount ascending and descending on MySQL')`
- `test('invoices table does not suffer from N+1 queries when loading invoices with payments')`

Verified passing on both SQLite and MySQL 8.
