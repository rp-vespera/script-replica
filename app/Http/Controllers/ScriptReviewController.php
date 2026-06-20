<?php

namespace App\Http\Controllers;

use App\Models\ScriptRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Script Review — the privileged surface. Behind HTTP Basic Auth
 * (ScriptReviewAuth middleware).
 *
 * Approving runs the script now: success files it to scripts/done/, failure to
 * scripts/failed/. A failed script stays listed so it can be fixed and approved
 * again (re-run). The folder a file lives in is the source of truth.
 */
class ScriptReviewController extends Controller
{
    public function index()
    {
        return view('scripts.review', [
            'pending' => $this->listDir('pending'),
            'failed' => $this->listDir('failed'),
        ]);
    }

    /**
     * Approve = run the script now (wherever it lives — pending/ or failed/),
     * record the result, and file it: success → done/, failure → failed/.
     */
    public function approve(Request $request)
    {
        $name = $this->safeName($request->input('file'));
        $from = $this->locate($name);
        if ($name === null || $from === null) {
            abort(404);
        }

        // Already ran successfully before? Don't run it again — just file it to
        // done/. (A previously FAILED script has no success row, so it still
        // re-runs here, which is the fix-and-retry path.)
        $alreadyDone = ScriptRun::where('filename', $name)
            ->where('status', 'success')
            ->latest('id')
            ->first();

        if ($alreadyDone) {
            $this->moveFile($from, 'done', $name);
            $alreadyDone->update([
                'approval_status' => 'approved',
                'moved_to' => 'done',
                'approved_at' => now(),
            ]);

            return back()->with('status', "{$name} already ran successfully before — filed to scripts/done/ without re-running.");
        }

        Artisan::call('scripts:run-one', ['file' => $from]);

        $run = ScriptRun::where('filename', $name)->latest('id')->first();
        $target = ($run && $run->succeeded()) ? 'done' : 'failed';

        $this->moveFile($from, $target, $name);

        if ($run) {
            $run->update([
                'approval_status' => 'approved',
                'moved_to' => $target,
                'approved_at' => now(),
            ]);
        }

        $word = $target === 'done' ? 'succeeded' : 'failed';

        return back()->with('status', "{$name} {$word} → scripts/{$target}/");
    }

    /**
     * Reject = quarantine a pending script to failed/ without running it.
     */
    public function reject(Request $request)
    {
        $name = $this->safeName($request->input('file'));
        $from = $this->locate($name);
        if ($name === null || $from === null) {
            abort(404);
        }

        $this->moveFile($from, 'failed', $name);

        ScriptRun::create([
            'filename' => $name,
            'status' => 'failed',
            'approval_status' => 'rejected',
            'moved_to' => 'failed',
            'error' => 'Rejected without running.',
            'ran_at' => now(),
        ]);

        return back()->with('status', "Rejected {$name} → scripts/failed/");
    }

    /**
     * Delete a quarantined script from failed/ (give up on it).
     */
    public function delete(Request $request)
    {
        $name = $this->safeName($request->input('file'));
        if ($name === null) {
            abort(404);
        }

        $path = base_path('scripts/failed/'.$name);
        if (is_file($path)) {
            @unlink($path);
        }

        return back()->with('status', "Deleted scripts/failed/{$name}");
    }

    private function listDir(string $dir)
    {
        return collect(glob(base_path("scripts/{$dir}/*.php")) ?: [])
            ->map(fn ($p) => basename($p))
            ->sort()
            ->values();
    }

    /** Find the file in pending/ or failed/ and return its repo-relative path. */
    private function locate(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        foreach (['pending', 'failed'] as $dir) {
            if (is_file(base_path("scripts/{$dir}/{$name}"))) {
                return "scripts/{$dir}/{$name}";
            }
        }

        return null;
    }

    private function safeName(?string $file): ?string
    {
        if ($file === null) {
            return null;
        }

        $name = basename($file);

        return preg_match('/^[A-Za-z0-9._-]+\.php$/', $name) ? $name : null;
    }

    private function moveFile(string $fromRel, string $target, string $name): void
    {
        $from = base_path($fromRel);
        $to = base_path("scripts/{$target}/{$name}");

        // Already in the destination (e.g. a re-run that failed again) — nothing to do.
        if (realpath($from) !== false && realpath($from) === realpath($to)) {
            return;
        }

        if (is_file($from)) {
            @mkdir(dirname($to), 0775, true);
            @rename($from, $to);
        }
    }
}
