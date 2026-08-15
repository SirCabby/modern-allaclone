<?php

namespace App\Support\Quest;

/**
 * Reads a parsed quest script back as English.
 *
 * The script is the only place quest information exists -- EQEmu keeps none of
 * it in the database -- so a quest page has always had to show source, and
 * source is a poor answer to "what does this NPC want?". This walks the tree
 * ScriptOutline builds and rewrites it as what a player would do and see: the
 * phrase that starts the conversation, the items that have to go back, what
 * comes out the other side.
 *
 * Everything it emits is a list of segments rather than a finished sentence, so
 * an item, NPC, task, faction or spell the script names by id stays an id here
 * and becomes a link on the page. Ids are collected as they are met (see
 * refs()) for the caller to resolve in one query each.
 *
 * A call it does not recognise is emitted as its own source line rather than
 * dropped. Being told "this line was not translated" is recoverable; quietly
 * omitting a step of a quest is not.
 */
final class QuestNarrator
{
    /** How deep the walkthrough will nest before it stops recursing. */
    private const MAX_DEPTH = 12;

    /**
     * Every call that means "the player handed these items over".
     *
     * There are four spellings of the same act across the two languages and the
     * plugin layer, and a script that uses the one nobody taught this would
     * otherwise print its item ids as source -- which is the one thing the page
     * exists to avoid.
     */
    private const HANDIN = '/\b(?:check_handin|check_turn_in|handin|take_?items?)\s*\(/i';

    /** What each coin key in a hand-in or reward table is worth in copper. */
    private const COINS = ['copper' => 1, 'silver' => 10, 'gold' => 100, 'platinum' => 1000];

    /** Perl's $faction, and the standing each value means. */
    private const STANDINGS = [
        1 => 'Ally', 2 => 'Warmly', 3 => 'Kindly', 4 => 'Amiably', 5 => 'Indifferent',
        6 => 'Apprehensive', 7 => 'Dubious', 8 => 'Threatening', 9 => 'Scowling',
    ];

    /** Lua's Class.* constants that do not survive a plain ucfirst(). */
    private const CLASSES = [
        'SHADOWKNIGHT' => 'Shadow Knight',
        'SHADOW_KNIGHT' => 'Shadow Knight',
    ];

    private array $refs = [];

    private int $actions = 0;

    private int $untranslated = 0;

    private function __construct()
    {
    }

    /**
     * @return array{
     *     scenes: array<int, array<string, mixed>>,
     *     refs: array<string, array<int, int>>,
     *     actions: int,
     *     untranslated: int
     * }
     */
    public static function narrate(string $body, string $language): array
    {
        return (new self)->run($body, $language);
    }

    private function run(string $body, string $language): array
    {
        $nodes = ScriptOutline::parse($body, $language);
        $registered = $this->registrations($body);
        $scenes = [];
        $setup = [];

        foreach ($nodes as $node) {
            if ($node['type'] === 'statement') {
                $setup = array_merge($setup, $this->entries([$node], 0));

                continue;
            }

            $scene = $this->scene($node, $registered);

            if ($scene !== null) {
                $scenes[] = $scene;
            }
        }

        if ($setup) {
            $scenes[] = ['title' => 'Script setup', 'note' => null, 'aside' => true, 'entries' => $setup];
        }

        return [
            'scenes' => $scenes,
            'refs' => array_map('array_values', $this->refs),
            'actions' => $this->actions,
            'untranslated' => $this->untranslated,
        ];
    }

    // ------------------------------------------------------------------ scenes

    /** @return array<string, mixed>|null */
    private function scene(array $node, array $registered): ?array
    {
        $entries = $this->entries($node['children'] ?? [], 0);

        if (!$entries) {
            return null;
        }

        if ($node['type'] === 'handler') {
            return [
                'title' => self::eventTitle($node['event'] ?? ''),
                'note' => $node['event'] ?? null,
                'aside' => false,
                'entries' => $entries,
            ];
        }

        $name = $node['name'] ?? '';
        $event = $registered[strtolower($name)] ?? null;

        return [
            'title' => $event !== null
                ? self::eventTitle($event)
                : 'Helper: ' . str_replace('_', ' ', $name),
            'note' => $event !== null ? $name . '()' : null,
            'aside' => $event === null,
            'entries' => $entries,
        ];
    }

    /**
     * Named functions an encounter script wires to an event.
     *
     * Encounter scripts do not define event_spawn(); they define Vangl_Spawn()
     * and register it, so without this every one of them reads as a pile of
     * unexplained helpers.
     *
     * @return array<string, string> lowercased function name => event name
     */
    private function registrations(string $body): array
    {
        if (!preg_match_all('/register_npc_event\s*\(([^)]*)\)/i', $body, $matches)) {
            return [];
        }

        $out = [];

        foreach ($matches[1] as $args) {
            $parts = array_map('trim', self::splitArgs($args));

            // (bucket, Event.x, npc_id, handler), with the bucket optional.
            $handler = array_pop($parts);
            $event = null;

            foreach ($parts as $part) {
                if (preg_match('/Event\.(\w+)/i', $part, $m)) {
                    $event = $m[1];
                }
            }

            if ($event === null || !preg_match('/^[\w.]+$/', (string) $handler)) {
                continue;
            }

            $out[strtolower($handler)] = $event;
        }

        return $out;
    }

    // ----------------------------------------------------------------- entries

    /**
     * Children of one block, in source order.
     *
     * @return array<int, array<string, mixed>>
     */
    private function entries(array $nodes, int $depth): array
    {
        $out = [];

        foreach ($nodes as $node) {
            if ($node['type'] === 'statement') {
                $action = $this->action($node['code']);

                if ($action !== null) {
                    $out[] = $action;
                }

                continue;
            }

            if ($node['type'] === 'block' || $node['type'] === 'sub') {
                // An anonymous block is a construct the parser could not name;
                // its contents still belong where they were written.
                $out = array_merge($out, $this->entries($node['children'] ?? [], $depth));

                continue;
            }

            if ($depth >= self::MAX_DEPTH) {
                $out = array_merge($out, $this->entries($node['children'] ?? [], $depth));

                continue;
            }

            $keyword = $node['keyword'] ?? 'if';
            $entries = $this->entries($node['children'] ?? [], $depth + 1);

            if (!$entries) {
                continue;
            }

            $out[] = [
                'type' => 'branch',
                'joiner' => self::joiner($keyword),
                'condition' => $keyword === 'else' ? [] : $this->condition($node['condition'] ?? null),
                'entries' => $entries,
            ];
        }

        // A chain whose leading `if` had nothing to say -- an empty body, which
        // raid scripts use to swallow a timer -- would otherwise open on "Or if".
        foreach ($out as $i => $entry) {
            if ($entry['type'] === 'branch'
                && $entry['joiner'] === 'Or if'
                && (($out[$i - 1]['type'] ?? '') !== 'branch')) {
                $out[$i]['joiner'] = 'If';
            }
        }

        return $out;
    }

    private static function joiner(string $keyword): string
    {
        return match ($keyword) {
            'else' => 'Otherwise',
            'elseif' => 'Or if',
            'unless' => 'Unless',
            'loop' => 'For each',
            default => 'If',
        };
    }

    // -------------------------------------------------------------- conditions

    /**
     * A branch condition, with `and`/`or` kept as the join between clauses.
     *
     * @return array<int, array<string, mixed>>
     */
    private function condition(?string $expr, int $depth = 0): array
    {
        $expr = trim((string) $expr);

        if ($expr === '') {
            return [];
        }

        $clauses = self::splitLogical($expr);
        $collapsed = $this->countRange($clauses);

        if ($collapsed !== null) {
            return $collapsed;
        }

        $segments = [];

        foreach ($clauses as $i => [$clause, $join]) {
            if ($i > 0) {
                $segments[] = self::text(' ' . $join . ' ');
            }

            $segments = array_merge($segments, $this->clause($clause, $depth, count($clauses) > 1));
        }

        return $segments;
    }

    /**
     * Collapse "4 of it, or 3, or 2, or 1" into the range it means.
     *
     * Perl turn-ins can only test an exact count, so an NPC that takes any
     * number up to four is written as four checks or-ed together. Read back one
     * by one that is four near-identical lines saying one thing.
     *
     * @param  array<int, array{0: string, 1: string}>  $clauses
     * @return array<int, array<string, mixed>>|null
     */
    private function countRange(array $clauses): ?array
    {
        if (count($clauses) < 2) {
            return null;
        }

        $item = null;
        $counts = [];

        foreach ($clauses as $i => [$clause, $join]) {
            if ($i > 0 && $join !== 'or') {
                return null;
            }

            $pairs = self::pairs($clause);

            // Coin in the clause would be dropped by the collapse, so a hand-in
            // that asks for money as well as an item is left spelled out.
            if (!preg_match(self::HANDIN, $clause)
                || self::copper($clause) > 0
                || count($pairs) !== 1
                || ($item !== null && $item !== $pairs[0][0])) {
                return null;
            }

            $item = $pairs[0][0];
            $counts[] = $pairs[0][1];
        }

        $counts = array_unique($counts);
        sort($counts);
        $low = (int) reset($counts);
        $high = (int) end($counts);

        // Only a run: "1, 2 or 4" is not "1 to 4", and saying so would be wrong.
        if ($high - $low + 1 !== count($counts)) {
            return null;
        }

        return [
            self::text('you hand in '),
            self::em($low === $high ? $low . '×' : $low . '–' . $high . '×'),
            self::text(' '),
            $this->ref('item', $item),
        ];
    }

    /**
     * @param  bool  $grouped  whether this clause has siblings, so its own parentheses matter
     * @return array<int, array<string, mixed>>
     */
    private function clause(string $expr, int $depth = 0, bool $grouped = false): array
    {
        $expr = self::unwrap($expr);
        $negated = false;

        while (preg_match('/^(?:!|\bnot\b)\s*(.+)$/is', $expr, $m)) {
            $negated = !$negated;
            $expr = self::unwrap($m[1]);
        }

        // Peeling the parentheses can uncover a join the split above could not
        // see: `($a && $b) || $c` is two clauses, the first of which is itself
        // two. Reading only its head would quietly drop half the test.
        //
        // The parentheses go back on when the clause has siblings, because that
        // is the only thing holding `a and (b or c)` apart from `a and b or c`.
        if (!$negated && $depth < 3 && count(self::splitLogical($expr)) > 1) {
            $inner = $this->condition($expr, $depth + 1);

            return $grouped
                ? array_merge([self::text('(')], $inner, [self::text(')')])
                : $inner;
        }

        $segments = $this->known($expr, $negated);

        if ($segments !== null) {
            return $segments;
        }

        return $negated
            ? array_merge([self::text('not ')], [self::code($expr)])
            : [self::code($expr)];
    }

    /**
     * The condition shapes worth spelling out. Anything else keeps its source.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function known(string $expr, bool $negated): ?array
    {
        $not = fn (string $yes, string $no) => self::text($negated ? $no : $yes);

        // Said phrase: $text =~ /hail/i, e.message:findi("hail"). The Lua form
        // has to close on the quote it opened with -- half the trigger phrases
        // in the game have an apostrophe in them.
        if (preg_match('/\$text\s*=~\s*m?\/(.+?)\/[a-z]*\s*$/is', $expr, $m)) {
            return [$not('you say ', 'you do not say '), self::quote(self::readable($m[1]))];
        }

        if (preg_match('/\bmessage:find\w*\s*\(\s*(["\'])(.*?)\1/is', $expr, $m)) {
            return [$not('you say ', 'you do not say '), self::quote(self::readable($m[2]))];
        }

        // Turn-in: check_handin(\%itemcount, 1234 => 1), check_turn_in(e.trade,
        // {item1 = 1234}), handin({[1234] = 1}), takeItems(1234 => 1).
        if (preg_match(self::HANDIN, $expr)) {
            $items = $this->turnIn($expr);

            if ($items) {
                return array_merge([$not('you hand in ', 'you do not hand in ')], $items);
            }
        }

        // Perl's hand-in hash read directly: $itemcount{1234}, and with a count
        // when the NPC wants more than one. A test against anything but a real
        // quantity -- `== 0` for one -- means the opposite, and is left as source
        // rather than read backwards.
        if (preg_match('/\$itemcount\{\s*(\d+)\s*\}(?:\s*(==|>=)\s*([1-9]\d*)|(?!\s*[=!<>]))/', $expr, $m)) {
            $count = (int) ($m[3] ?? 1);

            return array_merge(
                [$not('you hand in ', 'you do not hand in ')],
                $count > 1 ? [self::em($count . '×'), self::text(' ')] : [],
                [$this->ref('item', (int) $m[1])]
            );
        }

        // Carrying an item: check_hasitem($client, 1234), e.other:HasItem(1234)
        if (preg_match('/\b(?:check_hasitem\s*\(\s*\$\w+\s*,|HasItem\s*\()\s*(\d+)/i', $expr, $m)) {
            return [$not('you are carrying ', 'you are not carrying '), $this->ref('item', (int) $m[1])];
        }

        // Quest flags, however the two languages spell them.
        if (preg_match('/\$qglobals\{\s*[\'"]?(\w+)[\'"]?\s*\}|qglobals\.(\w+)|GetGlobal\s*\(\s*[\'"](\w+)[\'"]\s*\)/i', $expr, $m)) {
            $name = $m[1] ?: ($m[2] ?? '') ?: ($m[3] ?? '');
            $value = self::comparison($expr);

            if ($value === null || $value === 'nil' || preg_match('/^defined|^exists/i', $expr)) {
                $unset = $value === 'nil';

                return [$not($unset ? 'you do not have the ' : 'you have the ', $unset ? 'you have the ' : 'you do not have the '),
                    self::flag($name), self::text(' quest flag')];
            }

            return [self::text('the '), self::flag($name), $not(' quest flag is ', ' quest flag is not '), self::em($value)];
        }

        // Level, class, race, GM status.
        if (preg_match('/(?:\$ulevel|\$mlevel|GetLevel\s*\(\s*\))\s*(<=|>=|==|<|>|!=)\s*(\d+)/i', $expr, $m)) {
            return [self::text('you are level ' . self::levelPhrase($m[1], (int) $m[2]))];
        }

        if (preg_match('/(?:\$class\b|Class\s*\(\s*\)|GetClass\s*\(\s*\))\s*(?:eq|==|~=|!=)\s*(?:[\'"](\w[\w ]*)[\'"]|Class\.(\w+)|(\d+))/i', $expr, $m)) {
            $class = $m[1] ?: self::constant($m[2] ?? '') ?: ('class ' . ($m[3] ?? ''));

            return [$not('you are a ', 'you are not a '), self::em($class)];
        }

        if (preg_match('/(?:\$race\b|GetRace(?:Name)?\s*\(\s*\))\s*(?:eq|==|~=|!=)\s*[\'"](\w[\w ]*)[\'"]/i', $expr, $m)) {
            return [$not('you are ', 'you are not '), self::em($m[1])];
        }

        if (preg_match('/\$status\s*(<=|>=|==|<|>)\s*(\d+)/i', $expr, $m)) {
            return [self::text('your account status is ' . $m[1] . ' ' . $m[2] . ' (GM check)')];
        }

        // Standing with this NPC's faction.
        if (preg_match('/\$faction\s*(<=|>=|==|<|>)\s*([1-9])\b/i', $expr, $m)) {
            $standing = self::STANDINGS[(int) $m[2]] ?? $m[2];
            $phrase = match ($m[1]) {
                '<=', '<' => 'your faction is ' . $standing . ' or better',
                '>=', '>' => 'your faction is ' . $standing . ' or worse',
                default => 'your faction is exactly ' . $standing,
            };

            return [self::text($phrase)];
        }

        // Task state.
        if (preg_match('/\bis_?task_?activity_?active\s*\(\s*(\d+)\s*,\s*(\d+)/i', $expr, $m)) {
            return [$not('you are on step ', 'you are not on step '), self::em((string) ((int) $m[2] + 1)),
                self::text(' of '), $this->ref('task', (int) $m[1])];
        }

        if (preg_match('/\bis_?task_?(active|completed|enabled|appropriate)\s*\(\s*(\d+)/i', $expr, $m)) {
            $phrase = match (strtolower($m[1])) {
                'completed' => ['you have completed ', 'you have not completed '],
                'enabled' => ['you have unlocked ', 'you have not unlocked '],
                'appropriate' => ['you are eligible for ', 'you are not eligible for '],
                default => ['you are on ', 'you are not on '],
            };

            return [$not($phrase[0], $phrase[1]), $this->ref('task', (int) $m[2])];
        }

        // Event payloads: which timer fired, which signal arrived, and so on.
        if (preg_match('/(?:\$timer|e\.timer)\s*(?:eq|==)\s*[\'"]?([\w ]+)[\'"]?/i', $expr, $m)) {
            return [self::text('the '), self::em(trim($m[1])), self::text(' timer fires')];
        }

        if (preg_match('/(?:\$signal|e\.signal)\s*==\s*(\d+)/i', $expr, $m)) {
            return [self::text('signal '), self::em($m[1]), self::text(' arrives')];
        }

        if (preg_match('/(?:\$wp|e\.wp)\s*==\s*(\d+)/i', $expr, $m)) {
            return [self::text('it reaches waypoint '), self::em($m[1])];
        }

        if (preg_match('/(?:\$hpevent|e\.hp_event)\s*==\s*(\d+)/i', $expr, $m)) {
            return [self::text('its health drops to '), self::em($m[1] . '%')];
        }

        if (preg_match('/(?:\$doorid|e\.door_id)\s*==\s*(\d+)/i', $expr, $m)) {
            return [self::text('door '), self::em($m[1]), self::text(' is clicked')];
        }

        if (preg_match('/e\.item:GetID\s*\(\s*\)\s*==\s*(\d+)/i', $expr, $m)) {
            return [$not('the item is ', 'the item is not '), $this->ref('item', (int) $m[1])];
        }

        // Zone keys.
        if (preg_match('/\bhas_zone_flag\s*\(\s*(\d+)/i', $expr, $m)) {
            return [$not('you hold the key to ', 'you do not hold the key to '), $this->ref('zone', (int) $m[1])];
        }

        if (preg_match('/\bHasSpellScribed\s*\(\s*(\d+)/i', $expr, $m)) {
            return [$not('you have ', 'you do not have '), $this->ref('spell', (int) $m[1]), self::text(' scribed')];
        }

        if (preg_match('/e\.task_id\s*==\s*(\d+)/i', $expr, $m)) {
            return [$not('the task is ', 'the task is not '), $this->ref('task', (int) $m[1])];
        }

        if (preg_match('/IsMobSpawnedByNpcTypeID\s*\(\s*(\d+)/i', $expr, $m)) {
            return [$not('', 'no '), $this->ref('npc', (int) $m[1]), self::text($negated ? ' is in the zone' : ' is already in the zone')];
        }

        if (preg_match('/^e\.joined$/i', $expr)) {
            return [self::text($negated ? 'combat ends' : 'combat starts')];
        }

        if (preg_match('/GetGM\s*\(\s*\)\s*$/i', $expr)) {
            return [$not('you are a GM', 'you are not a GM')];
        }

        return null;
    }

    private static function levelPhrase(string $operator, int $level): string
    {
        return match ($operator) {
            '>=' => $level . ' or above',
            '>' => 'above ' . $level,
            '<=' => $level . ' or below',
            '<' => 'below ' . $level,
            '!=' => 'anything but ' . $level,
            default => 'exactly ' . $level,
        };
    }

    /** The literal a comparison in this expression tests against, if it has one. */
    private static function comparison(string $expr): ?string
    {
        if (preg_match('/(?:==|eq|~=|!=|>=|<=|>|<)\s*[\'"]?([\w.\-]+)[\'"]?\s*\)?\s*$/i', $expr, $m)) {
            return $m[1];
        }

        return null;
    }

    // ------------------------------------------------------------------ action

    /**
     * One statement, as the thing it does.
     *
     * @return array<string, mixed>|null
     */
    private function action(string $code): ?array
    {
        $code = trim($code);

        if ($code === '' || preg_match('/^local\s+\w+\s*=\s*require\s*\(/i', $code)) {
            return null;
        }

        $entry = null;

        // A statement can be a chain -- `e.self:CastToNPC():AddItem(1234, 1)` --
        // or wrap the call that does the work in one that does not, so the first
        // call is not always the one worth reading. Take the first that has
        // something to say and leave the order alone otherwise.
        foreach (self::calls($code) as $call) {
            $entry = $this->translate($call['name'], $call['args'], $call['argv'], $call['recv']);

            if ($entry !== null) {
                break;
            }
        }

        $this->actions++;

        if ($entry === null) {
            $this->untranslated++;

            return ['type' => 'action', 'kind' => 'code', 'minor' => true, 'segments' => [self::code($code)], 'quote' => null];
        }

        return $entry + ['type' => 'action', 'minor' => false, 'quote' => null];
    }

    /**
     * @param  string  $name  call name, lowercased with underscores removed
     * @param  string  $args  everything between the parentheses
     * @param  array<int, string>  $argv  the same, split on top-level commas
     * @param  string  $recv  what the call was made on, lowercased, e.g. `e.other:`
     * @return array<string, mixed>|null
     */
    private function translate(string $name, string $args, array $argv, string $recv = ''): ?array
    {
        return match (true) {
            // ------------------------------------------------------- dialogue
            in_array($name, ['say', 'questsay'], true) => $this->speech('say', 'Says', $args),
            $name === 'emote' => $this->speech('emote', 'Emotes', $args),
            $name === 'shout' || $name === 'shout2' => $this->speech('say', 'Shouts', $args),
            $name === 'whisper' => $this->speech('say', 'Whispers', $args),
            in_array($name, ['message', 'messageclose', 'messagestring', 'me', 'mes'], true) => $this->speech('say', 'Tells you', $args),
            in_array($name, ['ze', 'we', 'zoneemote', 'worldemote', 'localemote', 'echo', 'zonesay'], true) => $this->speech('say', 'Announces to the zone', $args),
            $name === 'popup' => $this->speech('say', 'Opens a window reading', $args),

            // ---------------------------------------------------------- items
            in_array($name, ['summonitem', 'additem', 'summonitemintoinstance', 'summoncursoritem'], true)
                => $this->give($name, $argv, $recv),
            $name === 'questreward' => $this->questReward($args, $argv),
            in_array($name, ['returnitems', 'returnunuseditems'], true)
                => self::entry('give', [self::text('Hands back anything else you gave it')], true),
            in_array($name, ['takeitems', 'takeitem', 'handin'], true) => $this->takes($args),
            $name === 'trytomehandins' => self::entry('give', [self::text('Accepts a tome hand-in')]),

            // --------------------------------------------------------- payout
            in_array($name, ['exp', 'addexp', 'addquestexp'], true) => $this->experience($argv),
            $name === 'addaapoints' => self::entry('reward', [
                self::text('Grants '), self::em(($argv[0] ?? '') === '' ? 'AA points' : trim($argv[0]) . ' AA points'),
            ]),
            $name === 'creategroundobject' => ($ground = self::number($argv[0] ?? null)) !== null
                ? self::entry('give', [self::text('Leaves '), $this->ref('item', $ground), self::text(' on the ground')])
                : null,
            in_array($name, ['givecash', 'addmoneytoclient'], true) => $this->coin($argv),
            $name === 'faction' => $this->faction($argv),

            // ---------------------------------------------------------- flags
            in_array($name, ['setglobal', 'setqglobal', 'targlobal'], true) => $this->setGlobal($argv),
            in_array($name, ['delglobal', 'deleteglobal'], true) => $this->flagEntry('Clears the ', $argv),
            $name === 'setzoneflag' => ($zone = self::number($argv[0] ?? null)) !== null
                ? self::entry('flag', [self::text('Gives you the key to '), $this->ref('zone', $zone)])
                : null,

            // ---------------------------------------------------------- tasks
            in_array($name, ['taskselector', 'taskselectornocooldown'], true) => $this->tasks('Offers', $argv),
            in_array($name, ['insert', 'push'], true) => $this->taskList($argv),
            $name === 'assigntask' => $this->tasks('Starts', $argv),
            $name === 'enabletask' => $this->tasks('Unlocks', $argv),
            $name === 'disabletask' => $this->tasks('Locks', $argv),
            $name === 'failtask' => $this->tasks('Fails', $argv),
            in_array($name, ['completetask', 'markcompleted'], true) => $this->tasks('Completes', $argv),
            $name === 'uncompletetask' => $this->tasks('Un-completes', $argv),
            in_array($name, ['updatetaskactivity', 'resettaskactivity'], true) => $this->taskStep($name, $argv),

            // --------------------------------------------------------- spawns
            in_array($name, ['spawn2', 'uniquespawn', 'spawn'], true) => ($npcs = self::ids($argv[0] ?? null))
                ? self::entry('spawn', array_merge([self::text('Spawns ')], $this->refList('npc', $npcs)))
                : null,
            $name === 'depop' => self::entry('spawn', array_merge(
                [self::text('Despawns ')],
                ($npc = self::number($argv[0] ?? null)) !== null ? [$this->ref('npc', $npc)] : [self::text('itself')]
            )),
            $name === 'depopwithtimer' => self::entry('spawn', [self::text('Despawns itself and starts its respawn timer')]),
            in_array($name, ['depopall', 'depopzone'], true) => self::entry('spawn', [self::text('Despawns every copy of it in the zone')]),
            in_array($name, ['spawncondition', 'setspawncondition', 'toggle'], true)
                => self::entry('spawn', [self::text('Switches a spawn on or off')], true),
            $name === 'loadencounter' => self::entry('spawn', [self::text('Starts another encounter script')], true),
            $name === 'registernpcevent' => $this->registration($argv),

            // ------------------------------------------------------- movement
            in_array($name, ['movepc', 'movepcinstance', 'movepcwithinstanceid'], true) => $this->movePc($argv),
            $name === 'gotobind' => self::entry('move', [self::text('Sends you to your bind point')]),
            in_array($name, ['moveto', 'gmmove', 'setrunning', 'follow', 'sfollow', 'stopfollow', 'start', 'stop', 'pause', 'resume', 'updatespawntimer'], true)
                => self::entry('move', [self::text('Moves or re-paths this NPC')], true),

            // --------------------------------------------------------- combat
            in_array($name, ['castspell', 'selfcast', 'spelleffect', 'castonparty', 'castedspellfinished', 'spellfinished'], true)
                => ($spells = self::ids($argv[0] ?? null))
                    ? self::entry('combat', array_merge([self::text('Casts ')], $this->refList('spell', $spells)))
                    : self::entry('combat', [self::text('Casts a spell')], true),
            in_array($name, ['attack', 'addtohatelist', 'attacknpctype', 'aggroarea'], true)
                => self::entry('combat', [self::text('Turns hostile')]),
            in_array($name, ['wipehatelist', 'clearhatelist'], true) => self::entry('combat', [self::text('Drops everyone from its hate list')], true),
            in_array($name, ['sethp', 'damage', 'killnpc', 'kill'], true) => self::entry('combat', [self::text('Changes health directly')], true),

            // ---------------------------------------------------------- timing
            in_array($name, ['settimer', 'settimermsec', 'setnexthpevent', 'setnextinchpevent', 'setproximity'], true)
                => $this->timer($name, $argv),
            in_array($name, ['stoptimer', 'stopalltimers', 'pausetimer', 'resumetimer', 'cleartimers'], true)
                => self::entry('misc', [self::text('Stops a timer')], true),
            in_array($name, ['signal', 'signalwith', 'crosszonesignalnpcbynpctypeid'], true) => $this->signal($argv),

            // ----------------------------------------------------------- misc
            $name === 'learnrecipe' => self::entry('misc', [self::text('Teaches you a tradeskill recipe')]),
            in_array($name, ['createexpedition', 'addreplaylockout', 'addlockout'], true)
                => self::entry('misc', [self::text('Sets up an expedition or its lockout')]),
            in_array($name, ['random', 'chooserandom', 'rand'], true) => self::entry('misc', [self::text('Rolls at random')], true),
            in_array($name, ['scribespells', 'traindiscs', 'traindisc'], true) => self::entry('misc', [self::text('Scribes spells or disciplines for you')]),
            in_array($name, ['forcedooropen', 'forcedoorclose', 'toggledoorstate'], true) => self::entry('misc', [self::text('Opens or closes a door')]),
            in_array($name, ['ding'], true) => self::entry('misc', [self::text('Plays the level-up chime')], true),
            in_array($name, [
                'doanim', 'emoteanim', 'wearchange', 'setscale', 'changesize', 'npcsize', 'setappearance',
                'setrace', 'settexture', 'tempname', 'setspecialability', 'setspecialabilityparam',
                'modifynpcstat', 'modskilldmgtaken', 'cameraeffect', 'setentityvariable', 'setdata',
                'saveguardspot', 'debug', 'log',
            ], true) => self::entry('misc', [self::text('Adjusts how this NPC looks or behaves')], true),

            default => null,
        };
    }

    // ---------------------------------------------------------- action helpers

    /** @return array<string, mixed> */
    private function speech(string $kind, string $verb, string $args): array
    {
        $text = $this->dialogue($args);

        return [
            'kind' => $kind,
            'segments' => [self::text($verb . ($text === '' ? '' : ':'))],
            'quote' => $text === '' ? null : $text,
        ];
    }

    /**
     * A hand-in the script takes rather than tests, named where it can be.
     *
     * `quest::takeitems()` with nothing in the parentheses takes whatever is on
     * the trade window, which is all there is to say about it.
     *
     * @return array<string, mixed>
     */
    private function takes(string $args): array
    {
        $items = $this->turnIn($args);

        return $items
            ? self::entry('give', array_merge([self::text('Takes ')], $items, [self::text(' from you')]))
            : self::entry('give', [self::text('Keeps the items you handed in')]);
    }

    /**
     * An item put somewhere by the script, and where.
     *
     * SummonItem only exists on a client, so it always means the player, whoever
     * the script called it on -- in a player script `e.self` is the player too.
     * AddItem is the opposite: it belongs to NPCs and corpses, and stocks loot
     * for the player to take rather than handing anything over. Reading both as
     * "Gives you" put items in players' hands that the quest never gives them.
     *
     * @return array<string, mixed>|null
     */
    private function give(string $name, array $argv, string $recv): ?array
    {
        $ids = self::ids($argv[0] ?? null);

        if (!$ids) {
            return null;
        }

        $count = self::number($argv[1] ?? null) ?? 1;
        $segments = [self::text(match (true) {
            $name !== 'additem' => 'Gives you ',
            str_contains($recv, 'corpse') => 'Adds to the corpse ',
            default => 'Carries ',
        })];

        if ($count > 1) {
            $segments[] = self::em($count . '×');
            $segments[] = self::text(' ');
        }

        return self::entry('give', array_merge($segments, $this->refList('item', $ids)));
    }

    /**
     * An encounter script wiring one of its functions to an event.
     *
     * The scene these produce is titled from the same registration, so this is
     * the index rather than the content -- worth showing, not worth shouting.
     *
     * @return array<string, mixed>|null
     */
    private function registration(array $argv): ?array
    {
        $parts = array_map('trim', $argv);
        $handler = array_pop($parts);
        $event = null;
        $npc = null;

        foreach ($parts as $part) {
            if (preg_match('/Event\.(\w+)/i', $part, $m)) {
                $event = $m[1];
            } elseif (($id = self::number($part)) !== null) {
                $npc = $id;
            }
        }

        if ($event === null) {
            return null;
        }

        $segments = [self::text('Runs '), self::em(str_replace('_', ' ', (string) $handler)), self::text(' ')];
        $segments[] = self::text(lcfirst(self::eventTitle($event)));

        if ($npc !== null) {
            $segments[] = self::text(' (');
            $segments[] = $this->ref('npc', $npc);
            $segments[] = self::text(')');
        }

        return self::entry('misc', $segments, true);
    }

    /**
     * A task pushed onto the list a selector will later offer.
     *
     * The selector itself only ever sees the variable, so without this the
     * scripts that build their offer list at runtime -- the cultural armour
     * artisans, the DoN captains -- would name no tasks at all.
     *
     * @return array<string, mixed>|null
     */
    private function taskList(array $argv): ?array
    {
        if (!preg_match('/task/i', $argv[0] ?? '')) {
            return null;
        }

        $id = self::number($argv[1] ?? null);

        if ($id === null) {
            return null;
        }

        return self::entry('task', [self::text('Adds '), $this->ref('task', $id), self::text(' to the tasks it can offer')]);
    }

    /**
     * QuestReward pays in either shape: a table of named fields, or seven
     * positional arguments where the sixth is the item.
     *
     * @return array<string, mixed>|null
     */
    private function questReward(string $args, array $argv): ?array
    {
        $segments = [self::text('Rewards you with ')];
        $parts = [];
        $copper = 0;

        if (str_contains($args, '{')) {
            $copper += self::copper($args);

            if (preg_match('/\bitemid\s*=\s*(\d+)/i', $args, $m)) {
                $parts[] = [$this->ref('item', (int) $m[1])];
            }

            if (preg_match('/\bitems\s*=\s*\{([\d,\s]+)\}/i', $args, $m)) {
                foreach (preg_split('/[,\s]+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $id) {
                    $parts[] = [$this->ref('item', (int) $id)];
                }
            }

            if (preg_match('/\bexp\s*=\s*(\d+)/i', $args, $m) && (int) $m[1] > 0) {
                $parts[] = [self::em(number_format((int) $m[1]) . ' experience')];
            }

            if (preg_match('/\bfaction\s*=\s*(\d+)/i', $args, $m)) {
                $parts[] = [self::text('faction with '), $this->ref('faction', (int) $m[1])];
            }
        } else {
            foreach ([1 => 1, 2 => 10, 3 => 100, 4 => 1000] as $slot => $worth) {
                $value = trim($argv[$slot] ?? '0');
                $copper += ctype_digit($value) ? (int) $value * $worth : 0;
            }

            $item = trim($argv[5] ?? '');

            if (ctype_digit($item) && (int) $item > 0) {
                $parts[] = [$this->ref('item', (int) $item)];
            }

            $exp = trim($argv[6] ?? '');

            if (ctype_digit($exp) && (int) $exp > 0) {
                $parts[] = [self::em(number_format((int) $exp) . ' experience')];
            } elseif (preg_match('/ExpHelper/i', $exp)) {
                $parts[] = [self::em('experience')];
            }
        }

        if ($copper > 0) {
            $parts[] = [self::em(price($copper))];
        }

        if (!$parts) {
            return null;
        }

        foreach ($parts as $i => $part) {
            if ($i > 0) {
                $segments[] = self::text($i === count($parts) - 1 ? ' and ' : ', ');
            }

            $segments = array_merge($segments, $part);
        }

        return self::entry('give', $segments);
    }

    /** @return array<string, mixed>|null */
    private function experience(array $argv): ?array
    {
        $value = self::number($argv[0] ?? null);

        if ($value === null) {
            return self::entry('reward', [self::text('Grants experience')]);
        }

        return self::entry('reward', [self::text('Grants '), self::em(number_format($value) . ' experience')]);
    }

    /** @return array<string, mixed>|null */
    private function coin(array $argv): ?array
    {
        $copper = 0;

        foreach ([0 => 1, 1 => 10, 2 => 100, 3 => 1000] as $slot => $worth) {
            $copper += (self::number($argv[$slot] ?? null) ?? 0) * $worth;
        }

        if ($copper === 0) {
            return self::entry('reward', [self::text('Gives you coin')]);
        }

        return self::entry('reward', [self::text('Gives you '), self::em(price($copper))]);
    }

    /** @return array<string, mixed>|null */
    private function faction(array $argv): ?array
    {
        $id = self::number($argv[0] ?? null);
        $value = trim($argv[1] ?? '', " \t'\"");

        if ($id === null) {
            return null;
        }

        $segments = [self::text('Faction with '), $this->ref('faction', $id)];

        if (preg_match('/^-?\d+$/', $value)) {
            $segments[] = self::text(' ');
            $segments[] = self::em(((int) $value > 0 ? '+' : '') . $value);
        }

        return self::entry('reward', $segments);
    }

    /** @return array<string, mixed>|null */
    private function setGlobal(array $argv): ?array
    {
        $name = trim($argv[0] ?? '', " \t'\"");
        $value = trim($argv[1] ?? '', " \t'\"");

        if ($name === '') {
            return null;
        }

        $segments = [self::text('Sets the '), self::flag($name), self::text(' quest flag')];

        if ($value !== '') {
            $segments[] = self::text(' to ');
            $segments[] = self::em($value);
        }

        return self::entry('flag', $segments);
    }

    /** @return array<string, mixed>|null */
    private function flagEntry(string $verb, array $argv): ?array
    {
        $name = trim($argv[0] ?? '', " \t'\"");

        if ($name === '') {
            return null;
        }

        return self::entry('flag', [self::text($verb), self::flag($name), self::text(' quest flag')]);
    }

    /** @return array<string, mixed>|null */
    private function tasks(string $verb, array $argv): ?array
    {
        $ids = [];

        foreach ($argv as $arg) {
            foreach (preg_split('/[^\d]+/', $arg, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $id) {
                $ids[] = (int) $id;
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));

        if (!$ids) {
            // A selector handed a variable: the ids went in through the pushes
            // above, which are narrated where they happen.
            return $verb === 'Offers'
                ? self::entry('task', [self::text('Offers the tasks it collected above')])
                : null;
        }

        return self::entry('task', array_merge([self::text($verb . ' ')], $this->refList('task', $ids, ', ')));
    }

    /** @return array<string, mixed>|null */
    private function taskStep(string $name, array $argv): ?array
    {
        $task = self::number($argv[0] ?? null);
        $step = self::number($argv[1] ?? null);

        if ($task === null) {
            return null;
        }

        return self::entry('task', [
            self::text($name === 'resettaskactivity' ? 'Resets step ' : 'Completes step '),
            self::em($step === null ? '?' : (string) ($step + 1)),
            self::text(' of '),
            $this->ref('task', $task),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function movePc(array $argv): ?array
    {
        $zone = self::number($argv[0] ?? null);

        if ($zone === null) {
            return self::entry('move', [self::text('Teleports you somewhere else')]);
        }

        return self::entry('move', [self::text('Teleports you to '), $this->ref('zone', $zone)]);
    }

    /** @return array<string, mixed>|null */
    private function timer(string $name, array $argv): ?array
    {
        if ($name === 'setnexthpevent' || $name === 'setnextinchpevent') {
            $at = trim($argv[0] ?? '');

            return self::entry('misc', [self::text('Waits for its health to reach ' . ($at === '' ? 'a threshold' : $at . '%'))], true);
        }

        if ($name === 'setproximity') {
            return self::entry('misc', [self::text('Watches for players coming close')], true);
        }

        $label = trim($argv[0] ?? '', " \t'\"");

        return self::entry('misc', [self::text('Starts the ' . ($label === '' ? '' : '"' . $label . '" ') . 'timer')], true);
    }

    /** @return array<string, mixed>|null */
    private function signal(array $argv): ?array
    {
        $target = self::number($argv[0] ?? null);

        if ($target !== null) {
            return self::entry('misc', [self::text('Signals '), $this->ref('npc', $target)], true);
        }

        return self::entry('misc', [self::text('Signals another script')], true);
    }

    /**
     * The items a turn-in asks for, as chips, with any coin it wants alongside.
     *
     * A hand-in is written three ways: Perl's `1234 => 2`, Lua's `[1234] = 2`,
     * and the older `{item1 = 1234}` that carries no quantity -- the script's own
     * limit, not this one's. Money rides in the same table under a name rather
     * than an id, so it is picked out separately and read as coin.
     *
     * @return array<int, array<string, mixed>>
     */
    private function turnIn(string $expr): array
    {
        $found = self::pairs($expr);

        if (!$found && preg_match_all('/\bitem\d*\s*=\s*(\d+)/i', $expr, $m)) {
            foreach ($m[1] as $id) {
                $found[] = [(int) $id, 1];
            }
        }

        $parts = [];

        foreach ($found as [$id, $count]) {
            $parts[] = $count > 1
                ? [self::em($count . '×'), self::text(' '), $this->ref('item', $id)]
                : [$this->ref('item', $id)];
        }

        if (($copper = self::copper($expr)) > 0) {
            $parts[] = [self::em(price($copper))];
        }

        $segments = [];

        foreach ($parts as $i => $part) {
            if ($i > 0) {
                $segments[] = self::text($i === count($parts) - 1 ? ' and ' : ', ');
            }

            $segments = array_merge($segments, $part);
        }

        return $segments;
    }

    /**
     * The id => quantity pairs in a hand-in table, in either language's spelling.
     *
     * The id has to stand alone: `{item1 = 2301}` is one item written the older
     * way, not item 1 wanted 2301 times, and it is read below instead.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private static function pairs(string $expr): array
    {
        if (!preg_match_all('/(?<![\w\]])\[?(\d+)\]?\s*(?:=>|=)\s*(\d+)\b/', $expr, $m, PREG_SET_ORDER)) {
            return [];
        }

        return array_map(fn ($match) => [(int) $match[1], (int) $match[2]], $m);
    }

    /** What the coin keys of a hand-in or reward table come to, in copper. */
    private static function copper(string $expr): int
    {
        $copper = 0;

        foreach (self::COINS as $coin => $worth) {
            if (preg_match('/\b' . $coin . '\b[\'"]?\s*(?:=>|=)\s*(\d+)/i', $expr, $m)) {
                $copper += (int) $m[1] * $worth;
            }
        }

        return $copper;
    }

    // ---------------------------------------------------------------- segments

    private static function text(string $value): array
    {
        return ['t' => 'text', 'v' => $value];
    }

    private static function em(string $value): array
    {
        return ['t' => 'em', 'v' => $value];
    }

    private static function code(string $value): array
    {
        return ['t' => 'code', 'v' => $value];
    }

    private static function quote(string $value): array
    {
        return ['t' => 'quote', 'v' => $value];
    }

    private static function flag(string $value): array
    {
        return ['t' => 'flag', 'v' => $value];
    }

    /** Record an id for the caller to resolve, and emit the segment that names it. */
    private function ref(string $type, int $id): array
    {
        if ($id > 0) {
            $this->refs[$type][$id] = $id;
        }

        return ['t' => $type, 'id' => $id];
    }

    /** @return array<string, mixed> */
    private static function entry(string $kind, array $segments, bool $minor = false): array
    {
        return ['kind' => $kind, 'segments' => $segments, 'minor' => $minor];
    }

    /** An argument's integer value, quotes and whitespace and all. */
    private static function number(?string $arg): ?int
    {
        $arg = trim((string) $arg, " \t\n\r'\"");

        return ctype_digit($arg) ? (int) $arg : null;
    }

    /**
     * The ids an argument names -- one, or several when the script rolls for it.
     *
     * `SummonItem(eq.ChooseRandom(1001, 1002))` is one call handing out one of
     * two items, and reading only the first would name the wrong reward half
     * the time.
     *
     * @return array<int, int>
     */
    private static function ids(?string $arg): array
    {
        $one = self::number($arg);

        if ($one !== null) {
            return [$one];
        }

        if (!preg_match('/\bchoose_?random\s*\((.*)\)/is', (string) $arg, $m)) {
            return [];
        }

        $out = [];

        foreach (self::splitArgs($m[1]) as $part) {
            $value = self::number($part);

            if ($value !== null) {
                $out[] = $value;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Run a list of ids together as "a", "a or b", "a, b or c".
     *
     * @return array<int, array<string, mixed>>
     */
    private function refList(string $type, array $ids, string $last = ' or '): array
    {
        $segments = [];

        foreach (array_values($ids) as $i => $id) {
            if ($i > 0) {
                $segments[] = self::text($i === count($ids) - 1 ? $last : ', ');
            }

            $segments[] = $this->ref($type, $id);
        }

        return $segments;
    }

    // ------------------------------------------------------------------ syntax

    /**
     * The calls a statement makes, outermost first.
     *
     * The receiver is thrown away and each name folded to lowercase with the
     * underscores removed, which is what lets one arm of the table above answer
     * for `quest::summonitem`, `e.other:SummonItem` and `summon_item` alike.
     *
     * More than one is returned because the call that does the work is not
     * always the first one written: it can sit behind a cast, or inside a push.
     * The caller reads them in order and stops at the first it recognises, so a
     * statement that was understood before is still understood the same way.
     *
     * @return array<int, array{name: string, recv: string, args: string, argv: array<int, string>}>
     */
    private static function calls(string $code): array
    {
        $mask = self::maskStrings($code);
        $out = [];
        $offset = 0;

        // Six is well past any real statement; it is here so a generated line of
        // hundreds of calls cannot turn one script into a slow page.
        while (count($out) < 6 && ($open = strpos($mask, '(', $offset)) !== false) {
            $offset = $open + 1;

            if (!preg_match('/([\w.$>:-]*?)([A-Za-z_]\w*)\s*$/', substr($mask, 0, $open), $m)) {
                continue;
            }

            $depth = 0;
            $close = null;

            for ($i = $open, $n = strlen($mask); $i < $n; $i++) {
                if ($mask[$i] === '(') {
                    $depth++;
                } elseif ($mask[$i] === ')' && --$depth === 0) {
                    $close = $i;

                    break;
                }
            }

            if ($close === null) {
                break;
            }

            $args = substr($code, $open + 1, $close - $open - 1);

            $out[] = [
                'name' => strtolower(str_replace('_', '', $m[2])),
                'recv' => strtolower($m[1]),
                'args' => $args,
                'argv' => self::splitArgs($args),
            ];
        }

        return $out;
    }

    /** Top-level comma split; nested calls and tables stay in one piece. */
    private static function splitArgs(string $args): array
    {
        $mask = self::maskStrings($args);
        $parts = [];
        $start = 0;
        $depth = 0;

        for ($i = 0, $n = strlen($mask); $i < $n; $i++) {
            $char = $mask[$i];

            if ($char === '(' || $char === '{' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === '}' || $char === ']') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = substr($args, $start, $i - $start);
                $start = $i + 1;
            }
        }

        $parts[] = substr($args, $start);

        return $parts;
    }

    /**
     * Split an expression on its top-level `and` / `or`.
     *
     * @return array<int, array{0: string, 1: string}> [clause, the word joining it to the one before]
     */
    private static function splitLogical(string $expr): array
    {
        $mask = self::maskStrings($expr);
        $out = [];
        $start = 0;
        $depth = 0;
        $join = 'and';

        for ($i = 0, $n = strlen($mask); $i < $n; $i++) {
            $char = $mask[$i];

            if ($char === '(' || $char === '{' || $char === '[') {
                $depth++;

                continue;
            }

            if ($char === ')' || $char === '}' || $char === ']') {
                $depth--;

                continue;
            }

            if ($depth !== 0) {
                continue;
            }

            // Matched at an offset into the whole string rather than against a
            // substring of it: cutting the string first hides what comes before
            // from \b, and `rand <= 33` would split on the `and` inside `rand`.
            if (!preg_match('/\G(&&|\|\||\band\b|\bor\b)/i', $mask, $m, 0, $i)) {
                continue;
            }

            $out[] = [substr($expr, $start, $i - $start), $join];
            $join = in_array(strtolower($m[1]), ['||', 'or'], true) ? 'or' : 'and';
            $start = $i + strlen($m[1]);
            $i += strlen($m[1]) - 1;
        }

        $out[] = [substr($expr, $start), $join];

        return array_values(array_filter($out, fn ($part) => trim($part[0]) !== ''));
    }

    /** Peel the parentheses off an expression that is wrapped in exactly one pair. */
    private static function unwrap(string $expr): string
    {
        $expr = trim($expr);

        while (strlen($expr) > 1 && $expr[0] === '(' && str_ends_with($expr, ')')) {
            $depth = 0;
            $mask = self::maskStrings($expr);

            for ($i = 0, $n = strlen($mask); $i < $n; $i++) {
                if ($mask[$i] === '(') {
                    $depth++;
                } elseif ($mask[$i] === ')' && --$depth === 0 && $i !== $n - 1) {
                    return $expr;
                }
            }

            $expr = trim(substr($expr, 1, -1));
        }

        return $expr;
    }

    /**
     * The line of dialogue a say/emote call delivers.
     *
     * Dialogue is written as a concatenation -- literal, say-link, literal --
     * so the pieces are stitched back together in order. A say-link contributes
     * the word it displays, and anything else (a name variable, a format call)
     * contributes nothing, because there is no value here to put in its place.
     */
    private function dialogue(string $args): string
    {
        $out = '';

        foreach (self::splitConcat($args) as $part) {
            $part = trim($part);

            if (preg_match('/\bsay_?link\s*\(/i', $part)) {
                $literals = self::literals($part);
                $out .= $literals ? end($literals) : '';

                continue;
            }

            foreach (self::literals($part) as $literal) {
                $out .= $literal;
            }
        }

        return self::readable($out);
    }

    /** @return array<int, string> */
    private static function splitConcat(string $args): array
    {
        $mask = self::maskStrings($args);
        $parts = [];
        $start = 0;
        $depth = 0;

        for ($i = 0, $n = strlen($mask); $i < $n; $i++) {
            $char = $mask[$i];

            if ($char === '(' || $char === '{' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === '}' || $char === ']') {
                $depth--;
            } elseif ($depth === 0 && ($char === ',' || $char === '.')) {
                $parts[] = substr($args, $start, $i - $start);
                $start = $i + 1;
            }
        }

        $parts[] = substr($args, $start);

        return $parts;
    }

    /** @return array<int, string> */
    private static function literals(string $text): array
    {
        if (!preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\'/s', $text, $m, PREG_SET_ORDER)) {
            return [];
        }

        return array_map(fn ($match) => ($match[2] ?? '') !== '' ? $match[2] : $match[1], $m);
    }

    /** Undo the escaping and whitespace of a source literal so it reads as prose. */
    private static function readable(string $text): string
    {
        $text = stripcslashes($text);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Neutralise string and regex bodies while keeping every offset in place.
     *
     * Perl match operators are covered as well as quotes: `$text =~ /a sword and
     * shield/i` has a top-level `and` in it that would otherwise split the
     * condition in half.
     */
    private static function maskStrings(string $text): string
    {
        $text = preg_replace_callback(
            '/"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'/s',
            fn ($m) => $m[0][0] . str_repeat("\x01", max(0, strlen($m[0]) - 2)) . $m[0][0],
            $text
        ) ?? $text;

        return preg_replace_callback(
            '#(=~\s*m?/)((?:[^/\\\\]|\\\\.)*)(/)#',
            fn ($m) => $m[1] . str_repeat("\x01", strlen($m[2])) . $m[3],
            $text
        ) ?? $text;
    }

    private static function constant(string $name): string
    {
        if ($name === '') {
            return '';
        }

        return self::CLASSES[strtoupper($name)] ?? ucwords(strtolower(str_replace('_', ' ', $name)));
    }

    // ------------------------------------------------------------------ titles

    /** @var array<string, string> */
    private const EVENTS = [
        'say' => 'When you talk to this NPC',
        'proximitysay' => 'When you talk near this NPC',
        'item' => 'When you hand something in',
        'trade' => 'When you hand something in',
        'spawn' => 'When this NPC spawns',
        'death' => 'When this NPC is killed',
        'deathcomplete' => 'When this NPC is killed',
        'slay' => 'When this NPC kills someone',
        'npcslay' => 'When this NPC kills another NPC',
        'killedmerit' => 'When a group kills this NPC',
        'timer' => 'On a timer',
        'tick' => 'Every few seconds',
        'signal' => 'When another script signals this one',
        'combat' => 'When combat starts or ends',
        'aggro' => 'When this NPC becomes hostile',
        'attack' => 'When this NPC attacks',
        'hp' => 'At a health threshold',
        'enter' => 'When you step into range',
        'exit' => 'When you leave its range',
        'enterzone' => 'When you enter the zone',
        'zone' => 'When you zone',
        'connect' => 'When you log in',
        'clickdoor' => 'When a door or object is clicked',
        'clickobject' => 'When an object is clicked',
        'itemclick' => 'When an item is clicked',
        'itemclickcast' => 'When a clicky item finishes casting',
        'waypointarrive' => 'When it reaches a waypoint',
        'waypointdepart' => 'When it leaves a waypoint',
        'loot' => 'When its corpse is looted',
        'taskaccepted' => 'When you accept a task',
        'taskcomplete' => 'When you complete a task',
        'taskstagecomplete' => 'When you finish part of a task',
        'taskfail' => 'When you fail a task',
        'popupresponse' => 'When you answer a popup window',
        'spelleffect' => 'When a spell lands on it',
        'spelleffectclient' => 'When a spell lands on you',
        'spelleffectnpc' => 'When a spell lands on an NPC',
        'spelleffecttranslocatecomplete' => 'When a translocate finishes',
        'caston' => 'When a spell is cast on it',
        'cast' => 'When it casts a spell',
        'encounterload' => 'When the encounter starts',
        'encounterunload' => 'When the encounter ends',
        'scalecalc' => 'When it is scaled to the zone',
        'combinesuccess' => 'When a tradeskill combine succeeds',
        'combinevalidate' => 'When a tradeskill combine is checked',
        'fishsuccess' => 'When you catch a fish',
        'foragesuccess' => 'When you forage something',
        'levelup' => 'When you gain a level',
        'targetchange' => 'When it changes target',
        'win' => 'When the encounter is won',
        'reset' => 'When the encounter resets',
        'itementerzone' => 'When the item enters the zone',
        'command' => 'When a command is used',
    ];

    private static function eventTitle(string $event): string
    {
        $key = strtolower(str_replace('_', '', preg_replace('/^event_?/i', '', $event) ?? $event));

        return self::EVENTS[$key] ?? 'On ' . strtolower(str_replace('_', ' ', preg_replace('/^EVENT_/i', '', $event) ?? $event));
    }
}
