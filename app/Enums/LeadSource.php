<?php

namespace App\Enums;

enum LeadSource: string
{
    case REFERRAL = 'referral';
    case BNI = 'bni';
    case WEBSITE = 'website';
    case COLD_CALL = 'cold_call';
    case EVENT = 'event';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::REFERRAL => 'Referral',
            self::BNI => 'BNI',
            self::WEBSITE => 'Website',
            self::COLD_CALL => 'Cold Call',
            self::EVENT => 'Event',
            self::OTHER => 'Other',
        };
    }
}
