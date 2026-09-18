<?php

namespace App\Enums;

enum UserRole: string
{
    case ADMIN = 'admin';
    case SALES = 'sales';

    public function label(): string
    {
        return match ($this) {
            self::ADMIN => 'Admin',
            self::SALES => 'Sales',
        };
    }
}
