<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kyslik\ColumnSortable\Sortable;

class TradeskillRecipe extends Model
{
    use HasFactory;
    use Sortable;

    public array $sortable = [
        'name',
        // the id, which groups the recipes by skill the way the column reads
        'tradeskill',
        'enabled',
        'trivial',
    ];

    protected $connection = 'eqemu';
    protected $table = 'tradeskill_recipe';

    public function entries(): HasMany
    {
        return $this->hasMany(TradeskillRecipeEntry::class, 'recipe_id');
    }

    public function containerEntries()
    {
        return $this->entries()->with('item')->where('iscontainer', 1)->get();
    }

    public function successEntries()
    {
        return $this->entries()->with('item')->where('successcount', '>', 0)->get();
    }

    public function failEntries()
    {
        return $this->entries()->with('item')->where('failcount', '>', 0)->get();
    }

    public function componentEntriesWithFlags()
    {
        $components = $this->entries()->with('item')->where('iscontainer', 0)->where('componentcount', '>', 0)->get();

        foreach ($components as $component) {
            $itemId = $component->item?->id;
            $component->custom_is_merchant = \App\Models\Merchantlist::where('item', $itemId)->exists();
            $component->custom_is_drop = \App\Models\LootdropEntry::where('item_id', $itemId)->exists();
            $component->custom_is_foraged = \App\Models\Forage::where('itemid', $itemId)->exists();
            $component->custom_is_fished = \App\Models\Fishing::where('itemid', $itemId)->exists();
        }

        return $components;
    }
}
