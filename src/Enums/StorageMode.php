<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTranslationManager\Enums;

/**
 * Where edits made in the panel are stored.
 */
enum StorageMode: string
{
    /** An override layer in the database, applied instantly through the translation loader. */
    case Database = 'database';

    /** Written straight to the lang files (no override layer). */
    case Files = 'files';

    /** Saved in the database (applies instantly) and written to the lang files. */
    case Both = 'both';

    public function usesDatabase(): bool
    {
        return $this !== self::Files;
    }

    public function writesFiles(): bool
    {
        return $this !== self::Database;
    }
}
