# One-off scripts

Drop a one-off / run-once script in `pending/`. On the next deploy of that
**branch**, the pipeline runs each pending script against that environment's
database, then moves the file based on the outcome and commits the move back
to the branch (with `[skip ci]` so it doesn't re-trigger the deploy):

- **success** → moved to `done/`
- **failure** → moved to `failed/` (its DB changes are rolled back)

The deploy does **not** abort on a failed script — it quarantines it in
`failed/` and carries on. Every run (pass or fail) is also recorded in the
`script_runs` table and shown on the `/scripts` dashboard, with the error
trace for failures.

To retry a failed script, fix it and move it back into `pending/`.

## Behaviour (per-branch)

- A script on the `staging` branch runs on **staging** only.
- A script on the `main` branch runs on **production** only.
- "Done" is tracked by the file's location in git. Because `done/` is committed,
  a script never runs twice in the same branch.
- If you merge `staging` → `main`, any already-archived script arrives in `done/`
  and will **not** run on production. To run something on prod, add it to a
  branch that reaches `main` while still in `pending/` (e.g. commit it to `main`,
  or add it on `staging` in the same change that merges before it deploys).

## Writing a script

A script is a PHP file that **returns a closure**. It runs inside the booted
Laravel app, so Eloquent, facades, and config are all available. The closure
receives the artisan command instance, so you can use `$cmd->info(...)` etc.

```php
<?php // scripts/pending/2026_06_17_backfill_phone.php

return function ($cmd) {
    $updated = \App\Models\User::whereNull('phone')->update(['phone' => '']);
    $cmd->info("backfilled {$updated} users");
};
```

By default the closure runs inside a DB transaction (rolled back on error).

## Conventions

- Prefix filenames with a date so they sort/run in order: `YYYY_MM_DD_description.php`.
- **Make scripts idempotent** where possible: if a script succeeds but the
  follow-up `git push` fails, the next deploy will run it again.
- For scripts that must not run in a transaction (e.g. DDL on MySQL, or chunked
  long-running jobs), the runner can be invoked with `--no-transaction`.

## Run locally

```bash
php artisan scripts:run-one scripts/pending/2026_06_17_backfill_phone.php
```
