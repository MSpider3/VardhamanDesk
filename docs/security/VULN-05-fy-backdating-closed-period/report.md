# Vulnerability Report: VULN-05 — Statutory Financial Year Boundary Bypass via Draft Backdating

- **Vulnerability ID**: VULN-05
- **Severity**: High (CVSS: 7.4 - `CVSS:3.1/AV:N/AC:L/PR:L/UI:N/S:U/C:N/I:H/A:N`)
- **Status**: Fixed in v1.1.0
- **Target Component**: `app/Services/InvoiceSendService.php`
- **Assessed Release**: v1.0.0
- **Fixed Release**: v1.1.0

---

## Summary

Under Indian GST statutory guidelines, once a financial year's annual returns (e.g. GSTR-9) and monthly returns are filed, no new invoices can be inserted into that closed financial year sequence. 

In VardhamanDesk v1.0.0, users could create a Draft invoice with a backdated `invoice_date` (e.g. 15 March 2025) and execute the Send action months later, after dozens of `2026-27` invoices had already been issued. `InvoiceSendService` derived the financial year directly from the user-editable `invoice_date` at send time, allocating the next sequence from the old FY (`2025-26`), injecting new invoices into closed statutory periods.

---

## Root Cause Analysis

In `InvoiceSendService.php`:
```php
public function send(Invoice $invoice): Invoice
{
    // Derived straight from user-editable draft date with no boundary check
    $fy = $this->deriveFinancialYear($invoice->invoice_date);
    $seq = $this->getNextSequenceNumber($fy);
    ...
}
```

The system failed to verify whether the business had already moved on to subsequent financial years. If `financial_year_counters` contained sequences for `2026-27`, sending a draft dated in `2025-26` was permitted unconditionally.

---

## Remediation

In `app/Services/InvoiceSendService.php`, introduced a boundary check comparing the invoice's derived FY against the highest historical financial year recorded in `financial_year_counters`:

```php
$maxFy = FinancialYearCounter::max('financial_year');

if ($maxFy && strcmp($fy, $maxFy) < 0) {
    throw new DomainException(
        "This invoice's date falls in an already-closed financial year ({$fy}); update the invoice date to fall within {$maxFy}, or contact an admin."
    );
}
```

- If `$fy < $maxFy`, the operation is rejected immediately.
- Current FY invoices (`$fy === $maxFy`) or forward-dated invoices crossing into future FYs (`$fy > $maxFy`) proceed normally.

---

## Test Verification

Automated regression coverage added in `tests/Feature/InvoiceSendAndNumberingTest.php`:
- `test('sending invoice into an already-closed financial year is rejected')`
- `test('sending draft dated in a future financial year succeeds')`

Verified passing on both SQLite and MySQL 8.
