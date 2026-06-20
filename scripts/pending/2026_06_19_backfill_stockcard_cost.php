<?php // scripts/pending/2026_06_19_backfill_stockcard_cost.php

/**
 * Backfill blank cost/amount on stock-card records created by AGRIGR ("SYSTEM").
 *
 * Cause: PoStatusController wrote nvt_l_stockcard_mac.amt/cost (and the derived
 * nvt_l_stockcard_costadj) at 0 because it read $item['amt_netgr'] (never sent
 * by the Receive page) instead of price × qty. grline.amt_netgr is correct, so
 * we re-derive every blank stock-card row from it.
 *
 * Pass  A (always): fill per-row amt + cost on every blank app row.
 * Pass B (purchase-only ledgers): replay cumamt / average_cost. Ledgers that
 *         already have non-PURCHASE movements are left for SAERP recost.
 *
 * The work runs inside a transaction on the SAERP write connection; verification
 * counts are taken AFTER the updates so the dry-run shows real results, then it
 * rolls back when DRY_RUN. On error it rolls back and re-throws so the script
 * runner files it under scripts/failed/ with the trace.
 *
 * Idempotent: re-running finds no blank rows ("Nothing to do.") and is a no-op.
 */

return function ($cmd) {
    $DRY_RUN = false;                 // <-- false to commit
    $CONN    = 'mysql_secondary';     // <-- production SAERP write connection to apply (replica is read-only)

    $db = \DB::connection($CONN);
    $line = str_repeat('=', 64);
    echo "$line\nSTOCK-CARD COST/AMOUNT BACKFILL\nConnection: $CONN   Mode: " . ($DRY_RUN ? 'DRY-RUN' : 'APPLY') . "\n$line\n";

    $countBlank = fn() => (int) ($db->select("
        SELECT COUNT(*) AS n FROM nvt_l_stockcard_mac mac
        JOIN nvt_t_grline gl ON gl.nvt_l_stockcard_mac_id = mac.nvt_l_stockcard_mac_id
        WHERE mac.created='SYSTEM' AND (COALESCE(mac.amt,0)=0 OR COALESCE(mac.cost,0)=0) AND COALESCE(gl.amt_netgr,0) > 0
    ")[0]->n ?? 0);

    $blankBefore = $countBlank();
    echo "BEFORE — blank app MAC rows (recoverable): $blankBefore\n";

    $bad = $db->select("
        SELECT mac.nvt_l_stockcard_mac_id AS mac_id, mac.nvt_i_sku_id AS sku, mac.ad_org_id AS org,
               mac.qty AS qty, mac.documentno AS documentno, gl.amt_netgr AS correct_amt
        FROM nvt_l_stockcard_mac mac
        JOIN nvt_t_grline gl ON gl.nvt_l_stockcard_mac_id = mac.nvt_l_stockcard_mac_id
        WHERE mac.created='SYSTEM' AND (COALESCE(mac.amt,0)=0 OR COALESCE(mac.cost,0)=0) AND COALESCE(gl.amt_netgr,0) > 0
    ");
    if (count($bad) === 0) { echo "Nothing to do.\n$line\n"; return; }

    $correctAmt = []; $skuOrg = [];
    foreach ($bad as $r) { $correctAmt[(int)$r->mac_id] = (float)$r->correct_amt; $skuOrg[$r->sku.'|'.$r->org] = [(int)$r->sku, (int)$r->org]; }
    echo "Affected sku+org ledgers: " . count($skuOrg) . "\n\n";

    $purchaseOnly = [];
    foreach ($skuOrg as $key => [$sku, $org]) {
        $np = $db->select("SELECT COUNT(*) AS n FROM nvt_l_stockcard_mac WHERE nvt_i_sku_id=? AND ad_org_id=? AND COALESCE(is_active,1)=1 AND UPPER(COALESCE(`transaction`,''))<>'PURCHASE'", [$sku, $org]);
        $purchaseOnly[$key] = ((int)($np[0]->n ?? 0) === 0);
    }

    $res = ['macFilled'=>0,'adjFilled'=>0,'replayLedgers'=>0,'replayRows'=>0,'mixedLedgers'=>0,'errors'=>0];

    $db->beginTransaction();
    try {
        // PASS A — per-row amt + cost (every blank row)
        foreach ($bad as $r) {
            $id=(int)$r->mac_id; $qty=(float)$r->qty; $amt=(float)$r->correct_amt;
            $cost=$qty!=0.0?round($amt/$qty,2):0.0;
            $res['macFilled'] += $db->update("UPDATE nvt_l_stockcard_mac SET amt=?, cost=? WHERE nvt_l_stockcard_mac_id=? AND created='SYSTEM'", [$amt,$cost,$id]);
            $res['adjFilled'] += $db->update("UPDATE nvt_l_stockcard_costadj SET amount=?, cost=? WHERE created='SYSTEM' AND nvt_i_sku_id=? AND documentno=?", [$amt,$cost,$r->sku,$r->documentno]);
        }

        // PASS B — replay cumamt/average on purchase-only ledgers.
        // Running total is computed across the FULL ledger (native rows are READ so
        // positions are correct) but only OUR rows (created='SYSTEM') are WRITTEN —
        // native rows are never modified.
        foreach ($skuOrg as $key => [$sku, $org]) {
            if (!$purchaseOnly[$key]) { $res['mixedLedgers']++; continue; }
            $res['replayLedgers']++;
            $rows = $db->select("SELECT nvt_l_stockcard_mac_id AS id, qty, amt, documentno, created FROM nvt_l_stockcard_mac WHERE nvt_i_sku_id=? AND ad_org_id=? AND COALESCE(is_active,1)=1 ORDER BY nvt_l_stockcard_mac_id ASC", [$sku,$org]);
            $cumqty=0.0; $cumamt=0.0;
            foreach ($rows as $row) {
                $id=(int)$row->id; $qty=(float)$row->qty;
                $amt=array_key_exists($id,$correctAmt)?$correctAmt[$id]:(float)$row->amt;
                $prevQty=$cumqty; $prevAmt=$cumamt; $cumqty+=$qty; $cumamt+=$amt;
                if ($row->created !== 'SYSTEM') { continue; }   // never write native rows
                $avg=$cumqty!=0.0?round($cumamt/$cumqty,6):0.0;
                $res['replayRows'] += $db->update("UPDATE nvt_l_stockcard_mac SET cumqty=?, cumamt=? WHERE nvt_l_stockcard_mac_id=? AND created='SYSTEM'", [$cumqty,$cumamt,$id]);
                $db->update("UPDATE nvt_l_stockcard_costadj SET cumqty_prev=?, cumamt_prev=?, qty_basis=?, amount_basis=?, average_cost=? WHERE created='SYSTEM' AND nvt_i_sku_id=? AND documentno=?", [$prevQty,$prevAmt,$cumqty,$cumamt,$avg,$sku,$row->documentno]);
            }
        }

        $blankAfter = $countBlank();   // taken inside the txn — reflects the fix

        echo "RESULTS\n$line\n";
        printf("  MAC rows  — amt/cost filled : %d  (success)\n", $res['macFilled']);
        printf("  costadj   — amt/cost filled : %d  (success)\n", $res['adjFilled']);
        printf("  Ledgers   — average replayed: %d ledgers / %d rows\n", $res['replayLedgers'], $res['replayRows']);
        printf("  Ledgers   — mixed, skipped  : %d  (fill done; run SAERP recost for averages)\n", $res['mixedLedgers']);
        printf("  Blank app MAC rows  before  : %d\n", $blankBefore);
        printf("  Blank app MAC rows  after   : %d   %s\n", $blankAfter, $blankAfter===0?'✓ all cleared':'(remaining)');
        echo "$line\n";

        if ($DRY_RUN) { $db->rollBack(); echo "DRY-RUN — verified above, rolled back. No changes written.\n"; }
        else          { $db->commit();  echo "✅ COMMITTED — backfill applied successfully.\n"; }
    } catch (\Throwable $e) {
        $db->rollBack();
        echo "❌ ERROR (rolled back): " . $e->getMessage() . "\n";
        throw $e;   // surface to the runner so the file is filed under scripts/failed/
    }
    echo "$line\n";

    if (isset($cmd)) {
        $cmd->info(sprintf('stock-card backfill: %d MAC + %d costadj rows filled, %d ledgers replayed', $res['macFilled'], $res['adjFilled'], $res['replayLedgers']));
    }
};
