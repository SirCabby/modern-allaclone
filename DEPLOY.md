# Local deployment (akk-stack-live)

A containerised [modern-allaclone](https://github.com/chadw/modern-allaclone) pointed at the
live `peq` database, plus local changes for era targeting, a theme toggle, and quest scripts.

## Running it

```bash
docker compose up -d          # http://192.168.1.7:8081
docker compose logs -f        # boot + php-fpm/nginx output
```

The `Makefile` wraps this and everything below — `make` with no target lists the lot:

```bash
make            # list targets
make up         # start
make logs       # follow logs
make rebuild    # rebuild the image and recreate (needed for any code change)
make refresh    # ship everything: rebuild, migrate, reindex, drop caches
```

The container joins the external `akk-stack-live_backend` network, so the database is reached
at `mariadb:3306` rather than over the LAN.

## Database access

The site is **read-only against `peq`** and provisions nothing there:

- All 39 upstream models are pinned to a separate `eqemu` connection; there is not a single
  write in `app/`.
- Its own state (sessions, cache, quest index) lives in **sqlite** inside the
  `allaclone-data` volume, so there is no second MySQL database to create.
- The credential is `allaclone_ro`, SELECT-only, scoped to the docker bridge.

Create that account once per server (needs root; `CREATE USER` is not something the `eqemu`
account or dbmate can do, and root is socket-only):

```bash
cd ~/workspace/GitHub/akk-stack-live
./eqemu-ops/scripts/create-allaclone-user.sh \
    "$(grep '^EQEMU_DB_PASSWORD=' ~/workspace/GitHub/modern-allaclone/.env | cut -d= -f2-)"
```

Then, in this directory:

```bash
docker compose up -d --force-recreate
```

`docker compose restart` is **not** enough — `env_file` values are injected when the container
is created, so a restart keeps serving the previous credential.

Verify what the app actually connects as (read-only, no side effects):

```bash
docker exec modern-allaclone php artisan tinker --execute='
$c = DB::connection("eqemu");
echo $c->select("SELECT CURRENT_USER() u")[0]->u."\n";
foreach ($c->select("SHOW GRANTS FOR CURRENT_USER()") as $r) { echo array_values((array)$r)[0]."\n"; }'
```

Expect `allaclone_ro@172.%` with `GRANT SELECT ON \`peq\`.*` and nothing else. Do not test this
by attempting a write — if the account is not read-only, the write succeeds and leaves an
artifact in `peq`.

## Era targeting

`EQEMU_EXPANSION=auto` (the default) reads `Expansion:CurrentExpansion` straight from
`peq.rule_values`, so the site shows the era the server is actually running — Classic today —
and follows automatically when you bump the rule. There is no expansion picker on the Zones
page; zones are one flat list.

`App\Support\ContentFilter` is a port of the emulator's own `ContentFilterCriteria::apply()`,
including `content_flags` / `content_flags_disabled`, so seasonal and opt-in content
(`Classic_OldWorldDrops`, `frostfell`, ...) is gated the same way the server gates it.

The navbar selector pins a different era **for your browser session only** — useful for
planning content. Set `EQEMU_ALLOW_ERA_SWITCH=false` to remove it.

Caches are keyed by era, so switching does not serve another era's data.

### The era checklist on item search

`peq.items` has no expansion columns — an item's era is a property of *where you can get
it*, so it has to be derived. `php artisan items:index-eras` walks every route into a
player's inventory and keeps the **earliest** answer:

| Source | Path |
| --- | --- |
| `loot` | `lootdrop_entries` → `loottable_entries` → `npc_types` → `spawn2` → `zone.expansion` |
| `merchant` | `merchantlist` → `npc_types.merchant_id` → `spawn2` → `zone.expansion` |
| `forage` / `fishing` / `ground` | the row's `zoneid` → `zone.expansion` |
| `quest` | the zone folder of the script that hands it out (needs `quests:index` first) |
| `recipe` | the era of the recipe's **last** component — you need all of them, so a Kunark bar in a Classic recipe makes the product Kunark. Recipes feed recipes, so this runs to a fixpoint. A recipe with its own `min_expansion` set uses that instead. |

Zones at `expansion = 99` (loading screens, art tests) and anything in `ignore_zones` are
not eras and are skipped, as are ids that no longer exist in `peq.items`.

The zone that produced the answer is kept alongside the era, which is what the **Zone**
column on the results table shows — where the item drops, is sold, or is handed over.
Crafted items and the LDoN-flagged ones are dated without being placed anywhere, so they
have no zone and show `-`; that is about a quarter of the index.

The result lands in `item_expansions` in the app's own sqlite database — it cannot be
joined against `items`, so the filter pulls the ids across and inlines them. The checklist
and the **Era** and **Zone** columns all hide themselves until the index exists, so the
site is perfectly usable without ever running this.

Which columns the results table shows is a per-browser preference (localStorage, via the
**Columns** button), not part of the search — so it survives sorting and paging without
riding along in the query string, and nothing about it is stored server-side.

Deliberately **not** era-gated: it describes what an item is, not what the server is
running, so it does not change when the era switcher does. Re-run it after content
changes. `ITEM_ERAS_INDEX_ON_BOOT=true` runs it at container start, after `quests:index`.

```bash
docker exec modern-allaclone php artisan items:index-eras
```

### Loading a list on item search

The **List** picker at the top of the item search pins the whole search to a named set of
items: with one loaded, every other filter narrows *within* it and nothing outside it can
come back. A banner above the results says which list is loaded, since the search panel
remembers being closed — without it a search over 693 items looks like a search over all
118,000 that is quietly missing things.

A list is a text file in `resources/item-lists/`, one per list, named by *items* rather
than ids because that is the form a list arrives in. `php artisan items:index-lists`
resolves the names against `peq` into `item_lists` / `item_list_items` in sqlite, and the
filter inlines the ids the same way the era checklist does.

| Line | Means |
| --- | --- |
| `@title <name>` | what the picker calls this list; the slug is the filename |
| `# ...` | a comment — only at the start of a line, since item names carry `#` themselves ("Room Key # 6") |
| `<name>` | one item. Case and punctuation are ignored on both sides: backtick against apostrophe and hyphen against space are how a hand-written list drifts from `peq`, and neither ever distinguishes two items. |
| `<name> Set` | every worn piece whose name starts with `<name>` — unless an item is really called "`<name>` Set", which then wins; a hundred of those exist |
| `<name> = <id>` | pin an exact `peq.items.id`, for a name two items share |
| `- <name>` | drop a piece the `Set` line above it pulled in by mistake |

Names are not unique, which is most of what this command is for. `peq` carries later
re-issues of classic gear under the same name — four "Mithril Vambraces", a level 85
"Cloak of Flames" — and occasionally two genuinely different items share one: the Golden
Locket that drops in the Qeynos aqueduct is not the Golden Locket the North Qeynos quest
hands you. **The era index breaks the tie**: a re-issue is obtainable nowhere, so
`items:index-eras` never reached it, and the row it did reach is the one the list means.
That is why this runs *after* it, and why it warns when the era index is empty. Where that
still leaves two real items, the entry is reported rather than guessed at and wants a pin.

Anything that cannot be resolved is named with its file and line number, left out of the
index, and counted at the end — the command still succeeds, so one bad line in a list does
not abort a refresh.

Not era-gated either: a list says which items it is about, which does not change with what
the server is running. The files ship in the image, so this runs on every container start
rather than behind a flag.

```bash
docker exec modern-allaclone php artisan items:index-lists
```

## Quest scripts

EQEmu keeps quests as Perl/Lua on disk, so a stock allaclone shows no quest information at
all. `quests/` is bind-mounted read-only and indexed into sqlite:

```bash
docker exec modern-allaclone php artisan quests:index
```

That resolves each script to its NPC (by id, by `(zone, name)` through the spawn tables, then
by global name — with a fallback for names whose backticks/apostrophes become `-` on disk) and
extracts item IDs from hand-ins, `summonitem`, and the tree's own `-- items:` headers. It also
extracts **task IDs** from `assigntask` / `taskselector` / `updatetaskactivity` and friends —
including selector lists built at runtime (`table.insert(task_array, 5784)`,
`push(@task_array, 500146)`, and `task_id = 5501` data tables, which is how the cultural armor
artisans and the DoN captains offer theirs) — plus a `-- tasks:` header in the same style.
Every extracted ID is validated against `peq` before it is stored, which is what keeps timers,
coordinates, and gold amounts from being indexed as items.

Result: a **Quests** tab on NPC pages (the tasks the NPC offers / advances, the tasks it is an
objective of, linked items, and the script body) and an **appears in quest scripts** section on
item pages.

Re-run it after changing quests. `QUESTS_INDEX_ON_BOOT=true` runs it at container start.

### Reverse lookups

Both directions are bridged, which matters because the two halves live in different databases —
tasks and items in `peq`, scripts in the sqlite index:

| Page | Section | Source |
| --- | --- | --- |
| Item | *is part of a task* | `tasks.reward_id_list` and `task_activities.item_id_list`, set-matched |
| Item | *appears in quest scripts* | `quest_script_items` |
| Task | *quest scripts driving this task* | `quest_script_tasks` |
| Quest script | *Tasks* | `quest_script_tasks` |
| NPC | *Tasks: Offers / Advances / References* | `quest_script_tasks`, through the NPC's own scripts only |
| NPC | *Tasks: Target of* | `task_activities.npc_match_list` / `target_name`, reverse-matched in PHP |

Quest search also matches **task titles** (resolved against `peq` first, since the two halves
cannot be joined), so searching "arachnophobia" finds the scripts that drive that task even
though the files are named for their NPCs.

Two things to know when a reward does not show up:

- A script that picks its reward out of a lookup table (class-specific armor, for instance)
  never names the id in a `summonitem()` call, so **nothing** indexes it. Add the `-- items:` /
  `-- tasks:` headers to the script — that is what they are for.
- A task whose reward is granted from script must still list the possible items in
  `tasks.reward_id_list` for them to appear here. That is safe only when the task is
  `reward_method = 2` (METHODQUEST); at any other reward_method the emulator hands out every id
  in the list to every class.

## Rebuilding site data

Six things sit between an edit and what the site shows, and not one of them expires on its own
in any useful timeframe — each just keeps serving the old answer. `make refresh` is all of them,
in the only order that works:

```bash
make refresh    # rebuild -> migrate -> quests:index -> items:index-eras -> items:index-lists -> cache:clear
```

The rebuild is first because `opcache.validate_timestamps=0` means the image is the only code
php reads; the migrate follows it for the volume reason in the note below; the era index reads
quest rewards, so it follows the quest index; the list index settles same-named items by which
one the era index reached, so it follows that; and the page cache is dropped last, so the pages
rebuilt after it read the new indexes.

Data-only stages are still available one at a time as `make index-quests` / `make index-eras` /
`make index-lists` / `make cache-clear`, for when nothing but `peq` or `quests/` moved.

From the stack this browser follows (`akk-stack-live` by default), the same three stages are
available as `make allaclone-refresh`, which additionally no-ops unless this container is
attached to the stack being operated on — so a dev migration will not claim to have refreshed a
browser that follows live. `make migrate-up` / `migrate-down` (and the experiments channel)
already call it themselves, so a dbmate migration needs nothing extra. Run it by hand after
editing quest scripts, which the database never sees.

`items:index-lists` is deliberately not in that path: the list files ship in the image, so the
container start that follows any rebuild indexes them anyway, and nothing else moves them.

`items:index-eras` is the only stage that costs real time. `ALLACLONE_SKIP_ERAS=1` drops it when
you know nothing an item's era depends on moved (spawns, merchants, loot, forage/fishing/ground,
quest rewards, recipes, `zone.expansion`) — opt-in, because it is also the stage that goes stale
silently.

> Adding a Laravel migration needs one extra step on an existing deployment: the
> `allaclone-data` volume is mounted **over** `/var/www/html/database`, so migration files baked
> into the image are invisible to a volume that already exists. `make migrate` copies them in
> first, which is the whole difference from `php artisan migrate`:
>
> ```bash
> make migrate
> ```
>
> A fresh volume is seeded from the image and does not need this.

## Notes

- `EQEMU_DISCOVERY` defaults to **false**. Upstream defaults it on, which — with an empty
  `peq.discovered_items` table — masks every item on the site as "Undiscovered Item".
- `opcache.validate_timestamps=0`, so code changes need `docker compose build && up -d`.
