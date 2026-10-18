<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTranslationManager\Contracts;

/**
 * Translates lines for "Translate missing with AI". The lines arrive with their
 * placeholders, HTML tags and plural conditions replaced by markers (`⟦1⟧`) that must be
 * returned unchanged. Bind your own implementation (DeepL, a TMS…) in a service provider:
 *
 * ```php
 * $this->app->bind(MachineTranslator::class, DeepLTranslator::class);
 * ```
 *
 * Without a binding, laravel/ai is used when it is installed.
 */
interface MachineTranslator
{
    /**
     * @param  array<string, string>  $lines  id => text in `$from`
     * @param  array<string, string>  $context  id => the translation key (a hint for the meaning)
     * @return array<string, string> id => text in `$to` (missing ids are skipped)
     */
    public function translate(array $lines, string $from, string $to, array $context = []): array;
}
