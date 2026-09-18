<?php

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Client;
use App\Models\CompanySetting;
use App\Models\GstRate;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\InvoiceCalculationService;
use App\Services\InvoiceDraftService;
use App\Services\InvoiceSendService;
use App\Services\RecordPayment;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['email' => 'admin_pay@vardhaman.local']);
    $this->sales1 = User::factory()->sales()->create(['email' => 'sales1_pay@vardhaman.local']);

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

    $calcService = new InvoiceCalculationService;
    $draftService = new InvoiceDraftService($calcService);
    $sendService = new InvoiceSendService($calcService);

    $this->client = Client::factory()->create([
        'assigned_to' => $this->sales1->id,
        'state' => '08',
    ]);

    // Create a sent invoice with total = 11,800.00 (10,000 + 1,800 GST)
    $draft = $draftService->createDraft(
        ['client_id' => $this->client->id],
        [['description' => 'Test Service', 'quantity' => 1, 'rate' => 10000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales1
    );

    $this->sentInvoice = $sendService->send($draft, '2026-06-01');
    $this->recordPaymentService = new RecordPayment;
});

test('partial payment against sent invoice creates ledger entry and moves status to partially_paid', function () {
    expect($this->sentInvoice->status)->toBe(InvoiceStatus::SENT)
        ->and($this->sentInvoice->total)->toBe('11800.00');

    $payment = $this->recordPaymentService->execute(
        $this->sentInvoice,
        [
            'amount' => '5000.00',
            'method' => PaymentMethod::BANK_TRANSFER,
            'payment_date' => '2026-06-15',
            'reference_note' => 'UTR987654321',
        ],
        $this->sales1
    );

    expect($payment->amount)->toBe('5000.00')
        ->and($payment->method)->toBe(PaymentMethod::BANK_TRANSFER)
        ->and($payment->recorded_by)->toBe($this->sales1->id)
        ->and($this->sentInvoice->fresh()->status)->toBe(InvoiceStatus::PARTIALLY_PAID)
        ->and($this->sentInvoice->fresh()->paid_amount)->toBe('5000.00')
        ->and($this->sentInvoice->fresh()->outstanding_amount)->toBe('6800.00');
});

test('second partial payment totaling exact invoice value moves status to paid', function () {
    // First partial payment: 5,000
    $this->recordPaymentService->execute(
        $this->sentInvoice,
        ['amount' => '5000.00', 'method' => PaymentMethod::UPI],
        $this->sales1
    );

    // Second payment: remaining 6,800
    $secondPayment = $this->recordPaymentService->execute(
        $this->sentInvoice,
        ['amount' => '6800.00', 'method' => PaymentMethod::CHEQUE],
        $this->sales1
    );

    expect($secondPayment->amount)->toBe('6800.00')
        ->and($this->sentInvoice->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($this->sentInvoice->fresh()->paid_amount)->toBe('11800.00')
        ->and($this->sentInvoice->fresh()->outstanding_amount)->toBe('0.00');
});

test('single payment for full amount moves status directly to paid', function () {
    $this->recordPaymentService->execute(
        $this->sentInvoice,
        ['amount' => '11800.00', 'method' => PaymentMethod::BANK_TRANSFER],
        $this->sales1
    );

    expect($this->sentInvoice->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($this->sentInvoice->fresh()->outstanding_amount)->toBe('0.00');
});

test('overpayment is rejected outright without recording payment or mutating balance', function () {
    // Total is 11,800; attempt to record 12,000
    expect(fn () => $this->recordPaymentService->execute(
        $this->sentInvoice,
        ['amount' => '12000.00', 'method' => PaymentMethod::BANK_TRANSFER],
        $this->sales1
    ))->toThrow(ValidationException::class);

    // Verify nothing recorded and status unchanged
    expect(Payment::where('invoice_id', $this->sentInvoice->id)->count())->toBe(0)
        ->and($this->sentInvoice->fresh()->status)->toBe(InvoiceStatus::SENT)
        ->and($this->sentInvoice->fresh()->outstanding_amount)->toBe('11800.00');
});

test('cumulative overpayment after partial payment is rejected', function () {
    // Pay 10,000 first (remaining is 1,800)
    $this->recordPaymentService->execute(
        $this->sentInvoice,
        ['amount' => '10000.00', 'method' => PaymentMethod::BANK_TRANSFER],
        $this->sales1
    );

    // Attempt to pay 2,000 when remaining is 1,800
    expect(fn () => $this->recordPaymentService->execute(
        $this->sentInvoice,
        ['amount' => '2000.00', 'method' => PaymentMethod::BANK_TRANSFER],
        $this->sales1
    ))->toThrow(ValidationException::class);

    expect(Payment::where('invoice_id', $this->sentInvoice->id)->count())->toBe(1)
        ->and($this->sentInvoice->fresh()->status)->toBe(InvoiceStatus::PARTIALLY_PAID)
        ->and($this->sentInvoice->fresh()->outstanding_amount)->toBe('1800.00');
});

test('zero or negative payment amount is rejected', function () {
    expect(fn () => $this->recordPaymentService->execute(
        $this->sentInvoice,
        ['amount' => '0.00'],
        $this->sales1
    ))->toThrow(ValidationException::class);

    expect(fn () => $this->recordPaymentService->execute(
        $this->sentInvoice,
        ['amount' => '-500.00'],
        $this->sales1
    ))->toThrow(ValidationException::class);
});

test('payment cannot be recorded against draft invoice', function () {
    $calcService = new InvoiceCalculationService;
    $draftService = new InvoiceDraftService($calcService);
    $draft = $draftService->createDraft(['client_id' => $this->client->id], [], $this->sales1);

    expect(fn () => $this->recordPaymentService->execute(
        $draft,
        ['amount' => '500.00'],
        $this->sales1
    ))->toThrow(DomainException::class, 'Cannot record payments against a draft invoice');
});

test('payment cannot be recorded against fully paid invoice', function () {
    $this->recordPaymentService->execute(
        $this->sentInvoice,
        ['amount' => '11800.00'],
        $this->sales1
    );

    expect($this->sentInvoice->fresh()->status)->toBe(InvoiceStatus::PAID);

    expect(fn () => $this->recordPaymentService->execute(
        $this->sentInvoice,
        ['amount' => '100.00'],
        $this->sales1
    ))->toThrow(DomainException::class, 'This invoice is already fully paid');
});

test('payments are immutable and cannot be updated or deleted at model layer', function () {
    $payment = $this->recordPaymentService->execute(
        $this->sentInvoice,
        ['amount' => '1000.00'],
        $this->sales1
    );

    expect(fn () => $payment->update(['amount' => '2000.00']))->toThrow(DomainException::class);
    expect(fn () => $payment->delete())->toThrow(DomainException::class);
});
