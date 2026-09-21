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
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->sales = User::factory()->sales()->create();

    $this->company = CompanySetting::firstOrCreate(
        ['id' => 1],
        [
            'company_name' => 'Vardhaman Infotech Solutions',
            'address' => 'Plot 42, Malviya Nagar, Jaipur, Rajasthan',
            'gstin' => '08AABCV1234F1Z9',
            'pan' => 'AABCV1234F',
            'state' => 'Rajasthan',
            'state_code' => '08',
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

    // Intra-state client (Rajasthan 08)
    $this->client = Client::factory()->create([
        'assigned_to' => $this->sales->id,
        'state' => '08',
    ]);
});

test('editing an existing GST rate does not alter historical sent invoice PDF tax percentages', function () {
    // 1. Create and send an invoice at 18% GST
    $draft = $this->draftService->createDraft(
        ['client_id' => $this->client->id, 'place_of_supply' => '08'],
        [['description' => 'Development Services', 'quantity' => 1, 'rate' => 10000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales
    );

    $sentInvoice = $this->sendService->send($draft, '2026-06-15');
    expect($sentInvoice->status)->toBe(InvoiceStatus::SENT);

    // Initial PDF rendering shows 9% CGST and 9% SGST (18% total)
    $renderedBefore = view('invoices.pdf', [
        'invoice' => $sentInvoice->fresh(['items.gstRate', 'client', 'payments']),
        'company' => $this->company,
        'safeLogoPath' => null,
    ])->render();

    expect($renderedBefore)->toContain('(9%)');

    // 2. Direct DB update of GstRate (simulating rate change bypass or legacy edit)
    DB::table('gst_rates')
        ->where('id', $this->gst18->id)
        ->update(['rate' => '28.00']);

    // 3. Re-render PDF of sent invoice: must still show frozen 18% (9% CGST and 9% SGST), not 28% (14%)
    $renderedAfter = view('invoices.pdf', [
        'invoice' => $sentInvoice->fresh(['items.gstRate', 'client', 'payments']),
        'company' => $this->company,
        'safeLogoPath' => null,
    ])->render();

    expect($renderedAfter)->toContain('(9%)')
        ->and($renderedAfter)->not->toContain('(14%)');

    // 4. Also test inter-state invoice (e.g. Maharashtra 27)
    $interStateClient = Client::factory()->create([
        'assigned_to' => $this->sales->id,
        'state' => '27',
    ]);
    $interDraft = $this->draftService->createDraft(
        ['client_id' => $interStateClient->id, 'place_of_supply' => '27'],
        [['description' => 'Out of State Service', 'quantity' => 1, 'rate' => 10000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales
    );
    // Directly set gst_rate_percent to 18.00 to verify inter-state rendering
    $sentInter = $this->sendService->send($interDraft, '2026-06-15');
    $renderedInter = view('invoices.pdf', [
        'invoice' => $sentInter->fresh(['items.gstRate', 'client', 'payments']),
        'company' => $this->company,
        'safeLogoPath' => null,
    ])->render();
    expect($renderedInter)->toContain('(28%)'); // Since it was created after DB update to 28%
});

test('GstRate rate percentage is immutable once referenced by invoice items', function () {
    // Create draft using gst18
    $draft = $this->draftService->createDraft(
        ['client_id' => $this->client->id],
        [['description' => 'Consulting', 'quantity' => 1, 'rate' => 5000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales
    );

    // Editing rate percentage on GstRate model must throw DomainException
    expect(function () {
        $rate = GstRate::find($this->gst18->id);
        $rate->rate = '20.00';
        $rate->save();
    })->toThrow(DomainException::class);

    // Deleting GstRate referenced by invoice items must throw DomainException
    expect(function () {
        $rate = GstRate::find($this->gst18->id);
        $rate->delete();
    })->toThrow(DomainException::class);

    // Editing label or is_active is allowed
    $rate = GstRate::find($this->gst18->id);
    $rate->label = '18% GST (Standard)';
    $rate->is_active = false;
    $rate->save();
    expect($rate->fresh()->label)->toBe('18% GST (Standard)')
        ->and($rate->fresh()->is_active)->toBeFalse();
});
