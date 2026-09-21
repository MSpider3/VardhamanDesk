<?php

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Models\Client;
use App\Models\CompanySetting;
use App\Models\GstRate;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

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
});

test('Item 2: sorting invoices by paid_amount and outstanding_amount succeeds without SQL error and produces correct order on MySQL', function () {
    $this->actingAs($this->admin);

    // Invoice A: Total 1000, Paid 1000, Balance 0
    $invA = Invoice::factory()->create([
        'status' => InvoiceStatus::SENT,
        'invoice_number' => 'VI/2026-27/0001',
        'client_id' => $this->client->id,
        'total' => '1000.00',
        'subtotal' => '847.46',
        'cgst_amount' => '76.27',
        'sgst_amount' => '76.27',
        'created_by' => $this->admin->id,
    ]);
    Payment::factory()->create(['invoice_id' => $invA->id, 'amount' => '1000.00', 'recorded_by' => $this->admin->id]);

    // Invoice B: Total 2000, Paid 500, Balance 1500
    $invB = Invoice::factory()->create([
        'status' => InvoiceStatus::SENT,
        'invoice_number' => 'VI/2026-27/0002',
        'client_id' => $this->client->id,
        'total' => '2000.00',
        'subtotal' => '1694.92',
        'cgst_amount' => '152.54',
        'sgst_amount' => '152.54',
        'created_by' => $this->admin->id,
    ]);
    Payment::factory()->create(['invoice_id' => $invB->id, 'amount' => '500.00', 'recorded_by' => $this->admin->id]);

    // Invoice C: Total 3000, Paid 0, Balance 3000
    $invC = Invoice::factory()->create([
        'status' => InvoiceStatus::SENT,
        'invoice_number' => 'VI/2026-27/0003',
        'client_id' => $this->client->id,
        'total' => '3000.00',
        'subtotal' => '2542.37',
        'cgst_amount' => '228.81',
        'sgst_amount' => '228.81',
        'created_by' => $this->admin->id,
    ]);

    // Sort by paid_amount ascending -> should be Inv C (0), Inv B (500), Inv A (1000)
    Livewire::test(ListInvoices::class)
        ->sortTable('paid_amount', 'asc')
        ->assertCanSeeTableRecords([$invC, $invB, $invA], inOrder: true);

    // Sort by paid_amount descending -> Inv A (1000), Inv B (500), Inv C (0)
    Livewire::test(ListInvoices::class)
        ->sortTable('paid_amount', 'desc')
        ->assertCanSeeTableRecords([$invA, $invB, $invC], inOrder: true);

    // Sort by outstanding_amount ascending -> Inv A (0), Inv B (1500), Inv C (3000)
    Livewire::test(ListInvoices::class)
        ->sortTable('outstanding_amount', 'asc')
        ->assertCanSeeTableRecords([$invA, $invB, $invC], inOrder: true);

    // Sort by outstanding_amount descending -> Inv C (3000), Inv B (1500), Inv A (0)
    Livewire::test(ListInvoices::class)
        ->sortTable('outstanding_amount', 'desc')
        ->assertCanSeeTableRecords([$invC, $invB, $invA], inOrder: true);
});

test('Item 4: invoice list query count is constant between 5 and 50 invoices (no N+1) including invoices with zero payments', function () {
    $this->actingAs($this->admin);

    // Seed 5 invoices: 4 with zero payments, 1 with payment
    for ($i = 0; $i < 5; $i++) {
        $inv = Invoice::factory()->create([
            'status' => InvoiceStatus::SENT,
            'client_id' => $this->client->id,
            'total' => '1000.00',
            'created_by' => $this->admin->id,
        ]);
        if ($i === 0) {
            Payment::factory()->create(['invoice_id' => $inv->id, 'amount' => '250.00', 'recorded_by' => $this->admin->id]);
        }
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::test(ListInvoices::class);

    $queriesFor5 = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Now add 45 more invoices (total 50): mostly with zero payments, some with payments
    for ($i = 0; $i < 45; $i++) {
        $inv = Invoice::factory()->create([
            'status' => InvoiceStatus::SENT,
            'client_id' => $this->client->id,
            'total' => '1000.00',
            'created_by' => $this->admin->id,
        ]);
        if ($i % 5 === 0) {
            Payment::factory()->create(['invoice_id' => $inv->id, 'amount' => '250.00', 'recorded_by' => $this->admin->id]);
        }
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::test(ListInvoices::class);

    $queriesFor50 = count(DB::getQueryLog());
    DB::disableQueryLog();

    // With N+1, invoices with zero payments fall through and issue individual queries.
    // Query count must be strictly constant (difference at most 1 query for session/pagination).
    expect($queriesFor50)->toBeLessThanOrEqual($queriesFor5 + 1);
});
