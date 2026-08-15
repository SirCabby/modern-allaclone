<?php

namespace Tests\Unit;

use App\Support\Quest\QuestNarrator;
use PHPUnit\Framework\TestCase;

/**
 * The walkthrough is read out of Perl and Lua source by regular expressions, so
 * these cover the shapes that were actually got wrong on the way here: a brace
 * inside dialogue, an apostrophe inside a trigger phrase, a turn-in written as
 * four or-ed checks, and a call nobody has taught it yet.
 */
class QuestNarratorTest extends TestCase
{
    public function test_it_reads_a_lua_turn_in_as_what_you_give_and_get(): void
    {
        $text = $this->narrate(<<<'LUA'
        function event_trade(e)
            local item_lib = require("items");
            if(item_lib.check_turn_in(e.trade, {item1 = 2301})) then
                e.self:Say("Erollisi be praised!");
                e.other:SummonItem(1900);
                e.other:AddEXP(6000);
            end
        end
        LUA, 'lua');

        $this->assertStringContainsString('When you hand something in', $text);
        $this->assertStringContainsString('If you hand in ITEM#2301', $text);
        $this->assertStringContainsString('Says: "Erollisi be praised!"', $text);
        $this->assertStringContainsString('Gives you ITEM#1900', $text);
        $this->assertStringContainsString('Grants 6,000 experience', $text);
    }

    public function test_it_keeps_a_trigger_phrase_that_contains_an_apostrophe(): void
    {
        $text = $this->narrate(<<<'LUA'
        function event_say(e)
            if(e.message:findi("barkeep's compendium")) then
                e.self:Say("I found the book.");
            end
        end
        LUA, 'lua');

        $this->assertStringContainsString('If you say "barkeep\'s compendium"', $text);
    }

    public function test_it_reads_perl_hand_ins_rewards_and_faction(): void
    {
        $text = $this->narrate(<<<'PERL'
        sub EVENT_ITEM {
          if (plugin::check_handin(\%itemcount, 13118 => 1, 13383 => 2)) {
            quest::summonitem(13379);
            quest::faction(336, 5);
            quest::givecash(0, 0, 0, 2);
          }
        }
        PERL, 'pl');

        $this->assertStringContainsString('If you hand in ITEM#13118 and 2× ITEM#13383', $text);
        $this->assertStringContainsString('Gives you ITEM#13379', $text);
        $this->assertStringContainsString('Faction with FACTION#336 +5', $text);
        $this->assertStringContainsString('Gives you 2 pp', $text);
    }

    public function test_it_collapses_a_run_of_or_ed_turn_in_counts(): void
    {
        $text = $this->narrate(<<<'PERL'
        sub EVENT_ITEM {
          if (plugin::check_handin(\%itemcount, 58084 => 4) || plugin::check_handin(\%itemcount, 58084 => 3) || plugin::check_handin(\%itemcount, 58084 => 2) || plugin::check_handin(\%itemcount, 58084 => 1)) {
            quest::say("Good.");
          }
        }
        PERL, 'pl');

        $this->assertStringContainsString('If you hand in 1-4× ITEM#58084', str_replace('–', '-', $text));
    }

    /** Gaps in the counts are not a range, and must not be flattened into one. */
    public function test_it_leaves_a_gapped_set_of_counts_spelled_out(): void
    {
        $text = $this->narrate(<<<'PERL'
        sub EVENT_ITEM {
          if (plugin::check_handin(\%itemcount, 58084 => 4) || plugin::check_handin(\%itemcount, 58084 => 1)) {
            quest::say("Good.");
          }
        }
        PERL, 'pl');

        $this->assertStringNotContainsString('1-4', str_replace('–', '-', $text));
        $this->assertStringContainsString('4× ITEM#58084 or you hand in ITEM#58084', $text);
    }

    public function test_a_brace_inside_dialogue_does_not_close_the_block(): void
    {
        $text = $this->narrate(<<<'PERL'
        sub EVENT_SAY {
          if ($text=~/hail/i) {
            quest::say("Take this } and go # now");
            quest::summonitem(1001);
          }
          if ($text=~/again/i) {
            quest::summonitem(1002);
          }
        }
        PERL, 'pl');

        $this->assertStringContainsString('Says: "Take this } and go # now"', $text);
        // Both branches have to still be inside the handler, one after the other.
        $this->assertStringContainsString('If you say "hail"', $text);
        $this->assertStringContainsString('If you say "again"', $text);
        $this->assertStringContainsString('Gives you ITEM#1002', $text);
    }

    /**
     * The newer hand-in call, in both languages. Left unread these printed their
     * item ids as source, which is the one thing the walkthrough is for.
     */
    public function test_it_reads_the_handin_call_in_either_language(): void
    {
        $lua = $this->narrate(<<<'LUA'
        function event_trade(e)
            if(eq.handin({[67616] = 1})) then
                e.self:Say("My thanks.");
            end
        end
        LUA, 'lua');

        $this->assertStringContainsString('If you hand in ITEM#67616', $lua);

        $perl = $this->narrate(<<<'PERL'
        sub EVENT_ITEM {
          if (quest::handin({"platinum" => 300, 62632 => 1})) {
            quest::say("Done.");
          }
        }
        PERL, 'pl');

        $this->assertStringContainsString('If you hand in ITEM#62632 and 300 pp', $perl);
    }

    /** plugin::takeItems only returns true when the player actually handed them in. */
    public function test_it_reads_take_items_as_a_hand_in(): void
    {
        $text = $this->narrate(<<<'PERL'
        sub EVENT_ITEM {
          if (plugin::takeItems(13885 => 4)) {
            quest::say("Good.");
          }
        }
        PERL, 'pl');

        $this->assertStringContainsString('If you hand in 4× ITEM#13885', $text);
    }

    public function test_it_reads_the_raw_itemcount_hash(): void
    {
        $text = $this->narrate(<<<'PERL'
        sub EVENT_ITEM {
          if ($itemcount{85062} == 2) {
            quest::say("Two of them.");
          }
        }
        PERL, 'pl');

        $this->assertStringContainsString('If you hand in 2× ITEM#85062', $text);
    }

    /** The call that does the work is not always the first one on the line. */
    public function test_it_looks_past_a_cast_to_the_call_that_hands_an_item_over(): void
    {
        $text = $this->narrate(<<<'LUA'
        function event_spawn(e)
            e.self:CastToNPC():AddItem(56016, 1);
        end
        LUA, 'lua');

        $this->assertStringContainsString('Carries ITEM#56016', $text);
    }

    /**
     * AddItem stocks an NPC or a corpse; only SummonItem reaches the player. Read
     * as one thing, they put items in players' hands that no quest gives them.
     */
    public function test_it_separates_loot_an_npc_carries_from_what_it_gives_you(): void
    {
        $text = $this->narrate(<<<'LUA'
        function event_death_complete(e)
            e.other:AddItem(1001, 1);
            e.corpse:AddItem(1002, 1);
            e.self:SummonItem(1003);
        end
        LUA, 'lua');

        $this->assertStringContainsString('Carries ITEM#1001', $text);
        $this->assertStringContainsString('Adds to the corpse ITEM#1002', $text);
        $this->assertStringContainsString('Gives you ITEM#1003', $text);
    }

    /** A parenthesised half of a test must not vanish when its head is read. */
    public function test_it_keeps_both_halves_of_a_parenthesised_clause(): void
    {
        $text = $this->narrate(<<<'PERL'
        sub EVENT_ITEM {
          if (($itemcount{85062} && $ulevel >= 50) || $itemcount{124688}) {
            quest::say("Good.");
          }
        }
        PERL, 'pl');

        $this->assertStringContainsString('you hand in ITEM#85062', $text);
        $this->assertStringContainsString('you are level 50 or above', $text);
        $this->assertStringContainsString('you hand in ITEM#124688', $text);
    }

    public function test_it_shows_a_line_it_cannot_translate_rather_than_dropping_it(): void
    {
        $result = QuestNarrator::narrate(<<<'LUA'
        function event_spawn(e)
            e.self:SomethingNobodyHasTaughtItYet(42);
        end
        LUA, 'lua');

        $this->assertSame(1, $result['untranslated']);
        $this->assertStringContainsString('SomethingNobodyHasTaughtItYet(42)', $this->flatten($result));
    }

    public function test_it_pairs_every_branch_of_a_nested_conditional(): void
    {
        $text = $this->narrate(<<<'PERL'
        sub EVENT_SAY {
          if ($text=~/hail/i) {
            if (defined($qglobals{winered}) && ($qglobals{winered} == 1)) {
              quest::say("Welcome back.");
            }
            else {
              quest::say("Hello, friend.");
            }
          }
        }
        PERL, 'pl');

        $this->assertStringContainsString('If you say "hail"', $text);
        $this->assertStringContainsString('If you have the winered quest flag and the winered quest flag is 1', $text);
        $this->assertStringContainsString('Otherwise', $text);
        $this->assertStringContainsString('Says: "Hello, friend."', $text);
    }

    public function test_it_names_the_task_a_selector_offers(): void
    {
        $text = $this->narrate(<<<'PERL'
        sub EVENT_SAY {
          if ($text=~/tasks/i) {
            quest::taskselector(500170);
          }
        }
        PERL, 'pl');

        $this->assertStringContainsString('Offers TASK#500170', $text);
    }

    /** Flatten a narration to one string, ids and all, for assertion. */
    private function narrate(string $body, string $language): string
    {
        return $this->flatten(QuestNarrator::narrate($body, $language));
    }

    private function flatten(array $result): string
    {
        $out = '';

        foreach ($result['scenes'] as $scene) {
            $out .= $scene['title'] . "\n" . $this->entries($scene['entries']);
        }

        return $out;
    }

    private function entries(array $entries): string
    {
        $out = '';

        foreach ($entries as $entry) {
            if ($entry['type'] === 'branch') {
                $out .= $entry['joiner'] . ' ' . $this->segments($entry['condition']) . "\n"
                    . $this->entries($entry['entries']);

                continue;
            }

            $out .= $this->segments($entry['segments'])
                . ($entry['quote'] === null ? '' : ' "' . $entry['quote'] . '"')
                . "\n";
        }

        return $out;
    }

    private function segments(array $segments): string
    {
        $out = '';

        foreach ($segments as $segment) {
            $out .= match ($segment['t']) {
                'text', 'em', 'flag', 'code' => $segment['v'],
                'quote' => '"' . $segment['v'] . '"',
                default => strtoupper($segment['t']) . '#' . $segment['id'],
            };
        }

        return $out;
    }
}
