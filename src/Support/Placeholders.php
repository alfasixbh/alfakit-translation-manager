<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTranslationManager\Support;

/**
 * Checks a translation against the base locale: the same `:placeholders` (Laravel
 * replaces `:name`, `:Name` and `:NAME` alike), the same HTML tags and, for pluralised
 * strings, the plural forms. And masks those protected parts for machine translation.
 */
class Placeholders
{
    /** `:name` not preceded by a word character or a colon (keeps `12:30` and `http://` out). */
    public const PATTERN = '/(?<![\w:]):([A-Za-z_][A-Za-z0-9_]*)/u';

    public const TAG_PATTERN = '/<\/?([A-Za-z][A-Za-z0-9\-]*)\b[^<>]*>/u';

    /**
     * The placeholder names of a line, lower-cased, sorted and unique.
     *
     * @return list<string>
     */
    public static function extract(?string $line): array
    {
        if ($line === null || $line === '' || preg_match_all(self::PATTERN, $line, $matches) === false) {
            return [];
        }

        $names = array_values(array_unique(array_map(mb_strtolower(...), $matches[1])));
        sort($names);

        return $names;
    }

    /**
     * The placeholders of a line as written (`:name`, `:Count`), in order of appearance.
     *
     * @return list<string>
     */
    public static function tokens(?string $line): array
    {
        if ($line === null || $line === '' || preg_match_all(self::PATTERN, $line, $matches) === false) {
            return [];
        }

        return array_values(array_unique($matches[0]));
    }

    /**
     * HTML tag names with their counts (`['strong' => 2, 'a' => 2]`, opening and closing).
     *
     * @return array<string, int>
     */
    public static function tags(?string $line): array
    {
        if ($line === null || $line === '' || preg_match_all(self::TAG_PATTERN, $line, $matches) === false) {
            return [];
        }

        $tags = array_count_values(array_map(mb_strtolower(...), $matches[1]));
        ksort($tags);

        return $tags;
    }

    /**
     * Problems of `$translation` compared with `$base`: translated messages, for the editor.
     * Empty lines (missing translations) have no problems.
     *
     * @return list<string>
     */
    public static function warnings(?string $base, ?string $translation, ?string $locale = null): array
    {
        if ($base === null || $base === '' || $translation === null || $translation === '') {
            return [];
        }

        $warnings = [];
        $expected = self::extract($base);
        $actual = self::extract($translation);

        if (($missing = array_values(array_diff($expected, $actual))) !== []) {
            $warnings[] = (string) trans_choice('alfakit-translation-manager::translation-manager.warnings.missing_placeholders', count($missing), ['placeholders' => self::list($missing)]);
        }

        if (($extra = array_values(array_diff($actual, $expected))) !== []) {
            $warnings[] = (string) trans_choice('alfakit-translation-manager::translation-manager.warnings.unknown_placeholders', count($extra), ['placeholders' => self::list($extra)]);
        }

        if (self::tags($base) !== self::tags($translation)) {
            $warnings[] = (string) __('alfakit-translation-manager::translation-manager.warnings.html');
        }

        if (Plurals::isPlural($base) && ! Plurals::isPlural($translation) && ($locale === null || Plurals::count($locale) > 1)) {
            $warnings[] = (string) __('alfakit-translation-manager::translation-manager.warnings.plural');
        }

        return $warnings;
    }

    /**
     * Replace placeholders, HTML tags, entities and plural conditions (`{0}`, `[2,*]`) with
     * numbered markers a machine translator leaves alone.
     *
     * @return array{0: string, 1: array<string, string>} the masked text and the markers
     */
    public static function mask(string $line): array
    {
        $markers = [];
        $pattern = '/(^\s*(?:\{[^}]*\}|\[[^\]]*\]))|((?<=\|)\s*(?:\{[^}]*\}|\[[^\]]*\]))|<\/?[A-Za-z][^<>]*>|&[A-Za-z0-9#]+;|(?<![\w:]):[A-Za-z_][A-Za-z0-9_]*/u';

        $masked = (string) preg_replace_callback($pattern, static function (array $match) use (&$markers): string {
            $marker = '⟦'.(count($markers) + 1).'⟧';
            $markers[$marker] = $match[0];

            return $marker;
        }, $line);

        return [$masked, $markers];
    }

    /**
     * Put the masked parts back. Returns null when the translator dropped or duplicated a
     * marker (the result can't be trusted).
     *
     * @param  array<string, string>  $markers
     */
    public static function unmask(string $line, array $markers): ?string
    {
        foreach (array_keys($markers) as $marker) {
            if (substr_count($line, $marker) !== 1) {
                return null;
            }
        }

        if (preg_match('/⟦\d+⟧/u', strtr($line, array_fill_keys(array_keys($markers), ''))) === 1) {
            return null;
        }

        return strtr($line, $markers);
    }

    /**
     * @param  list<string>  $names
     */
    protected static function list(array $names): string
    {
        return implode(', ', array_map(static fn (string $name): string => ':'.$name, $names));
    }
}
