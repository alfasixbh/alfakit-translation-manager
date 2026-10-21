<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTranslationManager\Support;

use FilesystemIterator;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Finds the translation keys an application uses: `__()`, `trans()`, `trans_choice()`,
 * `Lang::get()`, `@lang` and `@choice` in PHP and Blade, and `t()`, `$t()`, `trans()` and
 * `__()` in JavaScript, TypeScript, Vue and Svelte files. Keys built at runtime
 * (`__("status.{$state}")`, `'status.'.$state`, `` t(`status.${state}`) ``) count as a
 * used prefix, so `status.*` keys aren't reported as unused.
 *
 * The last result is kept in the cache for the editor's "unused" filter.
 */
class SourceScanner
{
    public const CACHE_KEY = 'alfakit-translation-manager:scan';

    /** PHP and Blade translation helpers. */
    protected const PHP_FUNCTIONS = '(?:__|trans|trans_choice|Lang::get|Lang::choice|Lang::has|@lang|@choice)';

    /** JavaScript translation helpers (`t()` of vue-i18n, i18next, AlfaKit's `useT()`…). */
    protected const JS_FUNCTIONS = '(?:\\$t|\\$tc|t|tc|trans|trans_choice|__|i18n\\.t|wTrans)';

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $extensions
     */
    public function __construct(
        protected array $paths,
        protected array $extensions = ['php'],
        protected ?CacheRepository $cache = null,
    ) {}

    /**
     * Scan the paths and remember the result.
     *
     * @return array{keys: array<string, list<string>>, prefixes: list<string>, files: int, scannedAt: string}
     */
    public function scan(): array
    {
        $keys = [];
        $prefixes = [];
        $count = 0;

        foreach ($this->files() as [$file, $root]) {
            $count++;
            $contents = @file_get_contents($file);

            if ($contents === false || $contents === '') {
                continue;
            }

            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $relative = $this->relative($file, $root);

            foreach ($this->extract($contents, $extension === 'php') as [$key, $isPrefix]) {
                if ($isPrefix) {
                    $prefixes[$key] = true;
                } else {
                    $keys[$key][$relative] = true;
                }
            }
        }

        ksort($keys);
        $prefixes = array_keys($prefixes);
        sort($prefixes);

        $result = [
            'keys' => array_map(static fn (array $files): array => array_keys($files), $keys),
            'prefixes' => array_values(array_filter($prefixes, static fn (string $prefix): bool => $prefix !== '')),
            'files' => $count,
            'scannedAt' => now()->toIso8601String(),
        ];

        $this->cache?->forever(self::CACHE_KEY, $result);

        return $result;
    }

    /**
     * The last scan, or null when the code was never scanned.
     *
     * @return array{keys: array<string, list<string>>, prefixes: list<string>, files: int, scannedAt: string}|null
     */
    public function last(): ?array
    {
        $result = $this->cache?->get(self::CACHE_KEY);

        return is_array($result) && isset($result['keys'], $result['prefixes']) ? $result : null;
    }

    /**
     * Keys and dynamic prefixes in one file's contents.
     *
     * @return list<array{0: string, 1: bool}> `[key, isPrefix]`
     */
    public function extract(string $contents, bool $php = true): array
    {
        $functions = $php ? self::PHP_FUNCTIONS : self::JS_FUNCTIONS;
        // The helper (not a method like `->t(` / `obj.t(` or a longer name like `format(`),
        // then a quoted first argument, then maybe a concatenation (`.` in PHP, `+` in JS).
        // A dot before a PHP helper is a concatenation (`'Hi '.__('key')`), not a method.
        $before = $php ? '(?<![\w$:])(?<!->)' : '(?<![\w$:.])(?<!->)';
        $pattern = '/'.$before.$functions.'\s*\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"|`((?:[^`\\\\]|\\\\.)*)`)(\s*[.+](?![.\d]))?/s';

        if (preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL) === false) {
            return [];
        }

        $found = [];

        foreach ($matches as $match) {
            [$quote, $raw] = match (true) {
                $match[1] !== null => ["'", $match[1]],
                $match[2] !== null => ['"', $match[2]],
                default => ['`', (string) ($match[3] ?? '')],
            };

            $operator = trim((string) ($match[4] ?? ''));
            $concatenated = $operator === ($php ? '.' : '+');
            $value = $quote === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $raw) : stripcslashes($raw);

            // `"status.{$state}"`, `"status.$state"`, `` `status.${state}` ``: a prefix.
            $dynamic = match ($quote) {
                '"' => $php ? preg_match('/(?<!\\\\)(\{\$|\$[A-Za-z_])/', $raw, $position, PREG_OFFSET_CAPTURE) : 0,
                '`' => preg_match('/(?<!\\\\)\$\{/', $raw, $position, PREG_OFFSET_CAPTURE),
                default => 0,
            };

            if ($dynamic === 1 && isset($position[0][1])) {
                $found[] = [stripcslashes(substr($raw, 0, (int) $position[0][1])), true];
            } elseif ($concatenated) {
                $found[] = [$value, true];
            } elseif ($value !== '' && mb_strlen($value) <= 2000) {
                $found[] = [$value, false];
            }
        }

        return $found;
    }

    /**
     * @return iterable<array{0: string, 1: string}> the file and the scanned path it is in
     */
    protected function files(): iterable
    {
        $extensions = array_map(strtolower(...), $this->extensions);

        foreach ($this->paths as $path) {
            if (is_file($path)) {
                yield [$path, dirname($path)];

                continue;
            }

            if (! is_dir($path)) {
                continue;
            }

            $directories = new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                static fn (SplFileInfo $file): bool => ! ($file->isDir() && in_array($file->getFilename(), ['node_modules', 'vendor', '.git', 'storage', 'dist', 'build'], true)),
            );

            foreach (new RecursiveIteratorIterator($directories) as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && in_array(strtolower($file->getExtension()), $extensions, true)) {
                    yield [$file->getPathname(), $path];
                }
            }
        }
    }

    /**
     * The file relative to the project, or to the scanned path outside of it.
     */
    protected function relative(string $file, string $root): string
    {
        foreach ([base_path(), dirname(rtrim($root, '/\\'))] as $base) {
            $base = rtrim($base, '/\\').DIRECTORY_SEPARATOR;

            if (str_starts_with($file, $base) && $base !== DIRECTORY_SEPARATOR) {
                return str_replace('\\', '/', substr($file, strlen($base)));
            }
        }

        return $file;
    }
}
