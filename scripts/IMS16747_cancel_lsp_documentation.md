# IMS#16747 — Cancelling a Lot Payment Made by Mistake (PR-I0031024)

*Lot 5529 · Preownership record 6643*

> **Two run-once scripts, split around the manual cancel.** The procedure can't
> be a single auto-run script because Step 4 (Cancel LSP) is a manual ERP action.
> It is implemented as two closure scripts (run **replica first**, during a quiet
> window):
>
> 1. `scripts/pending/2026_06_19_ims16747_cancel_lsp_precancel.php` — Steps 0-3
>    (capture, guards, un-own, lot → AVAILABLE). Safe to auto-run on deploy.
> 2. **Manual:** run the in-app **Cancel LSP** for `PR-I0031024` (Step 4).
> 3. `scripts/2026_06_19_ims16747_cancel_lsp_postcancel.php` — Steps 5-6 (variance
>    fix + verify). **Staged at the `scripts/` root, NOT in `pending/`**, so it
>    can't run before the cancel. It aborts unless the `-CA` reversal exists. Move
>    it into `pending/` only after the cancel is done.

---

## PART 1 — Plain-Language Summary (for management)

### The situation
Client **Ronalene T. Cruz** originally purchased a **Community Vault (CV)** and we
already processed her lot payment, her interment order, and the related fees
(interment payment of **₱27,000** = ₱25,000 interment service + ₱2,000 weekend fee).

The client has now **decided to switch from the Community Vault to a Lawn Lot.**
Because of that change, everything booked on the Community Vault has to be **cancelled
in reverse order** and then **re-processed on the new Lawn Lot.**

To cancel cleanly, we work from the most recent transaction backwards:

| Order | Cancel this | What it is |
|------|-------------|------------|
| 1 | RAP **PR-I 31029** | Rental / add-on payment |
| 2 | NLIO Payment **PR-I 31025** | The ₱27,000 interment payment |
| 3 | NLIO **717** | The interment order itself |
| 4 | **LSP PR-I 31024** | **The lot sales payment — THIS is the step the system blocks** |

Steps 1-3 cancel normally. **Step 4 is where we are stuck**, and the rest of this
document is about clearing that one blocked step. Once it is cancelled, we re-process
for the lawn lot (new lot purchase, new interment order, interment payment, rental/add-ons).

### Why step 4 is blocked
The lot sales payment (**PR-I0031024**) cannot be cancelled because the Community Vault
lot became **fully paid**, which the system automatically promotes to **"owned" (titled).**
To protect that title, the system blocks the cancellation. There is no built-in button
to reverse a payment once a lot reaches the fully-paid/owned stage — so we have to
clear it carefully by hand.

### Why the system won't let us cancel it
The system has a built-in safety rule: **once a lot is "fully paid," it automatically
becomes "owned" (titled) by the customer.** To protect that title, the system blocks
anyone from cancelling the payment behind it.

In short — the payment is locked because the lot is already marked **fully paid and
owned**, and the system has no built-in button to reverse a payment once it reaches
that stage.

### What we will do to clear the blocked step (step 4 — the LSP)
We carefully return the lot record to the state it was in *before* it became fully
paid/owned, let the system cancel the payment normally, then make sure all the numbers
line up again:

1. **Lift the "owned/title" mark** on the lot so the cancel is allowed.
   *(The ownership record is kept for history, not deleted.)*
2. **Set the lot back to "available"** — so it can be used again (the client is
   leaving the Community Vault).
3. **Run the system's normal Cancel function** on the payment. With the locks lifted,
   the system reverses the payment properly on its own, including all accounting entries.
4. **Re-balance the internal tracking figure** so it matches the official accounting
   records exactly. *(This is the "variance fix" — explained below.)*
5. **Verify** everything balances and the payment shows as cancelled.

### After the cancellation — re-process on the Lawn Lot
Once steps 1-4 of the cancellation chain are complete, the client's purchase is rebuilt
on the new lawn lot:

6. Process **Project Closure** for NLIO 717
7. Process **OR-LSP** for the new lawn-lot purchase
8. Process **creation of new LIO** (lawn interment order)
9. Process **IO payment**
10. Process **Rental and add-ons** request, then **RAP**

### How we make sure the books stay correct
When the system cancels the payment in step 3, it **automatically corrects the official
financial records** — those are always right. There is only **one internal tracking
number** that needs a manual touch-up afterward, and we set it using the system's own
official figure as the source of truth — never a guessed number.

After step 5 we run a check that compares the internal tracking number against the
official records. If they match exactly, we are done. If not, we stop and undo —
nothing is final until that check passes.

### Where we do this
- **First on the test copy (replica)** — a safe practice environment — to confirm the
  whole process works and the numbers balance.
- **Only then on the live system**, during a quiet period, with the same steps.

### Safety net
Before changing anything, we **write down the original values**. If anything looks
wrong at any point, we can restore the record exactly as it was.

### Recommendation
This kind of mistake can happen again. Today the fix is a careful manual procedure that
depends on staff performing every step correctly. A small, one-time system improvement
would let staff cancel these payments with a single safe click — no manual steps, no
risk to the books. We suggest scheduling that improvement when convenient.

---

## What "the variance" means (plain language)

The system keeps **two figures** about how much has been paid on a lot:

- the **official accounting figure** (the books), and
- an **internal running total** used for screens and reports.

Normally they are always equal. When we lift the lock to allow the cancellation, the
**internal running total** ends up slightly off from the **official books** — that gap
is the **variance**. The "variance fix" simply resets the internal running total back to
match the official books, so reports (Statement of Account, Outstanding Balance,
Preownership Ledger) show the correct amount again.

---

## PART 2 — Technical Procedure (for IT / execution)

> Run on the **REPLICA first**. Engine: MySQL / MariaDB. All edits tagged `IMS#16747`.
> The cancel reverses the GL / threshold / by-org figures correctly on its own; only
> `total_sales` needs the manual correction in Step 5 (the variance fix).

### Step 0 — Capture before-state (read-only; SAVE the output)
```sql
-- identify the record + the two preconditions
SELECT l.mp_l_preownership_id, l.mp_i_lot_id
FROM mp_t_lot_sales s
JOIN mp_t_lot_sales_line l ON l.mp_t_lot_sales_id = s.mp_t_lot_sales_id
WHERE s.documentno = 'PR-I0031024';                 -- expect exactly ONE row

SELECT p.amtcontract_sales, p.total_sales_discount, p.amt_waived,
       (p.amtcontract_sales - COALESCE(p.total_sales_discount,0) - COALESCE(p.amt_waived,0)) AS fully_paid_threshold,
       p.total_sales, p.is_paid, p.is_owned, p.is_printed,
       (SELECT COALESCE(SUM(t.amt_sales),0) FROM mp_l_preownership_threshold t
         WHERE t.mp_l_preownership_id = p.mp_l_preownership_id) AS official_figure
FROM mp_l_preownership p WHERE p.mp_l_preownership_id = 6643;
-- PRECONDITION: fully_paid_threshold MUST be > 11606  (else pick a lower value in Step 2)
-- official_figure = the correct target for Step 5

SELECT mp_l_ownership_id, is_active, mp_l_preownership_id FROM mp_l_ownership WHERE mp_l_preownership_id = 6643;
SELECT is_owned, is_preowned, is_reserved, status_code, mp_i_lotstatus_id FROM mp_i_lot WHERE mp_i_lot_id = 5529;
```

### Step 1 — Lift the "owned" lock (detach + retire ownership)
```sql
UPDATE mp_l_ownership
SET is_active = 0, mp_l_preownership_id = NULL, updated = 'IMS#16747'
WHERE mp_l_preownership_id = (
    SELECT l.mp_l_preownership_id FROM mp_t_lot_sales s
    JOIN mp_t_lot_sales_line l ON l.mp_t_lot_sales_id = s.mp_t_lot_sales_id
    WHERE s.documentno = 'PR-I0031024' LIMIT 1);
```

### Step 2 — Lower the running total below the "fully paid" threshold + un-own
```sql
UPDATE mp_l_preownership
SET is_owned = 0, is_printed = 0, total_sales = 11606.00, updated = 'IMS#16747'
WHERE mp_l_preownership_id = (
    SELECT l.mp_l_preownership_id FROM mp_t_lot_sales s
    JOIN mp_t_lot_sales_line l ON l.mp_t_lot_sales_id = s.mp_t_lot_sales_id
    WHERE s.documentno = 'PR-I0031024' LIMIT 1);
```

### Step 3 — Return the lot to AVAILABLE
```sql
UPDATE mp_i_lot i
SET i.is_owned = 0, i.is_preowned = 0, i.is_reserved = 0, status_code = 'AVL', updated = 'IMS#16747'
WHERE i.mp_i_lot_id = (
    SELECT mp_i_lot_id FROM mp_l_preownership WHERE mp_l_preownership_id = (
        SELECT l.mp_l_preownership_id FROM mp_t_lot_sales s
        JOIN mp_t_lot_sales_line l ON l.mp_t_lot_sales_id = s.mp_t_lot_sales_id
        WHERE s.documentno = 'PR-I0031024' LIMIT 1));
```

### Step 4 — Cancel the LSP in the application
Run **Cancel LSP** for `PR-I0031024`. It should now pass both guards and post the full
reversal. If it stops on *not-contra / Debt Already Settled / accelerated / later LPT*,
resolve that prerequisite first.

### Step 5 — Fix the variance (run AFTER the cancel commits)
Reset the internal running total to the official figure — by formula, never hardcoded:
```sql
UPDATE mp_l_preownership p
SET p.total_sales = (
        SELECT COALESCE(SUM(t.amt_sales),0) FROM mp_l_preownership_threshold t
        WHERE t.mp_l_preownership_id = p.mp_l_preownership_id),
    p.is_paid = 0, p.updated = 'IMS#16747'
WHERE p.mp_l_preownership_id = 6643;
```

### Step 6 — Verify (must show OK before considering it done)
```sql
SELECT p.total_sales,
       COALESCE(SUM(t.amt_sales),0) AS official_figure,
       CASE WHEN p.total_sales - COALESCE(SUM(t.amt_sales),0)=0 THEN 'OK' ELSE 'VARIANCE' END AS status
FROM mp_l_preownership p
LEFT JOIN mp_l_preownership_threshold t ON t.mp_l_preownership_id = p.mp_l_preownership_id
WHERE p.mp_l_preownership_id = 6643 GROUP BY p.total_sales;

SELECT documentno, is_cancelled, docstatus FROM mp_t_lot_sales WHERE documentno IN ('PR-I0031024','PR-I0031024-CA');
SELECT is_owned, is_preowned, is_reserved, status_code FROM mp_i_lot WHERE mp_i_lot_id = 5529;
```
Expected: `status = OK`, the `-CA` reversal record exists, and the lot reads AVAILABLE.

### Rollback (undo on the replica, using the Step 0 saved values)
```sql
-- UPDATE mp_l_ownership    SET is_active=<orig>, mp_l_preownership_id=6643 WHERE mp_l_ownership_id=<saved_id>;
-- UPDATE mp_l_preownership SET is_owned=1, is_printed=<orig>, is_paid=<orig>, total_sales=<orig_total_sales> WHERE mp_l_preownership_id=6643;
-- UPDATE mp_i_lot          SET is_owned=1, is_preowned=<orig>, is_reserved=<orig>, status_code='<orig>', mp_i_lotstatus_id=<orig> WHERE mp_i_lot_id=5529;
-- (and reverse the application -CA cancellation if it committed)
```
