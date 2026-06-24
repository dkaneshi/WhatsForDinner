<?php

namespace App;

enum ProteinCategory: string
{
    case Beef = 'beef';
    case Poultry = 'poultry';
    case Pork = 'pork';
    case Fish = 'fish';
    case Vegetable = 'vegetable';

    public function label(): string
    {
        return match ($this) {
            self::Beef => __('Beef'),
            self::Poultry => __('Poultry'),
            self::Pork => __('Pork'),
            self::Fish => __('Fish'),
            self::Vegetable => __('Vegetable'),
        };
    }
}
