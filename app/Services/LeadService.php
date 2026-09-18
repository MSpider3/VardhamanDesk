<?php

namespace App\Services;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LeadService
{
    /**
     * Transition lead status, enforcing terminal state rules.
     */
    public function updateStatus(Lead $lead, LeadStatus|string $newStatus): Lead
    {
        $targetStatus = $newStatus instanceof LeadStatus ? $newStatus : LeadStatus::from($newStatus);
        $currentStatus = $lead->status instanceof LeadStatus ? $lead->status : LeadStatus::from($lead->status);

        if ($currentStatus->isTerminal() && $targetStatus !== $currentStatus) {
            throw new DomainException('Converted leads are terminal and cannot transition to any other status.');
        }

        $lead->status = $targetStatus;
        $lead->save();

        return $lead;
    }

    /**
     * Assign or reassign a lead to another user.
     */
    public function assign(Lead $lead, User $assignee, User $actor): Lead
    {
        if (! $actor->isAdmin()) {
            throw new AuthorizationException('Only administrators can reassign leads.');
        }

        $lead->assigned_to = $assignee->id;
        $lead->save();

        return $lead;
    }

    /**
     * Add a note to a lead and transactionally update next_follow_up_date if provided.
     */
    public function addNote(Lead $lead, User $author, string $noteContent, ?string $followUpDate = null): LeadNote
    {
        if (! $author->isAdmin() && $lead->assigned_to !== $author->id) {
            throw new AuthorizationException('You are not authorized to add notes to this lead.');
        }

        return DB::transaction(function () use ($lead, $author, $noteContent, $followUpDate) {
            $note = LeadNote::create([
                'lead_id' => $lead->id,
                'created_by' => $author->id,
                'note' => $noteContent,
                'follow_up_date' => $followUpDate,
            ]);

            if ($followUpDate !== null) {
                $lead->next_follow_up_date = $followUpDate;
                $lead->save();
            }

            return $note;
        });
    }

    /**
     * Check if a lead has an overdue follow-up.
     */
    public function isOverdue(Lead $lead): bool
    {
        if (! $lead->next_follow_up_date) {
            return false;
        }

        $status = $lead->status instanceof LeadStatus ? $lead->status : LeadStatus::from($lead->status);
        if ($status->isTerminal()) {
            return false;
        }

        return Carbon::parse($lead->next_follow_up_date)->isBefore(Carbon::today());
    }

    /**
     * Check if a lead has a follow-up scheduled for today.
     */
    public function isToday(Lead $lead): bool
    {
        if (! $lead->next_follow_up_date) {
            return false;
        }

        $status = $lead->status instanceof LeadStatus ? $lead->status : LeadStatus::from($lead->status);
        if ($status->isTerminal()) {
            return false;
        }

        return Carbon::parse($lead->next_follow_up_date)->isSameDay(Carbon::today());
    }
}
