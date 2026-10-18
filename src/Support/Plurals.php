<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTranslationManager\Support;

use Illuminate\Translation\MessageSelector;

/**
 * Pluralised lines (`apple|apples`, `{0} None|[1,19] Some|[20,*] Many`): how many forms a
 * locale needs (from Laravel's own plural rules) and which numbers each form is used for.
 */
class Plurals
{
    /** @var array<string, list<list<int>>> */
    private static array $samples = [];

    public static function isPlural(?string $line): bool
    {
        return $line !== null && str_contains($line, '|');
    }

    /**
     * Whether the forms carry explicit conditions (`{0}`, `[1,19]`, `[20,*]`).
     */
    public static function hasConditions(string $line): bool
    {
        return preg_match('/^\s*(\{[^}]*\}|\[[^\]]*\])/', $line) === 1;
    }

    /**
     * @return list<string>
     */
    public static function split(string $line): array
    {
        return explode('|', $line);
    }

    /**
     * @param  array<mixed>  $forms
     */
    public static function join(array $forms): string
    {
        $forms = array_map(static fn (mixed $form): string => is_scalar($form) ? str_replace('|', '', (string) $form) : '', array_values($forms));

        // Trailing empty forms are dropped; an all-empty list is an empty line.
        while ($forms !== [] && trim((string) end($forms)) === '') {
            array_pop($forms);
        }

        return implode('|', $forms);
    }

    /**
     * How many plural forms a locale has (English 2, French 2, Russian 3, Arabic 6…).
     */
    public static function count(string $locale): int
    {
        return max(1, count(self::samples($locale)));
    }

    /**
     * The numbers each form is used for, as short labels: `['1', '0, 2, 3…']`.
     *
     * @return list<string>
     */
    public static function labels(string $locale): array
    {
        return array_map(static function (array $numbers): string {
            $shown = array_slice($numbers, 0, 3);

            return implode(', ', $shown).(count($numbers) > 3 ? '…' : '');
        }, self::samples($locale));
    }

    /**
     * The forms of a line in the editor and their labels, following the base line: its
     * conditions when it has them, otherwise the locale's plural rules. Null for lines that
     * aren't pluralised in the base locale.
     *
     * @return array{forms: list<string>, labels: list<string>}|null
     */
    public static function forms(?string $base, ?string $value, string $locale): ?array
    {
        if (! self::isPlural($base) && ! self::isPlural($value)) {
            return null;
        }

        $base ??= '';

        if (self::isPlural($base) && self::hasConditions($base)) {
            $labels = array_map(static function (string $form): string {
                preg_match('/^\s*(\{[^}]*\}|\[[^\]]*\])/', $form, $matches);

                return $matches[1] ?? '';
            }, self::split($base));
        } else {
            $labels = self::labels($locale);
        }

        $forms = $value === null || $value === '' ? [] : self::split($value);
        $count = max(count($labels), count($forms));

        return [
            'forms' => array_pad($forms, $count, ''),
            'labels' => array_pad($labels, $count, ''),
        ];
    }

    /**
     * Sample numbers (0 to 110) grouped by the plural form Laravel picks for them.
     *
     * @return list<list<int>>
     */
    protected static function samples(string $locale): array
    {
        if (isset(self::$samples[$locale])) {
            return self::$samples[$locale];
        }

        $selector = new MessageSelector;
        $groups = [];

        foreach ([1, 2, 3, 4, 5, 0, ...range(6, 110)] as $number) {
            $groups[$selector->getPluralIndex($locale, $number)][] = $number;
        }

        ksort($groups);

        foreach ($groups as $index => $numbers) {
            sort($numbers);
            $groups[$index] = $numbers;
        }

        return self::$samples[$locale] = array_values($groups);
    }
}
