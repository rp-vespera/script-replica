<?php

namespace App\Console\Commands;

use App\Models\ScriptRun;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class RunOneScript extends Command
{
    /**
     * Run a single one-off script file inside the application context.
     *
     * The file must `return` a closure. It is executed inside a DB
     * transaction so a mid-script failure rolls back cleanly. Every run —
     * success or failure — is recorded in the script_runs table so the
     * dashboard can show its outcome. A non-zero exit tells the deploy step
     * to move the file to scripts/failed/ instead of scripts/done/.
     */
    protected $signature = 'scripts:run-one
                            {file : Path to the script file (relative to the app root or absolute)}
                            {--no-transaction : Run without wrapping the script in a DB transaction}';

    protected $description = 'Execute a single one-off script file (used by the deploy pipeline)';

    public function handle(): int
    {
        $file = $this->argument('file');
        $path = $this->resolvePath($file);
        $name = $path !== null ? basename($path) : basename($file);

        if ($path === null || ! is_file($path)) {
            $this->error("Script not found: {$file}");
            $this->record($name, 'failed', null, "Script not found: {$file}", null);

            return self::FAILURE;
        }

        $script = require $path;

        if (! is_callable($script)) {
            $this->error("Script must return a callable (closure): {$file}");
            $this->record($name, 'failed', null, 'Script did not return a callable (closure).', null);

            return self::FAILURE;
        }

        $this->info("Running script: {$file}");

        $start = microtime(true);
        ob_start();

        try {
            if ($this->option('no-transaction')) {
                $script($this);
            } else {
                DB::transaction(fn () => $script($this));
            }
        } catch (Throwable $e) {
            $output = ob_get_clean() ?: null;
            $duration = (int) round((microtime(true) - $start) * 1000);

            $this->error("Script failed: {$file}");
            $this->error($e->getMessage());
            report($e);

            $this->record($name, 'failed', $output, $e->getMessage()."\n\n".$e->getTraceAsString(), $duration);

            return self::FAILURE;
        }

        $output = ob_get_clean() ?: null;
        $duration = (int) round((microtime(true) - $start) * 1000);

        if ($output !== null) {
            $this->line($output);
        }

        $this->info("Script succeeded: {$file} ({$duration}ms)");
        $this->record($name, 'success', $output, null, $duration);

        return self::SUCCESS;
    }

    private function resolvePath(string $file): ?string
    {
        if (is_file($file)) {
            return realpath($file) ?: $file;
        }

        $candidate = base_path($file);

        return is_file($candidate) ? (realpath($candidate) ?: $candidate) : null;
    }

    /**
     * Persist the run outcome. Written outside the script's transaction so a
     * failed (rolled-back) script still leaves a record. A logging failure
     * must never mask the real script result.
     */
    private function record(string $filename, string $status, ?string $output, ?string $error, ?int $durationMs): void
    {
        try {
            ScriptRun::create([
                'filename' => $filename,
                'status' => $status,
                'output' => $output,
                'error' => $error,
                'duration_ms' => $durationMs,
                'ran_at' => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            $this->warn('Could not record script run: '.$e->getMessage());
            report($e);
        }
    }
}
