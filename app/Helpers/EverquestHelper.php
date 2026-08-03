<?php
use Illuminate\Support\Facades\Config;

if (!function_exists('eq_race')) {
    function eq_race($id)
    {
        $races = config('everquest.races');
        return $races[$id] ?? 'Unknown';
    }
}

if (!function_exists('eq_class')) {
    function eq_class($id)
    {
        $classes = config('everquest.classes');
        return $classes[$id] ?? 'Unknown';
    }
}

if (!function_exists('eq_language')) {
    function eq_language($id)
    {
        $lang = config('everquest.languages');
        return $lang[$id] ?? 'Unknown';
    }
}

if (!function_exists('eq_deity')) {
    function eq_deity($id)
    {
        $deity = config('everquest.deity');
        return $deity[$id] ?? 'Unknown';
    }
}

if (!function_exists('item_bagtypes')) {
    function item_bagtypes($id) {
        $bagtypes = config('everquest.bagtypes');
        return $bagtypes[$id] ?? 'Unknown';
    }
}

if (!function_exists('eq_skills')) {
    function eq_skills() {
        return config('everquest.skills');
    }
}

if (!function_exists('eq_aa_types')) {
    function eq_aa_types() {
        return config('everquest.aa_types');
    }
}

if (!function_exists('format_loc')) {
    function format_loc($x, $y, $z, bool $labeled = false): string
    {
        $coords = config('everquest.coords_as_yxz')
            ? ['y' => $y, 'x' => $x, 'z' => $z]
            : ['x' => $x, 'y' => $y, 'z' => $z];

        $parts = [];
        foreach ($coords as $label => $value) {
            $parts[] = $labeled ? "{$label}={$value}" : $value;
        }

        return implode(', ', $parts);
    }
}

if (!function_exists('loc_order')) {
    function loc_order(): string
    {
        return config('everquest.coords_as_yxz') ? 'y,x,z' : 'x,y,z';
    }
}

if (!function_exists('seconds_to_human')) {
    function seconds_to_human($seconds): string
    {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $toseconds = $seconds % 60;

        $parts = [];
        if ($days > 0) {
            $parts[] = "{$days}d";
        }
        if ($hours > 0 || $days > 0) {
            $parts[] = "{$hours}h";
        }
        if ($minutes > 0 || $hours > 0 || $days > 0) {
            $parts[] = "{$minutes}m";
        }
        if (empty($parts) || $toseconds > 0) {
            $parts[] = "{$toseconds}s";
        }

        return implode(' ', $parts);
    }
}

if (!function_exists('sign')) {
    function sign($val) {
        return ($val > 0 ? '+' : '') . $val;
    }
}

if (!function_exists('get_class_usable_string')) {
    function get_class_usable_string($val): string {
        $classes = (array) config('everquest.classes_short');
        $result = [];

        // Special case for "ALL"
        if (isset($classes[65535]) && $val == 65535) {
            return $classes[65535];
        }

        foreach ($classes as $key => $short) {
            if (($val & $key) === $key && $key != 65535) {
                $result[] = $short;
            }
        }

        return implode(' ', $result);
    }
}

if (!function_exists('get_race_usable_string')) {
    function get_race_usable_string($val): string {
        $races = (array) config('everquest.races_short');
        $result = [];

        // Special case for "ALL"
        if (isset($races[65535]) && $val == 65535) {
            return $races[65535];
        }

        foreach ($races as $key => $short) {
            if (($val & $key) === $key && $key != 65535) {
                $result[] = $short;
            }
        }

        return implode(' ', $result);
    }
}

if (!function_exists('get_deity_usable_string')) {
    function get_deity_usable_string($val): string {
        $deities = (array) config('everquest.deities_short');
        $result = [];

        foreach ($deities as $bit => $name) {
            if (($val & $bit) === $bit) {
                $result[] = $name;
            }
        }

        return implode(', ', $result);
    }
}

if (!function_exists('get_slots_string')) {
    function get_slots_string($val): string {
        $slots = (array) config('everquest.slots');
        $result = [];

        foreach ($slots as $bit => $name) {
            if (($val & $bit) === $bit) {
                $result[] = $name;
            }
        }

        return implode(', ', $result);
    }
}

if (!function_exists('item_aug_data')) {
    function item_aug_data($item) {
        $augdb = (array) config('everquest.db_aug_restrict');
        $html = '';

        if (($item->itemtype ?? 0) == 54) {
            $html .= '<div class="mt-6 text-sm">';

            if (($item->augtype ?? 0) > 0) {
                $augType = $item->augtype;
                $augSlots = [];

                for ($i = 1, $bit = 1; $i <= 24; $i++, $bit *= 2) {
                    if ($bit <= $augType && ($augType & $bit)) {
                        $augSlots[] = $i;
                    }
                }

                $slotsText = implode(', ', $augSlots);
                $html .= "<p><strong>Aug Slot Type:</strong> {$slotsText}</p>";
            } else {
                $html .= "<p><strong>Aug Slot Type:</strong> All Slots</p>";
            }

            // Handle Augmentation Restriction
            $augRestrict = $item->augrestrict ?? 0;

            if ($augRestrict > 0) {
                if ($augRestrict > 12 || !isset($augdb[$augRestrict])) {
                    $html .= "<p><strong>Aug Restriction:</strong> Unknown Type</p>";
                } else {
                    $restriction = $augdb[$augRestrict];
                    $html .= "<p><strong>Aug Restriction:</strong> {$restriction}</p>";
                }
            }
            $html .= '</div>';
        }

        return $html;
    }
}

if (!function_exists('calculate_item_price')) {
    function calculate_item_price($price) {
        $platinum = intdiv($price, 1000);
        $price -= $platinum * 1000;

        $gold = intdiv($price, 100);
        $price -= $gold * 100;

        $silver = intdiv($price, 10);
        $price -= $silver * 10;

        $copper = $price;

        return [
            'platinum' => $platinum,
            'gold'     => $gold,
            'silver'   => $silver,
            'copper'   => $copper,
        ];
    }
}

if (!function_exists('consumable_strength_band')) {
    /**
     * The strength band a consumable falls into, or null when the item is not
     * food/drink/alcohol or carries no strength at all. Everything else reads
     * casttime_ as a cast time, so the itemtype check is not optional.
     */
    function consumable_strength_band(?int $strength, ?int $type): ?array {
        if (!in_array((int) $type, (array) config('everquest.consumable_types'), true)) {
            return null;
        }

        if (($strength ?? 0) <= 0) {
            return null;
        }

        foreach ((array) config('everquest.consumable_strengths') as $band) {
            if ($strength >= $band['min'] && ($band['max'] === null || $strength <= $band['max'])) {
                return $band;
            }
        }

        return null;
    }
}

if (!function_exists('get_food_drink_desc')) {
    /**
     * The in game blurb -- "This is a feast!" and friends. Alcohol has no such
     * wording, so it gets its potency shown instead.
     */
    function get_food_drink_desc(int $key, int $type) {
        $band = consumable_strength_band($key, $type);

        if (!$band || $type == 38) {
            return null;
        }

        return $band[$type == 14 ? 'food' : 'drink']['desc'];
    }
}

if (!function_exists('consumable_strength_label')) {
    /**
     * Short form for the search results: "Feast (45)" for food and drink,
     * "Potency 90" for alcohol.
     */
    function consumable_strength_label(?int $strength, ?int $type): ?string {
        $band = consumable_strength_band($strength, $type);

        if (!$band) {
            return null;
        }

        if ($type == 38) {
            return "Potency {$strength}";
        }

        return $band[$type == 14 ? 'food' : 'drink']['label'] . " ({$strength})";
    }
}

if (!function_exists('click_usage')) {
    /**
     * Where an item has to be for its click to fire -- 'inventory', 'equipped',
     * or null where there is no click to place.
     *
     * The effect bounds are the ones the item page already uses to decide
     * whether to draw the effect at all (peq stores -1 for "none" and a stray
     * row or two points past the end of the spell table), so the column and the
     * item agree about which items have a click in the first place.
     */
    function click_usage(?int $clickeffect, ?int $clicktype): ?string {
        if ($clickeffect === null || $clickeffect <= 0 || $clickeffect >= 65535) {
            return null;
        }

        return config('everquest.click_types.' . (int) $clicktype);
    }
}

if (!function_exists('price')) {
    function price(int $price): string
    {
        if ($price <= 0) {
            return '0 cp';
        }

        $p = intdiv($price, 1000);
        $price %= 1000;

        $g = intdiv($price, 100);
        $price %= 100;

        $s = intdiv($price, 10);
        $c = $price % 10;

        $parts = [];

        if ($p > 0) {
            $parts[] = "{$p} pp";
        }
        if ($g > 0) {
            $parts[] = "{$g} gp";
        }
        if ($s > 0) {
            $parts[] = "{$s} sp";
        }
        if ($c > 0 || empty($parts)) {
            $parts[] = "{$c} cp";
        }

        return implode(' ', $parts);
    }
}

if (!function_exists('ucRomanNumeral')) {
    function ucRomanNumeral(string $string): string
    {
        return preg_replace_callback('/\b(?=[mdclxvi])([mdclxvi]+)\b/i', function ($matches) {
            $possibleRoman = strtoupper($matches[1]);

            $valid = '/^(M{0,4})(CM|CD|D?C{0,3})(XC|XL|L?X{0,3})(IX|IV|V?I{0,3})$/';

            if (preg_match($valid, $possibleRoman)) {
                return $possibleRoman;
            }

            return $matches[0];
        }, $string);
    }
}

// https://github.com/Akkadius/spire/blob/07f745962011a257227b3108590460f9d042cdb6/frontend/src/app/spells.ts#L2875
if (!function_exists('getBuffDuration')) {
    function getBuffDuration($spell)
    {
        $i = 0;
        $minLevel = getMinLevel($spell);
        $buffDuration = (int) $spell['buffduration'];
        $buffDurationFormula = (int) $spell->buffdurationformula;

        switch ($buffDurationFormula) {
            case 0:
                return 0;
            case 1:
                $i = ceil($minLevel / 2);
                return ($i < $buffDuration ? ($i < 1 ? 1 : $i) : $buffDuration);
            case 2:
                $i = ceil($buffDuration / 5 * 3);
                return ($i < $buffDuration ? ($i < 1 ? 1 : $i) : $buffDuration);
            case 3:
                $i = $minLevel * 30;
                return ($i < $buffDuration ? ($i < 1 ? 1 : $i) : $buffDuration);
            case 4:
                return $buffDuration;
            case 5:
                $i = $buffDuration;
                return ($i < 3 ? ($i < 1 ? 1 : $i) : 3);
            case 6:
                $i = ceil($minLevel / 2);
                return ($i < $buffDuration ? ($i < 1 ? 1 : $i) : $buffDuration);
            case 7:
                $i = $minLevel;
                return ($i < $buffDuration ? ($i < 1 ? 1 : $i) : $buffDuration);
            case 8:
                $i = $minLevel + 10;
                return ($i < $buffDuration ? ($i < 1 ? 1 : $i) : $buffDuration);
            case 9:
                $i = $minLevel * 2 + 10;
                return ($i < $buffDuration ? ($i < 1 ? 1 : $i) : $buffDuration);
            case 10:
                $i = $minLevel * 3 + 10;
                return ($i < $buffDuration ? ($i < 1 ? 1 : $i) : $buffDuration);
            case 11:
            case 12:
                return $buffDuration;
            case 50:
                return 72000;
            case 3600:
                return ($buffDuration ? $buffDuration : 3600);
            default:
                //return "???";
                return 0;
        }
    }
}

if (!function_exists('getMinLevel')) {
    function getMinLevel($spell)
    {
        $minLevel = 255;
        for ($i = 1; $i <= 16; $i++) {
            $classIndex = "classes" . $i;
            if (($spell[$classIndex] > 0) && ($spell[$classIndex] < 255)) {
                if ($spell[$classIndex] < $minLevel) {
                    $minLevel = $spell[$classIndex];
                }
            }
        }

        return intval($minLevel);
    }
}

if (!function_exists('isUndiscoveredItem')) {
    function isUndiscoveredItem($itemId, $discoveredItems)
    {
        if (!config('everquest.discovered_items.enable')) {
            return false;
        }

        return !$discoveredItems->has($itemId);
    }
}
