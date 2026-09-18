<?php

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\CompanySetting;
use App\Models\GstRate;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('database seeder produces complete realistic demo dataset for all milestones', function () {
    $this->seed(DatabaseSeeder::class);

    // 1. Users
    expect(User::where('role', UserRole::ADMIN)->count())->toBeGreaterThanOrEqual(1)
        ->and(User::where('role', UserRole::SALES)->count())->toBeGreaterThanOrEqual(3);

    // 2. Leads & Follow-ups
    expect(Lead::withoutGlobalScopes()->count())->toBeGreaterThanOrEqual(15)
        ->and(Lead::withoutGlobalScopes()->whereNotNull('next_follow_up_date')->count())->toBeGreaterThanOrEqual(5);

    // 3. GST Rates & Company Settings
    expect(GstRate::count())->toBe(4)
        ->and(CompanySetting::count())->toBe(1);

    // 4. Clients
    expect(Client::withoutGlobalScopes()->count())->toBeGreaterThanOrEqual(4);

    // 5. Invoices across statuses
    $invoices = Invoice::withoutGlobalScopes()->get();
    expect($invoices->where('status', InvoiceStatus::DRAFT)->count())->toBeGreaterThanOrEqual(1)
        ->and($invoices->where('status', InvoiceStatus::SENT)->count())->toBeGreaterThanOrEqual(1)
        ->and($invoices->where('status', InvoiceStatus::PARTIALLY_PAID)->count())->toBeGreaterThanOrEqual(1)
        ->and($invoices->where('status', InvoiceStatus::PAID)->count())->toBeGreaterThanOrEqual(1);

    // 6. Sent invoice with no payments
    $sentUnpaid = $invoices->firstWhere('status', InvoiceStatus::SENT);
    expect($sentUnpaid)->not->toBeNull()
        ->and($sentUnpaid->paid_amount)->toBe('0.00')
        ->and($sentUnpaid->outstanding_amount)->toBe($sentUnpaid->total);

    // 7. Partially paid invoice with valid partial payment
    $partiallyPaid = $invoices->firstWhere('status', InvoiceStatus::PARTIALLY_PAID);
    expect($partiallyPaid)->not->toBeNull()
        ->and(bccomp($partiallyPaid->paid_amount, '0.00', 2))->toBeGreaterThan(0)
        ->and(bccomp($partiallyPaid->paid_amount, $partiallyPaid->total, 2))->toBeLessThan(0)
        ->and(bccomp($partiallyPaid->outstanding_amount, '0.00', 2))->toBeGreaterThan(0);

    // 8. Paid invoice with exact total paid
    $paid = $invoices->firstWhere('status', InvoiceStatus::PAID);
    expect($paid)->not->toBeNull()
        ->and($paid->paid_amount)->toBe($paid->total)
        ->and($paid->outstanding_amount)->toBe('0.00');

    // 9. Payments across months
    $payments = Payment::withoutGlobalScopes()->get();
    expect($payments->count())->toBeGreaterThanOrEqual(3);

    // Payment methods coverage
    $methodsUsed = $payments->pluck('method')->unique()->values()->all();
    expect($methodsUsed)->toContain(PaymentMethod::UPI)
        ->and($methodsUsed)->toContain(PaymentMethod::BANK_TRANSFER)
        ->and($methodsUsed)->toContain(PaymentMethod::CHEQUE);
});
