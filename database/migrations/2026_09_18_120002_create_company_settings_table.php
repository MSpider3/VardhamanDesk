<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->string('company_name');
            $table->text('address');
            $table->string('gstin', 15);
            $table->string('pan', 10);
            $table->string('state');
            $table->string('state_code', 2);
            $table->string('logo_path')->nullable();
            $table->string('bank_account_name');
            $table->string('bank_account_number');
            $table->string('bank_ifsc');
            $table->string('bank_name');
            $table->string('authorised_signatory_name');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
