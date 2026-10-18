<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTranslationManager\Support;

use AlfaSix\AlfakitTranslationManager\Contracts\MachineTranslator;
use AlfaSix\AlfakitTranslationManager\TranslationManager;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * "Translate missing with AI": the missing lines of a locale (optionally one group) go to
 * the machine translator in batches with their placeholders, HTML and plural conditions
 * masked; the results are stored as suggestions for review, never applied directly.
 */
class AiTranslations
{
    public function __construct(
        protected TranslationManager $manager,
        protected MachineTranslator $translator,
        protected int $batchSize = 25,
    ) {}

    /**
     * @return array{suggested: int, failed: int, remaining: int}
     */
    public function translateMissing(Catalog $catalog, string $locale, ?string $group = null, int $limit = 200, ?Authenticatable $user = null): array
    {
        $base = $this->manager->baseLocale();
        $pending = [];

        foreach ($catalog->keys() as $key) {
            if (($group !== null && $group !== '' && $key->groupId() !== $group)
                || $catalog->value($key, $locale) !== null
                || $catalog->suggestion($key, $locale) !== null) {
                continue;
            }

            $line = $catalog->value($key, $base);

            if ($line === null || trim($line) === '') {
                continue;
            }

            $pending[$key->index()] = [$key, $line];
        }

        $remaining = max(0, count($pending) - $limit);
        $pending = array_slice($pending, 0, max(0, $limit), true);
        $suggested = 0;
        $failed = 0;

        foreach (array_chunk($pending, max(1, $this->batchSize), true) as $batch) {
            $lines = [];
            $context = [];
            $markers = [];

            foreach (array_values($batch) as $position => [$key, $line]) {
                $id = 'l'.($position + 1);
                [$lines[$id], $markers[$id]] = Placeholders::mask($line);
                $context[$id] = $key->label();
            }

            $translated = $this->translator->translate($lines, $base, $locale, $context);

            foreach (array_values($batch) as $position => [$key, $line]) {
                $id = 'l'.($position + 1);
                $text = isset($translated[$id]) ? Placeholders::unmask(trim($translated[$id]), $markers[$id]) : null;

                if ($text === null || $text === '') {
                    $failed++;

                    continue;
                }

                $this->manager->suggest($key, $locale, $text, Placeholders::warnings($line, $text, $locale), TranslationManager::SOURCE_AI, $user);
                $suggested++;
            }
        }

        return ['suggested' => $suggested, 'failed' => $failed, 'remaining' => $remaining];
    }
}
