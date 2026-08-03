<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `peq.items` has no expansion columns at all -- an item's era is a property of
 * where you can get it, not of the row itself, so it has to be derived from the
 * zones its loot, merchants, forage, fishing, ground spawns, quest scripts and
 * recipes live in. That derivation is a ten-second pile of joins, which is fine
 * for `php artisan items:index-eras` and hopeless for a search filter.
 *
 * So it is materialised here, in the app's own sqlite database, alongside the
 * quest index. Nothing in peq is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_expansions', function (Blueprint $table) {
            $table->unsignedInteger('item_id')->primary();  // peq.items.id
            $table->unsignedTinyInteger('expansion');       // earliest era the item can be obtained in
            $table->string('source', 16);                   // which lookup won: loot | merchant | quest | ...
            $table->timestamp('indexed_at')->nullable();

            // The one query the search filter runs: every id in a set of eras.
            $table->index(['expansion', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_expansions');
    }
};
