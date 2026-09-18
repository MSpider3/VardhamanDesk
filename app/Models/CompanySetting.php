<?php

namespace App\Models;

use App\Enums\IndianState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanySetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_name',
        'address',
        'gstin',
        'pan',
        'state',
        'state_code',
        'logo_path',
        'bank_account_name',
        'bank_account_number',
        'bank_ifsc',
        'bank_name',
        'authorised_signatory_name',
    ];

    /**
     * Get the single company settings record.
     */
    public static function current(): ?self
    {
        return static::query()->first();
    }

    /**
     * Resolve company state as IndianState enum if available.
     */
    public function getIndianState(): ?IndianState
    {
        return IndianState::fromCodeOrName($this->state_code ?? $this->state);
    }
}
