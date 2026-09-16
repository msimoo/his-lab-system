-- ============================================================
-- MASTER FINANCIAL INTEGRITY MIGRATION v2.0
-- Historical + Forward-compatible
-- يُشغَّل مرة واحدة فقط
-- ============================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- PART 1: Journal Entries — Reversal System + Audit
-- ============================================================
ALTER TABLE rpos_journal_entries
    ADD COLUMN IF NOT EXISTS reversal_of INT NULL,
    ADD COLUMN IF NOT EXISTS reversed_by INT NULL,
    ADD COLUMN IF NOT EXISTS reversed_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS reversed_by_user INT NULL,
    ADD COLUMN IF NOT EXISTS reversal_reason TEXT NULL,
    ADD COLUMN IF NOT EXISTS cost_center_id INT NULL,
    ADD COLUMN IF NOT EXISTS currency VARCHAR(3) NOT NULL DEFAULT 'SDG',
    ADD COLUMN IF NOT EXISTS exchange_rate DECIMAL(19,6) NOT NULL DEFAULT 1.000000,
    ADD COLUMN IF NOT EXISTS total_debit DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    ADD COLUMN IF NOT EXISTS total_credit DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    ADD COLUMN IF NOT EXISTS approval_status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Approved',
    ADD COLUMN IF NOT EXISTS approved_by INT NULL,
    ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS attachment_path VARCHAR(255) NULL;

ALTER TABLE rpos_journal_entries 
    MODIFY COLUMN status ENUM('Draft','Posted','Reversed','Cancelled') NOT NULL DEFAULT 'Posted';

-- Indexes
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.statistics 
    WHERE table_schema = DATABASE() AND table_name = 'rpos_journal_entries' AND index_name = 'idx_je_reference');
SET @sql := IF(@idx_exists = 0, 'CREATE INDEX idx_je_reference ON rpos_journal_entries (reference_type, reference_id, status)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.statistics 
    WHERE table_schema = DATABASE() AND table_name = 'rpos_journal_entries' AND index_name = 'idx_je_reversal');
SET @sql := IF(@idx_exists = 0, 'CREATE INDEX idx_je_reversal ON rpos_journal_entries (reversal_of)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- PART 2: Journal Items — Precision + Cost Centers
-- ============================================================
ALTER TABLE rpos_journal_items
    MODIFY COLUMN debit DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    MODIFY COLUMN credit DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    ADD COLUMN IF NOT EXISTS cost_center_id INT NULL,
    ADD COLUMN IF NOT EXISTS currency VARCHAR(3) NOT NULL DEFAULT 'SDG',
    ADD COLUMN IF NOT EXISTS foreign_amount DECIMAL(19,4) NULL,
    ADD COLUMN IF NOT EXISTS exchange_rate DECIMAL(19,6) NOT NULL DEFAULT 1.000000;

-- ============================================================
-- PART 3: Accounts — Precision
-- ============================================================
ALTER TABLE rpos_accounts
    MODIFY COLUMN balance DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    ADD COLUMN IF NOT EXISTS is_contra TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS currency VARCHAR(3) NULL,
    ADD COLUMN IF NOT EXISTS is_reconciliation_account TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS parent_group ENUM('BS','PL','COGS','Opex','Other') NULL;

-- ============================================================
-- PART 4: Shift linkage in transaction tables
-- ============================================================
ALTER TABLE rpos_lab_requests ADD COLUMN IF NOT EXISTS shift_id VARCHAR(20) NULL;
ALTER TABLE rpos_patient_service_requests ADD COLUMN IF NOT EXISTS shift_id VARCHAR(20) NULL;
ALTER TABLE rpos_patient_consumable_requests ADD COLUMN IF NOT EXISTS shift_id VARCHAR(20) NULL;
ALTER TABLE rpos_patient_refunds ADD COLUMN IF NOT EXISTS shift_id VARCHAR(20) NULL;
ALTER TABLE rpos_insurance_claims ADD COLUMN IF NOT EXISTS shift_id VARCHAR(20) NULL;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.statistics 
    WHERE table_schema = DATABASE() AND table_name = 'rpos_lab_requests' AND index_name = 'idx_lab_shift');
SET @sql := IF(@idx_exists = 0, 'CREATE INDEX idx_lab_shift ON rpos_lab_requests (shift_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- PART 5: Fiscal Periods (Period-End Closing) - NEW
-- ============================================================
CREATE TABLE IF NOT EXISTS rpos_fiscal_periods (
    period_id INT AUTO_INCREMENT PRIMARY KEY,
    fiscal_year_id INT NOT NULL,
    period_name VARCHAR(20) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_closed TINYINT(1) NOT NULL DEFAULT 0,
    closed_by INT NULL,
    closed_at DATETIME NULL,
    INDEX idx_fy (fiscal_year_id),
    INDEX idx_dates (start_date, end_date),
    FOREIGN KEY (fiscal_year_id) REFERENCES rpos_fiscal_years(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PART 6: Cost Centers - NEW
-- ============================================================
CREATE TABLE IF NOT EXISTS rpos_cost_centers (
    cost_center_id INT AUTO_INCREMENT PRIMARY KEY,
    cost_center_code VARCHAR(20) NOT NULL UNIQUE,
    cost_center_name VARCHAR(100) NOT NULL,
    parent_id INT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES rpos_cost_centers(cost_center_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default cost centers
INSERT IGNORE INTO rpos_cost_centers (cost_center_code, cost_center_name) VALUES
    ('CC-OPD',    'العيادات الخارجية'),
    ('CC-LAB',    'المختبر'),
    ('CC-PHARM',  'الصيدلية'),
    ('CC-ER',     'الطوارئ'),
    ('CC-ADMIN',  'الإدارة'),
    ('CC-FIN',    'المالية'),
    ('CC-HR',     'الموارد البشرية');

-- ============================================================
-- PART 7: Financial Audit Log - NEW
-- ============================================================
CREATE TABLE IF NOT EXISTS rpos_financial_audit_log (
    log_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT NOT NULL,
    action VARCHAR(30) NOT NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    user_id INT NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_user_time (user_id, created_at),
    INDEX idx_action_time (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PART 8: Multi-Currency Support - NEW
-- ============================================================
CREATE TABLE IF NOT EXISTS rpos_currencies (
    currency_code VARCHAR(3) PRIMARY KEY,
    currency_name VARCHAR(50) NOT NULL,
    symbol VARCHAR(10) NULL,
    is_base TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO rpos_currencies VALUES 
    ('SDG', 'جنيه سوداني', 'SDG', 1, 1),
    ('USD', 'دولار أمريكي', '$',   0, 1),
    ('EUR', 'يورو',          '€',   0, 1),
    ('SAR', 'ريال سعودي',   'SAR', 0, 1),
    ('AED', 'درهم إماراتي',  'AED', 0, 1);

CREATE TABLE IF NOT EXISTS rpos_exchange_rates (
    rate_id INT AUTO_INCREMENT PRIMARY KEY,
    from_currency VARCHAR(3) NOT NULL,
    to_currency VARCHAR(3) NOT NULL,
    rate DECIMAL(19,6) NOT NULL,
    effective_date DATE NOT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pair_date (from_currency, to_currency, effective_date),
    FOREIGN KEY (from_currency) REFERENCES rpos_currencies(currency_code),
    FOREIGN KEY (to_currency) REFERENCES rpos_currencies(currency_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PART 9: Bank Reconciliation - NEW
-- ============================================================
CREATE TABLE IF NOT EXISTS rpos_bank_statements (
    statement_id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    statement_date DATE NOT NULL,
    opening_balance DECIMAL(19,4) NOT NULL DEFAULT 0,
    closing_balance DECIMAL(19,4) NOT NULL DEFAULT 0,
    is_reconciled TINYINT(1) NOT NULL DEFAULT 0,
    reconciled_by INT NULL,
    reconciled_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES rpos_accounts(account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rpos_bank_statement_lines (
    line_id INT AUTO_INCREMENT PRIMARY KEY,
    statement_id INT NOT NULL,
    transaction_date DATE NOT NULL,
    reference VARCHAR(100) NULL,
    description TEXT NULL,
    debit DECIMAL(19,4) NOT NULL DEFAULT 0,
    credit DECIMAL(19,4) NOT NULL DEFAULT 0,
    matched_journal_item_id INT NULL,
    FOREIGN KEY (statement_id) REFERENCES rpos_bank_statements(statement_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PART 10: Round-Off Account - NEW
-- ============================================================
INSERT IGNORE INTO rpos_accounts (parent_id, account_code, account_name, account_type, is_transactional, balance)
VALUES (NULL, '5099', 'فروق التقريب', 'Expense', 1, 0.0000);

-- ============================================================
-- PART 11: Retained Earnings - NEW
-- ============================================================
INSERT IGNORE INTO rpos_accounts (parent_id, account_code, account_name, account_type, is_transactional, balance)
VALUES (NULL, '3001', 'الأرباح المحتجزة', 'Equity', 1, 0.0000);

-- ============================================================
-- PART 12: Additional unique constraints to prevent duplicates
-- ============================================================
-- Prevent duplicate Posted entries with same reference
-- Handled via trigger (below) — MySQL can't do partial unique index

DELIMITER $$
DROP TRIGGER IF EXISTS trg_journal_entry_idempotency$$
CREATE TRIGGER trg_journal_entry_idempotency
BEFORE INSERT ON rpos_journal_entries
FOR EACH ROW
BEGIN
    DECLARE existing_count INT DEFAULT 0;
    
    IF NEW.status = 'Posted' 
       AND NEW.reference_type NOT IN ('Manual', 'Opening Balance', 'Adjustment', 'Reversal') 
       AND NEW.reference_id IS NOT NULL 
       AND NEW.reference_id != '' THEN
        
        SELECT COUNT(*) INTO existing_count
        FROM rpos_journal_entries
        WHERE reference_type = NEW.reference_type
          AND reference_id = NEW.reference_id
          AND status IN ('Posted', 'Reversed');
        
        IF existing_count > 0 THEN
            SIGNAL SQLSTATE '45000' 
                SET MESSAGE_TEXT = 'Duplicate journal entry: reference already posted';
        END IF;
    END IF;
END$$
DELIMITER ;

-- ============================================================
-- PART 13: Trigger to sync total_debit / total_credit
-- ============================================================
DELIMITER $$
DROP TRIGGER IF EXISTS trg_sync_je_totals$$
CREATE TRIGGER trg_sync_je_totals
AFTER INSERT ON rpos_journal_items
FOR EACH ROW
BEGIN
    UPDATE rpos_journal_entries
    SET total_debit  = (SELECT COALESCE(SUM(debit), 0)  FROM rpos_journal_items WHERE entry_id = NEW.entry_id),
        total_credit = (SELECT COALESCE(SUM(credit), 0) FROM rpos_journal_items WHERE entry_id = NEW.entry_id)
    WHERE entry_id = NEW.entry_id;
END$$
DELIMITER ;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- DONE — Verify with:
-- SELECT 'Migration complete' AS status;
-- ============================================================