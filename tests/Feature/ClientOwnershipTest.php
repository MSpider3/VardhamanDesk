<?php

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['email' => 'admin_cl_owner@vardhaman.local']);
    $this->sales1 = User::factory()->sales()->create(['email' => 'sales1_cl_owner@vardhaman.local']);
    $this->sales2 = User::factory()->sales()->create(['email' => 'sales2_cl_owner@vardhaman.local']);

    $this->client1 = Client::factory()->create([
        'name' => 'Client One',
        'assigned_to' => $this->sales1->id,
    ]);

    $this->client2 = Client::factory()->create([
        'name' => 'Client Two',
        'assigned_to' => $this->sales2->id,
    ]);
});

test('admin can see all clients in queries', function () {
    $this->actingAs($this->admin);

    $clients = Client::all();
    expect($clients)->toHaveCount(2)
        ->and($clients->pluck('id'))->toContain($this->client1->id, $this->client2->id);
});

test('sales user can only see their own clients in queries', function () {
    $this->actingAs($this->sales1);

    $clients = Client::all();
    expect($clients)->toHaveCount(1)
        ->and($clients->first()->id)->toBe($this->client1->id);

    $this->actingAs($this->sales2);
    $clients2 = Client::all();
    expect($clients2)->toHaveCount(1)
        ->and($clients2->first()->id)->toBe($this->client2->id);
});

test('sales user cannot view or edit another sales user client via policy', function () {
    expect(Gate::forUser($this->sales1)->allows('view', $this->client1))->toBeTrue();
    expect(Gate::forUser($this->sales1)->allows('view', $this->client2))->toBeFalse();

    expect(Gate::forUser($this->sales1)->allows('update', $this->client1))->toBeTrue();
    expect(Gate::forUser($this->sales1)->allows('update', $this->client2))->toBeFalse();
});

test('sales user cannot delete another sales user client via policy', function () {
    expect(Gate::forUser($this->sales1)->allows('delete', $this->client1))->toBeTrue();
    expect(Gate::forUser($this->sales1)->allows('delete', $this->client2))->toBeFalse();
});

test('sales user cannot access another sales user client in filament', function () {
    $this->actingAs($this->sales1)
        ->get("/admin/clients/{$this->client2->id}/edit")
        ->assertNotFound();
});
