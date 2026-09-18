<?php

use App\Models\CompanySetting;
use App\Models\FinancialYearCounter;
use App\Models\GstRate;

test('gst rates can be created and scoped by active flag', function () {
    GstRate::query()->delete();

    $active18 = GstRate::create(['rate' => '18.00', 'label' => '18% GST', 'is_active' => true]);
    $inactive12 = GstRate::create(['rate' => '12.00', 'label' => '12% GST (Old)', 'is_active' => false]);

    expect(GstRate::active()->count())->toBe(1)
        ->and(GstRate::active()->first()->id)->toBe($active18->id)
        ->and(GstRate::count())->toBe(2);
});

test('company settings record stores and resolves supplier state', function () {
    CompanySetting::query()->delete();

    $setting = CompanySetting::create([
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
    ]);

    expect(CompanySetting::current()->id)->toBe($setting->id)
        ->and(CompanySetting::current()->state_code)->toBe('08')
        ->and(CompanySetting::current()->getIndianState()?->code())->toBe('08');
});

test('financial year counter stores and increments sequence per year', function () {
    FinancialYearCounter::query()->delete();

    $counter26 = FinancialYearCounter::create([
        'financial_year' => '2026-27',
        'last_sequence' => 5,
    ]);

    $counter25 = FinancialYearCounter::create([
        'financial_year' => '2025-26',
        'last_sequence' => 12,
    ]);

    expect($counter26->last_sequence)->toBe(5)
        ->and($counter25->last_sequence)->toBe(12);

    $counter26->increment('last_sequence');
    expect($counter26->fresh()->last_sequence)->toBe(6)
        ->and($counter25->fresh()->last_sequence)->toBe(12);
});
