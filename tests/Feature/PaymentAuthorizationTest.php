<?php

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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['email' => 'admin_auth_pay@vardhaman.local']);
    $this->sales1 = User::factory()->sales()->create(['email' => 'sales1_auth_pay@vardhaman.local']);
    $this->sales2 = User::factory()->sales()->create(['email' => 'sales2_auth_pay@vardhaman.local']);

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

    $this->clientSales1 = Client::factory()->create(['assigned_to' => $this->sales1->id, 'state' => '08']);
    $this->clientSales2 = Client::factory()->create(['assigned_to' => $this->sales2->id, 'state' => '08']);

    // Invoice for Sales 1
    $draft1 = $draftService->createDraft(
        ['client_id' => $this->clientSales1->id],
        [['description' => 'Service 1', 'quantity' => 1, 'rate' => 5000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales1
    );
    $this->invoiceSales1 = $sendService->send($draft1, '2026-06-01');

    // Invoice for Sales 2
    $draft2 = $draftService->createDraft(
        ['client_id' => $this->clientSales2->id],
        [['description' => 'Service 2', 'quantity' => 1, 'rate' => 5000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales2
    );
    $this->invoiceSales2 = $sendService->send($draft2, '2026-06-01');

    $this->recordPayment = new RecordPayment;

    // Record a payment on invoice 1 (by Sales 1)
    $this->payment1 = $this->recordPayment->execute(
        $this->invoiceSales1,
        ['amount' => '1000.00', 'method' => PaymentMethod::BANK_TRANSFER],
        $this->sales1
    );

    // Record a payment on invoice 2 (by Sales 2)
    $this->payment2 = $this->recordPayment->execute(
        $this->invoiceSales2,
        ['amount' => '2000.00', 'method' => PaymentMethod::UPI],
        $this->sales2
    );
});

test('admin can see all payments across all clients', function () {
    $this->actingAs($this->admin);

    $payments = Payment::all();
    expect($payments)->toHaveCount(2)
        ->and($payments->pluck('id'))->toContain($this->payment1->id, $this->payment2->id);
});

test('sales user query scope isolates to payments for their own clients only', function () {
    $this->actingAs($this->sales1);

    $payments = Payment::all();
    expect($payments)->toHaveCount(1)
        ->and($payments->first()->id)->toBe($this->payment1->id);

    $this->actingAs($this->sales2);
    $payments2 = Payment::all();
    expect($payments2)->toHaveCount(1)
        ->and($payments2->first()->id)->toBe($this->payment2->id);
});

test('sales user cannot record payment on an invoice belonging to another sales user client', function () {
    expect(fn () => $this->recordPayment->execute(
        $this->invoiceSales2,
        ['amount' => '500.00'],
        $this->sales1
    ))->toThrow(AuthorizationException::class);
});

test('sales user cannot view another sales user payment via policy', function () {
    expect(Gate::forUser($this->sales1)->allows('view', $this->payment1))->toBeTrue();
    expect(Gate::forUser($this->sales1)->allows('view', $this->payment2))->toBeFalse();
});

test('payment cannot be updated or deleted by anyone including admin via policy', function () {
    expect(Gate::forUser($this->sales1)->allows('update', $this->payment1))->toBeFalse();
    expect(Gate::forUser($this->admin)->allows('update', $this->payment1))->toBeFalse();

    expect(Gate::forUser($this->sales1)->allows('delete', $this->payment1))->toBeFalse();
    expect(Gate::forUser($this->admin)->allows('delete', $this->payment1))->toBeFalse();
});

test('admin can record payment on any invoice', function () {
    $paymentAdmin = $this->recordPayment->execute(
        $this->invoiceSales2,
        ['amount' => '1000.00', 'method' => PaymentMethod::CASH],
        $this->admin
    );

    expect($paymentAdmin->recorded_by)->toBe($this->admin->id);
});

test('reference_note validation allows valid UPI IDs, gateway IDs, and bank notes while rejecting invalid characters', function () {
    $rules = ['reference_note' => ['nullable', 'string', 'regex:/^[A-Za-z0-9\/\-\s#.,:@_()]+$/']];

    expect(Validator::make(['reference_note' => 'upi_user@okhdfcbank'], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['reference_note' => 'pay_O8xY9z123'], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['reference_note' => 'NEFT (HDFC Bank)'], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['reference_note' => 'Chq #00124/2026'], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['reference_note' => '<script>alert(1)</script>'], $rules)->fails())->toBeTrue();
});
