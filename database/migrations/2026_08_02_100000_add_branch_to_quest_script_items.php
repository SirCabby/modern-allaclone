<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pair each reward with the turn-in that gates it.
 *
 * A quest script is one file per NPC, not one per quest -- the Skyshrine
 * armoursmith runs seven quests out of a single EVENT_ITEM -- so "what does
 * this reward cost" has no answer at script level. `branch` groups the rows
 * that belong to one turn-in check: a reward carries the branch of the check
 * above it and the handins carry the branch they are required by.
 *
 * Branch 0 means the reference is under no check at all, which is also what
 * every existing row gets: until `quests:index` is re-run nothing is paired,
 * and nothing that reads this is any worse off than before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quest_script_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('branch')->default(0)->after('kind');

            // An item can gate two branches of the same script (the Ocean of
            // Tears robe takes any one of two dozen breastplates), so the
            // branch has to be part of what makes a row unique.
            $table->dropUnique(['quest_script_id', 'item_id', 'kind']);
            $table->unique(['quest_script_id', 'item_id', 'kind', 'branch']);
        });
    }

    public function down(): void
    {
        Schema::table('quest_script_items', function (Blueprint $table) {
            $table->dropUnique(['quest_script_id', 'item_id', 'kind', 'branch']);
            $table->dropColumn('branch');
            $table->unique(['quest_script_id', 'item_id', 'kind']);
        });
    }
};
