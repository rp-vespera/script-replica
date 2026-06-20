<?php
/**
 * IMS#16747 — Cancel LSP PR-I0031024 — POST-CANCEL (2 of 2)
 * Lot 5529 · Preownership record 6643
 *
 * Full procedure: scripts/IMS16747_cancel_lsp_documentation.md
 *
 * STAGED HERE ON PURPOSE — this file is NOT in scripts/pending/ so it cannot
 * auto-run before the cancel. Sequence:
 *   1. run the pre-cancel script (scripts/pending/...precancel.php)
 *   2. run the in-app "Cancel LSP" for PR-I0031024 (doc Step 4)
 *   3. THEN move THIS file into scripts/pending/ and deploy
 *
 * Runs the doc's Step 5 (variance fix) + Step 6 (verify) inside ONE transaction
 * on the ERP connection. It will ABORT (and roll back) unless the application
 * cancel has already committed — verified by the existence of the -CA record.
 */

use Illuminate\Support\Facades\DB;

return function ($cmd) {
    $connName   = 'mysql_secondary';     // ERP database
    $documentno = 'PR-I0031024';
    $caDocno    = 'PR-I0031024-CA';      // reversal record the app cancel creates
    $preId      = 6643;
    $lotId      = 5529;
    $tag        = 'IMS#16747';

    $db = DB::connection($connName);

    $db->transaction(function () use ($db, $documentno, $caDocno, $preId, $lotId, $tag, $cmd) {
        // ── Guard — the in-app cancel must have committed first ─────────────
        $ca = $db->selectOne(
            'SELECT documentno, is_cancelled, docstatus FROM mp_t_lot_sales WHERE documentno = ?',
            [$caDocno]
        );
        if (! $ca) {
            throw new RuntimeException(
                "Cancel not yet performed: reversal record {$caDocno} does not exist. "
                . "Run the in-app 'Cancel LSP' for {$documentno} (doc Step 4) before this script. Aborting."
            );
        }
        $cmd->info("Cancel confirmed: {$caDocno} present (docstatus={$ca->docstatus}, is_cancelled={$ca->is_cancelled}).");

        // ── Step 5 — variance fix: reset running total to the official figure ─
        $a5 = $db->update(
            'UPDATE mp_l_preownership p
                SET p.total_sales = (
                        SELECT COALESCE(SUM(t.amt_sales),0) FROM mp_l_preownership_threshold t
                         WHERE t.mp_l_preownership_id = p.mp_l_preownership_id),
                    p.is_paid = 0, p.updated = ?
              WHERE p.mp_l_preownership_id = ?',
            [$tag, $preId]
        );
        $cmd->info("Step 5 (variance fix applied): {$a5} row(s)");

        // ── Step 6 — verify total_sales now equals the official figure ──────
        $chk = $db->selectOne(
            'SELECT p.total_sales,
                    COALESCE(SUM(t.amt_sales),0) AS official_figure,
                    (p.total_sales - COALESCE(SUM(t.amt_sales),0)) AS diff
               FROM mp_l_preownership p
               LEFT JOIN mp_l_preownership_threshold t ON t.mp_l_preownership_id = p.mp_l_preownership_id
              WHERE p.mp_l_preownership_id = ?
              GROUP BY p.total_sales',
            [$preId]
        );
        $cmd->info('Verify: ' . json_encode($chk));

        if (! $chk || (float) $chk->diff !== 0.0) {
            throw new RuntimeException(
                'Variance check FAILED (total_sales != official_figure). Rolling back the variance fix.'
            );
        }

        // Informational — final state of the lot.
        $lot = $db->selectOne(
            'SELECT is_owned, is_preowned, is_reserved, status_code FROM mp_i_lot WHERE mp_i_lot_id = ?',
            [$lotId]
        );
        $cmd->info('Lot final state: ' . json_encode($lot));
        $cmd->info('=== POST-CANCEL DONE — status OK (variance = 0). ===');
    });
};
