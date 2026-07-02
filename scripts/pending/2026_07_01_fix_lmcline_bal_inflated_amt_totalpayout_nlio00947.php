<?php // scripts/pending/2026_07_01_fix_lmcline_bal_inflated_amt_totalpayout_nlio00947.php

/**
 * Fix inflated amt_totalpayout on wip_l_lmcline_bal for NLIO00947.
 *
 * ROOT CAUSE
 * ----------
 * Java SAERP uses encumbrance accounting: it increments
 * wip_l_lmcline_bal.amt_totalpayout at DR-SAVE time (not PR time).
 * For NLIO00947, the Maker drafted in Java offline, which encumbered
 * amt_totalpayout += ₱X per line. The online system then processed new online
 * DRs to PR and incremented amt_totalpayout again — resulting in 2× per line.
 *
 * Neither system had a bug. The mismatch was a design gap: the online system
 * was incrementing amt_totalpayout at PR time rather than DR time, so it did
 * not account for the Java encumbrance already in the field.
 *
 * Fix applied: draftPayoutsForIo now encumbers amt_totalpayout at DR-save
 * time (aligned with Java). processPayoutToPr no longer increments it.
 *
 * NOTE: wip_t_lmc_bgtline.l_qty_payout and l_amt_payout were verified to be
 * correct (1× budget amount). Only amt_totalpayout on wip_l_lmcline_bal needs
 * correction. No GL or fin_l_debt changes are required — actual disbursements
 * are correct and were made exactly once.
 *
 * EVIDENCE
 * --------
 * Online PRs that wrote the CORRECT increment (one each):
 *   NLMC0008198  PR    ₱800.00  → bal_id 38038  MERIENDA PACKAGE
 *   NLMC0008197  PR  ₱1,000.00  → bal_id 38044  INCENTIVE FOR EMCEE
 *   NLMC0008195  PR  ₱1,500.00  → bal_id 38046  PHOTOGRAPHER AND VIDEOGRAPHER
 *   NLMC0008194  PR  ₱1,000.00  → bal_id 38047  SINGER
 *   NLMC0008196  PR  ₱5,000.00  → bal_id 38048  VIDEO LIVESTREAMING
 *
 * Current state (inflated):
 *   bal_id 38038  MERIENDA PACKAGE               budget ₱800    paid ₱1,600  ← 2×
 *   bal_id 38044  INCENTIVE FOR EMCEE            budget ₱1,000  paid ₱2,000  ← 2×
 *   bal_id 38046  PHOTOGRAPHER AND VIDEOGRAPHER  budget ₱1,500  paid ₱3,000  ← 2×
 *   bal_id 38047  SINGER                         budget ₱1,000  paid ₱2,000  ← 2×
 *   bal_id 38048  VIDEO LIVESTREAMING            budget ₱5,000  paid ₱10,000 ← 2×
 *
 * Correct value = amt_totallmcbudget = PR payoutline amt (fully paid, once).
 *
 * TABLES UPDATED
 * --------------
 *   wip_l_lmcline_bal  — amt_totalpayout set to correct (budget) amount
 *
 * TABLES VERIFIED OK (no change needed)
 * --------------------------------------
 *   wip_t_lmc_bgtline  — l_qty_payout and l_amt_payout already at 1× (correct)
 *   fin_l_debt         — not relevant; amt_totalpayout is a balance ledger field only
 *   acct_gl            — GL entries created once at PR time; no duplicate entries
 */

return function ($cmd) {
    $DRY_RUN = true;
    $CONN    = 'mysql_secondary';

    // Expected scope for all 5 lines — NLIO00947 INTERMENT ORDER scope.
    // Checked per-row to prevent accidental writes to a different IO.
    $EXPECTED_SCOPE_ID = 25958;

    // The 5 PRs that correctly incremented amt_totalpayout.
    // correct_amt must equal BOTH amt_totallmcbudget AND the PR payoutline amt.
    $corrections = [
        [
            'wip_l_lmcline_bal_id' => 38038,
            'description'          => 'MERIENDA PACKAGE',
            'correct_amt'          => 800.00,
            'pr_documentno'        => 'NLMC0008198',
        ],
        [
            'wip_l_lmcline_bal_id' => 38044,
            'description'          => 'INCENTIVE FOR EMCEE',
            'correct_amt'          => 1000.00,
            'pr_documentno'        => 'NLMC0008197',
        ],
        [
            'wip_l_lmcline_bal_id' => 38046,
            'description'          => 'PHOTOGRAPHER AND VIDEOGRAPHER',
            'correct_amt'          => 1500.00,
            'pr_documentno'        => 'NLMC0008195',
        ],
        [
            'wip_l_lmcline_bal_id' => 38047,
            'description'          => 'SINGER',
            'correct_amt'          => 1000.00,
            'pr_documentno'        => 'NLMC0008194',
        ],
        [
            'wip_l_lmcline_bal_id' => 38048,
            'description'          => 'VIDEO LIVESTREAMING',
            'correct_amt'          => 5000.00,
            'pr_documentno'        => 'NLMC0008196',
        ],
    ];

    $line  = str_repeat('─', 64);
    $db    = \DB::connection($CONN);
    $abort = false; // set to true by any hard guard failure → rollback everything

    echo "{$line}\n";
    echo "FIX INFLATED amt_totalpayout — NLIO00947\n";
    echo "Connection : {$CONN}\n";
    echo "Mode       : " . ($DRY_RUN ? 'DRY-RUN (no writes)' : 'APPLY') . "\n";
    echo "{$line}\n";

    // ── PRE-FLIGHT: run all guard checks before opening the write transaction ──

    echo "\n[PRE-FLIGHT CHECKS]\n";

    $preflight = true; // set to false on any pre-flight failure

    foreach ($corrections as $c) {
        $balId      = $c['wip_l_lmcline_bal_id'];
        $correctAmt = $c['correct_amt'];
        $desc       = $c['description'];
        $prDoc      = $c['pr_documentno'];

        echo "\n  ▶ bal_id={$balId}  {$desc}\n";

        // 1. Row existence
        $bal = $db->selectOne(
            'SELECT b.wip_l_lmcline_bal_id, b.amt_totallmcbudget, b.amt_totalpayout,
                    st.wip_i_project_scope_id
               FROM wip_l_lmcline_bal b
               JOIN wip_t_lmc_bgtline bl ON bl.wip_l_lmcline_bal_id = b.wip_l_lmcline_bal_id
               JOIN wip_i_project_scope_stage st
                    ON st.wip_i_project_scope_stage_id = bl.wip_i_project_scope_stage_id
              WHERE b.wip_l_lmcline_bal_id = ?',
            [$balId]
        );

        if (!$bal) {
            echo "    ⛔ FAIL: row not found in wip_l_lmcline_bal.\n";
            $preflight = false;
            continue;
        }

        $current = (float) $bal->amt_totalpayout;
        $budget  = (float) $bal->amt_totallmcbudget;

        // 2. Scope membership — must belong to NLIO00947 (scope 25958)
        if ((int) $bal->wip_i_project_scope_id !== $EXPECTED_SCOPE_ID) {
            echo "    ⛔ FAIL: scope_id={$bal->wip_i_project_scope_id} ≠ expected {$EXPECTED_SCOPE_ID}."
               . " Wrong IO — refusing to touch.\n";
            $preflight = false;
            continue;
        }

        // 3. Budget sanity — amt_totallmcbudget must equal correct_amt.
        //    If the budget was edited since analysis, correct_amt is stale.
        if (abs($budget - $correctAmt) > 0.001) {
            echo "    ⛔ FAIL: amt_totallmcbudget=₱{$budget} ≠ expected ₱{$correctAmt}."
               . " Budget changed since analysis — investigate before applying.\n";
            $preflight = false;
            continue;
        }

        // 4. 2× pattern guard — current must be exactly double correct_amt.
        //    Any other value means an unknown event changed the balance; do not touch.
        if (abs($current - ($correctAmt * 2)) > 0.001) {
            if (abs($current - $correctAmt) < 0.001) {
                echo "    ✅ Already at correct value ₱{$correctAmt} — will skip.\n";
            } else {
                echo "    ⛔ FAIL: amt_totalpayout=₱{$current} is not 2× expected ₱{$correctAmt}."
                   . " Unknown state — manual investigation required.\n";
                $preflight = false;
            }
            continue;
        }

        // 5. PR verification — the linked PR must exist, be in PR status, and its
        //    payoutline for this bal_id must have amt = correct_amt.
        $prLine = $db->selectOne(
            'SELECT pl.amt, p.docstatus, p.wip_t_lmc_payout_id
               FROM wip_t_lmc_payout p
               JOIN wip_t_lmc_payoutline pl
                    ON pl.wip_t_lmc_payout_id = p.wip_t_lmc_payout_id
              WHERE p.documentno           = ?
                AND p.wip_i_project_scope_id = ?
                AND pl.wip_l_lmcline_bal_id  = ?
                AND COALESCE(p.is_active, 1) = 1
                AND COALESCE(pl.is_active, 1) = 1
              LIMIT 1',
            [$prDoc, $EXPECTED_SCOPE_ID, $balId]
        );

        if (!$prLine) {
            echo "    ⛔ FAIL: PR {$prDoc} not found for bal_id={$balId} in scope {$EXPECTED_SCOPE_ID}.\n";
            $preflight = false;
            continue;
        }

        if ($prLine->docstatus !== 'PR') {
            echo "    ⛔ FAIL: {$prDoc} is in status '{$prLine->docstatus}', expected PR.\n";
            $preflight = false;
            continue;
        }

        if (abs((float) $prLine->amt - $correctAmt) > 0.001) {
            echo "    ⛔ FAIL: {$prDoc} payoutline amt=₱{$prLine->amt} ≠ expected ₱{$correctAmt}.\n";
            $preflight = false;
            continue;
        }

        echo "    ✓ scope={$bal->wip_i_project_scope_id}"
           . "  budget=₱{$budget}"
           . "  current=₱{$current}"
           . "  PR {$prDoc} amt=₱{$prLine->amt}  [{$prLine->docstatus}]\n";
    }

    if (!$preflight) {
        echo "\n{$line}\n";
        echo "⛔ PRE-FLIGHT FAILED — one or more checks did not pass.\n";
        echo "   No writes performed. Resolve the issues above before re-running.\n";
        echo "{$line}\n";
        if (isset($cmd)) $cmd->error('pre-flight failed — no changes written.');
        return;
    }

    echo "\n  ✅ All pre-flight checks passed.\n";

    // ── WRITE PHASE ──────────────────────────────────────────────────────────

    echo "\n[CORRECTIONS]\n";

    $totalRows = 0;

    $db->beginTransaction();
    try {
        foreach ($corrections as $c) {
            $balId      = $c['wip_l_lmcline_bal_id'];
            $correctAmt = $c['correct_amt'];
            $desc       = $c['description'];

            $current = (float) $db->selectOne(
                'SELECT amt_totalpayout FROM wip_l_lmcline_bal WHERE wip_l_lmcline_bal_id = ?',
                [$balId]
            )->amt_totalpayout;

            echo "\n  ▶ bal_id={$balId}  {$desc}\n";
            echo "    ₱{$current}  →  ₱{$correctAmt}\n";

            // Skip rows already at the correct value (idempotency — handles re-run after partial apply)
            if (abs($current - $correctAmt) < 0.001) {
                echo "    ✅ Already correct — skipping.\n";
                continue;
            }

            // Hard abort if the value shifted between pre-flight and now (concurrent write).
            // WHERE guard in the UPDATE also catches this, but we want an explicit error.
            if (abs($current - ($correctAmt * 2)) > 0.001) {
                $abort = true;
                echo "    ⛔ ABORT: value changed between pre-flight and write phase (₱{$current})."
                   . " Possible concurrent write — rolling back all rows.\n";
                break;
            }

            // WHERE clause pins the update to the exact inflated value we pre-read.
            // If any concurrent writer changed it between now and the UPDATE, rows = 0
            // and we detect a lost-update below.
            $rows = $db->update(
                'UPDATE wip_l_lmcline_bal
                    SET amt_totalpayout = ?,
                        updated         = ?,
                        date_updated    = NOW()
                  WHERE wip_l_lmcline_bal_id = ?
                    AND amt_totalpayout       = ?',
                [$correctAmt, 'Script by Web', $balId, $current]
            );

            if ($rows !== 1) {
                $abort = true;
                echo "    ⛔ ABORT: UPDATE matched {$rows} row(s) instead of 1 for bal_id={$balId}."
                   . " Possible concurrent write — rolling back all rows.\n";
                break;
            }

            echo "    wip_l_lmcline_bal updated : {$rows} row\n";
            $totalRows += $rows;
        }

        if ($abort) {
            $db->rollBack();
            echo "\n{$line}\n";
            echo "⛔ ABORTED — rolled back all changes.\n";
            echo "{$line}\n";
            if (isset($cmd)) $cmd->error('aborted — all changes rolled back.');
            return;
        }

        if ($DRY_RUN) {
            $db->rollBack();
            echo "\n{$line}\n";
            echo "DRY-RUN complete — no changes written.\n";
            echo "Set \$DRY_RUN = false to apply.\n";
            echo "{$line}\n";
            if (isset($cmd)) $cmd->info('dry-run complete, no changes written.');
            return;
        }

        $db->commit();

        // ── POST-COMMIT VERIFICATION ─────────────────────────────────────────

        echo "\n[POST-COMMIT VERIFICATION]\n";
        $allOk = true;
        foreach ($corrections as $c) {
            $balId      = $c['wip_l_lmcline_bal_id'];
            $correctAmt = $c['correct_amt'];
            $desc       = $c['description'];

            $afterBal = $db->selectOne(
                'SELECT amt_totalpayout, updated, date_updated
                   FROM wip_l_lmcline_bal
                  WHERE wip_l_lmcline_bal_id = ?',
                [$balId]
            );
            $isOk = abs((float) $afterBal->amt_totalpayout - $correctAmt) < 0.001;
            $allOk = $allOk && $isOk;
            $mark = $isOk ? '✅' : '⛔';
            echo "  {$mark} bal_id={$balId}  {$desc}"
               . "  amt_totalpayout=₱{$afterBal->amt_totalpayout}"
               . "  updated={$afterBal->updated}\n";
        }

        echo "\n{$line}\n";
        if ($allOk) {
            echo "✅ All {$totalRows} row(s) verified correct after commit.\n";
        } else {
            echo "⛔ Post-commit verification FAILED — check the rows above.\n";
        }
        echo "{$line}\n";
        if (isset($cmd)) $cmd->info("nlio00947-inflated-bal: {$totalRows} row(s) updated and verified.");

    } catch (\Throwable $e) {
        $db->rollBack();
        echo "\n❌ Exception — rolled back: " . $e->getMessage() . "\n";
        throw $e;
    }
};
