<?php

namespace App\ViewModels;

use App\Models\FactionList;
use App\Models\Item;
use App\Models\NpcType;
use App\Models\QuestScript;
use App\Models\Spell;
use App\Models\Task;
use App\Models\Zone;
use App\Support\Quest\QuestNarrator;
use Illuminate\Support\Facades\Cache;

/**
 * The walkthrough for one quest script: what QuestNarrator read out of the
 * source, with every id it met resolved to something the page can link to.
 *
 * The narrator deliberately stops at ids -- it knows nothing about peq -- so
 * this is where item 1900 becomes a Prayer Cloth of Tunare. Resolution happens
 * once per entity type rather than once per mention, because a chatty script
 * names the same handful of items twenty times over.
 */
class QuestWalkthroughViewModel
{
    /** @var array<string, mixed> */
    private array $narrative;

    /** @var array<string, array<int, object>> */
    private array $entities = [];

    public function __construct(private readonly QuestScript $quest, private readonly ?string $body)
    {
        $this->narrative = $body === null
            ? ['scenes' => [], 'refs' => [], 'actions' => 0, 'untranslated' => 0]
            : $this->read($body);

        $this->entities = $this->resolve($this->narrative['refs'] ?? []);
    }

    /**
     * Reading the script is a pure function of its bytes, and the index already
     * stores their sha1, so the cache key can be the content itself: a script
     * that changes on disk gets a new key rather than a stale walkthrough.
     *
     * The version in front of it is the other half of that: teaching the
     * narrator a call it did not know changes the reading of scripts whose bytes
     * are identical, and without a bump here those keep their old walkthrough.
     *
     * @return array<string, mixed>
     */
    private function read(string $body): array
    {
        $key = 'quest.walkthrough.v2.' . ($this->quest->sha1 ?: sha1($body));

        return Cache::rememberForever(
            $key,
            fn () => QuestNarrator::narrate($body, $this->quest->language ?? 'pl')
        );
    }

    /** The script itself, for pages that show both it and the walkthrough. */
    public function body(): ?string
    {
        return $this->body;
    }

    /** @return array<int, array<string, mixed>> */
    public function scenes(): array
    {
        return $this->narrative['scenes'] ?? [];
    }

    public function hasScenes(): bool
    {
        return $this->scenes() !== [];
    }

    /** How much of the script the walkthrough could put into words, as a percentage. */
    public function coverage(): ?int
    {
        $actions = $this->narrative['actions'] ?? 0;

        if ($actions === 0) {
            return null;
        }

        return (int) round(100 * ($actions - ($this->narrative['untranslated'] ?? 0)) / $actions);
    }

    /**
     * The row behind one `['t' => 'item', 'id' => 1900]` segment, or null when
     * peq has no such row -- a script can name an id that no longer exists.
     */
    public function entity(string $type, int $id): ?object
    {
        return $this->entities[$type][$id] ?? null;
    }

    /**
     * @param  array<string, array<int, int>>  $refs
     * @return array<string, array<int, object>>
     */
    private function resolve(array $refs): array
    {
        $out = [];

        if ($ids = $refs['item'] ?? []) {
            $out['item'] = Item::whereIn('id', $ids)->get(['id', 'Name', 'icon'])->keyBy('id')->all();
        }

        if ($ids = $refs['npc'] ?? []) {
            $out['npc'] = NpcType::whereIn('id', $ids)->get(['id', 'name', 'level'])->keyBy('id')->all();
        }

        if ($ids = $refs['task'] ?? []) {
            $out['task'] = Task::whereIn('id', $ids)->get(['id', 'title'])->keyBy('id')->all();
        }

        if ($ids = $refs['faction'] ?? []) {
            $out['faction'] = FactionList::whereIn('id', $ids)->get(['id', 'name'])->keyBy('id')->all();
        }

        if ($ids = $refs['spell'] ?? []) {
            $out['spell'] = Spell::whereIn('id', $ids)->get(['id', 'name', 'new_icon'])->keyBy('id')->all();
        }

        // Scripts name a zone by its numeric id, not the row's primary key, and
        // versioned zones repeat that number -- the lowest version is the one
        // the rest of the site links to.
        if ($ids = $refs['zone'] ?? []) {
            $out['zone'] = Zone::whereIn('zoneidnumber', $ids)
                ->orderByDesc('version')
                ->get(['id', 'zoneidnumber', 'short_name', 'long_name'])
                ->keyBy('zoneidnumber')
                ->all();
        }

        return $out;
    }
}
