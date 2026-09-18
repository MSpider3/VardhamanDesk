<?php

namespace Database\Seeders;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\CompanySetting;
use App\Models\GstRate;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\User;
use App\Services\InvoiceDraftService;
use App\Services\InvoiceSendService;
use App\Services\RecordPayment;
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

        // 4. Seed Reference Tables: GST Rates (0, 5, 18, 40)
        $gst0 = GstRate::firstOrCreate(
            ['rate' => '0.00'],
            ['label' => '0% GST', 'is_active' => true]
        );
        $gst5 = GstRate::firstOrCreate(
            ['rate' => '5.00'],
            ['label' => '5% GST', 'is_active' => true]
        );
        $gst18 = GstRate::firstOrCreate(
            ['rate' => '18.00'],
            ['label' => '18% GST', 'is_active' => true]
        );
        $gst40 = GstRate::firstOrCreate(
            ['rate' => '40.00'],
            ['label' => '40% GST (Sin/Luxury)', 'is_active' => true]
        );

        // 5. Seed Company Settings (Rajasthan supplier placeholder)
        CompanySetting::firstOrCreate(
            ['id' => 1],
            [
                'company_name' => 'Vardhaman Infotech Solutions',
                'address' => 'Plot No. 42, Malviya Industrial Area, Jaipur, Rajasthan - 302017',
                'gstin' => '08AABCV1234F1Z9',
                'pan' => 'AABCV1234F',
                'state' => 'Rajasthan',
                'state_code' => '08',
                'logo_path' => null,
                'bank_account_name' => 'Vardhaman Infotech Solutions',
                'bank_account_number' => '987654321012',
                'bank_ifsc' => 'HDFC0001234',
                'bank_name' => 'HDFC Bank, Malviya Nagar Branch',
                'authorised_signatory_name' => 'Director / Authorized Signatory',
            ]
        );

        // 6. Seed Clients
        // Converted Client from Dr. Arvind Sharma lead
        $convertedLead = Lead::withoutGlobalScopes()->where('email', 'arvind@apexdiagnostics.org')->first();
        $clientConverted = Client::withoutGlobalScopes()->firstOrCreate(
            ['email' => 'arvind@apexdiagnostics.org'],
            [
                'lead_id' => $convertedLead?->id,
                'assigned_to' => $sales3->id,
                'created_by' => $sales3->id,
                'name' => 'Dr. Arvind Sharma',
                'company' => 'Apex Diagnostic Center',
                'phone' => '+91 98294 65667',
                'billing_address' => '12, Medical Enclave, Tonk Road, Jaipur, Rajasthan - 302018',
                'state' => '08',
                'gstin' => '08AADCA1111A1Z1',
            ]
        );

        // Direct Client 1: Rajasthan (Intra-state) owned by Sales1
        $clientDirectRaj = Client::withoutGlobalScopes()->firstOrCreate(
            ['email' => 'finance@marwartech.in'],
            [
                'lead_id' => null,
                'assigned_to' => $sales1->id,
                'created_by' => $sales1->id,
                'name' => 'Marwar Tech Enterprises',
                'company' => 'Marwar Tech Enterprises Pvt Ltd',
                'phone' => '+91 98290 99887',
                'billing_address' => 'B-14, IT Park, Sitapura, Jaipur, Rajasthan - 302022',
                'state' => '08',
                'gstin' => '08ABCDE1234F1Z5',
            ]
        );

        // Direct Client 2: Maharashtra (Inter-state) owned by Sales2
        $clientDirectMh = Client::withoutGlobalScopes()->firstOrCreate(
            ['email' => 'accounts@mumbaifintech.io'],
            [
                'lead_id' => null,
                'assigned_to' => $sales2->id,
                'created_by' => $sales2->id,
                'name' => 'Mumbai FinTech Labs',
                'company' => 'Mumbai FinTech Labs LLP',
                'phone' => '+91 98200 11223',
                'billing_address' => '704, Platina Tower, Bandra Kurla Complex, Mumbai, Maharashtra - 400051',
                'state' => '27',
                'gstin' => '27ABCDE5678F1Z2',
            ]
        );

        // Direct Client 3: Unregistered buyer (Rajasthan) owned by Sales1
        $clientUnregistered = Client::withoutGlobalScopes()->firstOrCreate(
            ['email' => 'artisan@jaipurguild.org'],
            [
                'lead_id' => null,
                'assigned_to' => $sales1->id,
                'created_by' => $sales1->id,
                'name' => 'Jaipur Artisan Guild',
                'company' => 'Jaipur Artisan Guild',
                'phone' => '+91 98291 44556',
                'billing_address' => 'Johari Bazaar, Pink City, Jaipur, Rajasthan - 302003',
                'state' => '08',
                'gstin' => null,
            ]
        );

        // Direct Client 4: For Admin reassignment demo
        $clientReassignable = Client::withoutGlobalScopes()->firstOrCreate(
            ['email' => 'info@bikanersweets.com'],
            [
                'lead_id' => null,
                'assigned_to' => $sales2->id,
                'created_by' => $admin->id,
                'name' => 'Bikaner Sweets & Spices',
                'company' => 'Bikaner Sweets & Spices Ltd',
                'phone' => '+91 98295 77889',
                'billing_address' => 'Station Road, Bikaner, Rajasthan - 334001',
                'state' => '08',
                'gstin' => '08AAACB9999K1Z4',
            ]
        );

        // 7. Seed Invoices & Payments (Draft, Sent, Partially Paid, Paid; Current & Prior Month; Intra & Inter-state)
        $draftService = app(InvoiceDraftService::class);
        $sendService = app(InvoiceSendService::class);
        $recordPaymentService = app(RecordPayment::class);

        $now = Carbon::now();
        $currentMonth = $now->copy();
        $priorMonth = $now->copy()->subMonth();

        // Invoice 1: Draft Intra-state with Mixed Rates (18% and 5%)
        $draftIntra = $draftService->createDraft(
            [
                'client_id' => $clientDirectRaj->id,
                'place_of_supply' => '08',
                'invoice_date' => $currentMonth->toDateString(),
                'due_date' => $currentMonth->copy()->addDays(30)->toDateString(),
            ],
            [
                [
                    'description' => 'Custom Software Architecture & Consulting',
                    'sac_code' => '998313',
                    'quantity' => '1.00',
                    'rate' => '50000.00',
                    'gst_rate_id' => $gst18->id,
                ],
                [
                    'description' => 'Technical Documentation & User Manual Printing Support',
                    'sac_code' => '998319',
                    'quantity' => '2.00',
                    'rate' => '5000.00',
                    'gst_rate_id' => $gst5->id,
                ],
            ],
            $sales1
        );

        // Invoice 2: Sent Intra-state Invoice (Sent with NO payments, Current Month)
        $invoiceToSent1 = $draftService->createDraft(
            [
                'client_id' => $clientDirectRaj->id,
                'place_of_supply' => '08',
                'invoice_date' => $currentMonth->copy()->startOfMonth()->addDays(1)->toDateString(),
                'due_date' => $currentMonth->copy()->startOfMonth()->addDays(31)->toDateString(),
            ],
            [
                [
                    'description' => 'Annual Software Maintenance Contract - Q1',
                    'sac_code' => '998314',
                    'quantity' => '1.00',
                    'rate' => '40000.00',
                    'gst_rate_id' => $gst18->id,
                ],
            ],
            $sales1
        );
        $sendService->send($invoiceToSent1, $currentMonth->copy()->startOfMonth()->addDays(1)->toDateString());

        // Invoice 3: Partially Paid Inter-state Invoice (Maharashtra, 18%, Current Month)
        // Subtotal: 100,000 + IGST 18% (18,000) = 118,000.00
        $invoicePartial = $draftService->createDraft(
            [
                'client_id' => $clientDirectMh->id,
                'place_of_supply' => '27',
                'invoice_date' => $currentMonth->copy()->startOfMonth()->addDays(3)->toDateString(),
                'due_date' => $currentMonth->copy()->startOfMonth()->addDays(33)->toDateString(),
            ],
            [
                [
                    'description' => 'Cloud Infrastructure Management & Monitoring Setup',
                    'sac_code' => '998315',
                    'quantity' => '1.00',
                    'rate' => '100000.00',
                    'gst_rate_id' => $gst18->id,
                ],
            ],
            $sales2
        );
        $sentPartial = $sendService->send($invoicePartial, $currentMonth->copy()->startOfMonth()->addDays(3)->toDateString());

        // Record partial payment of 50,000.00 via UPI in current month
        $recordPaymentService->execute(
            $sentPartial,
            [
                'amount' => '50000.00',
                'payment_date' => $currentMonth->copy()->startOfMonth()->addDays(6)->toDateString(),
                'method' => PaymentMethod::UPI,
                'reference_note' => 'UPI/2026/8892147',
            ],
            $sales2
        );

        // Invoice 4: Paid Intra-state Invoice (Issued in Prior Month, Payments in Prior & Current Month)
        // Subtotal: 50,000 + CGST 9% (4,500) + SGST 9% (4,500) = 59,000.00
        $invoicePaid = $draftService->createDraft(
            [
                'client_id' => $clientConverted->id,
                'place_of_supply' => '08',
                'invoice_date' => $priorMonth->copy()->startOfMonth()->addDays(5)->toDateString(),
                'due_date' => $priorMonth->copy()->startOfMonth()->addDays(35)->toDateString(),
            ],
            [
                [
                    'description' => 'Hospital Management ERP System Implementation - Milestone 1',
                    'sac_code' => '998314',
                    'quantity' => '1.00',
                    'rate' => '50000.00',
                    'gst_rate_id' => $gst18->id,
                ],
            ],
            $sales3
        );
        $sentPaid = $sendService->send($invoicePaid, $priorMonth->copy()->startOfMonth()->addDays(5)->toDateString());

        // Payment 1: 30,000.00 in Prior Month via Bank Transfer
        $recordPaymentService->execute(
            $sentPaid,
            [
                'amount' => '30000.00',
                'payment_date' => $priorMonth->copy()->startOfMonth()->addDays(15)->toDateString(),
                'method' => PaymentMethod::BANK_TRANSFER,
                'reference_note' => 'NEFT/AXIS/98711200',
            ],
            $sales3
        );

        // Payment 2: 29,000.00 in Current Month via Cheque (completes payment to 59,000.00 -> PAID)
        $recordPaymentService->execute(
            $sentPaid,
            [
                'amount' => '29000.00',
                'payment_date' => $currentMonth->copy()->startOfMonth()->addDays(2)->toDateString(),
                'method' => PaymentMethod::CHEQUE,
                'reference_note' => 'CHQ#440912',
            ],
            $sales3
        );

        // Invoice 5: Sent Intra-state Invoice with Mixed Rates (18% and 0%, Current Month, No Payments)
        $invoiceToSent2 = $draftService->createDraft(
            [
                'client_id' => $clientReassignable->id,
                'place_of_supply' => '08',
                'invoice_date' => $currentMonth->copy()->startOfMonth()->addDays(7)->toDateString(),
                'due_date' => $currentMonth->copy()->startOfMonth()->addDays(37)->toDateString(),
            ],
            [
                [
                    'description' => 'Security Audit & Compliance Assessment',
                    'sac_code' => '998316',
                    'quantity' => '1.00',
                    'rate' => '80000.00',
                    'gst_rate_id' => $gst18->id,
                ],
                [
                    'description' => 'Open Source Research Advisory (Exempt)',
                    'sac_code' => '998319',
                    'quantity' => '1.00',
                    'rate' => '20000.00',
                    'gst_rate_id' => $gst0->id,
                ],
            ],
            $sales2
        );
        $sendService->send($invoiceToSent2, $currentMonth->copy()->startOfMonth()->addDays(7)->toDateString());
    }
}
