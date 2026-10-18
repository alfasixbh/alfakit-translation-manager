<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTranslationManager\Models;

use AlfaSix\AlfakitTranslationManager\Support\TranslationKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One line of the database override layer.
 *
 * @property int $id
 * @property string $scope
 * @property string $locale
 * @property string $namespace
 * @property string $group
 * @property string $key
 * @property string $key_hash
 * @property string|null $value
 * @property string|null $updated_by_type
 * @property int|string|null $updated_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Translation extends Model
{
    protected $table = 'alfakit_translations';

    protected $guarded = [];

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
