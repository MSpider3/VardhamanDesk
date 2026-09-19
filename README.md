# VardhamanDesk

VardhamanDesk is an internal sales pipeline tracking, GST billing, and payment ledger management system built for Vardhaman Infotech using Laravel 13, Filament 5, and MySQL 8.

---

## Technology Stack

- **PHP**: 8.3+ (tested on PHP 8.5.10 CLI with `pdo_mysql`, `mbstring`, `curl`, `xml`, `zip`, `bcmath`)
- **Framework**: Laravel 13.32+
- **Admin UI**: Filament 5.8+ (Livewire 4.4+)
- **Database**: MySQL 8.0+
- **PDF Generation**: `barryvdh/laravel-dompdf` (v3.1.2) with `dompdf/dompdf` (v3.1.6)
- **Testing**: Pest 4.7+ (`pestphp/pest-plugin-laravel`)
- **Code Style**: Laravel Pint

---

## Prerequisites & Database Configuration

1. **PHP & Composer**:
   Ensure PHP 8.3+ and Composer 2.x are installed.
2. **MySQL 8 Database**:
   A running MySQL 8 instance on `127.0.0.1:3306`.
   For example, start a Docker container:
   ```bash
   docker run -d --name vardhamandesk-mysql -p 127.0.0.1:3306:3306 \
     -e MYSQL_ROOT_PASSWORD=secret \
     -e MYSQL_DATABASE=vardhamandesk \
     -e MYSQL_USER=vardhaman \
     -e MYSQL_PASSWORD=secret mysql:8
   ```

---

## Local Installation & Quick Start

1. **Clone repository and install PHP dependencies**:
   ```bash
   composer install
   ```

2. **Configure environment**:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
   Ensure `.env` matches your MySQL database configuration:
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=vardhamandesk
   DB_USERNAME=vardhaman
   DB_PASSWORD=secret
   ```

3. **Run database migrations and realistic demo seeders**:
   ```bash
   php artisan migrate:fresh --seed
   ```

4. **Run the automated test suite**:
   ```bash
   ./vendor/bin/pest
   ```

5. **Run code style checks**:
   ```bash
   vendor/bin/pint --test
   ```

6. **Start local development server**:
   ```bash
   php artisan serve
   ```
   Access the web interface at [http://localhost:8000/admin](http://localhost:8000/admin).

---

## Demo Credentials (Development & Review)

| Role | Name | Email | Password | Scope Visibility |
| :--- | :--- | :--- | :--- | :--- |
| **Admin** | Admin User | `admin@vardhamandesk.local` | `password` | Global company-wide visibility; can reassign Leads & Clients |
| **Sales Rep (Sales A)** | Aarav Sharma | `sales@vardhamandesk.local` | `password` | Scoped to assigned Leads, Clients, Invoices, and Payments |
| **Sales Rep 1** | Aarav Sharma | `sales1@vardhamandesk.local` | `password` | Scoped to assigned Leads, Clients, Invoices, and Payments |
| **Sales Rep 2** | Priya Patel | `sales2@vardhamandesk.local` | `password` | Scoped to assigned Leads, Clients, Invoices, and Payments |
| **Sales Rep 3** | Rohan Gupta | `sales3@vardhamandesk.local` | `password` | Scoped to assigned Leads, Clients, Invoices, and Payments |

---

## Core Feature Overview & Architectural Safeguards

### 1. Lead Pipeline & Conversion
- **Lifecycle**: Status transitions freely between `New`, `Contacted`, `Qualified`, and `Lost`. `Converted` is a one-way terminal state.
- **Conversion Flow**: Only `Qualified` leads can be converted into `Clients`. Conversion is atomic and attaches original lead notes to the resulting client record without duplication.
- **Direct Clients**: Clients can also be created directly without an originating lead (for onboarding existing accounts).
- **Ownership & Reassignment**: Client ownership is governed strictly by `clients.assigned_to`. Admins can reassign clients independently of the original lead.

### 2. GST Invoicing & Calculations
- **Per-Line Rates**: GST is calculated per line item against valid slabs (0%, 5%, 18%, 40% based on CBIC Notification No. 9/2025). Default IT service SAC code is `998313`.
- **Place of Supply**: Derived from client's billing state. If matching company state (`08` Rajasthan), tax splits evenly into CGST and SGST; otherwise applies full IGST.
- **GSTIN Validation**: Enforces 15-character alphanumeric format and cross-validates the first 2 state code digits against the client's state.

### 3. Invoice Lifecycle, Numbering & Immutability
- **Draft Stage**: Displays `DRAFT` with no sequence allocated. Deletable by authorized users.
- **Draft → Sent Action**: Allocates unique sequence numbers in format `VI/{FY}/{0001}` (e.g. `VI/2026-27/0001`) inside a database transaction with `SELECT ... FOR UPDATE` row locking on `financial_year_counters`. Sequence resets cleanly across April 1.
- **Hard Immutability**: Once `Sent`, financial fields, dates, client, and line items are permanently locked.
- **Blocked Deletion**: Deletion of `Sent`, `Partially Paid`, or `Paid` invoices is blocked at model and policy layers for all users (including Admin).

### 4. Payments & Ledger
- **Append-Only Ledger**: Payments cannot be edited or deleted. No soft deletes exist on `payments`.
- **Atomic Service (`RecordPayment`)**: Acquires a row lock (`lockForUpdate`) on the invoice, recalculates paid total from the ledger, rejects any overpayment outright, records the payment, and automatically updates invoice status:
  - `0.00` paid → `sent`
  - `> 0.00` and `< total` → `partially_paid`
  - `= total` → `paid`
- **Supported Payment Methods**: Bank Transfer (NEFT/RTGS/IMPS), UPI, Cheque, and Cash with optional reference note (UTR, cheque number, transaction ID).

### 5. Invoice PDF Generation
- Streamed or downloaded via Dompdf.
- Sourced exclusively from persisted database entities and `company_settings`.
- Displays company details, client billing info, place of supply, itemized SAC table, CGST/SGST or IGST tax breakdown, bank details for payment, and authorized signatory line.
- Draft invoices display a prominent "DRAFT INVOICE" watermark and cannot reveal an official number.

### 6. Dashboard v2 Financial Overview
- **Invoiced This Month**: DB `SUM(total)` for Sent, Partially Paid, and Paid invoices issued in the current calendar month. Drafts excluded.
- **Received This Month**: DB `SUM(amount)` of payments received in the current calendar month by `payment_date`.
- **Outstanding Receivables**: DB `SUM(total) - SUM(paid)` for Sent and Partially Paid invoices. Drafts and Paid excluded.
- **Follow-up Widgets**: Overdue follow-ups sorted above Today's follow-ups, scoped per user.

---

## 30-Minute Demonstration Script

To perform the complete end-to-end client demonstration:

1. **Login as Sales Representative 1 (`sales1@vardhamandesk.local`)**:
   - View Dashboard: Notice **Overdue Follow-ups** placed above **Today's Follow-ups**, and the **Financial Overview** cards showing Sales 1's scoped figures.
   - Navigate to **Leads**: Create a new Lead (`Status: New`, `Source: Referral`).
   - Add a Lead Note with a follow-up date. Move status: `New` → `Contacted` → `Qualified`.
   - Click **Convert to Client**: Fill billing address and state (`Rajasthan`). Lead becomes `Converted` (terminal).
   - Navigate to **Clients**: Open the converted client and confirm the lead notes are visible.

2. **Create and Issue Invoice**:
   - In **Invoices**, click **New Invoice**: Select the converted client.
   - Add Item 1: SAC `998313`, Qty `1`, Rate `10,000.00`, GST `18%` (CGST 9% + SGST 9%).
   - Add Item 2: SAC `998319`, Qty `1`, Rate `2,000.00`, GST `5%` (CGST 2.5% + SGST 2.5%).
   - Save as Draft: Notice status is `Draft` and invoice number displays as `DRAFT`.
   - Click **Send Invoice**: Status updates to `Sent`, official number `VI/2026-27/0005` is allocated.
   - Verify that line items and financial fields are now locked against edits.

3. **Record Payments & Verify Immutability**:
   - Click **Record Payment**: Enter `₹5,000.00`, Method: `UPI`, Reference: `UPI-TEST-01`.
   - Verify status transitions to `Partially Paid`, Paid shows `₹5,000.00`, and Outstanding updates to `₹8,900.00`.
   - Attempt an overpayment: Enter `₹10,000.00` in Record Payment. Verify the system rejects it outright with a clear validation error.
   - Record final payment of `₹8,900.00` with `Bank Transfer`. Verify status transitions to `Paid` and Outstanding becomes `₹0.00`.
   - Click **Download PDF**: Verify the clean, formatted GST PDF with tax breakdown, bank details, and signatory line.

4. **Verify Deletion Block & Cross-Owner Security (as Admin)**:
   - Log out and log in as **Admin (`admin@vardhamandesk.local`)**:
   - View Dashboard: Observe company-wide aggregates across all sales representatives.
   - In **Invoices**: Attempt to delete the `Paid` or `Sent` invoice. Verify deletion is permanently blocked.
   - In **Clients**: Reassign a client from Sales Rep 1 to Sales Rep 2.
   - Log in as **Sales Rep 2 (`sales2@vardhamandesk.local`)**: Verify the client, invoices, and payment records now appear in Sales 2's panel, and are no longer accessible to Sales 1.
