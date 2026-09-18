<?php

namespace Database\Seeders;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\UserRole;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Create Admin user
        $admin = User::firstOrCreate(
            ['email' => 'admin@vardhamandesk.local'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
                'role' => UserRole::ADMIN,
                'email_verified_at' => now(),
            ]
        );

        // 2. Create 3 Sales users
        $sales1 = User::firstOrCreate(
            ['email' => 'sales1@vardhamandesk.local'],
            [
                'name' => 'Aarav Sharma',
                'password' => Hash::make('password'),
                'role' => UserRole::SALES,
                'email_verified_at' => now(),
            ]
        );

        $sales2 = User::firstOrCreate(
            ['email' => 'sales2@vardhamandesk.local'],
            [
                'name' => 'Priya Patel',
                'password' => Hash::make('password'),
                'role' => UserRole::SALES,
                'email_verified_at' => now(),
            ]
        );

        $sales3 = User::firstOrCreate(
            ['email' => 'sales3@vardhamandesk.local'],
            [
                'name' => 'Rohan Gupta',
                'password' => Hash::make('password'),
                'role' => UserRole::SALES,
                'email_verified_at' => now(),
            ]
        );

        $salesUsers = [$sales1, $sales2, $sales3];

        // 3. Seed ~15 realistic leads covering all sources and statuses
        $today = Carbon::today()->format('Y-m-d');
        $yesterday = Carbon::yesterday()->format('Y-m-d');
        $threeDaysAgo = Carbon::today()->subDays(3)->format('Y-m-d');
        $fiveDaysAgo = Carbon::today()->subDays(5)->format('Y-m-d');
        $tomorrow = Carbon::tomorrow()->format('Y-m-d');
        $nextWeek = Carbon::today()->addDays(7)->format('Y-m-d');

        $leadsData = [
            // Overdue leads
            [
                'name' => 'Vikram Singhania',
                'company' => 'Singhania Logistics Ltd',
                'phone' => '+91 98290 11223',
                'email' => 'vikram@singhanialogistics.com',
                'source' => LeadSource::REFERRAL,
                'assigned_to' => $sales1->id,
                'status' => LeadStatus::CONTACTED,
                'next_follow_up_date' => $threeDaysAgo,
                'notes' => [
                    ['by' => $sales1->id, 'text' => 'Initial discovery call completed. Client requested quote comparison.', 'follow_up' => $threeDaysAgo],
                ],
            ],
            [
                'name' => 'Ananya Verma',
                'company' => 'Verma Retail Solutions',
                'phone' => '+91 98291 22334',
                'email' => 'ananya@verma-retail.in',
                'source' => LeadSource::BNI,
                'assigned_to' => $sales2->id,
                'status' => LeadStatus::QUALIFIED,
                'next_follow_up_date' => $fiveDaysAgo,
                'notes' => [
                    ['by' => $sales2->id, 'text' => 'BNI 1-on-1 meeting. Budget confirmed at 5 Lakhs.', 'follow_up' => $fiveDaysAgo],
                ],
            ],
            [
                'name' => 'Rajesh Joshi',
                'company' => 'Joshi Textiles Jaipur',
                'phone' => '+91 98292 33445',
                'email' => 'rajesh@joshitextiles.com',
                'source' => LeadSource::COLD_CALL,
                'assigned_to' => $sales3->id,
                'status' => LeadStatus::NEW,
                'next_follow_up_date' => $yesterday,
                'notes' => [
                    ['by' => $sales3->id, 'text' => 'Cold call pitched ERP billing solution. Asked to call back yesterday.', 'follow_up' => $yesterday],
                ],
            ],

            // Today's follow-ups
            [
                'name' => 'Sanjay Mehta',
                'company' => 'Mehta Agro Tech',
                'phone' => '+91 98293 44556',
                'email' => 'sanjay@mehta-agro.com',
                'source' => LeadSource::WEBSITE,
                'assigned_to' => $sales1->id,
                'status' => LeadStatus::CONTACTED,
                'next_follow_up_date' => $today,
                'notes' => [
                    ['by' => $sales1->id, 'text' => 'Submitted website inquiry form. Scheduled demo presentation for today.', 'follow_up' => $today],
                ],
            ],
            [
                'name' => 'Divya Bafna',
                'company' => 'Bafna Jewellers',
                'phone' => '+91 98294 55667',
                'email' => 'divya@bafnajewellers.com',
                'source' => LeadSource::EVENT,
                'assigned_to' => $sales2->id,
                'status' => LeadStatus::QUALIFIED,
                'next_follow_up_date' => $today,
                'notes' => [
                    ['by' => $sales2->id, 'text' => 'Met at Jaipur Trade Expo. Decision maker available today at 3 PM.', 'follow_up' => $today],
                ],
            ],
            [
                'name' => 'Karan Malhotra',
                'company' => 'Malhotra Pharma Distributors',
                'phone' => '+91 98295 66778',
                'email' => 'karan@malhotrapharma.in',
                'source' => LeadSource::REFERRAL,
                'assigned_to' => $sales3->id,
                'status' => LeadStatus::NEW,
                'next_follow_up_date' => $today,
                'notes' => [
                    ['by' => $sales3->id, 'text' => 'Referred by CA Agarwal. Need to connect today.', 'follow_up' => $today],
                ],
            ],

            // Future follow-ups / pipeline
            [
                'name' => 'Harish Chandra',
                'company' => 'Chandra Steels',
                'phone' => '+91 98296 77889',
                'email' => 'harish@chandrasteels.com',
                'source' => LeadSource::BNI,
                'assigned_to' => $sales1->id,
                'status' => LeadStatus::QUALIFIED,
                'next_follow_up_date' => $tomorrow,
                'notes' => [
                    ['by' => $sales1->id, 'text' => 'Proposal drafted and sent. Awaiting board review tomorrow.', 'follow_up' => $tomorrow],
                ],
            ],
            [
                'name' => 'Pooja Agarwal',
                'company' => 'Agarwal Handicrafts',
                'phone' => '+91 98297 88990',
                'email' => 'pooja@agarwalcrafts.com',
                'source' => LeadSource::WEBSITE,
                'assigned_to' => $sales2->id,
                'status' => LeadStatus::CONTACTED,
                'next_follow_up_date' => $nextWeek,
                'notes' => [
                    ['by' => $sales2->id, 'text' => 'Requested case studies. Following up next week after travel.', 'follow_up' => $nextWeek],
                ],
            ],
            [
                'name' => 'Sunil Choudhary',
                'company' => 'Choudhary Motors',
                'phone' => '+91 98298 99001',
                'email' => 'sunil@choudharymotors.com',
                'source' => LeadSource::OTHER,
                'assigned_to' => $sales3->id,
                'status' => LeadStatus::NEW,
                'next_follow_up_date' => null,
                'notes' => [
                    ['by' => $sales3->id, 'text' => 'Inbound telephone call. Left message.', 'follow_up' => null],
                ],
            ],
            [
                'name' => 'Deepak Jain',
                'company' => 'Jain Electricals',
                'phone' => '+91 98299 10112',
                'email' => 'deepak@jainelectricals.com',
                'source' => LeadSource::COLD_CALL,
                'assigned_to' => $sales1->id,
                'status' => LeadStatus::CONTACTED,
                'next_follow_up_date' => $nextWeek,
                'notes' => [],
            ],
            [
                'name' => 'Ritu Saxena',
                'company' => 'Saxena & Associates',
                'phone' => '+91 98290 21223',
                'email' => 'ritu@saxenalaw.com',
                'source' => LeadSource::EVENT,
                'assigned_to' => $sales2->id,
                'status' => LeadStatus::NEW,
                'next_follow_up_date' => null,
                'notes' => [],
            ],
            [
                'name' => 'Gaurav Khandelwal',
                'company' => 'Khandelwal Sweets & Namkeen',
                'phone' => '+91 98291 32334',
                'email' => 'gaurav@khandelwalsweets.com',
                'source' => LeadSource::REFERRAL,
                'assigned_to' => $sales3->id,
                'status' => LeadStatus::QUALIFIED,
                'next_follow_up_date' => $nextWeek,
                'notes' => [],
            ],

            // Reopenable Lost Leads
            [
                'name' => 'Manish Tiwari',
                'company' => 'Tiwari Construction Group',
                'phone' => '+91 98292 43445',
                'email' => 'manish@tiwariconstruction.com',
                'source' => LeadSource::COLD_CALL,
                'assigned_to' => $sales1->id,
                'status' => LeadStatus::LOST,
                'next_follow_up_date' => null,
                'notes' => [
                    ['by' => $sales1->id, 'text' => 'Client selected competing vendor due to existing multi-year contract. Noted to re-engage in Q4.', 'follow_up' => null],
                ],
            ],
            [
                'name' => 'Ashok Gehlot & Sons',
                'company' => 'Desert Stone Works',
                'phone' => '+91 98293 54556',
                'email' => 'contact@desertstoneworks.com',
                'source' => LeadSource::OTHER,
                'assigned_to' => $sales2->id,
                'status' => LeadStatus::LOST,
                'next_follow_up_date' => null,
                'notes' => [
                    ['by' => $sales2->id, 'text' => 'Budget put on hold due to plant expansion. Reopenable later.', 'follow_up' => null],
                ],
            ],

            // Converted Lead (Terminal guard verification)
            [
                'name' => 'Dr. Arvind Sharma',
                'company' => 'Apex Diagnostic Center',
                'phone' => '+91 98294 65667',
                'email' => 'arvind@apexdiagnostics.org',
                'source' => LeadSource::REFERRAL,
                'assigned_to' => $sales3->id,
                'status' => LeadStatus::CONVERTED,
                'next_follow_up_date' => null,
                'notes' => [
                    ['by' => $sales3->id, 'text' => 'Successfully converted to client. Contract finalized.', 'follow_up' => null],
                ],
            ],
        ];

        foreach ($leadsData as $data) {
            $notes = $data['notes'] ?? [];
            unset($data['notes']);

            $lead = Lead::withoutGlobalScopes()->updateOrCreate(
                ['email' => $data['email']],
                $data
            );

            foreach ($notes as $noteData) {
                LeadNote::withoutGlobalScopes()->create([
                    'lead_id' => $lead->id,
                    'created_by' => $noteData['by'],
                    'note' => $noteData['text'],
                    'follow_up_date' => $noteData['follow_up'],
                ]);
            }
        }
    }
}
