<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTranslationManager\Commands;

use AlfaSix\AlfakitTranslationManager\TranslationManager;
use Illuminate\Console\Command;

/**
 * Write the database overrides to the lang files (backing up every overwritten file) and
 * remove them from the database.
 */
class ExportCommand extends Command
{
    protected $signature = 'alfakit-translations:export {--locale=* : Only these locales}';

    protected $description = 'Write the translations edited in the panel to the lang files';

    public function handle(TranslationManager $manager): int
    {
        /** @var list<string> $locales */
        $locales = array_values(array_filter((array) $this->option('locale'), is_string(...)));
        $result = $manager->export($locales === [] ? null : $locales);

        $this->components->info(sprintf('Wrote %d lines to %d files.', $result['lines'], count($result['files'])));

        if ($result['backup'] !== null) {
            $this->components->twoColumnDetail('Backup', $result['backup']);
        }

        return self::SUCCESS;
    }
}
