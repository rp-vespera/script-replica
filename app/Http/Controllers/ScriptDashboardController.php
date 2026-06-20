<?php

namespace App\Http\Controllers;

use App\Models\ScriptRun;
use Illuminate\Http\Request;

/**
 * Script Runs — read-only history of every recorded run. Optionally gated by
 * SCRIPTS_DASHBOARD_KEY. The privileged actions live in ScriptReviewController.
 */
class ScriptDashboardController extends Controller
{
    public function index(Request $request)
    {
        $key = config('services.scripts_dashboard.key');
        if (! empty($key) && ! hash_equals($key, (string) $request->input('key'))) {
            abort(403);
        }

        $runs = ScriptRun::query()->latest('ran_at')->limit(200)->get();

        $stats = [
            'total' => ScriptRun::count(),
            'success' => ScriptRun::where('status', 'success')->count(),
            'failed' => ScriptRun::where('status', 'failed')->count(),
        ];

        return view('scripts.runs', compact('runs', 'stats'));
    }
}
