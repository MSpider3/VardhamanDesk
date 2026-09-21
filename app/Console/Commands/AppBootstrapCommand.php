<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\CompanySetting;
use App\Models\GstRate;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class AppBootstrapCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:bootstrap
                            {--name= : Name for the Admin user}
                            {--email= : Email for the Admin user}
                            {--password= : Password for the Admin user}
                            {--admin-name= : Alias for --name}
                            {--admin-email= : Alias for --email}
                            {--admin-password= : Alias for --password}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Bootstrap VardhamanDesk for production: create first Admin, seed GST rates, and company settings';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting production bootstrap...');

        // 1. Ensure first Admin exists
        $admin = User::where('role', UserRole::ADMIN)->first();
        if (! $admin) {
            $email = $this->option('admin-email') ?: ($this->option('email') ?: env('SEED_ADMIN_EMAIL', 'admin@vardhamandesk.local'));
            $name = $this->option('admin-name') ?: ($this->option('name') ?: 'Admin User');
            $password = $this->option('admin-password') ?: ($this->option('password') ?: (env('APP_BOOTSTRAP_ADMIN_PASSWORD') ?: env('SEED_DEFAULT_PASSWORD')));

            if (app()->isProduction()) {
                if (empty($password) || $password === 'password') {
                    $this->error('In production, you must supply a secure password via --password or the SEED_DEFAULT_PASSWORD / APP_BOOTSTRAP_ADMIN_PASSWORD environment variable.');

                    return self::FAILURE;
                }
            } else {
                $password = $password ?: 'password';
            }

            $admin = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make($password),
                    'role' => UserRole::ADMIN,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );

            // Ensure existing user with this email has active and verified status if promoted
            if (! $admin->is_active || $admin->email_verified_at === null || $admin->role !== UserRole::ADMIN) {
                $admin->is_active = true;
                $admin->email_verified_at = $admin->email_verified_at ?? now();
                $admin->role = UserRole::ADMIN;
                $admin->save();
            }

            $this->info("Admin user created/configured: {$admin->email}");
        } else {
            $this->info("Admin user already exists: {$admin->email}");
        }

        // 2. Seed 4 core GST Rates if missing
        $rates = [
            ['rate' => '0.00', 'label' => '0% GST', 'is_active' => true],
            ['rate' => '5.00', 'label' => '5% GST', 'is_active' => true],
            ['rate' => '18.00', 'label' => '18% GST', 'is_active' => true],
            ['rate' => '40.00', 'label' => '40% GST (Sin/Luxury)', 'is_active' => true],
        ];

        foreach ($rates as $rateData) {
            GstRate::firstOrCreate(
                ['rate' => $rateData['rate']],
                [
                    'label' => $rateData['label'],
                    'is_active' => $rateData['is_active'],
                ]
            );
        }
        $this->info('GST rates checked/seeded.');

        // 3. Seed placeholder CompanySetting if table empty
        if (CompanySetting::count() === 0) {
            CompanySetting::create([
                'id' => 1,
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
            ]);
            $this->info('Company settings seeded.');
        } else {
            $this->info('Company settings already exist.');
        }

        $this->info('Bootstrap completed successfully.');

        return Command::SUCCESS;
    }
}
