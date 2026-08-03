<?php

namespace App\Console\Commands;

use App\Models\QuestScript;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Walk the server's quests/ tree and build the NPC <-> script <-> item index.
 *
 * EQEmu keeps quests as Perl/Lua files on disk; the game database has no idea
 * they exist. That is why a stock allaclone shows no quest information at all.
 * This command closes that gap by resolving each script to the NPC it drives and
 * extracting the item IDs it hands in, summons, or names.
 *
 * Every extracted ID is validated against peq before it is stored, which is what
 * keeps the regexes honest -- timers, coordinates, spell IDs and gold amounts are
 * all plausible-looking integers, and only the ones that are really items or NPCs
 * survive.
 */
class IndexQuests extends Command
{
    protected $signature = 'quests:index {--path= : Override the quests root}';

    protected $description = 'Index quest scripts and cross-reference them to NPCs and items';

    /** Directories under quests/ that are library code, not NPC scripts. */
    private const SKIP_DIRS = ['plugins', 'templates', 'lua_modules', 'items', '.git', 'bots'];

    public function handle(): int
    {
        $root = rtrim($this->option('path') ?: config('everquest.quests_root'), '/');

        if (!is_dir($root)) {
            $this->error("Quests root not readable: {$root}");
            return self::FAILURE;
        }

        $this->info("Indexing quest scripts under {$root}");

        $files = $this->collectFiles($root);
        $this->info(sprintf('Found %d script files', count($files)));

        if (!$files) {
            return self::SUCCESS;
        }

        $this->line('Building NPC name maps from peq...');
        [$byZone, $byName, $zoneIds] = $this->buildNpcMaps();

        $rows = [];
        $itemRefs = [];   // relative_path => [item_id => kind]
        $npcRefs = [];    // relative_path => [npc_id]
        $taskRefs = [];   // relative_path => [task_id => kind]

        $bar = $this->output->createProgressBar(count($files));
        $bar->start();

        foreach ($files as $file) {
            $relative = ltrim(substr($file, strlen($root)), '/');
            $parts = explode('/', $relative);
            $zone = count($parts) > 1 ? $parts[0] : 'global';
            $fileName = basename($file);
            $base = preg_replace('/\.(lua|pl)$/', '', $fileName);
            $body = @file_get_contents($file);

            if ($body === false) {
                $bar->advance();
                continue;
            }

            [$npcId, $ambiguous] = $this->resolveNpc($base, $zone, $byZone, $byName, $zoneIds);

            $rows[] = [
                'zone' => $zone,
                'file_name' => $fileName,
                'relative_path' => $relative,
                'language' => str_ends_with($fileName, '.lua') ? 'lua' : 'pl',
                'npc_name' => ctype_digit($base) ? null : $base,
                'npc_id' => $npcId,
                'npc_ambiguous' => $ambiguous,
                'bytes' => strlen($body),
                'sha1' => sha1($body),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $itemRefs[$relative] = $this->extractItems($body);
            $npcRefs[$relative] = $this->extractNpcs($body);
            $taskRefs[$relative] = $this->extractTasks($body);

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->line('Validating extracted IDs against peq...');
        $validItems = $this->validIds('items', collect($itemRefs)->flatMap(fn ($refs) => array_column($refs, 'item_id'))->unique()->all());
        $validNpcs = $this->validIds('npc_types', collect($npcRefs)->flatten()->unique()->all());
        $validTasks = $this->validIds('tasks', collect($taskRefs)->flatMap(fn ($m) => array_keys($m))->unique()->all());

        $this->line(sprintf(
            'Item refs: %d candidates -> %d real items | NPC refs: %d -> %d | Task refs: %d -> %d',
            collect($itemRefs)->flatMap(fn ($refs) => array_column($refs, 'item_id'))->unique()->count(),
            count($validItems),
            collect($npcRefs)->flatten()->unique()->count(),
            count($validNpcs),
            collect($taskRefs)->flatMap(fn ($m) => array_keys($m))->unique()->count(),
            count($validTasks),
        ));

        $this->line('Writing index...');
        DB::transaction(function () use ($rows, $itemRefs, $npcRefs, $taskRefs, $validItems, $validNpcs, $validTasks) {
            // Full rebuild: cascades clear the child tables.
            QuestScript::query()->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                QuestScript::insert($chunk);
            }

            $ids = QuestScript::pluck('id', 'relative_path');
            $itemRows = [];
            $npcRows = [];
            $taskRows = [];

            foreach ($itemRefs as $path => $refs) {
                $scriptId = $ids[$path] ?? null;
                if (!$scriptId) continue;

                foreach ($refs as $ref) {
                    if (isset($validItems[$ref['item_id']])) {
                        $itemRows[] = ['quest_script_id' => $scriptId] + $ref;
                    }
                }
            }

            foreach ($npcRefs as $path => $list) {
                $scriptId = $ids[$path] ?? null;
                if (!$scriptId) continue;

                foreach (array_unique($list) as $npcId) {
                    if (isset($validNpcs[$npcId])) {
                        $npcRows[] = ['quest_script_id' => $scriptId, 'npc_id' => $npcId];
                    }
                }
            }

            foreach ($taskRefs as $path => $map) {
                $scriptId = $ids[$path] ?? null;
                if (!$scriptId) continue;

                foreach ($map as $taskId => $kind) {
                    if (isset($validTasks[$taskId])) {
                        $taskRows[] = ['quest_script_id' => $scriptId, 'task_id' => $taskId, 'kind' => $kind];
                    }
                }
            }

            foreach (array_chunk($itemRows, 500) as $chunk) {
                DB::table('quest_script_items')->insertOrIgnore($chunk);
            }
            foreach (array_chunk($npcRows, 500) as $chunk) {
                DB::table('quest_script_npcs')->insertOrIgnore($chunk);
            }
            foreach (array_chunk($taskRows, 500) as $chunk) {
                DB::table('quest_script_tasks')->insertOrIgnore($chunk);
            }

            $this->info(sprintf('Indexed %d scripts, %d item links, %d npc links, %d task links',
                count($rows), count($itemRows), count($npcRows), count($taskRows)));
        });

        $linked = QuestScript::whereNotNull('npc_id')->count();
        $this->info(sprintf('%d of %d scripts resolved to an NPC', $linked, count($rows)));

        return self::SUCCESS;
    }

    /** @return string[] absolute paths */
    private function collectFiles(string $root): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $f) {
            if (!$f->isFile()) continue;

            $ext = strtolower($f->getExtension());
            if ($ext !== 'lua' && $ext !== 'pl') continue;

            $relative = ltrim(substr($f->getPathname(), strlen($root)), '/');
            $top = explode('/', $relative)[0];
            if (in_array($top, self::SKIP_DIRS, true)) continue;

            $out[] = $f->getPathname();
        }

        sort($out);
        return $out;
    }

    /**
     * npc_types has no zone column, so the (zone, name) map is built through the
     * spawn tables. A global name map is the fallback for script-only NPCs that
     * are never placed by spawn2.
     */
    private function buildNpcMaps(): array
    {
        $byZone = [];
        DB::connection('eqemu')->table('spawn2')
            ->join('spawnentry', 'spawn2.spawngroupID', '=', 'spawnentry.spawngroupID')
            ->join('npc_types', 'spawnentry.npcID', '=', 'npc_types.id')
            ->select('spawn2.zone', 'npc_types.name', 'npc_types.id')
            ->distinct()
            ->orderBy('npc_types.id')
            ->chunk(20000, function ($chunk) use (&$byZone) {
                foreach ($chunk as $r) {
                    $zone = strtolower($r->zone);
                    $byZone[$zone][strtolower($r->name)][] = (int) $r->id;

                    // Filename-safe alias (see self::filesystemKey()).
                    $alias = self::filesystemKey($r->name);
                    if ($alias !== strtolower($r->name)) {
                        $byZone[$zone][$alias][] = (int) $r->id;
                    }
                }
            });

        $byName = [];
        DB::connection('eqemu')->table('npc_types')
            ->select('id', 'name')
            ->orderBy('id')
            ->chunk(20000, function ($chunk) use (&$byName) {
                foreach ($chunk as $r) {
                    $byName[strtolower($r->name)][] = (int) $r->id;

                    $alias = self::filesystemKey($r->name);
                    if ($alias !== strtolower($r->name)) {
                        $byName[$alias][] = (int) $r->id;
                    }
                }
            });

        $zoneIds = DB::connection('eqemu')->table('zone')
            ->pluck('zoneidnumber', 'short_name')
            ->map(fn ($v) => (int) $v)
            ->all();

        return [$byZone, $byName, $zoneIds];
    }

    /** @return array{0: ?int, 1: bool} [npc_id, ambiguous] */
    private function resolveNpc(string $base, string $zone, array $byZone, array $byName, array $zoneIds): array
    {
        // Scripts named by NPC id are unambiguous.
        if (ctype_digit($base)) {
            return [(int) $base, false];
        }

        $key = strtolower($base);
        $zoneKey = strtolower($zone);

        // Best case: this NPC actually spawns in this zone.
        $candidates = $byZone[$zoneKey][$key] ?? [];

        // Names carrying characters that are unsafe in a filename (backticks and
        // apostrophes, which are everywhere in EQ names) are written to disk with
        // '-' substituted, so `#De`van_Szostek` becomes #De-van_Szostek.lua.
        if (!$candidates) {
            $candidates = $byZone[$zoneKey][self::filesystemKey($base)] ?? [];
        }
        if (count($candidates) === 1) {
            return [$candidates[0], false];
        }
        if (count($candidates) > 1) {
            return [min($candidates), true];
        }

        // Script-only NPC (spawned by another script, never in spawn2).
        $global = $byName[$key] ?? $byName[self::filesystemKey($base)] ?? [];
        if (count($global) === 1) {
            return [$global[0], false];
        }
        if (count($global) > 1) {
            // PEQ numbers NPCs as zoneidnumber * 1000 + n, so prefer one that
            // belongs to this zone before falling back to the lowest id.
            $zoneId = $zoneIds[$zoneKey] ?? null;
            if ($zoneId !== null) {
                foreach ($global as $id) {
                    if (intdiv($id, 1000) === $zoneId) {
                        return [$id, false];
                    }
                }
            }
            return [min($global), true];
        }

        return [null, false];
    }

    /**
     * Lowercased name with every character that the quest loader cannot put in a
     * filename collapsed to '-'. Underscore and '#' are kept because EQEmu uses
     * them verbatim (spaces are already '_' in npc_types.name).
     */
    private static function filesystemKey(string $name): string
    {
        return preg_replace('/[^a-z0-9_#]/', '-', strtolower($name));
    }

    /**
     * Item references, each tagged with the turn-in that gates it.
     *
     * A script is one file per NPC, not one per quest: the Skyshrine armourer
     * runs seven quests out of a single EVENT_ITEM and the Ocean of Tears robe
     * has two dozen interchangeable turn-ins. What a reward *costs* therefore
     * has no answer at script level, only per branch, and the only structure a
     * regex can see is source order -- a reward belongs to the check most
     * recently opened above it, and a sub/function boundary closes whatever was
     * open. Branch 0 is "under no check at all": a hail that hands you a note,
     * or a turn-in table built too far from its call to pair up.
     *
     * @return array<int, array{item_id: int, kind: string, branch: int}>
     */
    private function extractItems(string $body): array
    {
        $rows = [];
        $seen = [];

        $add = function ($id, string $kind, int $branch) use (&$rows, &$seen) {
            $id = (int) $id;
            $key = "{$kind}:{$branch}:{$id}";

            if ($id <= 0 || isset($seen[$key])) {
                return;
            }

            $seen[$key] = true;
            $rows[] = ['item_id' => $id, 'kind' => $kind, 'branch' => $branch];
        };

        $branches = $this->turnInBranches($body);

        foreach ($branches as $branch) {
            foreach ($branch['items'] as $id) {
                $add($id, 'handin', $branch['branch']);
            }
        }

        // Turn-in tables that sit outside any call we could pair them with --
        // built into a variable, or passed through a helper. They are real
        // handins, they just cannot gate anything.
        if (preg_match_all('/\bitem\d*\s*=\s*(\d+)/i', $body, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as [$id, $at]) {
                if (!$this->withinBranch($at, $branches)) {
                    $add($id, 'handin', 0);
                }
            }
        }

        $boundaries = $this->handlerBoundaries($body);

        foreach ($this->rewardReferences($body) as [$id, $at]) {
            $add($id, 'reward', $this->branchAt($at, $branches, $boundaries));
        }

        // The tree's own convention: a `-- items: 1,2,3` / `# items: 1,2,3`
        // header. It is a backstop for what the patterns above cannot see, so
        // it only speaks for items they did not already find.
        $claimed = array_flip(array_column($rows, 'item_id'));

        if (preg_match_all('/^\s*(?:--|#)\s*items?\s*:\s*([\d,\s]+)$/mi', $body, $m)) {
            foreach ($m[1] as $list) {
                foreach (preg_split('/[,\s]+/', trim($list), -1, PREG_SPLIT_NO_EMPTY) as $id) {
                    if (!isset($claimed[(int) $id])) {
                        $add($id, 'mentioned', 0);
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * Every turn-in check in the script, numbered from 1 in source order.
     *
     * Only the keyed forms count as items: perl writes `1234 => 1` and lua
     * `{item1 = 1234}`, while a bare number in the same call is a quantity or,
     * in `{gold = 25000}`, a pile of coin that happens to look like an item id.
     *
     * @return array<int, array{branch: int, offset: int, end: int, items: int[]}>
     */
    private function turnInBranches(string $body): array
    {
        if (!preg_match_all('/\b(?:check_handin|check_turn_in)\s*\(/i', $body, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $branches = [];

        foreach ($m[0] as [$match, $at]) {
            $open = $at + strlen($match) - 1;
            $args = $this->callArguments($body, $open);

            if ($args === null) {
                continue;
            }

            $items = [];

            foreach (['/(\d+)\s*=>\s*\d+/', '/\bitem\d*\s*=\s*(\d+)/i'] as $re) {
                if (preg_match_all($re, $args, $mm)) {
                    $items = array_merge($items, array_map('intval', $mm[1]));
                }
            }

            if (!$items) {
                continue;
            }

            $branches[] = [
                'branch' => count($branches) + 1,
                'offset' => $at,
                'end' => $open + strlen($args) + 2,
                'items' => array_values(array_unique($items)),
            ];
        }

        return $branches;
    }

    /**
     * Items a script gives out, with the offset each is named at.
     *
     * `QuestReward` comes in two shapes and is worth the trouble: it is how a
     * thousand of the lua scripts pay out, and summonitem() alone misses all
     * of them.
     *
     * @return array<int, array{0: int, 1: int}> [item_id, offset]
     */
    private function rewardReferences(string $body): array
    {
        $found = [];

        if (preg_match_all('/\b(?:summonitem|additem)\s*\(\s*(\d+)/i', $body, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as [$id, $at]) {
                $found[] = [(int) $id, $at];
            }
        }

        if (!preg_match_all('/\bQuestReward\s*\(/i', $body, $m, PREG_OFFSET_CAPTURE)) {
            return $found;
        }

        foreach ($m[0] as [$match, $at]) {
            $args = $this->callArguments($body, $at + strlen($match) - 1);

            if ($args === null) {
                continue;
            }

            // Table form: QuestReward(npc, {itemid = 1234}) / {items = {1, 2}}
            if (str_contains($args, '{')) {
                foreach (['/\bitemid\s*=\s*(\d+)/i', '/\bitems\s*=\s*\{([\d,\s]+)\}/i'] as $re) {
                    if (preg_match_all($re, $args, $mm)) {
                        foreach ($mm[1] as $list) {
                            foreach (preg_split('/[,\s]+/', $list, -1, PREG_SPLIT_NO_EMPTY) as $id) {
                                $found[] = [(int) $id, $at];
                            }
                        }
                    }
                }

                continue;
            }

            // Positional: QuestReward(npc, copper, silver, gold, platinum, item, exp).
            // Anything but a literal in the item slot is a variable or a random
            // pick, which names no single item.
            $slot = trim($this->splitArguments($args)[5] ?? '');

            if (ctype_digit($slot)) {
                $found[] = [(int) $slot, $at];
            }
        }

        return $found;
    }

    /** Offsets where a new sub / function starts, closing any open turn-in. */
    private function handlerBoundaries(string $body): array
    {
        preg_match_all('/^[ \t]*(?:local\s+)?(?:sub|function)\b/mi', $body, $m, PREG_OFFSET_CAPTURE);

        return array_column($m[0] ?? [], 1);
    }

    /** The branch a reward at this offset sits under, or 0 for none. */
    private function branchAt(int $offset, array $branches, array $boundaries): int
    {
        $open = null;

        foreach ($branches as $branch) {
            if ($branch['offset'] >= $offset) {
                break;
            }

            $open = $branch;
        }

        if ($open === null) {
            return 0;
        }

        foreach ($boundaries as $at) {
            if ($at > $open['offset'] && $at < $offset) {
                return 0;
            }
        }

        return $open['branch'];
    }

    private function withinBranch(int $offset, array $branches): bool
    {
        foreach ($branches as $branch) {
            if ($offset >= $branch['offset'] && $offset <= $branch['end']) {
                return true;
            }
        }

        return false;
    }

    /**
     * The text between a call's parentheses. Counting depth rather than
     * stopping at the first ')' is what keeps `{items = {1, 2}}` and
     * `eq.ExpHelper(15)` from truncating the argument list.
     */
    private function callArguments(string $body, int $open): ?string
    {
        $depth = 0;
        $length = strlen($body);

        for ($i = $open; $i < $length; $i++) {
            $char = $body[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')' && --$depth === 0) {
                return substr($body, $open + 1, $i - $open - 1);
            }
        }

        return null;
    }

    /** Top-level comma split; nested calls and tables stay in one piece. */
    private function splitArguments(string $args): array
    {
        $parts = [];
        $current = '';
        $depth = 0;

        foreach (str_split($args) as $char) {
            if ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            if ($char === '(' || $char === '{' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === '}' || $char === ']') {
                $depth--;
            }

            $current .= $char;
        }

        $parts[] = $current;

        return $parts;
    }

    /**
     * Task IDs a script drives. Argument shapes matter here: taskselector() and
     * enabletask() are variadic and every number in the call is a task, while
     * updatetaskactivity() and friends take the task id FIRST and an ACTIVITY id
     * second -- collecting both would invent tasks out of activity numbers.
     *
     * Two families are deliberately left out: `*inset` / `*taskcount` take a task
     * SET id, and the crosszone* / worldwide* variants lead with a character,
     * group or guild id. Both perl (assigntask) and lua (assign_task) spellings
     * are matched.
     *
     * @return array<int, string> task_id => kind
     */
    private function extractTasks(string $body): array
    {
        $found = [];

        $add = function (array $ids, string $kind) use (&$found) {
            foreach ($ids as $id) {
                $id = (int) $id;
                if ($id <= 0) continue;
                // offer beats update beats mentioned
                $rank = ['offer' => 3, 'update' => 2, 'mentioned' => 1];
                if (!isset($found[$id]) || $rank[$kind] > $rank[$found[$id]]) {
                    $found[$id] = $kind;
                }
            }
        };

        // Variadic: every number in the call is a task id.
        foreach ([
            'offer' => ['task_?selector(?:_nocooldown)?', 'enable_?task'],
            'update' => ['disable_?task'],
        ] as $kind => $names) {
            foreach ($names as $name) {
                if (preg_match_all('/\b' . $name . '\s*\(\s*\{?([\d,\s]+)/i', $body, $m)) {
                    foreach ($m[1] as $list) {
                        $add(preg_split('/[,\s]+/', trim($list), -1, PREG_SPLIT_NO_EMPTY), $kind);
                    }
                }
            }
        }

        // Task id is the first argument; whatever follows is an activity id or a flag.
        foreach ([
            'offer' => ['assign_?task'],
            'update' => [
                'update_?task_?activity', 'reset_?task_?activity', 'fail_?task',
                'complete_?task', 'uncomplete_?task',
            ],
            'mentioned' => [
                'is_?task_?active', 'is_?task_?activity_?active', 'is_?task_?completed',
                'is_?task_?appropriate', 'is_?task_?enabled', 'task_?time_?left',
                'get_?task_?activity_?done_?count', 'get_?task_?name',
            ],
        ] as $kind => $names) {
            foreach ($names as $name) {
                if (preg_match_all('/\b' . $name . '\s*\(\s*(\d+)/i', $body, $m)) {
                    $add($m[1], $kind);
                }
            }
        }

        // Selector lists built at runtime: lua `table.insert(task_array, 5784)`,
        // perl `push(@task_array, 500146)`, and data tables carrying
        // `task_id = 5501` entries. The selector call itself only ever sees a
        // variable, so without these patterns whole scripts (the cultural armor
        // artisans, the DoN captains) index with no tasks at all. They count as
        // offers only when the script really drives a selector; `task_id == n`
        // comparisons cannot match because the literal '=' consumes the first
        // equals sign and `\d` rejects the second.
        $kind = preg_match('/\b(?:task_?selector|assign_?task|enable_?task)\s*\(/i', $body)
            ? 'offer' : 'mentioned';

        foreach ([
            '/table\.insert\s*\(\s*\w*task\w*\s*,\s*(\d+)/i',
            '/\bpush\s*\(\s*@\w*task\w*\s*,\s*(\d+)/i',
            '/\btask_id\s*=\s*(\d+)/i',
        ] as $re) {
            if (preg_match_all($re, $body, $m)) {
                $add($m[1], $kind);
            }
        }

        // The counterpart to the `-- items: 1,2,3` header, for scripts that drive
        // tasks through a lookup table or a variable, where no literal call names
        // the id: `# tasks: 1,2,3`.
        if (preg_match_all('/^\s*(?:--|#)\s*tasks?\s*:\s*([\d,\s]+)$/mi', $body, $m)) {
            foreach ($m[1] as $list) {
                $add(preg_split('/[,\s]+/', trim($list), -1, PREG_SPLIT_NO_EMPTY), 'mentioned');
            }
        }

        return $found;
    }

    /** @return int[] */
    private function extractNpcs(string $body): array
    {
        $ids = [];

        foreach ([
            '/\bspawn2\s*\(\s*(\d+)/i',
            '/\bunique_spawn\s*\(\s*(\d+)/i',
            '/\bSpawn2\s*\(\s*(\d+)/',
        ] as $re) {
            if (preg_match_all($re, $body, $m)) {
                $ids = array_merge($ids, array_map('intval', $m[1]));
            }
        }

        return array_values(array_unique(array_filter($ids, fn ($i) => $i > 0)));
    }

    /**
     * Keep only IDs that really exist in peq. This is what stops timers,
     * coordinates and gold amounts from being indexed as items.
     *
     * @return array<int, true>
     */
    private function validIds(string $table, array $ids): array
    {
        $valid = [];

        foreach (array_chunk($ids, 5000) as $chunk) {
            $rows = DB::connection('eqemu')->table($table)
                ->whereIn('id', $chunk)
                ->pluck('id');

            foreach ($rows as $id) {
                $valid[(int) $id] = true;
            }
        }

        return $valid;
    }
}
