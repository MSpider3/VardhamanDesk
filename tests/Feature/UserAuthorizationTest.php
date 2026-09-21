<?php

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create([
        'email' => 'admin@vardhamandesk.local',
    ]);

    $this->sales = User::factory()->sales()->create([
        'email' => 'sales@vardhamandesk.local',
    ]);
});

test('admin user can view user management', function () {
    expect(Gate::forUser($this->admin)->allows('viewAny', User::class))->toBeTrue();
    expect(Gate::forUser($this->admin)->allows('create', User::class))->toBeTrue();
    expect(Gate::forUser($this->admin)->allows('update', $this->sales))->toBeTrue();
    expect(Gate::forUser($this->admin)->allows('delete', $this->sales))->toBeTrue();
});

test('sales user cannot view or manage users', function () {
    expect(Gate::forUser($this->sales)->allows('viewAny', User::class))->toBeFalse();
    expect(Gate::forUser($this->sales)->allows('create', User::class))->toBeFalse();
    expect(Gate::forUser($this->sales)->allows('update', $this->admin))->toBeFalse();
    expect(Gate::forUser($this->sales)->allows('delete', $this->admin))->toBeFalse();
});

test('sales user cannot access filament user management resource page', function () {
    $this->actingAs($this->sales)
        ->get('/admin/users')
        ->assertForbidden();
});

test('admin user can access filament user management resource page', function () {
    $this->actingAs($this->admin)
        ->get('/admin/users')
        ->assertSuccessful();
});

test('admin cannot create a user with an existing email due to unique validation', function () {
    Livewire\Livewire::actingAs($this->admin)
        ->test(CreateUser::class)
        ->fillForm([
            'name' => 'Duplicate Sales',
            'email' => 'sales@vardhamandesk.local',
            'role' => 'sales',
            'password' => 'secret12345',
        ])
        ->call('create')
        ->assertHasFormErrors(['email' => 'unique']);
});
