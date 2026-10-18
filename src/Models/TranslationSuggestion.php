<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTranslationManager\Models;

use AlfaSix\AlfakitTranslationManager\Support\TranslationKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A machine translation waiting for review. It is never used by the application until
 * someone accepts it in the editor.
 *
 * @property int $id
 * @property string $scope
 * @property string $locale
 * @property string $namespace
 * @property string $group
 * @property string $key
 * @property string $key_hash
 * @property string $value
 * @property string $source
 * @property list<string>|null $warnings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TranslationSuggestion extends Model
{
    protected $table = 'alfakit_translation_suggestions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['warnings' => 'array'];
    }

    public function translationKey(): TranslationKey
    {
        return new TranslationKey($this->namespace, $this->group, $this->key);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForKey(Builder $query, TranslationKey $key): Builder
    {
        return $query->where('namespace', $key->namespace)->where('group', $key->group)->where('key_hash', $key->hash());
    }
}
