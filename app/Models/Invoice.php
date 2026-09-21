<?php

namespace App\Models;

use App\Enums\IndianState;
use App\Enums\InvoiceStatus;
use App\Models\Scopes\InvoiceOwnershipScope;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'invoice_number',
        'financial_year',
        'sequence_number',
        'place_of_supply',
        'status',
        'invoice_date',
        'due_date',
        'subtotal',
        'cgst_amount',
        'sgst_amount',
        'igst_amount',
        'total',
        'created_by',
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new InvoiceOwnershipScope);

        static::deleting(function (Invoice $invoice) {
            $isDraft = $invoice->status instanceof InvoiceStatus
                ? $invoice->status === InvoiceStatus::DRAFT
                : $invoice->status === InvoiceStatus::DRAFT->value;

            if (! $isDraft) {
                throw new DomainException('Only draft invoices can be deleted. Sent or paid invoices cannot be deleted by anyone.');
            }
        });

        static::updating(function (Invoice $invoice) {
            $origStatus = $invoice->getOriginal('status');
            $wasDraft = $origStatus instanceof InvoiceStatus
                ? $origStatus === InvoiceStatus::DRAFT
                : $origStatus === InvoiceStatus::DRAFT->value;

            if (! $wasDraft) {
                $currentStatus = $invoice->status instanceof InvoiceStatus
                    ? $invoice->status
                    : InvoiceStatus::tryFrom((string) $invoice->status);

                if ($currentStatus === InvoiceStatus::DRAFT) {
                    throw new DomainException('Cannot transition a locked (sent/paid) invoice back to draft status.');
                }

                $wasPaid = $origStatus instanceof InvoiceStatus
                    ? $origStatus === InvoiceStatus::PAID
                    : $origStatus === InvoiceStatus::PAID->value;

                if ($wasPaid && $currentStatus !== InvoiceStatus::PAID) {
                    throw new DomainException('A fully paid invoice cannot transition to another status.');
                }

                $financialFields = [
                    'client_id',
                    'invoice_number',
                    'financial_year',
                    'sequence_number',
                    'place_of_supply',
                    'invoice_date',
                    'subtotal',
                    'cgst_amount',
                    'sgst_amount',
                    'igst_amount',
                    'total',
                ];

                if ($invoice->isDirty($financialFields)) {
                    throw new DomainException('Cannot modify financial details or line items of a locked (sent/paid) invoice.');
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'place_of_supply' => IndianState::class,
            'invoice_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'sequence_number' => 'integer',
        ];
    }

    /**
     * Normalize place_of_supply attribute on set.
     */
    public function setPlaceOfSupplyAttribute($value): void
    {
        $resolved = IndianState::fromCodeOrName($value);
        $this->attributes['place_of_supply'] = $resolved ? $resolved->value : $value;
    }

    /**
     * Display label for invoice number (DRAFT if draft).
     */
    public function getDisplayNumberAttribute(): string
    {
        $isDraft = $this->status instanceof InvoiceStatus
            ? $this->status === InvoiceStatus::DRAFT
            : $this->status === InvoiceStatus::DRAFT->value;

        if ($isDraft || blank($this->invoice_number)) {
            return 'DRAFT';
        }

        return $this->invoice_number;
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Total paid amount derived from the payments ledger.
     */
    public function getPaidAmountAttribute(): string
    {
        if (array_key_exists('paid_amount', $this->attributes)) {
            $amount = (float) ($this->attributes['paid_amount'] ?? 0.0);

            return number_format($amount, 2, '.', '');
        }

        $sum = $this->payments()->sum('amount');

        return number_format((float) $sum, 2, '.', '');
    }

    /**
     * Remaining balance to be paid.
     */
    public function getOutstandingAmountAttribute(): string
    {
        if (array_key_exists('outstanding_amount', $this->attributes)) {
            $remaining = max(0.0, (float) ($this->attributes['outstanding_amount'] ?? 0.0));

            return number_format($remaining, 2, '.', '');
        }

        $paid = (float) $this->paid_amount;
        $total = (float) $this->total;
        $remaining = max(0.0, $total - $paid);

        return number_format($remaining, 2, '.', '');
    }

    /**
     * Recalculate and update status based on payment ledger.
     */
    public function recalculateStatus(): InvoiceStatus
    {
        $totalPaid = (float) $this->payments()->sum('amount');
        $invoiceTotal = (float) $this->total;

        if ($totalPaid >= $invoiceTotal) {
            $newStatus = InvoiceStatus::PAID;
        } elseif ($totalPaid > 0) {
            $newStatus = InvoiceStatus::PARTIALLY_PAID;
        } else {
            $newStatus = InvoiceStatus::SENT;
        }

        if ($this->status !== $newStatus) {
            $this->status = $newStatus;
            $this->save();
        }

        return $newStatus;
    }
}
