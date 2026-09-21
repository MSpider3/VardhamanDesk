# Vulnerability Report: VULN-09 — Premature Financial Year Lockout via Forward-Dated Invoices

- **Vulnerability ID**: VULN-09
- **Severity**: Medium / Business Logic Flaw (CVSS: 5.3 - `CVSS:3.1/AV:N/AC:L/PR:L/UI:N/S:U/C:N/I:N/A:H`)
- **Status**: Fixed in v1.1.0
- **Target Component**: `app/Services/InvoiceSendService.php`
- **Assessed Release**: v1.0.1
- **Fixed Release**: v1.1.0

---

## Summary

In VardhamanDesk v1.0.1, the guard introduced to prevent statutory backdating into closed financial years compared the candidate invoice's financial year against `FinancialYearCounter::max('financial_year')`. If a user issued or drafted a legitimate forward-dated invoice (for example, sending an advance contract invoice dated in `2027-28`), the maximum counter was established at `2027-28`. As a direct result, all subsequent invoices dated in the actual open calendar financial year (`2026-27`) were permanently rejected with an unhandled domain exception (`This invoice's date falls in an already-closed financial year`), halting standard billing operations across the company.

---

## Root Cause Analysis

In `app/Services/InvoiceSendService.php`, the backdating validation checked:
```php
$maxFy = FinancialYearCounter::max('financial_year');
if ($maxFy && strcmp($fy, $maxFy) < 0) {
    throw new DomainException("This invoice's date falls in an already-closed financial year ({$fy}); update the invoice date to fall within {$maxFy}, or contact an admin.");
}
```

This logic erroneously equated "the highest financial year sequence ever initialized" with "the current open financial year". Under Indian accounting standards and typical enterprise billing practices, forward-dated contracts or advance invoices may be issued ahead of time. Elevating the boundary floor to the forward year permanently closed the current calendar year while it was still legally and operationally open.

---

## Proof of Concept & Reproduction

1. Issue an invoice in current financial year `2026-27`:
   - Invoice `VI/2026-27/0001` succeeds.
2. Issue a forward-dated invoice in `2027-28`:
   - Invoice `VI/2027-28/0001` succeeds and creates counter `2027-28`.
3. Issue another normal invoice dated for the current month in `2026-27`:
   - Attempting to send triggers `DomainException`:
     ```text
     This invoice's date falls in an already-closed financial year (2026-27); update the invoice date to fall within 2027-28, or contact an admin.
     ```
4. **Impact**: All sales and billing staff are completely locked out from issuing invoices for the ongoing financial year.

---

## Remediation

In `app/Services/InvoiceSendService.php`, changed the reference point of the backdating check to today's actual calendar financial year:
```php
$currentFy = self::deriveFinancialYear(Carbon::now());
if (strcmp($fy, $currentFy) < 0) {
    throw new DomainException("This invoice's date falls in an already-closed financial year ({$fy}); update the invoice date to fall within {$currentFy}, or contact an admin.");
}
```

The system now permits invoices dated in the current open FY and any future FYs, while strictly rejecting invoices whose dates fall into historical, closed financial years.

---

## Test Verification

Automated regression coverage added in `tests/Feature/InvoiceSendAndNumberingTest.php`:
- `test('Item 9: invoice send rejects backdating into a closed financial year')`:
  Verifies the 4-step sequence:
  1. Send standard current FY invoice -> succeeds (`VI/2026-27/0001`).
  2. Send backdated prior FY invoice -> rejected (`DomainException`).
  3. Send forward-dated FY invoice -> succeeds (`VI/2027-28/0001`).
  4. Send subsequent current FY invoice -> **succeeds** (`VI/2026-27/0002`).

Verified passing on both SQLite and MySQL 8.
