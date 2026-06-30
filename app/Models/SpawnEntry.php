<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SpawnEntry extends Model
{
    use HasFactory;

    protected $connection = 'eqemu';
    protected $table = 'spawnentry';

    public function npc(): BelongsTo
    {
        return $this->belongsTo(NpcType::class, 'npcID', 'id')
            ->select(['id', 'name', 'level']);
    }

    public function spawn2(): BelongsTo
    {
        return $this->belongsTo(SpawnTwo::class, 'spawngroupID', 'spawngroupID');
    }

    public function spawn2s(): HasMany
    {
        return $this->hasMany(SpawnTwo::class, 'spawngroupID', 'spawngroupID');
    }
}
