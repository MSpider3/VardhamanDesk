<?php

namespace App\Models;

use App\Models\Scopes\LeadNoteOwnershipScope;
use Database\Factories\LeadNoteFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'lead_id',
    'created_by',
    'note',
    'follow_up_date',
])]
class LeadNote extends Model
{
    /** @use HasFactory<LeadNoteFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'follow_up_date' => 'date',
        ];
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new LeadNoteOwnershipScope);

        static::creating(function (LeadNote $note) {
            if (Auth::check() && ! $note->created_by) {
                $note->created_by = Auth::id();
            }
        });

        static::created(function (LeadNote $note) {
            if ($note->follow_up_date !== null) {
                DB::transaction(function () use ($note) {
                    $note->lead()->update([
                        'next_follow_up_date' => $note->follow_up_date,
                    ]);
                });
            }
        });

        static::updating(function () {
            throw new DomainException('Lead notes are immutable and cannot be modified.');
        });

        static::deleting(function () {
            throw new DomainException('Lead notes are immutable and cannot be deleted.');
        });
    }

    /**
     * Lead that owns this note.
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    /**
     * User who authored this note.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
