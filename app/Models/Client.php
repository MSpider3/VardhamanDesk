<?php

namespace App\Models;

use App\Enums\IndianState;
use App\Enums\InvoiceStatus;
use App\Models\Scopes\ClientOwnershipScope;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class Client extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'lead_id',
        'assigned_to',
        'created_by',
        'name',
        'company',
        'phone',
        'email',
        'billing_address',
        'state',
        'gstin',
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new ClientOwnershipScope);

        static::deleting(function (Client $client) {
            $hasLockedInvoices = $client->invoices()
                ->where('status', '!=', InvoiceStatus::DRAFT)
                ->where('status', '!=', InvoiceStatus::DRAFT->value)
                ->exists();

            if ($hasLockedInvoices) {
                throw new DomainException('Cannot delete client with active, sent, or paid invoices.');
            }
        });

        static::updating(function (Client $client) {
            if ($client->isDirty('assigned_to') && Auth::check()) {
                $user = Auth::user();
                if ($user->isSales() && $client->assigned_to !== $client->getOriginal('assigned_to')) {
                    throw new AuthorizationException('Sales representatives cannot reassign clients.');
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'state' => IndianState::class,
        ];
    }

    /**
     * Normalize state attribute on set.
     */
    public function setStateAttribute($value): void
    {
        $resolved = IndianState::fromCodeOrName($value);
        $this->attributes['state'] = $resolved ? $resolved->value : $value;
    }

    /**
     * Normalize GSTIN attribute on set (uppercase, trimmed, nullable).
     */
    public function setGstinAttribute($value): void
    {
        $this->attributes['gstin'] = blank($value) ? null : strtoupper(trim((string) $value));
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function leadNotes(): HasMany
    {
        return $this->hasMany(LeadNote::class, 'lead_id', 'lead_id');
    }
}
