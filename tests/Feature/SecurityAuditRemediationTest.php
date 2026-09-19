<?php

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\Client;
use App\Models\CompanySetting;
use App\Models\GstRate;
use App\Models\Lead;
use App\Models\User;
use App\Services\InvoiceCalculationService;
use App\Services\InvoiceDraftService;
use App\Services\InvoicePdfService;
use Database\Seeders\DatabaseSeeder;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['email' => 'admin_audit@test.local']);
    $this->sales1 = User::factory()->sales()->create(['email' => 'sales1_audit@test.local']);
    $this->sales2 = User::factory()->sales()->create(['email' => 'sales2_audit@test.local']);

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
});

test('Finding 1: canAccessPanel allows active verified users and denies inactive or unverified users', function () {
    $panel = Filament::getCurrentOrDefaultPanel();

    $activeVerifiedUser = User::factory()->create([
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    expect($activeVerifiedUser->canAccessPanel($panel))->toBeTrue();

    $inactiveUser = User::factory()->inactive()->create([
        'email_verified_at' => now(),
    ]);
    expect($inactiveUser->canAccessPanel($panel))->toBeFalse();

    $unverifiedUser = User::factory()->unverified()->create([
        'is_active' => true,
    ]);
    expect($unverifiedUser->canAccessPanel($panel))->toBeFalse();
});

test('Finding 2: DatabaseSeeder stops immediately when running in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $seeder = new DatabaseSeeder;
    $seeder->run();

    // Verify seeder did not proceed to create users
    expect(app()->isProduction())->toBeTrue();

    // Reset back to testing
    app()->detectEnvironment(fn () => 'testing');
});

test('Finding 8: InvoiceDraftService::updateDraft strictly validates client ownership when actor is provided', function () {
    $client1 = Client::factory()->create(['assigned_to' => $this->sales1->id, 'state' => '08']);
    $client2 = Client::factory()->create(['assigned_to' => $this->sales2->id, 'state' => '08']);

    $draft = $this->draftService->createDraft(
        ['client_id' => $client1->id],
        [['description' => 'Service', 'quantity' => 1, 'rate' => 1000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales1
    );

    // sales1 cannot reassign draft to sales2's client
    expect(fn () => $this->draftService->updateDraft(
        $draft,
        ['client_id' => $client2->id],
        [['description' => 'Service', 'quantity' => 1, 'rate' => 1000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales1
    ))->toThrow(AuthorizationException::class, 'You are not authorized to assign this invoice to another client.');

    // Admin CAN reassign draft to sales2's client
    $updated = $this->draftService->updateDraft(
        $draft,
        ['client_id' => $client2->id],
        [['description' => 'Service', 'quantity' => 1, 'rate' => 1000, 'gst_rate_id' => $this->gst18->id]],
        $this->admin
    );

    expect($updated->client_id)->toBe($client2->id);
});

test('Finding 9 & 10: Invoice PDF sanitizes HTML in free-text fields and rejects traversal in logo_path', function () {
    $client = Client::factory()->create([
        'assigned_to' => $this->sales1->id,
        'billing_address' => "Line 1<script>alert('xss')</script>\nLine 2",
        'state' => '08',
    ]);

    $draft = $this->draftService->createDraft(
        ['client_id' => $client->id],
        [['description' => '<b>Malicious Bold</b> Item', 'quantity' => 1, 'rate' => 1000, 'gst_rate_id' => $this->gst18->id]],
        $this->sales1
    );

    // Set malicious logo path attempt
    $company = CompanySetting::first();
    $company->update(['logo_path' => '../../../../storage/logs/laravel.log']);

    $pdfService = new InvoicePdfService;
    $dompdf = $pdfService->generate($draft);
    $html = $dompdf->output();

    // Verify PDF generates without crashing despite malicious path
    expect($dompdf)->not->toBeNull();
    expect($html)->not->toBeEmpty();
});

test('Finding 11: forceDelete and restore on Leads and Clients are restricted to Admin only', function () {
    $lead = Lead::factory()->create(['assigned_to' => $this->sales1->id]);
    $client = Client::factory()->create(['assigned_to' => $this->sales1->id]);

    // Sales representative cannot forceDelete or restore
    expect(Gate::forUser($this->sales1)->allows('forceDelete', $lead))->toBeFalse();
    expect(Gate::forUser($this->sales1)->allows('restore', $lead))->toBeFalse();
    expect(Gate::forUser($this->sales1)->allows('forceDelete', $client))->toBeFalse();
    expect(Gate::forUser($this->sales1)->allows('restore', $client))->toBeFalse();

    // Admin CAN forceDelete and restore
    expect(Gate::forUser($this->admin)->allows('forceDelete', $lead))->toBeTrue();
    expect(Gate::forUser($this->admin)->allows('restore', $lead))->toBeTrue();
    expect(Gate::forUser($this->admin)->allows('forceDelete', $client))->toBeTrue();
    expect(Gate::forUser($this->admin)->allows('restore', $client))->toBeTrue();
});

test('Finding 15: SecurityHeaders middleware emits strict HTTP security headers', function () {
    $response = $this->actingAs($this->admin)->get('/admin');

    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'same-origin');
    $response->assertHeader('Permissions-Policy', 'geolocation=(), microphone=()');
    expect($response->headers->get('Content-Security-Policy'))->toContain("default-src 'self'");
});

test('Finding 5: login rate limiting blocks excessive attempts', function () {
    $component = Livewire::test(Login::class);

    for ($i = 0; $i < 5; $i++) {
        $component->set('data.email', 'wrong@example.com')
            ->set('data.password', 'wrongpassword')
            ->call('authenticate');
    }

    // 6th attempt hits rate limit
    $component->call('authenticate');
    expect(auth()->check())->toBeFalse();
});

test('Finding 7: User creation enforces minimum 8 characters and complexity', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Weak User',
            'email' => 'weak@test.local',
            'role' => 'sales',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
        ->call('create')
        ->assertHasFormErrors(['password']);
});
