<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordPayment
{
    /**
     * Record a payment atomically against an invoice with row-level locking and status recalculation.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(Invoice|int $invoice, array $data, User $actingUser): Payment
    {
        $invoiceId = $invoice instanceof Invoice ? $invoice->id : $invoice;

        return DB::transaction(function () use ($invoiceId, $data, $actingUser) {
            /** @var Invoice $lockedInvoice */
            $lockedInvoice = Invoice::withoutGlobalScopes()
                ->with(['client'])
                ->where('id', $invoiceId)
                ->lockForUpdate()
                ->firstOrFail();

            // Authorization: Admin or owner of the client
            if (! $actingUser->isAdmin()) {
                $client = $lockedInvoice->client;
                if (! $client || $client->assigned_to !== $actingUser->id) {
                    throw new AuthorizationException('You are not authorized to record payments for this invoice.');
                }
            }

            // Verify status eligibility (only sent or partially_paid)
            $isSent = $lockedInvoice->status === InvoiceStatus::SENT || $lockedInvoice->status === InvoiceStatus::SENT->value;
            $isPartiallyPaid = $lockedInvoice->status === InvoiceStatus::PARTIALLY_PAID || $lockedInvoice->status === InvoiceStatus::PARTIALLY_PAID->value;

            if ($lockedInvoice->status === InvoiceStatus::DRAFT || $lockedInvoice->status === InvoiceStatus::DRAFT->value) {
                throw new DomainException('Cannot record payments against a draft invoice.');
            }

            if ($lockedInvoice->status === InvoiceStatus::PAID || $lockedInvoice->status === InvoiceStatus::PAID->value) {
                throw new DomainException('This invoice is already fully paid.');
            }

            if (! $isSent && ! $isPartiallyPaid) {
                throw new DomainException("Cannot record payment against invoice with status '{$lockedInvoice->status->value}'.");
            }

            // Fresh sum of existing payments from locked DB state
            $existingPaidSum = (float) Payment::withoutGlobalScopes()
                ->where('invoice_id', $lockedInvoice->id)
                ->sum('amount');

            $invoiceTotal = (float) $lockedInvoice->total;
            $remainingBalance = max(0.0, round($invoiceTotal - $existingPaidSum, 2));

            $amount = (float) ($data['amount'] ?? 0);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment amount must be greater than zero.',
                ]);
            }

            // Reject overpayments outright
            if (round($amount, 2) > $remainingBalance) {
                throw ValidationException::withMessages([
                    'amount' => sprintf(
                        'Payment amount (₹%s) exceeds the remaining invoice balance of ₹%s.',
                        number_format($amount, 2),
                        number_format($remainingBalance, 2)
                    ),
                ]);
            }

            $rawMethod = $data['method'] ?? null;
            $method = $rawMethod instanceof PaymentMethod
                ? $rawMethod
                : (PaymentMethod::tryFrom((string) $rawMethod) ?? PaymentMethod::BANK_TRANSFER);

            $paymentDate = ! empty($data['payment_date'])
                ? Carbon::parse($data['payment_date'])->toDateString()
                : Carbon::today()->toDateString();

            $payment = Payment::create([
                'invoice_id' => $lockedInvoice->id,
                'amount' => number_format($amount, 2, '.', ''),
                'payment_date' => $paymentDate,
                'method' => $method,
                'reference_note' => $data['reference_note'] ?? null,
                'recorded_by' => $actingUser->id,
            ]);

            // Recompute status after payment write
            $newPaidTotal = round($existingPaidSum + $amount, 2);

            if ($newPaidTotal >= $invoiceTotal) {
                $lockedInvoice->status = InvoiceStatus::PAID;
            } elseif ($newPaidTotal > 0) {
                $lockedInvoice->status = InvoiceStatus::PARTIALLY_PAID;
            } else {
                $lockedInvoice->status = InvoiceStatus::SENT;
            }

            $lockedInvoice->save();

            return $payment;
        });
    }
}
