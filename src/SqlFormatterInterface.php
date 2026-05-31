<?php

declare(strict_types=1);

namespace Koriym\CsSql;

interface SqlFormatterInterface
{
    public function format(string $sql): string;
}
