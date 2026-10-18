<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTranslationManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A locale added in the panel.
 *
 * @property int $id
 * @property string $code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TranslationLocale extends Model
{
    protected $table = 'alfakit_translation_locales';

    protected $guarded = [];
}
