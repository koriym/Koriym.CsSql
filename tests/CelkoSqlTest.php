<?php

declare(strict_types=1);

namespace Koriym\CelkoSql;

use PHPUnit\Framework\TestCase;

class CelkoSqlTest extends TestCase
{
    protected CelkoSql $celkoSql;

    protected function setUp(): void
    {
        $this->celkoSql = new CelkoSql();
    }

    public function testIsInstanceOfCelkoSql(): void
    {
        $actual = $this->celkoSql;
        $this->assertInstanceOf(CelkoSql::class, $actual);
    }
}
