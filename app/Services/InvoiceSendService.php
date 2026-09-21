<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\FinancialYearCounter;
use App\Models\Invoice;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\QueryException;
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

            // Update line items tax breakdown and freeze GST rate percentage
            foreach ($calc['items'] as $index => $calcItem) {
                $itemModel = $items[$index];
                $itemModel->amount = $calcItem['amount'];
                $itemModel->gst_rate_percent = $calcItem['gst_rate_percent'];
                $itemModel->cgst_amount = $calcItem['cgst_amount'];
                $itemModel->sgst_amount = $calcItem['sgst_amount'];
                $itemModel->igst_amount = $calcItem['igst_amount'];
                $itemModel->save();
            }

            // Financial Year derivation from send date
            $effectiveDate = $sendDate ? Carbon::parse($sendDate) : ($lockedInvoice->invoice_date ?? Carbon::now());
            $fy = self::deriveFinancialYear($effectiveDate);

            // Restrict backdating into an already-closed financial year (earlier than current real FY)
            $currentFy = self::deriveFinancialYear(Carbon::now());
            if (strcmp($fy, $currentFy) < 0) {
                throw new DomainException("This invoice's date falls in an already-closed financial year ({$fy}); update the invoice date to fall within {$currentFy}, or contact an admin.");
            }

            // Ensure FY counter exists atomically and lock it
            if (! FinancialYearCounter::where('financial_year', $fy)->exists()) {
                try {
                    FinancialYearCounter::create([
                        'financial_year' => $fy,
                        'last_sequence' => 0,
                    ]);
                } catch (QueryException $e) {
                    // Row already exists or was concurrently created
                    if (! str_contains($e->getMessage(), 'Duplicate entry') &&
                        ! str_contains($e->getMessage(), 'UNIQUE constraint failed') &&
                        $e->getCode() !== '23000') {
                        throw $e;
                    }
                }
            }

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
