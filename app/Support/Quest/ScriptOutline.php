<?php

namespace App\Support\Quest;

/**
 * Turns a quest script into the block tree the walkthrough is written from.
 *
 * EQEmu quests are Perl or Lua source, so this is a parser -- but only as much
 * of one as a walkthrough needs: which handler a line sits in, which branch of
 * which condition, and the source text of each statement. Expressions are left
 * alone; QuestNarrator does the translating.
 *
 * Two things make that tractable without a real grammar:
 *
 *  - Strings and comments are masked *in place*, so a brace inside a line of
 *    dialogue can never read as code, and every offset in the mask still points
 *    at the same character of the untouched source.
 *  - Each line's structural change is reconciled against its own token count, so
 *    a construct this parser does not model -- an inline Lua callback, a Perl
 *    hash literal spanning lines -- costs one anonymous block rather than
 *    derailing the rest of the file.
 */
final class ScriptOutline
{
    public const LUA = 'lua';
    public const PERL = 'pl';

    /** Lines one statement may be joined across before we stop waiting for its ')'. */
    private const MAX_JOIN = 24;

    /** Stands in for a masked-out character: matches no \w, \s or bracket. */
    private const MASK = "\x01";

    /**
     * @return array<int, array<string, mixed>> top-level nodes, in source order
     */
    public static function parse(string $body, string $language): array
    {
        $lua = self::isLua($language);
        $stack = [['type' => 'root', 'children' => []]];
        $pending = false;

        foreach (self::records($body, $lua) as $record) {
            $mask = $record['mask'];
            $start = 0;

            if (trim($mask) === '') {
                continue;
            }

            // The '{' / 'then' a construct on an earlier line is still waiting
            // for. Blank lines between the two are common enough in Perl that
            // the wait has to survive them, which is why this sits below the
            // skip above.
            if ($pending) {
                $pending = false;

                if (preg_match($lua ? '/^\s*(?:then|do)\b/' : '/^\s*\{/', $mask, $m)) {
                    $start = strlen($m[0]);
                }
            }

            if (trim(substr($mask, $start)) === '') {
                continue;
            }

            $delta = self::tokenDelta(substr($mask, $start), $lua);
            $applied = 0;

            [$closed, $start] = self::leadingClosers($mask, $start, $lua);

            for ($i = 0; $i < $closed; $i++) {
                self::close($stack);
            }

            $applied -= $closed;

            $construct = self::construct($record, $start, $lua);

            if ($construct === null) {
                self::statement($stack, $record, $start, $lua);
            } else {
                // Lua shares one 'end' across a whole if/elseif/else chain, so the
                // branch a Lua 'elseif' closes costs no token; Perl's own '}' was
                // already counted above, on this line or the one before it.
                if ($lua && in_array($construct['node']['keyword'] ?? '', ['elseif', 'else'], true)) {
                    self::close($stack);
                }

                self::open($stack, $construct['node']);

                if ($construct['consumes']) {
                    $applied++;
                }

                if ($construct['body'] === null) {
                    $pending = true;
                } else {
                    $rest = substr($record['mask'], $construct['body']);

                    self::statement($stack, $record, $construct['body'], $lua);

                    // A one-line branch: `if (x) { say(); }`.
                    if (self::tokenDelta($rest, $lua) < 0) {
                        self::close($stack);
                        $applied--;
                    }
                }
            }

            // Whatever this line opened or closed that the shapes above do not
            // model still has to move the stack, or every later line lands in the
            // wrong block.
            for ($diff = $delta - $applied; $diff > 0; $diff--) {
                self::open($stack, ['type' => 'block', 'line' => $record['line'], 'children' => []]);
            }

            for ($diff = $delta - $applied; $diff < 0; $diff++) {
                self::close($stack);
            }
        }

        while (count($stack) > 1) {
            self::close($stack);
        }

        return $stack[0]['children'];
    }

    public static function isLua(string $language): bool
    {
        return strtolower($language) === self::LUA;
    }

    /**
     * Source lines, comment- and string-masked, with statements that wrap across
     * lines joined back into one. `raw` and `mask` are always the same length.
     *
     * @return array<int, array{line: int, raw: string, mask: string}>
     */
    private static function records(string $body, bool $lua): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $body) ?: [];
        $masked = [];
        $string = null;
        $comment = false;

        foreach ($lines as $i => $text) {
            [$mask, $string, $comment] = self::mask($text, $lua, $string, $comment);
            $masked[] = ['line' => $i + 1, 'raw' => $text, 'mask' => $mask];
        }

        $out = [];

        for ($i = 0, $n = count($masked); $i < $n; $i++) {
            $record = $masked[$i];
            $joined = 0;

            // An unclosed '(' means the call carries on below -- a long say(), a
            // reward table. The narrator needs the whole call to read it.
            while (self::unclosed($record['mask'], $lua) > 0 && $i + 1 < $n && $joined < self::MAX_JOIN) {
                $next = $masked[++$i];
                $record['raw'] .= "\n" . $next['raw'];
                $record['mask'] .= "\n" . $next['mask'];
                $joined++;
            }

            $out[] = $record;
        }

        return $out;
    }

    /**
     * Blank out string bodies and comments while keeping every offset in place.
     *
     * Double-quoted strings carry across lines because quest dialogue really is
     * written that way; single-quoted ones do not, so a stray apostrophe in a
     * Perl regex costs one line instead of the rest of the file.
     *
     * @return array{0: string, 1: ?string, 2: bool} [mask, open string, in block comment]
     */
    private static function mask(string $line, bool $lua, ?string $string, bool $comment): array
    {
        $out = '';
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($comment) {
                if ($lua && substr($line, $i, 2) === ']]') {
                    $comment = false;
                    $out .= '  ';
                    $i++;

                    continue;
                }

                $out .= ' ';

                continue;
            }

            if ($string !== null) {
                if ($char === '\\' && $i + 1 < $length) {
                    $out .= self::MASK . self::MASK;
                    $i++;

                    continue;
                }

                if ($char === $string) {
                    $string = null;
                    $out .= $char;

                    continue;
                }

                $out .= self::MASK;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $string = $char;
                $out .= $char;

                continue;
            }

            if ($lua && substr($line, $i, 2) === '--') {
                if (substr($line, $i, 4) === '--[[') {
                    $comment = true;
                    $out .= str_repeat(' ', 4);
                    $i += 3;

                    continue;
                }

                $out .= str_repeat(' ', $length - $i);

                break;
            }

            if (!$lua && $char === '#') {
                $out .= str_repeat(' ', $length - $i);

                break;
            }

            $out .= $char;
        }

        return [$out, $string === "'" ? null : $string, $comment];
    }

    /**
     * How many brackets this line opens and never closes.
     *
     * Braces join in Lua too, where '{' can only ever be a table literal -- it
     * is what pulls a reward table spread over five lines back into the one
     * statement the narrator can read. Perl braces are left alone: there they
     * are just as likely to be the block this parser is tracking.
     */
    private static function unclosed(string $mask, bool $lua): int
    {
        $depth = 0;

        foreach (str_split($mask) as $char) {
            if ($char === '(' || $char === '[' || ($lua && $char === '{')) {
                $depth++;
            } elseif ($char === ')' || $char === ']' || ($lua && $char === '}')) {
                $depth--;
            }
        }

        return max(0, $depth);
    }

    /** Net blocks a stretch of masked code opens: Perl counts braces, Lua keywords. */
    private static function tokenDelta(string $mask, bool $lua): int
    {
        if (!$lua) {
            return substr_count($mask, '{') - substr_count($mask, '}');
        }

        return preg_match_all('/\b(?:function|then|do|repeat)\b/', $mask)
            - preg_match_all('/\b(?:end|until)\b/', $mask);
    }

    /**
     * Block ends at the head of a line, and where the code after them starts.
     *
     * @return array{0: int, 1: int} [count, offset]
     */
    private static function leadingClosers(string $mask, int $offset, bool $lua): array
    {
        $pattern = $lua ? '/^\s*(?:end|until)\b[\s;,)]*/' : '/^\s*\}[\s;,)]*/';
        $count = 0;

        while (preg_match($pattern, substr($mask, $offset), $m)) {
            $count++;
            $offset += strlen($m[0]);
        }

        return [$count, $offset];
    }

    /**
     * Record the code from $from to the end of the line as one statement.
     *
     * Block ends the line closes are shaved off first -- they belong to the
     * branch, not to the call sitting in front of them -- and a slice the mask
     * says holds no code is dropped rather than shown: a lone '}' or the tail
     * comment after an opening brace says nothing a walkthrough can use.
     */
    private static function statement(array &$stack, array $record, int $from, bool $lua): void
    {
        $raw = substr($record['raw'], $from);
        $mask = substr($record['mask'], $from);
        $pattern = $lua ? '/\s*\bend\b[\s;,)]*$/' : '/\s*\}[\s;,)]*$/';

        while (self::tokenDelta($mask, $lua) < 0 && preg_match($pattern, $mask, $m)) {
            $raw = substr($raw, 0, -strlen($m[0]));
            $mask = substr($mask, 0, -strlen($m[0]));
        }

        $raw = trim($raw);

        if (!preg_match('/[a-z0-9]/i', $mask)) {
            return;
        }

        self::add($stack, ['type' => 'statement', 'line' => $record['line'], 'code' => $raw]);
    }

    /**
     * The block-opening construct a line starts with, if it starts with one.
     *
     * `body` is the offset the block's contents begin at, or null when the
     * opener is still on a later line. `consumes` says whether the opener was a
     * token tokenDelta() counted -- Lua's `else` opens a block with no token at
     * all, and the reconciliation has to know that.
     *
     * @return array{node: array<string, mixed>, body: ?int, consumes: bool}|null
     */
    private static function construct(array $record, int $offset, bool $lua): ?array
    {
        $mask = $record['mask'];
        $head = substr($mask, $offset);

        $node = null;
        $after = null;      // offset the opener is searched from
        $opener = null;     // ['{'] for Perl, keyword for Lua; null when there is none

        if (!$lua) {
            if (preg_match('/^\s*sub\s+([A-Za-z_]\w*)/i', $head, $m)) {
                $node = self::handlerNode($m[1], $record['line']);
                $after = $offset + strlen($m[0]);
                $opener = '{';
            } elseif (preg_match('/^\s*(?:\bels(?:e)?if\b|\belse\s+if\b)/i', $head)) {
                [$condition, $end] = self::parenthesised($record, $offset);
                $node = ['type' => 'branch', 'keyword' => 'elseif', 'condition' => $condition, 'line' => $record['line'], 'children' => []];
                $after = $end;
                $opener = '{';
            } elseif (preg_match('/^\s*else\b/i', $head, $m)) {
                $node = ['type' => 'branch', 'keyword' => 'else', 'condition' => null, 'line' => $record['line'], 'children' => []];
                $after = $offset + strlen($m[0]);
                $opener = '{';
            } elseif (preg_match('/^\s*(if|unless)\b/i', $head, $m)) {
                [$condition, $end] = self::parenthesised($record, $offset);
                $node = [
                    'type' => 'branch',
                    'keyword' => strtolower($m[1]) === 'unless' ? 'unless' : 'if',
                    'condition' => $condition,
                    'line' => $record['line'],
                    'children' => [],
                ];
                $after = $end;
                $opener = '{';
            } elseif (preg_match('/^\s*(foreach|for|while|until)\b([^{]*)/i', $head, $m)) {
                $node = [
                    'type' => 'branch',
                    'keyword' => 'loop',
                    'condition' => trim(substr($record['raw'], $offset, strlen($m[0]))),
                    'line' => $record['line'],
                    'children' => [],
                ];
                $after = $offset + strlen($m[0]);
                $opener = '{';
            }
        } else {
            if (preg_match('/^\s*(?:local\s+)?function\s*([\w.:]*)\s*\(/i', $head, $m)) {
                $node = self::handlerNode($m[1], $record['line']);
                $after = self::afterParen($mask, $offset + strlen($m[0]) - 1);

                return ['node' => $node, 'body' => $after, 'consumes' => true];
            }

            if (preg_match('/^\s*elseif\b/i', $head, $m)) {
                $node = ['type' => 'branch', 'keyword' => 'elseif', 'condition' => null, 'line' => $record['line'], 'children' => []];
                $after = $offset + strlen($m[0]);
                $opener = 'then';
            } elseif (preg_match('/^\s*else\b/i', $head, $m)) {
                return [
                    'node' => ['type' => 'branch', 'keyword' => 'else', 'condition' => null, 'line' => $record['line'], 'children' => []],
                    'body' => $offset + strlen($m[0]),
                    'consumes' => false,
                ];
            } elseif (preg_match('/^\s*if\b/i', $head, $m)) {
                $node = ['type' => 'branch', 'keyword' => 'if', 'condition' => null, 'line' => $record['line'], 'children' => []];
                $after = $offset + strlen($m[0]);
                $opener = 'then';
            } elseif (preg_match('/^\s*(for|while)\b/i', $head, $m)) {
                $node = ['type' => 'branch', 'keyword' => 'loop', 'condition' => null, 'line' => $record['line'], 'children' => []];
                $after = $offset + strlen($m[0]);
                $opener = 'do';
            } elseif (preg_match('/^\s*repeat\b/i', $head, $m)) {
                return [
                    'node' => ['type' => 'branch', 'keyword' => 'loop', 'condition' => 'repeat', 'line' => $record['line'], 'children' => []],
                    'body' => $offset + strlen($m[0]),
                    'consumes' => true,
                ];
            }
        }

        if ($node === null) {
            return null;
        }

        $body = $opener === '{'
            ? self::afterBrace($mask, $after)
            : self::afterKeyword($mask, $after, $opener);

        // Lua keeps its condition between the keyword and `then`; Perl's came out
        // of the parentheses above.
        if ($lua && $body !== null && $node['type'] === 'branch' && $node['condition'] === null) {
            $node['condition'] = trim(substr($record['raw'], $after, $body - $after - strlen($opener)));
        }

        return ['node' => $node, 'body' => $body, 'consumes' => $body !== null];
    }

    private static function handlerNode(string $name, int $line): array
    {
        $event = preg_match('/^(?:EVENT_\w+|event_\w+)$/', $name) ? $name : null;

        return [
            'type' => $event ? 'handler' : 'sub',
            'name' => $name,
            'event' => $event,
            'line' => $line,
            'children' => [],
        ];
    }

    /**
     * The parenthesised condition a Perl `if`/`while` opens with.
     *
     * @return array{0: ?string, 1: ?int} [condition, offset after ')']
     */
    private static function parenthesised(array $record, int $offset): array
    {
        $open = strpos($record['mask'], '(', $offset);

        if ($open === false) {
            return [null, null];
        }

        $close = self::afterParen($record['mask'], $open);

        if ($close === null) {
            return [null, null];
        }

        return [trim(substr($record['raw'], $open + 1, $close - $open - 2)), $close];
    }

    /** Offset just past the ')' that closes the '(' at $open. */
    private static function afterParen(string $mask, int $open): ?int
    {
        $depth = 0;

        for ($i = $open, $n = strlen($mask); $i < $n; $i++) {
            if ($mask[$i] === '(') {
                $depth++;
            } elseif ($mask[$i] === ')' && --$depth === 0) {
                return $i + 1;
            }
        }

        return null;
    }

    private static function afterBrace(string $mask, ?int $from): ?int
    {
        if ($from === null) {
            return null;
        }

        $at = strpos($mask, '{', $from);

        return $at === false ? null : $at + 1;
    }

    private static function afterKeyword(string $mask, ?int $from, string $keyword): ?int
    {
        if ($from === null) {
            return null;
        }

        if (!preg_match('/\b' . $keyword . '\b/', $mask, $m, PREG_OFFSET_CAPTURE, $from)) {
            return null;
        }

        return $m[0][1] + strlen($keyword);
    }

    private static function open(array &$stack, array $node): void
    {
        $stack[] = $node;
    }

    private static function close(array &$stack): void
    {
        if (count($stack) <= 1) {
            return;
        }

        $node = array_pop($stack);
        self::add($stack, $node);
    }

    private static function add(array &$stack, array $node): void
    {
        $stack[count($stack) - 1]['children'][] = $node;
    }
}
