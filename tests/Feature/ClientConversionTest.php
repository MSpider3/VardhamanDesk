<?php

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\User;
use App\Services\ClientService;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['email' => 'admin_conv@vardhaman.local']);
    $this->sales1 = User::factory()->sales()->create(['email' => 'sales1_conv@vardhaman.local']);
    $this->sales2 = User::factory()->sales()->create(['email' => 'sales2_conv@vardhaman.local']);

    $this->service = new ClientService;
});

test('qualified lead converts transactionally to client with lead ownership defaulted', function () {
    $lead = Lead::factory()->create([
        'name' => 'Qualified Person',
        'company' => 'Target Corp',
        'phone' => '+91 99999 88888',
        'email' => 'person@target.local',
        'source' => LeadSource::REFERRAL,
        'assigned_to' => $this->sales1->id,
        'status' => LeadStatus::QUALIFIED,
    ]);

    LeadNote::create([
        'lead_id' => $lead->id,
        'created_by' => $this->sales1->id,
        'note' => 'Qualified note before conversion',
    ]);

    $clientData = [
        'billing_address' => '123 Business Way, Jaipur, Rajasthan',
        'state' => '08',
        'gstin' => '08ABCDE1234F1Z5',
    ];

    $client = $this->service->convertLeadToClient($lead, $clientData, $this->sales1);

    expect($client->lead_id)->toBe($lead->id)
        ->and($client->assigned_to)->toBe($this->sales1->id)
        ->and($client->created_by)->toBe($this->sales1->id)
        ->and($client->name)->toBe('Qualified Person')
        ->and($client->company)->toBe('Target Corp')
        ->and($client->billing_address)->toBe('123 Business Way, Jaipur, Rajasthan')
        ->and($client->state->value)->toBe('08')
        ->and($client->gstin)->toBe('08ABCDE1234F1Z5');

    // Lead status must now be CONVERTED (terminal)
    expect($lead->fresh()->status)->toBe(LeadStatus::CONVERTED);

    // Lead notes are surfaced on client without duplication
    expect($client->leadNotes)->toHaveCount(1)
        ->and($client->leadNotes->first()->note)->toBe('Qualified note before conversion');
});

test('non-qualified lead cannot be converted', function () {
    $lead = Lead::factory()->create([
        'assigned_to' => $this->sales1->id,
        'status' => LeadStatus::CONTACTED,
    ]);

    expect(fn () => $this->service->convertLeadToClient($lead, [
        'billing_address' => 'Some address',
        'state' => '08',
    ], $this->sales1))->toThrow(DomainException::class, 'Only qualified leads can be converted');
});

test('already converted lead cannot be converted again', function () {
    $lead = Lead::factory()->create([
        'assigned_to' => $this->sales1->id,
        'status' => LeadStatus::QUALIFIED,
    ]);

    $this->service->convertLeadToClient($lead, [
        'billing_address' => 'Some address',
        'state' => '08',
    ], $this->sales1);

    // Second attempt must fail
    expect(fn () => $this->service->convertLeadToClient($lead->fresh(), [
        'billing_address' => 'Another address',
        'state' => '08',
    ], $this->sales1))->toThrow(DomainException::class);
});

test('conversion with invalid gstin rolls back transaction completely', function () {
    $lead = Lead::factory()->create([
        'assigned_to' => $this->sales1->id,
        'status' => LeadStatus::QUALIFIED,
    ]);

    expect(fn () => $this->service->convertLeadToClient($lead, [
        'billing_address' => 'Address',
        'state' => '08',
        'gstin' => 'INVALID_GSTIN',
    ], $this->sales1))->toThrow(ValidationException::class);

    // Verify lead status remained QUALIFIED and no Client was created
    expect($lead->fresh()->status)->toBe(LeadStatus::QUALIFIED)
        ->and(Client::withoutGlobalScopes()->where('lead_id', $lead->id)->count())->toBe(0);
});

test('direct client creation is allowed with null lead_id', function () {
    $client = $this->service->createDirectClient([
        'name' => 'Direct Client Ltd',
        'company' => 'Direct Enterprises',
        'billing_address' => '456 Tech Park, Mumbai',
        'state' => '27',
        'gstin' => '27ABCDE1234F1Z5',
    ], $this->sales1);

    expect($client->lead_id)->toBeNull()
        ->and($client->assigned_to)->toBe($this->sales1->id)
        ->and($client->created_by)->toBe($this->sales1->id)
        ->and($client->state->value)->toBe('27');
});

test('admin can create direct client assigned to another sales user', function () {
    $client = $this->service->createDirectClient([
        'name' => 'Assigned Client Ltd',
        'assigned_to' => $this->sales2->id,
        'billing_address' => 'Jaipur, Rajasthan',
        'state' => '08',
    ], $this->admin);

    expect($client->lead_id)->toBeNull()
        ->and($client->assigned_to)->toBe($this->sales2->id)
        ->and($client->created_by)->toBe($this->admin->id);
});

test('admin can independently reassign a client to another sales user', function () {
    $client = Client::factory()->create([
        'assigned_to' => $this->sales1->id,
    ]);

    expect($client->assigned_to)->toBe($this->sales1->id);

    $client->assigned_to = $this->sales2->id;
    $client->save();

    expect($client->fresh()->assigned_to)->toBe($this->sales2->id);
});
