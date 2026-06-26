<?php

namespace App;

enum WeeklyPlanEntrySlot: string
{
    case Main = 'main';
    case Alternative = 'alternative';

    public function label(): string
    {
        return match ($this) {
            self::Main => __('Main'),
            self::Alternative => __('Alternative'),
        };
    }
}
