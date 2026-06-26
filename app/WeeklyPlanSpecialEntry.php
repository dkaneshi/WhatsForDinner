<?php

namespace App;

enum WeeklyPlanSpecialEntry: string
{
    case EatOut = 'eat_out';
    case TvDinnerNight = 'tv_dinner_night';

    public function label(): string
    {
        return match ($this) {
            self::EatOut => __('Eat Out'),
            self::TvDinnerNight => __('TV Dinner Night'),
        };
    }
}
