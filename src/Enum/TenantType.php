<?php

namespace App\Enum;

enum TenantType: string
{
    case MS_TEAMS = 'ms_teams';
    case WEB = 'web';

    public function label(): string
    {
        return match ($this) {
            self::MS_TEAMS => 'MS Teams',
            self::WEB => 'Web',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->label()] = $case->value;
        }

        return $choices;
    }
}
