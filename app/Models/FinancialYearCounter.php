<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinancialYearCounter extends Model
{
    use HasFactory;

    protected $primaryKey = 'financial_year';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'financial_year',
        'last_sequence',
    ];

    protected function casts(): array
    {
        return [
            'last_sequence' => 'integer',
        ];
    }
}
