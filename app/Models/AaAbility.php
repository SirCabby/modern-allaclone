<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Kyslik\ColumnSortable\Sortable;

class AaAbility extends Model
{
    use Sortable;

    public array $sortable = [
        'id',
        'name',
        // both are the raw column: the class bitmask groups the abilities that
        // are offered to the same classes, and the category id is what its name
        // is looked up by
        'classes',
        'category',
    ];

    protected $connection = 'eqemu';
    protected $table = 'aa_ability';
    public $timestamps = false;

    protected $casts = [
        'enabled' => 'boolean',
        'grant_only' => 'boolean',
        'reset_on_death' => 'boolean',
        'auto_grant_enabled' => 'boolean',
    ];

    public function scopeClassBit($query, $classBit)
    {
        return $query->whereRaw('classes & ? != 0', [$classBit]);
    }

    public function rankChain(): Collection
    {
        $ranks = collect();

        $rank = AaRank::with([
            'effects',
            'prereqs.ability',
            'spell_:id,name,new_icon',
        ])
            ->find($this->first_rank_id);

        while ($rank) {
            $ranks->push($rank);

            $rank = $rank->next_id > 0
                ? AaRank::with([
                    'effects',
                    'prereqs.ability',
                    'spell_:id,name,new_icon',
                ])->find($rank->next_id)
                : null;
        }

        return $ranks;
    }

    public function firstRank(): BelongsTo
    {
        return $this->belongsTo(AaRank::class, 'first_rank_id');
    }

    public function ranks(): HasMany
    {
        return $this->hasMany(AaRank::class, 'id', 'first_rank_id')
            ->orWhereIn('id', function ($q) {
                $q->select('id')
                    ->from('aa_ranks')
                    ->where('prev_id', '>=', 0);
            });
    }
}
