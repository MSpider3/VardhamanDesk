<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GstRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'rate',
        'label',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (GstRate $rate): void {
            if ($rate->isDirty('rate') && $rate->invoiceItems()->exists()) {
                throw new \DomainException('Cannot modify rate percentage for a GST rate that is referenced by existing invoices. Create a new GST rate instead and deactivate this one.');
            }
        });

        static::deleting(function (GstRate $rate): void {
            if ($rate->invoiceItems()->exists()) {
                throw new \DomainException('Cannot delete a GST rate that is referenced by existing invoices. Deactivate it instead.');
            }
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }
}
