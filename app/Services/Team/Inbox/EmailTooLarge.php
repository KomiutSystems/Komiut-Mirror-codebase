<?php

declare(strict_types=1);

namespace App\Services\Team\Inbox;

use RuntimeException;

final class EmailTooLarge extends RuntimeException
{
    public function __construct(string $key)
    {
        parent::__construct("Inbound email {$key} is over the size cap.");
    }
}
