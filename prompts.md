## PROMPT 1 — Phase 1: Departments Foundation

```javascript
Read IMPLEMENTATION_PLAN.md §1 (PHASE 1 — Foundation: Departments) and implement it fully.

SCOPE — exactly these deliverables, nothing more:

1. MIGRATION — append to pos/admin/megration.sql as "-- PART 14: Departments":
   - CREATE TABLE rpos_departments exactly as specified in §1.1 (dept_id, dept_code UNIQUE,
     dept_name, dept_name_en, dept_type ENUM, parent_dept_id, clinic_id, cost_center_id,
     revenue_account_id, expense_account_id, location, phone, manager_staff_id, is_active,
     sort_order, created_at, updated_at + 3 indexes + 4 FKs). ENGINE=InnoDB CHARSET=utf8mb4.
   - ALTER rpos_clinics / rpos_staff to add dept_id, cost_center_id, revenue_account_id.
     IMPORTANT: the plan's §1.2 uses MariaDB-only "ADD COLUMN IF NOT EXISTS". Instead use the
     portable information_schema + PREPARE/EXECUTE guard form shown in §4.4. Do NOT use
     ADD COLUMN IF NOT EXISTS anywhere.
   - Idempotent INSERT IGNORE seed of the 9 departments in §1.3 (bilingual AR/EN names), and
     seed the two missing cost centers CC-RAD (الأشعة) and CC-IPD (التنويم).

2. NEW PAGE — pos/admin/departments.php
   Use pos/admin/clinics.php as the literal template: same header block (require config,
   checklogin, languages, partials/_head.php), same hero-KPI card row, same card grid,
   same Bootstrap 4.5 + Font Awesome markup. Do not invent new UI patterns.
   - Add / Edit / soft-Toggle / soft-Delete (delete refuses if children exist or clinics linked,
     otherwise sets is_active=0 — never hard DELETE)
   - Fields per §1.4 table
   - Validation: dept_code matches ^[A-Z0-9\-]{2,20}$ ; reject self-parenting; reject parent cycles
   - Hero KPIs: total, active, linked clinics, staff headcount, MTD revenue per dept
   - Hierarchy tree rendering via parent_dept_id
   - CSV export
   - SECURITY (mandatory, §3): all queries prepared statements with bind_param;
     CSRF token in $_SESSION['dept_csrf'] verified with hash_equals(); every echo through
     htmlspecialchars(); permission guard using the 'departments.php' page key.

3. NEW PAGE — pos/admin/ajax_department_loader.php
   Mirror pos/admin/ajax_service_loader.php structurally. Actions: list, list&type=,
   get&id=, and POST link_clinic. Respond with the {"success":true,"data":[...]} envelope.

4. INTEGRATIONS (§1.6) — minimal, additive:
   - partials/_sidebar.php: new "الأقسام والمراكز" group with departments.php, guarded by
     isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli,$admin->admin_id,'departments.php')
   - config/languages.php: add the 18 Phase-1 keys from §11 to BOTH $lang['en'] and $lang['ar']
   - clinics.php: add a dept_id <select> to add/edit forms + a department badge on each card
   - add_staff.php / update_staff.php / hrm.php: replace the free-text department input with a
     dept_id <select> fed by the ajax loader. KEEP the legacy text column and backfill it from
     the selected department name so nothing breaks.

HARD CONSTRAINTS:
- Match existing code style exactly. No new libraries, no frameworks, no composer packages.
- Additive changes only — do not refactor or rename anything existing.
- Do NOT touch anything in Phase 2, 3, or 4.

VERIFY BEFORE FINISHING:
- Run `php -l` on every new or modified PHP file and paste the output.
- Confirm no MariaDB-only syntax remains in my new section.
- Report the acceptance checklist from §1.7 with each item marked pass/fail.
- Do NOT commit or push. Show me the diff summary first.
```

---

## PROMPT 2 — Phase 1b: Clinic & Staff Integration *(optional split)*

```javascript
Phase 1 is done. Verify and finish only the integration half of it (§1.6).

1. Confirm rpos_departments exists and is seeded; confirm rpos_clinics and rpos_staff each
   have dept_id, cost_center_id, revenue_account_id.
2. clinics.php — verify the dept_id select persists on both add and edit, and that the
   department badge renders on the card grid in both AR and EN.
3. add_staff.php, update_staff.php, hrm.php — verify the department <select> loads from
   ajax_department_loader.php, persists dept_id, and still writes the legacy text column.
4. Backfill: write an idempotent SQL statement that fills rpos_staff.dept_id by matching the
   legacy free-text department column against rpos_departments.dept_name / dept_name_en.
   Add it as a commented, manually-run block at the end of PART 14 — do not auto-run it.
5. Regression check: open clinics.php, add_staff.php, update_staff.php, hrm.php and confirm
   each still loads with no PHP notice/warning with display_errors on.

Report results as a pass/fail table. Run php -l on every touched file. Do not commit.
```

---

## 📦 PROMPT 3 — Phase 2a: Radiology & Imaging

```javascript
Read IMPLEMENTATION_PLAN.md §2.1 (Radiology & Imaging) and implement it.

DELIVERABLES:
- pos/admin/radiology_management.php — full imaging lifecycle mirroring the existing
  pathology lab workflow. Reuse the same status progression and UI conventions as the lab
  pages already in this codebase; read them first with search/grep before writing anything.
- The radiology tables listed in §2.2/§9 — append to pos/admin/megration.sql as
  "-- PART 15: Radiology" using the portable information_schema PREPARE/EXECUTE guard.
  Never use ADD COLUMN IF NOT EXISTS.

FINANCIAL RULES (non-negotiable, §4):
- Only the Verified state posts to the ledger.
- Posting must go through createJournalEntry() wrapped in fin_transaction($mysqli, fn).
- Use ONLY fin_add / fin_sub / fin_mul / fin_div / fin_cmp / fin_round — never raw float math.
- Use a reference_type value OUTSIDE the trigger's exempt list so
  trg_journal_entry_idempotency blocks duplicate posting automatically. Verify this by
  re-running the posting action and confirming the second call does not create a new entry.
- Amounts come from the radiology revenue account resolved via revenue_account_id.

ALSO:
- Register the page in partials/_sidebar.php under a new "العمليات الطبية" group, permission-guarded.
- Add its i18n keys (subset of the 32 in §11) to BOTH languages.
- CSRF + prepared statements + htmlspecialchars everywhere.

VERIFY (§2.5):
- php -l output for all new/modified files.
- Radiology reaches Verified and the resulting journal entry is BALANCED (Σdebit == Σcredit
  to 4 decimals). Show the actual entry lines.
- Double-post attempt is blocked. Show the evidence.
- Mark each §2.5 checklist item pass/fail. Do not commit.
```

---

## 📦 PROMPT 4 — Phase 2b: Ward & Bed Management

```javascript
Read IMPLEMENTATION_PLAN.md §2.2 (Ward & Bed Management) and implement it.

DELIVERABLES:
- pos/admin/ward_bed_management.php — visual bed board + admission / transfer / discharge /
  bed-charge workflow.
- Ward, bed, admission, and charge tables (§2.2 / §9) appended to pos/admin/megration.sql as
  "-- PART 16: Ward & Beds", portable guarded ALTER form, no ADD COLUMN IF NOT EXISTS.

REQUIREMENTS:
- Bed board reflects status changes immediately (AJAX refresh consistent with existing AJAX pages).
- Occupancy percentage math via fin_div/fin_round — compute correctly at 0 beds (no divide-by-zero).
- Bed charges post once to the inpatient revenue account (dept IPD / cost center CC-IPD seeded
  in Phase 1). Post via createJournalEntry() inside fin_transaction(), idempotent by trigger.
- A bed cannot be double-assigned; admission transfer preserves history rather than overwriting.

VERIFY (§2.5, ward items):
- Occupancy math correct for a worked example — show the numbers.
- Bed charge posts exactly once on repeated runs — show evidence.
- php -l clean on all touched files. Checklist pass/fail. Do not commit.
```

---

## PROMPT 5 — Phase 2c: Insurance Pre-Authorization

```javascript
Read IMPLEMENTATION_PLAN.md §2.3 (Insurance Pre-Authorization) and implement it.

DELIVERABLES:
- pos/admin/insurance_preauthorization.php — request, approve, reject, expiry tracking.
- Pre-auth tables (§2.3 / §9) appended to megration.sql as "-- PART 17: Insurance Pre-Auth"
  (portable guarded ALTERs).

REQUIREMENTS:
- Status machine: Draft → Submitted → Approved / Rejected / Expired.
- HARD GATE: submitting a claim against an EXPIRED or unapproved pre-auth must be refused
  server-side, not just hidden in the UI. Enforce in PHP before any DB write.
- Expiry evaluated against a real date comparison; no reliance on a cron.
- Approved amount capped: claim amount cannot exceed approved amount.
- CSRF + prepared statements + authorization guard + bilingual labels.

VERIFY:
- Demonstrate that an expired pre-auth blocks claim submission and returns a clean message
  (no PHP notice). Show the code path and the test output.
- php -l clean. Checklist pass/fail. Do not commit.
```

---

## PROMPT 6 — Phase 2d: Patient Result Portal

```javascript
Read IMPLEMENTATION_PLAN.md §2.4 (Patient Result Portal) and implement it.

DELIVERABLES:
- pos/admin/patient_portal_tokens.php — staff-side token issuance and revocation.
- result_portal.php — PUBLIC page (no login). This is a new attack surface, so treat security
  as the primary requirement.
- Token table (§2.4 / §9) appended to megration.sql as "-- PART 18: Patient Portal".

SECURITY — ALL MANDATORY:
- Store ONLY a hash of the token (password_hash / hash('sha256', ...)). Never store the raw token.
- Compare with hash_equals() (constant-time). No == comparisons on tokens.
- Hard expiry enforced in SQL: WHERE expires_at > NOW(). Never "expire" in PHP after fetching.
- One-time / bounded use: record use_count and invalidate after the configured limit.
- An invalid, expired, or unknown token must expose NOTHING — no patient name, no test names,
  no error detail that reveals whether the token ever existed. Return the same generic page.
- No enumerable IDs in URLs. No sequential integers. No patient_id in the query string.
- Rate-limit repeated failed token attempts per IP/session.
- The portal shows results READ-ONLY; no writes of any kind.
- Force no-cache headers so results are not left in a shared browser cache.

VERIFY (§2.5, portal item):
- Show evidence that an invalid token renders only the generic page with zero patient data
  leaked in the HTML.
- Show that an expired token is rejected by the SQL predicate.
- php -l clean. Checklist pass/fail. Do not commit.
```

---

## PROMPT 7 — Phase 3a: Financial Reports

```javascript
Read IMPLEMENTATION_PLAN.md §3.1 (Financial Reports) and implement it.
Open pos/admin/financial_helpers.php and pos/admin/config/financial_helpers.php FIRST and use
their existing fin_* API. Zero new math primitives.

DELIVERABLES:
- pos/admin/financial_reports.php — Trial Balance, Balance Sheet, Income Statement (multi-step),
  Cash Flow (indirect), General Ledger, AR Aging, AP Aging, Cost-Center report.
- pos/admin/print_financial_report.php — follows the existing print_profits.php /
  print_order_report.php pattern: company header, title, filter summary, signature block, A4 CSS.

RULES:
- Only status = 'Posted' journal entries are ever included.
- Filters on all reports: fiscal year / fiscal period, date range, cost centre, department
  (dept_id from Phase 1), currency with exchange-rate translation.
- General Ledger running balance recomputed in BCMath as it is built.
- Aging buckets: 0-30 / 31-60 / 61-90 / 90+ days.
- All aggregation math through fin_* helpers. No float arithmetic anywhere.

VERIFY (§3.8):
- Trial Balance: Σdebit - Σcredit must equal exactly 0.0000 for every period tested. Show it.
- Balance Sheet: Assets == Liabilities + Equity, proven in BCMath. Show it.
- Cash Flow reconciles to the change in cash accounts. Show it.
- php -l clean. Checklist pass/fail. Do not commit.
```

---

## PROMPT 8 — Phase 3b: Bank Reconciliation

```javascript
Read IMPLEMENTATION_PLAN.md §3.2 (Bank Reconciliation) and implement it.

DELIVERABLES:
- pos/admin/bank_reconciliation.php — activates the dormant rpos_bank_statements and
  rpos_bank_statement_lines tables. No schema change should be needed; inspect the existing
  columns first and report what you found.

FEATURES:
- Statement header create: account, statement date, opening balance, closing balance.
- CSV import of statement lines mapping date / reference / description / debit / credit.
  Validate row count, reject malformed rows with a clear per-row message, and never partially
  import silently — wrap the import in a transaction.
- Auto-match engine in this priority order:
  1) exact debit/credit + date ±1 day
  2) reference string against rpos_journal_entries.reference_id
  3) amount-only match, flagged for manual confirmation
- Manual match / unmatch via matched_journal_item_id.
- Unreconciled difference computed in BCMath across the whole statement.
- Closing locks the statement: is_reconciled = 1, reconciled_by, reconciled_at.
- Reconciliation summary print-out.

INTEGRITY (critical): reconciliation must NEVER mutate ledger entries. It only sets
matched_journal_item_id and statement flags. Any discrepancy is corrected by a proper
createJournalEntry() adjustment or a reversal entry — never by editing history.

VERIFY:
- For a test statement, drive the unreconciled difference to exactly 0.0000. Show the arithmetic.
- Confirm no UPDATE statement in the file targets any journal or ledger table.
- php -l clean. Checklist pass/fail. Do not commit.
```

---

## PROMPT 9 — Phase 3c: Currency Management

```javascript
Read IMPLEMENTATION_PLAN.md §3.3 (Currency Management) and implement it.

DELIVERABLES:
- pos/admin/currency_management.php — activates rpos_currencies and rpos_exchange_rates.
- Add fin_get_rate(mysqli $mysqli, string $from, string $to, string $date): string to
  pos/admin/config/financial_helpers.php (non-invasive append). It resolves the latest
  effective_date <= $date and returns '1.000000' for same-currency.

FEATURES:
- Currency CRUD: ISO-3 code, AR/EN name, symbol, is_base, is_active.
- Exchange-rate entry with effective_date and full history display.
- Revaluation of foreign-currency balances: compute the delta and post an adjustment entry
  through createJournalEntry() inside fin_transaction() — gain to revenue, loss to expense.

GUARDS:
- Exactly ONE base currency. Enforce inside a transaction so a concurrent request cannot
  produce two base currencies. Demonstrate the enforcement.
- Rate lookup must be deterministic when two rates share an effective_date — pick a defined
  tiebreak and document it in a comment.
- All rate arithmetic through fin_* helpers.

VERIFY:
- Show that fin_get_rate returns the correct historical rate for a date between two rate rows.
- Show that setting a second base currency is rejected.
- php -l clean. Checklist pass/fail. Do not commit.
```

---

## PROMPT 10 — Phase 3d: Department Profitability

```javascript
Read IMPLEMENTATION_PLAN.md §3.4 (Department Profitability) and implement it.
This report is the payoff for Phase 1 and depends on rpos_departments, clinics.dept_id,
cost_center_id, and revenue_account_id all being populated.

DELIVERABLES:
- pos/admin/department_profitability.php — per-department revenue, direct expense, allocated
  expense, gross margin, net contribution, and margin percentage.

RULES:
- Only Posted entries.
- Expense allocation across departments must use a stated, documented basis (e.g. proportional
  to revenue or to headcount) and the basis must be visible on screen so the number is auditable.
- All math via fin_* helpers. Margin percentage computed with fin_div guarded against a zero
  denominator.
- Drill-down from a department row into the underlying journal items.

VERIFY (§3.8):
- PROVE the sum of all department revenue equals total revenue and the sum of all department
  expense equals total expense. This is the acceptance gate — show the two totals side by side.
- Report any department with unlinked clinics/staff as a data-quality warning rather than
  silently dropping it from the totals.
- php -l clean. Do not commit.
```

---

## PROMPT 11 — Phase 3e: Fixed Assets

```javascript
Read IMPLEMENTATION_PLAN.md §3.5 (Fixed Assets) and implement it.

DELIVERABLES:
- pos/admin/fixed_assets.php — asset register, acquisition, depreciation runs, disposal.
- Asset and depreciation tables (§3.5 / §9) appended to megration.sql as the next PART,
  portable guarded ALTERs only.

REQUIREMENTS:
- Asset register: code, name, category, acquisition date, cost, salvage value, useful life,
  method (straight-line at minimum), department, cost centre, status.
- Depreciation run posts balanced journal entries via createJournalEntry() inside
  fin_transaction(). It must be IDEMPOTENT per period — re-running a period must not post twice.
  Enforce with a per-asset-per-period uniqueness constraint plus the idempotency trigger.
- Disposal computes and posts the correct gain or loss against book value.
- Accumulated depreciation never exceeds depreciable base; final period true-up so book value
  lands exactly on salvage value with no rounding residue.
- All math via fin_* helpers.

VERIFY (§3.8):
- Run depreciation twice for the same period and show the second run creating no entries.
- Show the disposal gain/loss calculation for a worked example, including the final-period
  true-up case.
- php -l clean. Do not commit.
```

---

## PROMPT 12 — Phase 3f: Accounts Payable / Vendor Bills

```javascript
Read IMPLEMENTATION_PLAN.md §3.6 (Accounts Payable / Vendor Bills) and implement it.

DELIVERABLES:
- pos/admin/vendor_bills.php — vendor bill entry, payment application, and a THREE-WAY MATCH
  against purchase order and goods receipt where those records exist.
- Vendor bill / payment tables (§3.6 / §9) appended to megration.sql as the next PART.

REQUIREMENTS:
- Three-way match must FLAG mismatches explicitly (quantity mismatch, price mismatch, no
  matching GRN) rather than blocking or silently passing. Show the mismatch reason on screen.
- Partial payments supported; bill status derived from paid vs total in BCMath
  (Unpaid / Partially Paid / Paid). Never rely on float comparison for the settled state.
- Payment posting via createJournalEntry() inside fin_transaction(), idempotent.
- Overpayment rejected.
- AP aging on this page must reconcile with the AP Aging figure in financial_reports.php.

VERIFY (§3.8):
- Construct a deliberate mismatch and show it being flagged with the reason.
- Show a partial payment sequence and the resulting status transitions, with the BCMath
  comparison proving the Paid state.
- php -l clean. Do not commit.
```

---

## 📦 PROMPT 13 — Phase 3g: Budgeting & Variance

```javascript
Read IMPLEMENTATION_PLAN.md §3.7 (Budgeting & Variance) and implement it.

DELIVERABLES:
- pos/admin/financial_budget.php — budget definition by fiscal period / account / department /
  cost centre, plus Actual vs Budget vs Variance reporting.
- Budget tables (§3.7 / §9) appended to megration.sql as the next PART.

REQUIREMENTS:
- Budget entry with a per-row uniqueness constraint on (fiscal period, account, dept/cost centre)
  so the same line cannot be budgeted twice.
- Variance = Actual - Budget, and Variance % = Variance / Budget guarded against a zero budget.
  All via fin_* helpers.
- Favorable/unfavorable direction depends on account type: more revenue is favorable, more
  expense is unfavorable. Encode this explicitly and label it on screen.
- Actuals come only from Posted entries and must reuse the same query logic as
  financial_reports.php so the two pages never disagree.

VERIFY (§3.8):
- Take one account and one period, compute variance by hand, and show the page agreeing exactly.
- Show the zero-budget case rendering without a division error.
- php -l clean. Do not commit.
```

---

## PROMPT 14 — Phase 4: Integration & Hardening

```javascript
Read IMPLEMENTATION_PLAN.md §4 (PHASE 4 — Integration & Hardening) and implement it.

SCOPE:
1. §4.1 — Register the three new sidebar groups in partials/_sidebar.php before the closing
   </ul>, each item permission-guarded with the isSuperAdmin() || userHasPagePermission()
   pattern, using sidebar_active() and the existing icon/color classes:
     🏢 الأقسام والمراكز   → departments.php
      العمليات الطبية     → radiology_management.php, ward_bed_management.php,
                              insurance_preauthorization.php, patient_portal_tokens.php
     💰 المالية المتقدمة    → financial_reports.php, bank_reconciliation.php,
                              currency_management.php, department_profitability.php,
                              fixed_assets.php, vendor_bills.php, financial_budget.php
   Add __() i18n keys for every group label.

2. §4.2 — RBAC verification (no code change expected, since permissions enumerate pages
   dynamically). Confirm each new page appears in the matrix, super-admin bypasses all guards,
   and unprivileged users get a clean denial with no PHP notice.

3. §4.3 + §4.6 — Write pos/admin/tests/i18n_parity.php asserting the array_diff between
   $lang['en'] and $lang['ar'] keys is empty. Run it and fix any missing keys in BOTH directions.

4. §4.6 — Write pos/admin/tests/financial_smoke.php asserting: Trial Balance balances to
   0.0000, the Balance Sheet identity holds, and there are no orphan journal items
   (items with no parent entry). Run it.

5. §4.4 (gap G-15) — Apply the portable information_schema guard style retroactively to
   PARTS 1-5 of megration.sql. This must be BEHAVIOUR-PRESERVING: the resulting DDL must be
   identical on a MariaDB server. Re-read the file first and change only the guard mechanism.

6. §4.5 (gap G-16) — Add display-only fin_format($amount, $currency, $decimals) and
   fin_currency_symbol(mysqli $mysqli, string $code) to pos/admin/config/financial_helpers.php.
   These must never alter stored values.

7. §4.6 — Extend pos/admin/system_check.php with existence checks for all 19 new tables.

8. §4.7 — Update README.md: add a Key Modules row per new module, add a "What's New" section
   covering the 4 phases, and document the new permission keys.

FINALLY:
- Run php -l across every new and modified PHP file, and report the full result.
- Run both test scripts and paste their output.
- Produce the §Definition of Done checklist with every item marked pass/fail.
- Show a full `git status --short` and a diff stat.

Do NOT commit yet — present everything for review first.
```

---

## PROMPT 15 — Final: Commit & Push

```javascript
All four phases are implemented and verified. Commit and push to origin/main.

1. Run php -l on every touched file one final time.
2. Run pos/admin/tests/i18n_parity.php and pos/admin/tests/financial_smoke.php.
3. git status --short and review that only intended files are staged.
4. Stage with git add, then commit using a conventional message, for example:
   "Add departments, medical modules, and financial expansion (Phases 1-4)"
   with a body listing the new pages, new tables, and migration parts added.
5. git fetch origin, then push to origin/main. If the push is rejected, rebase onto
   origin/main (do not merge), resolve any conflicts, and push again.
6. Verify with git status --short --branch and git --no-pager log --oneline -3 that local
   main and origin/main point to the same commit.
```
