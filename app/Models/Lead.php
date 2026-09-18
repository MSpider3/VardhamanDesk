<?php

namespace App\Models;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Scopes\LeadOwnershipScope;
use Database\Factories\LeadFactory;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

#[Fillable([
    'name',
    'company',
    'phone',
    'email',
    'source',
    'assigned_to',
    'status',
    'next_follow_up_date',
])]
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => LeadSource::class,
            'status' => LeadStatus::class,
            'next_follow_up_date' => 'date',
        ];
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new LeadOwnershipScope);

        static::creating(function (Lead $lead) {
            if (Auth::check()) {
                $user = Auth::user();
                if ($user->isSales() || ! $lead->assigned_to) {
                    $lead->assigned_to = $user->id;
                }
            }
        });

        static::updating(function (Lead $lead) {
            if ($lead->isDirty('status')) {
                $originalStatus = $lead->getOriginal('status');
                $isOriginalConverted = $originalStatus instanceof LeadStatus
                    ? $originalStatus === LeadStatus::CONVERTED
                    : $originalStatus === LeadStatus::CONVERTED->value;

                if ($isOriginalConverted && $lead->status !== LeadStatus::CONVERTED) {
                    throw new DomainException('Converted leads are terminal and cannot transition to any other status.');
                }
            }

            if ($lead->isDirty('assigned_to') && Auth::check()) {
                $user = Auth::user();
                if ($user->isSales() && $lead->assigned_to !== $lead->getOriginal('assigned_to')) {
                    throw new AuthorizationException('Sales representatives cannot reassign leads.');
                }
            }
        });
    }

    /**
     * User assigned to this lead.
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Notes associated with this lead.
     */
    public function notes(): HasMany
    {
        return $this->hasMany(LeadNote::class, 'lead_id')->latest();
    }
}
