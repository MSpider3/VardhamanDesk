<?php

use App\Enums\LeadStatus;
use App\Filament\Widgets\OverdueFollowUpsWidget;
use App\Filament\Widgets\TodayFollowUpsWidget;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->sales1 = User::factory()->sales()->create();
    $this->sales2 = User::factory()->sales()->create();

    $this->today = Carbon::today()->format('Y-m-d');
    $this->yesterday = Carbon::yesterday()->format('Y-m-d');

    // Overdue lead for sales1
    $this->overdueSales1 = Lead::factory()->create([
        'name' => 'Overdue Lead 1',
        'assigned_to' => $this->sales1->id,
        'next_follow_up_date' => $this->yesterday,
        'status' => LeadStatus::CONTACTED,
    ]);

    // Overdue lead for sales2
    $this->overdueSales2 = Lead::factory()->create([
        'name' => 'Overdue Lead 2',
        'assigned_to' => $this->sales2->id,
        'next_follow_up_date' => $this->yesterday,
        'status' => LeadStatus::CONTACTED,
    ]);

    // Today lead for sales1
    $this->todaySales1 = Lead::factory()->create([
        'name' => 'Today Lead 1',
        'assigned_to' => $this->sales1->id,
        'next_follow_up_date' => $this->today,
        'status' => LeadStatus::NEW,
    ]);

    // Today lead for sales2
    $this->todaySales2 = Lead::factory()->create([
        'name' => 'Today Lead 2',
        'assigned_to' => $this->sales2->id,
        'next_follow_up_date' => $this->today,
        'status' => LeadStatus::NEW,
    ]);

    // Overdue Converted lead (should be excluded from overdue and today lists)
    $this->convertedOverdue = Lead::factory()->create([
        'name' => 'Converted Overdue Lead',
        'assigned_to' => $this->sales1->id,
        'next_follow_up_date' => $this->yesterday,
        'status' => LeadStatus::CONVERTED,
    ]);

    $this->convertedToday = Lead::factory()->create([
        'name' => 'Converted Today Lead',
        'assigned_to' => $this->sales1->id,
        'next_follow_up_date' => $this->today,
        'status' => LeadStatus::CONVERTED,
    ]);
});

test('overdue follow-ups widget displays overdue non-converted leads and scopes by role', function () {
    // Sales1 view
    $this->actingAs($this->sales1);

    Livewire::test(OverdueFollowUpsWidget::class)
        ->assertCanSeeTableRecords([$this->overdueSales1])
        ->assertCanNotSeeTableRecords([$this->overdueSales2, $this->convertedOverdue, $this->todaySales1]);

    // Admin view sees both
    $this->actingAs($this->admin);

    Livewire::test(OverdueFollowUpsWidget::class)
        ->assertCanSeeTableRecords([$this->overdueSales1, $this->overdueSales2])
        ->assertCanNotSeeTableRecords([$this->convertedOverdue, $this->todaySales1, $this->todaySales2]);
});

test('today follow-ups widget displays today non-converted leads and scopes by role', function () {
    // Sales1 view
    $this->actingAs($this->sales1);

    Livewire::test(TodayFollowUpsWidget::class)
        ->assertCanSeeTableRecords([$this->todaySales1])
        ->assertCanNotSeeTableRecords([$this->todaySales2, $this->convertedToday, $this->overdueSales1]);

    // Admin view sees both
    $this->actingAs($this->admin);

    Livewire::test(TodayFollowUpsWidget::class)
        ->assertCanSeeTableRecords([$this->todaySales1, $this->todaySales2])
        ->assertCanNotSeeTableRecords([$this->convertedToday, $this->overdueSales1, $this->overdueSales2]);
});

test('overdue follow-ups widget is sorted before today follow-ups widget', function () {
    $overdueRefl = new ReflectionClass(OverdueFollowUpsWidget::class);
    $overdueSort = $overdueRefl->getStaticPropertyValue('sort');

    $todayRefl = new ReflectionClass(TodayFollowUpsWidget::class);
    $todaySort = $todayRefl->getStaticPropertyValue('sort');

    expect($overdueSort)->toBeLessThan($todaySort);
});
