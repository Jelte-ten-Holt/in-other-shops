<?php

declare(strict_types=1);

namespace InOtherShops\Logging\Contracts;

use InOtherShops\Logging\DTOs\LogEntry;

interface LogHandler
{
    public function handle(LogEntry $entry): void;
}
