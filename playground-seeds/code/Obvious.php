<?php

declare(strict_types=1);

namespace PlaygroundSeeds;

/**
 * Working but non-optimal code with obvious opcode wins:
 * global calls without FQN, nested ifs, temporary variables, array_key_exists on arrays without nulls,
 * count() in a loop condition.
 */
final class Obvious
{
    public function slugify(string $title): string
    {
        $lower = strtolower(trim($title));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $lower);

        return trim((string) $slug, '-');
    }

    public function describe(mixed $value): string
    {
        if (is_array($value)) {
            if (count($value) === 0) {
                return 'empty array';
            } else {
                return 'array of ' . count($value);
            }
        } else {
            if (is_string($value)) {
                return 'string of ' . strlen($value);
            } else {
                return gettype($value);
            }
        }
    }

    /**
     * @param array<string, int> $prices
     */
    public function priceOf(array $prices, string $sku): int
    {
        if (array_key_exists($sku, $prices)) {
            $price = $prices[$sku];

            return $price;
        }

        return 0;
    }

    /**
     * @param list<int> $numbers
     */
    public function sumEven(array $numbers): int
    {
        $sum = 0;
        for ($i = 0; $i < count($numbers); $i++) {
            if ($numbers[$i] % 2 === 0) {
                $sum += $numbers[$i];
            }
        }

        return $sum;
    }
}
