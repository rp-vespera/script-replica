<?php // WIP35755 — zero out 2 duplicate closures for project 11229

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');
    $AMT = 125662.31;
    $TAG = 'SCRIPT-WEB';

    $DUPS = [
        // WPCL-ACPR0974
        ['closure_id' => 25177, 'acct_gl_ids' => [2343203, 2343204], 'wip_bal_id' => 861957, 'ml_bal_id' => 861945],
        // WPCL-ACPR0979
        ['closure_id' => 26860, 'acct_gl_ids' => [2367820, 2367821], 'wip_bal_id' => 871423, 'ml_bal_id' => 871414],
    ];

    $db->beginTransaction();
    try {
        foreach ($DUPS as $d) {
            // acct_gl DR/CR → 0
            $db->update(
                "UPDATE acct_gl SET debit = 0, credit = 0, updated = ?, date_updated = NOW()
                 WHERE acct_gl_id IN (" . implode(',', $d['acct_gl_ids']) . ")",
                [$TAG]
            );

            // acct_balance WIP row → 0 (was credit 125,662.31)
            $db->update(
                "UPDATE acct_balance SET debit = 0, credit = 0, updated = ?, date_updated = NOW()
                 WHERE acct_balance_id = ?",
                [$TAG, $d['wip_bal_id']]
            );

            // acct_balance Memorial Lot → decrement 125,662.31 (shared row, not zeroed)
            $db->update(
                "UPDATE acct_balance SET debit = debit - ?, updated = ?, date_updated = NOW()
                 WHERE acct_balance_id = ?",
                [$AMT, $TAG, $d['ml_bal_id']]
            );

            // wip_t_project_closure amt_closure → 0
            $db->update(
                "UPDATE wip_t_project_closure SET amt_closure = 0, updated = ?, date_updated = NOW()
                 WHERE wip_t_project_closure_id = ?",
                [$TAG, $d['closure_id']]
            );
        }
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }
};
