<?php

declare(strict_types=1);

namespace Koriym\CsSql;

use Koriym\CsSql\Cli\FormatCommand;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function fopen;
use function is_dir;
use function is_file;
use function mkdir;
use function rewind;
use function rmdir;
use function stream_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const DIRECTORY_SEPARATOR;

final class FormatCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $directory = tempnam(sys_get_temp_dir(), 'cs-sql-');
        $this->assertIsString($directory);
        unlink($directory);
        mkdir($directory);

        $this->directory = $directory;
    }

    protected function tearDown(): void
    {
        $orders = $this->directory . DIRECTORY_SEPARATOR . 'queries' . DIRECTORY_SEPARATOR . 'orders.SQL';
        if (is_file($orders)) {
            unlink($orders);
        }

        $queries = $this->directory . DIRECTORY_SEPARATOR . 'queries';
        if (is_dir($queries)) {
            rmdir($queries);
        }

        foreach (['clean.sql', 'needs-format.sql', 'users.sql', 'README.txt'] as $filename) {
            $file = $this->directory . DIRECTORY_SEPARATOR . $filename;
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testFormatsSqlFilesRecursivelyInPlace(): void
    {
        mkdir($this->directory . DIRECTORY_SEPARATOR . 'queries');
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'users.sql', 'select id, name from users');
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'queries' . DIRECTORY_SEPARATOR . 'orders.SQL', 'select * from orders where total >= 10');
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'README.txt', 'select untouched from docs');

        $output = fopen('php://memory', 'w+');
        $error = fopen('php://memory', 'w+');
        $this->assertIsResource($output);
        $this->assertIsResource($error);

        $exitCode = (new FormatCommand(null, $output, $error))->run(['cs-sql', $this->directory]);

        rewind($output);
        rewind($error);

        $this->assertSame(0, $exitCode);
        $this->assertSame("Formatted 2 SQL files, changed 2.\n", stream_get_contents($output));
        $this->assertSame('', stream_get_contents($error));
        $this->assertSame("SELECT\n    id,\n    name\nFROM\n    users\n", file_get_contents($this->directory . DIRECTORY_SEPARATOR . 'users.sql'));
        $this->assertSame("SELECT *\nFROM\n    orders\nWHERE\n    total >= 10\n", file_get_contents($this->directory . DIRECTORY_SEPARATOR . 'queries' . DIRECTORY_SEPARATOR . 'orders.SQL'));
        $this->assertSame('select untouched from docs', file_get_contents($this->directory . DIRECTORY_SEPARATOR . 'README.txt'));
    }

    public function testCheckModeReturnsSuccessWhenFilesAreFormatted(): void
    {
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'clean.sql', "SELECT id\nFROM\n    users\n");

        $output = fopen('php://memory', 'w+');
        $error = fopen('php://memory', 'w+');
        $this->assertIsResource($output);
        $this->assertIsResource($error);

        $exitCode = (new FormatCommand(null, $output, $error))->run(['cs-sql', '--check', $this->directory]);

        rewind($output);
        rewind($error);

        $this->assertSame(0, $exitCode);
        $this->assertSame("Checked 1 SQL file, 0 need formatting.\n", stream_get_contents($output));
        $this->assertSame('', stream_get_contents($error));
        $this->assertSame("SELECT id\nFROM\n    users\n", file_get_contents($this->directory . DIRECTORY_SEPARATOR . 'clean.sql'));
    }

    public function testCheckModeReturnsFailureWithoutChangingFilesWhenFormattingIsNeeded(): void
    {
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'needs-format.sql', 'select id from users');

        $output = fopen('php://memory', 'w+');
        $error = fopen('php://memory', 'w+');
        $this->assertIsResource($output);
        $this->assertIsResource($error);

        $exitCode = (new FormatCommand(null, $output, $error))->run(['cs-sql', '--check', $this->directory]);

        rewind($output);
        rewind($error);

        $this->assertSame(1, $exitCode);
        $this->assertSame("Checked 1 SQL file, 1 need formatting.\n", stream_get_contents($output));
        $this->assertSame('', stream_get_contents($error));
        $this->assertSame('select id from users', file_get_contents($this->directory . DIRECTORY_SEPARATOR . 'needs-format.sql'));
    }
}
