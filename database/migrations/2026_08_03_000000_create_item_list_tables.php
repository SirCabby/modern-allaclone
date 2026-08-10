<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named item lists: a hand-written set of items that the search can be pinned
 * to, so that picking one narrows every other filter to just those items.
 *
 * The lists themselves are text files under resources/item-lists, written as
 * item *names* because that is what a person has. `php artisan items:index-lists`
 * resolves those names against peq and materialises the answer here -- in the
 * app's own sqlite database, alongside the era and quest indexes, since it is
 * derived data this app owns and peq stays read-only.
 *
 * Which also means it can never be joined against `items`: the filter pulls the
 * ids across and inlines them, exactly as the era checklist does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_lists', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();   // the file's basename; what ?list= carries
            $table->string('name');                 // what the picker shows
            $table->unsignedInteger('item_count')->default(0);
            $table->timestamp('indexed_at')->nullable();
        });

        Schema::create('item_list_items', function (Blueprint $table) {
            $table->foreignId('item_list_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('item_id');     // peq.items.id

            // The one query the search filter runs: every id in one list.
            $table->primary(['item_list_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_list_items');
        Schema::dropIfExists('item_lists');
    }
};
