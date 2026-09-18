<?php

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadService;

beforeEach(function () {
    $this->sales = User::factory()->sales()->create();
    $this->service = new LeadService;
});

test('lead supports all canonical sources', function () {
    $sources = [
        LeadSource::REFERRAL,
        LeadSource::BNI,
        LeadSource::WEBSITE,
        LeadSource::COLD_CALL,
        LeadSource::EVENT,
        LeadSource::OTHER,
    ];

    foreach ($sources as $source) {
        $lead = Lead::factory()->create([
            'assigned_to' => $this->sales->id,
            'source' => $source,
        ]);

        expect($lead->source)->toBe($source);
    }
});

test('lead supports moving between non-terminal statuses', function () {
    $lead = Lead::factory()->create([
        'assigned_to' => $this->sales->id,
        'status' => LeadStatus::NEW,
    ]);

    // Move to contacted
    $this->service->updateStatus($lead, LeadStatus::CONTACTED);
    expect($lead->fresh()->status)->toBe(LeadStatus::CONTACTED);

    // Move to qualified
    $this->service->updateStatus($lead, LeadStatus::QUALIFIED);
    expect($lead->fresh()->status)->toBe(LeadStatus::QUALIFIED);

    // Backward transition: Qualified -> Contacted is allowed
    $this->service->updateStatus($lead, LeadStatus::CONTACTED);
    expect($lead->fresh()->status)->toBe(LeadStatus::CONTACTED);

    // Move to lost
    $this->service->updateStatus($lead, LeadStatus::LOST);
    expect($lead->fresh()->status)->toBe(LeadStatus::LOST);

    // Reopen lost: Lost -> Qualified is allowed
    $this->service->updateStatus($lead, LeadStatus::QUALIFIED);
    expect($lead->fresh()->status)->toBe(LeadStatus::QUALIFIED);
});

test('converted status is terminal and blocks all transitions out of it', function () {
    $lead = Lead::factory()->create([
        'assigned_to' => $this->sales->id,
        'status' => LeadStatus::QUALIFIED,
    ]);

    // Transition to converted
    $this->service->updateStatus($lead, LeadStatus::CONVERTED);
    expect($lead->fresh()->status)->toBe(LeadStatus::CONVERTED);

    // Attempt to transition to qualified
    expect(fn () => $this->service->updateStatus($lead, LeadStatus::QUALIFIED))
        ->toThrow(DomainException::class);

    // Attempt to transition to new
    expect(fn () => $this->service->updateStatus($lead, LeadStatus::NEW))
        ->toThrow(DomainException::class);

    // Attempt to transition to lost
    expect(fn () => $this->service->updateStatus($lead, LeadStatus::LOST))
        ->toThrow(DomainException::class);

    // Direct model update attempt also throws DomainException via model boot
    expect(function () use ($lead) {
        $lead->status = LeadStatus::QUALIFIED;
        $lead->save();
    })->toThrow(DomainException::class);
});
