<?php
/** Database tables (MySQL 5.7+ / MariaDB 10.3+). Safe to run repeatedly. */
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
            location VARCHAR(120) NOT NULL DEFAULT '',
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
        "CREATE TABLE IF NOT EXISTS settings (
            k VARCHAR(60) PRIMARY KEY,
            v MEDIUMTEXT NOT NULL
        ) $t",
    ];
}
