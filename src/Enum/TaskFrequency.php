<?php

namespace App\Enum;

enum TaskFrequency: string
{
    case MANUAL = 'manual';
    case HOURLY = 'hourly';
    case DAILY = 'daily';
    case WEEKDAYS = 'weekdays';
    case WEEKLY = 'weekly';

    public function label(): string
    {
        return match ($this) {
            self::MANUAL => 'Manual',
            self::HOURLY => 'Hourly',
            self::DAILY => 'Daily',
            self::WEEKDAYS => 'Weekdays (Mon-Fri)',
            self::WEEKLY => 'Weekly',
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
