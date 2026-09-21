<?php

use App\Enums\LeadStatus;
use App\Models\Client;
use App\Models\CompanySetting;
use App\Models\Lead;
use App\Models\User;
use App\Services\ClientService;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->sales = User::factory()->sales()->create();

    CompanySetting::firstOrCreate(
        ['id' => 1],
        [
            'company_name' => 'Vardhaman Infotech Solutions',
            'address' => 'Jaipur, Rajasthan',
            'gstin' => '08AABCV1234F1Z9',
            'pan' => 'AABCV1234F',
            'state' => 'Rajasthan',
            'state_code' => '08',
            'bank_account_name' => 'Vardhaman Infotech Solutions',
            'bank_account_number' => '987654321012',
            'bank_ifsc' => 'HDFC0001234',
            'bank_name' => 'HDFC Bank',
            'authorised_signatory_name' => 'Director',
        ]
    );

    $this->clientService = new ClientService;
});

test('Item 5 DB guard: clients table rejects duplicate lead_id via unique constraint', function () {
    $lead = Lead::factory()->create([
        'status' => LeadStatus::QUALIFIED,
        'assigned_to' => $this->sales->id,
    ]);

    Client::create([
        'lead_id' => $lead->id,
        'assigned_to' => $this->sales->id,
        'created_by' => $this->sales->id,
        'name' => 'Client One',
        'billing_address' => 'Jaipur',
        'state' => '08',
    ]);

    // Attempting to create a second client for the same lead must be rejected by unique constraint
    expect(fn () => Client::create([
        'lead_id' => $lead->id,
        'assigned_to' => $this->sales->id,
        'created_by' => $this->sales->id,
        'name' => 'Client Two',
        'billing_address' => 'Jaipur',
        'state' => '08',
    ]))->toThrow(QueryException::class);
});

test('Item 5 App guard: concurrent lead conversion rejects second request and allows only one client row', function () {
    $lead = Lead::factory()->create([
        'status' => LeadStatus::QUALIFIED,
        'assigned_to' => $this->sales->id,
    ]);

    $clientData1 = [
        'name' => 'Client Attempt 1',
        'billing_address' => 'Address 1, Jaipur',
        'state' => '08',
    ];

    $clientData2 = [
        'name' => 'Client Attempt 2',
        'billing_address' => 'Address 2, Jaipur',
        'state' => '08',
    ];

    $secondAttemptResult = null;
    $secondAttemptException = null;

    // Simulate concurrent request arriving before transaction 1 commits
    Client::creating(function ($client) use ($lead, $clientData2, &$secondAttemptException, &$secondAttemptResult) {
        static $alreadyFired = false;
        if (! $alreadyFired && $client->lead_id === $lead->id) {
            $alreadyFired = true;
            try {
                // Second concurrent request fires while first request is inside transaction
                $secondAttemptResult = $this->clientService->convertLeadToClient($lead->fresh(), $clientData2, $this->sales);
            } catch (Throwable $e) {
                $secondAttemptException = $e;
            }
        }
    });

    $firstClient = $this->clientService->convertLeadToClient($lead, $clientData1, $this->sales);

    expect($firstClient)->toBeInstanceOf(Client::class);
    // Second request must have failed with DomainException
    expect($secondAttemptException)->toBeInstanceOf(DomainException::class);
    expect($secondAttemptException?->getMessage())->toBe('This lead has already been converted.');

    // Only one Client row must ever exist for this lead
    expect(Client::where('lead_id', $lead->id)->count())->toBe(1);
});

test('Item 4 (Second-Pass): converting lead whose prior client was soft-deleted cleanly restores client without dead-end or unique constraint crash', function () {
    $lead = Lead::factory()->create([
        'status' => LeadStatus::QUALIFIED,
        'assigned_to' => $this->sales->id,
        'name' => 'Original Lead Name',
    ]);

    // 1. Initial conversion
    $client = $this->clientService->convertLeadToClient($lead, [
        'name' => 'Client v1',
        'billing_address' => 'Jaipur, Rajasthan',
        'state' => '08',
    ], $this->sales);

    expect(Client::where('lead_id', $lead->id)->count())->toBe(1);
    expect($lead->fresh()->status)->toBe(LeadStatus::CONVERTED);

    // 2. Client is soft-deleted (while invoice-free)
    $client->delete();
    expect(Client::where('lead_id', $lead->id)->count())->toBe(0);
    expect(Client::withTrashed()->where('lead_id', $lead->id)->count())->toBe(1);

    // 3. Re-convert the lead: must restore the soft-deleted client, update details, and return active client
    $reconvertedClient = $this->clientService->convertLeadToClient($lead->fresh(), [
        'name' => 'Client Restored',
        'billing_address' => 'Updated Address, Jaipur',
        'state' => '08',
    ], $this->sales);

    expect($reconvertedClient)->toBeInstanceOf(Client::class);
    expect($reconvertedClient->trashed())->toBeFalse();
    expect($reconvertedClient->name)->toBe('Client Restored');
    expect($reconvertedClient->billing_address)->toBe('Updated Address, Jaipur');
    expect(Client::where('lead_id', $lead->id)->count())->toBe(1);
});
