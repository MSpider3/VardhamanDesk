<?php

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create([
        'email' => 'admin@vardhamandesk.local',
        'password' => bcrypt('password'),
    ]);

    $this->sales1 = User::factory()->sales()->create([
        'email' => 'sales1@vardhamandesk.local',
        'password' => bcrypt('password'),
    ]);

    $this->sales2 = User::factory()->sales()->create([
        'email' => 'sales2@vardhamandesk.local',
        'password' => bcrypt('password'),
    ]);

    $this->leadSales1 = Lead::factory()->create([
        'name' => 'Acme Corporation',
        'assigned_to' => $this->sales1->id,
        'status' => LeadStatus::QUALIFIED,
    ]);

    $this->leadSales2 = Lead::factory()->create([
        'name' => 'Stark Industries',
        'assigned_to' => $this->sales2->id,
        'status' => LeadStatus::QUALIFIED,
    ]);
});

test('admin can log in and access all panel areas', function () {
    // 1. Visit login page
    $this->get('/admin/login')->assertSuccessful();

    // 2. Authenticate as Admin and verify dashboard & navigation
    $this->actingAs($this->admin)
        ->get('/admin')
        ->assertSuccessful()
        ->assertSee('Leads')
        ->assertSee('Users');

    // 3. Admin can view leads list
    $this->actingAs($this->admin)
        ->get('/admin/leads')
        ->assertSuccessful();

    // 4. Admin can view users list
    $this->actingAs($this->admin)
        ->get('/admin/users')
        ->assertSuccessful();

    // 5. Admin can view clients and invoices lists and create forms
    $this->actingAs($this->admin)
        ->get('/admin/clients')
        ->assertSuccessful();

    $this->actingAs($this->admin)
        ->get('/admin/clients/create')
        ->assertSuccessful();

    $this->actingAs($this->admin)
        ->get('/admin/invoices')
        ->assertSuccessful();

    $this->actingAs($this->admin)
        ->get('/admin/invoices/create')
        ->assertSuccessful();
});

test('sales user can log in and is strictly isolated to own records', function () {
    // 1. Authenticate as Sales 1 and verify navigation (Users is hidden)
    $this->actingAs($this->sales1)
        ->get('/admin')
        ->assertSuccessful()
        ->assertSee('Leads')
        ->assertSee('Clients')
        ->assertSee('Invoices')
        ->assertDontSee('Users');

    // 2. Leads list is accessible
    $this->actingAs($this->sales1)
        ->get('/admin/leads')
        ->assertSuccessful();

    // 3. Clients and Invoices lists are accessible
    $this->actingAs($this->sales1)
        ->get('/admin/clients')
        ->assertSuccessful();

    $this->actingAs($this->sales1)
        ->get('/admin/invoices')
        ->assertSuccessful();

    // 4. Directly attempting to view users is forbidden
    $this->actingAs($this->sales1)
        ->get('/admin/users')
        ->assertForbidden();

    // 5. Directly attempting to edit other sales rep lead is 404 (scoped out)
    $this->actingAs($this->sales1)
        ->get("/admin/leads/{$this->leadSales2->id}/edit")
        ->assertNotFound();
});
