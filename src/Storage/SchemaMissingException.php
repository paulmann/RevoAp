<?php
/**
 * SchemaMissingException: typed signal that a required table is absent from
 * the live database even after the repository attempted to auto-create it.
 *
 * Thrown by ShareRepository (and similar storage classes) so the HTTP layer
 * can surface a precise, actionable diagnostic (HTTP 409 migration_required)
 * instead of an opaque 500.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-13
 */
declare(strict_types=1);

namespace App\Storage;

use RuntimeException;
use Throwable;

final class SchemaMissingException extends RuntimeException
{
    public function __construct(
        public readonly string $table,
        public readonly string $migration = '',
        string $message = '',
        ?Throwable $previous = null,
    ) {
        if ($message === '') {
            $message = sprintf(
                'Required table "%s" is missing from the live database%s.',
                $table,
                $migration !== '' ? ' (apply ' . $migration . ')' : ''
            );
        }
        parent::__construct($message, 0, $previous);
    }
}
