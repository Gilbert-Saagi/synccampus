<?php
declare(strict_types=1);

namespace SyncCampus;

use RuntimeException;

/** Thrown anywhere in a request to stop and send {"detail": message} with a status code. */
final class HttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
