# 📋 Implementation Plan — New Features, Departments & Financial Expansion

> **Project:** Al-Wahat HIS & LIMS — `msimoo/his-lab-system`
> **Document type:** Feature & Schema Roadmap
> **Branch target:** `main`
> **Status:** 📝 DRAFT — awaiting approval before coding
> **Created:** 2026-09-16

---

## 📑 Table of Contents

1. [Executive Summary](#-executive-summary)
2. [Context Gathered (Codebase Audit)](#-context-gathered-codebase-audit)
3. [Architectural Conventions To Follow](#-architectural-conventions-to-follow)
4. [Critical Gaps Identified](#-critical-gaps-identified)
5. [Phase 1 — Foundation: Departments](#-phase-1--foundation-departments)
6. [Phase 2 — New Medical & Operational Features](#-phase-2--new-medical--operational-features)
7. [Phase 3 — Financial Expansion](#-phase-3--financial-expansion)
8. [Phase 4 — Integration & Hardening](#-phase-4--integration--hardening)
9. [Full Database Schema Additions](#-full-database-schema-additions)
10. [Sidebar Navigation Map](#-sidebar-navigation-map)
11. [i18n Key Registry](#-i18n-key-registry)
12. [Risk Register & Mitigations](#-risk-register--mitigations)
13. [Deliverables Summary](#-deliverables-summary)
14. [Delivery Order & Acceptance Criteria](#-delivery-order--acceptance-criteria)

---

## 🎯 Executive Summary

This plan introduces **three capability layers** into Al-Wahat HIS & LIMS:

| Layer | Purpose | Pages | Tables |
|---|---|---|---|
| 🏢 **Departments** | Introduce a real organizational hierarchy (`rpos_departments`) that groups clinics, staff, cost centers, and revenue accounts — the current system has **no department entity at all** | 2 | 1 (+2 ALTERs) |
| 🩺 **New Features** | Radiology/Imaging, Ward & Bed management, Insurance pre-authorization, Patient result portal | 5 | 11 |
| 💰 **Financial Expansion** | Formal financial statements, bank reconciliation, multi-currency UI, fixed assets, accounts payable, budgeting, department profitability | 8 | 7 |

**Total:** 15 new pages · 19 new tables · ~6 shared files updated · fully bilingual (AR/EN) · full RBAC integration.

**Guiding principle:** *Zero new primitives.* Every financial computation reuses the existing BCMath-based `financial_helpers.php` API (`fin_add`, `fin_sub`, `fin_mul`, `fin_div`, `fin_cmp`, `fin_round`, `fin_transaction`, `createJournalEntry`). No module writes to the ledger directly.

---

##  Context Gathered (Codebase Audit)

### Technology Stack (verified)

| Component | Version | Evidence |
|---|---|---|
| PHP | 7.4 / 8.0 / 8.2 | `README.md` badges; `mysqli` + OOP `$mysqli->query()` |
| MySQL / MariaDB | 5.7 / 8.0 | `initialize_financial_db.php`, `megration.sql` |
| Frontend | Bootstrap 4.5 + jQuery 3.5+ | `assets/css/argon.css`, `assets/js/*` |
| Icons | Font Awesome + Nucleo | `fas fa-*` classes throughout |
| Fonts | Tajawal (RTL support) | `assets/fonts/Tajawal-*.ttf` |
| Math | BCMath | `fin_dec()` wraps `bcadd()` |
| Charts | Chart.js | `finance_dashboard.php` |

### Existing Directory Layout

```
www/
├── index.php                     # entry point
├── check_login.php               # login gate (new, committed)
├── session.php / session_init.php
├── style.css / js_main.js / js_check.js
├── README.md
└── pos/admin/                    # ← all application pages live here
    ├── config/
    │   ├── config.php            # DB connection ($mysqli)
    │   ├── checklogin.php        # check_login()
    │   ├── languages.php         # $lang['en'|'ar'][ key ] — 1389 lines
    │   ├── financial_helpers.php # legacy + wrapper helper
    │   ── ...
    ├── financial_helpers.php     # ★ v2.0 institutional-grade core (934 lines)
    ├── initialize_financial_db.php
    ├── megration.sql             # ★ master migration (261 lines)
    ├── financial_settings.php    # ★ settings registry (chart role mapping)
    ├── financial_console.php     # ★ 2058-line control console
    ├── finance_dashboard.php
    ├── financial_analytics.php
    ├── clinics.php               # ★ model for CRUD page pattern
    ├── partials/
    │   ├── _head.php             # <head> + CSS vars
    │   ├── _sidebar.php          # ★ 1042-line navigation registry
    │   ├── _topnav.php
    │   ├── _navbar_enhanced.php  # theme toggle navbar
    │   ├── _footer.php
    │   ├── _scripts.php
    │   └── _analytics.php
    ── ... (≈180 page files)
```

### Financial Core Capabilities (already implemented — DO NOT REBUILD)

`pos/admin/financial_helpers.php` — header states *"Institutional Grade"*:

| Feature | Function | Status |
|---|---|---|
| Decimal math (no float drift) | `fin_dec/add/sub/mul/div/cmp/round` | ✅ |
| Transaction safety w/ savepoints | `fin_transaction($mysqli, callable)` | ✅ |
| Idempotent journal posting | `createJournalEntry()` + DB trigger `trg_journal_entry_idempotency` | ✅ |
| Reversal entries (IFRS) | `SECTION` in helpers + `reversal_of` columns | ✅ |
| Fiscal period validation | `rpos_fiscal_periods` | ✅ |
| Cost centers | `rpos_cost_centers` (7 seeded: OPD, LAB, PHARM, ER, ADMIN, FIN, HR) | ✅ |
| Multi-currency | `rpos_currencies` (SDG/USD/EUR/SAR/AED) + `rpos_exchange_rates` | ✅ |
| Bank statements | `rpos_bank_statements` + `rpos_bank_statement_lines` | ✅ (schema only) |
| Audit logging | `rpos_financial_audit_log` (JSON old/new values) | ✅ |
| Fiscal year closing → retained earnings | `fin_close_fiscal_year()` | ✅ |
| Deadlock-safe locking | Canonical account ordering + `FIN_MAX_RETRY = 3` | ✅ |
| Round-off account | `5099 فروق التقريب` | ✅ |
| Retained earnings account | `3001 الأرباح المحتجزة` | ✅ |

**Key insight:** Phases in this plan are largely **UI over existing schema** for financial items. The heavy lifting is already done — the gap is *presentation, workflows, and department modeling*.

---
## 🏗️ Architectural Conventions To Follow

> These are **mandatory** — every new file must match these patterns exactly so the codebase stays coherent.

### 1. Page Header Contract (every new page)

```php
<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include('config/languages.php');
```

### 2. Page Body Structure Contract (modelled on `clinics.php`)

```php
/* ============================================================
   BACKEND LOGIC — POST handlers first
   ============================================================ */
if (isset($_POST['add_x'])) { /* prepared statement + bind_param */ }

/* ============================================================
   VIEW DATA (read-only helpers)
   ============================================================ */
$items = [];
$res = $mysqli->query("SELECT ...");
if ($res) { while ($row = $res->fetch_assoc()) { $items[] = $row; } }

require_once('partials/_head.php');
?>
<style>/* page-scoped theme tokens */</style>
<!-- markup -->
<?php require_once('partials/_footer.php'); ?>
```

### 3. Security Contract

| Concern | Required approach |
|---|---|
| SQL injection | `$mysqli->prepare()` + `bind_param()` for **all** writes; `intval()` cast for integer IDs |
| CSRF | `$_SESSION['<page>_csrf'] = bin2hex(random_bytes(32));` + hidden input + `hash_equals()` verify |
| Auth | `check_login()` at top of **every** page |
| Authorization | `isSuperAdmin($admin_id) \|\| userHasPagePermission($mysqli, $admin_id, '<file>.php')` |
| Output escaping | `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')` |
| Money | BCMath only — **never** plain `+` / `-` / `*` on currency |

### 4. Financial Contract (Phase 3 pages)

```php
include_once('config/financial_helpers.php');

// ALWAYS wrap multi-table writes:
fin_transaction($mysqli, function() use ($mysqli) { /* ... */ });

// ALWAYS post via the canonical entry point:
createJournalEntry($mysqli, $description, $reference_type, $reference_id, $items, $date, $user_id);

// ALWAYS resolve accounts via settings (never hardcode account_id):
$account_id = fin_get_default_account($mysqli, 'default_account_clinic_revenue');

// NEVER use float arithmetic on amounts:
$total = fin_add($line1, $line2, FIN_SCALE);   // OK
$total = $line1 + $line2;                       // FORBIDDEN
```

### 5. Migration Contract (`megration.sql` — append only)

- New tables: `CREATE TABLE IF NOT EXISTS` — always `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`
- New columns: guarded via `information_schema.columns` (MySQL 8 safe — see Risk R-1)
- New indexes: guarded via `information_schema.statistics` + `PREPARE / EXECUTE / DEALLOCATE`
- Seed data: `INSERT IGNORE INTO`
- Numbered `-- PART n:` banners matching the existing style
- **Append only** — never edit earlier parts that may have already run in production

### 6. Design System Tokens (from `_sidebar.php` / `_head.php`)

| Token | Value | Use |
|---|---|---|
| `--accent` | `#0891b2` | primary actions |
| violet | `#8b5cf6` | analytics / dashboards |
| sky | `#06b6d4` | secondary charts |
| amber | `#f59e0b` | settings / warnings |
| emerald | `#10b981` | revenue / success |
| rose | `#f43f5e` | expense / danger |
| `--bg-sidebar` | dark surface | nav shell |
| Radius | `12px` (nav), `16px` (cards), `20px` (hero) | consistent rounding |
| Icon convention | `icon-violet`, `icon-sky`, `icon-amber`, `icon-teal` | sidebar icon colouring |

### 7. Bilingual Contract

Every user-visible string must be either an Arabic literal (existing sidebar habit) or `__('snake_case_key')` with entries added to **both** `$lang['en']` and `$lang['ar']`. New keys are listed in §11.

##  Critical Gaps Identified

| # | Gap | Impact | Addressed In |
|---|---|---|---|
| **G-1** | **No `rpos_departments` table.** `department` exists only as *free text* in `add_staff.php` and `hrm.php`. `rpos_clinics` has no department FK. | Cannot report revenue/expense by department; organizational hierarchy is unmodellable; cost allocation impossible | **Phase 1** |
| **G-2** | `rpos_clinics` lacks `dept_id`, `cost_center_id`, `revenue_account_id` | Clinic revenue cannot map to a ledger account automatically; all clinics fall back to one global revenue account | **Phase 1** |
| **G-3** | `rpos_staff` has no `dept_id` | HR headcount-by-department reporting impossible | **Phase 1** |
| **G-4** | Radiology/Imaging has **no module** — `lab_management.php` covers pathology only | Imaging handled out-of-band; no revenue capture, no report archive | **Phase 2** |
| **G-5** | `inpatient_management.php` exists but there is **no bed/ward inventory** | Manual bed tracking; no occupancy KPIs; admission cannot validate availability | **Phase 2** |
| **G-6** | No insurance **pre-authorization** step before `insurance_claims.php` | Claims rejected for missing pre-auth; no approval trail | **Phase 2** |
| **G-7** | Patients cannot access their own lab results | Front-desk burden; no self-service | **Phase 2** |
| **G-8** | `rpos_bank_statements` schema exists but **no UI** | Bank reconciliation is manual/unused | **Phase 3** |
| **G-9** | `rpos_currencies` / `rpos_exchange_rates` exist but **no UI** | Multi-currency is inert despite `exchange_rate` columns everywhere | **Phase 3** |
| **G-10** | No **formal financial statements** (Trial Balance, Balance Sheet, Cash Flow) — dashboards only | Cannot produce auditable statements | **Phase 3** |
| **G-11** | No **fixed asset register** or depreciation engine | Assets expensed immediately; accumulated-depreciation account absent | **Phase 3** |
| **G-12** | No **accounts payable / vendor bills** module (only `supplier_account.php` statement view) | AP aging impossible; no 3-way match against GRN | **Phase 3** |
| **G-13** | No **budgeting** capability | No variance analysis; no spend control | **Phase 3** |
| **G-14** | Cost centers are seeded but **unused** — no UI, nothing populates `cost_center_id` | Department profitability impossible | **Phase 1 + 3** |
| **G-15** | `megration.sql` uses MariaDB-only `ADD COLUMN IF NOT EXISTS` | May silently fail on MySQL 5.7/8.0 strict | **Phase 4** |
| **G-16** | No shared currency/number formatting helper for display | Each page formats money ad hoc | **Phase 4** |

## 🏢 PHASE 1 — Foundation: Departments

> **Goal:** Introduce a real organizational hierarchy that groups clinics, staff, cost centers, and revenue accounts — unlocking departmental P&L in Phase 3.
> **Prerequisite for:** Phase 3 `department_profitability.php`.

### 1.1 New Table — `rpos_departments`

```sql
CREATE TABLE IF NOT EXISTS rpos_departments (
    dept_id            INT AUTO_INCREMENT PRIMARY KEY,
    dept_code          VARCHAR(20)  NOT NULL UNIQUE,
    dept_name          VARCHAR(120) NOT NULL,
    dept_name_en       VARCHAR(120) NULL,
    dept_type          ENUM('Clinical','Diagnostic','Pharmacy','Admin','Support')
                       NOT NULL DEFAULT 'Clinical',
    parent_dept_id     INT NULL,
    clinic_id          INT NULL,
    cost_center_id     INT NULL,
    revenue_account_id INT NULL,
    expense_account_id INT NULL,
    location           VARCHAR(120) NULL,
    phone              VARCHAR(30)  NULL,
    manager_staff_id   INT NULL,
    is_active          TINYINT(1) NOT NULL DEFAULT 1,
    sort_order         INT NOT NULL DEFAULT 0,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                       ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_parent (parent_dept_id),
    INDEX idx_type   (dept_type),
    INDEX idx_active (is_active),
    FOREIGN KEY (parent_dept_id)     REFERENCES rpos_departments(dept_id)          ON DELETE SET NULL,
    FOREIGN KEY (cost_center_id)     REFERENCES rpos_cost_centers(cost_center_id)  ON DELETE SET NULL,
    FOREIGN KEY (revenue_account_id) REFERENCES rpos_accounts(account_id)          ON DELETE SET NULL,
    FOREIGN KEY (expense_account_id) REFERENCES rpos_accounts(account_id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 1.2 ALTERs to existing tables

```sql
ALTER TABLE rpos_clinics ADD COLUMN IF NOT EXISTS dept_id INT NULL;
ALTER TABLE rpos_staff   ADD COLUMN IF NOT EXISTS dept_id INT NULL;
ALTER TABLE rpos_clinics ADD COLUMN IF NOT EXISTS cost_center_id INT NULL;
ALTER TABLE rpos_clinics ADD COLUMN IF NOT EXISTS revenue_account_id INT NULL;
```

> MySQL-8-safe guarded form (via `information_schema.columns`) is given in §9.

### 1.3 Seed Data (idempotent, bilingual)

| code | AR name | EN name | type | cost center |
|---|---|---|---|---|
| `OPD` | العيادات الخارجية | Outpatient Clinics | Clinical | CC-OPD |
| `LAB` | المختبر | Laboratory | Diagnostic | CC-LAB |
| `RAD` | الأشعة والتصوير | Radiology & Imaging | Diagnostic | CC-RAD *(new)* |
| `PHARM` | الصيدلية | Pharmacy | Pharmacy | CC-PHARM |
| `ER` | الطوارئ | Emergency | Clinical | CC-ER |
| `IPD` | قسم التنويم | Inpatient Ward | Clinical | CC-IPD *(new)* |
| `ADMIN` | الإدارة | Administration | Admin | CC-ADMIN |
| `FIN` | المالية | Finance | Admin | CC-FIN |
| `HR` | الموارد البشرية | Human Resources | Support | CC-HR |

> Uses `INSERT IGNORE` so re-running is safe. Also seeds the two missing cost centers `CC-RAD` and `CC-IPD`.

### 1.4 New Page — `pos/admin/departments.php`

| Aspect | Detail |
|---|---|
| **Pattern source** | `clinics.php` (CRUD + hero stats + card grid) |
| **Add** | `dept_code`, `dept_name`, `dept_name_en`, `dept_type`, `parent_dept_id`, `clinic_id`, `cost_center_id`, `revenue_account_id`, `location`, `phone`, `manager_staff_id` |
| **Update** | all of the above by `dept_id` |
| **Toggle** | `is_active` flip (soft toggle instead of hard delete) |
| **Delete** | soft — refuses if children exist or clinics are linked; otherwise `is_active = 0` |
| **Validation** | `dept_code` matches `^[A-Z0-9\-]{2,20}$`; reject self-parenting; reject cycles |
| **Hero KPIs** | Total departments · Active · Linked clinics · Staff headcount · MTD revenue per dept |
| **Extras** | Hierarchy tree via `parent_dept_id`; `sort_order` numeric input; CSV export |

**Guards:**
- POST: prepared statements + `bind_param` for every write
- CSRF: `$_SESSION['dept_csrf']` + `hash_equals()`
- Authorization: `departments.php` permission key
- All output escaped with `htmlspecialchars()`

### 1.5 New Page — `pos/admin/ajax_department_loader.php`

Feeds department dropdowns to other pages (mirrors `ajax_service_loader.php`).

```
GET  ?action=list               → all active departments (JSON)
GET  ?action=list&type=Clinical → filtered by dept_type
GET  ?action=get&id=5           → single department detail
POST ?action=link_clinic        → attach clinic_id to dept_id
```

Response envelope (matches existing AJAX pages):

```json
{ "success": true, "data": [ { "dept_id": 1, "dept_code": "LAB", "dept_name": "المختبر" } ] }
```

### 1.6 Integrations in Phase 1

| File | Change |
|---|---|
| `partials/_sidebar.php` | New «الأقسام والمراكز» group containing `departments.php` (permission-guarded) |
| `config/languages.php` | +18 keys × 2 languages (see §11) |
| `clinics.php` | Add `dept_id` select to add/edit forms; show department badge on each clinic card |
| `add_staff.php`, `update_staff.php`, `hrm.php` | Replace free-text `department` input with a real `dept_id` `<select>`; keep legacy column and backfill |
| `megration.sql` | Append `-- PART 14: Departments` |

### 1.7 Phase 1 Acceptance Criteria

- [ ] `rpos_departments` created with FKs; seeded with 9 departments + 2 new cost centers
- [ ] `departments.php` supports add / edit / toggle / soft-delete with CSRF + prepared statements
- [ ] Parent-child hierarchy renders correctly and rejects cycles
- [ ] Clinics can be linked to a department from both `clinics.php` and `departments.php`
- [ ] Sidebar entry visible only with permission; super-admin always sees it
- [ ] All labels render correctly in Arabic (RTL) and English (LTR)

## 🩺 PHASE 2 — New Medical & Operational Features

> **Goal:** Close the operational gaps (G-4 → G-7) with four new workflow modules.
> **Depends on:** Phase 1 (`rpos_departments` supplies `dept_id` and cost-center tagging).

### 2.1 Radiology & Imaging — `pos/admin/radiology_management.php`

**Purpose:** full imaging lifecycle mirroring the pathology lab workflow.

```
Order (doctor) → Pre-authorize (insurance) → Schedule → Arrive
→ Perform (modality) → Report (radiologist) → Verify → Print/Send
```

**New tables:**

| Table | Purpose |
|---|---|
| `rpos_imaging_types` | Modality catalogue: `code`, `name_ar`, `name_en`, `modality` (X-Ray/US/CT/MRI/Mammo/Fluoroscopy), `body_part`, `base_price`, `tariff_price`, `prep_instructions`, `is_active` |
| `rpos_imaging_requests` | The order: `patient_id`, `doctor_id`, `imaging_type_id`, `dept_id`, `status`, `priority`, `clinical_notes`, `scheduled_at`, `performed_at`, `reported_at`, `verified_by`, `shift_id`, `cost_center_id`, `total_amount`, `insurance_claim_id` |
| `rpos_imaging_reports` | `request_id`, `findings`, `impression`, `recommendation`, `radiologist_id`, `verified_at`, `attachment_path` |

**Status ENUM:** `Requested` → `PreAuthorized` → `Scheduled` → `Arrived` → `Performed` → `Reported` → `Verified` → `Cancelled`

**Financial hook:** on `Verified`, post revenue via the existing helper:

```php
createJournalEntry($mysqli, "إيراد أشعة - {$type['name_ar']}", 'ImagingRequest',
    $request_id, [
        ['account_id' => $cash_or_ar_account,      'debit'  => $amount, 'credit' => 0],
        ['account_id' => $imaging_revenue_account, 'debit'  => 0, 'credit' => $amount],
    ], $date, $admin_id);
```

> `reference_type = 'ImagingRequest'` is **not** in the trigger's exempt list, so the existing `trg_journal_entry_idempotency` trigger protects against double-posting automatically.

**Page features:** worklist by status · modality calendar · report entry with templates · print via `print_imaging_report.php` · monthly volume + revenue KPIs.

---

### 2.2 Ward & Bed Management — `pos/admin/ward_bed_management.php`

**Purpose:** real-time bed census board feeding `inpatient_management.php`.

**New tables:**

| Table | Purpose |
|---|---|
| `rpos_wards` | `ward_code`, `ward_name`, `dept_id`, `ward_type` (General/ICU/Pediatric/Maternity/Isolation/Surgical), `floor`, `daily_charge`, `is_active` |
| `rpos_rooms` | `ward_id`, `room_number`, `room_type` (Single/Double/Ward/Isolation), `capacity`, `has_bathroom`, `is_active` |
| `rpos_beds` | `room_id`, `bed_code`, `bed_status` (Available/Occupied/Reserved/Cleaning/Maintenance/Blocked), `current_patient_id`, `current_admission_id` |
| `rpos_bed_assignments` | Historic trail: `bed_id`, `patient_id`, `admission_id`, `assigned_at`, `released_at`, `assigned_by`, `reason` (Admission/Transfer/Discharge) |

**Page features:**
- Visual bed grid coloured by status using the existing design tokens
- Occupancy % per ward, per ward-type, hospital-wide
- Assign / transfer / discharge actions with automatic daily-charge accrual
- Isolation & gender-mismatch warnings on assignment
- Print-friendly census sheet

**Financial hook:** nightly accrual (or on discharge) posts bed charges:

```php
createJournalEntry($mysqli, "رسوم تنويم - {$ward['ward_name']}", 'BedCharge',
    $assignment_id, [
        ['account_id' => $patient_ar,        'debit'  => $charge, 'credit' => 0],
        ['account_id' => $inpatient_revenue, 'debit'  => 0, 'credit' => $charge],

### 2.3 Insurance Pre-Authorization — `pos/admin/insurance_preauthorization.php`

**Purpose:** approval workflow sitting *before* `insurance_claims.php` (gap G-6).

**New table — `rpos_preauth_requests`:**

| Column | Notes |
|---|---|
| `preauth_id` | PK |
| `patient_id`, `policy_id`, `insurance_company_id` | links into existing insurance tables |
| `request_type` | `Lab` / `Imaging` / `Admission` / `Procedure` / `Pharmacy` |
| `reference_type`, `reference_id` | polymorphic link to the source order |
| `estimated_amount` | `DECIMAL(19,4)` — BCMath compatible |
| `approved_amount` | nullable until approved |
| `status` | `Draft` / `Submitted` / `Approved` / `PartiallyApproved` / `Rejected` / `Expired` |
| `auth_code` | payer reference number |
| `valid_from`, `valid_to` | authorization window |
| `submitted_by`, `submitted_at`, `responded_at` | audit trail |
| `rejection_reason` | text |
| `attachment_path` | scanned approval |
| `shift_id`, `cost_center_id`, `dept_id` | reporting dimensions |

**Page features:**
- Submission queue with batch submit
- Approval recording + `auth_code` capture
- **Valid-from/to window enforcement** — `insurance_claims.php` refuses to submit a claim whose pre-auth has expired
- Approval-rate & turnaround-time KPIs per insurance company
- Cross-link: `insurance_claims.php` gains a `preauth_id` column and a "View pre-auth" button

---

### 2.4 Patient Result Portal — `patient_portal_tokens.php` + public `result_portal.php`

**Purpose:** let patients retrieve their own lab/imaging results without a staff account (gap G-7).

**New table — `rpos_portal_tokens`:**

| Column | Notes |
|---|---|
| `token_hash` | SHA-256 of the token — **never store the raw token** |
| `patient_id` | owner |
| `resource_type` | `LabResult` / `ImagingReport` / `Statement` |
| `resource_id` | polymorphic target |
| `expires_at` | mandatory — default `NOW() + INTERVAL 7 DAY` |
| `max_uses`, `use_count` | optional throttling |
| `created_by`, `created_at` | audit |
| `last_accessed_at`, `last_ip` | access trail |
| `revoked_at`, `revoked_by` | manual revocation |

**Pages:**

| Page | Auth | Purpose |
|---|---|---|
| `patient_portal_tokens.php` | admin session | Create / list / revoke tokens, copy link, WhatsApp/SMS share, QR code |
| `result_portal.php` | **public, token-only** | Validates hash, checks expiry/use-count, renders the result read-only |

**Security requirements (critical — public endpoint):**

1. Token generated via `bin2hex(random_bytes(32))`; only the SHA-256 hash is persisted
2. `hash_equals()` comparison — constant time, no timing leak
3. Hard expiry enforced in SQL (`AND expires_at > NOW() AND revoked_at IS NULL`)
4. `use_count` incremented atomically inside a transaction; abort when `max_uses` reached
5. **No patient enumeration** — invalid/expired tokens return an identical generic message
6. `noindex, nofollow` meta + `X-Robots-Tag` header
7. No clinical data cached; every render re-checks authorization
8. All access logged to `last_accessed_at` + `last_ip`

---

### 2.5 Phase 2 Acceptance Criteria

- [ ] All 11 tables created; FKs resolve
- [ ] Radiology workflow reaches `Verified` and posts a **balanced** journal entry
- [ ] Re-running a radiology posting does **not** double-post (trigger blocks it)
- [ ] Bed board reflects status changes in real time; occupancy math correct
- [ ] Bed charges post to the inpatient revenue account
##  PHASE 3 — Financial Expansion

> **Goal:** Turn the existing institutional-grade financial *engine* into a complete *accounting application*.
> **Rule:** every page reuses `config/financial_helpers.php`. **No new math primitives. No direct ledger writes.**

### 3.1 Financial Reports — `financial_reports.php` (+ `print_financial_report.php`)

| Report | Source | Notes |
|---|---|---|
| **Trial Balance** | `rpos_journal_items`  `rpos_accounts` | Σdebit vs Σcredit must balance exactly (BCMath) |
| **Balance Sheet** | `account_type IN ('Asset','Liability','Equity')` | Grouped by `parent_group` (BS/PL/COGS/Opex/Other) |
| **Income Statement (P&L)** | `Revenue`, `Expense` types | Multi-step: revenue → COGS → gross profit → opex → net income |
| **Cash Flow (indirect)** | Asset movements + net income | Operating / Investing / Financing sections |
| **General Ledger** | Per-account drill-down | Running balance recomputed in BCMath |
| **AR Aging** | Patient balances | Buckets: 0-30 / 31-60 / 61-90 / 90+ days |
| **AP Aging** | `rpos_vendor_bills` | Same buckets |
| **Cost-Center Report** | `rpos_cost_centers` dimension | Revenue vs expense per centre |

**Filters (all reports):**
- Fiscal year / fiscal period (`rpos_fiscal_years`, `rpos_fiscal_periods`)
- Date range (`entry_date BETWEEN ? AND ?`)
- Cost centre (`cost_center_id`)
- Department (`dept_id` — enabled by Phase 1)
- Currency (`currency` + `exchange_rate` translation)
- Only `status = 'Posted'` entries are ever included

**Print companion — `print_financial_report.php`:** follows the existing `print_profits.php` / `print_order_report.php` pattern — company header, report title, filter summary, signature block, A4 CSS.

---

### 3.2 Bank Reconciliation — `bank_reconciliation.php`

**Purpose:** activate the dormant `rpos_bank_statements` / `rpos_bank_statement_lines` schema (gap G-8).

**Features:**
- Statement header create: account, statement date, opening & closing balance
- **CSV import** of statement lines (map date / reference / description / debit / credit)
- **Auto-matching engine:**
  1. Exact match on `debit`/`credit` + date ±1 day
  2. Reference-string match against `rpos_journal_entries.reference_id`
  3. Amount-only match (flagged for manual confirmation)
- Manual match / unmatch with `matched_journal_item_id`
- Running **unreconciled difference** computed in BCMath; must reach exactly `0.0000`
- Closing locks the statement (`is_reconciled = 1`, `reconciled_by`, `reconciled_at`)
- Reconciliation summary print-out

**Integrity:** reconciliation never mutates ledger entries — it only sets `matched_journal_item_id`. Discrepancies are corrected via a proper `createJournalEntry()` adjustment (or a reversal entry).

---

### 3.3 Currency Management — `currency_management.php`

**Purpose:** activate `rpos_currencies` / `rpos_exchange_rates` (gap G-9).

**Features:**
- Currency CRUD: code (ISO-3), name AR/EN, symbol, `is_base`, `is_active`
- Exchange-rate entry with `effective_date` and full history
- **Rate resolution helper** (added to `financial_helpers.php`):
  ```php
  function fin_get_rate(mysqli $mysqli, string $from, string $to, string $date): string
  ```
  resolves the latest `effective_date <= $date`; returns `1.000000` for same-currency.
- **Revaluation** of foreign-currency balances: computes the delta and posts an adjustment entry (gain → revenue, loss → expense)
- Guard: exactly **one** base currency (`is_base = 1`) enforced inside a transaction

---

### 3.4 Department Profitability — `department_profitability.php`

**Purpose:** the payoff of Phase 1 — P&L by department and cost centre (gap G-14).

**Method:**
1. **Direct revenue** — journal items joined via `rpos_departments.revenue_account_id` (and `dept_id` on source documents)
2. **Direct expense** — same via `expense_account_id`
3. **Allocated overhead** — allocated by a configurable driver:
   - Driver options: headcount · floor area · revenue share · transaction count
   - Allocation stored in `rpos_cost_allocations` so it is reproducible and auditable
4. **Contribution margin** = revenue − direct expense
5. **Net margin** = contribution margin − allocated overhead

**Table — `rpos_cost_allocations`:** `allocation_id`, `period_id`, `source_cost_center_id`, `target_dept_id`, `driver`, `driver_value`, `allocated_amount`, `created_by`, `created_at`

**Views:** matrix (departments × periods) · drill-down · trend chart (Chart.js) · print pack.

---

### 3.5 Fixed Assets — `fixed_assets.php`

**Purpose:** asset register + depreciation engine (gap G-11).

**New tables:**

| Table | Key columns |
|---|---|
| `rpos_fixed_assets` | `asset_code`, `asset_name`, `category` (Building/Equipment/Vehicle/Furniture/IT/Medical), `purchase_date`, `purchase_cost`, `salvage_value`, `useful_life_months`, `depreciation_method` (StraightLine/DecliningBalance), `accumulated_depreciation`, `book_value`, `dept_id`, `cost_center_id`, `location`, `serial_number`, `warranty_until`, `status` (Active/Disposed/UnderRepair/Idle), `disposal_date`, `disposal_proceeds`, `created_by` |
| `rpos_asset_depreciation_schedule` | `asset_id`, `period_id`, `period_date`, `opening_book_value`, `depreciation_amount`, `accumulated_depreciation`, `closing_book_value`, `journal_entry_id`, `is_posted` |

**Depreciation run:**

```
1. Select assets WHERE status = 'Active' AND book_value > salvage_value
2. For each: amount = (cost - salvage) / useful_life   [straight-line], BCMath
3. Insert schedule row (is_posted = 0)
4. Preview screen -> user confirms
5. Post via createJournalEntry():
     Dr  Depreciation Expense (5100)         amount
     Cr  Accumulated Depreciation (1200)     amount
6. Mark schedule row is_posted = 1, link journal_entry_id
7. Update asset accumulated_depreciation + book_value
```

**Also supported:** asset disposal (gain/loss entry), transfer between departments, full asset-register print-out.

**New default accounts (seeded):** `5100` إهلاك الأصول (Expense), `1200` مجمع إهلاك الأصول (Asset, `is_contra = 1` — uses the existing column).

---

### 3.6 Accounts Payable / Vendor Bills — `vendor_bills.php`

**Purpose:** supplier invoice management with 3-way match (gap G-12).

**New tables:**

| Table | Key columns |
|---|---|
| `rpos_vendor_bills` | `bill_number`, `supplier_id`, `supplier_invoice_no`, `bill_date`, `due_date`, `receive_id` (GRN link), `purchase_order_id`, `subtotal`, `tax_amount`, `discount_amount`, `total_amount`, `paid_amount`, `balance`, `status` (Draft/Pending/Approved/PartiallyPaid/Paid/Overdue/Void), `currency`, `exchange_rate`, `dept_id`, `cost_center_id`, `notes`, `attachment_path`, `created_by` |
| `rpos_vendor_bill_items` | `bill_id`, `product_id`, `description`, `quantity`, `unit_price`, `line_total`, `tax_rate`, `expense_account_id` |

**Features:**
- Manual entry + line-item grid
- **3-way match:** PO (`rpos_purchase_orders`) ↔ GRN (`stock_receive`) ↔ Bill — flags quantity/price mismatches
- Approval workflow (`approved_by`, `approved_at`)
- Partial payments → post:
  ```
  Dr  Accounts Payable (2100)      amount
  Cr  Cash / Bank                  amount
  ```
- **AP aging** report → also surfaced in `financial_reports.php`
- Supplier statements reconciling against `supplier_account.php`

**New default account (seeded):** `2100` الدائنة / Accounts Payable (Liability).

---

### 3.7 Budgeting & Variance — `financial_budget.php`

**Purpose:** annual budget with actual-vs-budget variance (gap G-13).

**New tables:**

| Table | Key columns |
|---|---|
| `rpos_budgets` | `budget_name`, `fiscal_year_id`, `dept_id`, `cost_center_id`, `status` (Draft/Approved/Active/Closed), `approved_by`, `approved_at`, `notes` |
| `rpos_budget_lines` | `budget_id`, `account_id`, `period_id`, `budgeted_amount`, `actual_amount`, `variance`, `variance_pct` |

**Features:**
- Budget builder: monthly or annual spread, per account / department / cost centre
- Templates — copy last year's budget, or spread an annual total evenly across periods
- Approval flow (Draft → Approved → Active)
- **Variance report:** actual (from posted journal entries) vs budgeted, with % variance and a favourability flag
- Warning when spend exceeds a configurable % of budget
- Chart.js variance waterfall
- Print pack

**Integration:** surfaced on `finance_dashboard.php` as a budget-utilization widget.

---

### 3.8 Phase 3 Acceptance Criteria

- [ ] Trial Balance balances to exactly `0.0000` for every period
- [ ] Balance Sheet satisfies `Assets = Liabilities + Equity` (BCMath)
- [ ] Cash Flow reconciles to the change in cash accounts
- [ ] Bank reconciliation reaches a zero unreconciled difference for a test statement
- [ ] Exchange-rate lookup returns the correct historical rate
- [ ] Department profitability sums back to total revenue and total expense
- [ ] Depreciation run posts balanced entries and is idempotent per period
- [ ] Disposal posts the correct gain/loss
## 🔧 PHASE 4 — Integration & Hardening

### 4.1 Sidebar Registration (`partials/_sidebar.php`)

Three new collapsible groups appended before the closing `</ul>`, each following the existing guard pattern:

```php
<?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'departments.php')): ?>
<li class="nav-item<?php echo sidebar_active('departments.php', $current_page); ?>">
  <a class="nav-link" href="departments.php">
    <i class="fas fa-sitemap icon-teal"></i> <?php echo __('departments'); ?>
  </a>
</li>
<?php endif; ?>
```

| Group | Pages |
|---|---|
| 🏢 **الأقسام والمراكز** | `departments.php` |
| 🩺 **العمليات الطبية** | `radiology_management.php`, `ward_bed_management.php`, `insurance_preauthorization.php`, `patient_portal_tokens.php` |
| 💰 **المالية المتقدمة** | `financial_reports.php`, `bank_reconciliation.php`, `currency_management.php`, `department_profitability.php`, `fixed_assets.php`, `vendor_bills.php`, `financial_budget.php` |

### 4.2 RBAC Synchronization

`get_page_permissions.php` / `save_page_permissions.php` enumerate pages dynamically, so **no code change is required** — but the following must be verified:

- [ ] Each new page appears in the permission matrix after deployment
- [ ] Super-admin bypasses all new guards (`isSuperAdmin()`)
- [ ] Non-privileged users get a clean denial, not a PHP notice

### 4.3 i18n Parity Check

- [ ] Every new key exists in **both** `$lang['en']` and `$lang['ar']`
- [ ] A helper script verifies key parity (see §4.6)

### 4.4 Migration Safety Hardening (gap G-15)

The existing `megration.sql` uses MariaDB-only `ADD COLUMN IF NOT EXISTS`. New parts use the portable guarded form:

```sql
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name   = 'rpos_clinics'
    AND column_name  = 'dept_id'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE rpos_clinics ADD COLUMN dept_id INT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

A **Phase 4 retrofit** optionally applies the same guard style to PARTS 1–5 of the existing file (behaviour-preserving).

### 4.5 Shared Display Helper (gap G-16)

New non-invasive additions to `pos/admin/config/financial_helpers.php`:

```php
function fin_format($amount, $currency = null, $decimals = 2) { /* thousands sep + symbol */ }
function fin_currency_symbol(mysqli $mysqli, string $code): string { /* from rpos_currencies */ }
```

> These are **display-only** — they never alter stored values.

### 4.6 Verification Tooling

| Tool | Purpose |
|---|---|
| `php -l <file>` | Syntax gate on every new/modified PHP file |
| `pos/admin/tests/financial_smoke.php` | Asserts: TB balances, BS identity holds, no orphan journal items |
| `pos/admin/tests/i18n_parity.php` | Asserts `array_diff` between `en` and `ar` keys is empty |
| `pos/admin/system_check.php` | Extended with new-table existence checks |

### 4.7 Documentation

- Extend `README.md` **Key Modules** table with a row per new module
- Add a **"What's New"** section describing the 4 phases
- Document the new permission keys

---

## 🗄️ Full Database Schema Additions

**Total: 19 new tables.** All `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`, added as `-- PART 14` … `-- PART 20` (append-only) in `megration.sql`.

| Part | Phase | Tables | Depends on |
|---|---|---|---|
| **14** | 1 | `rpos_departments` + 2 cost centers + 4 ALTERs | `rpos_cost_centers`, `rpos_accounts` |
| **15** | 2 | `rpos_imaging_types`, `rpos_imaging_requests`, `rpos_imaging_reports` | `rpos_patients`, `rpos_staff`, `rpos_departments` |
| **16** | 2 | `rpos_wards`, `rpos_rooms`, `rpos_beds`, `rpos_bed_assignments` | `rpos_departments` |
| **17** | 2 | `rpos_preauth_requests`, `rpos_portal_tokens` | insurance tables, `rpos_patients` |
| **18** | 3 | `rpos_fixed_assets`, `rpos_asset_depreciation_schedule` | `rpos_fiscal_periods`, `rpos_journal_entries` |
| **19** | 3 | `rpos_vendor_bills`, `rpos_vendor_bill_items`, `rpos_cost_allocations` | `rpos_suppliers`, `rpos_products` |
| **20** | 3 | `rpos_budgets`, `rpos_budget_lines` | `rpos_fiscal_years`, `rpos_fiscal_periods` |

### New Chart-of-Accounts Entries (seeded via `INSERT IGNORE`)

| Code | Name AR | Name EN | Type | Purpose |
|---|---|---|---|---|
| `1200` | مجمع إهلاك الأصول | Accumulated Depreciation | Asset (`is_contra = 1`) | Fixed assets §3.5 |
| `1300` | مصروفات مدفوعة مقدماً | Prepaid Expenses | Asset | Pre-payments |
| `2100` | الدائنة — الموردون | Accounts Payable | Liability | Vendor bills §3.6 |
| `4200` | إيراد الأشعة | Imaging Revenue | Revenue | Radiology §2.1 |
| `4210` | إيراد التنويم | Inpatient Revenue | Revenue | Ward charges §2.2 |
| `5100` | مصروف الإهلاك | Depreciation Expense | Expense | Fixed assets §3.5 |
| `5300` | فروق أسعار الصرف | FX Gain/Loss | Expense | Revaluation §3.3 |

### New Cost Centers (seeded)

| Code | Name AR |
|---|---|
| `CC-RAD` | الأشعة والتصوير |
| `CC-IPD` | قسم التنويم |

### Referential Integrity Policy

| Relationship | On delete |
|---|---|
| `parent_dept_id` → `rpos_departments` | `SET NULL` (preserve orphan, never cascade) |
| `cost_center_id` / account FKs | `SET NULL` |
| Child rows of a document (items, schedule, rooms) | `CASCADE` |
| Patient / staff references | `RESTRICT` — never delete clinical history |

---

##  Sidebar Navigation Map

Proposed final placement inside `partials/_sidebar.php`:

```
┌─ العمليات الطبية (Clinical) ─────────────────────────────
│  doctor_appointments.php                                 │
│  outpatient_management.php                               │
│  clinic_queue.php                                        │
│  lab_management.php                                      │
│  radiology_management.php          * NEW (Phase 2)       │
│  inpatient_management.php                                │
│  ward_bed_management.php           * NEW (Phase 2)       │
│  insurance_preauthorization.php    * NEW (Phase 2)       │
│  patient_portal_tokens.php         * NEW (Phase 2)       │
──────────────────────────────────────────────────────────┘
┌─ الأقسام والمراكز (Organization) ────────────────────────┐
│  departments.php                   * NEW (Phase 1)       │
└──────────────────────────────────────────────────────────┘
┌─ المالية (Financial) ────────────────────────────────────┐
│  finance_dashboard.php                                   │
│  financial_analytics.php                                 │
│  financial_reports.php             * NEW (Phase 3)       │
│  department_profitability.php      * NEW (Phase 3)       │
│  bank_reconciliation.php           * NEW (Phase 3)       │
│  currency_management.php           * NEW (Phase 3)       │
│  fixed_assets.php                  * NEW (Phase 3)       │
│  vendor_bills.php                  * NEW (Phase 3)       │
│  financial_budget.php              * NEW (Phase 3)       │
│  financial_settings.php                                  │
│  financial_console.php                                   │
└──────────────────────────────────────────────────────────┘
```

Every entry wrapped in:
```php
<?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, '<file>.php')): ?>
```

## 🌐 i18n Key Registry

New keys to add to **both** `$lang['en']` and `$lang['ar']` in `config/languages.php`:

### Phase 1 — Departments (18 keys)
`departments`, `department`, `add_department`, `edit_department`, `dept_code`, `dept_name`, `dept_name_en`, `dept_type`, `parent_department`, `dept_type_clinical`, `dept_type_diagnostic`, `dept_type_pharmacy`, `dept_type_admin`, `dept_type_support`, `manager`, `location`, `sort_order`, `linked_clinics`

### Phase 2 — Features (32 keys)
`radiology`, `radiology_management`, `imaging_type`, `imaging_request`, `imaging_report`, `modality`, `findings`, `impression`, `recommendation`, `performed_at`, `reported_at`, `wards`, `ward`, `rooms`, `room`, `beds`, `bed`, `bed_status`, `occupancy_rate`, `assign_bed`, `transfer_bed`, `discharge_bed`, `preauthorization`, `preauth_request`, `auth_code`, `valid_from`, `valid_to`, `approved_amount`, `portal_tokens`, `generate_token`, `revoke_token`, `token_expires`

### Phase 3 — Financial Statements & Assets (38 keys)
`financial_reports`, `trial_balance`, `balance_sheet`, `income_statement`, `cash_flow`, `general_ledger`, `ar_aging`, `ap_aging`, `cost_center_report`, `fiscal_period`, `cost_center`, `bank_reconciliation`, `statement_date`, `opening_balance`, `closing_balance`, `unreconciled_difference`, `auto_match`, `manual_match`, `matched`, `currency_management`, `exchange_rate`, `effective_date`, `base_currency`, `revaluation`, `department_profitability`, `direct_revenue`, `direct_expense`, `allocated_overhead`, `contribution_margin`, `net_margin`, `allocation_driver`, `fixed_assets`, `asset_code`, `useful_life`, `depreciation_method`, `accumulated_depreciation`, `book_value`, `disposal`

### Phase 3 — AP & Budget (26 keys)
`vendor_bills`, `bill_number`, `supplier_invoice_no`, `bill_date`, `due_date`, `subtotal`, `tax_amount`, `discount_amount`, `three_way_match`, `payment_schedule`, `partially_paid`, `overdue`, `void`, `budget`, `budget_name`, `budget_lines`, `budgeted_amount`, `actual_amount`, `variance`, `variance_pct`, `favourable`, `unfavourable`, `spread_evenly`, `copy_last_year`, `budget_utilization`, `budget_exceeded`

**Total: ~114 new keys × 2 languages = 228 entries.**

---

## ⚠️ Risk Register & Mitigations

| ID | Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| **R-1** | `megration.sql` uses MariaDB-only `ADD COLUMN IF NOT EXISTS`; fails on MySQL 5.7/8.0 | High | High | §4.4 guarded `information_schema` pattern for all new parts; optional retrofit of PARTS 1–5 |
| **R-2** | Double-posting revenue from new modules | Medium | **Critical** | Rely on `createJournalEntry()` idempotency + `trg_journal_entry_idempotency`; never insert into `rpos_journal_entries` directly |
| **R-3** | Float drift introduced in new financial math | Medium | High | Mandate `fin_*` helpers; acceptance test greps for bare `+ - * /` on amount variables |
| **R-4** | Public portal leaks PHI | Low | **Critical** | Hash-only storage, constant-time compare, hard SQL expiry, no enumeration, access logging, `noindex` |
| **R-5** | Bed charges double-accrued across days | Medium | Medium | One `rpos_bed_assignments` row per stay segment; idempotency on `reference_id = assignment_id` |
| **R-6** | Depreciation re-run posts twice | Medium | High | Per-asset-per-period unique guard + `is_posted` flag checked inside `fin_transaction()` |
| **R-7** | Cost-center allocations not reproducible | Medium | Medium | Persist every allocation in `rpos_cost_allocations` with driver + driver value |
| **R-8** | Sidebar becomes unwieldy (already 1042 lines) | High | Low | Collapsible groups; consider extracting to `partials/_sidebar_financial.php` |
| **R-9** | Language key drift (EN without AR) | High | Medium | `tests/i18n_parity.php` fails on any difference |
| **R-10** | Performance on aging / large GL queries | Medium | Medium | Index `entry_date`, `cost_center_id`, `dept_id`; paginate GL; avoid N+1 in loops |
| **R-11** | Existing production data lacks `dept_id` | High | Low | All new FK columns nullable + backfill script; no NOT NULL constraints |
| **R-12** | Breaking change to existing pages | Low | High | Phase 1 staff/clinic changes are **additive** — legacy `department` text column retained |

---
---
- Exchange-rate entry with `effective_date` and full history
- **Rate resolution helper** (added to `financial_helpers.php`):
  ```php
  function fin_get_rate(mysqli $mysqli, string $from, string $to, string $date): string
  ```
  resolves the latest `effective_date <= $date`; returns `1.000000` for same-currency.
- **Revaluation** of foreign-currency balances: computes the delta and posts an adjustment entry (gain → revenue, loss → expense)
- Guard: exactly **one** base currency (`is_base = 1`) enforced inside a transaction

---
- [ ] Pre-auth expiry blocks claim submission in `insurance_claims.php`
- [ ] Portal token cannot be reused beyond `max_uses`; expired tokens rejected
- [ ] Public portal exposes **zero** data for an invalid token
- [ ] All 4 pages permission-guarded and present in the sidebar

---

##  Deliverables Summary

| Phase | New Pages | New Tables | Modified Files |
|---|---|---|---|
| **1 — Departments** | 2 | 1 (+2 cost centers, +4 ALTERs, +7 CoA) | `_sidebar.php`, `languages.php`, `megration.sql`, `clinics.php`, `add_staff.php`, `update_staff.php`, `hrm.php` |
| **2 — Features** | 5 | 11 | `_sidebar.php`, `languages.php`, `megration.sql`, `inpatient_management.php`, `insurance_claims.php`, `lab_management.php` |
| **3 — Financial** | 8 | 7 | `_sidebar.php`, `languages.php`, `megration.sql`, `financial_helpers.php`, `finance_dashboard.php`, `financial_analytics.php` |
| **4 — Integration** | 3 (tests/print) | — | `_sidebar.php`, `languages.php`, `README.md`, `system_check.php`, `financial_helpers.php` |

### New File Inventory

```
pos/admin/departments.php                        (Phase 1)
pos/admin/ajax_department_loader.php             (Phase 1)
pos/admin/radiology_management.php               (Phase 2)
pos/admin/print_imaging_report.php               (Phase 2)
pos/admin/ward_bed_management.php                (Phase 2)
pos/admin/insurance_preauthorization.php         (Phase 2)
pos/admin/patient_portal_tokens.php              (Phase 2)
pos/admin/result_portal.php              [PUBLIC] (Phase 2)
pos/admin/financial_reports.php                  (Phase 3)
pos/admin/print_financial_report.php             (Phase 3)
pos/admin/bank_reconciliation.php                (Phase 3)
pos/admin/currency_management.php                (Phase 3)
pos/admin/department_profitability.php           (Phase 3)
pos/admin/fixed_assets.php                       (Phase 3)
pos/admin/vendor_bills.php                       (Phase 3)
pos/admin/financial_budget.php                   (Phase 3)
pos/admin/tests/financial_smoke.php              (Phase 4)
pos/admin/tests/i18n_parity.php                  (Phase 4)
```

**16 feature pages + 3 support/utility pages = 19 new files.**

---

## 🚦 Delivery Order & Acceptance Criteria

| # | Step | Gate |
|---|---|---|
| 1 | **Phase 1** — schema + `departments.php` + `ajax_department_loader.php` | `php -l` clean · CRUD works · sidebar guarded · bilingual |
| 2 | **Phase 1b** — clinic & staff integration | Existing pages still function; `dept_id` persists |
| 3 | **Phase 2a** — Radiology | `Verified` posts a balanced entry; no double-post |
| 4 | **Phase 2b** — Ward & Beds | Occupancy correct; charges post once |
| 5 | **Phase 2c** — Pre-auth | Expired pre-auth blocks claim submission |
| 6 | **Phase 2d** — Portal | Invalid token exposes nothing |
| 7 | **Phase 3a** — Reports | TB balances to `0.0000`; BS identity holds |
| 8 | **Phase 3b** — Bank recon | Unreconciled difference reaches `0.0000` |
| 9 | **Phase 3c** — Currency | Historical rate lookup correct; one base currency |
| 10 | **Phase 3d** — Dept profitability | Sums back to total revenue/expense |
| 11 | **Phase 3e** — Fixed assets | Depreciation idempotent; disposal correct |
| 12 | **Phase 3f** — Vendor bills | 3-way match flags mismatches |
| 13 | **Phase 3g** — Budget | Variance matches manual calc |
| 14 | **Phase 4** — Hardening, tests, docs | All tests pass; i18n parity clean |
| 15 | **Commit & push** | Pushed to `origin/main` |

### Definition of Done (per phase)

- [ ] `php -l` passes on every new/modified file
- [ ] No SQL string interpolation on user input anywhere
- [ ] CSRF verified on every state-changing form
- [ ] Permission guard present on the page **and** in the sidebar
- [ ] Both languages render correctly (RTL + LTR)
- [ ] All money math goes through `fin_*`
- [ ] All ledger writes go through `createJournalEntry()` inside `fin_transaction()`
- [ ] No regression in existing pages
- [ ] Committed with a conventional message and pushed to `origin/main`

---

## 🔭 Out of Scope (Future Backlog)

- HL7 / FHIR integration for external systems
- DICOM viewer integration for radiology images
- Mobile PWA for ward nurses
- SMS gateway integration for portal tokens
- Insurance EDI / electronic claim submission
- Payroll → GL automatic posting (currently separate)
- Report scheduler / email distribution
- Multi-facility (multi-branch) tenancy

---

<div align="center">

**Al-Wahat HIS & LIMS — Implementation Plan**
Prepared for **Mohamed Omer Elsamani** · `msimoo/his-lab-system`

*This is a plan document — no application code has been modified by its creation.*

</div>