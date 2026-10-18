<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTranslationManager\Commands;

use AlfaSix\AlfakitTranslationManager\TranslationManager;
use Illuminate\Console\Command;

/**
 * Copy the lines of the lang files into the database.
 */
class ImportCommand extends Command
{
    protected $signature = 'alfakit-translations:import
        {--locale=* : Only these locales}
        {--overwrite : Replace lines that already have a database value}';

    protected $description = 'Copy the lang files into the translations database';

    public function handle(TranslationManager $manager): int
    {
        /** @var list<string> $locales */
        $locales = array_values(array_filter((array) $this->option('locale'), is_string(...)));
        $count = $manager->import($locales === [] ? null : $locales, (bool) $this->option('overwrite'));

        $this->components->info(sprintf('Imported %d lines.', $count));

        return self::SUCCESS;
    }
}
