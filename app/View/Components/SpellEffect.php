<?php

namespace App\View\Components;

use Closure;
use App\Models\Pet;
use App\Models\Aura;
use App\Models\Item;
use App\Models\Spell;
use Illuminate\View\Component;
use Illuminate\Support\Collection;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;

class SpellEffect extends Component
{
    public $spell;
    public $n;
    public $allSpells;
    public $allZones;

    public function __construct(Spell $spell, int $n, Collection $allSpells, Collection $allZones)
    {
        $this->spell = $spell;
        $this->n = $n;
        $this->allSpells = $allSpells;
        $this->allZones = $allZones;
    }

    public function render(): View|Closure|string
    {
        $desc = $this->getSpellEffectInfo();

        return view('components.spell-effect', ['desc' => $desc]);
    }

    protected function getItem(int $id)
    {
        if (!$id) return null;

        return Cache::remember("item:$id", now()->addMonth(), function () use ($id) {
            return Item::select('id', 'Name', 'icon')->find($id);
        });
    }

    protected function getPet(string $type)
    {
        if (!$type) return null;

        return Cache::remember("pet:$type", now()->addMonth(), function () use ($type) {
            return Pet::select('id', 'type')->where('type', $type)->first();
        });
    }

    protected function getSpellGroup(int $id)
    {
        if (!$id) return null;

        return Cache::remember("spellgroup:$id", now()->addMonth(), function () use ($id) {
            return Spell::select('id', 'name', 'new_icon')->where('spellgroup', $id)->first();

        });
    }

    protected function getAura(int $id)
    {
        if (!$id) return null;

        return Aura::select('type', 'spell_id', 'duration')->where('type', $id)
            ->with('spell')->get()->first();
    }

    // https://github.com/Akkadius/spire/blob/07f745962011a257227b3108590460f9d042cdb6/frontend/src/app/spells.ts
    protected function getSpellEffectInfo()
    {
        $serverMaxLvl = config('everquest.server_max_level');
        $n = $this->n;
        $spell = $this->spell;
        $desc = '';

        $serverMaxLevel = 100;
        $effectsInfo = '';
        $tmp         = '';
        $pertick     = $spell['buffduration'] ? ' per tick ' : '';
        $base        = $spell['effect_base_value' . $n];
        $limit       = $spell['effect_limit_value' . $n];
        $max         = $spell['max' . $n];
        $formula     = $spell['formula' . $n];

        if ($spell['effectid' . $n] !== 254) {

            $id = $spell['effectid' . $n];

            $maxlvl = $serverMaxLevel;
            $minlvl = 255; // make this 255; FIX THIS

            for ($classId = 1; $classId <= 16; $classId++) {
                if ($spell['classes' . $classId] < $minlvl) {
                    $minlvl = $spell['classes' . $classId];
                }
            }

            $value_min = $this->calcSpellEffectValue($spell['formula' . $n], $base, $max, 1, $minlvl);
            $value_max = $this->calcSpellEffectValue($spell['formula' . $n], $base, $max, 1, $serverMaxLevel);

            if (($value_min < $value_max) && ($value_max < 0)) {
                $tn = $value_min;
                $value_min = $value_max;
                $value_max = $tn;
            }

            $special_range = $this->calcValueRange(
                $spell['formula' . $n], $base, $max, $spell['effectid' . $n],
                $spell['buffduration'], $serverMaxLevel
            );

            if (($spell['formula' . $n] != 100) && ($minlvl < 255)) {
                $maxlvl = $this->getSpellMaxOutLevel($spell['formula' . $n], $base, $max, $minlvl);
            }

            switch ($id) {
                case 0:
                    $tmp .= $limit > 0 ? ' (' . config('everquest.spell_target_restrictions.' . abs($limit)) . ')' : '';
                    $desc .= $this->getFormatStandard('Current HP', '', $value_min, $value_max, $minlvl, $maxlvl) . $pertick . $special_range . $tmp;
                    break;
                case 1:
                    $desc .= $this->getFormatStandard('AC', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 2:
                    $desc .= $this->getFormatStandard('ATK', '', $value_min, $value_max, $minlvl, $maxlvl);
                break;
                case 3:
                    $desc .= $this->getFormatStandard('Movement Speed', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 4:
                    $desc .= $this->getFormatStandard('STR', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 5:
                    $desc .= $this->getFormatStandard('DEX', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 6:
                    $desc .= $this->getFormatStandard('AGI', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 7:
                    $desc .= $this->getFormatStandard('STA', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 8:
                    $desc .= $this->getFormatStandard('INT', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 9:
                    $desc .= $this->getFormatStandard('WIS', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 10:
                    if ($base === 0 && ($spell['formula' . $n] === 100)) { //This is used as a placeholder do not calculate
                        $desc = '';
                    } else {
                        $desc .= $this->getFormatStandard('CHA', '', $value_min, $value_max, $minlvl, $maxlvl);
                    }
                    break;
                case 11: // Slow 70=>-30%, Haste 130=>+30%
                    if ($base < 100) {
                        $value_max = (100 - $value_max) * -1;
                        $value_min = (100 - $value_min) * -1;
                    } else {
                        $value_max = $value_max - 100;
                        $value_min = $value_min - 100;
                    }
                    $desc .= $this->getFormatStandard('Attack Speed', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 12:
                    $desc .= 'Invisibility (Unstable)' . ($base > 1 ? ' (Enhanced ' . $base . ')' : '');
                    break;
                case 13:
                    $desc .= 'See Invisible' . ($base > 1 ? ' (Enhanced ' . $base . ')' : '');
                    break;
                case 14:
                    $desc .= 'Enduring Breath';
                    break;
                case 15:
                    $desc .= $this->getFormatStandard('Current Mana', '', $value_min, $value_max, $minlvl, $maxlvl) . $pertick . $special_range;
                    break;
                case 16:
                    $desc .= 'Error: (' . $spell[$id] . ') not used';
                    break;
                case 17:
                    $desc .= 'Error: (' . $spell[$id] . ') not used';
                    break;
                case 18:
                    $desc .= 'Pacify';
                    break;
                case 19:
                    $desc .= $this->getFormatStandard('Faction', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 20:
                    $desc .= 'Blind';
                    break;
                case 21:
                    if ($base !== $limit && $limit !== 0) {
                        $tmp .= ' ( ' . ($limit / 1000) . ' in PvP)';
                    }
                    $desc .= 'Stun for ' . ($base / 1000) . ' sec' . $tmp . $this->getUpToMaxLvl($max);
                    break;
                case 22:
                    $desc .= 'Charm' . $this->getUpToMaxLvl($max);
                    break;
                case 23:
                    $desc .= 'Fear' . $this->getUpToMaxLvl($max);
                break;
                case 24:
                    $desc .= $this->getFormatStandard('Stamina Loss', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 25:
                    if ($base === 2) {
                        $tmp .= ' (Secondary Bind Point)';
                    } else if ($base === 3) {
                        $tmp .= ' (Tertiary Bind Point)';
                    }
                    $desc .= 'Bind' . $tmp;
                    break;
                case 26:
                    if ($limit === 2) {
                        $tmp .= ' to Secondary Bind Point ';
                    } else if ($limit === 3) {
                        $tmp .= ' to Tertiary Bind Point ';
                    }
                    $desc .= 'Gate' . $tmp . ' (' . (100 - $base) . '% chance to fail)';
                    break;
                case 27:
                    $desc .= 'Dispel' . ' with level modifier of ' . ' (' . $base . ')';
                    break;
                case 28:
                    $desc .= 'Invisibility to Undead (Unstable)' . ($base > 1 ? ' (Enhanced ' . $base . ')' : '');
                    break;
                case 29:
                    $desc .= 'Invisibility to Animals (Unstable)' . ($base > 1 ? ' (Enhanced ' . $base . ')' : '');
                    break;
                case 30:
                    $desc .= 'Decrease Aggro Radius to ' . $base . $this->getUpToMaxLvl($max);
                    break;
                case 31:
                    $desc .= 'Mesmerize' . $this->getUpToMaxLvl($max) . ' (Stack Type: ' . $base . ')';
                    break;
                case 32:
                    $item = $this->getItem($spell['effect_base_value' . $n]);
                    $desc .= 'Summon Item ';
                    if ($item instanceof Item && isset($item->Name)) {
                        if ($max > 1) {
                            $desc .= ' (Stacks: ' . $max . ') ';
                        } elseif ($spell['formula' . $n] >= 1 && $spell['spell_category'] == 60) { // enchant item
                            $desc .= ' <span class="text-success">x' . $spell['formula' . $n] . '</span> ';
                        } elseif ($spell['spell_category'] == 217) { // summon weapon
                            $desc .= ' ';
                        }

                        $desc .= view('components.item-link', [
                            'itemId' => $item->id,
                            'itemName' => $item->Name,
                            'itemIcon' => $item->icon,
                            'itemClass' => 'inline-block ml-1',
                        ])->render();
                    } else {
                        $desc .= 'Unknown Item';
                    }
                    break;
                case 33:
                    $pet = $this->getPet($spell['teleport_zone']);
                    if ($pet instanceof Pet) {
                        $desc .= 'Summon Pet: ' . $this->renderPetDetails($pet->id, $pet->type);
                    } else {
                        $desc .= 'Summon Pet: ' . ($spell['teleport_zone'] ?? 'Unknown Pet');
                    }
                    break;
                case 34:
                    $desc .= 'Error: (' . $spell[$id] . ') not used';
                    break;
                case 35:
                    $desc .= $this->getFormatStandard('Disease Counter', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 36:
                    $desc .= $this->getFormatStandard('Poison Counter', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 37:
                    $desc .= 'Error: (' . $spell[$id] . ') not used';
                    break;
                case 38:
                    $desc .= 'Error: (' . $spell[$id] . ') not used';
                    break;
                case 39:
                    $desc .= 'Can not be Twincast';
                    break;
                case 40:
                    $desc .= 'Invulnerability';
                    break;
                case 41:
                    $desc .= 'Destroy';
                    break;
                case 42:
                    $desc .= 'Shadowstep';
                    break;
                case 43:
                    $desc .= 'Berserk: Allows chance to crippling blow';
                    break;
                case 44:
                    $desc .= 'Stacking: Delayed Heal Marker ' . $base;
                    break;
                case 45:
                    $desc .= 'Lifetap from Weapon Damage: ' . $base . '%';
                    break;
                case 46:
                    $desc .= $this->getFormatStandard('Fire Resist', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 47:
                    $desc .= $this->getFormatStandard('Cold Resist', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 48:
                    $desc .= $this->getFormatStandard('Poison Resist', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 49:
                    $desc .= $this->getFormatStandard('Disease Resist', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 50:
                    $desc .= $this->getFormatStandard('Magic Resist', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 51:
                    $desc .= 'Error: (' . $spell[$id] . ') not used';
                    break;
                case 52:
                    $desc .= 'Sense Undead';
                    break;
                case 53:
                    $desc .= 'Sense Summoned';
                    break;
                case 54:
                    $desc .= 'Sense Animal';
                    break;
                case 55:
                    $desc .= 'Absorb Damage: 100%, Total: ' . $base;
                    break;
                case 56:
                    $desc .= 'True North';
                    break;
                case 57:
                    $desc .= 'Levitate' . ($limit > 0 ? ' While Moving' : '') . ($base > 1 ? ' (Stack Type: ' . $base . ')' : '');
                    break;
                case 58:
                    $desc .= 'Illusion: ' . config('everquest.db_races.' . $spell['effect_base_value' . $n]);
                    break;
                case 59:
                    $desc .= $this->getFormatStandard("Damage Shield", "", -$value_min, -$value_max, $minlvl, $maxlvl);
                    break;
                case 60:
                    $desc .= 'Error: (' . $spell[$id] . ') not used';
                    break;
                case 61:
                    $desc .= 'Identify Item';
                    break;
                case 62:
                    $desc .= 'Error: (' . $spell[$id] . ') not used';
                    break;
                case 63:
                    $desc .= 'Memory Blur' . ' (' . $base . '% chance)';
                    break;
                case 64:
                    if ($base !== $limit && $limit !== 0) {
                        $desc .= 'Stun and Spin NPC for ' . ($base / 1000) . ' sec (PC for ' . ($limit / 1000) . ' sec ' . $this->getUpToMaxLvl($max);
                    } else {
                        $desc .= 'Stun and Spin for ' . ($base / 1000) . ' sec ' . $this->getUpToMaxLvl($max);
                    }
                    break;
                case 65:
                    $desc .= 'Infravision';
                    break;
                case 66:
                    $desc .= 'Ultravision';
                    break;
                case 67:
                    $desc .= 'Eye of Zomm: ' . $spell['teleport_zone'];
                    break;
                case 68:
                    $desc .= 'Reclaim Pet Mana';
                    break;
                case 69:
                    $desc .= $this->getFormatStandard("Max HP", "", $value_min, $value_max, $minlvl, $maxlvl) . $special_range;
                    break;
                case 70:
                    $desc .= 'Error: (' . $spell[$id] . ') not used';
                    break;
                case 71:
                    $pet = $this->getPet($spell['teleport_zone']);
                    if ($pet instanceof Pet) {
                        $desc .= 'Summon Pet: ' . $this->renderPetDetails($pet->id, $pet->type);
                    } else {
                        $desc .= 'Summon Pet: ' . ($spell['teleport_zone'] ?? 'Unknown Pet');
                    }
                    break;
                case 72:
                    $desc .= "Error: (" . $spell[$id] . ") not used";
                    break;
                case 73:
                    $desc .= 'Bind Sight';
                    break;
                case 74:
                    $desc .= 'Feign Death (' . $base . '% chance)';
                    break;
                case 75:
                    $desc .= 'Project Voice';
                    break;
                case 76:
                    $desc .= 'Sentinel';
                    break;
                case 77:
                    $desc .= 'Locate Corpse';
                    break;
                case 78:
                    $desc .= 'Absorb Spell Damage: 100%, Total: ' . $base;
                    break;
                case 79:
                    $tmp .= $limit ? ' (' . config('everquest.spell_target_restrictions.' . abs($limit)) . ')' : '';
                    $desc .= $this->getFormatStandard('Current HP', '', $value_min, $value_max, $minlvl, $maxlvl) . $special_range . $tmp;
                    break;
                case 80:
                    $desc .= 'Error: (' . $spell[$id] . ') not used';
                    break;
                case 81:
                    $desc .= 'Resurrect with ' . $base . '% XP';
                    break;
                case 82:
                    $desc .= 'Summon Player';
                    break;
                case 83: //TODO teleport zone long enum ?
                    $targetZone = strtolower(trim($spell['teleport_zone'] ?? ''));
                    $zone = null;

                    if ($targetZone !== '' && isset($this->allZones[$targetZone])) {
                        $zone = $this->allZones[$targetZone];
                    }

                    // Teleport base values are stored y, x, z (see EQEmu SE_Teleport)
                    $coords = format_loc(
                        $spell['effect_base_value' . ($n + 1)],
                        $spell['effect_base_value' . ($n)],
                        $spell['effect_base_value' . ($n + 2)]
                    );
                    $desc .= 'Teleport to (' . $coords . ') in ';

                    if ($zone) {
                        $desc .= '<a href="/zones/' . $zone->id . '" class="link-accent link-hover">' . $zone->long_name . '</a>';
                    } else {
                        $desc .= 'Unknown Zone (' . ($targetZone ?: 'Empty') . ')';
                    }
                    break;
                case 84:
                    $desc .= 'Gravity Flux';
                    break;
                case 85:
                    $desc .= 'Add Melee Proc ';
                    $desc .= $this->renderSpellEffect($base);
                    $desc .= ($limit ? ' with ' . $limit . ' % Rate Mod' : '');
                    break;
                case 86:
                    $desc .= 'Decrease Social Radius to ' . $base . $this->getUpToMaxLvl($max);
                    break;
                case 87:
                    $desc .= $this->getFormatStandard('Magnification', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 88:
                    $targetZone = strtolower(trim($spell['teleport_zone'] ?? ''));

                    if ($targetZone !== '' && $targetZone !== 'same' && isset($this->allZones[$targetZone])) {
                        $zone = $this->allZones[$targetZone];

                        // Teleport base values are stored y, x, z (see EQEmu SE_Succor)
                        $coords = format_loc(
                            $spell['effect_base_value' . ($n + 1)],
                            $spell['effect_base_value' . ($n)],
                            $spell['effect_base_value' . ($n + 2)]
                        );
                        $desc .= 'Evacuate to (' . $coords . ') in ';
                        $desc .= '<a href="/zones/' . $zone->id . '" class="link-accent link-hover">' . $zone->long_name . '</a>';
                    } else {
                        $desc .= 'Evacuate to safe point in zone';
                    }
                break;
                case 89:
                    if ($base !== 0 && $base !== 100) {
                        if ($base < 100) {
                            $value_max = (100 - $value_max) * -1;
                            $value_min = (100 - $value_min) * -1;
                        } else {
                            $value_max = $value_max - 100;
                            $value_min = $value_min - 100;
                        }
                        $desc .= $this->getFormatStandard("Player Size", '%', $value_min, $value_max, $minlvl, $maxlvl);
                    } else if ($limit) {
                        $desc .= 'Set Player Size: ' . $limit;
                    }
                    break;
                case 90:
                    $desc .= 'Ignore Pet (not implemented)';
                    break;
                case 91:
                    $desc .= 'Summon Corpse up to level ' . $base;
                    break;
                case 92:
                    $desc .= $this->getFormatStandard("Hate", "", $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 93:
                    $desc .= 'Stop Rain';
                    break;
                case 94:
                    $desc .= 'Cancel if Combat Initiated';
                    break;
                case 95:
                    $desc .= 'Sacrifice';
                    break;
                case 96:
                    $desc .= 'Inhibit Spell Casting (Silence)';
                    break;
                case 97:
                    $desc .= $this->getFormatStandard("Max Mana", "", $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 98:
                    if ($base < 100) {
                        $value_max = (100 - $value_max) * -1;
                        $value_min = (100 - $value_min) * -1;
                    } else {
                        $value_max = $value_max - 100;
                        $value_min = $value_min - 100;
                    }
                    $desc .= $this->getFormatStandard('Attack Speed', '%', $value_min, $value_max, $minlvl, $maxlvl) . '(v98 capped)';
                    break;
                case 99:
                    $desc .= 'Root';
                    break;
                case 100:
                    $tmp .= $limit ? ' (' . config('everquest.spell_target_restrictions.' . abs($limit)) . ')' : '';
                    $desc .= $this->getFormatStandard('Current HP', '', $value_min, $value_max, $minlvl, $maxlvl) . $pertick . $special_range . $tmp;
                    break;
                case 101:
                    $desc .= 'Increase Current HP by ' . ($base * 7500) . ' with recast blocking buff';
                    break;
                case 102:
                    $desc .= 'Fear Immunity';
                    break;
                case 103:
                    $desc .= 'Summon Pet to Player';
                    break;
                case 104:
                    $targetZone = $spell['teleport_zone'] ?? '';
                    $tzKey = strtolower(trim($targetZone));

                    if ($tzKey !== '' && isset($this->allZones[$tzKey])) {
                        $zone = $this->allZones[$tzKey];

                        // Teleport base values are stored y, x, z (see EQEmu SE_Translocate)
                        $coords = format_loc(
                            $spell['effect_base_value' . ($n + 1)],
                            $spell['effect_base_value' . ($n)],
                            $spell['effect_base_value' . ($n + 2)]
                        );
                        $desc .= 'Translocate to (' . $coords . ') in ';
                        $desc .= '<a href="/zones/' . $zone->id . '" class="link-accent link-hover">' . $zone->long_name . '</a>';
                    } else {
                        $desc .= 'Translocate to bind';
                    }
                    break;
                case 105:
                    $desc .= 'Inhibit Gate (' . $base . ')';
                    break;
                case 106:
                    $pet = $this->getPet($spell['teleport_zone']);
                    if ($pet instanceof Pet) {
                        $desc .= 'Summon Warder: ' . $this->renderPetDetails($pet->id, $pet->type);
                    } else {
                        $desc .= 'Summon Warder: ' . ($spell['teleport_zone'] ?? 'Unknown Warder');
                    }
                    break;
                case 107:
                    $desc .= $this->getFormatStandard('NPC Level', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 108:
                    $pet = $this->getPet($spell['teleport_zone']);
                    if ($pet instanceof Pet) {
                        $desc .= 'Summon Familiar: ' . $this->renderPetDetails($pet->id, $pet->type);
                    } else {
                        $desc .= 'Summon Familiar: ' . ($spell['teleport_zone'] ?? 'Unknown Familiar');
                    }
                    break;
                case 109:
                    $item = $this->getItem($spell['effect_base_value' . $n]);
                    $desc .= 'Summon into Bag ';
                    if ($item instanceof Item && isset($item->id)) {
                        $desc .= view('components.item-link', [
                            'itemId' => $item->id,
                            'itemName' => $item->Name,
                            'itemIcon' => $item->icon,
                            'itemClass' => 'inline-block ml-1',
                        ])->render();
                    } else {
                        $desc .= 'Unknown Item';
                    }
                    break;
                case 110:
                    $desc .= 'Error: (' . $spell[$id] . ') not used';
                    break;
                case 111:
                    $desc .= $this->getFormatStandard('All Resists', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 112:
                    $desc .= $this->getFormatStandard('Effective Casting Level', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 113:
                    $desc .= 'Summon Mount ' . $spell['teleport_zone'];
                    break;
                case 114:
                    $desc .= $this->getFormatStandard('Hate Generated', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 115:
                    $desc .= 'Reset Hunger Counter';
                    break;
                case 116:
                    $desc .= $this->getFormatStandard('Curse Counter', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 117:
                    $desc .= 'Make Weapon Magical';
                    break;
                case 118:
                    $desc .= $this->getFormatStandard('Singing Amplification', '%', ($value_min * 10), ($value_max * 10), $minlvl, $maxlvl);
                    break;
                case 119:
                    $desc .= $this->getFormatStandard('Melee Overhaste', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 120:
                    $desc .= $this->getFormatStandard('Healing Received', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 121:
                    $desc .= $this->getFormatStandard('Reverse Damage Shield', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 122:
                    $desc .= 'Error: (' . $spell[$id] . ') not used';
                    break;
                case 123:
                    $desc .= 'Buff Blocker: Screech (' . $base . ')';
                    break;
                case 124:
                    $desc .= $this->getFocusPercentRange('Spell Damage', $base, $limit, false);
                    break;
                case 125:
                    $desc .= $this->getFocusPercentRange('Healing', $base, $limit, false);
                    break;
                case 126:
                    $desc .= $this->getFocusPercentRange('Spell Resist Rate', $base, $limit, true);
                    break;
                case 127:
                    $desc .= $this->getFocusPercentRange('Spell Haste', $base, $limit, false);
                    break;
                case 128:
                    $desc .= $this->getFocusPercentRange('Spell Duration', $base, $limit, false);
                    break;
                case 129:
                    $desc .= $this->getFocusPercentRange('Spell Range', $base, $limit, false);
                    break;
                case 130:
                    $desc .= $this->getFocusPercentRange('Spell and Bash Hate', $base, $limit, false);
                    break;
                case 131:
                    $desc .= $this->getFocusPercentRange('Chance of Using Reagent', $base, $limit, true);
                    break;
                case 132:
                    $desc .= $this->getFocusPercentRange('Spell Mana Cost', $base, $limit, true);
                    break;
                case 133:
                    $desc .= $this->getFocusPercentRange('Stun Time', $base, $limit, false);
                    break;
                case 134:
                    $desc .= 'Limit Max Level: ' . $base . ' (lose ' . ($limit ? $limit : 100) . '% per level)';
                    break;
                case 135:
                    $desc .= 'Limit Resist: ' . ($base < 0 ? 'Exclude ' : '') . config('everquest.db_elements.' . abs($base));
                    break;
                case 136:
                    $desc .= 'Limit Target: ' . ($base < 0 ? 'Exclude ' : '') . config('everquest.spell_targets.' . abs($base));
                    break;
                case 137:
                    $desc .= 'Limit Effect: ' . ($base < 0 ? 'Exclude ' : '') . config('everquest.spell_effects.' . abs($base));
                    break;
                case 138:
                    $desc .= 'Limit Type: ' . ($base ? 'Beneficial' : 'Detrimental');
                    break;
                case 139:
                    $spellKey = abs($base);
                    $desc .= 'Limit Spell: ' . ($base < 0 ? 'Exclude ' : '');
                    if (isset($this->allSpells[$spellKey])) {
                        $desc .= $this->renderSpellEffect($spellKey);
                    } else {
                        $desc .= 'Unknown Spell';
                    }
                    break;
                case 140:
                    $desc .= 'Limit Min Duration: ' . ($base * 6) . 's';
                    break;
                case 141:
                    $desc .= 'Limit Duration Type: ' . ($base ? 'Non-Duration Spells' : 'Duration Spells');
                    break;
                case 142:
                    $desc .= 'Limit Min Level: ' . $base;
                    break;
                case 143:
                    $desc .= 'Limit Min Casting Time: ' . ($base / 1000) . 's';
                    break;
                case 144:
                    $desc .= 'Limit Max Casting Time: ' . ($base / 1000) . 's';
                    break;
                case 145:
                    if ($spell['teleport_zone'] !== 'same') {
                        $zone = $this->allZones[$spell['teleport_zone']];
                        // Teleport base values are stored y, x, z (see EQEmu SE_Teleport2)
                        $coords = format_loc(
                            $spell['effect_base_value' . ($n + 1)],
                            $spell['effect_base_value' . ($n)],
                            $spell['effect_base_value' . ($n + 2)]
                        );
                        $desc .= 'Teleport to (' . $coords . ') in ' . $spell['teleport_zone'];
                    } else {
                        $desc .= 'Teleport to to safe point in zone';
                    }
                    break;
                case 146: //todo data location for port xyz for 45 , Set position to
                    break;
                case 147:
                    $desc .= $this->getFormatStandard('Current HP', '%', $value_min, $value_max, $minlvl, $maxlvl) . ' up to ' . $max;
                    break;
                case 148:
                    $tmp .= $limit ? $limit : $spell['formula_' . $n] % 100;
                    $desc .= 'Stacking: Block new spell if slot ' . $tmp . ' is ' . config('everquest.spell_effects.' . abs($base)) . ' and less than ' . $max;
                    break;
                case 149:
                    $tmp .= $limit ? $limit : $spell['formula_' . $n] % 100;
                    $desc .= 'Stacking: Overwrite spell if slot ' . $tmp . ' is ' . config('everquest.spell_effects.' . abs($base)) . ' and less than ' . $max;
                    break;
                case 150:
                    $tmp .= $max ? ' (Increase heal by ' . $max . ' if affected is above lv ' . $limit . ')' : '';
                    $desc .= ($base === 1) ? 'Divine Intervention with 300 Heal' . $tmp : 'Divine Intervention with 8000 Heal' . $tmp;
                    break;
                case 151:
                    $desc .= 'Suspend Pet' . ($base ? ' with Buffs' : '');
                    break;
                case 152:
                    $pet = $this->getPet($spell['teleport_zone']);
                    if ($pet instanceof Pet) {
                        $desc .= 'Summon Temp Pet: ' . $this->renderPetDetails($pet->id, $pet->type) . ' x' . $base . ' for ' . $max . 's';
                    } else {
                        $desc .= 'Summon Familiar: ' . ($spell['teleport_zone'] ?? 'Unknown Familiar') . ' x' . $base . ' for ' . $max . 's';
                    }
                    break;
                case 153:
                    $desc .= 'Balance Group HP with ' . $base . '% Penalty (Max HP taken: ' . $limit . ')';
                    break;
                case 154:
                    if ($limit !== 0) {
                        $desc .= 'Decrease Detrimental Duration by 50% ' . ($base / 10) . '% Chance)' . $this->getUpToMaxLvl($max);
                    } else {
                        $desc .= 'Dispel Detrimental ' . ($base / 10) . '% Chance' . $this->getUpToMaxLvl($max);
                    }
                    break;
                case 156:
                    $desc .= 'Illusion: Target';
                    break;
                case 157:
                    $desc .= $this->getFormatStandard('Spell Damage Shield', '', -$value_min, -$value_max, $minlvl, $maxlvl);
                    break;
                case 158:
                    $tmp .= $max ? ' with up to ' . $max . '% Base Damage' : '';
                    if ($limit > 0) {
                        $tmp .= ' and ' . $limit . ' Improved Resist Mod';
                    } else if ($limit < 0) {
                        $tmp .= ' and ' . $limit . ' Reduced Resist Mod';
                    }
                    $desc .= $this->getFormatStandard('Chance to Reflect Spell', '%', $value_min, $value_max, $minlvl, $maxlvl) . $tmp;
                    break;
                case 159:
                    $desc .= $this->getFormatStandard('All Base Stats', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 160:
                    $desc .= 'Intoxicate if Tolerance under ' . $base;
                    break;
                case 161:
                    $tmp .= $limit ? 'Max Per Hit: ' . $limit : '';
                    $tmp .= $max ? ', Total: ' . $max : '';
                    $desc .= 'Absorb Spell Damage: ' . $base . '%' . $tmp;
                    break;
                case 162:
                    $tmp .= $limit ? 'Max Per Hit: ' . $limit : '';
                    $tmp .= $max ? ', Total: ' . $max : '';
                    $desc .= 'Absorb Melee Damage: ' . $base . '%' . $tmp;
                    break;
                case 163:
                    $tmp .= $max ? ', Max Per Hit: ' . $max : '';
                    $desc .= 'Absorb ' . $base . ' Hits or Spells ' . $base . '%' . $tmp;
                    break;
                case 164:
                    $desc .= 'Appraise Chest ' . $value_max;
                    break;
                case 165:
                    $desc .= 'Disarm Chest ' . $value_max;
                    break;
                case 166:
                    $desc .= 'Unlock Chest ' . $value_max;
                    break;
                case 167:
                    $desc .= 'Increase Pet Power ' . $value_max;
                    break;
                case 168:
                    $desc .= $this->getFormatStandard('Melee Mitigation', '%', -$value_min, -$value_max, $minlvl, $maxlvl);
                    break;
                case 169:
                    $tmp .= $limit >= 0 ? ' with ' . config('everquest.db_skills.' . $limit) : '';
                    $desc .= $this->getFormatStandard('Chance to Critical Hit', '%', $value_min, $value_max, $minlvl, $maxlvl) . $tmp;
                    break;
                case 170:
                    $desc .= $this->getFormatStandard('Critical Nuke Damage', '%', $value_min, $value_max, $minlvl, $maxlvl) . ' of Base Damage';
                    break;
                case 171:
                    $desc .= $this->getFormatStandard('Chance to Crippling Blow', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 172:
                    $desc .= $this->getFormatStandard('Chance to Avoid Melee', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 173:
                    $desc .= $this->getFormatStandard('Chance to Riposte', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 174:
                    $desc .= $this->getFormatStandard('Chance to Dodge', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 175:
                    $desc .= $this->getFormatStandard('Chance to Parry', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 176:
                    $desc .= $this->getFormatStandard('Chance to Dual Wield', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 177:
                    $desc .= $this->getFormatStandard('Chance to Double Attack', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 178:
                    $desc .= 'Lifetap from Weapon Damage: ' . $base . '%';
                    break;
                case 179:
                    $desc .= 'Set All Instrument Modifiers: ' . $value_max;
                    break;
                case 180:
                    $desc .= $this->getFormatStandard('Chance to Resist Spell', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 181:
                    $desc .= $this->getFormatStandard('Chance to Resist Fear Spell', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 182:
                    $desc .= $this->getFormatStandard('Weapon Delay', '%', $value_min / 10, $value_max / 10, $minlvl, $maxlvl);
                    break;
                case 183:
                    $desc .= 'Error: (' . $spell[$id] . ') not used';
                    break;
                case 184:
                    $desc .= $this->getFormatStandard('Chance to Hit', '%', $value_min, $value_max, $minlvl, $maxlvl) . ($limit >= 0 ? ' with ' . config('everquest.db_skills.' . $limit) : '');
                    break;
                case 185:
                    $desc .= $this->getFormatStandard(config('everquest.db_skills.' . $limit) . ' Damage', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 186:
                    $desc .= $this->getFormatStandard('Min ' . config('everquest.db_skills.' . $limit) . ' Damage', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 187:
                    $desc .= 'Balance Group Mana with ' . $base . '% Penalty (Max Mana taken: ' . $limit . ')';
                    break;
                case 188:
                    $desc .= $this->getFormatStandard('Chance to Block', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 189:
                    $desc .= $this->getFormatStandard('Current Endurance', '', $value_min, $value_max, $minlvl, $maxlvl) . $pertick . $special_range;
                    break;
                case 190:
                    $desc .= $this->getFormatStandard('Max Endurance', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 191:
                    $desc .= 'Inhibit Combat Abilities';
                    break;
                case 192:
                    $desc .= $this->getFormatStandard('Hate', '', $value_min, $value_max, $minlvl, $maxlvl) . $pertick . $special_range;
                    break;
                case 193:
                    $desc .= config('everquest.db_skills.' . $spell['skill']) . ' Attack for ' . $base . ' with ' . $limit . ' % Accuracy Mod';
                    break;
                case 194:
                    $desc .= 'Cancel Aggro (' . $base . ' % Chance)';
                    break;
                case 195:
                    $desc .= $this->getFormatStandard('Chance to Resist Any Stun', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 196:
                    $desc .= $this->getFormatStandard('Strikethrough', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 197:
                    $tmp .= ($limit >= 0) ? config('everquest.db_skills.' . $limit) : ' Hit ';
                    $desc .= $this->getFormatStandard($tmp . ' Damage Taken', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 198:
                    $desc .= $this->getFormatStandard('Current Endurance', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 199:
                    $desc .= 'Taunt with ' . $limit . ' added Hate (Chance ' . $base . '%)';
                    break;
                case 200:
                    $desc .= $this->getFormatStandard('Worn Proc Rate', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 201:
                    $desc .= 'Add Range Proc: ';
                    $desc .= $this->renderSpellEffect($base);
                    $desc .= 'with ' . $limit . '% Rate Mod';
                    break;
                case 202:
                    $desc .= 'Project Illusion on Next Spell';
                    break;
                case 203:
                    $desc .= 'Mass Group Buff on Next Spell';
                    break;
                case 204:
                    $desc .= 'Group Fear Immunity for ' . ($base * 10) . 's';
                    break;
                case 205:
                    $desc .= 'Rampage (' . $base . ')' . ($limit ? ' (Max Hit Count: ' . $limit . ')' : '') . +($max ? ' (AE Range: ' . $max . ')' : '');
                    break;
                case 206:
                    $desc .= 'AE Taunt with ' . $base . ' added Hate';
                    break;
                case 207:
                    $desc .= 'Flesh to Bone Chips';
                    break;
                case 208:
                    $desc .= 'Error: (' . $spell[$id] . ') not used';
                    break;
                case 209:
                    if ($limit !== 0) {
                        $desc .= 'Decrease Beneficial Duration by 50% ' . ($base / 10) . '% Chance)' . $this->getUpToMaxLvl($max);
                    } else {
                        $desc .= 'Dispel Beneficial ' . ($base / 10) . '% Chance' . $this->getUpToMaxLvl($max);
                    }
                    break;
                case 210:
                    $desc .= 'Pet Shielding for ' . $base * 12 . 's' . ($limit ? ' (Owner Mitigation: ' . $limit . ' %)' : '') . ($max ? ' (Pet Mitigation: ' . $max . ' %)' : '');
                    break;
                case 211:
                    $desc .= 'AE Melee for ' . $base * 12 . 's';
                    break;
                case 212:
                    $desc .= $this->getFormatStandard('Frenzied Devastation: Chance to Critical Nuke', '%', $value_min, $value_max, $minlvl, $maxlvl) . ' and Increase Spell Mana Cost 100%';
                    break;
                case 213:
                    $desc .= $this->getFormatStandard('Pet Max HP', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 214:
                    $desc .= $this->getFormatStandard('Max HP', '%', $value_min / 100, $value_max / 100, $minlvl, $maxlvl);
                    break;
                case 215:
                    $desc .= $this->getFormatStandard('Pet Chance to Avoid Melee', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 216:
                    $desc .= $this->getFormatStandard('Accuracy', '', $value_min, $value_max, $minlvl, $maxlvl) . ($limit >= 0 ? ' with ' . config('everquest.db_skills.' . $limit) : '');
                    break;
                case 217:
                    $desc .= 'Add Headshot Proc with up to ' . $limit . ' Damage';
                    break;
                case 218:
                    $desc .= $this->getFormatStandard('Pet Chance to Critical Hit', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 219:
                    $desc .= $this->getFormatStandard('Chance to Slay Undead', '%', $value_min / 10, $value_max / 10, $minlvl, $maxlvl) . ' with ' . $limit . ' Damage Mod';
                    break;
                case 220:
                    $desc .= $this->getFormatStandard(config('everquest.db_skills.' . $limit) . ' Damage Bonus', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 221:
                    $desc .= $this->getFormatStandard('Weight', '%', -$value_min, -$value_max, $minlvl, $maxlvl);
                    break;
                case 222:
                    $desc .= $this->getFormatStandard('Chance to Block from Back', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 223:
                    $desc .= $this->getFormatStandard('Chance to Double Riposte', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 224:
                    if ($limit > 0) {
                        $desc .= $this->getFormatStandard('Chance of Additional Riposte', '%', $value_min, $value_max, $minlvl, $maxlvl) . ' with ' . config('everquest.db_skills.' . $limit);
                    } else {
                        $desc .= $this->getFormatStandard('Chance of Additional Riposte', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    }
                    break;
                case 225:
                    $desc .= $this->getFormatStandard('Chance to Double Attack ', '%', $value_min, $value_max, $minlvl, $maxlvl) . ' (Additive)';
                    break;
                case 226:
                    $desc .= 'Add Two-Handed Bash Ability';
                    break;
                case 227:
                    $desc .= 'Decrease ' . config('everquest.db_skills.' . $limit) . ' Timer by ' . seconds_to_human($base) . ' (Before Haste)';
                    break;
                case 228:
                    $desc .= $this->getFormatStandard('Falling Damage', '%', -$value_min, -$value_max, $minlvl, $maxlvl);
                    break;
                case 229:
                    $desc .= $this->getFormatStandard('Chance to Cast Through Stun', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 230:
                    $desc .= $this->getFormatStandard('Shield Ability Range', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 231:
                    $desc .= $this->getFormatStandard('Chance to Stun Bash', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 232:
                    $tmp .= $limit ? ' and ' . $this->renderSpellEffect($limit) : '';
                    $desc .= 'Cast ';
                    $desc .= $this->renderSpellEffect(4789);
                    $desc .= $tmp . ' on Death (' . $base . '% Chance Divine Save)';
                    break;
                case 233:
                    $desc .= $this->getFormatStandard('Food Consumption', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 234:
                    $desc .= 'Decrease Poison Application Time by ' . $base / 1000 . 's';
                    break;
                case 235:
                    $desc .= $this->getFormatStandard('Chance to Channel Spells', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 236:
                    $desc .= 'Error: (' . $spell[$id] . ') not used';
                    break;
                case 237:
                    $desc .= 'Enable Pet Ability: Receive Group Buffs';
                    break;
                case 238:
                    $desc .= ($base >= 2) ? 'Permanent Illusion (Persist After Death)' : ' Permanent Illusion';
                    break;
                case 239:
                    $desc .= $this->getFormatStandard('Chance to Feign Death Through Spell Hit', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 240:
                    $desc .= 'Error: (' . $spell[$id] . ') not used';
                    break;
                case 241:
                    $desc .= 'Reclaim Pet Mana (Return ' . $base . '%)';
                    break;
                case 242:
                    $desc .= $this->getFormatStandard('Chance to Memory Blur', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 243:
                    $desc .= $this->getFormatStandard('Chance of Charm Breaking', '%', -$value_min, -$value_max, $minlvl, $maxlvl);
                    break;
                case 244:
                    $desc .= $this->getFormatStandard('Chance of Root Breaking', '%', -$value_min, -$value_max, $minlvl, $maxlvl);
                    break;
                case 245:
                    $desc .= $this->getFormatStandard('Chance of Trap Circumvention', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 246:
                    $desc .= $this->getFormatStandard('Lung Capacity', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 247:
                    $desc .= $this->getFormatStandard(config('everquest.db_skills.' . $limit) . ' Skill Cap', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 248:
                    $desc .= 'Train Second Magic Specialization Ability (Secondary Forte)';
                    break;
                case 249:
                    $desc .= $this->getFormatStandard('Offhand Weapon Damage Bonus', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 250:
                    $desc .= $this->getFormatStandard('Melee Proc Rate (from buffs, abilities and skills', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 251:
                    $desc .= $this->getFormatStandard('Chance of Using Ammo', '%', -$value_min, -$value_max, $minlvl, $maxlvl);
                    break;
                case 252:
                    $desc .= $this->getFormatStandard('Chance to Backstab From Front', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 253:
                    $desc .= 'Allow Frontal Backstab for Minimum Damage';
                    break;
                case 255:
                    $desc .= 'Increase Shield Ability Duration by ' . $base . 's';
                    break;
                case 256:
                    $desc .= 'Shroud of Stealth (' . $base . ')';
                    break;
                case 257:
                    $desc .= 'Enable Pet Ability: Hold';
                    break;
                case 258:
                    $desc .= $this->getFormatStandard('Chance to Triple Backstab', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 259:
                    $desc .= $this->getFormatStandard('AC Soft Cap', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 260:
                    $desc .= 'Set Instrument Modifier: ' . config('everquest.db_bard_skills.' . $limit) . ' ' . $value_max;
                    break;
                case 261:
                    $desc .= $this->getFormatStandard('Song Cap', '', ($value_min), ($value_max), $minlvl, $maxlvl);
                    break;
                case 262:
                    $desc .= $this->getFormatStandard(config('everquest.db_spell_worn_attribute_cap.' . $limit) . ' Cap', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 263:
                    $desc .= $this->getFormatStandard('Ability to Specialize Tradeskills', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 264:
                    $desc .= 'Reduce [AA ' . $limit . '] Timer by ' . seconds_to_human($base);
                    break;
                case 265:
                    $desc .= 'No Fizzle up to level ' . $base;
                    break;
                case 266:
                    $desc .= $this->getFormatStandard('Chance of ' . $limit . ' Additional 2H Attacks', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 267:
                    $desc .= 'Enable Pet Ability: (' . config('everquest.db_spell_petcmds.' . $limit) . ')';
                    break;
                case 268:
                    $desc .= $this->getFormatStandard('Chance to Fail ' . config('everquest.db_skills.' . $limit) . ' Combine', '%', -$value_min, -$value_max, $minlvl, $maxlvl);
                    break;
                case 269:
                    $desc .= $this->getFormatStandard('Bandage HP Cap', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 270:
                    $desc .= $this->getFormatStandard('Beneficial Song Range', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 271:
                    $desc .= $this->getFormatStandard('Innate Movement Speed', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 272:
                    $desc .= $this->getFormatStandard('Effective casting level', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 273: //Does live effect now have decay component?
                    $desc .= $this->getFormatStandard('Chance to Critical DoT', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 274: //Does live effect now have decay component?
                    $desc .= $this->getFormatStandard('Chance to Critical Heal', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 275:
                    $desc .= $this->getFormatStandard('Chance to Critical Mend', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 276:
                    $desc .= $this->getFormatStandard('Dual Wield Skill Amount', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 277:
                    $desc .= 'Second Chance to Trigger Divine Intervention with a Heal for ' . $base . '% of baseline';
                    break;
                case 278:
                    $desc .= 'Add Finishing Blow Proc with up to ' . ($base / 10) . ' Damage (' . $limit . '% Chance)';
                    break;
                case 279:
                    $desc .= $this->getFormatStandard('Chance to Flurry', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 280:
                    $desc .= $this->getFormatStandard('Pet Chance to Flurry', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 281:
                    $desc .= 'Pet Chance to Feign Death (' . $base . '%)';
                    break;
                case 282:
                    $desc .= $this->getFormatStandard('Bandage Amount', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 283:
                    $desc .= $this->getFormatStandard('Chance to perform a Double Special Attack', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 284:
                    $desc .= 'Error: (' . $spell[$id] . ') not used';
                    break;
                case 285:
                    $desc .= 'Chance Hide skill will succeed while moving (' . $base . '%)';
                    break;
                case 286:
                    $desc .= $this->getFormatStandard('Spell Damage Amount', '', $value_min, $value_max, $minlvl, $maxlvl) . ' (before crit)';
                    break;
                case 287:
                    $desc .= $this->getFormatStandard('Spell Duration', 'seconds', ($value_min * 6), ($value_max * 6), $minlvl, $maxlvl);
                    break;
                case 288:
                    $castwhat = $max ? $this->renderSpellEffect($max) : 'Get Spell from AA Table';
                    $desc .= "Cast {$castwhat} on " . config('everquest.spell_effects.' . $limit) . ' use (' . ($base / 10) . '% Chance)';
                    break;
                case 289:
                    $desc .= 'Cast ';
                    $desc .= $this->renderSpellEffect($base);
                    $desc .= 'on Duration Fade';
                    break;
                case 290:
                    $desc .= $this->getFormatStandard('Movement Speed Cap', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 291:
                    $desc .= 'Remove up to (' . $base . ') detrimental effects';
                    break;
                case 292:
                    $desc .= $this->getFormatStandard('Chance of Strikethrough', '%', $value_min, $value_max, $minlvl, $maxlvl) . '(v292)';
                    break;
                case 293:
                    $desc .= $this->getFormatStandard('Chance to Resist Melee Stun', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 294:
                    $desc .= ($base) ? $this->getFormatStandard('Chance to Critical Nuke', '%', $value_min, $value_max, $minlvl, $maxlvl) : '';
                    $desc .= ($base) ? ' and ' : '';
                    $desc .= ($limit) ? $this->getFormatStandard('Critical Nuke Damage', '%', $limit, $limit, $minlvl, $maxlvl) . ' of Base Damage' : '';
                    break;
                case 295:
                    $desc .= 'Error: (' . $spell[$id] . ') not used';
                    break;
                case 296:
                    $desc .= $this->getFocusPercentRange('Spell Damage Taken', $base, $limit, false);
                    break;
                case 297:
                    $desc .= $this->getFormatStandard('Spell Damage Taken', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 298:
                    $desc .= $this->getFormatStandard('Pet Size', '%', -$value_min, -$value_max, $minlvl, $maxlvl);
                    break;
                case 299:
                    $desc .= 'Wake the Dead (' . $max . ')';
                    break;
                case 300:
                    $desc .= 'Summon Doppelganger: ' . $spell['teleport_zone'];
                    break;
                case 301:
                    $desc .= $this->getFormatStandard('Archery Damage', '%', -$value_min, -$value_max, $minlvl, $maxlvl);
                    break;
                case 302:
                    $desc .= $this->getFocusPercentRange('Spell Damage', $base, $limit, false) . '(v302 before crit)';
                    break;
                case 303:
                    $desc .= $this->getFormatStandard('Spell Damage', '', $value_min, $value_max, $minlvl, $maxlvl) . '(v303 before crit)';
                    break;
                case 304:
                    $desc .= $this->getFormatStandard('Chance to Avoid Offhand Riposte', '%', -$value_min, -$value_max, $minlvl, $maxlvl);
                    break;
                case 305:
                    $desc .= $this->getFormatStandard('Offhand Damage Shield Taken', '%', -$value_min, -$value_max, $minlvl, $maxlvl);
                    break;
                case 306:
                    $desc .= 'Wake the Dead: + ' . $spell['teleport_zone'] . ' x ' . $base . ' for ' . $max . 's';
                    break;
                case 307:
                    $desc .= 'Appraisal';
                    break;
                case 308:
                    $desc .= 'Suspend Minion to remain after Zoning';
                    break;
                case 309:
                    $desc .= 'Teleport to Caster\'s Bind';
                    break;
                case 310:
                    $desc .= 'Reduce Timer by ' . seconds_to_human($base / 1000);
                    break;
                case 311:
                    $desc .= 'Limit Type: ' . ($base === 1 ? 'Include' : 'Exclude') . ' Combat Skills';
                    break;
                case 312:
                    $desc .= 'Sanctuary: Place caster bottom hate list, fades if cast on other than self.';
                    break;
                case 313:
                    $desc .= $this->getFormatStandard('Chance to Double Forage', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 314:
                    $desc .= 'Invisibility' . ($base > 1 ? ' (Enhanced ' . $base . ')' : '');
                    break;
                case 315:
                    $desc .= 'Invisibility to Undead' . ($base > 1 ? ' (Enhanced ' . $base . ')' : '');
                    break;
                case 316:
                    $desc .= 'Invisibility to Animals' . ($base > 1 ? ' (Enhanced ' . $base . ')' : '');
                    break;
                case 317:
                    $desc .= $this->getFormatStandard('HP Regen Cap', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 318:
                    $desc .= $this->getFormatStandard('Mana Regen Cap', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 319:
                    $desc .= $this->getFormatStandard('Chance to Critical HoT', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 320:
                    $desc .= $this->getFormatStandard('Shield Block Chance', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 321:
                    $desc .= $this->getFormatStandard('Target\'s Target Hate', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 322:
                    $desc .= 'Gate to Home City';
                    break;
                case 323:
                    $desc .= 'Add Defensive Proc: ';
                    $desc .= $this->renderSpellEffect($spell['effect_base_value' . $n]);
                    $desc .= ($limit ? ' with ' . $limit . ' % Rate Mod' : '');
                    break;
                case 324:
                    $desc .= "Cast from HP with " . $base . "% Penalty";
                    break;
                case 325:
                    $desc .= $this->getFormatStandard("Chance to Remain Hidden When Hit By AE", '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 326:
                    $desc .= $this->getFormatStandard("Spell Memorization Gems", "", $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 327:
                    $desc .= $this->getFormatStandard("Buff Slots", "", $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 328:
                    $desc .= $this->getFormatStandard("Max Negative HP", "", $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 329:
                    $desc .= "Absorb Damage using Mana: " . $base . '%';
                    break;
                case 330:
                    $desc .= $this->getFormatStandard("Critical " . config('everquest.db_skills.' . $limit) . " Damage", '%', $value_min, $value_max, $minlvl, $maxlvl) . " of Base Damage";
                    break;
                case 331:
                    $desc .= $this->getFormatStandard("Chance to Salvage Components", '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 332:
                    $desc .= "Summon to Corpse";
                    break;
                case 333:
                    $desc .= 'Cast ';
                    $desc .= $this->renderSpellEffect($base);
                    $desc .= 'on Rune Fade';
                    break;
                case 334:
                    $desc .= $this->getFormatStandard("Current HP", '%', $value_min, $value_max, $minlvl, $maxlvl) . $pertick . $special_range . " (If Target Not Moving)";
                    break;
                case 335:
                    $desc .= "Block Next Spell" . ($base < 100 ? " (" . $base . "% Chance)" : "");
                    break;
                case 336:
                    $desc .= "Error: (" . $spell[$id] . ") not used";
                    break;
                case 337:
                    $desc .= $this->getFormatStandard("Experience Gain", '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 338:
                    $desc .= "Summon and Resurrect All Corpses";
                    break;
                case 339:
                    $desc .= 'Cast ';
                    $desc .= $this->renderSpellEffect($limit);
                    $desc .= 'on Spell Use (' . $base . '% Chance)';
                    break;
                case 340: //Only one effect casts if multiple 340s in spell
                    $desc .= 'Cast ';
                    $desc .= $this->renderSpellEffect($limit);
                    $desc .= ($base < 100 ? ' (' . $base . '% Chance)' : '');
                    break;
                case 341:
                    $desc .= $this->getFormatStandard('ATK Cap', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 342:
                    $desc .= 'Inhibit Low Health Fleeing';
                    break;
                case 343:
                    $desc .= 'Interrupt Casting' . ($base < 100 ? '(' . $base . '% Chance)' : '');
                    break;
                case 344:
                    $desc .= $this->getFormatStandard('Chance to Channel Item Click Effects', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 345:
                    $desc .= 'Limit Assassinate Level: ' . $base . ($limit ? '(' . $limit . '% Chance Bonus)' : '');
                    break;
                case 346:
                    $desc .= 'Limit Headshot Level: ' . $base . ($limit ? '(' . $limit . '% Chance Bonus)' : '');
                    break;
                case 347:
                    $desc .= $this->getFormatStandard('Chance of Double Archery Attack', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 348:
                    $desc .= 'Limit: Min Mana Cost: ' . $base;
                    break;
                case 349:
                    $desc .= $this->getFormatStandard('Damage When Shield Equipped', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 350:
                    $desc .= 'Manaburn: Consumes up to ' . $base . ' mana to deal ' . -$limit . '% of that mana as direct damage';
                    break;
                case 351:
                    $desc .= 'Aura Effect: ';
                    $aura = $this->getAura($spell->id);
                    if ($aura) {
                        $desc .= $this->renderSpellEffect($aura->spell->id, $aura->spell->name);
                    } else {
                        $desc .= 'Uknown Aura Effect';
                    }
                    break;
                case 352:
                    $desc .= 'Error: (' . $spell[$id] . ') not used';
                    break;
                case 353:
                    $desc .= $this->getFormatStandard('Aura Count', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 354:
                    $desc .= 'Error: (' . $spell[$id] . ') not used';
                    break;
                case 355:
                    $desc .= 'Error: (' . $spell[$id] . ') not used';
                    break;
                case 356:
                    $desc .= 'Error: (' . $spell[$id] . ') not used';
                    break;
                case 357:
                    $desc .= 'Inhibit Spell Casting (Focus Silence) (' . $base . '% Chance)';
                    break;
                case 358:
                    $desc .= $this->getFormatStandard('Current Mana', '', $value_min, $value_max, $minlvl, $maxlvl) . $special_range;
                    break;
                case 359:
                    $desc .= $this->getFormatStandard('Chance to Sense Trap', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 360:
                    $desc .= 'Add Killshot Proc: ';
                    $desc .= $this->renderSpellEffect($limit);
                    $desc .= '(' . $base . '% Chance)' . ($max ? ' Target Max Lv: ' . $max : '');
                    break;
                case 361:
                    $desc .= 'Cast ';
                    $desc .= $this->renderSpellEffect($limit);
                    $desc .= 'on Death (' . $base . '% Chance)';
                    break;
                case 362:
                    $desc .= $this->getFormatStandard('Potion Belt Slots', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 363:
                    $desc .= $this->getFormatStandard('Bandolier Slots', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 364:
                    $desc .= $this->getFormatStandard('Chance to Triple Attack', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 365:
                    $desc .= 'Cast ';
                    $desc .= $this->renderSpellEffect($limit);
                    $desc .= 'if spell Kills Target (' . $base . '% Chance)';
                    break;
                case 366:
                    $desc .= 'Error: (' . $spell[$id] . ') not used';
                    break;
                case 367:
                    $desc .= 'Transform Body Type to ' . config('everquest.db_bodytypes.' . $base);
                    break;
                case 368: //TODO: get faction name from dbase
                    $desc .= $this->getFormatStandard('Faction with [Faction ' . $base . ']', '', $limit, $limit, $minlvl, $maxlvl);
                    break;
                case 369:
                    $desc .= $this->getFormatStandard('Corruption Counter', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 370:
                    $desc .= $this->getFormatStandard('Corruption Resist', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 371:
                    $desc .= $this->getFormatStandard('Attack Speed', '', -$value_min, -$value_max, $minlvl, $maxlvl) . '(Stackable)';
                    break;
                case 372:
                    $desc .= $this->getFormatStandard('Forage Skill Cap', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 373:
                    $desc .= 'Cast ';
                    $desc .= $this->renderSpellEffect($base);
                    $desc .= 'on Duration Fade (v373)';
                    break;
                case 374:
                    $desc .= 'Cast ';
                    $desc .= $this->renderSpellEffect($limit);
                    $desc .= ($base < 100 ? ' (' . $base . '% Chance)' : '');
                    break;
                case 375:
                    $desc .= $this->getFormatStandard("Critical DoT Damage", '%', $value_min, $value_max, $minlvl, $maxlvl) . ' of Base Damage';
                    break;
                case 376:
                    $desc .= 'Fling';
                    break;
                case 377:
                    $desc .= 'Cast ';
                    $desc .= $this->renderSpellEffect($base);
                    $desc .= 'on Duration Fade (v377)';
                    break;
                case 378:
                    $desc .= $this->getFormatStandard('Chance to Resist ' . config('everquest.spell_effects.' . $limit) . ' Effects', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 379://client handles this function
                    if ($limit === 0) {
                        $desc .= 'Shadowstep Forward ' . $base;
                    }
                    if ($limit === 90) {
                        $desc .= 'Shadowstep Right ' . $base;
                    }
                    if ($limit === 180) {
                        $desc .= 'Shadowstep Back ' . $base;
                    }
                    if ($limit === 270) {
                        $desc .= 'Shadowstep Left ' . $base;
                    } else {
                        $desc .= 'Shadowstep ' . $base . ' to ' . $limit . ' Degrees';
                    }
                    break;
                case 380:
                    $desc .= 'Push Back ' . $limit . ' and Up ' . $base;
                    break;
                case 381:
                    $desc .= 'Fling to Self (Velocity: ' . $base . ')' . ($max ? 'Target must be ' . $max . ' or fewer lv higher than you' : '');
                    break;
                case 382:
                    $desc .= 'Inhibit Effect: ' . config('everquest.spell_effects.' . $limit) . ($base ? ' (From: ' . config('everquest.spell_negatetype.' . $base) . ' Effects)' : '');
                    break;
                case 383:
                    $desc .= 'Cast ';
                    $desc .= $this->renderSpellEffect($limit);
                    $desc .= 'on Spell Use' . ($base !== 100 ? ' (Proc rate mod: ' . ($base - 100) . '%)' : '');
                    break;
                case 384:
                    $desc .= 'Fling to Target (Velocity: ' . $base . ')';
                    break;
                case 385:
                    $spellGroup = $this->getSpellGroup(abs($base));
                    if ($spellGroup) {
                        $desc .= 'Limit Spell Group: ' . ($base >= 0 ? '' : 'Exclude ') . $this->renderSpellEffect($spellGroup->id, $spellGroup->name);
                    } else {
                        $desc .= 'Limit Spell Group: ' . ($base >= 0 ? '' : 'Exclude ') . 'Unknown Spell Group';
                    }
                    break;
                case 386:
                    $desc .= 'Cast ';
                    $desc .= $this->renderSpellEffect($base);
                    $desc .= 'on Curer';
                    break;
                case 387:
                    $desc .= 'Cast ';
                    $desc .= $this->renderSpellEffect($base);
                    $desc .= 'on Cured';
                    break;
                case 388:
                    $desc .= 'Summon All Corpses (From Any Zone)';
                    break;
                case 389:
                    $desc .= 'Reset Spell Recast Timers';
                    break;
                case 390:
                    $desc .= 'Set Spell Lockout Recast Timers to ' . seconds_to_human($base / 1000);
                    break;
                case 391:
                    $desc .= 'Limit Max Mana Cost: ' . $base;
                    break;
                case 392:
                    $desc .= $this->getFormatStandard('Healing Amount', '', $value_min, $value_max, $minlvl, $maxlvl) . '(v392)';
                    break;
                case 393:
                    $desc .= $this->getFocusPercentRange('Healing Received', $base, $limit, false) . '(v393)';
                    break;
                case 394:
                    $desc .= $this->getFormatStandard('Healing Amount Received', '', $value_min, $value_max, $minlvl, $maxlvl) . '(v394)';
                    break;
                case 395:
                    $desc .= $this->getFocusPercentRange('Healing Received', $base, $limit, false) . '(v395)';
                    break;
                case 396:
                    $desc .= $this->getFormatStandard("Healing Amount", "", $value_min, $value_max, $minlvl, $maxlvl) . "(v396 before crit)";
                    break;
                case 397:
                    $desc .= $this->getFormatStandard("Pet AC", "", $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 398:
                    $desc .= "Increase Temp Pet Duration by " . ($base / 1000) . " sec";
                    break;
                case 399:
                    $desc .= $this->getFormatStandard("Chance to Twincast", '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 400:
                    $desc .= "Increase Groups Current HP by up to " . abs(floor($base * $limit / 10)) . " (" . abs($limit / 10) . " HP per 1 Mana Drained)";
                    break;
                case 401:
                    $desc .= "Decrease Current HP by up to " . abs(floor($base * $limit / 10)) . " and Drain up to " . $base . " mana (" . abs($limit / 10) . " HP per 1 Target Mana Drained)";
                    break;
                case 402:
                    $desc .= "Decrease Current HP by up to " . abs(floor($base * $limit / 10)) . " and Drain up to " . $base . " endurance (" . abs($limit / 10) . " HP per 1 Target Endurance Drained)";
                    break;
                case 403: //Do not have defines for this, corresponds to spell table field data spell_class (field 221)
                    $desc .= "Limit Spell Class: " . ($base >= 0 ? "" : "Exclude ") . "(ID: " . abs($base) . ")";
                    break;
                case 404: //Do not have defines for this, corresponds to spell table field data spell_subclass (field 222)
                    $desc .= "Limit Spell Subclass: " . ($base >= 0 ? "" : "Exclude ") . "ID: " . abs($base) . ")";
                    break;
                case 405:
                    $desc .= $this->getFormatStandard("Staff Block Chance", '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 406:
                    $desc .= 'Cast ';
                    $desc .= $this->renderSpellEffect($base);
                    $desc .= 'if Max Hits Used';
                    break;
                case 407:
                    $desc .= 'Cast ';
                    $desc .= $this->renderSpellEffect($base);
                    $desc .= 'on Focus Limit Match';
                    break;
                case 408:
                    $desc .= 'Cap HP at ' . ($limit > 0 ? 'lowest of ' . $base . '% or ' . $limit : +$base . '%');
                    break;
                case 409:
                    $desc .= 'Cap Mana at ' . ($limit > 0 ? 'lowest of ' . $base . '% or ' . $limit : +$base . '%');
                    break;
                case 410:
                    $desc .= 'Cap Endurance at ' . ($limit > 0 ? 'lowest of ' . $base . '% or ' . $limit : +$base . '%');
                    break;
                case 411:
                    $desc .= 'Limit Class: ' . config('everquest.classes_short.' . ($base >> 1));
                    break;
                case 412:
                    $desc .= 'Limit Race:  ' . config('everquest.races.' . $base);
                    break;
                case 413:
                    $desc .= $this->getFormatStandard('Base Spell Effectiveness', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 414:
                    $desc .= 'Limit Casting Skill: ' . config('everquest.db_skills.' . $base);
                    break;
                case 415:
                    $desc .= 'Error: (' . $spell[$id] . ') not used';
                    break;
                case 416:
                    $desc .= $this->getFormatStandard('AC', '', $value_min, $value_max, $minlvl, $maxlvl) . '(v416)';
                    break;
                case 417:
                    $desc .= $this->getFormatStandard("Current Mana", "", $value_min, $value_max, $minlvl, $maxlvl) . $pertick . $special_range . "(v417)";
                    break;
                case 418:
                    $desc .= $this->getFormatStandard(config('everquest.db_skills.' . $limit) . " Damage bonus", "", $value_min, $value_max, $minlvl, $maxlvl) . $pertick . $special_range . "(v418)";
                    break;
                case 419:
                    $desc .= 'Add Melee Proc (v2) ';
                    $desc .= $this->renderSpellEffect($base);
                    $desc .= ($limit ? ' with ' . $limit . ' % Rate Mod' : '');
                    break;
                case 420:
                    $desc .= $this->getFormatStandard("Max Hits Count", '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 421:
                    $desc .= $this->getFormatStandard("Max Hits Count", "", $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 422:
                    $desc .= 'Limit Max Hits Min: ' . $base;
                    break;
                case 423:
                    $desc .= 'Limit Max Hits Type: ' . config('everquest.spell_numhitstype.' . $base);
                    break;
                case 424:
                    $desc .= 'Gradual ' . ($base > 0 ? 'Push' : 'Pull') . ' to ' . $limit . ' away (Force=' . abs($base) . ')' . $this->getUpToMaxLvl($max);
                    break;
                case 425: // not implemented on eqemu
                    $desc .= 'Fly';
                    break;
                case 426:
                    $desc .= $this->getFormatStandard('Extended Target Window Slots', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 427:
                    $desc .= 'Add Skill Proc: ';
                    $desc .= $this->renderSpellEffect($base);
                    $desc .= ($limit ? ' with ' . $limit . ' % Rate Mod' : '');
                    break;
                case 428:
                    $desc .= 'Limit Skill: ' . config('everquest.db_skills.' . $base);
                    break;
                case 429:
                    $desc .= 'Add Skill Proc on Successful Hit: ';
                    $desc .= $this->renderSpellEffect($base);
                    $desc .= ($limit ? ' with ' . $limit . ' % Rate Mod' : '');
                    break;
                case 430:
                    $desc .= 'Alter Vision: Base1=' . $base . ' Base2=' . $limit . ' Max=' . $max;
                    break;
                case 431:
                    if ($base < 0) {
                        $desc .= "Tint Vision: Red= " . ($base >> 16 & 0xff) . " Green=" . ($base >> 8 & 0xff) . " Blue=" . ($base & 0xff);
                    } else {
                        $desc .= "Alter Vision: Base1=" . $base . " Base2=" . $limit . " Max=" . $max;
                    }
                    break;
                case 432:
                    $desc .= $this->getFormatStandard("Trophy Slots", "", $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 433:
                    $desc .= $this->getFormatStandard("Crtical DoT Chance", '%', $value_min, $value_max, $minlvl, $maxlvl) . "Decay Rate of " . $limit . " over level " . $max;
                    break;
                case 434:
                    $desc .= $this->getFormatStandard("Crtical Heal Chance", '%', $value_min, $value_max, $minlvl, $maxlvl) . "Decay Rate of " . $limit . " over level " . $max;
                    break;
                case 435:
                    $desc .= $this->getFormatStandard("Crtical HoT Chance", '%', $value_min, $value_max, $minlvl, $maxlvl) . "Decay Rate of " . $limit . " over level " . $max;
                    break;
                case 436:
                    $desc .= "Toggle: Freeze Buffs";
                    break;
                case 437:
                    if ($base === 52584) {
                        $tmp .= "Primary Anchor";
                    }
                    if ($base === 52585) {
                        $tmp .= "Secondary Anchor";
                    }
                    if ($base === 50874) {
                        $tmp .= "Guild Anchor";
                    }
                    $desc .= "Teleport to your " . $tmp;
                    break;
                case 438:
                    if ($base === 52584) {
                        $tmp .= "Primary Anchor";
                    }
                    if ($base === 52585) {
                        $tmp .= "Secondary Anchor";
                    }
                    if ($base === 50874) {
                        $tmp .= "Guild Anchor";
                    }
                    $desc .= "Teleport to their " . $tmp;
                    break;
                case 439:
                    $desc .= "Add Assassinate Proc with up to " . $limit . "Damage" . ($base ? " Chance Mod:" . $base : "");
                    break;
                case 440:
                    $desc .= "Limit Finishing Blow Level to " . $base . " and lower NPC targets with" . ($limit / 10) . "% or less health.";
                    break;
                case 441:
                    $desc .= "Cancel if Moved " . $base . "'";
                    break;
                case 442:
                    $desc .= 'Cast ';
                    $desc .= $this->renderSpellEffect($base);
                    $desc .= 'once if ' .  config('everquest.spell_target_restrictions.' . $limit);
                    break;
                case 443:
                    $desc .= 'Cast ';
                    $desc .= $this->renderSpellEffect($base);
                    $desc .= 'once if Caster ' . config('everquest.spell_target_restrictions.' . $limit);
                    break;
                case 444:
                    $desc .= "Lock Aggro on Caster and " . $this->getFormatStandard("Other Aggro", '%', ($limit - 100), ($limit - 100), $minlvl, $maxlvl) . $this->getUpToMaxLvl($base);
                    break;
                case 445:
                    $desc .= 'Grant ' . $base . 'Mercenary Slots';
                    break;
                case 446:
                    $desc .= 'Buff Blocker A (' . $base . ')';
                    break;
                case 447:
                    $desc .= 'Buff Blocker B (' . $base . ')';
                    break;
                case 448:
                    $desc .= 'Buff Blocker C (' . $base . ')';
                    break;
                case 449:
                    $desc .= 'Buff Blocker D (' . $base . ')';
                    break;
                case 450:
                    $desc .= 'Absorb DoT Damage: ' . $base . '%' . ($limit > 0 ? 'Max Per Hit: ' . $limit : '') . ($max > 0 ? ' Total: ' . $max : '');
                    break;
                case 451:
                    $desc .= 'Absorb Melee Damage: ' . $base . '% over ' . $limit . ($max > 0 ? ' Total: ' . $max : '');
                    break;
                case 452:
                    $desc .= 'Absorb Spell Damage: ' . $base . '% over ' . $limit . ($max > 0 ? ' Total: ' . $max : '');
                    break;
                case 453:
                    $desc .= 'Cast ';
                    $desc .= $this->renderSpellEffect($base);
                    $desc .= 'if ' . $limit . ' Melee Damage Taken in Single Hit';
                    break;
                case 454:
                    $desc .= 'Cast ';
                    $desc .= $this->renderSpellEffect($base);
                    $desc .= 'if ' . $limit . ' Spell Damage Taken in Single Hit';
                    break;
                case 455:
                    $desc .= $this->getFormatStandard('Current Hate', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 456:
                    $desc .= $this->getFormatStandard('Current Hate', '%', $value_min, $value_max, $minlvl, $maxlvl) . $pertick;
                    break;
                case 457:
                    if ($limit === 0) {
                        $tmp .= 'HP';
                    }
                    if ($limit === 1) {
                        $tmp .= 'Mana';
                    }
                    if ($limit === 2) {
                        $tmp .= 'Endurance';
                    }
                    $desc .= 'Return ' . ($base / 10) . '% of Spell Damage as' . $tmp . ($max > 0 ? ', Max Per Hit: ' . $max . ')' : '');
                    break;
                case 458:
                    $desc .= $this->getFormatStandard('Faction Hit', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 459:
                    $desc .= $this->getFormatStandard(config('everquest.db_skills.' . $limit) . ' Damage', '%', $value_min, $value_max, $minlvl, $maxlvl) . ' (v459)';
                    break;
                case 460:
                    $desc .= 'Limit Type: Include Non-Focusable';
                    break;
                case 461:
                    $desc .= $this->getFocusPercentRange("Spell Damage", $base, $limit, false) . ' (v461)';
                    break;
                case 462:
                    $desc .= $this->getFormatStandard("Spell Damage Amount", "", $value_min, $value_max, $minlvl, $maxlvl) . ' (v462)';
                    break;
                case 463:
                    $desc .= 'Melee Shielding: ' . $base . '%';
                    break;
                case 464:
                    $desc .= $this->getFormatStandard('Pet Chance to Rampage', '%', $value_min, $value_max, $minlvl, $maxlvl) . ($limit ? ' with ' . $limit . '% of Damage' : '');
                    break;
                case 465:
                    $desc .= $this->getFormatStandard('Pet Chance to AE Rampage', '%', $value_min, $value_max, $minlvl, $maxlvl) . ($limit ? ' with ' . $limit . '% of Damage' : '');
                    break;
                case 466:
                    $desc .= $this->getFormatStandard('Pet Chance to Flurry on Double Attack', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 467:
                    $desc .= $this->getFormatStandard('Damage Shield Taken', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 468:
                    $desc .= $this->getFormatStandard('Damage Shield Taken', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 469:
                    $spellGroup = $this->getSpellGroup(abs($base));
                    if ($spellGroup) {
                        $desc .= 'Cast Highest Rank of [Group ' . $this->renderSpellEffect($spellGroup->id, $spellGroup->name) . ']';
                    } else {
                        $desc .= 'Cast Highest Rank of [Group ' . 'Unknown Spell Group]';
                    }
                    $desc .= ($base < 100 ? ' (' . $base . '% Chance) ' : '');
                    break;
                case 470:
                    $desc .= 'Cast Highest Rank of [Group ' . $limit . ']' . ($base < 100 ? ' (' . $base . '% Chance)' : '');
                    break;
                case 471:
                    $desc .= $this->getFormatStandard('Chance to Repeat Primary Hand Round', '%', $value_min, $value_max, $minlvl, $maxlvl) . ($limit ? ' with ' . $limit . ' % Damage Bonus' : '');
                    break;
                case 472:
                    $desc .= 'Buy AA Rank (' . $base . ')';
                    break;
                case 473:
                    $desc .= $this->getFormatStandard('Chance to Double Backstab From Front', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 474:
                    $desc .= $this->getFormatStandard('Pet Critical Hit Damage', '%', $value_min, $value_max, $minlvl, $maxlvl) . ' of Base Damage';
                    break;
                case 475:
                    $desc .= 'Cast ';
                    $desc .= $this->renderSpellEffect($limit);
                    $desc .= 'if Not Cast By Item Click' . ($base ? ' (Chance ' . $base . '%)' : '');
                    break;
                case 476:
                    if ($base === 0) {
                        $tmp .= '2H Weapons';
                    }
                    if ($base === 1) {
                        $tmp .= 'Shields';
                    }
                    if ($base === 2) {
                        $tmp .= 'Dual Wield';
                    }
                    $desc .= 'Weapon Stance: Apply spell ';
                    $desc .= $this->renderSpellEffect($limit);
                    $desc .= 'when using ' . $tmp;
                    break;
                case 477:
                    $desc .= 'Move to Top of Rampage List (' . $base . '% Chance)';
                    break;
                case 478:
                    $desc .= 'Move to Bottom of Rampage List (' . $base . '% Chance)';
                    break;
                case 479:
                    $desc .= 'Limit Effect: ' . config('everquest.spell_effects.' . $limit) . ' greater than ' . $base;
                    break;
                case 480:
                    $desc .= 'Limit Effect: ' . config('everquest.spell_effects.' . $limit) . ' less than ' . $base;
                    break;
                case 481:
                    $desc .= 'Cast ';
                    $desc .= $this->renderSpellEffect($limit);
                    $desc .= 'if Hit By Spell' . ($base < 100 ? '(' . $base . '% Chance)' : '');
                    break;
                case 482:
                    $desc .= $this->getFormatStandard('Base ' . config('everquest.db_skills.' . $limit) . ' Damage', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 483:
                    $desc .= $this->getFocusPercentRange('Spell Damage Taken', $base, $limit, false) . '(v483)';
                    break;
                case 484:
                    $desc .= $this->getFormatStandard('Spell Damage Taken Amount', '', $value_min, $value_max, $minlvl, $maxlvl) . '(v484)';
                    break;
                case 485:
                    $desc .= 'Limit Caster Class: ' . config('everquest.classes_short.' . ($base >> 1)) . '(Outgoing Focus Limit)';
                    break;
                case 486:
                    $desc .= 'Limit Caster: ' . ($base === 0 ? 'Exclude ' : '') . 'Self';
                    break;
                case 487:
                    $desc .= $this->getFormatStandard(config('everquyest.db_skills.' . $limit) . ' Skill Cap with Recipes', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 488:
                    $desc .= $this->getFormatStandard('Push Taken', '%', -$value_min, -$value_max, $minlvl, $maxlvl);
                    break;
                case 489:
                    $desc .= $this->getFormatStandard('Endurance Regen Cap', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 490:
                    $desc .= 'Limit Min Recast: ' . ($base / 1000) . 's';
                    break;
                case 491:
                    $desc .= 'Limit Max Recast: ' . ($base / 1000) . 's';
                    break;
                case 492:
                    $desc .= 'Limit Min Endurance Cost: ' . $base;
                    break;
                case 493:
                    $desc .= 'Limit Max Endurance Cost: ' . $base;
                    break;
                case 494:
                    $desc .= $this->getFormatStandard('Pet ATK', '', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 495:
                    $desc .= 'Limit Max Duration: ' . ($base * 6);
                    break;
                case 496:
                    $desc .= $this->getFormatStandard('Critical ' . config('everquest.db_skills.' . $limit) . ' Damage', '%', $value_min, $value_max, $minlvl, $maxlvl) . ' of Base Damage (Non Stacking)';
                    break;
                case 497:
                    $desc .= 'Limit: No Procs or Twincast';
                    break;
                case 498:
                    $desc .= $this->getFormatStandard('Chance of ' . $limit . ' Additional 1H Attacks', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 499:
                    $desc .= $this->getFormatStandard('Chance of ' . $limit . ' Secondary 1H Attack', '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 500:
                    $desc .= $this->getFocusPercentRange('Spell Haste', $base, $limit, false) . '(v500 no max reduction limit)';
                    break;
                case 501:
                    $desc .= ($base < 0 ? 'Increase' : 'Decrease') . ' Casting Times by ' . abs($base / 1000) . 's';
                    break;
                case 502:
                    if ($base !== $limit && $limit !== 0) {
                        $tmp .= ' ( ' . ($limit / 1000) . ' in PvP)';
                    }
                    $desc .= 'Stun and Fear ' . ($base / 1000) . ' sec' . $tmp . ($max >= 1000 ? 'up to level ' . ($max - 1000) : 'up to level + ' . $max);
                    break;
                case 503:
                    $desc .= $this->getFormatStandard(($limit === 0 ? 'Rear' : 'Frontal') . ' Arc Melee Damage', '%', $value_min / 10, $value_max / 10, $minlvl, $maxlvl);
                    break;
                case 503:
                    $desc .= $this->getFormatStandard(($limit === 0 ? 'Rear' : 'Frontal') . ' Arc Melee Damage Amount', '', $value_min / 10, $value_max / 10, $minlvl, $maxlvl);
                    break;
                case 505:
                    $desc .= $this->getFormatStandard(($limit === 0 ? 'Rear' : 'Frontal') . ' Arc Melee Damage Taken', '%', $value_min / 10, $value_max / 10, $minlvl, $maxlvl);
                    break;
                case 506:
                    $desc .= $this->getFormatStandard(($limit === 0 ? 'Rear' : 'Frontal') . ' Arc Melee Damage Taken Amount', '', $value_min / 10, $value_max / 10, $minlvl, $maxlvl);
                    break;
                case 507:
                    $desc .= $this->getFocusPercentRange('Spell Power', $base, $limit, false) . ' (Focus Spell DOT, DD and Healing)';
                    break;
                case 509:
                    $desc .= ($limit < 0 ? 'Decrease' : 'Increase') . ' Current HP by ' . (abs($limit) / 10) . '% of Caster Current HP ( ' . (abs($base) / 10) . '% Life Burn)';
                    break;
                case 510:
                    $desc .= $this->getFormatStandard("Incoming Resist Modifier", '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 511:
                    $desc .= "Limit: Focus Reuse Timer: " . ($limit / 1000) . "s";
                    break;
                case 512:
                    $desc .= "Proc Reuse Timer: " . ($limit / 1000) . "s";
                    break;
                case 513:
                    $desc .= $this->getFormatStandard("Max Mana", '%', $value_min / 100, $value_max / 100, $minlvl, $maxlvl);
                    break;
                case 514:
                    $desc .= $this->getFormatStandard("Max Endurance", '%', $value_min / 100, $value_max / 100, $minlvl, $maxlvl);
                    break;
                case 515:
                    $desc .= $this->getFormatStandard("Avoidance AC", '%', $value_min / 1000, $value_max / 1000, $minlvl, $maxlvl);
                    break;
                case 516:
                    $desc .= $this->getFormatStandard("Mitigation AC", '%', $value_min / 1000, $value_max / 1000, $minlvl, $maxlvl);
                    break;
                case 517:
                    $desc .= $this->getFormatStandard("ATK Offense", '%', $value_min / 1000, $value_max / 1000, $minlvl, $maxlvl);
                    break;
                case 518:
                    $desc .= $this->getFormatStandard("ATK Accuracy", '%', $value_min / 1000, $value_max / 1000, $minlvl, $maxlvl);
                    break;
                case 519:
                    $desc .= $this->getFormatStandard("Luck", "", $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 520:
                    $desc .= $this->getFormatStandard("Luck", '%', $value_min, $value_max, $minlvl, $maxlvl);
                    break;
                case 521:
                    $desc .= "Absorb Damage using Endurance: " . ($base / 100) . ($limit !== 10000 ? ($limit / 10000) . " End per 1 HP)" : "") . ($max > 0 ? ", Max Per Hit: " . $max : "");
                    break;
                case 522:
                    $desc .= $this->getFormatStandard("Current Mana", '%', $value_min / 100, $value_max / 100, $minlvl, $maxlvl) . " up to " . $max;
                    break;
                case 523:
                    $desc .= $this->getFormatStandard("Current Endurance", '%', $value_min / 100, $value_max / 100, $minlvl, $maxlvl) . " up to " . $max;
                    break;
                case 524:
                    $desc .= $this->getFormatStandard("Current HP", '%', $value_min, $value_max, $minlvl, $maxlvl) . " up to " . $max . $pertick;
                    break;
                case 525:
                    $desc .= $this->getFormatStandard("Current Mana", '%', $value_min, $value_max, $minlvl, $maxlvl) . " up to " . $max . $pertick;
                    break;
                case 526:
                    $desc .= $this->getFormatStandard("Current Endurance", '%', $value_min, $value_max, $minlvl, $maxlvl) . " up to " . $max . $pertick;
                    break;
            }

            if ($desc !== '') {
                $effectsInfo = $n . ') '. $desc;
                //effectsInfo = util.format("%s) %s", effectIndex, printBuffer)
            }
        }

        return $effectsInfo;
    }

    // spell_effects.cpp int Mob::CalcSpellEffectValue_formula(int formula, int base, int max, int caster_level, int16 spell_id)
    // https://github.com/Akkadius/spire/blob/07f745962011a257227b3108590460f9d042cdb6/frontend/src/app/spells.ts#L2535
    protected function calcSpellEffectValue($form, $base, $max, $tick, $lvl)
    {
        if ($form === 0) {
            return $base;
        }

        if ($form === 100) {
            if ($max > 0 && $base > $max) {
                return $max;
            }

            return $base;
        }

        $change = 0;

        switch ($form) {
            case 60:
            case 70:
                $change = $base / 100;
                break;
            case 101:
                $change = $lvl / 2;
                break;
            case 102:
                $change = $lvl;
                break;
            case 103:
                $change = $lvl * 2;
                break;
            case 104:
                $change = $lvl * 3;
                break;
            case 105:
                $change = $lvl * 4;
                break;
            case 107:
                $change = -1 * $tick;
                break;
            case 108:
                $change = -2 * $tick;
                break;
            case 109:
                $change = $lvl / 4;
                break;
            case 110:
                $change = $lvl / 6;
                break;
            case 111:
                if ($lvl > 16) $change = ($lvl - 16) * 6;
                break;
            case 112:
                if ($lvl > 24) $change = ($lvl - 24) * 8;
                break;
            case 113:
                if ($lvl > 34) $change = ($lvl - 34) * 10;
                break;
            case 114:
                if ($lvl > 44) $change = ($lvl - 44) * 15;
                break;
            case 115:
                if ($lvl > 15) $change = ($lvl - 15) * 7;
                break;
            case 116:
                if ($lvl > 24) $change = ($lvl - 24) * 10;
                break;
            case 117:
                if ($lvl > 34) $change = ($lvl - 34) * 13;
                break;
            case 118:
                if ($lvl > 44) $change = ($lvl - 44) * 20;
                break;
            case 119:
                $change = $lvl / 8;
                break;
            case 120:
                $change = -5 * $tick;
                break;
            case 121:
                $change = $lvl / 3;
                break;
            case 122:
                $change = -12 * $tick;
                break;
            case 123:
                $change = (abs($max) - abs($base)) / 2;
                break;
            case 124:
                if ($lvl > 50) $change = ($lvl - 50);
                break;
            case 125:
                if ($lvl > 50) $change = ($lvl - 50) * 2;
                break;
            case 126:
                if ($lvl > 50) $change = ($lvl - 50) * 3;
                break;
            case 127:
                if ($lvl > 50) $change = ($lvl - 50) * 4;
                break;
            case 128:
                if ($lvl > 50) $change = ($lvl - 50) * 5;
                break;
            case 129:
                if ($lvl > 50) $change = ($lvl - 50) * 10;
                break;
            case 130:
                if ($lvl > 50) $change = ($lvl - 50) * 15;
                break;
            case 131:
                if ($lvl > 50) $change = ($lvl - 50) * 20;
                break;
            case 132:
                if ($lvl > 50) $change = ($lvl - 50) * 25;
                break;
            case 139:
                if ($lvl > 30) $change = ($lvl - 30) / 2;
                break;
            case 140:
                if ($lvl > 30) $change = ($lvl - 30);
                break;
            case 141:
                if ($lvl > 30) $change = 3 * ($lvl - 30) / 2;
                break;
            case 142:
                if ($lvl > 30) $change = 2 * ($lvl - 60);
                break;
            case 143:
                $change = 3 * $lvl / 4;
                break;
            case 144:
                $change = (($lvl * 10) + (($lvl - 40) * 20));
                break;
            case 3000:
                return $base;
            default:
                if ($form > 0 && $form < 1000) {
                    $change = $lvl * $form;
                }

                if ($form >= 1000 && $form < 2000) {
                    $change = $tick * ($form - 1000) * -1;
                }

                if ($form >= 2000 && $form < 3000) {
                    $change = $lvl * ($form - 2000);
                }

                if ($form >= 4000 && $form < 5000) {
                    $change = -$tick * ($form - 4000);
                }
                break;
        }

        $value = abs($base) + $change;
        if ($max !== 0 && $value > abs($max)) {
            $value = abs($max);
        }

        if ($base < 0) {
            $value = -$value;
        }

        return (int) $value;
    }

    // https://github.com/Akkadius/spire/blob/07f745962011a257227b3108590460f9d042cdb6/frontend/src/app/spells.ts
    protected function calcValueRange($calc, $base, $max, $spa, $duration, $level)
    {
        $desc = '';
        $start = $this->calcSpellEffectValue($calc, $base, $max, 1, $level);
        $finish = abs($this->calcSpellEffectValue($calc, $base, $max, $duration, $level));
        $type = abs($start) < abs($finish) ? "Growing" : "Decaying";

        if ($calc === 123) {
            if ($base < 0) {
                $max = $max * -1;
            }
            $desc = ' (Random: ' . abs($base) . ' to ' . abs($max) . ')';
        }

        if ($calc === 107) {
            $desc = ' (' . $type . ' to ' . $finish . ' @ 1/tick)';
        }

        if ($calc === 108) {
            $desc = ' (' . $type . ' to ' . $finish . ' @ 2/tick)';
        }

        if ($calc === 120) {
            $desc = ' (' . $type . ' to ' . $finish . ' @ 5/tick)';
        }

        if ($calc === 122) {
            $desc = ' (' . $type . ' to ' . $finish . ' @ 12/tick)';
        }

        if ($calc > 1000 && $calc < 2000) {
            $desc = ' (' . $type . ' to ' . $finish . ' @ ' . ($calc - 1000) . '/tick)';
        }

        if ($calc >= 3000 && $calc < 4000) {
            if ($calc - 3000 === $spa) {
                $desc = ' (Scales, Base Level: 100)';
            }
            if ($calc - 3500 === $spa) {
                $desc = ' (Scales, Base Level: 105)';
            }
        }

        if ($calc > 4000 && $calc < 5000) {
            $desc = ' (' . $type . ' to ' . $finish . ' @ ' . ($calc - 4000) . '/tick)';
        }

        return $desc;
    }

    // https://github.com/Akkadius/spire/blob/07f745962011a257227b3108590460f9d042cdb6/frontend/src/app/spells.ts
    protected function getSpellMaxOutLevel($calc, $base, $max, $minLevel)
    {
        $MaxServerLevel = 100; //Better way to define this.
        $value = 0;

        for ($i = $minLevel; $i <= 100; $i++) {
            $value = $this->calcSpellEffectValue($calc, $base, $max, 1, $i);

            if (abs($value) >= $max) {
                return $i;
            }
        }

        return $MaxServerLevel;
    }

    // https://github.com/Akkadius/spire/blob/07f745962011a257227b3108590460f9d042cdb6/frontend/src/app/spells.ts
    protected function getFormatStandard($effect_name, $type, $value_min, $value_max, $minlvl, $maxlvl)
    {
        $modifier = '';
        if ($value_max < 0) {
            $modifier = 'Decrease ';
        } else {
            $modifier = 'Increase ';
        }

        $desc = $modifier . $effect_name;
        if ($value_min !== $value_max) {
            $desc .= ' by ' . (abs($value_min)) . $type . ' (L' . $minlvl . ') to ' . (abs($value_max)) . $type . ' (L' . $maxlvl . ')';
        } else {
            $desc .= ' by ' . (abs($value_max)) . $type;
        }

        return $desc;
    }

    protected function getUpToMaxLvl($max)
    {
        $desc = '';
        if ($max > 0) {
            $desc = " up to level " . $max;
        }

        return $desc;
    }

    protected function getFocusPercentRange($effect_name, $min, $max, $negate)
    {
        $desc = '';
        $modifier = '';

        if ($min < 0) {
            if ($min < $max) {
                $temp = $min;
                $min = $max;
                $max = $temp;
            }
        } else {
            if ($min > $max) {
                $max = $min;
            }
        }

        if ($negate) {
            $min = -$min;
            $max = -$max;
        }

        if ($max < 0) {
            $modifier = "Decrease ";
        } else {
            $modifier = "Increase ";
        }

        if ($min === $max || $max === 0) {
            $desc .= $modifier . $effect_name . " by " . abs($min) . '%';
            return $desc;
        }

        $desc .= $modifier . $effect_name . " by " . abs($min) . "% to " . abs($max) . '%';

        return $desc;
    }

    protected function renderSpellEffect($spellId, $spellName = null, $class = 'inline-flex')
    {
        $name = $spellName ?? ($this->allSpells[$spellId] ?? 'Unknown');
        return view('components.spell-link', [
            'spellId' => $spellId,
            'spellName' => $name,
            'spellIcon' => null,
            'spellClass' => $class,
            'effectsOnly' => 1,
        ])->render();
    }

    protected function renderPetDetails($petId, $petName = null, $class = 'inline-flex')
    {
        return view('components.pet-link', [
            'petId' => $petId,
            'petName' => $petName ?? 'Unknown',
            'petClass' => $class,
            'effectsOnly' => 1,
        ])->render();
    }
}
