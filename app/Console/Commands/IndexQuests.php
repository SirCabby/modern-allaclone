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

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->line('Validating extracted IDs against peq...');
        $validItems = $this->validIds('items', collect($itemRefs)->flatMap(fn ($m) => array_keys($m))->unique()->all());
        $validNpcs = $this->validIds('npc_types', collect($npcRefs)->flatten()->unique()->all());

        $this->line(sprintf(
            'Item refs: %d candidates -> %d real items | NPC refs: %d -> %d',
            collect($itemRefs)->flatMap(fn ($m) => array_keys($m))->unique()->count(),
            count($validItems),
            collect($npcRefs)->flatten()->unique()->count(),
            count($validNpcs),
        ));

        $this->line('Writing index...');
        DB::transaction(function () use ($rows, $itemRefs, $npcRefs, $validItems, $validNpcs) {
            // Full rebuild: cascades clear the child tables.
            QuestScript::query()->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                QuestScript::insert($chunk);
            }

            $ids = QuestScript::pluck('id', 'relative_path');
            $itemRows = [];
            $npcRows = [];

            foreach ($itemRefs as $path => $map) {
                $scriptId = $ids[$path] ?? null;
                if (!$scriptId) continue;

                foreach ($map as $itemId => $kind) {
                    if (isset($validItems[$itemId])) {
                        $itemRows[] = ['quest_script_id' => $scriptId, 'item_id' => $itemId, 'kind' => $kind];
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

            foreach (array_chunk($itemRows, 500) as $chunk) {
                DB::table('quest_script_items')->insertOrIgnore($chunk);
            }
            foreach (array_chunk($npcRows, 500) as $chunk) {
                DB::table('quest_script_npcs')->insertOrIgnore($chunk);
            }

            $this->info(sprintf('Indexed %d scripts, %d item links, %d npc links',
                count($rows), count($itemRows), count($npcRows)));
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
     * @return array<int, string> item_id => kind
     */
    private function extractItems(string $body): array
    {
        $found = [];

        $add = function (array $ids, string $kind) use (&$found) {
            foreach ($ids as $id) {
                $id = (int) $id;
                if ($id <= 0) continue;
                // handin beats reward beats mentioned
                $rank = ['handin' => 3, 'reward' => 2, 'mentioned' => 1];
                if (!isset($found[$id]) || $rank[$kind] > $rank[$found[$id]]) {
                    $found[$id] = $kind;
                }
            }
        };

        // Turn-in tables: lua `{item1 = 1234, item2 = 5678}`
        if (preg_match_all('/\bitem\d*\s*=\s*(\d+)/i', $body, $m)) {
            $add($m[1], 'handin');
        }

        // Perl handins: `plugin::check_handin(\%itemcount, 1234 => 1)`
        if (preg_match_all('/check_handin\s*\((.*?)\)/is', $body, $blocks)) {
            foreach ($blocks[1] as $block) {
                if (preg_match_all('/(\d+)\s*=>\s*\d+/', $block, $mm)) {
                    $add($mm[1], 'handin');
                }
            }
        }

        // Lua turn-ins spelled out as a table literal
        if (preg_match_all('/check_turn_in\s*\((.*?)\)/is', $body, $blocks)) {
            foreach ($blocks[1] as $block) {
                if (preg_match_all('/(\d+)/', $block, $mm)) {
                    $add($mm[1], 'handin');
                }
            }
        }

        // Rewards
        if (preg_match_all('/summonitem\s*\(\s*(\d+)/i', $body, $m)) {
            $add($m[1], 'reward');
        }
        if (preg_match_all('/\badditem\s*\(\s*(\d+)/i', $body, $m)) {
            $add($m[1], 'reward');
        }

        // The tree's own convention: a `-- items: 1,2,3` / `# items: 1,2,3` header.
        if (preg_match_all('/^\s*(?:--|#)\s*items?\s*:\s*([\d,\s]+)$/mi', $body, $m)) {
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
