<?php
/** Database tables (MySQL 5.7+ / MariaDB 10.3+). Safe to run repeatedly. */

/** Bump when table_sql() or column_sql() changes; ensure_schema() then upgrades existing installs. */
const SCHEMA_VERSION = 5;
function table_sql(): array
{
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            phone VARCHAR(40) NOT NULL DEFAULT '',
            password_hash VARCHAR(255) NULL,
            role VARCHAR(10) NOT NULL DEFAULT 'staff',
            location VARCHAR(500) NOT NULL DEFAULT '',
            schools TEXT NULL,
            job_title VARCHAR(120) NOT NULL DEFAULT '',
            duties TEXT NULL,
            address VARCHAR(300) NOT NULL DEFAULT '',
            next_of_kin VARCHAR(120) NOT NULL DEFAULT '',
            next_of_kin_phone VARCHAR(40) NOT NULL DEFAULT '',
            bank_name VARCHAR(80) NOT NULL DEFAULT '',
            bank_account VARCHAR(40) NOT NULL DEFAULT '',
            start_date DATE NULL,
            photo_id INT UNSIGNED NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            failed_logins INT NOT NULL DEFAULT 0,
            locked_until DATETIME NULL,
            last_login DATETIME NULL,
            created_at DATETIME NOT NULL
        ) $t",
        "CREATE TABLE IF NOT EXISTS reports (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            report_date DATE NOT NULL,
            location VARCHAR(120) NOT NULL DEFAULT '',
            work_type VARCHAR(120) NOT NULL DEFAULT '',
            finished VARCHAR(60) NOT NULL DEFAULT '',
            lesson VARCHAR(10) NOT NULL DEFAULT '',
            classes_taught INT NOT NULL DEFAULT 0,
            laptops_total INT NOT NULL DEFAULT 0,
            laptops_faulty INT NOT NULL DEFAULT 0,
            laptop_resolved VARCHAR(60) NOT NULL DEFAULT '',
            complaint VARCHAR(120) NOT NULL DEFAULT '',
            followup VARCHAR(120) NOT NULL DEFAULT '',
            urgent VARCHAR(60) NOT NULL DEFAULT '',
            installation VARCHAR(10) NOT NULL DEFAULT '',
            issues_closed TINYINT(1) NOT NULL DEFAULT 0,
            data LONGTEXT NOT NULL,
            source VARCHAR(10) NOT NULL DEFAULT 'app',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            KEY idx_user_date (user_id, report_date),
            KEY idx_date (report_date)
        ) $t",
        "CREATE TABLE IF NOT EXISTS wallet_txns (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            kind VARCHAR(10) NOT NULL,
            category VARCHAR(80) NOT NULL DEFAULT '',
            amount DECIMAL(12,2) NOT NULL,
            effect DECIMAL(12,2) NOT NULL,
            description TEXT NULL,
            route VARCHAR(190) NOT NULL DEFAULT '',
            txn_date DATE NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'posted',
            review_note TEXT NULL,
            staff_reply TEXT NULL,
            reviewed_by INT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            request_id INT UNSIGNED NULL,
            report_id INT UNSIGNED NULL,
            created_by INT UNSIGNED NULL,
            source VARCHAR(10) NOT NULL DEFAULT 'app',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            KEY idx_user (user_id, status),
            KEY idx_date (txn_date),
            KEY idx_kind_status (kind, status)
        ) $t",
        "CREATE TABLE IF NOT EXISTS fund_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            purpose TEXT NOT NULL,
            needed_by DATE NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'pending',
            admin_note TEXT NULL,
            txn_id INT UNSIGNED NULL,
            decided_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            decided_at DATETIME NULL,
            KEY idx_user (user_id),
            KEY idx_status (status)
        ) $t",
        "CREATE TABLE IF NOT EXISTS attachments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            owner_type VARCHAR(10) NOT NULL,
            owner_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            stored_name VARCHAR(190) NOT NULL,
            thumb_name VARCHAR(190) NULL,
            original_name VARCHAR(190) NOT NULL,
            mime VARCHAR(60) NOT NULL,
            size INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            KEY idx_owner (owner_type, owner_id)
        ) $t",
        "CREATE TABLE IF NOT EXISTS notifications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            type VARCHAR(30) NOT NULL,
            title VARCHAR(190) NOT NULL,
            body TEXT NULL,
            link VARCHAR(190) NOT NULL DEFAULT '',
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            KEY idx_user_read (user_id, read_at)
        ) $t",
        "CREATE TABLE IF NOT EXISTS audit_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            action VARCHAR(40) NOT NULL,
            entity VARCHAR(30) NOT NULL,
            entity_id INT UNSIGNED NULL,
            details TEXT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_entity (entity, entity_id)
        ) $t",
        "CREATE TABLE IF NOT EXISTS jobs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(190) NOT NULL,
            client_type VARCHAR(10) NOT NULL DEFAULT 'school',
            client_name VARCHAR(190) NOT NULL DEFAULT '',
            location VARCHAR(190) NOT NULL DEFAULT '',
            contact_name VARCHAR(120) NOT NULL DEFAULT '',
            contact_phone VARCHAR(40) NOT NULL DEFAULT '',
            description TEXT NULL,
            priority VARCHAR(10) NOT NULL DEFAULT 'normal',
            due_date DATE NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'open',
            created_by INT UNSIGNED NULL,
            source_report_id INT UNSIGNED NULL,
            completion_note TEXT NULL,
            completed_by INT UNSIGNED NULL,
            completed_at DATETIME NULL,
            verified_by INT UNSIGNED NULL,
            verified_at DATETIME NULL,
            review_note TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            KEY idx_status (status),
            KEY idx_due (due_date)
        ) $t",
        "CREATE TABLE IF NOT EXISTS job_assignees (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            job_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            assigned_by INT UNSIGNED NULL,
            assigned_at DATETIME NOT NULL,
            removed_at DATETIME NULL,
            KEY idx_job (job_id),
            KEY idx_user (user_id, removed_at)
        ) $t",
        "CREATE TABLE IF NOT EXISTS job_updates (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            job_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NULL,
            kind VARCHAR(12) NOT NULL DEFAULT 'comment',
            report_id INT UNSIGNED NULL,
            body TEXT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_job (job_id)
        ) $t",
        "CREATE TABLE IF NOT EXISTS tools (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            category VARCHAR(80) NOT NULL DEFAULT '',
            serial_no VARCHAR(120) NOT NULL DEFAULT '',
            tag_code VARCHAR(60) NOT NULL DEFAULT '',
            `condition` VARCHAR(10) NOT NULL DEFAULT 'good',
            value DECIMAL(12,2) NOT NULL DEFAULT 0,
            purchase_date DATE NULL,
            notes TEXT NULL,
            holder_id INT UNSIGNED NULL,
            issued_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            KEY idx_holder (holder_id)
        ) $t",
        "CREATE TABLE IF NOT EXISTS tool_moves (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tool_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NULL,
            action VARCHAR(10) NOT NULL,
            `condition` VARCHAR(10) NOT NULL DEFAULT '',
            note TEXT NULL,
            by_user INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            KEY idx_tool (tool_id),
            KEY idx_user (user_id)
        ) $t",
        "CREATE TABLE IF NOT EXISTS attendance (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            work_date DATE NOT NULL,
            in_at DATETIME NOT NULL,
            in_lat DECIMAL(10,7) NULL,
            in_lng DECIMAL(10,7) NULL,
            in_acc INT NULL,
            in_site VARCHAR(120) NOT NULL DEFAULT '',
            in_dist INT NULL,
            out_at DATETIME NULL,
            out_lat DECIMAL(10,7) NULL,
            out_lng DECIMAL(10,7) NULL,
            out_acc INT NULL,
            out_site VARCHAR(120) NOT NULL DEFAULT '',
            out_dist INT NULL,
            note TEXT NULL,
            source VARCHAR(10) NOT NULL DEFAULT 'app',
            edited_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            KEY idx_user_date (user_id, work_date),
            KEY idx_date (work_date)
        ) $t",
        "CREATE TABLE IF NOT EXISTS clients (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(190) NOT NULL UNIQUE,
            type VARCHAR(10) NOT NULL DEFAULT 'school',
            address VARCHAR(300) NOT NULL DEFAULT '',
            area VARCHAR(120) NOT NULL DEFAULT '',
            contact_name VARCHAR(120) NOT NULL DEFAULT '',
            contact_phone VARCHAR(40) NOT NULL DEFAULT '',
            contact_email VARCHAR(190) NOT NULL DEFAULT '',
            lat DECIMAL(10,7) NULL,
            lng DECIMAL(10,7) NULL,
            notes TEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL
        ) $t",
        "CREATE TABLE IF NOT EXISTS pay_marks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            work_date DATE NOT NULL,
            kind VARCHAR(30) NOT NULL,
            note VARCHAR(500) NOT NULL DEFAULT '',
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_mark (user_id, work_date, kind),
            KEY idx_date (work_date)
        ) $t",
        "CREATE TABLE IF NOT EXISTS pay_adjustments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            month CHAR(7) NOT NULL,
            label VARCHAR(120) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            note VARCHAR(500) NOT NULL DEFAULT '',
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            KEY idx_month_user (month, user_id)
        ) $t",
        "CREATE TABLE IF NOT EXISTS pay_locks (
            month CHAR(7) NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            data MEDIUMTEXT NOT NULL,
            locked_by INT UNSIGNED NULL,
            locked_at DATETIME NOT NULL,
            PRIMARY KEY (month, user_id)
        ) $t",
        "CREATE TABLE IF NOT EXISTS settings (
            k VARCHAR(60) PRIMARY KEY,
            v MEDIUMTEXT NOT NULL
        ) $t",
    ];
}

/** Columns added after the first release: [table, column, definition]. Added by ensure_schema() when missing. */
function column_sql(): array
{
    return [
        ['users', 'job_title', "VARCHAR(120) NOT NULL DEFAULT ''"],
        ['users', 'duties', 'TEXT NULL'],
        ['users', 'address', "VARCHAR(300) NOT NULL DEFAULT ''"],
        ['users', 'next_of_kin', "VARCHAR(120) NOT NULL DEFAULT ''"],
        ['users', 'next_of_kin_phone', "VARCHAR(40) NOT NULL DEFAULT ''"],
        ['users', 'bank_name', "VARCHAR(80) NOT NULL DEFAULT ''"],
        ['users', 'bank_account', "VARCHAR(40) NOT NULL DEFAULT ''"],
        ['users', 'start_date', 'DATE NULL'],
        ['users', 'photo_id', 'INT UNSIGNED NULL'],
        // v3: priorities, client sign-off, strict attendance
        ['reports', 'priority', "VARCHAR(10) NOT NULL DEFAULT ''"],
        ['jobs', 'client_id', 'INT UNSIGNED NULL'],
        ['jobs', 'signoff_name', "VARCHAR(120) NOT NULL DEFAULT ''"],
        ['jobs', 'signoff_phone', "VARCHAR(40) NOT NULL DEFAULT ''"],
        ['jobs', 'signoff_at', 'DATETIME NULL'],
        ['jobs', 'signoff_skipped', "VARCHAR(500) NOT NULL DEFAULT ''"],
        ['attendance', 'in_gps_ts', 'DATETIME NULL'],
        ['attendance', 'out_gps_ts', 'DATETIME NULL'],
        ['attendance', 'in_skew', 'INT NULL'],
        ['attendance', 'out_skew', 'INT NULL'],
        ['attendance', 'in_reason', "VARCHAR(500) NOT NULL DEFAULT ''"],
        ['attendance', 'out_reason', "VARCHAR(500) NOT NULL DEFAULT ''"],
        ['attendance', 'flags', "VARCHAR(120) NOT NULL DEFAULT ''"],
        ['attendance', 'out_missed', 'TINYINT(1) NOT NULL DEFAULT 0'],
        // v4: staff can be assigned to several schools
        ['users', 'schools', 'TEXT NULL'],
        // v5: payroll
        ['users', 'salary', 'DECIMAL(12,2) NOT NULL DEFAULT 0'],
    ];
}

/** Create missing tables and columns on any database connection. */
function apply_schema(PDO $pdo): void
{
    foreach (table_sql() as $sql) {
        $pdo->exec($sql);
    }
    $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    foreach (column_sql() as [$table, $col, $def]) {
        $check->execute([$table, $col]);
        if (!(int)$check->fetchColumn()) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
        }
    }
}
