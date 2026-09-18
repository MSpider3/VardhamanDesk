<?php

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\CompanySetting;
use App\Models\GstRate;
use App\Models\User;
use App\Services\InvoiceCalculationService;
use App\Services\InvoiceDraftService;
use App\Services\InvoicePdfService;
use App\Services\InvoiceSendService;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['email' => 'admin_pdf@vardhaman.local']);
    $this->sales1 = User::factory()->sales()->create(['email' => 'sales1_pdf@vardhaman.local']);
    $this->sales2 = User::factory()->sales()->create(['email' => 'sales2_pdf@vardhaman.local']);

    $this->company = CompanySetting::firstOrCreate(
        ['id' => 1],
        [
            'company_name' => 'Vardhaman Infotech Solutions',
            'address' => 'Plot 42, Malviya Nagar, Jaipur, Rajasthan',
            'gstin' => '08AABCV1234F1Z9',
            'pan' => 'AABCV1234F',
            'state' => 'Rajasthan',
            'state_code' => '08',
            'logo_path' => null, // Optional logo null
            'bank_account_name' => 'Vardhaman Infotech',
            'bank_account_number' => '987654321012',
            'bank_ifsc' => 'HDFC0001234',
            'bank_name' => 'HDFC Bank',
            'authorised_signatory_name' => 'Director Signatory',
        ]
    );

    $this->gst18 = GstRate::firstOrCreate(['rate' => '18.00'], ['label' => '18% GST', 'is_active' => true]);

    $calcService = new InvoiceCalculationService;
    $this->draftService = new InvoiceDraftService($calcService);
    $this->sendService = new InvoiceSendService($calcService);
    $this->pdfService = new InvoicePdfService;

    // Client for Sales 1 without GSTIN (unregistered buyer)
    $this->clientSales1 = Client::factory()->create([
        'assigned_to' => $this->sales1->id,
        'state' => '08',
        'gstin' => null,
    ]);

    // Client for Sales 2
    $this->clientSales2 = Client::factory()->create([
        'assigned_to' => $this->sales2->id,
        'state' => '27',
        'gstin' => '27ABCDE1234F1Z5',
    ]);
});

test('authorized admin and sales owner can download invoice pdf', function () {
    $draft = $this->draftService->createDraft(
        ['client_id' => $this->clientSales1->id],
        [['description' => 'Web App Development', 'quantity' => 1, 'rate' => 50000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales1
    );
    $sentInvoice = $this->sendService->send($draft, '2026-06-10');

    // Sales 1 (owner) can download
    $responseSales = $this->actingAs($this->sales1)->get("/admin/invoices/{$sentInvoice->id}/pdf");
    $responseSales->assertSuccessful()
        ->assertHeader('content-type', 'application/pdf');

    // Admin can download
    $responseAdmin = $this->actingAs($this->admin)->get("/admin/invoices/{$sentInvoice->id}/pdf");
    $responseAdmin->assertSuccessful()
        ->assertHeader('content-type', 'application/pdf');
});

test('unauthorized sales user cannot download pdf of another sales user invoice', function () {
    $draft = $this->draftService->createDraft(
        ['client_id' => $this->clientSales2->id],
        [['description' => 'Security Audit', 'quantity' => 1, 'rate' => 20000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales2
    );
    $sentInvoice = $this->sendService->send($draft, '2026-06-10');

    // Sales 1 is NOT the owner of clientSales2 - blocked by ownership scope
    $response = $this->actingAs($this->sales1)->get("/admin/invoices/{$sentInvoice->id}/pdf");
    expect(in_array($response->status(), [403, 404], true))->toBeTrue();
});

test('draft invoice pdf generates successfully and contains DRAFT label', function () {
    $draft = $this->draftService->createDraft(
        ['client_id' => $this->clientSales1->id],
        [['description' => 'Consulting Draft', 'quantity' => 1, 'rate' => 10000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales1
    );

    expect($draft->status)->toBe(InvoiceStatus::DRAFT)
        ->and($draft->invoice_number)->toBeNull();

    $response = $this->actingAs($this->sales1)->get("/admin/invoices/{$draft->id}/pdf");
    $response->assertSuccessful();

    $filename = $this->pdfService->getFilename($draft);
    expect($filename)->toContain('DRAFT');

    $pdf = $this->pdfService->generate($draft);
    $output = $pdf->output();
    expect($output)->not->toBeEmpty();
});

test('pdf renders safely with missing optional fields like logo and null gstin', function () {
    $draft = $this->draftService->createDraft(
        ['client_id' => $this->clientSales1->id], // Client has gstin = null
        [['description' => 'Item Without Logo', 'quantity' => 2, 'rate' => 1500, 'gst_rate_id' => $this->gst18->id]],
        $this->sales1
    );

    $pdf = $this->pdfService->generate($draft);
    $output = $pdf->output();

    expect($output)->not->toBeEmpty();
});
