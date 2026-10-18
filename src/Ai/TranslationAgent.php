<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTranslationManager\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * The laravel/ai agent behind `LaravelAiTranslator`. Only loaded when laravel/ai is
 * installed. Fake it in tests with `TranslationAgent::fake([...])`.
 */
class TranslationAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'TEXT'
            You translate user interface strings of a web application.
            Rules:
            - Translate every item's "text" from the source language to the target language. Use the item's "key" only to understand the context.
            - Keep every marker like ⟦1⟧ exactly once and unchanged; they stand for placeholders, HTML tags and plural conditions. Move them where the grammar of the target language needs them.
            - A text containing "|" is pluralised: return the plural forms the target language needs, separated by "|", in the order of its plural rules. Keep the markers at the start of a form at the start of that form.
            - Keep the tone, punctuation style and capitalisation of a user interface. Do not add explanations, quotes or notes.
            - Return every item with its original "id".
            TEXT;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'translations' => $schema->array()->items($schema->object([
                'id' => $schema->string()->required(),
                'text' => $schema->string()->required(),
            ]))->required(),
        ];
    }
}
