<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The override layer: one row per edited key and locale. `scope` is '' for the
        // whole application, or `type:key` of a tenant when the plugin is tenant-scoped.
        Schema::create('alfakit_translations', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 191)->default('');
            $table->string('locale', 32);
            $table->string('namespace', 191)->default('*');
            $table->string('group', 191)->default('*');
            $table->text('key');
            $table->char('key_hash', 40);
            $table->text('value')->nullable();
            $table->nullableMorphs('updated_by');
            $table->timestamps();

            $table->unique(['scope', 'locale', 'namespace', 'group', 'key_hash'], 'alfakit_translations_unique');
            $table->index(['scope', 'locale']);
        });

        // Who changed what: every save, AI acceptance, import and export.
        Schema::create('alfakit_translation_revisions', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 191)->default('');
            $table->string('locale', 32);
            $table->string('namespace', 191)->default('*');
            $table->string('group', 191)->default('*');
            $table->text('key');
            $table->char('key_hash', 40);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('source', 20)->default('editor');
            $table->nullableMorphs('user');
            $table->string('user_name')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['scope', 'locale', 'namespace', 'group', 'key_hash'], 'alfakit_translation_revisions_key');
        });

        // Machine translations waiting for review; never applied until accepted.
        Schema::create('alfakit_translation_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 191)->default('');
            $table->string('locale', 32);
            $table->string('namespace', 191)->default('*');
            $table->string('group', 191)->default('*');
            $table->text('key');
            $table->char('key_hash', 40);
            $table->text('value');
            $table->string('source', 20)->default('ai');
            $table->json('warnings')->nullable();
            $table->nullableMorphs('created_by');
            $table->timestamps();

            $table->unique(['scope', 'locale', 'namespace', 'group', 'key_hash'], 'alfakit_translation_suggestions_unique');
        });

        // Locales added in the panel that have no lang files yet.
        Schema::create('alfakit_translation_locales', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alfakit_translation_locales');
        Schema::dropIfExists('alfakit_translation_suggestions');
        Schema::dropIfExists('alfakit_translation_revisions');
        Schema::dropIfExists('alfakit_translations');
    }
};
