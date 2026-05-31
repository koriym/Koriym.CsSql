<?php

declare(strict_types=1);

namespace Koriym\CsSql\Cli;

use FilesystemIterator;
use Koriym\CsSql\CsSql;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use UnexpectedValueException;

use function array_slice;
use function count;
use function file_get_contents;
use function file_put_contents;
use function fwrite;
use function implode;
use function is_dir;
use function is_file;
use function is_readable;
use function is_writable;
use function pathinfo;
use function sprintf;
use function str_ends_with;
use function strcasecmp;

use const PATHINFO_EXTENSION;
use const PHP_EOL;
use const STDERR;
use const STDOUT;

final class FormatCommand
{
    private CsSql $formatter;

    /** @var resource */
    private $output;

    /** @var resource */
    private $error;

    /**
     * @param resource|null $output
     * @param resource|null $error
     */
    public function __construct(CsSql|null $formatter = null, $output = null, $error = null)
    {
        $this->formatter = $formatter ?? new CsSql();
        $this->output = $output ?? STDOUT;
        $this->error = $error ?? STDERR;
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $arguments = array_slice($argv, 1);
        if ($arguments === [] || $arguments === ['--help'] || $arguments === ['-h']) {
            $this->writeUsage($arguments === [] ? $this->error : $this->output);

            return $arguments === [] ? 2 : 0;
        }

        $check = false;
        if ($arguments[0] === '--check') {
            $check = true;
            $arguments = array_slice($arguments, 1);
        }

        if (count($arguments) !== 1) {
            fwrite($this->error, 'Expected exactly one file or directory path.' . PHP_EOL);
            $this->writeUsage($this->error);

            return 2;
        }

        $path = $arguments[0];
        if (! is_file($path) && ! is_dir($path)) {
            fwrite($this->error, sprintf('Path not found: %s', $path) . PHP_EOL);

            return 2;
        }

        $files = $this->collectSqlFiles($path);
        $changed = 0;
        foreach ($files as $file) {
            $needsFormatting = $check ? $this->fileNeedsFormatting($file) : $this->formatFile($file);
            if (! $needsFormatting) {
                continue;
            }

            $changed++;
        }

        if ($check) {
            fwrite($this->output, sprintf('Checked %d SQL file%s, %d need formatting.', count($files), count($files) === 1 ? '' : 's', $changed) . PHP_EOL);

            return $changed === 0 ? 0 : 1;
        }

        fwrite($this->output, sprintf('Formatted %d SQL file%s, changed %d.', count($files), count($files) === 1 ? '' : 's', $changed) . PHP_EOL);

        return 0;
    }

    /** @return list<string> */
    private function collectSqlFiles(string $path): array
    {
        if (is_file($path)) {
            return $this->isSqlFile($path) ? [$path] : [];
        }

        if (! is_readable($path)) {
            fwrite($this->error, sprintf('Directory is not readable: %s', $path) . PHP_EOL);

            return [];
        }

        $files = [];
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD,
            );
        } catch (UnexpectedValueException) {
            fwrite($this->error, sprintf('Failed to read directory: %s', $path) . PHP_EOL);

            return [];
        }

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $filename = $file->getPathname();
            if (! $this->isSqlFile($filename)) {
                continue;
            }

            $files[] = $filename;
        }

        return $files;
    }

    private function isSqlFile(string $path): bool
    {
        return strcasecmp(pathinfo($path, PATHINFO_EXTENSION), 'sql') === 0;
    }

    private function formatFile(string $file): bool
    {
        if (! is_writable($file)) {
            fwrite($this->error, sprintf('File is not writable: %s', $file) . PHP_EOL);

            return false;
        }

        $formatted = $this->formatFileContents($file);
        if ($formatted === null) {
            return false;
        }

        [$sql, $formattedSql] = $formatted;
        if ($formattedSql === $sql) {
            return false;
        }

        if (file_put_contents($file, $formattedSql) === false) {
            fwrite($this->error, sprintf('Failed to write file: %s', $file) . PHP_EOL);

            return false;
        }

        return true;
    }

    private function fileNeedsFormatting(string $file): bool
    {
        $formatted = $this->formatFileContents($file);
        if ($formatted === null) {
            return false;
        }

        [$sql, $formattedSql] = $formatted;

        return $formattedSql !== $sql;
    }

    /** @return array{0: string, 1: string}|null */
    private function formatFileContents(string $file): array|null
    {
        if (! is_readable($file)) {
            fwrite($this->error, sprintf('File is not readable: %s', $file) . PHP_EOL);

            return null;
        }

        $sql = file_get_contents($file);
        if ($sql === false) {
            fwrite($this->error, sprintf('Failed to read file: %s', $file) . PHP_EOL);

            return null;
        }

        $formatted = $this->formatter->format($sql);
        if ($formatted !== '' && ! str_ends_with($formatted, "\n")) {
            $formatted .= "\n";
        }

        return [$sql, $formatted];
    }

    /** @param resource $stream */
    private function writeUsage($stream): void
    {
        fwrite($stream, implode(PHP_EOL, [
            'Usage:',
            '  cs-sql <file-or-directory>',
            '  cs-sql --check <file-or-directory>',
            '',
            'Formats .sql files in place. Directories are processed recursively.',
            '--check verifies formatting without writing files.',
        ]) . PHP_EOL);
    }
}
