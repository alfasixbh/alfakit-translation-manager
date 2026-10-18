<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTranslationManager\Support;

use Closure;
use FilesystemIterator;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Reads and writes an application's lang directory: PHP group files (nested arrays and
 * sub directories such as `lang/en/admin/users.php`), `lang/{locale}.json` and package
 * overrides under `lang/vendor/{namespace}/{locale}/{group}.php`.
 *
 * Writes are atomic (a temporary file in the same directory, then `rename()`), and the
 * previous file is copied to the backup directory first.
 */
class LangFiles
{
    /** Locale codes the files may use: `en`, `pt_BR`, `zh-Hant`, `sr_Latn_RS`. */
    public const LOCALE_PATTERN = '/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8}){0,3}$/';

    /** Group names: file names, optionally inside sub directories. */
    public const GROUP_PATTERN = '#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+)*$#';

    public const NAMESPACE_PATTERN = '/^[A-Za-z0-9_\-.]+$/';

    /** @var array<string, array<string, mixed>> */
    private array $memo = [];

    private ?string $lastBackupDirectory = null;

    /**
     * @param  array<string, string>  $packagePaths  package namespaces to their lang directories
     * @param  list<string>|null  $listedNamespaces  the packages listed with all their lines (null: every package path)
     * @param  (Closure(string, string, string): array<mixed>)|null  $namespaceLoader  loads a package group as the application does
     */
    public function __construct(
        protected string $path,
        protected ?string $backupPath = null,
        protected array $packagePaths = [],
        protected ?array $listedNamespaces = null,
        protected ?Closure $namespaceLoader = null,
    ) {
        $this->path = rtrim($path, '/\\');
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public static function isValidLocale(string $locale): bool
    {
        return preg_match(self::LOCALE_PATTERN, $locale) === 1;
    }

    // ---------------------------------------------------------------- Reading

    /**
     * Locales with a directory or a JSON file (vendor overrides and package files included).
     *
     * @return list<string>
     */
    public function locales(): array
    {
        $locales = [];

        foreach (glob($this->path.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $locales[] = basename($directory);
        }

        foreach (glob($this->path.'/*.json') ?: [] as $file) {
            $locales[] = basename($file, '.json');
        }

        foreach ($this->namespaces() as $namespace) {
            foreach ($this->namespaceDirectories($namespace) as $directory) {
                foreach (glob($directory.'/*', GLOB_ONLYDIR) ?: [] as $localeDirectory) {
                    $locales[] = basename($localeDirectory);
                }
            }
        }

        $locales = array_values(array_unique(array_filter($locales, static fn (string $locale): bool => $locale !== 'vendor' && self::isValidLocale($locale))));
        sort($locales);

        return $locales;
    }

    /**
     * The namespaces with overrides in `lang/vendor` and the configured package namespaces.
     *
     * @return list<string>
     */
    public function namespaces(): array
    {
        $namespaces = $this->listedNamespaces === null ? array_keys($this->packagePaths) : array_values(array_intersect($this->listedNamespaces, array_keys($this->packagePaths)));

        foreach (glob($this->path.'/vendor/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $namespaces[] = basename($directory);
        }

        $namespaces = array_values(array_unique(array_filter($namespaces, static fn (string $namespace): bool => preg_match(self::NAMESPACE_PATTERN, $namespace) === 1)));
        sort($namespaces);

        return $namespaces;
    }

    /**
     * The PHP groups of a locale (`*` namespace) or of a package namespace.
     *
     * @return list<string>
     */
    public function groups(string $locale, string $namespace = TranslationKey::WILDCARD): array
    {
        if (! self::isValidLocale($locale)) {
            return [];
        }

        $directories = $namespace === TranslationKey::WILDCARD
            ? [$this->path.'/'.$locale]
            : array_map(static fn (string $directory): string => $directory.'/'.$locale, $this->namespaceDirectories($namespace));

        $groups = [];

        foreach ($directories as $directory) {
            foreach ($this->phpFiles($directory) as $relative) {
                $groups[] = substr($relative, 0, -4);
            }
        }

        $groups = array_values(array_unique(array_filter($groups, static fn (string $group): bool => preg_match(self::GROUP_PATTERN, $group) === 1)));
        sort($groups);

        return $groups;
    }

    /**
     * A group's lines (nested), as Laravel loads them: for package namespaces, the package
     * file with the `lang/vendor` override on top.
     *
     * @return array<string, mixed>
     */
    public function readGroup(string $locale, string $group, string $namespace = TranslationKey::WILDCARD): array
    {
        if (! self::isValidLocale($locale) || preg_match(self::GROUP_PATTERN, $group) !== 1) {
            return [];
        }

        $memo = "{$namespace}\0{$locale}\0{$group}";

        if (isset($this->memo[$memo])) {
            return $this->memo[$memo];
        }

        if ($namespace === TranslationKey::WILDCARD) {
            return $this->memo[$memo] = $this->requireArray($this->path."/{$locale}/{$group}.php");
        }

        // The application's own loader knows every directory of a package (language packs
        // included) and applies the `lang/vendor` override.
        if ($this->namespaceLoader !== null) {
            $lines = ($this->namespaceLoader)($locale, $group, $namespace);

            return $this->memo[$memo] = is_array($lines) ? $lines : [];
        }

        $lines = isset($this->packagePaths[$namespace]) ? $this->requireArray($this->packagePaths[$namespace]."/{$locale}/{$group}.php") : [];

        return $this->memo[$memo] = array_replace_recursive($lines, $this->readVendorOverride($locale, $group, $namespace));
    }

    /**
     * Only the `lang/vendor/{namespace}` override file of a package group.
     *
     * @return array<string, mixed>
     */
    public function readVendorOverride(string $locale, string $group, string $namespace): array
    {
        return $this->requireArray($this->path."/vendor/{$namespace}/{$locale}/{$group}.php");
    }

    /**
     * @return array<string, string>
     */
    public function readJson(string $locale): array
    {
        if (! self::isValidLocale($locale)) {
            return [];
        }

        $memo = "*\0{$locale}\0*";

        if (isset($this->memo[$memo])) {
            /** @var array<string, string> */
            return $this->memo[$memo];
        }

        $file = $this->path."/{$locale}.json";
        $lines = [];

        if (is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);

            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    if (is_string($value)) {
                        $lines[(string) $key] = $value;
                    }
                }
            }
        }

        return $this->memo[$memo] = $lines;
    }

    /**
     * Nested group lines as `dotted.key => string` (non-string leaves are skipped).
     *
     * @param  array<mixed>  $lines
     * @return array<string, string>
     */
    public static function flatten(array $lines): array
    {
        $flat = [];

        foreach (Arr::dot($lines) as $key => $value) {
            if (is_string($value)) {
                $flat[(string) $key] = $value;
            } elseif (is_int($value) || is_float($value)) {
                $flat[(string) $key] = (string) $value;
            }
        }

        return $flat;
    }

    /**
     * The value of one key in the files of a locale.
     */
    public function value(TranslationKey $key, string $locale): ?string
    {
        if ($key->isJson()) {
            return $this->readJson($locale)[$key->key] ?? null;
        }

        $value = Arr::get($this->readGroup($locale, $key->group, $key->namespace), $key->key);

        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : null;
    }

    public function forget(): void
    {
        $this->memo = [];
    }

    // ---------------------------------------------------------------- Writing

    /**
     * Set lines of one group or JSON file and write it. `$lines` are `key => value`
     * (dotted keys for PHP groups); null values remove the key.
     *
     * @param  array<string, string|null>  $lines
     * @return string the written file
     */
    public function write(string $locale, string $group, string $namespace, array $lines): string
    {
        $file = $this->fileFor($locale, $group, $namespace);

        if ($group === TranslationKey::WILDCARD) {
            $current = $this->readJson($locale);

            foreach ($lines as $key => $value) {
                if ($value === null) {
                    unset($current[$key]);
                } else {
                    $current[$key] = $value;
                }
            }

            ksort($current, SORT_NATURAL | SORT_FLAG_CASE);
            $contents = $current === [] ? "{}\n" : json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        } else {
            // Package groups only keep the overridden lines in `lang/vendor`.
            $current = $namespace === TranslationKey::WILDCARD ? $this->readGroup($locale, $group) : $this->readVendorOverride($locale, $group, $namespace);

            foreach ($lines as $key => $value) {
                if ($value === null) {
                    Arr::forget($current, $key);
                } else {
                    Arr::set($current, $key, $value);
                }
            }

            $contents = "<?php\n\nreturn ".self::export($current).";\n";
        }

        $this->atomicWrite($file, $contents);
        unset($this->memo["{$namespace}\0{$locale}\0{$group}"]);

        return $file;
    }

    /**
     * Create a locale's directory and an empty JSON file (when they don't exist).
     */
    public function createLocale(string $locale): void
    {
        if (! self::isValidLocale($locale)) {
            throw new InvalidArgumentException("Invalid locale [{$locale}].");
        }

        if (! is_dir($this->path.'/'.$locale) && ! @mkdir($this->path.'/'.$locale, 0755, true) && ! is_dir($this->path.'/'.$locale)) {
            throw new RuntimeException("The directory [{$this->path}/{$locale}] could not be created.");
        }

        if (! is_file($this->path."/{$locale}.json")) {
            $this->atomicWrite($this->path."/{$locale}.json", "{}\n");
        }
    }

    /**
     * The file a group of a locale lives in (validated: no path can leave the lang directory).
     */
    public function fileFor(string $locale, string $group, string $namespace = TranslationKey::WILDCARD): string
    {
        if (! self::isValidLocale($locale)) {
            throw new InvalidArgumentException("Invalid locale [{$locale}].");
        }

        if ($group === TranslationKey::WILDCARD) {
            if ($namespace !== TranslationKey::WILDCARD) {
                throw new InvalidArgumentException('Package namespaces have no JSON files.');
            }

            return $this->path."/{$locale}.json";
        }

        if (preg_match(self::GROUP_PATTERN, $group) !== 1) {
            throw new InvalidArgumentException("Invalid group [{$group}].");
        }

        if ($namespace === TranslationKey::WILDCARD) {
            return $this->path."/{$locale}/{$group}.php";
        }

        if (preg_match(self::NAMESPACE_PATTERN, $namespace) !== 1 || str_contains($namespace, '..')) {
            throw new InvalidArgumentException("Invalid namespace [{$namespace}].");
        }

        return $this->path."/vendor/{$namespace}/{$locale}/{$group}.php";
    }

    /**
     * The backup directory of the last write (null when nothing was backed up).
     */
    public function getLastBackupDirectory(): ?string
    {
        return $this->lastBackupDirectory;
    }

    /**
     * Start a new backup directory (one per export).
     */
    public function startBackup(): void
    {
        $this->lastBackupDirectory = null;
    }

    protected function atomicWrite(string $file, string $contents): void
    {
        $directory = dirname($file);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("The directory [{$directory}] could not be created.");
        }

        if (is_file($file)) {
            if ((string) file_get_contents($file) === $contents) {
                return;
            }

            $this->backup($file);
        }

        $temporary = tempnam($directory, '.alfakit-');

        if ($temporary === false) {
            throw new RuntimeException("A temporary file could not be created in [{$directory}].");
        }

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
                throw new RuntimeException("The file [{$file}] could not be written.");
            }

            @chmod($temporary, is_file($file) ? (fileperms($file) & 0777) : 0644);

            if (! @rename($temporary, $file)) {
                throw new RuntimeException("The file [{$file}] could not be replaced.");
            }
        } catch (Throwable $exception) {
            @unlink($temporary);

            throw $exception;
        }

        if (function_exists('opcache_invalidate') && str_ends_with($file, '.php')) {
            @opcache_invalidate($file, true);
        }
    }

    protected function backup(string $file): void
    {
        if ($this->backupPath === null || $this->backupPath === '') {
            return;
        }

        $this->lastBackupDirectory ??= rtrim($this->backupPath, '/\\').'/'.date('Y-m-d_His').'_'.substr(bin2hex(random_bytes(3)), 0, 6);
        $target = $this->lastBackupDirectory.'/'.ltrim(substr($file, strlen($this->path)), '/\\');

        if (! is_dir(dirname($target)) && ! @mkdir(dirname($target), 0755, true) && ! is_dir(dirname($target))) {
            throw new RuntimeException('The backup directory ['.dirname($target).'] could not be created.');
        }

        if (! @copy($file, $target)) {
            throw new RuntimeException("The backup of [{$file}] could not be written.");
        }
    }

    /**
     * `var_export()` with short arrays and four-space indentation.
     *
     * @param  array<mixed>  $value
     */
    public static function export(array $value, int $depth = 0): string
    {
        if ($value === []) {
            return '[]';
        }

        $indent = str_repeat('    ', $depth + 1);
        $lines = [];

        foreach ($value as $key => $item) {
            $exportedKey = is_int($key) ? (string) $key : var_export((string) $key, true);
            $exportedValue = is_array($item) ? self::export($item, $depth + 1) : var_export(is_scalar($item) || $item === null ? $item : (string) json_encode($item), true);
            $lines[] = $indent.$exportedKey.' => '.$exportedValue.',';
        }

        return "[\n".implode("\n", $lines)."\n".str_repeat('    ', $depth).']';
    }

    /**
     * @return array<string, mixed>
     */
    protected function requireArray(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }

        try {
            $lines = (static fn (string $__file): mixed => require $__file)($file);
        } catch (Throwable) {
            return [];
        }

        return is_array($lines) ? $lines : [];
    }

    /**
     * @return list<string>
     */
    protected function namespaceDirectories(string $namespace): array
    {
        $directories = [];

        if ($this->listedNamespaces === null || in_array($namespace, $this->listedNamespaces, true)) {
            $paths = isset($this->packagePaths[$namespace]) ? [$this->packagePaths[$namespace]] : [];

            // Namespaces with language packs (AlfaKit's `Translations::register()`).
            if (class_exists(\AlfaSix\Alfakit\Localization\Translations::class) && \AlfaSix\Alfakit\Localization\Translations::isManaged($namespace)) {
                $paths = [...$paths, ...\AlfaSix\Alfakit\Localization\Translations::paths($namespace)];
            }

            foreach ($paths as $packagePath) {
                if (is_dir($packagePath)) {
                    $directories[] = rtrim($packagePath, '/\\');
                }
            }
        }

        if (is_dir($this->path.'/vendor/'.$namespace)) {
            $directories[] = $this->path.'/vendor/'.$namespace;
        }

        return $directories;
    }

    /**
     * PHP files under a directory, relative to it (`auth.php`, `admin/users.php`).
     *
     * @return list<string>
     */
    protected function phpFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', ltrim(substr($file->getPathname(), strlen($directory)), '/\\'));
            }
        }

        sort($files);

        return $files;
    }
}
