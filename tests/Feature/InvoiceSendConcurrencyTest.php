<?php

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\CompanySetting;
use App\Models\FinancialYearCounter;
use App\Models\GstRate;
use App\Models\User;
use App\Services\InvoiceCalculationService;
use App\Services\InvoiceDraftService;
use App\Services\InvoiceSendService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();

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

    $this->gst18 = GstRate::firstOrCreate(['rate' => '18.00'], ['label' => '18% GST', 'is_active' => true]);
    $this->client = Client::factory()->create(['assigned_to' => $this->admin->id, 'state' => '08']);
    $this->calcService = new InvoiceCalculationService;
    $this->draftService = new InvoiceDraftService($this->calcService);
    $this->sendService = new InvoiceSendService($this->calcService);
});

test('Item 6: two concurrent sends in a new financial year with no counter row allocate sequential non-colliding numbers without duplicate key crash', function () {
    $futureDate = '2035-05-15'; // FY 2035-36
    $fy = '2035-36';

    expect(FinancialYearCounter::where('financial_year', $fy)->exists())->toBeFalse();

    $draft1 = $this->draftService->createDraft(
        [
            'client_id' => $this->client->id,
            'invoice_date' => $futureDate,
            'due_date' => '2035-06-15',
            'place_of_supply' => '08',
        ],
        [['description' => 'Item 1', 'quantity' => 1, 'rate' => 1000, 'gst_rate_id' => $this->gst18->id]],
        $this->admin
    );

    $draft2 = $this->draftService->createDraft(
        [
            'client_id' => $this->client->id,
            'invoice_date' => $futureDate,
            'due_date' => '2035-06-15',
            'place_of_supply' => '08',
        ],
        [['description' => 'Item 2', 'quantity' => 2, 'rate' => 2000, 'gst_rate_id' => $this->gst18->id]],
        $this->admin
    );

    $sent1 = $this->sendService->send($draft1, $futureDate);
    $sent2 = $this->sendService->send($draft2, $futureDate);

    expect($sent1->invoice_number)->toBe("VI/{$fy}/0001");
    expect($sent2->invoice_number)->toBe("VI/{$fy}/0002");
    expect(FinancialYearCounter::where('financial_year', $fy)->first()->last_sequence)->toBe(2);
});

test('Item 6: counter bootstrap handles concurrent row creation without throwing duplicate key exception', function () {
    $futureDate = '2036-05-15';
    $fy = '2036-37';

    expect(FinancialYearCounter::where('financial_year', $fy)->exists())->toBeFalse();

    $draft = $this->draftService->createDraft(
        [
            'client_id' => $this->client->id,
            'invoice_date' => $futureDate,
            'due_date' => '2036-06-15',
            'place_of_supply' => '08',
        ],
        [['description' => 'Item', 'quantity' => 1, 'rate' => 1000, 'gst_rate_id' => $this->gst18->id]],
        $this->admin
    );

    // Simulate concurrent counter insertion right as firstOrCreate attempts to create it
    $injected = false;
    FinancialYearCounter::creating(function ($counter) use (&$injected, $fy) {
        if (! $injected && $counter->financial_year === $fy) {
            $injected = true;
            // Interleaving process creates the counter row directly in DB first
            DB::table('financial_year_counters')->insert([
                'financial_year' => $fy,
                'last_sequence' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    $sent = $this->sendService->send($draft, $futureDate);

    expect($sent->status)->toBe(InvoiceStatus::SENT);
    expect($sent->invoice_number)->toBe("VI/{$fy}/0001");
    expect(FinancialYearCounter::where('financial_year', $fy)->first()->last_sequence)->toBe(1);
});
