{{-- One phrase of the walkthrough, assembled as a single string.

     Segments carry their own spacing -- "Gives you ", ", ", " and " -- so they
     have to run together with nothing whatsoever between them, and Blade emits
     its own newlines around every directive. Concatenating here and echoing once
     is the same approach the task activity partials take for their item lists. --}}
@php
    $link = fn (string $href, string $label, string $style) =>
        '<a href="' . $href . '" class="' . $style . ' link-hover">' . e($label) . '</a>';

    // A script can name an id peq no longer has -- say so rather than drop it.
    $missing = fn (string $label, $id) =>
        '<span class="text-base-content/50" title="Nothing with this id is in the database">'
        . $label . ' ' . e($id) . '</span>';

    $html = '';

    foreach ($segments as $segment) {
        $id = $segment['id'] ?? null;
        $entity = $id === null ? null : $walkthrough->entity($segment['t'], $id);

        $html .= match ($segment['t']) {
            'text' => e($segment['v']),
            'em' => '<span class="font-semibold text-base-content">' . e($segment['v']) . '</span>',
            'quote' => '<span class="italic">&ldquo;' . e($segment['v']) . '&rdquo;</span>',
            'flag' => '<code class="rounded bg-base-300 px-1 text-xs">' . e($segment['v']) . '</code>',
            'code' => '<code class="rounded bg-base-300 px-1 text-xs text-base-content/60">' . e($segment['v']) . '</code>',
            'item' => $entity
                ? trim(view('components.item-link', [
                    'itemId' => $entity->id,
                    'itemName' => $entity->Name,
                    'itemIcon' => $entity->icon,
                    'itemClass' => 'inline-block align-middle',
                ])->render())
                : $missing('item', $id),
            'spell' => $entity
                ? trim(view('components.spell-link', [
                    'spellId' => $entity->id,
                    'spellName' => $entity->name,
                    'spellIcon' => $entity->new_icon,
                    'spellClass' => 'inline-block align-middle',
                    'effectsOnly' => false,
                ])->render())
                : $missing('spell', $id),
            'npc' => $entity ? $link(route('npcs.show', $entity->id), $entity->clean_name, 'link-info') : $missing('NPC', $id),
            'task' => $entity ? $link(route('tasks.show', $entity->id), $entity->title, 'link-info') : $missing('task', $id),
            'faction' => $entity ? $link(route('factions.show', $entity->id), $entity->name, 'link-secondary') : $missing('faction', $id),
            'zone' => $entity ? $link(route('zones.show', $entity->id), $entity->long_name, 'link-accent') : $missing('zone', $id),
            default => '',
        };
    }
@endphp
{!! $html !!}
