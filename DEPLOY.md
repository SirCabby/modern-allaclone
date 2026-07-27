# Local deployment (akk-stack-live)

A containerised [modern-allaclone](https://github.com/chadw/modern-allaclone) pointed at the
live `peq` database, plus local changes for era targeting, a theme toggle, and quest scripts.

## Running it

```bash
docker compose up -d          # http://192.168.1.7:8081
docker compose logs -f        # boot + php-fpm/nginx output
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

## Quest scripts

EQEmu keeps quests as Perl/Lua on disk, so a stock allaclone shows no quest information at
all. `quests/` is bind-mounted read-only and indexed into sqlite:

```bash
docker exec modern-allaclone php artisan quests:index
```

That resolves each script to its NPC (by id, by `(zone, name)` through the spawn tables, then
by global name — with a fallback for names whose backticks/apostrophes become `-` on disk) and
extracts item IDs from hand-ins, `summonitem`, and the tree's own `-- items:` headers. Every
extracted ID is validated against `peq` before it is stored, which is what keeps timers,
coordinates, and gold amounts from being indexed as items.

Result: a **Quests** tab on NPC pages (linked items + the script body) and an
**appears in quest scripts** section on item pages.

Re-run it after changing quests. `QUESTS_INDEX_ON_BOOT=true` runs it at container start.

## Notes

- `EQEMU_DISCOVERY` defaults to **false**. Upstream defaults it on, which — with an empty
  `peq.discovered_items` table — masks every item on the site as "Undiscovered Item".
- `opcache.validate_timestamps=0`, so code changes need `docker compose build && up -d`.
