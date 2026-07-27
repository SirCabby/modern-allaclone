<?php

namespace App\Http\Controllers;

use App\Support\ContentFilter;
use Illuminate\Http\Request;

class EraController extends Controller
{
    /**
     * Retarget the era this browser session is viewing.
     *
     * The default ('auto') tracks the server's own Expansion:CurrentExpansion
     * rule. Pinning an era is a per-session view preference for planning
     * content -- it never changes anything on the server.
     */
    public function update(Request $request)
    {
        abort_unless(config('everquest.allow_era_switch'), 404);

        $validated = $request->validate([
            'era' => ['required', 'string'],
        ]);

        $era = $validated['era'];
        $allowed = array_map('strval', ContentFilter::availableExpansions());

        if ($era === 'auto') {
            $request->session()->forget(ContentFilter::SESSION_KEY);
        } elseif ($era === 'all' || in_array($era, $allowed, true)) {
            $request->session()->put(ContentFilter::SESSION_KEY, $era);
        } else {
            return back()->withErrors(['era' => 'Unknown era.']);
        }

        return back();
    }
}
