# AI History: Milestone 2 — Clients and Invoices

## Objectives & Scope
- Lead-to-Client conversion workflow with atomic state transition and preservation of lead notes on the client record.
- Support direct client creation without an originating lead.
- Enable Admin reassignment of `clients.assigned_to` independently from the originating lead.
- GSTIN format and state-code validation.
- Draft invoice creation with server-side GST calculation (CGST+SGST vs. IGST based on place of supply).
- Draft-to-Sent action allocating unique sequential invoice numbers via transactional row-locking on `financial_year_counters`.
- Hard immutability of Sent invoices and blocking of deletion on Sent/Paid invoices.

## Key Decisions & Architecture
1. **Client Ownership vs. Lead Ownership**:
   - `clients.assigned_to` governs client visibility and derived invoice access. When an Admin reassigns a client, access shifts immediately to the new sales representative without altering the lead's historical owner.
2. **GST Calculation Service**:
   - `InvoiceCalculationService` computes per-line tax using `round($lineTaxable * $rate / 100, 2)`.
   - Compares company state (`08` Rajasthan) with invoice `place_of_supply`. If matching, splits evenly into CGST and SGST; otherwise applies full IGST.
3. **Sequential Numbering with Row Lock**:
   - `InvoiceSendService` uses `DB::transaction()` with `FinancialYearCounter::where('financial_year', $fy)->lockForUpdate()->first()`.
   - Generates numbers in the format `VI/{FY}/{0001}` (e.g. `VI/2026-27/0001`).
   - Resets sequence counters automatically when a new financial year starts on April 1.
4. **Sent Invoice Protection**:
   - Eloquent `deleting` hook throws a `DomainException` if `status !== InvoiceStatus::DRAFT`.
   - Eloquent `updating` hook prevents modifications to client, place of supply, dates, or financial totals once the invoice status is no longer draft.

## Verification & Outcomes
- 33 additional tests created (totaling 66 passing Pest tests).
- Verified concurrent Send requests against MySQL container to prove zero race-condition collisions.
