<?php

use App\Enums\InvoiceStatus;
use App\Enums\LeadStatus;
use App\Models\Client;
use App\Models\CompanySetting;
use App\Models\GstRate;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\User;
use App\Services\ClientService;
use App\Services\InvoiceCalculationService;
use App\Services\InvoiceDraftService;
use App\Services\InvoicePdfService;
use App\Services\InvoiceSendService;
use App\Services\RecordPayment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['email' => 'admin_sec@vardhaman.local']);
    $this->sales1 = User::factory()->sales()->create(['email' => 'sales1_sec@vardhaman.local']);
    $this->sales2 = User::factory()->sales()->create(['email' => 'sales2_sec@vardhaman.local']);

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
});

test('VULN-01: sent or paid invoice cannot transition back to draft status', function () {
    $client = Client::factory()->create(['assigned_to' => $this->sales1->id, 'state' => '08']);
    $draft = $this->draftService->createDraft(
        ['client_id' => $client->id],
        [['description' => 'Service', 'quantity' => 1, 'rate' => 1000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales1
    );

    $sent = $this->sendService->send($draft);
    expect($sent->status)->toBe(InvoiceStatus::SENT);

    expect(fn () => $sent->update(['status' => InvoiceStatus::DRAFT]))
        ->toThrow(DomainException::class, 'Cannot transition a locked (sent/paid) invoice back to draft status.');
});

test('VULN-01: paid invoice cannot transition backwards to sent or partially paid', function () {
    $client = Client::factory()->create(['assigned_to' => $this->sales1->id, 'state' => '08']);
    $draft = $this->draftService->createDraft(
        ['client_id' => $client->id],
        [['description' => 'Service', 'quantity' => 1, 'rate' => 1000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales1
    );

    $sent = $this->sendService->send($draft);

    $paymentService = new RecordPayment;
    $paymentService->execute($sent, [
        'amount' => 1180.00,
        'payment_date' => '2026-06-01',
    ], $this->sales1);

    $paidInvoice = $sent->fresh();
    expect($paidInvoice->status)->toBe(InvoiceStatus::PAID);

    expect(fn () => $paidInvoice->update(['status' => InvoiceStatus::SENT]))
        ->toThrow(DomainException::class, 'A fully paid invoice cannot transition to another status.');
});

test('VULN-01: locked invoice number and sequence cannot be modified', function () {
    $client = Client::factory()->create(['assigned_to' => $this->sales1->id, 'state' => '08']);
    $draft = $this->draftService->createDraft(
        ['client_id' => $client->id],
        [['description' => 'Service', 'quantity' => 1, 'rate' => 1000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales1
    );

    $sent = $this->sendService->send($draft);

    expect(fn () => $sent->update(['invoice_number' => 'FORGED/0001']))
        ->toThrow(DomainException::class, 'Cannot modify financial details or line items of a locked (sent/paid) invoice.');

    expect(fn () => $sent->update(['sequence_number' => 999]))
        ->toThrow(DomainException::class, 'Cannot modify financial details or line items of a locked (sent/paid) invoice.');
});

test('VULN-02: sales user cannot create draft invoice for another sales user client (IDOR)', function () {
    $clientOfSales2 = Client::factory()->create(['assigned_to' => $this->sales2->id, 'state' => '08']);

    expect(fn () => $this->draftService->createDraft(
        ['client_id' => $clientOfSales2->id],
        [['description' => 'Service', 'quantity' => 1, 'rate' => 1000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales1
    ))->toThrow(AuthorizationException::class, 'You are not authorized to create invoices for this client.');
});

test('VULN-02: admin can create draft invoice for any client', function () {
    $clientOfSales2 = Client::factory()->create(['assigned_to' => $this->sales2->id, 'state' => '08']);

    $draft = $this->draftService->createDraft(
        ['client_id' => $clientOfSales2->id],
        [['description' => 'Service', 'quantity' => 1, 'rate' => 1000, 'gst_rate_id' => $this->gst18->id]],
        $this->admin
    );

    expect($draft)->toBeInstanceOf(Invoice::class)
        ->and($draft->client_id)->toBe($clientOfSales2->id);
});

test('VULN-03: client with non-draft invoice cannot be deleted', function () {
    $client = Client::factory()->create(['assigned_to' => $this->sales1->id, 'state' => '08']);
    $draft = $this->draftService->createDraft(
        ['client_id' => $client->id],
        [['description' => 'Service', 'quantity' => 1, 'rate' => 1000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales1
    );

    $this->sendService->send($draft);

    $this->actingAs($this->sales1);
    expect(Gate::forUser($this->sales1)->allows('delete', $client))->toBeFalse();

    expect(fn () => $client->delete())
        ->toThrow(DomainException::class, 'Cannot delete client with active, sent, or paid invoices.');
});

test('VULN-03: client without invoices can be deleted and soft-deleted client invoices remain viewable by owner', function () {
    $clientEmpty = Client::factory()->create(['assigned_to' => $this->sales1->id, 'state' => '08']);
    expect(Gate::forUser($this->sales1)->allows('delete', $clientEmpty))->toBeTrue();
    expect($clientEmpty->delete())->toBeTrue();

    // Now test invoice ownership scope resilience with soft-deleted client
    $clientWithDraft = Client::factory()->create(['assigned_to' => $this->sales1->id, 'state' => '08']);
    $draft = $this->draftService->createDraft(
        ['client_id' => $clientWithDraft->id],
        [['description' => 'Service', 'quantity' => 1, 'rate' => 1000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales1
    );
    // Soft delete the client directly via DB query to test scope resilience
    Client::withoutGlobalScopes()->where('id', $clientWithDraft->id)->delete();

    $this->actingAs($this->sales1);
    // Invoice should still be discoverable by sales1 via InvoiceOwnershipScope
    $foundInvoices = Invoice::where('id', $draft->id)->get();
    expect($foundInvoices)->toHaveCount(1);

    // Gate::allows('view', $draft) should still return true for sales1
    expect(Gate::forUser($this->sales1)->allows('view', $draft))->toBeTrue();

    // Sales2 should still be denied
    expect(Gate::forUser($this->sales2)->allows('view', $draft))->toBeFalse();

    // PDF service works
    $pdfService = new InvoicePdfService;
    $pdf = $pdfService->generate($draft);
    expect($pdf)->not->toBeNull();
});

test('VULN-04: calculation service rejects non-positive quantity and negative rate', function () {
    expect(fn () => $this->calcService->calculate('08', [
        ['quantity' => 0, 'rate' => 500, 'gst_rate_id' => $this->gst18->id],
    ]))->toThrow(DomainException::class, 'Line item quantity must be greater than zero.');

    expect(fn () => $this->calcService->calculate('08', [
        ['quantity' => -2, 'rate' => 500, 'gst_rate_id' => $this->gst18->id],
    ]))->toThrow(DomainException::class, 'Line item quantity must be greater than zero.');

    expect(fn () => $this->calcService->calculate('08', [
        ['quantity' => 1, 'rate' => -100, 'gst_rate_id' => $this->gst18->id],
    ]))->toThrow(DomainException::class, 'Line item unit rate cannot be negative.');
});

test('VULN-05: sales user cannot reassign client at model level', function () {
    $client = Client::factory()->create(['assigned_to' => $this->sales1->id, 'state' => '08']);

    $this->actingAs($this->sales1);

    expect(fn () => $client->update(['assigned_to' => $this->sales2->id]))
        ->toThrow(AuthorizationException::class, 'Sales representatives cannot reassign clients.');

    // Admin CAN reassign
    $this->actingAs($this->admin);
    $client->update(['assigned_to' => $this->sales2->id]);
    expect($client->fresh()->assigned_to)->toBe($this->sales2->id);
});

test('Item 10: CSP header is tightened and does not contain open https: wildcard source', function () {
    $response = $this->actingAs($this->admin)->get('/admin');

    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->not->toBeNull();
    // Must NOT contain bare https:
    expect($csp)->not->toMatch('/(^|\s)https:($|[\s;])/');
    // Must contain scoped directives
    expect($csp)->toContain("default-src 'self'");
    expect($csp)->toContain("script-src 'self'");
    expect($csp)->toContain("style-src 'self'");
});

test('VULN-06: sales user cannot convert a lead assigned to another sales user (IDOR)', function () {
    $leadOfSales2 = Lead::factory()->create([
        'assigned_to' => $this->sales2->id,
        'status' => LeadStatus::QUALIFIED,
    ]);

    $clientService = new ClientService;

    expect(fn () => $clientService->convertLeadToClient($leadOfSales2, [
        'billing_address' => '123 Business Way, Jaipur',
        'state' => '08',
    ], $this->sales1))->toThrow(AuthorizationException::class, 'You are not authorized to convert this lead.');

    // Admin CAN convert
    $client = $clientService->convertLeadToClient($leadOfSales2->fresh(), [
        'billing_address' => '123 Business Way, Jaipur',
        'state' => '08',
    ], $this->admin);

    expect($client)->toBeInstanceOf(Client::class)
        ->and($client->lead_id)->toBe($leadOfSales2->id);
});

test('HTTP security headers (X-Frame-Options, X-Content-Type-Options, Referrer-Policy, HSTS) are strictly configured', function () {
    $response = $this->actingAs($this->admin)->get('/admin');

    expect($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Referrer-Policy'))->toBe('same-origin')
        ->and($response->headers->get('Permissions-Policy'))->toContain('geolocation=(), microphone=()');

    // Secure request receives HSTS
    $secureResponse = $this->actingAs($this->admin)->get('https://localhost/admin');
    expect($secureResponse->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000; includeSubDomains');
});
