<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTranslationManager\Ai;

use AlfaSix\AlfakitTranslationManager\Contracts\MachineTranslator;
use AlfaSix\AlfakitTranslationManager\Support\Catalog;
use ArrayAccess;

/**
 * Translates with laravel/ai: one structured prompt per batch of lines.
 */
class LaravelAiTranslator implements MachineTranslator
{
    public function __construct(
        protected ?string $provider = null,
        protected ?string $model = null,
        protected int $timeout = 120,
    ) {}

    public static function isAvailable(): bool
    {
        return interface_exists(\Laravel\Ai\Contracts\Agent::class) && trait_exists(\Laravel\Ai\Promptable::class);
    }

    public function translate(array $lines, string $from, string $to, array $context = []): array
    {
        if ($lines === []) {
            return [];
        }

        $items = [];

        foreach ($lines as $id => $text) {
            $items[] = ['id' => (string) $id, 'key' => $context[$id] ?? null, 'text' => $text];
        }

        $prompt = sprintf(
            "Source language: %s (%s)\nTarget language: %s (%s)\nItems (JSON):\n%s",
            Catalog::localeLabel($from),
            $from,
            Catalog::localeLabel($to),
            $to,
            json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        );

        $response = TranslationAgent::make()->prompt($prompt, provider: $this->provider, model: $this->model, timeout: $this->timeout);
        $translations = $response instanceof ArrayAccess ? ($response['translations'] ?? []) : [];
        $result = [];

        foreach (is_iterable($translations) ? $translations : [] as $translation) {
            if (is_array($translation) && isset($translation['id'], $translation['text']) && is_string($translation['text']) && array_key_exists((string) $translation['id'], $lines)) {
                $result[(string) $translation['id']] = $translation['text'];
            }
        }

        return $result;
    }
}
