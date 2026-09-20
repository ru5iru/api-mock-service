<?php

namespace App\Services\Config;

enum ImportMode: string
{
    case CreateOnly = 'create-only';
    case Upsert = 'upsert';
    case Clone = 'clone';

    public function label(): string
    {
        return match ($this) {
            self::CreateOnly => 'Create only',
            self::Upsert => 'Update by UUID',
            self::Clone => 'Clone with new UUIDs',
        };
    }
}
