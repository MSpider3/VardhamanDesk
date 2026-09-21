<?php

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\CompanySetting;
use App\Models\FinancialYearCounter;
use App\Models\GstRate;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\InvoiceCalculationService;
use App\Services\InvoiceDraftService;
use App\Services\InvoiceSendService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['email' => 'admin_send@vardhaman.local']);
    $this->sales1 = User::factory()->sales()->create(['email' => 'sales1_send@vardhaman.local']);

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

    $this->calcService = new InvoiceCalculationService;
    $this->draftService = new InvoiceDraftService($this->calcService);
    $this->sendService = new InvoiceSendService($this->calcService);

    $this->client = Client::factory()->create([
        'assigned_to' => $this->sales1->id,
        'state' => '08',
    ]);
});

test('financial year is derived correctly across april 1 boundary', function () {
    expect(InvoiceSendService::deriveFinancialYear('2026-03-31'))->toBe('2025-26')
        ->and(InvoiceSendService::deriveFinancialYear('2026-04-01'))->toBe('2026-27')
        ->and(InvoiceSendService::deriveFinancialYear('2026-12-15'))->toBe('2026-27')
        ->and(InvoiceSendService::deriveFinancialYear('2027-01-10'))->toBe('2026-27')
        ->and(InvoiceSendService::deriveFinancialYear('2027-03-31'))->toBe('2026-27')
        ->and(InvoiceSendService::deriveFinancialYear('2027-04-01'))->toBe('2027-28');
});

test('sending draft allocates formatted sequential number starting from 0001 per FY', function () {
    FinancialYearCounter::query()->delete();

    $draft1 = $this->draftService->createDraft(
        ['client_id' => $this->client->id, 'invoice_date' => '2026-05-10'],
        [['description' => 'Service A', 'quantity' => 1, 'rate' => 1000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales1
    );

    $draft2 = $this->draftService->createDraft(
        ['client_id' => $this->client->id, 'invoice_date' => '2026-05-12'],
        [['description' => 'Service B', 'quantity' => 2, 'rate' => 2000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales1
    );

    $sent1 = $this->sendService->send($draft1, '2026-05-10');
    $sent2 = $this->sendService->send($draft2, '2026-05-12');

    expect($sent1->invoice_number)->toBe('VI/2026-27/0001')
        ->and($sent1->financial_year)->toBe('2026-27')
        ->and($sent1->sequence_number)->toBe(1)
        ->and($sent1->status)->toBe(InvoiceStatus::SENT);

    expect($sent2->invoice_number)->toBe('VI/2026-27/0002')
        ->and($sent2->financial_year)->toBe('2026-27')
        ->and($sent2->sequence_number)->toBe(2)
        ->and($sent2->status)->toBe(InvoiceStatus::SENT);
});

test('different financial years maintain separate sequential counters', function () {
    Carbon::setTestNow('2026-03-15');
    try {
        $draftFy25 = $this->draftService->createDraft(
            ['client_id' => $this->client->id, 'invoice_date' => '2026-03-15'],
            [['description' => 'Past FY Service', 'quantity' => 1, 'rate' => 5000, 'gst_rate_id' => $this->gst18->id]],
            $this->sales1
        );

        $draftFy26 = $this->draftService->createDraft(
            ['client_id' => $this->client->id, 'invoice_date' => '2026-04-15'],
            [['description' => 'Current FY Service', 'quantity' => 1, 'rate' => 5000, 'gst_rate_id' => $this->gst18->id]],
            $this->sales1
        );

        $sentFy25 = $this->sendService->send($draftFy25, '2026-03-15');
        $sentFy26 = $this->sendService->send($draftFy26, '2026-04-15');

        expect($sentFy25->invoice_number)->toBe('VI/2025-26/0001')
            ->and($sentFy25->financial_year)->toBe('2025-26')
            ->and($sentFy25->sequence_number)->toBe(1);

        expect($sentFy26->invoice_number)->toBe('VI/2026-27/0001')
            ->and($sentFy26->financial_year)->toBe('2026-27')
            ->and($sentFy26->sequence_number)->toBe(1);
    } finally {
        Carbon::setTestNow();
    }
});

test('deleted draft consumes no sequence number and leaves no gaps', function () {
    FinancialYearCounter::query()->delete();

    $draftToKeep = $this->draftService->createDraft(
        ['client_id' => $this->client->id],
        [['description' => 'Kept', 'quantity' => 1, 'rate' => 1000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales1
    );

    $draftToDelete = $this->draftService->createDraft(
        ['client_id' => $this->client->id],
        [['description' => 'Discarded', 'quantity' => 1, 'rate' => 1000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales1
    );

    $draftToDelete->delete();

    $sent = $this->sendService->send($draftToKeep, '2026-06-01');

    // Number must still be 0001
    expect($sent->invoice_number)->toBe('VI/2026-27/0001')
        ->and($sent->sequence_number)->toBe(1);
});

test('repeated send attempt is idempotent and does not increment sequence', function () {
    FinancialYearCounter::query()->delete();

    $draft = $this->draftService->createDraft(
        ['client_id' => $this->client->id],
        [['description' => 'Item', 'quantity' => 1, 'rate' => 1000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales1
    );

    $firstSend = $this->sendService->send($draft, '2026-06-01');
    expect($firstSend->invoice_number)->toBe('VI/2026-27/0001');

    // Second call on already sent invoice
    $secondSend = $this->sendService->send($firstSend, '2026-06-01');
    expect($secondSend->invoice_number)->toBe('VI/2026-27/0001')
        ->and(FinancialYearCounter::where('financial_year', '2026-27')->first()->last_sequence)->toBe(1);
});

test('sent invoice deletion is strictly blocked for both admin and sales at policy and model layers', function () {
    $draft = $this->draftService->createDraft(
        ['client_id' => $this->client->id],
        [['description' => 'Item', 'quantity' => 1, 'rate' => 1000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales1
    );

    $sent = $this->sendService->send($draft, '2026-06-01');

    // Policy blocks deletion
    expect(Gate::forUser($this->sales1)->allows('delete', $sent))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('delete', $sent))->toBeFalse();

    // Model lifecycle guard blocks deletion
    expect(fn () => $sent->delete())->toThrow(DomainException::class);
});

test('sent invoice line items and financial fields cannot be modified', function () {
    $draft = $this->draftService->createDraft(
        ['client_id' => $this->client->id],
        [['description' => 'Original', 'quantity' => 1, 'rate' => 1000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales1
    );

    $sent = $this->sendService->send($draft, '2026-06-01');

    // Policy blocks update
    expect(Gate::forUser($this->sales1)->allows('update', $sent))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('update', $sent))->toBeFalse();

    // Model lifecycle blocks updating financial fields
    expect(fn () => $sent->update(['total' => '500.00']))->toThrow(DomainException::class);

    // Model lifecycle blocks adding line item
    expect(fn () => InvoiceItem::create([
        'invoice_id' => $sent->id,
        'description' => 'Sneaky line item',
        'sac_code' => '998313',
        'quantity' => 1,
        'rate' => 100,
        'amount' => 100,
        'gst_rate_id' => $this->gst18->id,
    ]))->toThrow(DomainException::class);

    // Model lifecycle blocks modifying existing line item
    $existingItem = $sent->items()->first();
    expect(fn () => $existingItem->update(['rate' => '9999.00']))->toThrow(DomainException::class);

    // Model lifecycle blocks deleting existing line item
    expect(fn () => $existingItem->delete())->toThrow(DomainException::class);
});

test('multiple drafts sent in sequence never collide and increment sequence sequentially', function () {
    FinancialYearCounter::query()->delete();

    $sentInvoices = [];
    for ($i = 1; $i <= 5; $i++) {
        $draft = $this->draftService->createDraft(
            ['client_id' => $this->client->id, 'invoice_date' => '2026-06-01'],
            [['description' => "Item {$i}", 'quantity' => 1, 'rate' => 1000 * $i, 'gst_rate_id' => $this->gst18->id]],
            $this->sales1
        );
        $sentInvoices[] = $this->sendService->send($draft, '2026-06-01');
    }

    foreach ($sentInvoices as $index => $invoice) {
        $expectedSeq = $index + 1;
        $expectedNumber = sprintf('VI/2026-27/%04d', $expectedSeq);
        expect($invoice->sequence_number)->toBe($expectedSeq)
            ->and($invoice->invoice_number)->toBe($expectedNumber);
    }

    $counter = FinancialYearCounter::where('financial_year', '2026-27')->first();
    expect($counter->last_sequence)->toBe(5);
});

test('Item 9: backdating invoice into already-closed financial year is rejected while current or future FY sends successfully', function () {
    FinancialYearCounter::query()->delete();

    // 1. Send an invoice in FY 2026-27 (establishing 2026-27 as the highest active FY)
    $draftCurrent = $this->draftService->createDraft(
        ['client_id' => $this->client->id, 'invoice_date' => '2026-06-01'],
        [['description' => 'Current Year Service', 'quantity' => 1, 'rate' => 1000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales1
    );
    $sentCurrent = $this->sendService->send($draftCurrent, '2026-06-01');
    expect($sentCurrent->financial_year)->toBe('2026-27');

    // 2. Attempt to send a draft backdated into FY 2025-26 -> MUST be rejected with clear DomainException
    $draftBackdated = $this->draftService->createDraft(
        ['client_id' => $this->client->id, 'invoice_date' => '2026-03-15'],
        [['description' => 'Backdated Service', 'quantity' => 1, 'rate' => 1000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales1
    );

    expect(fn () => $this->sendService->send($draftBackdated, '2026-03-15'))
        ->toThrow(DomainException::class, "This invoice's date falls in an already-closed financial year (2025-26); update the invoice date to fall within 2026-27, or contact an admin.");

    // 3. Send a draft forward-dated into a future FY (2027-28) -> MUST succeed (does not over-block)
    $draftFuture = $this->draftService->createDraft(
        ['client_id' => $this->client->id, 'invoice_date' => '2027-04-10'],
        [['description' => 'Future Service', 'quantity' => 1, 'rate' => 1000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales1
    );

    $sentFuture = $this->sendService->send($draftFuture, '2027-04-10');
    expect($sentFuture->financial_year)->toBe('2027-28');
    expect($sentFuture->invoice_number)->toBe('VI/2027-28/0001');

    // 4. Send another ordinary invoice dated in 2026-27 (today's real FY) -> MUST still succeed and not be locked out by the forward-dated invoice!
    $draftCurrentAfterFuture = $this->draftService->createDraft(
        ['client_id' => $this->client->id, 'invoice_date' => '2026-07-01'],
        [['description' => 'Current Year Service After Forward Dated', 'quantity' => 1, 'rate' => 1000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales1
    );

    $sentCurrentAfterFuture = $this->sendService->send($draftCurrentAfterFuture, '2026-07-01');
    expect($sentCurrentAfterFuture->financial_year)->toBe('2026-27');
    expect($sentCurrentAfterFuture->invoice_number)->toBe('VI/2026-27/0002');
});
