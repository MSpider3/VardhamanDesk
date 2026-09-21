<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'description',
        'sac_code',
        'quantity',
        'rate',
        'amount',
        'gst_rate_id',
        'gst_rate_percent',
        'cgst_amount',
        'sgst_amount',
        'igst_amount',
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::saving(function (InvoiceItem $item) {
            $invoice = $item->invoice ?? Invoice::withoutGlobalScopes()->find($item->invoice_id);
            if ($invoice) {
                $isDraft = $invoice->status instanceof InvoiceStatus
                    ? $invoice->status === InvoiceStatus::DRAFT
                    : $invoice->status === InvoiceStatus::DRAFT->value;

                if (! $isDraft) {
                    throw new DomainException('Cannot modify or add line items to a locked (sent/paid) invoice.');
                }
            }
        });

        static::deleting(function (InvoiceItem $item) {
            $invoice = $item->invoice ?? Invoice::withoutGlobalScopes()->find($item->invoice_id);
            if ($invoice) {
                $isDraft = $invoice->status instanceof InvoiceStatus
                    ? $invoice->status === InvoiceStatus::DRAFT
                    : $invoice->status === InvoiceStatus::DRAFT->value;

                if (! $isDraft) {
                    throw new DomainException('Cannot delete line items from a locked (sent/paid) invoice.');
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'rate' => 'decimal:2',
            'amount' => 'decimal:2',
            'gst_rate_percent' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function gstRate(): BelongsTo
    {
        return $this->belongsTo(GstRate::class, 'gst_rate_id');
    }
}
