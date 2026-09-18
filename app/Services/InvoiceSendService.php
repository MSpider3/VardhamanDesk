<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\FinancialYearCounter;
use App\Models\Invoice;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InvoiceSendService
{
    public function __construct(
        protected InvoiceCalculationService $calculationService
    ) {}

    /**
     * Derive Indian Financial Year (e.g. 2026-03-15 -> 2025-26, 2026-04-15 -> 2026-27).
     */
    public static function deriveFinancialYear(CarbonInterface|string|null $date = null): string
    {
        $carbon = $date ? Carbon::parse($date) : Carbon::now();
        $year = $carbon->year;
        $month = $carbon->month;

        if ($month >= 4) {
            $startYear = $year;
            $endYear = ($year + 1) % 100;
        } else {
            $startYear = $year - 1;
            $endYear = $year % 100;
        }

        return sprintf('%04d-%02d', $startYear, $endYear);
    }

    /**
     * Finalize and send a Draft invoice with transactional sequence allocation.
     */
    public function send(Invoice|int $invoice, ?string $sendDate = null): Invoice
    {
        $invoiceId = $invoice instanceof Invoice ? $invoice->id : $invoice;

        return DB::transaction(function () use ($invoiceId, $sendDate) {
            /** @var Invoice $lockedInvoice */
            $lockedInvoice = Invoice::withoutGlobalScopes()
                ->where('id', $invoiceId)
                ->lockForUpdate()
                ->firstOrFail();

            $isDraft = $lockedInvoice->status instanceof InvoiceStatus
                ? $lockedInvoice->status === InvoiceStatus::DRAFT
                : $lockedInvoice->status === InvoiceStatus::DRAFT->value;

            // Idempotency: If already sent, do not re-allocate
            if (! $isDraft) {
                return $lockedInvoice;
            }

            // Validate invoice readiness
            if (! $lockedInvoice->client_id) {
                throw new DomainException('Cannot send invoice without an assigned client.');
            }

            if (empty($lockedInvoice->place_of_supply)) {
                throw new DomainException('Cannot send invoice without a valid place of supply.');
            }

            if (! $lockedInvoice->invoice_date || ! $lockedInvoice->due_date) {
                throw new DomainException('Cannot send invoice without invoice date and due date.');
            }

            $items = $lockedInvoice->items()->get();
            if ($items->isEmpty()) {
                throw new DomainException('Cannot send an invoice with no line items.');
            }

            // Recalculate totals to guarantee internal consistency
            $itemsData = $items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'quantity' => $item->quantity,
                    'rate' => $item->rate,
                    'gst_rate_id' => $item->gst_rate_id,
                ];
            })->toArray();

            $calc = $this->calculationService->calculate($lockedInvoice->place_of_supply, $itemsData);

            // Update line items tax breakdown
            foreach ($calc['items'] as $index => $calcItem) {
                $itemModel = $items[$index];
                $itemModel->amount = $calcItem['amount'];
                $itemModel->cgst_amount = $calcItem['cgst_amount'];
                $itemModel->sgst_amount = $calcItem['sgst_amount'];
                $itemModel->igst_amount = $calcItem['igst_amount'];
                $itemModel->save();
            }

            // Financial Year derivation from send date
            $effectiveDate = $sendDate ? Carbon::parse($sendDate) : ($lockedInvoice->invoice_date ?? Carbon::now());
            $fy = self::deriveFinancialYear($effectiveDate);

            // Ensure FY counter exists and lock it
            FinancialYearCounter::firstOrCreate(
                ['financial_year' => $fy],
                ['last_sequence' => 0]
            );

            /** @var FinancialYearCounter $counter */
            $counter = FinancialYearCounter::where('financial_year', $fy)
                ->lockForUpdate()
                ->firstOrFail();

            $newSequence = $counter->last_sequence + 1;
            $counter->last_sequence = $newSequence;
            $counter->save();

            $formattedInvoiceNumber = sprintf('VI/%s/%04d', $fy, $newSequence);

            $lockedInvoice->invoice_number = $formattedInvoiceNumber;
            $lockedInvoice->financial_year = $fy;
            $lockedInvoice->sequence_number = $newSequence;
            $lockedInvoice->subtotal = $calc['subtotal'];
            $lockedInvoice->cgst_amount = $calc['cgst_amount'];
            $lockedInvoice->sgst_amount = $calc['sgst_amount'];
            $lockedInvoice->igst_amount = $calc['igst_amount'];
            $lockedInvoice->total = $calc['total'];
            $lockedInvoice->status = InvoiceStatus::SENT;
            $lockedInvoice->save();

            return $lockedInvoice;
        });
    }
}
