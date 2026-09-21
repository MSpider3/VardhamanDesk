<?php

use App\Enums\UserRole;
use App\Models\CompanySetting;
use App\Models\GstRate;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;

test('app:bootstrap creates admin, gst rates, and company settings on empty database and is safe in production', function () {
    app()->detectEnvironment(fn () => 'production');
    expect(app()->isProduction())->toBeTrue();

    // Database is empty
    expect(User::count())->toBe(0);
    expect(GstRate::count())->toBe(0);
    expect(CompanySetting::count())->toBe(0);

    // Run app:bootstrap command
    $this->artisan('app:bootstrap')
        ->assertSuccessful();

    // Assert Admin created and can access Filament panel immediately
    $admin = User::where('role', UserRole::ADMIN)->first();
    expect($admin)->not->toBeNull();
    expect($admin->email_verified_at)->not->toBeNull();
    expect($admin->is_active)->toBeTrue();
    expect($admin->canAccessPanel(Filament::getCurrentOrDefaultPanel()))->toBeTrue();

    // Assert 4 GST rates exist
    expect(GstRate::count())->toBe(4);
    expect(GstRate::pluck('rate')->map(fn ($r) => (float) $r)->sort()->values()->all())
        ->toBe([0.0, 5.0, 18.0, 40.0]);

    // Assert Company Settings created
    expect(CompanySetting::count())->toBe(1);
    $setting = CompanySetting::first();
    expect($setting->state)->toBe('Rajasthan');
    expect($setting->state_code)->toBe('08');

    // Run again to verify idempotency (safe to re-run without duplicate rows or errors)
    $this->artisan('app:bootstrap')
        ->assertSuccessful();

    expect(User::where('role', UserRole::ADMIN)->count())->toBe(1);
    expect(GstRate::count())->toBe(4);
    expect(CompanySetting::count())->toBe(1);

    app()->detectEnvironment(fn () => 'testing');
});

test('GstRatePolicy gates access to Admin only', function () {
    $admin = User::factory()->admin()->create();
    $sales = User::factory()->sales()->create();
    $rate = GstRate::create(['rate' => '12.00', 'label' => '12% GST', 'is_active' => true]);

    expect(Gate::forUser($admin)->allows('viewAny', GstRate::class))->toBeTrue();
    expect(Gate::forUser($admin)->allows('create', GstRate::class))->toBeTrue();
    expect(Gate::forUser($admin)->allows('update', $rate))->toBeTrue();

    expect(Gate::forUser($sales)->allows('viewAny', GstRate::class))->toBeFalse();
    expect(Gate::forUser($sales)->allows('create', GstRate::class))->toBeFalse();
    expect(Gate::forUser($sales)->allows('update', $rate))->toBeFalse();
    expect(Gate::forUser($sales)->allows('delete', $rate))->toBeFalse();
});

test('CompanySettingPolicy allows Admin view and update but forbids create and delete', function () {
    $admin = User::factory()->admin()->create();
    $sales = User::factory()->sales()->create();
    $setting = CompanySetting::factory()->create();

    expect(Gate::forUser($admin)->allows('viewAny', CompanySetting::class))->toBeTrue();
    expect(Gate::forUser($admin)->allows('view', $setting))->toBeTrue();
    expect(Gate::forUser($admin)->allows('update', $setting))->toBeTrue();
    // Singleton disallows create and delete
    expect(Gate::forUser($admin)->allows('create', CompanySetting::class))->toBeFalse();
    expect(Gate::forUser($admin)->allows('delete', $setting))->toBeFalse();

    expect(Gate::forUser($sales)->allows('create', CompanySetting::class))->toBeFalse();
    expect(Gate::forUser($sales)->allows('delete', $setting))->toBeFalse();
});

test('Filament panel restricts GstRate and CompanySetting pages to Admin', function () {
    $admin = User::factory()->admin()->create();
    $sales = User::factory()->sales()->create();
    CompanySetting::factory()->create();

    // Admin access
    $this->actingAs($admin)->get('/admin/gst-rates')->assertSuccessful();
    $this->actingAs($admin)->get('/admin/company-settings')->assertSuccessful();

    // Sales access forbidden
    $this->actingAs($sales)->get('/admin/gst-rates')->assertForbidden();
    $this->actingAs($sales)->get('/admin/company-settings')->assertForbidden();
});
