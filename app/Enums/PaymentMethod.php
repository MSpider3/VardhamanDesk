<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case BANK_TRANSFER = 'bank_transfer';
    case UPI = 'upi';
    case CHEQUE = 'cheque';
    case CASH = 'cash';

    public function label(): string
    {
        return match ($this) {
            self::BANK_TRANSFER => 'Bank Transfer (NEFT/RTGS/IMPS)',
            self::UPI => 'UPI',
            self::CHEQUE => 'Cheque',
            self::CASH => 'Cash',
        };
    }
}
