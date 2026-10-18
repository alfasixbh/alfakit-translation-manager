<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTranslationManager\Commands;

use AlfaSix\AlfakitTranslationManager\Support\Catalog;
use AlfaSix\AlfakitTranslationManager\Support\TranslationKey;
use AlfaSix\AlfakitTranslationManager\TranslationManager;
use Illuminate\Console\Command;

/**
 * Scan the code for translation keys and report the ones no lang file defines (missing)
 * and the ones the code never uses (unused). The result also feeds the editor's filters.
 */
class ScanCommand extends Command
{
    protected $signature = 'alfakit-translations:scan
        {--missing : List the keys the code uses that the base locale lacks}
        {--unused : List the keys the code never uses}
        {--json : Print the result as JSON}
        {--fail-on-missing : Exit with an error when keys are missing (for CI)}';

    protected $description = 'Find missing and unused translation keys in the code';

    public function handle(TranslationManager $manager): int
    {
        $scan = $manager->scanner()->scan();
        $catalog = new Catalog($manager, $manager->discoverLocales(), $manager->baseLocale(), $scan);
        $base = $manager->baseLocale();

        $missing = [];
        $unused = [];

        foreach ($catalog->keys() as $key) {
            if ($catalog->isUndefined($key) || $catalog->value($key, $base) === null && $catalog->isUsed($key) === true) {
                $missing[] = $key->label();
            } elseif ($catalog->isUsed($key) === false) {
                $unused[] = $key->label();
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'files' => $scan['files'],
                'used' => count($scan['keys']),
                'prefixes' => $scan['prefixes'],
                'missing' => $missing,
                'unused' => $unused,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->info(sprintf('Scanned %d files: %d keys used, %d missing in [%s], %d unused.', $scan['files'], count($scan['keys']), count($missing), $base, count($unused)));

            if ($this->option('missing') && $missing !== []) {
                $this->table(['Missing key', 'Used in'], array_map(fn (string $label): array => [$label, implode(', ', array_slice($scan['keys'][$label] ?? [], 0, 3))], $missing));
            }

            if ($this->option('unused') && $unused !== []) {
                $this->table(['Unused key', 'Group'], array_map(static fn (string $label): array => [$label, Catalog::groupLabel(TranslationKey::parse($label))], $unused));
            }
        }

        return $this->option('fail-on-missing') && $missing !== [] ? self::FAILURE : self::SUCCESS;
    }
}
