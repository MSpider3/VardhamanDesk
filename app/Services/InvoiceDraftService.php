<?php

namespace App\Services;

use App\Enums\IndianState;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

class InvoiceDraftService
{
    public function __construct(
        protected InvoiceCalculationService $calculationService
    ) {}

    /**
     * Create a new draft invoice with line items.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $itemsData
     */
    public function createDraft(array $data, array $itemsData, User $creator): Invoice
    {
        $clientId = $data['client_id'];
        $client = Client::withoutGlobalScopes()->findOrFail($clientId);

        $pos = $data['place_of_supply'] ?? $client->state;
        $posState = IndianState::fromCodeOrName($pos);
        $posCode = $posState?->value ?? (string) $pos;

        $invoiceDate = ! empty($data['invoice_date'])
            ? Carbon::parse($data['invoice_date'])
            : Carbon::today();

        $dueDate = ! empty($data['due_date'])
            ? Carbon::parse($data['due_date'])
            : $invoiceDate->copy()->addDays(30);

        $calc = $this->calculationService->calculate($posCode, $itemsData);

        return DB::transaction(function () use ($clientId, $posCode, $invoiceDate, $dueDate, $creator, $calc) {
            $invoice = Invoice::create([
                'client_id' => $clientId,
                'invoice_number' => null,
                'financial_year' => null,
                'sequence_number' => null,
                'place_of_supply' => $posCode,
                'status' => InvoiceStatus::DRAFT,
                'invoice_date' => $invoiceDate->format('Y-m-d'),
                'due_date' => $dueDate->format('Y-m-d'),
                'subtotal' => $calc['subtotal'],
                'cgst_amount' => $calc['cgst_amount'],
                'sgst_amount' => $calc['sgst_amount'],
                'igst_amount' => $calc['igst_amount'],
                'total' => $calc['total'],
                'created_by' => $creator->id,
            ]);

            foreach ($calc['items'] as $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $item['description'] ?? 'Services',
                    'sac_code' => $item['sac_code'] ?? '998313',
                    'quantity' => $item['quantity'],
                    'rate' => $item['rate'],
                    'amount' => $item['amount'],
                    'gst_rate_id' => $item['gst_rate_id'],
                    'cgst_amount' => $item['cgst_amount'],
                    'sgst_amount' => $item['sgst_amount'],
                    'igst_amount' => $item['igst_amount'],
                ]);
            }

            return $invoice;
        });
    }

    /**
     * Update an existing draft invoice and replace its line items.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $itemsData
     */
    public function updateDraft(Invoice $invoice, array $data, array $itemsData): Invoice
    {
        $isDraft = $invoice->status instanceof InvoiceStatus
            ? $invoice->status === InvoiceStatus::DRAFT
            : $invoice->status === InvoiceStatus::DRAFT->value;

        if (! $isDraft) {
            throw new DomainException('Cannot edit an invoice that is not in draft status.');
        }

        $pos = $data['place_of_supply'] ?? $invoice->place_of_supply;
        $posState = IndianState::fromCodeOrName($pos);
        $posCode = $posState?->value ?? (string) $pos;

        $invoiceDate = ! empty($data['invoice_date'])
            ? Carbon::parse($data['invoice_date'])
            : $invoice->invoice_date;

        $dueDate = ! empty($data['due_date'])
            ? Carbon::parse($data['due_date'])
            : (! empty($data['invoice_date']) ? Carbon::parse($data['invoice_date'])->addDays(30) : $invoice->due_date);

        $calc = $this->calculationService->calculate($posCode, $itemsData);

        return DB::transaction(function () use ($invoice, $data, $posCode, $invoiceDate, $dueDate, $calc) {
            $invoice->client_id = $data['client_id'] ?? $invoice->client_id;
            $invoice->place_of_supply = $posCode;
            $invoice->invoice_date = $invoiceDate->format('Y-m-d');
            $invoice->due_date = $dueDate->format('Y-m-d');
            $invoice->subtotal = $calc['subtotal'];
            $invoice->cgst_amount = $calc['cgst_amount'];
            $invoice->sgst_amount = $calc['sgst_amount'];
            $invoice->igst_amount = $calc['igst_amount'];
            $invoice->total = $calc['total'];
            $invoice->save();

            // Replace line items
            $invoice->items()->delete();

            foreach ($calc['items'] as $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $item['description'] ?? 'Services',
                    'sac_code' => $item['sac_code'] ?? '998313',
                    'quantity' => $item['quantity'],
                    'rate' => $item['rate'],
                    'amount' => $item['amount'],
                    'gst_rate_id' => $item['gst_rate_id'],
                    'cgst_amount' => $item['cgst_amount'],
                    'sgst_amount' => $item['sgst_amount'],
                    'igst_amount' => $item['igst_amount'],
                ]);
            }

            return $invoice;
        });
    }
}
