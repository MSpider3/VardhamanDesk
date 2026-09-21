# GST Rate Freezing and Dynamic CI APP_KEY Summary

This document records the analysis, implementation, and verification for the two issues addressed:
1. **GST Rate Editing Retroactively Changes Historical Invoice PDFs**
2. **Plaintext `APP_KEY` in Repository Configurations**

---

## 1. GST Rate Editing Retroactively Changes Historical Invoice PDFs

### The Problem
In `resources/views/invoices/pdf.blade.php`, line items displayed their tax percentage via the live Eloquent relationship `$item->gstRate?->rate` rather than a frozen historical value. While tax amounts (`cgst_amount`, `sgst_amount`, `igst_amount`, `amount`) were correctly frozen on the `invoice_items` table at Send time, the tax rate percentage was dynamically fetched.

If an administrator modified an existing GST rate record (for example, if statutory GST Council slabs changed from 18% to 28%) instead of creating a new rate and deactivating the old one, every previously sent and paid invoice rendered using that rate would retroactively display `(14%)` next to tax amounts that were actually calculated and charged at 9% (18% total). On legal tax documents, this created an inconsistency between printed rates and billed amounts.

### What Changed
1. **Database Schema**:
   - Added `gst_rate_percent` (`decimal(5,2)`, nullable) to `invoice_items` via migration `2026_09_21_121633_add_gst_rate_percent_to_invoice_items_table.php`.
   - Included a backfill migration statement updating existing `invoice_items` with their corresponding `gst_rates.rate`.
2. **Models & Services**:
   - Added `gst_rate_percent` to `$fillable` and `$casts` (`'decimal:2'`) on `App\Models\InvoiceItem`.
   - Updated `App\Services\InvoiceCalculationService::calculate()` to include `'gst_rate_percent' => number_format($ratePercent, 2, '.', '')`.
   - Updated `App\Services\InvoiceDraftService` to persist `gst_rate_percent` on draft creation and updates.
   - Updated `App\Services\InvoiceSendService::send()` to freeze `gst_rate_percent` onto line items at send time.
3. **PDF Template**:
   - In `resources/views/invoices/pdf.blade.php`, updated CGST, SGST, and IGST line-item rate displays to use `($item->gst_rate_percent ?? $item->gstRate?->rate ?? 0)`.
4. **Model Immutability & UI Protection**:
   - In `App\Models\GstRate::booted()`, added an `updating` listener that throws a `\DomainException` if `rate` is modified while the record is referenced by any `invoice_items` row.
   - Added a `deleting` listener in `GstRate::booted()` throwing a `\DomainException` if a rate with existing invoice items is deleted.
   - In `App\Filament\Resources\GstRates\Schemas\GstRateForm`, disabled the `rate` input when referenced by invoice items and added helper text prompting the user to create a new GST rate and deactivate the old one.

### Verification
- Added automated feature tests in `tests/Feature/GstRateImmutabilityAndPdfTest.php`:
  - Verified that updating a `GstRate` row in the database does not alter the rendered PDF rate percentages on previously sent invoices (tested for both intra-state and inter-state invoices).
  - Verified that updating `rate` on an in-use `GstRate` model or attempting to delete it throws a `\DomainException`.
  - Verified that non-rate attributes (`label`, `is_active`) remain editable.

---

## 2. Dynamic CI APP_KEY Generation and Secret Removal

### The Problem
A valid Laravel encryption key was previously hardcoded into `phpunit.xml`, `phpunit.mysql.xml`, and `.github/workflows/ci.yml` to resolve a `MissingAppKeyException` during automated testing. Although scoped to testing environments, hardcoded keys in version control trigger secret scanner alerts and pose copy-paste hygiene risks.

### What Changed
1. **GitHub Actions Workflow (`.github/workflows/ci.yml`)**:
   - Removed the hardcoded `APP_KEY` from the workflow `env:` block.
   - Added a setup step prior to test execution:
     ```yaml
     - name: Prepare Environment & Generate Test Key
       run: |
         cp .env.example .env
         php artisan key:generate --force
     ```
2. **Environment & PHPUnit Configuration**:
   - Removed the hardcoded `APP_KEY` entries from both `phpunit.xml` and `phpunit.mysql.xml`.
   - Updated `.env.example` to leave `SEED_DEFAULT_PASSWORD=` blank.
   - Added forced blank environment overrides for `SEED_DEFAULT_PASSWORD` and `APP_BOOTSTRAP_ADMIN_PASSWORD` in `phpunit.xml` and `phpunit.mysql.xml` to ensure isolated, repeatable test runs.

### Verification
- Ran full test suite locally on SQLite (`./vendor/bin/pest`): 132 passed (603 assertions).
- Ran full test suite locally on MySQL 8 (`composer test:mysql`): 132 passed (603 assertions).
- Verified GitHub Actions CI run `35599384217`: both `PHP 8.4 - sqlite` and `PHP 8.4 - mysql` passed cleanly.
