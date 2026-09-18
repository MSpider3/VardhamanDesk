<?php

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\CompanySetting;
use App\Models\GstRate;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceCalculationService;
use App\Services\InvoiceDraftService;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['email' => 'admin_calc@vardhaman.local']);
    $this->sales1 = User::factory()->sales()->create(['email' => 'sales1_calc@vardhaman.local']);
    $this->sales2 = User::factory()->sales()->create(['email' => 'sales2_calc@vardhaman.local']);

    CompanySetting::firstOrCreate(
        ['id' => 1],
        [
            'company_name' => 'Vardhaman Infotech',
            'address' => 'Jaipur, Rajasthan',
            'gstin' => '08AABCV1234F1Z9',
            'pan' => 'AABCV1234F',
            'state' => 'Rajasthan',
            'state_code' => '08',
            'bank_account_name' => 'Vardhaman Infotech',
            'bank_account_number' => '1234567890',
            'bank_ifsc' => 'HDFC0001234',
            'bank_name' => 'HDFC Bank',
            'authorised_signatory_name' => 'Signatory',
        ]
    );

    $this->gst18 = GstRate::firstOrCreate(['rate' => '18.00'], ['label' => '18% GST', 'is_active' => true]);
    $this->gst5 = GstRate::firstOrCreate(['rate' => '5.00'], ['label' => '5% GST', 'is_active' => true]);
    $this->gst0 = GstRate::firstOrCreate(['rate' => '0.00'], ['label' => '0% GST', 'is_active' => true]);

    $this->calcService = new InvoiceCalculationService;
    $this->draftService = new InvoiceDraftService($this->calcService);

    $this->clientRaj = Client::factory()->create([
        'assigned_to' => $this->sales1->id,
        'state' => '08',
    ]);

    $this->clientMh = Client::factory()->create([
        'assigned_to' => $this->sales1->id,
        'state' => '27',
    ]);

    $this->clientSales2 = Client::factory()->create([
        'assigned_to' => $this->sales2->id,
        'state' => '08',
    ]);
});

test('draft invoice has null invoice_number and displays as DRAFT', function () {
    $invoice = $this->draftService->createDraft(
        ['client_id' => $this->clientRaj->id],
        [
            [
                'description' => 'Test Item',
                'sac_code' => '998313',
                'quantity' => '1',
                'rate' => '1000',
                'gst_rate_id' => $this->gst18->id,
            ],
        ],
        $this->sales1
    );

    expect($invoice->invoice_number)->toBeNull()
        ->and($invoice->financial_year)->toBeNull()
        ->and($invoice->sequence_number)->toBeNull()
        ->and($invoice->status)->toBe(InvoiceStatus::DRAFT)
        ->and($invoice->display_number)->toBe('DRAFT');
});

test('due date defaults to invoice date plus 30 days', function () {
    $invoice = $this->draftService->createDraft(
        [
            'client_id' => $this->clientRaj->id,
            'invoice_date' => '2026-05-01',
        ],
        [],
        $this->sales1
    );

    expect($invoice->invoice_date->format('Y-m-d'))->toBe('2026-05-01')
        ->and($invoice->due_date->format('Y-m-d'))->toBe('2026-05-31');
});

test('rajasthan place of supply produces intra-state cgst and sgst with zero igst', function () {
    $items = [
        [
            'quantity' => '2.00',
            'rate' => '1000.00',
            'gst_rate_id' => $this->gst18->id,
        ],
    ];

    $calc = $this->calcService->calculate('08', $items);

    expect($calc['is_intra_state'])->toBeTrue()
        ->and($calc['subtotal'])->toBe('2000.00')
        ->and($calc['cgst_amount'])->toBe('180.00') // 9% of 2000
        ->and($calc['sgst_amount'])->toBe('180.00') // 9% of 2000
        ->and($calc['igst_amount'])->toBe('0.00')
        ->and($calc['total'])->toBe('2360.00');
});

test('inter-state place of supply produces igst with zero cgst and sgst', function () {
    $items = [
        [
            'quantity' => '2.00',
            'rate' => '1000.00',
            'gst_rate_id' => $this->gst18->id,
        ],
    ];

    $calc = $this->calcService->calculate('27', $items); // Maharashtra

    expect($calc['is_intra_state'])->toBeFalse()
        ->and($calc['subtotal'])->toBe('2000.00')
        ->and($calc['cgst_amount'])->toBe('0.00')
        ->and($calc['sgst_amount'])->toBe('0.00')
        ->and($calc['igst_amount'])->toBe('360.00') // 18% of 2000
        ->and($calc['total'])->toBe('2360.00');
});

test('mixed gst rates on same invoice aggregate correctly line by line', function () {
    $items = [
        [
            'quantity' => '1.00',
            'rate' => '10000.00',
            'gst_rate_id' => $this->gst18->id, // 18% -> 900 CGST + 900 SGST
        ],
        [
            'quantity' => '1.00',
            'rate' => '5000.00',
            'gst_rate_id' => $this->gst5->id,  // 5% -> 125 CGST + 125 SGST
        ],
        [
            'quantity' => '1.00',
            'rate' => '2000.00',
            'gst_rate_id' => $this->gst0->id,  // 0% -> 0 CGST + 0 SGST
        ],
    ];

    $calc = $this->calcService->calculate('08', $items);

    expect($calc['subtotal'])->toBe('17000.00')
        ->and($calc['cgst_amount'])->toBe('1025.00') // 900 + 125
        ->and($calc['sgst_amount'])->toBe('1025.00') // 900 + 125
        ->and($calc['igst_amount'])->toBe('0.00')
        ->and($calc['total'])->toBe('19050.00');
});

test('server recalculates totals and ignores client-submitted tax amounts', function () {
    // Attempting to pass crafted 0 tax on a 18% item
    $invoice = $this->draftService->createDraft(
        [
            'client_id' => $this->clientRaj->id,
            'subtotal' => '100.00', // Spoofed
            'total' => '100.00',    // Spoofed
        ],
        [
            [
                'description' => 'Real Item',
                'sac_code' => '998313',
                'quantity' => '1',
                'rate' => '1000',
                'gst_rate_id' => $this->gst18->id,
                'cgst_amount' => '0.00', // Spoofed
            ],
        ],
        $this->sales1
    );

    // Server calculation must prevail
    expect($invoice->subtotal)->toBe('1000.00')
        ->and($invoice->cgst_amount)->toBe('90.00')
        ->and($invoice->sgst_amount)->toBe('90.00')
        ->and($invoice->total)->toBe('1180.00');
});

test('draft invoice can be deleted by owner and admin', function () {
    $draft = $this->draftService->createDraft(
        ['client_id' => $this->clientRaj->id],
        [],
        $this->sales1
    );

    expect(Gate::forUser($this->sales1)->allows('delete', $draft))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('delete', $draft))->toBeTrue();

    // Sales 2 cannot delete it
    expect(Gate::forUser($this->sales2)->allows('delete', $draft))->toBeFalse();

    $draft->delete();
    expect(Invoice::find($draft->id))->toBeNull();
});

test('sales user cannot view or update draft for another sales user client', function () {
    $draftSales2 = $this->draftService->createDraft(
        ['client_id' => $this->clientSales2->id],
        [],
        $this->sales2
    );

    $this->actingAs($this->sales1);
    expect(Invoice::where('id', $draftSales2->id)->count())->toBe(0);

    expect(Gate::forUser($this->sales1)->allows('view', $draftSales2))->toBeFalse();
    expect(Gate::forUser($this->sales1)->allows('update', $draftSales2))->toBeFalse();
});
