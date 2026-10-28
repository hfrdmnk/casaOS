<?php

namespace App\Actions\Finance\Import;

use RuntimeException;

/**
 * A file that cannot be imported. Nothing was written.
 */
final class ImportRejected extends RuntimeException
{
    /**
     * @param  list<array{line: int, mapping: string, message: string}>  $errors
     */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
