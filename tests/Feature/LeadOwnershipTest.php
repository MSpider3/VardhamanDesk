<?php

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['email' => 'admin_test@vardhaman.local']);
    $this->sales1 = User::factory()->sales()->create(['email' => 'sales1_test@vardhaman.local']);
    $this->sales2 = User::factory()->sales()->create(['email' => 'sales2_test@vardhaman.local']);

    $this->lead1 = Lead::factory()->create([
        'name' => 'Lead One',
        'assigned_to' => $this->sales1->id,
        'status' => LeadStatus::NEW,
    ]);

    $this->lead2 = Lead::factory()->create([
        'name' => 'Lead Two',
        'assigned_to' => $this->sales2->id,
        'status' => LeadStatus::NEW,
    ]);
});

test('admin has global access to all leads in queries', function () {
    $this->actingAs($this->admin);

    $leads = Lead::all();
    expect($leads)->toHaveCount(2)
        ->and($leads->pluck('id'))->toContain($this->lead1->id, $this->lead2->id);
});

test('sales user query is scoped only to leads they own', function () {
    $this->actingAs($this->sales1);

    $leads = Lead::all();
    expect($leads)->toHaveCount(1)
        ->and($leads->first()->id)->toBe($this->lead1->id);

    $this->actingAs($this->sales2);

    $leads2 = Lead::all();
    expect($leads2)->toHaveCount(1)
        ->and($leads2->first()->id)->toBe($this->lead2->id);
});

test('sales user cannot view another sales user lead via policy', function () {
    expect(Gate::forUser($this->sales1)->allows('view', $this->lead1))->toBeTrue();
    expect(Gate::forUser($this->sales1)->allows('view', $this->lead2))->toBeFalse();
});

test('sales user cannot update another sales user lead via policy', function () {
    expect(Gate::forUser($this->sales1)->allows('update', $this->lead1))->toBeTrue();
    expect(Gate::forUser($this->sales1)->allows('update', $this->lead2))->toBeFalse();
});

test('sales user cannot delete another sales user lead via policy', function () {
    expect(Gate::forUser($this->sales1)->allows('delete', $this->lead1))->toBeTrue();
    expect(Gate::forUser($this->sales1)->allows('delete', $this->lead2))->toBeFalse();
});

test('sales user cannot reassign lead', function () {
    expect(Gate::forUser($this->sales1)->allows('reassign', $this->lead1))->toBeFalse();
    expect(Gate::forUser($this->sales1)->allows('reassign', $this->lead2))->toBeFalse();

    $this->actingAs($this->sales1);
    expect(fn () => (new LeadService)->assign($this->lead1, $this->sales2, $this->sales1))
        ->toThrow(AuthorizationException::class);
});

test('admin can reassign lead to any sales user', function () {
    expect(Gate::forUser($this->admin)->allows('reassign', $this->lead1))->toBeTrue();

    $this->actingAs($this->admin);
    $service = new LeadService;
    $service->assign($this->lead1, $this->sales2, $this->admin);

    expect($this->lead1->fresh()->assigned_to)->toBe($this->sales2->id);
});

test('sales user creating lead is automatically assigned as owner', function () {
    $this->actingAs($this->sales1);

    $lead = Lead::create([
        'name' => 'Auto Assigned Lead',
        'source' => LeadSource::WEBSITE,
        'status' => LeadStatus::NEW,
    ]);

    expect($lead->assigned_to)->toBe($this->sales1->id);
});

test('sales user accessing another sales user lead edit page in filament is forbidden', function () {
    $this->actingAs($this->sales1)
        ->get("/admin/leads/{$this->lead2->id}/edit")
        ->assertNotFound(); // Scoped out by global scope => 404
});
