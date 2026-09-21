# Vulnerability Report: VULN-03 — Financial Year Sequence Counter Bootstrap Race Condition

- **Vulnerability ID**: VULN-03
- **Severity**: Medium (CVSS: 6.5 - `CVSS:3.1/AV:N/AC:H/PR:L/UI:N/S:U/C:N/I:H/A:L`)
- **Status**: Fixed in v1.1.0
- **Target Component**: `app/Services/InvoiceSendService.php`
- **Assessed Release**: v1.0.0
- **Fixed Release**: v1.1.0

---

## Summary

In VardhamanDesk v1.0.0, when sending the very first invoice in a new financial year (e.g. crossing April 1st), `InvoiceSendService` called `FinancialYearCounter::firstOrCreate(['financial_year' => $fy])` prior to locking the row. 

Under concurrent invoice issuance, two parallel requests could both attempt `firstOrCreate()` simultaneously. Because `financial_year` is enforced as unique in the schema, the second request crashed with an unhandled database unique constraint violation (`QueryException: Duplicate entry '2026-27' for key 'financial_year'`), failing the invoice send operation.

---

## Technical Analysis

### Vulnerable Implementation (v1.0.0)
```php
protected function getNextSequenceNumber(string $fy): int
{
    // Non-atomic bootstrap
    FinancialYearCounter::firstOrCreate(
        ['financial_year' => $fy],
        ['last_sequence' => 0]
    );

    // Row lock acquired AFTER creation attempt
    $counter = FinancialYearCounter::where('financial_year', $fy)
        ->lockForUpdate()
        ->firstOrFail();

    $counter->last_sequence += 1;
    $counter->save();

    return $counter->last_sequence;
}
```

If Request 1 and Request 2 both call `getNextSequenceNumber("2026-27")`:
1. Request 1 queries `SELECT * FROM financial_year_counters WHERE financial_year = '2026-27' LIMIT 1` -> empty.
2. Request 2 queries `SELECT * FROM financial_year_counters WHERE financial_year = '2026-27' LIMIT 1` -> empty.
3. Request 1 inserts row `(financial_year: '2026-27', last_sequence: 0)`.
4. Request 2 attempts to insert row `(financial_year: '2026-27', last_sequence: 0)`.
5. MySQL rejects Request 2 with `SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry`.
6. Request 2 fails catastrophically instead of waiting for the lock and allocating sequence #2.

---

## Remediation

In `app/Services/InvoiceSendService.php`, replaced `firstOrCreate` with an atomic creation attempt inside a `try ... catch (QueryException)` block, followed immediately by `lockForUpdate()`:

```php
protected function getNextSequenceNumber(string $fy): int
{
    try {
        FinancialYearCounter::create([
            'financial_year' => $fy,
            'last_sequence' => 0,
        ]);
    } catch (\Illuminate\Database\QueryException $e) {
        // Unique constraint violation indicates another concurrent thread created the counter row.
        // We safely ignore this error and proceed to acquire the exclusive row lock.
    }

    $counter = FinancialYearCounter::where('financial_year', $fy)
        ->lockForUpdate()
        ->firstOrFail();

    $counter->last_sequence += 1;
    $counter->save();

    return $counter->last_sequence;
}
```

---

## Test Verification

Automated regression coverage added in `tests/Feature/InvoiceSendConcurrencyTest.php`:
- `test('invoice send safely bootstraps brand new financial year counter under simulated concurrency')`
- `test('financial year counter increments sequences sequentially without gaps or duplicates under concurrency')`

Verified passing on both SQLite and MySQL 8.
