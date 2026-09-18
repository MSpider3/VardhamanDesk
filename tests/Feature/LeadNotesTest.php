<?php

use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\User;
use App\Services\LeadService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->sales1 = User::factory()->sales()->create();
    $this->sales2 = User::factory()->sales()->create();

    $this->lead = Lead::factory()->create([
        'assigned_to' => $this->sales1->id,
        'next_follow_up_date' => null,
    ]);

    $this->service = new LeadService;
});

test('adding a note with follow up date synchronizes leads next_follow_up_date', function () {
    $followUpDate = Carbon::tomorrow()->format('Y-m-d');

    $note = $this->service->addNote(
        $this->lead,
        $this->sales1,
        'Called client, agreed to follow up tomorrow.',
        $followUpDate
    );

    expect($note->follow_up_date->format('Y-m-d'))->toBe($followUpDate)
        ->and($this->lead->fresh()->next_follow_up_date->format('Y-m-d'))->toBe($followUpDate);
});

test('adding a note without follow up date leaves next_follow_up_date unchanged', function () {
    $initialDate = Carbon::today()->addDays(5)->format('Y-m-d');
    $this->lead->update(['next_follow_up_date' => $initialDate]);

    $note = $this->service->addNote(
        $this->lead,
        $this->sales1,
        'Spoke with assistant, no new date set.',
        null
    );

    expect($note->follow_up_date)->toBeNull()
        ->and($this->lead->fresh()->next_follow_up_date->format('Y-m-d'))->toBe($initialDate);
});

test('admin can add note to any lead', function () {
    $followUpDate = Carbon::today()->addDays(2)->format('Y-m-d');

    $note = $this->service->addNote(
        $this->lead,
        $this->admin,
        'Admin check-in note.',
        $followUpDate
    );

    expect($note->created_by)->toBe($this->admin->id)
        ->and($this->lead->fresh()->next_follow_up_date->format('Y-m-d'))->toBe($followUpDate);
});

test('unauthorized sales user cannot add note to another sales user lead', function () {
    expect(fn () => $this->service->addNote(
        $this->lead,
        $this->sales2,
        'Unauthorized note attempt',
        Carbon::tomorrow()->format('Y-m-d')
    ))->toThrow(AuthorizationException::class);
});

test('lead notes are immutable and cannot be updated or deleted', function () {
    $note = LeadNote::withoutGlobalScopes()->create([
        'lead_id' => $this->lead->id,
        'created_by' => $this->sales1->id,
        'note' => 'Original note text',
    ]);

    expect(Gate::forUser($this->sales1)->allows('update', $note))->toBeFalse()
        ->and(Gate::forUser($this->sales1)->allows('delete', $note))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('update', $note))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('delete', $note))->toBeFalse();

    expect(function () use ($note) {
        $note->note = 'Modified note text';
        $note->save();
    })->toThrow(DomainException::class);

    expect(function () use ($note) {
        $note->delete();
    })->toThrow(DomainException::class);
});
