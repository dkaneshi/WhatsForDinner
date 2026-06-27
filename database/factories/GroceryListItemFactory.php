<?php

namespace Database\Factories;

use App\Models\GroceryList;
use App\Models\GroceryListItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GroceryListItem>
 */
class GroceryListItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word().' '.fake()->unique()->word();

        return [
            'grocery_list_id' => GroceryList::factory(),
            'name' => Str::title($name),
            'normalized_name' => Str::of($name)->squish()->lower()->toString(),
            'is_checked' => false,
            'is_manual' => false,
            'is_suppressed' => false,
            'source_entry_ids' => [],
            'source_labels' => [],
        ];
    }

    public function manual(): static
    {
        return $this->state(fn (): array => [
            'is_manual' => true,
            'source_entry_ids' => null,
            'source_labels' => null,
        ]);
    }

    public function suppressed(): static
    {
        return $this->state(fn (): array => [
            'is_suppressed' => true,
            'is_checked' => false,
        ]);
    }
}
