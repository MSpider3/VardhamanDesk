<?php

use App\Enums\IndianState;
use App\Enums\InvoiceStatus;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\PaymentMethod;
use App\Models\Client;
use App\Models\CompanySetting;
use App\Models\GstRate;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\User;
use App\Services\ClientService;
use App\Services\InvoiceCalculationService;
use App\Services\InvoiceDraftService;
use App\Services\InvoicePdfService;
use App\Services\InvoiceSendService;
use App\Services\RecordPayment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->sales1 = User::factory()->sales()->create();
    $this->sales2 = User::factory()->sales()->create();

    $this->gst18 = GstRate::firstOrCreate(
        ['rate' => 18.00],
        ['code' => 'GST18', 'label' => 'Standard Rate', 'cgst_rate' => 9.00, 'sgst_rate' => 9.00, 'igst_rate' => 18.00, 'is_active' => true]
    );

    $this->gst5 = GstRate::firstOrCreate(
        ['rate' => 5.00],
        ['code' => 'GST5', 'label' => 'Reduced Rate', 'cgst_rate' => 2.50, 'sgst_rate' => 2.50, 'igst_rate' => 5.00, 'is_active' => true]
    );

    CompanySetting::firstOrCreate(
        ['id' => 1],
        CompanySetting::factory()->raw()
    );
});

test('complete 30-minute lifecycle flow: Lead -> Qualify -> Convert -> Draft Invoice -> Send -> Partial Pay -> Full Pay -> PDF', function () {
    // 1. Create Lead for Sales 1
    $lead = Lead::create([
        'name' => 'Demo Prospect',
        'company' => 'Demo Enterprises',
        'email' => 'prospect@demo.local',
        'phone' => '9829011111',
        'source' => LeadSource::REFERRAL,
        'status' => LeadStatus::NEW,
        'assigned_to' => $this->sales1->id,
        'created_by' => $this->sales1->id,
    ]);

    // 2. Add Note and Qualify
    $lead->notes()->create([
        'created_by' => $this->sales1->id,
        'note' => 'Qualified during discovery meeting.',
        'follow_up_date' => now()->addDays(2)->toDateString(),
    ]);
    $lead->update(['status' => LeadStatus::QUALIFIED]);

    // 3. Convert Lead to Client
    $clientService = app(ClientService::class);
    $client = $clientService->convertLeadToClient($lead, [
        'billing_address' => '123 MI Road, Jaipur, Rajasthan',
        'state' => IndianState::RAJASTHAN->value,
        'gstin' => '08AAACD1234E1Z5',
    ], $this->sales1);

    expect($lead->fresh()->status)->toBe(LeadStatus::CONVERTED)
        ->and($client->assigned_to)->toBe($this->sales1->id)
        ->and($client->lead_id)->toBe($lead->id);

    // 4. Create Draft Invoice with Mixed GST Rates (18% and 5%)
    // Line 1: 10,000 @ 18% = 1,800 tax (CGST 900, SGST 900)
    // Line 2: 2,000 @ 5% = 100 tax (CGST 50, SGST 50)
    // Subtotal: 12,000.00, Tax: 1,900.00, Total: 13,900.00
    $calcService = new InvoiceCalculationService;
    $draftService = new InvoiceDraftService($calcService);
    $sendService = new InvoiceSendService($calcService);

    $draft = $draftService->createDraft(
        [
            'client_id' => $client->id,
            'place_of_supply' => IndianState::RAJASTHAN->value,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ],
        [
            [
                'description' => 'Software Architecture Consulting',
                'sac_code' => '998313',
                'quantity' => 1,
                'rate' => 10000.00,
                'gst_rate_id' => $this->gst18->id,
            ],
            [
                'description' => 'User Manual Documentation',
                'sac_code' => '998319',
                'quantity' => 1,
                'rate' => 2000.00,
                'gst_rate_id' => $this->gst5->id,
            ],
        ],
        $this->sales1
    );

    expect($draft->status)->toBe(InvoiceStatus::DRAFT)
        ->and($draft->display_number)->toBe('DRAFT')
        ->and($draft->subtotal)->toBe('12000.00')
        ->and($draft->cgst_amount)->toBe('950.00')
        ->and($draft->sgst_amount)->toBe('950.00')
        ->and($draft->igst_amount)->toBe('0.00')
        ->and($draft->total)->toBe('13900.00');

    // 5. Send Invoice -> Allocate Number & Lock
    $sent = $sendService->send($draft, now()->toDateString());
    expect($sent->status)->toBe(InvoiceStatus::SENT)
        ->and($sent->invoice_number)->not->toBeNull()
        ->and($sent->paid_amount)->toBe('0.00')
        ->and($sent->outstanding_amount)->toBe('13900.00');

    // Immutability: deletion and financial edits blocked
    expect(fn () => $sent->delete())->toThrow(DomainException::class);
    expect(fn () => $sent->update(['total' => '20000.00']))->toThrow(DomainException::class);

    // 6. Record Partial Payment (5,000.00)
    $recordPayment = app(RecordPayment::class);
    $payment1 = $recordPayment->execute(
        $sent,
        [
            'amount' => '5000.00',
            'method' => PaymentMethod::UPI,
            'payment_date' => now()->toDateString(),
            'reference_note' => 'UPI-REF-001',
        ],
        $this->sales1
    );

    expect($sent->fresh()->status)->toBe(InvoiceStatus::PARTIALLY_PAID)
        ->and($sent->fresh()->paid_amount)->toBe('5000.00')
        ->and($sent->fresh()->outstanding_amount)->toBe('8900.00');

    // 7. Attempt Overpayment (9,000.00 exceeds 8,900.00 balance) -> rejected
    expect(fn () => $recordPayment->execute(
        $sent->fresh(),
        [
            'amount' => '9000.00',
            'method' => PaymentMethod::BANK_TRANSFER,
            'payment_date' => now()->toDateString(),
        ],
        $this->sales1
    ))->toThrow(ValidationException::class);

    // State unchanged after rejected overpayment
    expect($sent->fresh()->status)->toBe(InvoiceStatus::PARTIALLY_PAID)
        ->and($sent->fresh()->paid_amount)->toBe('5000.00')
        ->and($sent->fresh()->outstanding_amount)->toBe('8900.00');

    // 8. Record Final Payment (8,900.00 exact remaining balance)
    $payment2 = $recordPayment->execute(
        $sent->fresh(),
        [
            'amount' => '8900.00',
            'method' => PaymentMethod::BANK_TRANSFER,
            'payment_date' => now()->toDateString(),
            'reference_note' => 'NEFT-REF-002',
        ],
        $this->sales1
    );

    expect($sent->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($sent->fresh()->paid_amount)->toBe('13900.00')
        ->and($sent->fresh()->outstanding_amount)->toBe('0.00');

    // Payment immutability
    expect(fn () => $payment1->delete())->toThrow(DomainException::class);
    expect(fn () => $payment1->update(['amount' => '6000.00']))->toThrow(DomainException::class);

    // 9. Generate and verify PDF
    $pdfService = app(InvoicePdfService::class);
    $pdfOutput = $pdfService->generate($sent->fresh())->output();
    expect($pdfOutput)->toBeString()
        ->and(strlen($pdfOutput))->toBeGreaterThan(1000)
        ->and(str_starts_with($pdfOutput, '%PDF'))->toBeTrue();
});

test('cross-owner access is strictly enforced for leads, clients, invoices, payments, and reassignment works seamlessly', function () {
    // Sales 1 creates lead and converts to client
    $lead = Lead::factory()->create([
        'assigned_to' => $this->sales1->id,
        'status' => LeadStatus::QUALIFIED,
    ]);
    $client = app(ClientService::class)->convertLeadToClient($lead, [
        'billing_address' => 'Station Road, Jaipur',
        'state' => IndianState::RAJASTHAN->value,
    ], $this->sales1);

    // Create and send invoice for Sales 1
    $calcService = new InvoiceCalculationService;
    $draftService = new InvoiceDraftService($calcService);
    $sendService = new InvoiceSendService($calcService);

    $draft = $draftService->createDraft(
        [
            'client_id' => $client->id,
            'place_of_supply' => IndianState::RAJASTHAN->value,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ],
        [[
            'description' => 'Consulting',
            'sac_code' => '998313',
            'quantity' => 1,
            'rate' => 10000.00,
            'gst_rate_id' => $this->gst18->id,
        ]],
        $this->sales1
    );
    $sent = $sendService->send($draft, now()->toDateString());

    // Record payment by Sales 1
    $payment = app(RecordPayment::class)->execute(
        $sent,
        [
            'amount' => '5000.00',
            'method' => PaymentMethod::CASH,
            'payment_date' => now()->toDateString(),
        ],
        $this->sales1
    );

    // Sales 2 cannot see Sales 1's lead, client, invoice, or payment via queries
    $this->actingAs($this->sales2);
    expect(Lead::find($lead->id))->toBeNull()
        ->and(Client::find($client->id))->toBeNull()
        ->and(Invoice::find($sent->id))->toBeNull()
        ->and(Payment::find($payment->id))->toBeNull();

    // Sales 2 cannot access policies
    expect(Gate::forUser($this->sales2)->allows('view', $lead))->toBeFalse()
        ->and(Gate::forUser($this->sales2)->allows('view', $client))->toBeFalse()
        ->and(Gate::forUser($this->sales2)->allows('view', $sent))->toBeFalse()
        ->and(Gate::forUser($this->sales2)->allows('view', $payment))->toBeFalse()
        ->and(Gate::forUser($this->sales2)->allows('recordPayment', $sent))->toBeFalse();

    // Admin reassigns client to Sales 2
    $this->actingAs($this->admin);
    $client->update(['assigned_to' => $this->sales2->id]);

    // Now Sales 2 CAN see client, invoice, payment, and record payment
    $this->actingAs($this->sales2);
    expect(Client::find($client->id))->not->toBeNull()
        ->and(Invoice::find($sent->id))->not->toBeNull()
        ->and(Payment::find($payment->id))->not->toBeNull();

    expect(Gate::forUser($this->sales2)->allows('view', $client->fresh()))->toBeTrue()
        ->and(Gate::forUser($this->sales2)->allows('view', $sent->fresh()))->toBeTrue()
        ->and(Gate::forUser($this->sales2)->allows('view', $payment->fresh()))->toBeTrue()
        ->and(Gate::forUser($this->sales2)->allows('recordPayment', $sent->fresh()))->toBeTrue();

    // And Sales 1 can no longer access the reassigned client/invoice/payment
    $this->actingAs($this->sales1);
    expect(Client::find($client->id))->toBeNull()
        ->and(Invoice::find($sent->id))->toBeNull()
        ->and(Payment::find($payment->id))->toBeNull();
});
