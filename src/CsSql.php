<?php

declare(strict_types=1);

namespace Koriym\CsSql;

use function array_pop;
use function array_slice;
use function count;
use function explode;
use function implode;
use function in_array;
use function preg_match;
use function rtrim;
use function str_ends_with;
use function str_repeat;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtoupper;
use function substr;

/**
 * @psalm-type Token = array{text: string, type: string}
 * @phpstan-type Token array{text: string, type: string}
 */
final class CsSql implements SqlFormatterInterface
{
    private const TYPE_WORD = 'word';
    private const TYPE_NUMBER = 'number';
    private const TYPE_STRING = 'string';
    private const TYPE_QUOTED_IDENTIFIER = 'quoted_identifier';
    private const TYPE_BRACKET_IDENTIFIER = 'bracket_identifier';
    private const TYPE_COMMENT = 'comment';
    private const TYPE_SYMBOL = 'symbol';
    private const TYPE_OPERATOR = 'operator';
    private const INDENTATION = '    ';

    private const CLAUSE_PHRASES = [
        'FROM',
        'WHERE',
        'GROUP BY',
        'HAVING',
        'ORDER BY',
        'LIMIT',
        'INNER JOIN',
        'LEFT JOIN',
        'LEFT OUTER JOIN',
        'RIGHT JOIN',
        'RIGHT OUTER JOIN',
        'FULL JOIN',
        'FULL OUTER JOIN',
        'JOIN',
    ];

    private const FUNCTION_LIKE_WORDS = [
        'AVG',
        'CONCAT',
        'COUNT',
        'DATE_SUB',
        'COALESCE',
        'JSON_UNQUOTE',
        'NOW',
        'ROUND',
        'ROW_NUMBER',
        'SUM',
        'VARCHAR',
    ];

    private const UPPERCASE_WORDS = [
        'ADD',
        'ALTER',
        'AND',
        'AS',
        'BEGIN',
        'BETWEEN',
        'BY',
        'CASE',
        'COLUMN',
        'COMMIT',
        'CREATE',
        'CURRENT_TIMESTAMP',
        'DEFAULT',
        'DELETE',
        'DESC',
        'DROP',
        'EACH',
        'ELSE',
        'END',
        'FOR',
        'FROM',
        'FULL',
        'GROUP',
        'HAVING',
        'IN',
        'INDEX',
        'INNER',
        'INSERT',
        'INTERVAL',
        'INTO',
        'JOIN',
        'KEY',
        'LEFT',
        'LIMIT',
        'MATCHED',
        'MERGE',
        'MODIFY',
        'NOT',
        'NULL',
        'ON',
        'OR',
        'ORDER',
        'OUTER',
        'OVER',
        'PARTITION',
        'PRECEDING',
        'PRIMARY',
        'PROCEDURE',
        'RECURSIVE',
        'RIGHT',
        'ROLLBACK',
        'ROW',
        'ROWS',
        'SELECT',
        'SET',
        'START',
        'THEN',
        'TRANSACTION',
        'TRIGGER',
        'UNION',
        'UNIQUE',
        'UPDATE',
        'USING',
        'VALUES',
        'WHEN',
        'WHERE',
        'WITH',
        'YEAR',
    ];

    public function format(string $sql): string
    {
        $statements = $this->splitStatements($this->tokenize($sql));
        $formatted = [];
        foreach ($statements as $statement) {
            if ($statement === []) {
                continue;
            }

            $formatted[] = $this->formatStatement($statement, 0);
        }

        return $this->trimLines(implode("\n\n", $formatted));
    }

    /** @return list<Token> */
    private function tokenize(string $sql): array
    {
        $tokens = [];
        $length = strlen($sql);
        $offset = 0;

        while ($offset < $length) {
            $char = $sql[$offset];

            if ($char === ' ' || $char === "\n" || $char === "\r" || $char === "\t") {
                $offset++;
                continue;
            }

            if (substr($sql, $offset, 2) === '--') {
                $end = strpos($sql, "\n", $offset);
                $text = $end === false ? substr($sql, $offset) : substr($sql, $offset, $end - $offset);
                $tokens[] = ['text' => $text, 'type' => self::TYPE_COMMENT];
                $offset += strlen($text);
                continue;
            }

            if ($char === '#') {
                $end = strpos($sql, "\n", $offset);
                $text = $end === false ? substr($sql, $offset) : substr($sql, $offset, $end - $offset);
                $tokens[] = ['text' => $text, 'type' => self::TYPE_COMMENT];
                $offset += strlen($text);
                continue;
            }

            if (substr($sql, $offset, 2) === '/*') {
                $end = strpos($sql, '*/', $offset + 2);
                $text = $end === false ? substr($sql, $offset) : substr($sql, $offset, $end - $offset + 2);
                $tokens[] = ['text' => $text, 'type' => self::TYPE_COMMENT];
                $offset += strlen($text);
                continue;
            }

            if ($char === '\'' || $char === '"' || $char === '`') {
                $tokens[] = $this->readQuotedToken($sql, $offset, $char);
                $offset += strlen($tokens[count($tokens) - 1]['text']);
                continue;
            }

            if ($char === '[') {
                $end = strpos($sql, ']', $offset + 1);
                $text = $end === false ? '[' : substr($sql, $offset, $end - $offset + 1);
                $tokens[] = ['text' => $text, 'type' => self::TYPE_BRACKET_IDENTIFIER];
                $offset += strlen($text);
                continue;
            }

            $twoChars = substr($sql, $offset, 2);
            if (in_array($twoChars, ['>=', '<=', '<>', '!=', '||', '::', '->', '=>'], true)) {
                $tokens[] = ['text' => $twoChars, 'type' => self::TYPE_OPERATOR];
                $offset += 2;
                continue;
            }

            if (preg_match('/\G[A-Za-z_][A-Za-z0-9_$]*/', $sql, $matches, 0, $offset) === 1) {
                $tokens[] = ['text' => $matches[0], 'type' => self::TYPE_WORD];
                $offset += strlen($matches[0]);
                continue;
            }

            if (preg_match('/\G[0-9]+(?:\.[0-9]+)?/', $sql, $matches, 0, $offset) === 1) {
                $tokens[] = ['text' => $matches[0], 'type' => self::TYPE_NUMBER];
                $offset += strlen($matches[0]);
                continue;
            }

            $tokens[] = [
                'text' => $char,
                'type' => in_array($char, ['+', '-', '*', '/', '=', '<', '>'], true) ? self::TYPE_OPERATOR : self::TYPE_SYMBOL,
            ];
            $offset++;
        }

        return $tokens;
    }

    /** @return Token */
    private function readQuotedToken(string $sql, int $offset, string $quote): array
    {
        $length = strlen($sql);
        $index = $offset + 1;

        while ($index < $length) {
            if ($sql[$index] === '\\') {
                $index += 2;
                continue;
            }

            if ($sql[$index] === $quote) {
                if ($quote === '\'' && $index + 1 < $length && $sql[$index + 1] === '\'') {
                    $index += 2;
                    continue;
                }

                $index++;
                break;
            }

            $index++;
        }

        return [
            'text' => substr($sql, $offset, $index - $offset),
            'type' => $quote === '\'' ? self::TYPE_STRING : self::TYPE_QUOTED_IDENTIFIER,
        ];
    }

    /**
     * @param list<Token> $tokens
     *
     * @return list<list<Token>>
     */
    private function splitStatements(array $tokens): array
    {
        $statements = [];
        $statement = [];
        $depth = 0;
        $blockDepth = 0;

        foreach ($tokens as $token) {
            $text = $this->upperText($token);
            if ($token['text'] === '(') {
                $depth++;
            } elseif ($token['text'] === ')') {
                $depth--;
            } elseif ($depth === 0 && $text === 'BEGIN') {
                $blockDepth++;
            } elseif ($depth === 0 && $text === 'END' && $blockDepth > 0) {
                $blockDepth--;
            }

            $statement[] = $token;
            if ($token['text'] === ';' && $depth === 0 && $blockDepth === 0) {
                $statements[] = $statement;
                $statement = [];
            }
        }

        if ($statement !== []) {
            $statements[] = $statement;
        }

        return $statements;
    }

    /** @param list<Token> $tokens */
    private function formatStatement(array $tokens, int $indent): string
    {
        $hasSemicolon = $tokens !== [] && $tokens[count($tokens) - 1]['text'] === ';';
        if ($hasSemicolon) {
            array_pop($tokens);
        }

        if ($tokens === []) {
            return $hasSemicolon ? ';' : '';
        }

        if ($this->matchPhrase($tokens, 0, ['WITH'])) {
            $formatted = $this->formatWith($tokens, $indent);
        } elseif ($this->hasTopLevelUnion($tokens)) {
            $formatted = $this->formatUnion($tokens, $indent);
        } elseif ($this->matchPhrase($tokens, 0, ['SELECT'])) {
            $formatted = $this->formatSelect($tokens, $indent);
        } elseif ($this->matchPhrase($tokens, 0, ['INSERT'])) {
            $formatted = $this->formatInsert($tokens, $indent);
        } elseif ($this->matchPhrase($tokens, 0, ['UPDATE'])) {
            $formatted = $this->formatUpdate($tokens, $indent);
        } elseif ($this->matchPhrase($tokens, 0, ['DELETE'])) {
            $formatted = $this->formatDelete($tokens, $indent);
        } elseif ($this->matchPhrase($tokens, 0, ['CREATE', 'PROCEDURE'])) {
            $formatted = $this->formatProcedure($tokens, $indent);
        } elseif ($this->matchPhrase($tokens, 0, ['CREATE', 'TRIGGER'])) {
            $formatted = $this->formatTrigger($tokens, $indent);
        } elseif ($this->matchPhrase($tokens, 0, ['CREATE', 'TABLE'])) {
            $formatted = $this->formatCreateTable($tokens, $indent);
        } elseif ($this->matchPhrase($tokens, 0, ['ALTER', 'TABLE'])) {
            $formatted = $this->formatAlterTable($tokens, $indent);
        } elseif ($this->matchPhrase($tokens, 0, ['MERGE'])) {
            $formatted = $this->formatMerge($tokens, $indent);
        } else {
            $formatted = $this->indent($indent) . $this->inlineTokens($tokens);
        }

        return $hasSemicolon ? $formatted . ';' : $formatted;
    }

    /** @param list<Token> $tokens */
    private function formatWith(array $tokens, int $indent): string
    {
        $open = $this->findToken($tokens, '(');
        if ($open === null) {
            return $this->indent($indent) . $this->inlineTokens($tokens);
        }

        $close = $this->findMatchingParen($tokens, $open);
        if ($close === null) {
            return $this->indent($indent) . $this->inlineTokens($tokens);
        }

        $lines = [];
        $lines[] = $this->indent($indent) . $this->inlineTokens(array_slice($tokens, 0, $open)) . ' (';
        $lines[] = $this->formatStatement(array_slice($tokens, $open + 1, $close - $open - 1), $indent + 1);
        $lines[] = $this->indent($indent) . ')';

        $tail = array_slice($tokens, $close + 1);
        if ($tail !== []) {
            $lines[] = $this->formatStatement($tail, $indent);
        }

        return implode("\n", $lines);
    }

    /** @param list<Token> $tokens */
    private function formatUnion(array $tokens, int $indent): string
    {
        $parts = [];
        $operators = [];
        $start = 0;
        $depth = 0;

        for ($i = 0; $i < count($tokens); $i++) {
            if ($tokens[$i]['text'] === '(') {
                $depth++;
                continue;
            }

            if ($tokens[$i]['text'] === ')') {
                $depth--;
                continue;
            }

            if ($depth === 0 && $this->matchPhrase($tokens, $i, ['UNION'])) {
                $end = $this->matchPhrase($tokens, $i, ['UNION', 'ALL']) ? $i + 2 : $i + 1;
                $parts[] = array_slice($tokens, $start, $i - $start);
                $operators[] = $this->inlineTokens(array_slice($tokens, $i, $end - $i));
                $start = $end;
                $i = $end - 1;
            }
        }

        $parts[] = array_slice($tokens, $start);
        $lines = [];
        foreach ($parts as $index => $part) {
            if ($index > 0) {
                $lines[] = $this->indent($indent) . $operators[$index - 1];
            }

            $lines[] = $this->formatStatement($part, $indent);
        }

        return implode("\n", $lines);
    }

    /** @param list<Token> $tokens */
    private function formatSelect(array $tokens, int $indent): string
    {
        $clauses = $this->splitSelectClauses($tokens);
        $selectTokens = $clauses[0]['tokens'] ?? [];
        $lines = $this->formatSelectList($selectTokens, $indent);

        foreach ($clauses as $clauseTokens) {
            $name = $clauseTokens['name'];
            $tokens = $clauseTokens['tokens'];
            if ($name === 'SELECT') {
                continue;
            }

            if (str_ends_with($name, 'JOIN')) {
                $lines = [...$lines, ...$this->formatJoin($name, $tokens, $indent)];
                continue;
            }

            if ($name === 'FROM') {
                $lines = [...$lines, ...$this->formatFrom($tokens, $indent)];
                continue;
            }

            if ($name === 'WHERE') {
                $lines = [...$lines, ...$this->formatWhere($tokens, $indent)];
                continue;
            }

            $lines[] = $this->indent($indent) . $name;
            if ($tokens !== []) {
                $lines[] = $this->indent($indent + 1) . $this->inlineTokens($tokens);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<Token> $tokens
     *
     * @return list<string>
     */
    private function formatJoin(string $name, array $tokens, int $indent): array
    {
        $lines = [$this->indent($indent) . $name];
        if ($tokens === []) {
            return $lines;
        }

        $on = $this->findTopLevelPhrase($tokens, ['ON']);
        if ($on === null) {
            $lines[] = $this->indent($indent + 1) . $this->inlineTokens($tokens);

            return $lines;
        }

        $source = array_slice($tokens, 0, $on);
        if ($source !== []) {
            $lines[] = $this->indent($indent + 1) . $this->inlineTokens($source);
        }

        $condition = array_slice($tokens, $on + 1);
        $lines[] = $this->indent($indent + 1) . 'ON' . ($condition === [] ? '' : ' ' . $this->inlineTokens($condition));

        return $lines;
    }

    /**
     * @param list<Token> $tokens
     *
     * @return list<array{name: string, tokens: list<Token>}>
     */
    private function splitSelectClauses(array $tokens): array
    {
        $clauses = [];
        $currentName = 'SELECT';
        $current = [];
        $depth = 0;

        for ($i = 1; $i < count($tokens); $i++) {
            if ($tokens[$i]['text'] === '(') {
                $depth++;
            } elseif ($tokens[$i]['text'] === ')') {
                $depth--;
            }

            $phrase = $depth === 0 && $tokens[$i - 1]['text'] !== ':' ? $this->readClausePhrase($tokens, $i) : null;
            if ($phrase !== null) {
                $clauses[] = ['name' => $currentName, 'tokens' => $current];
                $currentName = $phrase;
                $current = [];
                $i += count(explode(' ', $phrase)) - 1;
                continue;
            }

            $current[] = $tokens[$i];
        }

        $clauses[] = ['name' => $currentName, 'tokens' => $current];

        return $clauses;
    }

    /**
     * @param list<Token> $tokens
     *
     * @return list<string>
     */
    private function formatSelectList(array $tokens, int $indent): array
    {
        if ($tokens === []) {
            return [$this->indent($indent) . 'SELECT'];
        }

        $items = $this->splitByTopLevelComma($tokens);
        if (count($items) === 1 && strlen($this->inlineTokens($items[0])) <= 60) {
            return [$this->indent($indent) . 'SELECT ' . $this->formatSelectItem($items[0], $indent, false)];
        }

        $lines = [$this->indent($indent) . 'SELECT'];
        foreach ($items as $index => $item) {
            $formatted = $this->formatSelectItem($item, $indent + 1, true);
            if ($index < count($items) - 1) {
                $formatted .= ',';
            }

            $lines[] = $formatted;
        }

        return $lines;
    }

    /** @param list<Token> $tokens */
    private function formatSelectItem(array $tokens, int $indent, bool $includeIndent): string
    {
        $subquery = $this->formatParenthesizedSubquery($tokens, $indent);
        if ($subquery !== null) {
            return $subquery;
        }

        if ($this->containsTopLevelWord($tokens, 'CASE')) {
            return $this->formatCaseExpression($tokens, $indent);
        }

        if ($this->containsTopLevelWord($tokens, 'OVER')) {
            $formatted = $this->formatWindowExpression($tokens, $indent);
            if ($formatted !== null) {
                return $formatted;
            }
        }

        if ($this->containsComment($tokens)) {
            return $this->formatCommentedExpression($tokens, $indent);
        }

        return ($includeIndent ? $this->indent($indent) : '') . $this->inlineTokens($tokens);
    }

    /**
     * @param list<Token> $tokens
     *
     * @return list<string>
     */
    private function formatFrom(array $tokens, int $indent): array
    {
        if ($tokens !== [] && $tokens[0]['text'] === '(') {
            $close = $this->findMatchingParen($tokens, 0);
            if ($close !== null) {
                $lines = [$this->indent($indent) . 'FROM', $this->indent($indent + 1) . '('];
                $lines[] = $this->formatStatement(array_slice($tokens, 1, $close - 1), $indent + 2);
                $suffix = array_slice($tokens, $close + 1);
                $lines[] = $this->indent($indent + 1) . ')' . ($suffix === [] ? '' : ' ' . $this->inlineTokens($suffix));

                return $lines;
            }
        }

        return [
            $this->indent($indent) . 'FROM',
            $this->indent($indent + 1) . $this->inlineTokens($tokens),
        ];
    }

    /**
     * @param list<Token> $tokens
     *
     * @return list<string>
     */
    private function formatWhere(array $tokens, int $indent): array
    {
        $parts = [];
        $operators = [];
        $start = 0;
        $depth = 0;
        $betweenDepth = 0;

        for ($i = 0; $i < count($tokens); $i++) {
            $upper = $this->upperText($tokens[$i]);
            if ($tokens[$i]['text'] === '(') {
                $depth++;
            } elseif ($tokens[$i]['text'] === ')') {
                $depth--;
            }

            if ($depth === 0 && $upper === 'BETWEEN') {
                $betweenDepth = 1;
                continue;
            }

            if ($depth === 0 && $betweenDepth === 1 && $upper === 'AND') {
                $betweenDepth = 0;
                continue;
            }

            if ($depth === 0 && $betweenDepth === 0 && in_array($upper, ['AND', 'OR'], true)) {
                $parts[] = array_slice($tokens, $start, $i - $start);
                $operators[] = $this->renderToken($tokens[$i]);
                $start = $i + 1;
            }
        }

        $parts[] = array_slice($tokens, $start);
        $lines = [$this->indent($indent) . 'WHERE'];
        $lines = [...$lines, ...$this->formatConditionLines($parts[0], $indent + 1, '')];
        for ($i = 1; $i < count($parts); $i++) {
            $lines = [...$lines, ...$this->formatConditionLines($parts[$i], $indent + 1, $operators[$i - 1] . ' ')];
        }

        return $lines;
    }

    /**
     * @param list<Token> $tokens
     *
     * @return list<string>
     */
    private function formatConditionLines(array $tokens, int $indent, string $prefix): array
    {
        for ($i = 0; $i < count($tokens); $i++) {
            if ($tokens[$i]['text'] !== '(' || ! $this->matchPhrase($tokens, $i + 1, ['SELECT'])) {
                continue;
            }

            $close = $this->findMatchingParen($tokens, $i);
            if ($close === null) {
                continue;
            }

            $suffix = array_slice($tokens, $close + 1);

            return [
                $this->indent($indent) . $prefix . $this->inlineTokens(array_slice($tokens, 0, $i)) . ' (',
                $this->formatStatement(array_slice($tokens, $i + 1, $close - $i - 1), $indent + 1),
                $this->indent($indent) . ')' . ($suffix === [] ? '' : ' ' . $this->inlineTokens($suffix)),
            ];
        }

        return [$this->indent($indent) . $prefix . $this->inlineTokens($tokens)];
    }

    /** @param list<Token> $tokens */
    private function formatInsert(array $tokens, int $indent): string
    {
        $values = $this->findTopLevelPhrase($tokens, ['VALUES']);
        if ($values === null) {
            return $this->indent($indent) . $this->inlineTokens($tokens);
        }

        $head = array_slice($tokens, 0, $values);
        $lines = [];
        $open = $this->findToken($head, '(');
        if ($open !== null) {
            $close = $this->findMatchingParen($head, $open);
            if ($close !== null) {
                $lines[] = $this->indent($indent) . $this->inlineTokens(array_slice($head, 0, $open)) . ' (';
                foreach ($this->splitByTopLevelComma(array_slice($head, $open + 1, $close - $open - 1)) as $index => $item) {
                    $lines[] = $this->indent($indent + 1) . $this->inlineTokens($item) . ($index === count($this->splitByTopLevelComma(array_slice($head, $open + 1, $close - $open - 1))) - 1 ? '' : ',');
                }

                $lines[] = $this->indent($indent) . ')';
            }
        } else {
            $lines[] = $this->indent($indent) . $this->inlineTokens($head);
        }

        $groups = $this->readParenthesizedGroups(array_slice($tokens, $values + 1));
        foreach ($groups as $index => $group) {
            $lines[] = $this->indent($indent) . ($index === 0 ? 'VALUES (' : '(');
            $items = $this->splitByTopLevelComma($group);
            foreach ($items as $itemIndex => $item) {
                $lines[] = $this->indent($indent + 1) . $this->inlineTokens($item) . ($itemIndex === count($items) - 1 ? '' : ',');
            }

            $lines[] = $this->indent($indent) . ')' . ($index === count($groups) - 1 ? '' : ',');
        }

        return implode("\n", $lines);
    }

    /** @param list<Token> $tokens */
    private function formatUpdate(array $tokens, int $indent): string
    {
        $set = $this->findTopLevelPhrase($tokens, ['SET']);
        if ($set === null) {
            return $this->indent($indent) . $this->inlineTokens($tokens);
        }

        $where = $this->findTopLevelPhrase($tokens, ['WHERE']);
        $lines = [$this->indent($indent) . $this->inlineTokens(array_slice($tokens, 0, $set))];
        $assignments = $this->splitByTopLevelComma(array_slice($tokens, $set + 1, ($where ?? count($tokens)) - $set - 1));
        $lines[] = $this->indent($indent) . 'SET';
        foreach ($assignments as $index => $assignment) {
            $lines[] = $this->indent($indent + 1) . $this->inlineTokens($assignment) . ($index === count($assignments) - 1 ? '' : ',');
        }

        if ($where !== null) {
            $lines = [...$lines, ...$this->formatWhere(array_slice($tokens, $where + 1), $indent)];
        }

        return implode("\n", $lines);
    }

    /** @param list<Token> $tokens */
    private function formatDelete(array $tokens, int $indent): string
    {
        $where = $this->findTopLevelPhrase($tokens, ['WHERE']);
        if ($where === null) {
            return $this->indent($indent) . $this->inlineTokens($tokens);
        }

        return implode("\n", [
            $this->indent($indent) . $this->inlineTokens(array_slice($tokens, 0, $where)),
            ...$this->formatWhere(array_slice($tokens, $where + 1), $indent),
        ]);
    }

    /** @param list<Token> $tokens */
    private function formatCreateTable(array $tokens, int $indent): string
    {
        $open = $this->findToken($tokens, '(');
        if ($open === null) {
            return $this->indent($indent) . $this->inlineTokens($tokens);
        }

        $close = $this->findMatchingParen($tokens, $open);
        if ($close === null) {
            return $this->indent($indent) . $this->inlineTokens($tokens);
        }

        $lines = [$this->indent($indent) . $this->inlineTokens(array_slice($tokens, 0, $open)) . ' ('];
        $columns = $this->splitByTopLevelComma(array_slice($tokens, $open + 1, $close - $open - 1));
        foreach ($columns as $index => $column) {
            $lines[] = $this->indent($indent + 1) . $this->inlineTokens($column) . ($index === count($columns) - 1 ? '' : ',');
        }

        $lines[] = $this->indent($indent) . ')';

        return implode("\n", $lines);
    }

    /** @param list<Token> $tokens */
    private function formatAlterTable(array $tokens, int $indent): string
    {
        $operations = $this->splitByTopLevelComma(array_slice($tokens, 3));
        $lines = [$this->indent($indent) . $this->inlineTokens(array_slice($tokens, 0, 3))];
        foreach ($operations as $index => $operation) {
            $lines[] = $this->indent($indent) . $this->inlineTokens($operation) . ($index === count($operations) - 1 ? '' : ',');
        }

        return implode("\n", $lines);
    }

    /** @param list<Token> $tokens */
    private function formatProcedure(array $tokens, int $indent): string
    {
        return $this->formatBeginEndBlock($tokens, $indent, [], true);
    }

    /** @param list<Token> $tokens */
    private function formatTrigger(array $tokens, int $indent): string
    {
        return $this->formatBeginEndBlock($tokens, $indent, [['BEFORE'], ['FOR', 'EACH', 'ROW']], false);
    }

    /**
     * @param list<Token>        $tokens
     * @param list<list<string>> $headerBreaks
     */
    private function formatBeginEndBlock(array $tokens, int $indent, array $headerBreaks, bool $compactFirstParen): string
    {
        $begin = $this->findTopLevelPhrase($tokens, ['BEGIN']);
        $end = $this->findLastTopLevelPhrase($tokens, ['END']);
        if ($begin === null || $end === null || $end <= $begin) {
            return $this->indent($indent) . $this->inlineTokens($tokens);
        }

        $lines = $this->formatBrokenHeader(array_slice($tokens, 0, $begin), $indent, $headerBreaks, $compactFirstParen);
        $lines[] = $this->indent($indent) . 'BEGIN';

        foreach ($this->splitStatements(array_slice($tokens, $begin + 1, $end - $begin - 1)) as $statement) {
            $lines[] = $this->formatStatement($statement, $indent + 1);
        }

        $tail = array_slice($tokens, $end + 1);
        $lines[] = $this->indent($indent) . 'END' . ($tail === [] ? '' : ' ' . $this->inlineTokens($tail));

        return implode("\n", $lines);
    }

    /**
     * @param list<Token>        $tokens
     * @param list<list<string>> $breaks
     *
     * @return list<string>
     */
    private function formatBrokenHeader(array $tokens, int $indent, array $breaks, bool $compactFirstParen): array
    {
        if ($breaks === []) {
            return [$this->indent($indent) . $this->formatHeaderLine($tokens, $compactFirstParen)];
        }

        $lines = [];
        $tokenCount = count($tokens);
        $current = [];
        for ($index = 0; $index < $tokenCount; $index++) {
            foreach ($breaks as $break) {
                if ($current !== [] && $this->matchPhrase($tokens, $index, $break)) {
                    $lines[] = $this->indent($indent) . $this->formatHeaderLine(
                        $current,
                        $compactFirstParen,
                    );
                    $current = [];
                }
            }

            $current[] = $tokens[$index];
        }

        $lines[] = $this->indent($indent) . $this->formatHeaderLine($current, $compactFirstParen);

        return $lines;
    }

    /** @param list<Token> $tokens */
    private function formatHeaderLine(array $tokens, bool $compactFirstParen): string
    {
        $line = $this->inlineTokens($tokens);
        if (! $compactFirstParen) {
            return $line;
        }

        $position = strpos($line, ' (');
        if ($position === false) {
            return $line;
        }

        return substr($line, 0, $position) . substr($line, $position + 1);
    }

    /** @param list<Token> $tokens */
    private function formatMerge(array $tokens, int $indent): string
    {
        $using = $this->findTopLevelPhrase($tokens, ['USING']);
        $whenMatched = $this->findTopLevelPhrase($tokens, ['WHEN', 'MATCHED']);
        $whenNotMatched = $this->findTopLevelPhrase($tokens, ['WHEN', 'NOT', 'MATCHED']);
        if ($using === null || $whenMatched === null || $whenNotMatched === null) {
            return $this->indent($indent) . $this->inlineTokens($tokens);
        }

        $lines = [
            $this->indent($indent) . $this->inlineTokens(array_slice($tokens, 0, $using)),
            $this->indent($indent) . 'USING ' . $this->inlineTokens(array_slice($tokens, $using + 1, $whenMatched - $using - 1)),
        ];

        $matched = array_slice($tokens, $whenMatched, $whenNotMatched - $whenMatched);
        $then = $this->findTopLevelPhrase($matched, ['THEN']);
        if ($then !== null) {
            $lines[] = $this->indent($indent) . $this->inlineTokens(array_slice($matched, 0, $then + 1));
            $lines[] = $this->indent($indent + 1) . $this->inlineTokens(array_slice($matched, $then + 1));
        }

        $notMatched = array_slice($tokens, $whenNotMatched);
        $then = $this->findTopLevelPhrase($notMatched, ['THEN']);
        if ($then !== null) {
            $lines[] = $this->indent($indent) . $this->inlineTokens(array_slice($notMatched, 0, $then + 1));
            $lines = [...$lines, ...$this->formatMergeInsert(array_slice($notMatched, $then + 1), $indent + 1)];
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<Token> $tokens
     *
     * @return list<string>
     */
    private function formatMergeInsert(array $tokens, int $indent): array
    {
        $values = $this->findTopLevelPhrase($tokens, ['VALUES']);
        if ($values === null) {
            return [$this->indent($indent) . $this->inlineTokens($tokens)];
        }

        $lines = [];
        foreach ([array_slice($tokens, 0, $values), array_slice($tokens, $values)] as $index => $part) {
            $open = $this->findToken($part, '(');
            $close = $open === null ? null : $this->findMatchingParen($part, $open);
            if ($open === null || $close === null) {
                $lines[] = $this->indent($indent) . $this->inlineTokens($part);
                continue;
            }

            $lines[] = $this->indent($indent) . $this->inlineTokens(array_slice($part, 0, $open)) . ' (';
            $items = $this->splitByTopLevelComma(array_slice($part, $open + 1, $close - $open - 1));
            foreach ($items as $itemIndex => $item) {
                $lines[] = $this->indent($indent + 1) . $this->inlineTokens($item) . ($itemIndex === count($items) - 1 ? '' : ',');
            }

            $lines[] = $this->indent($indent) . ')' . ($index === 0 ? '' : '');
        }

        return $lines;
    }

    /** @param list<Token> $tokens */
    private function formatParenthesizedSubquery(array $tokens, int $indent): string|null
    {
        if ($tokens === [] || $tokens[0]['text'] !== '(' || ! $this->matchPhrase($tokens, 1, ['SELECT'])) {
            return null;
        }

        $close = $this->findMatchingParen($tokens, 0);
        if ($close === null) {
            return null;
        }

        $lines = [$this->indent($indent) . '('];
        $lines[] = $this->formatStatement(array_slice($tokens, 1, $close - 1), $indent + 1);
        $suffix = array_slice($tokens, $close + 1);
        $lines[] = $this->indent($indent) . ')' . ($suffix === [] ? '' : ' ' . $this->inlineTokens($suffix));

        return implode("\n", $lines);
    }

    /** @param list<Token> $tokens */
    private function formatCaseExpression(array $tokens, int $indent): string
    {
        $case = $this->findTopLevelPhrase($tokens, ['CASE']);
        $end = $this->findLastTopLevelPhrase($tokens, ['END']);
        if ($case === null || $end === null) {
            return $this->indent($indent) . $this->inlineTokens($tokens);
        }

        $prefix = array_slice($tokens, 0, $case);
        $suffix = array_slice($tokens, $end + 1);
        $body = array_slice($tokens, $case + 1, $end - $case - 1);
        $lines = [];
        $lines[] = $this->indent($indent) . ($prefix === [] ? '' : $this->inlineTokens($prefix) . ' ') . 'CASE';

        $current = [];
        foreach ($body as $token) {
            if ($current !== [] && in_array($this->upperText($token), ['WHEN', 'ELSE'], true)) {
                $lines[] = $this->indent($indent + 1) . $this->inlineTokens($current);
                $current = [];
            }

            $current[] = $token;
        }

        if ($current !== []) {
            $lines[] = $this->indent($indent + 1) . $this->inlineTokens($current);
        }

        $lines[] = $this->indent($indent) . 'END' . ($suffix === [] ? '' : ' ' . $this->inlineTokens($suffix));

        return implode("\n", $lines);
    }

    /** @param list<Token> $tokens */
    private function formatWindowExpression(array $tokens, int $indent): string|null
    {
        $over = $this->findTopLevelPhrase($tokens, ['OVER']);
        if ($over === null) {
            return null;
        }

        $open = $over + 1;
        if (! isset($tokens[$open]) || $tokens[$open]['text'] !== '(') {
            return null;
        }

        $close = $this->findMatchingParen($tokens, $open);
        if ($close === null) {
            return null;
        }

        $lines = [$this->indent($indent) . $this->inlineTokens(array_slice($tokens, 0, $over + 1)) . ' ('];
        foreach ($this->splitWindowClauses(array_slice($tokens, $open + 1, $close - $open - 1)) as $clause) {
            $lines[] = $this->indent($indent + 1) . $this->inlineTokens($clause);
        }

        $suffix = array_slice($tokens, $close + 1);
        $lines[] = $this->indent($indent) . ')' . ($suffix === [] ? '' : ' ' . $this->inlineTokens($suffix));

        return implode("\n", $lines);
    }

    /**
     * @param list<Token> $tokens
     *
     * @return list<list<Token>>
     */
    private function splitWindowClauses(array $tokens): array
    {
        $parts = [];
        $start = 0;
        for ($i = 1; $i < count($tokens); $i++) {
            if (
                $this->matchPhrase($tokens, $i, ['ORDER', 'BY'])
                || $this->matchPhrase($tokens, $i, ['ROWS'])
            ) {
                $parts[] = array_slice($tokens, $start, $i - $start);
                $start = $i;
            }
        }

        $parts[] = array_slice($tokens, $start);

        return $parts;
    }

    /** @param list<Token> $tokens */
    private function formatCommentedExpression(array $tokens, int $indent): string
    {
        $lines = [];
        $current = [];
        foreach ($tokens as $token) {
            if ($token['type'] !== self::TYPE_COMMENT) {
                $current[] = $token;
                continue;
            }

            if (str_starts_with($token['text'], '/*')) {
                if ($current !== []) {
                    $lines[] = $this->indent($indent) . $this->inlineTokens($current);
                    $current = [];
                }

                $lines[] = $this->indent($indent) . $token['text'];
                continue;
            }

            $line = $current === [] ? '' : $this->inlineTokens($current) . ' ';
            $lines[] = $this->indent($indent) . $line . $token['text'];
            $current = [];
        }

        if ($current !== []) {
            $lines[] = $this->indent($indent) . $this->inlineTokens($current);
        }

        return implode("\n", $lines);
    }

    /** @param list<Token> $tokens */
    private function inlineTokens(array $tokens): string
    {
        $result = '';
        $previous = null;

        foreach ($tokens as $index => $token) {
            $text = $previous !== null && $previous['text'] === ':' ? $token['text'] : $this->renderToken($token);

            if ($text === '') {
                continue;
            }

            if ($result === '') {
                $result = $text;
                $previous = $token;
                continue;
            }

            if ($token['text'] === '.') {
                $result = rtrim($result) . '.';
            } elseif ($previous !== null && $previous['text'] === '.') {
                $result .= $text;
            } elseif ($token['text'] === '[]') {
                $result .= $text;
            } elseif ($token['text'] === ',') {
                $result = rtrim($result) . ', ';
            } elseif ($token['text'] === ';') {
                $result = rtrim($result) . ';';
            } elseif ($token['text'] === ':') {
                $result = rtrim($result) . ($previous !== null && $previous['type'] === self::TYPE_OPERATOR ? ' :' : ':');
            } elseif ($token['text'] === ')') {
                $result = rtrim($result) . ')';
            } elseif ($token['text'] === '(') {
                $result .= $this->needsSpaceBeforeParen($previous, $tokens[$index + 1] ?? null) ? ' (' : '(';
            } elseif ($token['type'] === self::TYPE_COMMENT) {
                $result = rtrim($result) . ' ' . $text;
            } elseif ($previous !== null && $previous['text'] === '(') {
                $result .= $text;
            } elseif ($previous !== null && $previous['text'] === ':') {
                $result .= $text;
            } elseif ($previous !== null && $previous['text'] === ',') {
                $result .= $text;
            } elseif ($previous !== null && $previous['type'] === self::TYPE_OPERATOR) {
                $result .= ' ' . $text;
            } elseif ($token['type'] === self::TYPE_OPERATOR) {
                $result = rtrim($result) . ' ' . $text;
            } else {
                $result .= ' ' . $text;
            }

            $previous = $token;
        }

        return rtrim($result);
    }

    /**
     * @param Token|null $previous
     * @param Token|null $next
     */
    private function needsSpaceBeforeParen(array|null $previous, array|null $next): bool
    {
        if ($previous === null) {
            return false;
        }

        if ($next !== null && $this->upperText($next) === 'SELECT') {
            return true;
        }

        return ! (
            $previous['type'] === self::TYPE_WORD
            && in_array($this->upperText($previous), self::FUNCTION_LIKE_WORDS, true)
        );
    }

    /**
     * @param list<Token> $tokens
     *
     * @return list<list<Token>>
     */
    private function splitByTopLevelComma(array $tokens): array
    {
        $parts = [];
        $part = [];
        $depth = 0;

        foreach ($tokens as $token) {
            if ($token['text'] === '(') {
                $depth++;
            } elseif ($token['text'] === ')') {
                $depth--;
            }

            if ($token['text'] === ',' && $depth === 0) {
                $parts[] = $part;
                $part = [];
                continue;
            }

            $part[] = $token;
        }

        $parts[] = $part;

        return $parts;
    }

    /**
     * @param list<Token> $tokens
     *
     * @return list<list<Token>>
     */
    private function readParenthesizedGroups(array $tokens): array
    {
        $groups = [];
        for ($i = 0; $i < count($tokens); $i++) {
            if ($tokens[$i]['text'] !== '(') {
                continue;
            }

            $close = $this->findMatchingParen($tokens, $i);
            if ($close === null) {
                break;
            }

            $groups[] = array_slice($tokens, $i + 1, $close - $i - 1);
            $i = $close;
        }

        return $groups;
    }

    /** @param array<int, Token> $tokens */
    private function readClausePhrase(array $tokens, int $offset): string|null
    {
        foreach (self::CLAUSE_PHRASES as $phrase) {
            if ($this->matchPhrase($tokens, $offset, explode(' ', $phrase))) {
                return $phrase;
            }
        }

        return null;
    }

    /** @param list<Token> $tokens */
    private function findToken(array $tokens, string $text): int|null
    {
        foreach ($tokens as $index => $token) {
            if ($token['text'] === $text) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<Token> $tokens */
    private function findMatchingParen(array $tokens, int $open): int|null
    {
        $depth = 0;
        for ($i = $open; $i < count($tokens); $i++) {
            if ($tokens[$i]['text'] === '(') {
                $depth++;
            } elseif ($tokens[$i]['text'] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, Token> $tokens
     * @param list<string>      $phrase
     */
    private function findTopLevelPhrase(array $tokens, array $phrase): int|null
    {
        $depth = 0;
        for ($i = 0; $i < count($tokens); $i++) {
            if ($tokens[$i]['text'] === '(') {
                $depth++;
            } elseif ($tokens[$i]['text'] === ')') {
                $depth--;
            }

            if ($depth === 0 && $this->matchPhrase($tokens, $i, $phrase)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param array<int, Token> $tokens
     * @param list<string>      $phrase
     */
    private function findLastTopLevelPhrase(array $tokens, array $phrase): int|null
    {
        $found = null;
        $depth = 0;
        for ($i = 0; $i < count($tokens); $i++) {
            if ($tokens[$i]['text'] === '(') {
                $depth++;
            } elseif ($tokens[$i]['text'] === ')') {
                $depth--;
            }

            if ($depth === 0 && $this->matchPhrase($tokens, $i, $phrase)) {
                $found = $i;
            }
        }

        return $found;
    }

    /**
     * @param array<int, Token> $tokens
     * @param list<string>      $phrase
     */
    private function matchPhrase(array $tokens, int $offset, array $phrase): bool
    {
        if ($offset < 0) {
            return false;
        }

        foreach ($phrase as $index => $word) {
            $position = $offset + $index;
            if (! isset($tokens[$position]) || $this->upperText($tokens[$position]) !== $word) {
                return false;
            }
        }

        return true;
    }

    /** @param list<Token> $tokens */
    private function hasTopLevelUnion(array $tokens): bool
    {
        return $this->findTopLevelPhrase($tokens, ['UNION']) !== null;
    }

    /** @param list<Token> $tokens */
    private function containsTopLevelWord(array $tokens, string $word): bool
    {
        return $this->findTopLevelPhrase($tokens, [$word]) !== null;
    }

    /** @param list<Token> $tokens */
    private function containsComment(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($token['type'] === self::TYPE_COMMENT) {
                return true;
            }
        }

        return false;
    }

    /** @param Token $token */
    private function renderToken(array $token): string
    {
        if ($token['type'] !== self::TYPE_WORD) {
            return $token['text'];
        }

        if (! in_array(strtoupper($token['text']), [...self::UPPERCASE_WORDS, ...self::FUNCTION_LIKE_WORDS], true)) {
            return $token['text'];
        }

        return strtoupper($token['text']);
    }

    /** @param Token $token */
    private function upperText(array $token): string
    {
        return strtoupper($token['text']);
    }

    private function trimLines(string $sql): string
    {
        $lines = explode("\n", $sql);
        foreach ($lines as $index => $line) {
            $lines[$index] = rtrim($line);
        }

        return rtrim(implode("\n", $lines));
    }

    private function indent(int $level): string
    {
        return str_repeat(self::INDENTATION, $level);
    }
}
