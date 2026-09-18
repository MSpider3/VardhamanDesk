<?php

use App\Enums\IndianState;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Filament\Widgets\FinancialOverviewWidget;
use App\Filament\Widgets\OverdueFollowUpsWidget;
use App\Filament\Widgets\TodayFollowUpsWidget;
use App\Models\Client;
use App\Models\GstRate;
use App\Models\Invoice;
use App\Models\User;
use App\Services\RecordPayment;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->sales1 = User::factory()->sales()->create();
    $this->sales2 = User::factory()->sales()->create();

    $this->gst18 = GstRate::firstOrCreate(
        ['rate' => 18.00],
        ['code' => 'GST18', 'label' => 'Standard Rate', 'cgst_rate' => 9.00, 'sgst_rate' => 9.00, 'igst_rate' => 18.00, 'is_active' => true]
    );

    $this->client1 = Client::factory()->create([
        'name' => 'Client of Sales 1',
        'state' => IndianState::MAHARASHTRA,
        'assigned_to' => $this->sales1->id,
    ]);

    $this->client2 = Client::factory()->create([
        'name' => 'Client of Sales 2',
        'state' => IndianState::MAHARASHTRA,
        'assigned_to' => $this->sales2->id,
    ]);
});

test('financial overview widget displays zero values when no invoices or payments exist', function () {
    $this->actingAs($this->admin);

    Livewire::test(FinancialOverviewWidget::class)
        ->assertSuccessful()
        ->assertSee('Invoiced This Month')
        ->assertSee('Received This Month')
        ->assertSee('Outstanding Receivables')
        ->assertSee('₹0.00');
});

test('invoiced this month metric includes sent partially_paid and paid in current month but excludes drafts and prior months', function () {
    $this->actingAs($this->admin);

    $now = Carbon::now();
    $lastMonth = Carbon::now()->subMonth();

    // 1. Sent invoice in current month: 10,000
    Invoice::create([
        'client_id' => $this->client1->id,
        'invoice_number' => 'INV-TEST-001',
        'financial_year' => '2026-27',
        'sequence_number' => 1,
        'place_of_supply' => IndianState::MAHARASHTRA,
        'status' => InvoiceStatus::SENT,
        'invoice_date' => $now->toDateString(),
        'due_date' => $now->copy()->addDays(15)->toDateString(),
        'subtotal' => 10000.00,
        'cgst_amount' => 0.00,
        'sgst_amount' => 0.00,
        'igst_amount' => 0.00,
        'total' => 10000.00,
        'created_by' => $this->sales1->id,
    ]);

    // 2. Draft invoice in current month: 5,000 (must be excluded)
    Invoice::create([
        'client_id' => $this->client1->id,
        'place_of_supply' => IndianState::MAHARASHTRA,
        'status' => InvoiceStatus::DRAFT,
        'invoice_date' => $now->toDateString(),
        'due_date' => $now->copy()->addDays(15)->toDateString(),
        'subtotal' => 5000.00,
        'cgst_amount' => 0.00,
        'sgst_amount' => 0.00,
        'igst_amount' => 0.00,
        'total' => 5000.00,
        'created_by' => $this->sales1->id,
    ]);

    // 3. Sent invoice in prior month: 20,000 (must be excluded from this month's invoiced)
    Invoice::create([
        'client_id' => $this->client1->id,
        'invoice_number' => 'INV-TEST-002',
        'financial_year' => '2026-27',
        'sequence_number' => 2,
        'place_of_supply' => IndianState::MAHARASHTRA,
        'status' => InvoiceStatus::SENT,
        'invoice_date' => $lastMonth->toDateString(),
        'due_date' => $lastMonth->copy()->addDays(15)->toDateString(),
        'subtotal' => 20000.00,
        'cgst_amount' => 0.00,
        'sgst_amount' => 0.00,
        'igst_amount' => 0.00,
        'total' => 20000.00,
        'created_by' => $this->sales1->id,
    ]);

    Livewire::test(FinancialOverviewWidget::class)
        ->assertSuccessful()
        ->assertSee('₹10,000.00'); // Only the 10,000 sent invoice from this month
});

test('received this month metric uses payment date regardless of invoice date', function () {
    $now = Carbon::now();
    $lastMonth = Carbon::now()->subMonth();

    // Invoice created last month: 15,000
    $oldInvoice = Invoice::create([
        'client_id' => $this->client1->id,
        'invoice_number' => 'INV-TEST-003',
        'financial_year' => '2026-27',
        'sequence_number' => 3,
        'place_of_supply' => IndianState::MAHARASHTRA,
        'status' => InvoiceStatus::SENT,
        'invoice_date' => $lastMonth->toDateString(),
        'due_date' => $lastMonth->copy()->addDays(15)->toDateString(),
        'subtotal' => 15000.00,
        'cgst_amount' => 0.00,
        'sgst_amount' => 0.00,
        'igst_amount' => 0.00,
        'total' => 15000.00,
        'created_by' => $this->sales1->id,
    ]);

    // Payment 1 received in current month: 6,000
    app(RecordPayment::class)->execute(
        $oldInvoice,
        [
            'amount' => '6000.00',
            'method' => PaymentMethod::UPI,
            'payment_date' => $now->toDateString(),
            'reference_note' => 'UPI-NOW-01',
        ],
        $this->admin
    );

    // Payment 2 received in previous month: 4,000
    app(RecordPayment::class)->execute(
        $oldInvoice,
        [
            'amount' => '4000.00',
            'method' => PaymentMethod::BANK_TRANSFER,
            'payment_date' => $lastMonth->toDateString(),
            'reference_note' => 'NEFT-OLD-01',
        ],
        $this->admin
    );

    $this->actingAs($this->admin);

    Livewire::test(FinancialOverviewWidget::class)
        ->assertSuccessful()
        ->assertSee('₹6,000.00'); // Only the payment from this month
});

test('outstanding metric includes sent and partially paid balances but excludes drafts and paid', function () {
    $now = Carbon::now();

    // 1. Sent invoice with no payments: 8,000
    Invoice::create([
        'client_id' => $this->client1->id,
        'invoice_number' => 'INV-TEST-004',
        'financial_year' => '2026-27',
        'sequence_number' => 4,
        'place_of_supply' => IndianState::MAHARASHTRA,
        'status' => InvoiceStatus::SENT,
        'invoice_date' => $now->toDateString(),
        'due_date' => $now->copy()->addDays(15)->toDateString(),
        'subtotal' => 8000.00,
        'cgst_amount' => 0.00,
        'sgst_amount' => 0.00,
        'igst_amount' => 0.00,
        'total' => 8000.00,
        'created_by' => $this->sales1->id,
    ]);

    // 2. Partially paid invoice: 12,000 total with 5,000 paid (outstanding = 7,000)
    $partialInvoice = Invoice::create([
        'client_id' => $this->client1->id,
        'invoice_number' => 'INV-TEST-005',
        'financial_year' => '2026-27',
        'sequence_number' => 5,
        'place_of_supply' => IndianState::MAHARASHTRA,
        'status' => InvoiceStatus::SENT,
        'invoice_date' => $now->toDateString(),
        'due_date' => $now->copy()->addDays(15)->toDateString(),
        'subtotal' => 12000.00,
        'cgst_amount' => 0.00,
        'sgst_amount' => 0.00,
        'igst_amount' => 0.00,
        'total' => 12000.00,
        'created_by' => $this->sales1->id,
    ]);
    app(RecordPayment::class)->execute(
        $partialInvoice,
        [
            'amount' => '5000.00',
            'method' => PaymentMethod::CASH,
            'payment_date' => $now->toDateString(),
        ],
        $this->admin
    );

    // 3. Paid invoice: 10,000 total with 10,000 paid (outstanding = 0)
    $paidInvoice = Invoice::create([
        'client_id' => $this->client1->id,
        'invoice_number' => 'INV-TEST-006',
        'financial_year' => '2026-27',
        'sequence_number' => 6,
        'place_of_supply' => IndianState::MAHARASHTRA,
        'status' => InvoiceStatus::SENT,
        'invoice_date' => $now->toDateString(),
        'due_date' => $now->copy()->addDays(15)->toDateString(),
        'subtotal' => 10000.00,
        'cgst_amount' => 0.00,
        'sgst_amount' => 0.00,
        'igst_amount' => 0.00,
        'total' => 10000.00,
        'created_by' => $this->sales1->id,
    ]);
    app(RecordPayment::class)->execute(
        $paidInvoice,
        [
            'amount' => '10000.00',
            'method' => PaymentMethod::BANK_TRANSFER,
            'payment_date' => $now->toDateString(),
            'reference_note' => 'FULL-PAY',
        ],
        $this->admin
    );

    // 4. Draft invoice: 25,000 (must be excluded from outstanding)
    Invoice::create([
        'client_id' => $this->client1->id,
        'place_of_supply' => IndianState::MAHARASHTRA,
        'status' => InvoiceStatus::DRAFT,
        'invoice_date' => $now->toDateString(),
        'due_date' => $now->copy()->addDays(15)->toDateString(),
        'subtotal' => 25000.00,
        'cgst_amount' => 0.00,
        'sgst_amount' => 0.00,
        'igst_amount' => 0.00,
        'total' => 25000.00,
        'created_by' => $this->sales1->id,
    ]);

    // Total outstanding should be: 8,000 (sent) + 7,000 (partially paid balance) = 15,000.00
    $this->actingAs($this->admin);

    Livewire::test(FinancialOverviewWidget::class)
        ->assertSuccessful()
        ->assertSee('₹15,000.00');
});

test('admin sees company-wide financial totals while sales users see only their assigned clients', function () {
    $now = Carbon::now();

    // Client 1 (Sales 1): 20,000 invoice with 10,000 payment
    $inv1 = Invoice::create([
        'client_id' => $this->client1->id,
        'invoice_number' => 'INV-SALES1-01',
        'financial_year' => '2026-27',
        'sequence_number' => 7,
        'place_of_supply' => IndianState::MAHARASHTRA,
        'status' => InvoiceStatus::SENT,
        'invoice_date' => $now->toDateString(),
        'due_date' => $now->copy()->addDays(15)->toDateString(),
        'subtotal' => 20000.00,
        'cgst_amount' => 0.00,
        'sgst_amount' => 0.00,
        'igst_amount' => 0.00,
        'total' => 20000.00,
        'created_by' => $this->sales1->id,
    ]);
    app(RecordPayment::class)->execute(
        $inv1,
        [
            'amount' => '10000.00',
            'method' => PaymentMethod::BANK_TRANSFER,
            'payment_date' => $now->toDateString(),
            'reference_note' => 'REF-S1',
        ],
        $this->sales1
    );

    // Client 2 (Sales 2): 30,000 invoice with 5,000 payment
    $inv2 = Invoice::create([
        'client_id' => $this->client2->id,
        'invoice_number' => 'INV-SALES2-01',
        'financial_year' => '2026-27',
        'sequence_number' => 8,
        'place_of_supply' => IndianState::MAHARASHTRA,
        'status' => InvoiceStatus::SENT,
        'invoice_date' => $now->toDateString(),
        'due_date' => $now->copy()->addDays(15)->toDateString(),
        'subtotal' => 30000.00,
        'cgst_amount' => 0.00,
        'sgst_amount' => 0.00,
        'igst_amount' => 0.00,
        'total' => 30000.00,
        'created_by' => $this->sales2->id,
    ]);
    app(RecordPayment::class)->execute(
        $inv2,
        [
            'amount' => '5000.00',
            'method' => PaymentMethod::UPI,
            'payment_date' => $now->toDateString(),
            'reference_note' => 'REF-S2',
        ],
        $this->sales2
    );

    // Test Sales 1:
    // Invoiced: 20,000.00
    // Received: 10,000.00
    // Outstanding: 10,000.00
    $this->actingAs($this->sales1);
    Livewire::test(FinancialOverviewWidget::class)
        ->assertSuccessful()
        ->assertSee('₹20,000.00')
        ->assertSee('₹10,000.00')
        ->assertDontSee('₹50,000.00')
        ->assertDontSee('₹30,000.00');

    // Test Sales 2:
    // Invoiced: 30,000.00
    // Received: 5,000.00
    // Outstanding: 25,000.00
    $this->actingAs($this->sales2);
    Livewire::test(FinancialOverviewWidget::class)
        ->assertSuccessful()
        ->assertSee('₹30,000.00')
        ->assertSee('₹5,000.00')
        ->assertSee('₹25,000.00')
        ->assertDontSee('₹20,000.00');

    // Test Admin:
    // Invoiced: 50,000.00
    // Received: 15,000.00
    // Outstanding: 35,000.00
    $this->actingAs($this->admin);
    Livewire::test(FinancialOverviewWidget::class)
        ->assertSuccessful()
        ->assertSee('₹50,000.00')
        ->assertSee('₹15,000.00')
        ->assertSee('₹35,000.00');
});

test('widget sorting places financial overview before overdue and today follow-ups', function () {
    $financialRefl = new ReflectionClass(FinancialOverviewWidget::class);
    $financialSort = $financialRefl->getStaticPropertyValue('sort');

    $overdueRefl = new ReflectionClass(OverdueFollowUpsWidget::class);
    $overdueSort = $overdueRefl->getStaticPropertyValue('sort');

    $todayRefl = new ReflectionClass(TodayFollowUpsWidget::class);
    $todaySort = $todayRefl->getStaticPropertyValue('sort');

    expect($financialSort)->toBeLessThan($overdueSort)
        ->and($overdueSort)->toBeLessThan($todaySort);
});
