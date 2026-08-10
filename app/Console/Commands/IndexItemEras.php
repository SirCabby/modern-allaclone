<?php

namespace App\Console\Commands;

use App\Models\ItemExpansion;
use App\Support\ContentFilter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Work out which era every item belongs to and materialise it into sqlite.
 *
 * `peq.items` carries no expansion columns, so there is nothing to read: an
 * item's era is a property of where you can obtain it. This walks every route
 * into a player's inventory and keeps the *earliest* answer -- the era the item
 * first becomes gettable:
 *
 *   loot      lootdrop_entries -> loottable_entries -> npc_types -> spawn2 -> zone
 *   merchant  merchantlist -> npc_types.merchant_id -> spawn2 -> zone
 *   forage    forage.zoneid -> zone
 *   fishing   fishing.zoneid -> zone
 *   ground    ground_spawns.zoneid -> zone
 *   quest     the *last* of the script's zone and the turn-ins that branch
 *             asks for -- collecting a reward in Qeynos for a Velious drop
 *             does not make it Classic (sqlite quest index)
 *   recipe    the era of the recipe's *last* component -- you need all of them,
 *             so a Kunark bar in a Classic recipe makes the product Kunark
 *
 * The zone a thing sits in is only ever the *starting* answer. Expansions drop
 * their own content into old zones all the time -- the LDoN adventure camps are
 * in Commonlands and Everfrost, DoN's crystal merchants stand in Lavastorm --
 * so every source pushes its era forward past anything that marks the placement
 * itself as later content (see placementQueries() and applyIntrinsicFloors()).
 *
 * The winning zone is kept alongside the era, since working it out is most of
 * what this command does anyway -- that is the "dropped by an NPC in Befallen"
 * the results table shows. Crafting and the LDoN flag date an item without
 * placing it, so they store no zone.
 *
 * Deliberately NOT era-gated: this describes what an item *is*, not what the
 * server is currently running, so it does not change when the era switcher does.
 * Content flags are a different question and do apply -- a spawn behind a flag
 * nobody has switched on is not content this server holds in any era, and
 * reading one as a source is how the Apprentice Robe ended up Classic off a
 * `nektulos_classic_relocated` spawn that never appears in the world. Quests
 * are held to it through the NPC running the script (see blockedQuestScripts),
 * since a Perl file on disk carries no flag of its own.
 *
 * Quests and recipes run to a fixpoint together: what a quest costs is often
 * crafted, and what a recipe needs is often quested.
 */
class IndexItemEras extends Command
{
    protected $signature = 'items:index-eras';

    protected $description = 'Derive each item\'s earliest era from where it can be obtained';

    /**
     * `zone.expansion` values that are not a real era. PEQ parks its loading
     * screens, art tests and designer zones at 99.
     */
    private const NOT_AN_ERA = 99;

    /** Recipes feed quests feed recipes; a runaway guard, not a real limit. */
    private const MAX_PASSES = 10;

    /**
     * Merchants that trade in an expansion's own currency are that expansion's
     * content wherever they happen to be standing, so their stock cannot be
     * dated by the zone around them. `npc_types.class` names them; the numbers
     * are the same ones config('everquest.npc_classes') labels.
     */
    private const MERCHANT_CLASS_ERAS = [
        59 => 7,    // Discord Merchant          -> Gates of Discord
        61 => 6,    // LDoN Adventure Merchant   -> Lost Dungeons of Norrath
        67 => 9,    // Norrath's Keepers Merchant-> Dragons of Norrath
        68 => 9,    // Dark Reign Merchant       -> Dragons of Norrath
    ];

    /**
     * Races that were not in the box, and the expansion that added them. The
     * keys are the bits `items.races` uses, the same ones races_short labels.
     */
    private const RACE_ERAS = [
        4096 => 'Ruins of Kunark',      // Iksar
        8192 => 'Shadows of Luclin',    // Vah Shir
        16384 => 'Legacy of Ykesha',    // Froglok
        32768 => "The Serpent's Spine", // Drakkin
    ];

    /** The same for `items.classes`. */
    private const CLASS_ERAS = [
        16384 => 'Shadows of Luclin',   // Beastlord
        32768 => 'Gates of Discord',    // Berserker
    ];

    /**
     * `items.itemtype` values that name a system the game did not have yet, and
     * the expansion that introduced it. Keys are the numbers item_types labels.
     *
     * Only types whose whole point arrived with an expansion belong here. A
     * Classic sword is still a 1H Slashing, so most of this table would say
     * nothing; an augment is not a thing you could have owned before there was
     * anywhere to socket it.
     *
     * The distinction that matters is whether the *type* postdates the item or
     * the other way round, because the client relabels old items freely. Armor
     * Dye reads like an obvious candidate and is not one: the tailoring dyes
     * carry it and have been mixed in Classic since launch. Illusion is worse
     * -- it was hung retroactively on the Mask of Deception out of Lower Guk
     * and the Amulet of Necropotence out of Fear. A type that can be applied
     * backwards is not a floor. Each entry here is a system that did not exist,
     * not a label that did not exist.
     */
    private const ITEM_TYPE_ERAS = [
        54 => 'Lost Dungeons of Norrath',   // Augment
        55 => 'Lost Dungeons of Norrath',   // Augment Solvent
        56 => 'Lost Dungeons of Norrath',   // Augment Distiller
        58 => 'Dragons of Norrath',         // Guild Banner Kit
        59 => 'Dragons of Norrath',         // Guild Banner Modify Token
        64 => 'Lost Dungeons of Norrath',   // Perfected Augment Distiller
        68 => 'Shadows of Luclin',          // Mount
    ];

    /** The `races` / `classes` value meaning "anyone", rather than a list of one. */
    private const USABLE_BY_ALL = 65535;

    /** Highest era config knows about; anything past it is a bad value, not an era. */
    private int $maxEra;

    /** item_id => earliest expansion seen so far. */
    private array $era = [];

    /** item_id => the source that produced the era above. */
    private array $source = [];

    /**
     * item_id => the zone that source pointed at, where there is one. Crafting
     * and the LDoN flag date an item without placing it anywhere, so this stays
     * null for them.
     */
    private array $zone = [];

    /** quest_script_ids whose NPC never spawns; see blockedQuestScripts(). */
    private array $blockedScripts = [];

    public function handle(): int
    {
        $this->maxEra = max(array_keys(config('everquest.expansions')));

        $zoneEras = $this->zoneEras();

        if (!$zoneEras) {
            $this->error('No usable zones in peq -- cannot derive item eras.');

            return self::FAILURE;
        }

        $this->info(sprintf('Deriving item eras from %d zones', count($zoneEras)));

        $this->indexWorldPlacement();

        $this->blockedScripts = $this->blockedQuestScripts();

        $rewards = $this->loadQuestRewards($zoneEras);
        $recipes = $this->loadRecipes();

        // Turn-ins are also a last resort for dating the items themselves (see
        // indexQuestHandins), and both fixpoints feed on whatever is dated
        // already, so it all interleaves: components and turn-in costs first,
        // so an unplaced one cannot stall what it gates, then the fixpoints,
        // then whatever they could not reach, then one more round for the
        // recipes and quests those unblock. Nothing here overwrites an era, so
        // the extra rounds can only add coverage.
        $handins = $this->indexQuestHandins($zoneEras, $this->derivedItems($rewards, $recipes));
        [$quests, $crafted] = $this->indexDerived($rewards, $recipes);
        $handins += $this->indexQuestHandins($zoneEras, []);
        [$more, $extra] = $this->indexDerived($rewards, $recipes);

        $this->line(sprintf('  %-9s %6d rewards (from %d turn-ins)', 'quest', $quests + $more, count($rewards)));
        $this->line(sprintf('  %-9s %6d references', 'handin', $handins));
        $this->line(sprintf('  %-9s %6d products (from %d recipes)', 'recipe', $crafted + $extra, count($recipes)));

        $this->applyIntrinsicFloors();

        $stored = $this->persist();

        Cache::forget(ItemExpansion::ERAS_CACHE_KEY);

        foreach (ItemExpansion::ORDERABLE as $column) {
            Cache::forget(ItemExpansion::ORDER_CACHE_KEY . ".{$column}");
        }

        $this->newLine();
        $this->info(sprintf('Indexed %d items', count($stored)));
        $this->table(
            ['Era', 'Items'],
            collect($stored)->countBy('expansion')->sortKeys()
                ->map(fn ($count, $era) => [config('everquest.expansions')[$era] ?? "Expansion {$era}", $count])
                ->values()
                ->all()
        );

        return self::SUCCESS;
    }

    /**
     * short_name => ['era' => earliest expansion, 'versions' => [version => expansion]],
     * skipping dev zones and anything the site is configured to ignore.
     *
     * Zones are versioned and the versions can be eras apart: `paw` is the
     * Classic Lair of the Splitpaw at version 0 and the Dragons of Norrath
     * revamp at version 1. `era` is the earliest of them -- the right answer
     * for anything that does not say which version it means -- and `versions`
     * is there for the things that do.
     */
    private function zoneEras(): array
    {
        $rows = ContentFilter::applyFlags(DB::connection('eqemu')->table('zone'))
            ->where('expansion', '<', self::NOT_AN_ERA)
            ->whereNotIn('short_name', config('everquest.ignore_zones') ?? [])
            ->groupBy('short_name', 'version')
            ->select('short_name', 'version', DB::raw('MIN(expansion) as expansion'))
            ->get();

        $eras = [];

        foreach ($rows as $row) {
            $era = (int) $row->expansion;

            $eras[$row->short_name]['versions'][(int) $row->version] = $era;
            $eras[$row->short_name]['era'] = min($eras[$row->short_name]['era'] ?? $era, $era);
        }

        return $eras;
    }

    /**
     * The era floor a quest script takes from where it sits on disk, or null if
     * that folder is not a zone this index recognises.
     *
     * Scripts live under quests/<zone>/, and the four zones PEQ keeps two
     * layouts of put theirs in v0 and v1 folders alongside -- quests/lavastorm/v1
     * is the Dragons of Norrath Lavastorm, not the Classic one. Reading the
     * folder name alone is what dated the Glowing Magma Ring, handed out by a
     * miner who only exists in the revamp, as Classic. A script sitting directly
     * in the zone folder belongs to no particular version, so it keeps the
     * zone's earliest era.
     */
    private function questZoneEra(array $zoneEras, string $zone, ?string $relativePath): ?int
    {
        if (!isset($zoneEras[$zone])) {
            return null;
        }

        $parts = explode('/', (string) $relativePath);
        $version = isset($parts[1]) && preg_match('/^v(\d+)$/', $parts[1], $matches)
            ? (int) $matches[1]
            : null;

        return $version !== null
            ? ($zoneEras[$zone]['versions'][$version] ?? $zoneEras[$zone]['era'])
            : $zoneEras[$zone]['era'];
    }

    /**
     * Everything that puts an item in a zone. Each of these is one grouped
     * query rather than a row-by-row walk -- the joins are wide, but MIN() over
     * the per-placement era collapses them server-side.
     */
    private function indexWorldPlacement(): void
    {
        foreach ($this->placementQueries() as $source => $query) {
            $rows = $query()->get();

            foreach ($rows as $row) {
                $this->record((int) $row->item_id, (int) $row->expansion, $source, $row->zone);
            }

            $this->line(sprintf('  %-9s %6d items', $source, $rows->count()));
        }
    }

    /** @return array<string, callable> */
    private function placementQueries(): array
    {
        $ignore = config('everquest.ignore_zones') ?? [];

        // Content flags decide whether a row exists at all rather than when, so
        // every table a source walks through is checked. On a left join the
        // column reads null where there is no row, which passes -- the same
        // answer as a row carrying no flag.
        $flagged = fn ($query, string ...$tables) => array_reduce(
            $tables,
            fn ($carry, $table) => ContentFilter::applyFlags($carry, $table),
            $query
        );

        // Applied to every query below: dev zones and ignored zones are not eras.
        $zoneJoin = fn ($query) => $flagged($query, 'z')
            ->where('z.expansion', '<', self::NOT_AN_ERA)
            ->whereNotIn('z.short_name', $ignore);

        // PEQ's content tables all carry a min_expansion, but it is -1 on
        // everything that is not gated and holds a handful of 70s and 99s that
        // are not eras at all. Only a real era is a floor; anything else is 0,
        // which leaves the zone's own answer standing. A NULL from a left join
        // falls through the WHEN and lands on 0 too.
        $gate = fn (string $column) => sprintf(
            'CASE WHEN %s BETWEEN 0 AND %d THEN %s ELSE 0 END',
            $column, $this->maxEra, $column
        );

        // A zone's era depends on which version of it you are standing in.
        // `paw` is both the Classic Lair of the Splitpaw (version 0) and the
        // Dragons of Norrath revamp (version 1), and a dozen more zones were
        // reworked the same way; matching on short_name alone joins a spawn to
        // every version at once and MIN() then hands the revamp the original's
        // era. spawn2 says which version it belongs to, so where the zone has a
        // row for that version, that row is the answer.
        $zoneVersions = ContentFilter::applyFlags(DB::connection('eqemu')->table('zone'))
            ->where('expansion', '<', self::NOT_AN_ERA)
            ->whereNotIn('short_name', $ignore)
            ->groupBy('short_name', 'version')
            ->select('short_name', 'version', DB::raw('MIN(expansion) as expansion'));

        // A spawnentry with chance 0 never spawns, so it places nothing, and a
        // spawn behind a flag nobody has switched on never happens at all --
        // PEQ parks a revamped zone's old spawns that way rather than deleting
        // them, and they sit in the pre-revamp zone with its pre-revamp era.
        //
        // The versioned zone row is a left join, not an inner one: a fifth of
        // spawn2 has no row to match. The LDoN and Hardcore Heritage dungeons
        // run their randomised layouts as versions 1-10 off a single version 0
        // zone, and spawn2 uses -1 for "every version". None of those are a
        // different era from the zone itself, so they fall back to it.
        $spawned = fn ($query) => $flagged($query
            ->join('spawnentry as se', 'se.npcID', '=', 'n.id')
            ->join('spawn2 as s2', 's2.spawngroupID', '=', 'se.spawngroupID')
            ->join('zone as z', 'z.short_name', '=', 's2.zone')
            ->leftJoinSub($zoneVersions, 'zv', fn ($join) => $join
                ->on('zv.short_name', '=', 's2.zone')
                ->on('zv.version', '=', 's2.version'))
            ->where('se.chance', '>', 0), 'se', 's2');

        // Where a spawn puts you, in era terms: the version of the zone it is
        // in, raised by the gates on the spawn itself.
        $spawnEra = sprintf(
            'GREATEST(COALESCE(zv.expansion, z.expansion), %s, %s)',
            $gate('se.min_expansion'), $gate('s2.min_expansion')
        );

        $merchantClass = 'CASE n.class ' . implode(' ', array_map(
            fn ($class, $era) => "WHEN {$class} THEN {$era}",
            array_keys(self::MERCHANT_CLASS_ERAS),
            self::MERCHANT_CLASS_ERAS
        )) . ' ELSE 0 END';

        // The era one placement is reachable in: where it sits, pushed forward
        // by every floor that marks the placement as later content. MIN() then
        // keeps the earliest placement of the item -- and the GROUP_CONCAT
        // alongside it names the zone that won, by ordering the group the same
        // way and taking the head. group_concat_max_len truncates the tail of a
        // long list, which is exactly the part being thrown away.
        $pick = function (string $era, string ...$floors) {
            $reachable = 'GREATEST(' . implode(', ', [$era, ...$floors]) . ')';

            return [
                DB::raw("MIN({$reachable}) as expansion"),
                DB::raw("SUBSTRING_INDEX(GROUP_CONCAT(z.short_name ORDER BY {$reachable} ASC), ',', 1) as zone"),
            ];
        };

        return [
            'loot' => fn () => $zoneJoin($spawned($flagged(
                $this->peq('lootdrop_entries as lde')
                    ->join('loottable_entries as lte', 'lte.lootdrop_id', '=', 'lde.lootdrop_id')
                    ->join('npc_types as n', 'n.loottable_id', '=', 'lte.loottable_id')
                    ->leftJoin('loottable as lt', 'lt.id', '=', 'lte.loottable_id')
                    ->leftJoin('lootdrop as ld', 'ld.id', '=', 'lde.lootdrop_id'),
                'lde', 'lt', 'ld'
            )))->groupBy('lde.item_id')
                ->select('lde.item_id as item_id', ...$pick(
                    $spawnEra,
                    $gate('lde.min_expansion'), $gate('lt.min_expansion'), $gate('ld.min_expansion')
                )),

            // merchant_id 0 means "not a merchant", so it must not join.
            'merchant' => fn () => $zoneJoin($spawned($flagged(
                $this->peq('merchantlist as ml')
                    ->join('npc_types as n', 'n.merchant_id', '=', 'ml.merchantid')
                    ->where('ml.merchantid', '>', 0),
                'ml'
            )))->groupBy('ml.item')
                ->select('ml.item as item_id', ...$pick(
                    $spawnEra, $gate('ml.min_expansion'), $merchantClass
                )),

            // zoneid 0 is global forage; it joins to no zone and so has no era.
            'forage' => fn () => $zoneJoin($flagged(
                $this->peq('forage as f')->join('zone as z', 'z.zoneidnumber', '=', 'f.zoneid'), 'f'
            ))->groupBy('f.itemid')
                ->select('f.itemid as item_id', ...$pick('z.expansion', $gate('f.min_expansion'))),

            'fishing' => fn () => $zoneJoin($flagged(
                $this->peq('fishing as f')->join('zone as z', 'z.zoneidnumber', '=', 'f.zoneid'), 'f'
            ))->groupBy('f.itemid')
                ->select('f.itemid as item_id', ...$pick('z.expansion', $gate('f.min_expansion'))),

            'ground' => fn () => $zoneJoin($flagged(
                $this->peq('ground_spawns as g')->join('zone as z', 'z.zoneidnumber', '=', 'g.zoneid'), 'g'
            ))->groupBy('g.item')
                ->select('g.item as item_id', ...$pick('z.expansion', $gate('g.min_expansion'))),
        ];
    }

    /**
     * Quest scripts that never run, because the NPC holding them never spawns.
     *
     * Every spawn-based source goes through ContentFilter already -- a spawn
     * behind a flag nobody has switched on is not content this server holds in
     * any era. The quest sources did not, because they come off the on-disk
     * script index rather than the spawn tables, and nothing on disk knows
     * about a content flag. That is how the Nights of the Dead pie toss in
     * Toxxulia Forest dated a Tasty Squash Pie as Classic on a server where
     * `peq_halloween` has been off the entire time, and the same event's mount
     * race in Nektulos did the same for a bridle.
     *
     * The test is by *name*, not by the id quests:index resolved to, and not
     * per zone either. Both of those were tried and both are wrong:
     *
     *   by id      peq parks duplicate rows of an NPC beside the live one
     *              (`takp_import_parked_era_dupe` and friends). Ask whether the
     *              resolved id can spawn and 37 scripts come back blocked whose
     *              live twin is standing in the zone running that very file.
     *
     *   per zone   a seasonal script is copied into every zone folder the event
     *              visits, and only one of them holds the spawn. Marta Stalwart
     *              exists once, in `tox` behind the flag, but her script is also
     *              in `toxxulia` -- the Serpent's Spine revamp of the same
     *              forest, where nobody of that name is placed at all. Scoping
     *              the question to the folder let that copy through, and the
     *              squash pie came back as Serpent's Spine instead of Classic:
     *              a different wrong answer for an item you still cannot get.
     *
     * So: placed somewhere, reachable nowhere. Left alone is any script whose
     * NPC never resolved or was never named, and any name spawn2 does not place
     * at all -- fifteen hundred quest givers are put in the world by another
     * script, and blocking those would cost real coverage to catch nothing.
     *
     * @return int[] quest_script_ids
     */
    private function blockedQuestScripts(): array
    {
        $spawns = fn () => DB::connection('eqemu')->table('spawnentry as se')
            ->join('spawn2 as s2', 's2.spawngroupID', '=', 'se.spawngroupID')
            ->join('npc_types as n', 'n.id', '=', 'se.npcID')
            ->distinct()
            ->select('n.name');

        // Names spawn2 places anywhere at all, and the subset of those that can
        // really turn up. Absence from the first set is not evidence of
        // anything: fifteen hundred quest givers are put in the world by
        // another script rather than by spawn2, and have no row here to pass or
        // fail. Absence from the second, having been in the first, is.
        $index = fn ($rows) => array_fill_keys(
            $rows->map(fn ($row) => strtolower($row->name))->all(),
            true
        );

        $placed = $index($spawns()->get());
        $live = $index(
            ContentFilter::applyFlags(ContentFilter::applyFlags($spawns(), 'se'), 's2')
                ->where('se.chance', '>', 0)
                ->get()
        );

        $blocked = [];

        DB::table('quest_scripts')
            ->whereNotNull('npc_id')
            ->whereNotNull('npc_name')
            ->select('id', 'npc_name')
            ->orderBy('id')
            ->chunk(2000, function ($scripts) use ($placed, $live, &$blocked) {
                foreach ($scripts as $script) {
                    $name = strtolower($script->npc_name);

                    if (isset($placed[$name]) && !isset($live[$name])) {
                        $blocked[] = (int) $script->id;
                    }
                }
            });

        $this->line(sprintf(
            '  %-9s %6d scripts skipped (their NPC is placed, but never reachable)',
            'blocked', count($blocked)
        ));

        return $blocked;
    }

    /**
     * Every reward a quest script hands out, with what it costs, from the
     * on-disk index the quests:index command builds.
     *
     * The script's folder is its zone, which is the only era signal a Perl file
     * carries by itself -- but the zone you collect a reward in is a floor, not
     * the answer. Turning in a Velious drop to an NPC in Qeynos does not make
     * the reward Classic. So each reward is loaded with the items its own
     * branch requires (see the `branch` column) and dated at the latest of them
     * and the zone.
     *
     * Scripts under quests/global belong to no zone and are skipped.
     *
     * @return array<int, array{item_id: int, zone: string, zone_era: int, gates: int[]}>
     */
    private function loadQuestRewards(array $zoneEras): array
    {
        $costs = [];    // "script:branch" => item ids the branch asks for

        DB::table('quest_script_items')
            ->where('kind', 'handin')
            ->where('branch', '>', 0)
            ->select('quest_script_id', 'branch', 'item_id')
            ->orderBy('id')
            ->chunk(5000, function ($rows) use (&$costs) {
                foreach ($rows as $row) {
                    $costs["{$row->quest_script_id}:{$row->branch}"][] = (int) $row->item_id;
                }
            });

        $rewards = [];

        DB::table('quest_script_items as qsi')
            ->join('quest_scripts as qs', 'qs.id', '=', 'qsi.quest_script_id')
            ->where('qsi.kind', 'reward')
            ->when($this->blockedScripts, fn ($query) => $query
                ->whereIntegerNotInRaw('qsi.quest_script_id', $this->blockedScripts))
            ->select('qsi.quest_script_id', 'qsi.branch', 'qsi.item_id', 'qs.zone', 'qs.relative_path')
            ->orderBy('qsi.id')
            ->chunk(5000, function ($rows) use ($zoneEras, $costs, &$rewards) {
                foreach ($rows as $row) {
                    $zoneEra = $this->questZoneEra($zoneEras, $row->zone, $row->relative_path);

                    if ($zoneEra === null) {
                        continue;
                    }

                    $rewards[] = [
                        'item_id' => (int) $row->item_id,
                        'zone' => $row->zone,
                        'zone_era' => $zoneEra,
                        'gates' => $costs["{$row->quest_script_id}:{$row->branch}"] ?? [],
                    ];
                }
            });

        return $rewards;
    }

    /**
     * Turn-ins, as a last resort only.
     *
     * Handing an item over is not a way to *get* it, so a turn-in cannot date an
     * item the way a reward can -- and taking it as one is actively wrong, since
     * later expansions bolt new turn-ins onto NPCs in old zones. Akkirus'
     * Chestplate of the Risen is rewarded in Skyshrine and handed back in at
     * Ocean of Tears, and reading that turn-in as a source called a Velious item
     * Classic.
     *
     * It is still the only signal there is for an item nothing else places, so
     * it fills in -- and only fills in -- items still undated when it runs.
     *
     * @param  array<int, true> $crafted products to leave to the recipe pass
     * @return int references consumed
     */
    private function indexQuestHandins(array $zoneEras, array $crafted): int
    {
        return $this->indexQuestItems($zoneEras, 'handin', 'handin', $crafted);
    }

    /**
     * @param ?array<int, true> $skip when set, only items that are neither
     *                                already dated nor listed here are recorded
     * @return int references consumed
     */
    private function indexQuestItems(array $zoneEras, string $kind, string $source, ?array $skip = null): int
    {
        $matched = 0;

        // "Already dated" means dated before this pass began, not by this pass.
        // Testing the live map instead lets the first row a walk happens to
        // reach claim the item and shut every other one out, which is not "last
        // resort" -- it is "whichever qsi.id came first". PEQ keeps a script per
        // zone layout and the same NPC stands in both: Timtok Tonsmith takes
        // Crafted plate in the Classic `commons` *and* in the Serpent's Spine
        // `commonlands`, and reaching the second one first dated the whole
        // Crafted set as TSS. Against the snapshot every row still gets to
        // record(), which keeps the earliest of them.
        $dated = $skip === null ? [] : $this->era;

        DB::table('quest_script_items as qsi')
            ->join('quest_scripts as qs', 'qs.id', '=', 'qsi.quest_script_id')
            ->where('qsi.kind', $kind)
            ->when($this->blockedScripts, fn ($query) => $query
                ->whereIntegerNotInRaw('qsi.quest_script_id', $this->blockedScripts))
            ->select('qsi.id', 'qsi.item_id', 'qs.zone', 'qs.relative_path')
            ->orderBy('qsi.id')
            ->chunk(2000, function ($rows) use ($zoneEras, $source, $skip, $dated, &$matched) {
                foreach ($rows as $row) {
                    $itemId = (int) $row->item_id;

                    $zoneEra = $this->questZoneEra($zoneEras, $row->zone, $row->relative_path);

                    if ($zoneEra === null) {
                        continue;
                    }

                    if ($skip !== null && (isset($dated[$itemId]) || isset($skip[$itemId]))) {
                        continue;
                    }

                    $this->record($itemId, $zoneEra, $source, $row->zone);
                    $matched++;
                }
            });

        return $matched;
    }

    /** @return array<int, array> recipe_id => products, components, min_expansion */
    private function loadRecipes(): array
    {
        $recipes = [];

        ContentFilter::applyFlags(
            $this->peq('tradeskill_recipe_entries as tre')
                ->join('tradeskill_recipe as tr', 'tr.id', '=', 'tre.recipe_id')
                ->where('tr.enabled', 1),
            'tr'
        )
            ->select(
                'tre.id', 'tre.recipe_id', 'tre.item_id',
                'tre.successcount', 'tre.componentcount', 'tr.min_expansion'
            )
            ->orderBy('tre.id')
            ->chunk(5000, function ($rows) use (&$recipes) {
                foreach ($rows as $row) {
                    $id = (int) $row->recipe_id;
                    $recipes[$id]['min_expansion'] ??= (int) $row->min_expansion;

                    if ($row->successcount > 0) {
                        $recipes[$id]['products'][] = (int) $row->item_id;
                    }

                    if ($row->componentcount > 0) {
                        $recipes[$id]['components'][] = (int) $row->item_id;
                    }
                }
            });

        return $recipes;
    }

    /** @return array<int, true> every item a recipe or a quest produces */
    private function derivedItems(array $rewards, array $recipes): array
    {
        $items = [];

        foreach ($recipes as $recipe) {
            foreach ($recipe['products'] ?? [] as $product) {
                $items[$product] = true;
            }
        }

        foreach ($rewards as $reward) {
            $items[$reward['item_id']] = true;
        }

        return $items;
    }

    /**
     * Quest rewards and crafted items, resolved together to a fixpoint.
     *
     * Both are the same shape of problem: you cannot have the thing until you
     * have everything it takes, so its era is the *latest* of them -- max(),
     * not min(). And they feed each other. A recipe component is often a quest
     * reward, a turn-in is often crafted, and both chains run several deep, so
     * neither settles until the other stops moving. Each pass can only ever
     * lower an era, so this converges.
     *
     * Alternatives sort themselves out for free: two dozen branches rewarding
     * the same robe each record their own cost, and record() keeps the cheapest.
     *
     * @return array{0: int, 1: int} [rewards dated, products dated]
     */
    private function indexDerived(array $rewards, array $recipes): array
    {
        $quests = 0;
        $crafted = 0;

        for ($pass = 1; $pass <= self::MAX_PASSES; $pass++) {
            $changed = 0;

            foreach ($rewards as $reward) {
                $era = $this->prerequisiteEra($reward['gates'], $reward['zone_era']);

                if ($era !== null && $this->record($reward['item_id'], $era, 'quest', $reward['zone'])) {
                    $changed++;
                    $quests++;
                }
            }

            foreach ($recipes as $recipe) {
                $era = $this->recipeEra($recipe);

                if ($era === null) {
                    continue;
                }

                foreach ($recipe['products'] ?? [] as $product) {
                    if ($this->record($product, $era, 'recipe')) {
                        $changed++;
                        $crafted++;
                    }
                }
            }

            if ($changed === 0) {
                break;
            }
        }

        // A reward whose cost never resolved still has a floor the recipes do
        // not: you collect it somewhere. Date it from its zone and whichever
        // turn-ins are known, so one unplaceable component cannot drop a whole
        // quest chain out of the index.
        //
        // Withholding this where nothing resolved reads as the more honest
        // answer and is not: dropping the guess does not leave the item unknown,
        // it leaves whatever *later* script also hands the thing out standing
        // unopposed, and an epic 1.0 then dates from the 2.0 quest that upgrades
        // it. A floor that is too early loses to every real source; no floor at
        // all loses to nothing.
        foreach ($rewards as $reward) {
            $fallback = $this->knownEra($reward['gates'], $reward['zone_era']);

            if ($this->record($reward['item_id'], $fallback, 'quest', $reward['zone'])) {
                $quests++;
            }
        }

        return [$quests, $crafted];
    }

    /** The era every prerequisite is met in, or null while one is unknown. */
    private function prerequisiteEra(array $items, int $floor): ?int
    {
        foreach ($items as $item) {
            if (!isset($this->era[$item])) {
                return null;
            }

            $floor = max($floor, $this->era[$item]);
        }

        return $floor;
    }

    /** As above, but settling for the prerequisites that did resolve. */
    private function knownEra(array $items, int $floor): int
    {
        foreach ($items as $item) {
            $floor = max($floor, $this->era[$item] ?? 0);
        }

        return $floor;
    }

    /** The era a recipe becomes craftable in, or null while it is unknowable. */
    private function recipeEra(array $recipe): ?int
    {
        // An explicit min_expansion is the server's own answer; take it.
        $declared = $recipe['min_expansion'] ?? -1;

        if ($declared >= 0) {
            return $declared;
        }

        $components = $recipe['components'] ?? [];

        // An unplaced component may still resolve on a later pass, so unlike a
        // quest reward a recipe waits rather than guessing: there is no zone to
        // fall back on, and half a component list is not a floor.
        return $components ? $this->prerequisiteEra($components, 0) : null;
    }

    /** A few items date themselves, and when they do they outrank every zone. */
    private function applyIntrinsicFloors(): void
    {
        $this->applyLdonFloor();
        $this->applyEpicFloor();
        $this->applyRestrictionFloor();
        $this->applyItemTypeFloor();
        $this->applyLevelFloor();
    }

    /**
     * `items.ldonsold` is set on the 800-odd things the LDoN adventure merchants
     * stock. Those merchants stand in Commonlands, Everfrost and North Ro, and
     * the camps' own NPCs drop a handful of the same items, so *every* route to
     * an Aggressor's Greaves runs through a Classic zone and every one of them
     * is wrong. The flag comes from the client's item data and is not a guess,
     * so it wins outright -- it raises the era rather than competing for the
     * earliest one, and indexes items that nothing places at all.
     */
    private function applyLdonFloor(): void
    {
        $ldon = array_search('Lost Dungeons of Norrath', config('everquest.expansions'), true);

        if ($ldon === false) {
            return;
        }

        $raised = 0;

        $this->peq('items')->where('ldonsold', 1)->orderBy('id')->select('id')
            ->chunk(2000, function ($rows) use ($ldon, &$raised) {
                foreach ($rows as $row) {
                    $itemId = (int) $row->id;

                    if (($this->era[$itemId] ?? -1) >= $ldon) {
                        continue;
                    }

                    $this->era[$itemId] = $ldon;
                    $this->source[$itemId] = 'ldon';
                    // The flag dates the item without placing it: the camps
                    // themselves sit in Classic zones, so whatever zone lost
                    // here would be a misleading caption for an LDoN era.
                    $this->zone[$itemId] = null;
                    $raised++;
                }
            });

        $this->line(sprintf('  %-9s %6d items raised to LDoN', 'ldon', $raised));
    }

    /**
     * `items.epicitem` flags the epic weapons, and no epic predates Ruins of
     * Kunark -- they arrived with it.
     *
     * Half of an epic 1.0 chain is staged in old-world zones, and PEQ carries
     * only the steps someone has scripted, so a chain routinely loses whatever
     * dated it: nothing in the database consumes the Enchanted Clay the ranger
     * gets for a Jade Reaver out of City of Mist, which leaves every surviving
     * step of Earthcaller's chain sitting in Kaladim, Erudin and North Karana
     * and the epic reading as Classic.
     *
     * The flag does not say which tier, so unlike ldonsold it is only a floor,
     * and it says nothing at all about the 1.5s and 2.0s beyond "not before
     * Kunark" -- applyLevelFloor() is what carries those the rest of the way,
     * off the level they ask for. Nothing undated is indexed either: "an epic"
     * is not enough to call an unplaced 2.0 shard Kunark. The winning source
     * keeps its zone and label, since it is still where the thing is handed
     * over; only the date it implied was wrong.
     *
     * The flag is on the weapon and on nothing else, so the twenty-odd pieces a
     * chain is assembled from carry none of this by themselves -- and those are
     * exactly the pieces staged in old-world zones. Innoruuk's Curse is put
     * together in City of Mist, but its Head of the Valiant is handed over in
     * Paineel for a head looted in The Hole, and every zone in that sentence is
     * Classic. So the floor is walked back down the chain as well, over the
     * steps the chain is the only route to -- see epicChainItems().
     */
    private function applyEpicFloor(): void
    {
        $kunark = array_search('Ruins of Kunark', config('everquest.expansions'), true);

        if ($kunark === false) {
            return;
        }

        $epics = [];
        $raised = 0;

        $raise = function (int $itemId) use ($kunark, &$raised) {
            if (!isset($this->era[$itemId]) || $this->era[$itemId] >= $kunark) {
                return;
            }

            $this->era[$itemId] = $kunark;
            $raised++;
        };

        $this->peq('items')->where('epicitem', 1)->orderBy('id')->select('id')
            ->chunk(2000, function ($rows) use ($raise, &$epics) {
                foreach ($rows as $row) {
                    $epics[(int) $row->id] = true;
                    $raise((int) $row->id);
                }
            });

        foreach (array_keys($this->epicChainItems($epics)) as $itemId) {
            // A step something outside the chain also hands you is datable on
            // its own terms -- a Classic gem an epic asks for really is Classic,
            // and an epic asking for it does not move it. Only the steps whose
            // sole route is the chain itself inherit the floor.
            if (!in_array($this->source[$itemId] ?? null, ['quest', 'handin'], true)) {
                continue;
            }

            $raise($itemId);
        }

        $this->line(sprintf('  %-9s %6d items raised to Kunark', 'epic', $raised));
    }

    /**
     * Every scripted step behind an epic, walked back from the weapon itself.
     *
     * A branch's rewards are what you get for handing over what it asks for, so
     * the pairs the quest index already records *are* the chain: the things a
     * branch rewarding an epic asks for are the step before it, the things
     * rewarding those are the step before that, and so on to the start.
     *
     * Branch 0 is what a script gives away for talking to it, which nothing is
     * a prerequisite for, so a walk that reaches it simply stops.
     *
     * @param  array<int, true> $epics the flagged weapons to walk back from
     * @return array<int, true> the steps behind them, weapons excluded
     */
    private function epicChainItems(array $epics): array
    {
        $rewardedBy = [];   // item id => the "script:branch" keys handing it out
        $asks = [];         // "script:branch" => the item ids it wants

        DB::table('quest_script_items')
            ->where('branch', '>', 0)
            ->whereIn('kind', ['reward', 'handin'])
            ->select('quest_script_id', 'branch', 'item_id', 'kind')
            ->orderBy('id')
            ->chunk(5000, function ($rows) use (&$rewardedBy, &$asks) {
                foreach ($rows as $row) {
                    $branch = "{$row->quest_script_id}:{$row->branch}";

                    if ($row->kind === 'reward') {
                        $rewardedBy[(int) $row->item_id][] = $branch;
                    } else {
                        $asks[$branch][] = (int) $row->item_id;
                    }
                }
            });

        $seen = $epics;
        $frontier = array_keys($epics);
        $chain = [];

        while ($frontier) {
            $next = [];

            foreach ($frontier as $itemId) {
                foreach ($rewardedBy[$itemId] ?? [] as $branch) {
                    foreach ($asks[$branch] ?? [] as $required) {
                        if (isset($seen[$required])) {
                            continue;
                        }

                        $seen[$required] = true;
                        $chain[$required] = true;
                        $next[] = $required;
                    }
                }
            }

            $frontier = $next;
        }

        return $chain;
    }

    /**
     * An item only one race or class can use cannot predate the expansion that
     * added them: the Heavenfall Girding is Froglok-only, and Frogloks are
     * Legacy of Ykesha, whatever the Rathe Mountains wizard who hands it over
     * makes of it.
     *
     * The restriction has to rule out everyone who was there at launch before it
     * says anything -- an item a Froglok and a Human can both wear is as old as
     * the Human -- so the floor is the earliest expansion among the races the
     * item allows, and one Classic race in the list drops it to nothing.
     *
     * Nothing undated is indexed: knowing an item is for Frogloks is not knowing
     * where to get one.
     */
    private function applyRestrictionFloor(): void
    {
        $races = $this->eraMap(self::RACE_ERAS);
        $classes = $this->eraMap(self::CLASS_ERAS);

        if (!$races && !$classes) {
            return;
        }

        $raised = 0;

        $this->peq('items')
            ->where(fn ($query) => $query
                ->whereBetween('races', [1, self::USABLE_BY_ALL - 1])
                ->orWhereBetween('classes', [1, self::USABLE_BY_ALL - 1]))
            ->orderBy('id')
            ->select('id', 'races', 'classes')
            ->chunk(2000, function ($rows) use ($races, $classes, &$raised) {
                foreach ($rows as $row) {
                    $itemId = (int) $row->id;

                    if (!isset($this->era[$itemId])) {
                        continue;
                    }

                    $floor = max(
                        $this->maskEra((int) $row->races, $races),
                        $this->maskEra((int) $row->classes, $classes)
                    );

                    if ($floor <= $this->era[$itemId]) {
                        continue;
                    }

                    $this->era[$itemId] = $floor;
                    $raised++;
                }
            });

        $this->line(sprintf('  %-9s %6d items raised to a race or class expansion', 'usable', $raised));
    }

    /**
     * The earliest expansion anyone a bitmask allows was playable in.
     *
     * A bit this does not know about is someone who was in the box, and so is
     * era 0 -- which is the answer for almost every item and the reason this
     * has to walk the whole mask rather than just the late bits.
     *
     * @param array<int, int> $eras bit => expansion that added it
     */
    private function maskEra(int $mask, array $eras): int
    {
        if ($mask <= 0 || $mask === self::USABLE_BY_ALL) {
            return 0;
        }

        $floor = null;

        for ($bit = 1; $bit <= self::USABLE_BY_ALL; $bit <<= 1) {
            if ($mask & $bit) {
                $floor = min($floor ?? PHP_INT_MAX, $eras[$bit] ?? 0);
            }
        }

        return $floor ?? 0;
    }

    /**
     * Turn a key => expansion-name table into key => expansion number, dropping
     * anything this install's config does not name.
     *
     * @param  array<int, string> $eras
     * @return array<int, int>
     */
    private function eraMap(array $eras): array
    {
        $mapped = [];

        foreach ($eras as $bit => $name) {
            $era = array_search($name, config('everquest.expansions'), true);

            if ($era !== false) {
                $mapped[$bit] = (int) $era;
            }
        }

        return $mapped;
    }

    /**
     * An item cannot predate the kind of thing it is: nobody was riding
     * anywhere before Shadows of Luclin and there was nowhere to socket an
     * augment before Lost Dungeons of Norrath, whatever zone either turns up
     * in.
     *
     * Both are the LDoN-camp problem in a form the ldonsold flag never sees --
     * an expansion seeding its own content back through the old world, where
     * every route in is therefore an old-world route.
     *
     * Augments are the wholesale version: LDoN hung them off Kunark, Velious
     * and Planes of Power drops in bulk, so a Petrified Goblin Eye is looted in
     * Frontier Mountains and a Frozen Spider Eye in Velketor's Labyrinth and
     * neither has anything later anywhere on it. Guild banners are the same
     * story one merchant over -- the whole 298-kit catalogue is sold in the
     * Plane of Knowledge, which dates every one of them to Planes of Power.
     *
     * Mounts are the seasonal-event version, and tighter: Nights of the Dead
     * bolted a mount race onto Nektulos and a Knights of Truth chain onto East
     * Freeport, and a script in an old zone rewarding items the same script
     * hands out is a closed loop -- the horse traded in for the Bridle of Sir
     * Ariam is itself only ever quested in Befallen.
     *
     * Neither carries a level requirement, a race restriction or a
     * min_expansion. What the item *is* is the last signal standing.
     *
     * Nothing undated is indexed: knowing an item is a mount is not knowing
     * where to get one. The winning source keeps its zone and label too, since
     * the bridle really is handed over in Freeport and the eye really does drop
     * in Frontier Mountains; only the date they implied was wrong.
     */
    private function applyItemTypeFloor(): void
    {
        $types = $this->eraMap(self::ITEM_TYPE_ERAS);

        if (!$types) {
            return;
        }

        $raised = 0;

        $this->peq('items')
            ->whereIn('itemtype', array_keys($types))
            ->orderBy('id')
            ->select('id', 'itemtype')
            ->chunk(2000, function ($rows) use ($types, &$raised) {
                foreach ($rows as $row) {
                    $itemId = (int) $row->id;
                    $floor = $types[(int) $row->itemtype];

                    if (!isset($this->era[$itemId]) || $floor <= $this->era[$itemId]) {
                        continue;
                    }

                    $this->era[$itemId] = $floor;
                    $raised++;
                }
            });

        $this->line(sprintf('  %-9s %6d items raised to their item type\'s expansion', 'itemtype', $raised));
    }

    /**
     * Nothing was made for a level nobody could reach yet, so the era that
     * raised the cap to an item's level is a floor under it.
     *
     * This is the one signal that survives a quest chain PEQ only half scripts,
     * which is most of what dates old-world content wrongly: the Transcendent
     * Books of Culture are handed over in Halas, Grobb and Rivervale and read as
     * Classic, and they want level 100. It reaches the ranger epic 1.5 chain
     * too, though only part way -- an Essence of Earth and Wind wants level 60,
     * which is Kunark, and the LDoN it actually belongs to is nowhere in the
     * data.
     *
     * Keyed by cap, so the first entry at or above the item's level is the era
     * that opened it. `reclevel` counts alongside `reqlevel`: an item whose
     * stats are tuned for 85 was not written for a game that stopped at 50.
     */
    private const LEVEL_CAP_ERAS = [
        50  => 0,   // Classic
        60  => 1,   // Ruins of Kunark
        65  => 4,   // Planes of Power
        70  => 8,   // Omens of War
        75  => 12,  // The Serpent's Spine
        80  => 14,  // Secrets of Faydwer
        85  => 15,  // Seeds of Destruction
        90  => 17,  // House of Thule
        95  => 18,  // Veil of Alaris
        100 => 19,  // Rain of Fear
        105 => 21,  // The Darkened Sea
        110 => 24,  // Ring of Scale
        115 => 26,  // Torment of Velious
        120 => 28,  // Terror of Luclin
        125 => 30,  // Laurion's Song
    ];

    private function applyLevelFloor(): void
    {
        $caps = self::LEVEL_CAP_ERAS;
        $classic = array_key_first($caps);
        // Past every cap the game has ever had is not a level, it is a marker:
        // peq uses 255 for items no character is meant to equip.
        $highest = array_key_last($caps);
        $raised = 0;

        $this->peq('items')
            ->whereRaw('GREATEST(reqlevel, reclevel) > ?', [$classic])
            ->whereRaw('GREATEST(reqlevel, reclevel) <= ?', [$highest])
            ->orderBy('id')
            ->select('id', DB::raw('GREATEST(reqlevel, reclevel) as level'))
            ->chunk(2000, function ($rows) use ($caps, &$raised) {
                foreach ($rows as $row) {
                    $itemId = (int) $row->id;

                    if (!isset($this->era[$itemId])) {
                        continue;
                    }

                    foreach ($caps as $cap => $era) {
                        if ($cap < (int) $row->level) {
                            continue;
                        }

                        if ($era > $this->era[$itemId] && $era <= $this->maxEra) {
                            $this->era[$itemId] = $era;
                            $raised++;
                        }

                        break;
                    }
                }
            });

        $this->line(sprintf('  %-9s %6d items raised to their level cap', 'level', $raised));
    }

    /** Keep the earliest era for an item. Returns true if this lowered it. */
    private function record(int $itemId, int $expansion, string $source, ?string $zone = null): bool
    {
        if ($itemId <= 0 || $expansion < 0 || $expansion > $this->maxEra) {
            return false;
        }

        if (isset($this->era[$itemId]) && $this->era[$itemId] <= $expansion) {
            return false;
        }

        $this->era[$itemId] = $expansion;
        $this->source[$itemId] = $source;
        // Always assigned, never merged: era, source and zone are one answer,
        // and leaving a beaten source's zone behind would caption the new era
        // with the old place.
        $this->zone[$itemId] = $zone;

        return true;
    }

    /**
     * Replace the index. Ids are validated against peq first -- loot tables and
     * recipes both outlive the items they reference, and an era for an item
     * that no longer exists is just a row nothing can ever join to.
     */
    private function persist(): array
    {
        $known = array_flip($this->peq('items')->pluck('id')->all());
        $now = now();
        $rows = [];

        foreach ($this->era as $itemId => $expansion) {
            if (!isset($known[$itemId])) {
                continue;
            }

            $rows[] = [
                'item_id' => $itemId,
                'expansion' => $expansion,
                'source' => $this->source[$itemId],
                'zone' => $this->zone[$itemId] ?? null,
                'indexed_at' => $now,
            ];
        }

        DB::transaction(function () use ($rows) {
            ItemExpansion::query()->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                ItemExpansion::insert($chunk);
            }
        });

        return $rows;
    }

    private function peq(string $table)
    {
        return DB::connection('eqemu')->table($table);
    }
}
