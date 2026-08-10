<?php

namespace App\Console\Commands;

use App\Models\ItemExpansion;
use App\Models\ItemList;
use App\Models\ItemListItem;
use App\Support\ItemCategories;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Turn the text files in resources/item-lists into the searchable list index.
 *
 * A list is written as item *names*, because that is the form a person has one
 * in -- copied off a wiki, a guild's target list, a spreadsheet. peq's key is
 * an id, and the two do not line up cleanly:
 *
 *   - Names are not unique. peq carries later re-issues of classic gear under
 *     the same name (the level 85 "Cloak of Flames", four "Mithril Vambraces"),
 *     and occasionally two genuinely different items share one -- the Golden
 *     Locket dropped in the Qeynos aqueduct is not the Golden Locket the North
 *     Qeynos quest hands you.
 *   - Punctuation drifts. EQ writes possessives with a backtick about as often
 *     as an apostrophe, and peq is inconsistent within a single set.
 *   - A line often names a set rather than an item: seven pieces, all sharing a
 *     prefix, that nobody writes out.
 *
 * So the file's syntax is small and the resolution rules carry the weight:
 *
 *   @title <name>   what the picker calls this list; the slug is the filename
 *   # ...           a comment, and only at the start of a line -- item names of
 *                   their own carry '#' ("Room Key # 6")
 *   <name>          one item. Punctuation and case are ignored on both sides.
 *   <name> Set      every worn piece whose name starts with <name>. An item
 *                   really called "<name> Set" wins over the expansion; a
 *                   hundred of them exist ("Minotaur Horn Set").
 *   <name> = <id>   pin an exact peq.items.id, for a name two items share
 *   - <name>        drop a piece the Set line above it pulled in by mistake
 *
 * Where a name matches more than one item, the era index breaks the tie: peq's
 * re-issues are not obtainable anywhere, so `items:index-eras` never reached
 * them, and the one row it did reach is the one the list means. That is why
 * this runs after it. When that still leaves more than one -- two real items,
 * one name -- the entry is reported rather than guessed at, and wants a pin.
 *
 * Nothing here is era-gated: a list says which items it is about, which does
 * not change with what the server is currently running.
 */
class IndexItemLists extends Command
{
    protected $signature = 'items:index-lists {--path= : Override the list directory}';

    protected $description = 'Resolve the item list files into the searchable list index';

    /**
     * What a set is made of. Armour and shields -- the prefix a set is named for
     * is also worn by the quest pieces, augment ornaments and tradeskill
     * components that share it, and none of those are what "the set" means.
     */
    private const SET_ITEM_TYPES = [8, ItemCategories::TYPE_ARMOR];

    /** normalised name => [item id, ...] */
    private array $byName = [];

    /** item id => normalised name, for the prefix scan a Set line needs */
    private array $names = [];

    /** itemtype, keyed by item id */
    private array $types = [];

    /** item ids the era index knows, as a lookup */
    private array $indexed = [];

    private int $problems = 0;

    public function handle(): int
    {
        $dir = rtrim($this->option('path') ?: config('everquest.item_lists_path'), '/');

        if (!is_dir($dir)) {
            $this->error("Item list directory not readable: {$dir}");
            return self::FAILURE;
        }

        $files = glob("{$dir}/*.txt") ?: [];
        sort($files);

        $this->info(sprintf('Found %d list file(s) under %s', count($files), $dir));

        $this->line('Loading item names from peq...');
        $this->loadItems();

        if (!$this->indexed) {
            $this->warn('The era index is empty -- run `items:index-eras` first, or names '
                . 'that two items share cannot be told apart.');
        }

        $lists = [];

        foreach ($files as $file) {
            $slug = pathinfo($file, PATHINFO_FILENAME);

            // The filename is the slug, and the slug is what ?list= carries and
            // what the schema stores in 64 characters. Rejecting the file is
            // kinder than quietly serving a list under a mangled name.
            if (!preg_match('/^[a-z0-9_-]{1,64}$/', $slug)) {
                $this->problem(basename($file) . ': filename must be 1-64 characters of '
                    . 'a-z, 0-9, "-" or "_" -- it is the list\'s slug');
                continue;
            }

            $parsed = $this->parse($file);
            $itemIds = $this->resolve($parsed['entries'], $slug);

            $this->info(sprintf(
                '  %s ("%s"): %d items from %d entries',
                $slug,
                $parsed['title'] ?: $slug,
                count($itemIds),
                count($parsed['entries'])
            ));

            $lists[] = [
                'slug' => $slug,
                'name' => $parsed['title'] ?: $slug,
                'itemIds' => $itemIds,
            ];
        }

        $this->persist($lists);

        if ($this->problems) {
            $this->warn(sprintf(
                '%d entr%s could not be resolved and %s left out of the index.',
                $this->problems,
                $this->problems === 1 ? 'y' : 'ies',
                $this->problems === 1 ? 'was' : 'were'
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Every item in peq, keyed by a name with the punctuation taken out.
     *
     * Backtick against apostrophe and hyphen against space are the two ways a
     * hand-written list drifts from peq, and neither ever distinguishes two
     * items -- so both sides are flattened and the comparison stops caring.
     */
    private function loadItems(): void
    {
        DB::connection('eqemu')->table('items')
            ->select('id', 'Name', 'itemtype')
            ->orderBy('id')
            ->chunk(20000, function ($rows) {
                foreach ($rows as $row) {
                    $key = self::normalise($row->Name);
                    $this->byName[$key][] = (int) $row->id;
                    $this->names[(int) $row->id] = $key;
                    $this->types[(int) $row->id] = (int) $row->itemtype;
                }
            });

        $this->indexed = array_flip(ItemExpansion::query()->pluck('item_id')->all());
    }

    public static function normalise(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($name));
    }

    /**
     * Read one list file into a title and a flat list of entries.
     *
     * @return array{title: ?string, entries: array<int, array{line: int, kind: string, name: string, pin: ?int}>}
     */
    private function parse(string $file): array
    {
        $title = null;
        $entries = [];

        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $index => $raw) {
            $line = trim($raw);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, '@title ')) {
                $title = trim(substr($line, 7));
                continue;
            }

            if (str_starts_with($line, '- ')) {
                $entries[] = ['line' => $index + 1, 'kind' => 'exclude', 'name' => trim(substr($line, 2)), 'pin' => null];
                continue;
            }

            $pin = null;
            if (preg_match('/^(.*?)\s+=\s+(\d+)$/', $line, $m)) {
                $line = trim($m[1]);
                $pin = (int) $m[2];
            }

            $kind = ($pin === null && str_ends_with($line, ' Set')) ? 'set' : 'name';

            $entries[] = ['line' => $index + 1, 'kind' => $kind, 'name' => $line, 'pin' => $pin];
        }

        return ['title' => $title, 'entries' => $entries];
    }

    /**
     * Walk one file's entries and collect the ids they name.
     *
     * Order matters only for `- name`, which drops what the Set line above it
     * added; everything else accumulates into the same set, so an item named
     * twice (the list groups by zone, and plenty of things drop in two) lands
     * once.
     *
     * @param array<int, array{line: int, kind: string, name: string, pin: ?int}> $entries
     * @return int[]
     */
    private function resolve(array $entries, string $slug): array
    {
        $ids = [];

        foreach ($entries as $entry) {
            $where = "{$slug}.txt:{$entry['line']}";

            if ($entry['kind'] === 'exclude') {
                foreach ($this->matches($entry['name']) as $id) {
                    unset($ids[$id]);
                }
                continue;
            }

            if ($entry['pin'] !== null) {
                if (!isset($this->names[$entry['pin']])) {
                    $this->problem("{$where}: no item {$entry['pin']} in peq for \"{$entry['name']}\"");
                    continue;
                }

                $ids[$entry['pin']] = true;
                continue;
            }

            foreach ($this->resolveName($entry, $where) as $id) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * The ids one un-pinned entry names.
     *
     * @param array{line: int, kind: string, name: string, pin: ?int} $entry
     * @return int[]
     */
    private function resolveName(array $entry, string $where): array
    {
        $name = $entry['name'];

        // "<name> Set" is the expansion syntax, but a hundred items are really
        // called that, and an item that exists beats a rule about names.
        if ($entry['kind'] === 'set' && !$this->matches($name)) {
            $base = trim(substr($name, 0, -4));
            $pieces = $this->setPieces($base);

            if (!$pieces) {
                $this->problem("{$where}: \"{$base} Set\" matched no worn pieces");
            }

            return $pieces;
        }

        $candidates = $this->matches($name);

        // A trailing "(BST)" or "(INT casters)" is an annotation on the line,
        // not part of the name -- but 386 items really do end in a bracket
        // ("Words of Collection (Azia)"), so it is only dropped when keeping it
        // found nothing.
        if (!$candidates && preg_match('/^(.*?)\s*\([^)]*\)$/', $name, $m)) {
            $candidates = $this->matches(trim($m[1]));
        }

        if (!$candidates) {
            $this->problem("{$where}: no item named \"{$name}\"");
            return [];
        }

        if (count($candidates) === 1) {
            return $candidates;
        }

        // peq's re-issues are obtainable nowhere, so the era index never reached
        // them; if exactly one candidate is in it, that is the real item.
        $known = array_values(array_filter($candidates, fn ($id) => isset($this->indexed[$id])));

        if (count($known) === 1) {
            return $known;
        }

        $this->problem(sprintf(
            '%s: "%s" matches %d items (%s) -- pin one with `%s = <id>`',
            $where,
            $name,
            count($candidates),
            implode(', ', $known ?: $candidates),
            $name
        ));

        return [];
    }

    /**
     * The worn pieces of a set: everything whose name starts with the set's,
     * that the era index has placed somewhere.
     *
     * Both halves are load-bearing. Without the name prefix there is nothing to
     * go on at all -- peq records no set membership. Without the era index the
     * expansion also swallows every later re-issue of the same seven pieces,
     * which is most of what shares the prefix.
     *
     * @return int[]
     */
    private function setPieces(string $base): array
    {
        $prefix = self::normalise($base);
        $pieces = [];

        foreach ($this->names as $id => $name) {
            if (str_starts_with($name, $prefix)
                && isset($this->indexed[$id])
                && in_array($this->types[$id], self::SET_ITEM_TYPES, true)
            ) {
                $pieces[] = $id;
            }
        }

        return $pieces;
    }

    /** @return int[] */
    private function matches(string $name): array
    {
        return $this->byName[self::normalise($name)] ?? [];
    }

    private function problem(string $message): void
    {
        $this->problems++;
        $this->warn("  {$message}");
    }

    /**
     * Replace the index with what the files say.
     *
     * A list whose file is gone is dropped: the directory is the whole
     * definition, so an index row nothing on disk asks for is a stale list the
     * picker would still be offering.
     *
     * @param array<int, array{slug: string, name: string, itemIds: int[]}> $lists
     */
    private function persist(array $lists): void
    {
        $now = now();

        DB::transaction(function () use ($lists, $now) {
            ItemList::query()
                ->whereNotIn('slug', array_column($lists, 'slug'))
                ->get()
                ->each(function (ItemList $stale) {
                    $stale->entries()->delete();
                    $stale->delete();
                });

            foreach ($lists as $list) {
                $model = ItemList::updateOrCreate(
                    ['slug' => $list['slug']],
                    [
                        'name' => $list['name'],
                        'item_count' => count($list['itemIds']),
                        'indexed_at' => $now,
                    ]
                );

                $model->entries()->delete();

                foreach (array_chunk($list['itemIds'], 500) as $chunk) {
                    ItemListItem::insert(array_map(
                        fn ($id) => ['item_list_id' => $model->id, 'item_id' => $id],
                        $chunk
                    ));
                }
            }
        });
    }
}
