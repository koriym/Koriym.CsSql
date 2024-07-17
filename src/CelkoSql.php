<?php

declare(strict_types=1);

namespace Koriym\CelkoSql;

use function in_array;
use function preg_split;
use function str_repeat;
use function strlen;
use function strrpos;
use function strtoupper;

use const PREG_SPLIT_DELIM_CAPTURE;
use const PREG_SPLIT_NO_EMPTY;

interface SqlFormatterInterface
{
    public function format(string $sql, array $options = []): string;
}

class CelkoSql implements SqlFormatterInterface
{
    private $indentation = '    ';
    private $uppercase = false;
    private $lineBreakThreshold = 80;

    public function format(string $sql, array $options = []): string
    {
        $this->applyOptions($options);

        $tokens = $this->tokenize($sql);
        $formattedSql = $this->formatTokens($tokens);

        return $this->uppercase ? strtoupper($formattedSql) : $formattedSql;
    }

    private function applyOptions(array $options): void
    {
        $this->indentation = $options['indentation'] ?? $this->indentation;
        $this->uppercase = $options['uppercase'] ?? $this->uppercase;
        $this->lineBreakThreshold = $options['lineBreakThreshold'] ?? $this->lineBreakThreshold;
    }

    private function tokenize(string $sql): array
    {
        $pattern = '/\s+|(\(|\))|([,;])|(\bSELECT\b|\bFROM\b|\bWHERE\b|\bAND\b|\bOR\b|\bORDER BY\b|\bGROUP BY\b|\bHAVING\b|\bLIMIT\b)/i';

        return preg_split($pattern, $sql, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
    }

    private function formatTokens(array $tokens): string
    {
        $result = '';
        $indentLevel = 0;
        $newLine = true;

        foreach ($tokens as $token) {
            $upperToken = strtoupper($token);

            if (in_array($upperToken, ['SELECT', 'FROM', 'WHERE', 'ORDER BY', 'GROUP BY', 'HAVING', 'LIMIT'])) {
                $result .= $newLine ? str_repeat($this->indentation, $indentLevel) : ' ';
                $result .= $token . "\n";
                $newLine = true;
                if ($upperToken === 'SELECT') {
                    $indentLevel++;
                }
            } elseif ($token === '(') {
                $result .= $token . "\n";
                $indentLevel++;
                $newLine = true;
            } elseif ($token === ')') {
                $indentLevel--;
                $result .= $newLine ? str_repeat($this->indentation, $indentLevel) : ' ';
                $result .= $token;
                $newLine = false;
            } elseif ($token === ',') {
                $result .= $token . "\n";
                $newLine = true;
            } else {
                $result .= $newLine ? str_repeat($this->indentation, $indentLevel) : ' ';
                $result .= $token;
                $newLine = false;
            }

            if (strlen($result) - strrpos($result, "\n") > $this->lineBreakThreshold) {
                $result .= "\n";
                $newLine = true;
            }
        }

        return $result;
    }
}
