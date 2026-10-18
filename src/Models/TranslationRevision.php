<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTranslationManager\Models;

use AlfaSix\AlfakitTranslationManager\Support\TranslationKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One change of a key in a locale: who, when, from what to what, and how (`editor`, `ai`,
 * `import`, `export`).
 *
 * @property int $id
 * @property string $scope
 * @property string $locale
 * @property string $namespace
 * @property string $group
 * @property string $key
 * @property string $key_hash
 * @property string|null $old_value
 * @property string|null $new_value
 * @property string $source
 * @property string|null $user_type
 * @property int|string|null $user_id
 * @property string|null $user_name
 * @property Carbon|null $created_at
 */
class TranslationRevision extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'alfakit_translation_revisions';

    protected $guarded = [];

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForKey(Builder $query, TranslationKey $key): Builder
    {
        return $query->where('namespace', $key->namespace)->where('group', $key->group)->where('key_hash', $key->hash());
    }
}
