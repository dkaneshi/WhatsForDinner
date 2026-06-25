<?php

namespace App\Actions\DishImports;

use App\ProteinCategory;
use Illuminate\Support\Str;

class ParseMarkdownDishImport
{
    /**
     * Parse family dish notes into an editable import preview.
     *
     * @return list<array{name: string, note: string, ingredients: list<array{name: string, protein_category: string|null, is_main_protein: bool}>, errors: list<string>, warnings: list<string>}>
     */
    public function execute(string $markdown): array
    {
        $rows = [];
        $current = null;
        $noteLines = [];

        foreach (preg_split('/\R/u', $markdown) ?: [] as $line) {
            if (preg_match('/^\s*##\s+(.+?)\s*$/u', $line, $headingMatches) === 1) {
                $this->pushCurrentDish($rows, $current, $noteLines);

                $current = [
                    'name' => Str::squish($headingMatches[1]),
                    'note' => '',
                    'ingredients' => [],
                    'errors' => [],
                    'warnings' => [],
                ];
                $noteLines = [];

                continue;
            }

            if (is_null($current)) {
                continue;
            }

            if (preg_match('/^\s*[*+-]\s+(.+?)\s*$/u', $line, $ingredientMatches) === 1) {
                $parsedIngredient = $this->parseIngredient($ingredientMatches[1]);

                if ($parsedIngredient['name'] !== '') {
                    $current['ingredients'][] = $parsedIngredient;
                }

                continue;
            }

            if (trim($line) !== '') {
                $noteLines[] = trim($line);
            }
        }

        $this->pushCurrentDish($rows, $current, $noteLines);

        return $rows;
    }

    /**
     * @param  list<array{name: string, note: string, ingredients: list<array{name: string, protein_category: string|null, is_main_protein: bool}>, errors: list<string>, warnings: list<string>}>  $rows
     * @param  array{name: string, note: string, ingredients: list<array{name: string, protein_category: string|null, is_main_protein: bool}>, errors: list<string>, warnings: list<string>}|null  $current
     * @param  list<string>  $noteLines
     */
    private function pushCurrentDish(array &$rows, ?array $current, array $noteLines): void
    {
        if (is_null($current)) {
            return;
        }

        $current['note'] = Str::of(implode("\n", $noteLines))->trim()->toString();
        $rows[] = $current;
    }

    /**
     * @return array{name: string, protein_category: string|null, is_main_protein: bool}
     */
    private function parseIngredient(string $line): array
    {
        $ingredient = Str::squish($line);
        $category = null;
        $isMainProtein = false;

        if (preg_match('/\s*\((beef|poultry|pork|fish|vegetable)\)\s*$/iu', $ingredient, $matches) === 1) {
            $category = ProteinCategory::from(Str::lower($matches[1]))->value;
            $ingredient = Str::squish(preg_replace('/\s*\((beef|poultry|pork|fish|vegetable)\)\s*$/iu', '', $ingredient) ?? $ingredient);
            $isMainProtein = true;
        }

        return [
            'name' => $ingredient,
            'protein_category' => $category,
            'is_main_protein' => $isMainProtein,
        ];
    }
}
