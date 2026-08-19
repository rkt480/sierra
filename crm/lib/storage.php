<?php

declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/attribution.php';
require_once __DIR__ . '/auth.php';

function crm_db(): PDO
{
    static $pdo = null;
    static $schemaReady = false;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = require dirname(__DIR__) . '/config.php';
    $db = $config['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'],
        $db['port'],
        $db['database'],
        $db['charset']
    );

    $pdo = new PDO($dsn, (string) $db['user'], (string) $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => true,
    ]);

    // Schema checks used to run on every page request. Keep the safety net for
    // new installations and migrations, but avoid repeating the expensive
    // INFORMATION_SCHEMA/DDL work once this version is known to be ready.
    if (!$schemaReady && !crm_schema_version_is_current($pdo)) {
        crm_ensure_crm_schema($pdo);
        crm_mark_schema_version($pdo);
    }

    $schemaReady = true;

    return $pdo;
}

function crm_schema_version(): string
{
    return '20260813.1';
}

function crm_schema_version_is_current(PDO $pdo): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT setting_value
             FROM crm_settings
             WHERE setting_key = :setting_key
             LIMIT 1'
        );
        $stmt->execute(['setting_key' => '__crm_schema_version']);

        return (string) $stmt->fetchColumn() === crm_schema_version();
    } catch (Throwable $error) {
        // A missing settings table is expected on a fresh installation. The
        // regular schema bootstrap below will create it.
        return false;
    }
}

function crm_mark_schema_version(PDO $pdo): void
{
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        'INSERT INTO crm_settings (setting_key, setting_value, created_at, updated_at)
         VALUES (:setting_key, :setting_value, :created_at, :updated_at)
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_at = VALUES(updated_at)'
    );
    $stmt->execute([
        'setting_key' => '__crm_schema_version',
        'setting_value' => crm_schema_version(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function crm_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name'
    );
    $stmt->execute(['table_name' => $table]);

    return (int) $stmt->fetchColumn() > 0;
}

function crm_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function crm_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND INDEX_NAME = :index_name'
    );
    $stmt->execute([
        'table_name' => $table,
        'index_name' => $index,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function crm_column_character_length(PDO $pdo, string $table, string $column): int
{
    $stmt = $pdo->prepare(
        'SELECT COALESCE(CHARACTER_MAXIMUM_LENGTH, 0)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name
         LIMIT 1'
    );
    $stmt->execute(['table_name' => $table, 'column_name' => $column]);
    return (int) $stmt->fetchColumn();
}

function crm_ensure_crm_schema(PDO $pdo): void
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS crm_settings (
            setting_key VARCHAR(120) PRIMARY KEY,
            setting_value LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS whatsapp_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(512) NOT NULL UNIQUE,
            language VARCHAR(20) NOT NULL DEFAULT "pt_BR",
            category VARCHAR(30) NOT NULL DEFAULT "UTILITY",
            header_text TEXT NULL,
            body_text TEXT NOT NULL,
            footer_text TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT "draft",
            meta_template_id VARCHAR(100) NULL,
            meta_status VARCHAR(40) NULL,
            meta_rejection_reason TEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_whatsapp_templates_active (active, status, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    crm_migrate_legacy_settings($pdo);

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS kanban_columns (
            status VARCHAR(80) PRIMARY KEY,
            label VARCHAR(120) NOT NULL,
            position INT NOT NULL DEFAULT 0,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS lead_forms (
            id VARCHAR(32) PRIMARY KEY,
            slug VARCHAR(100) NOT NULL UNIQUE,
            name VARCHAR(160) NOT NULL,
            draft_config LONGTEXT NOT NULL,
            published_config LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT "draft",
            published_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS crm_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(160) NOT NULL,
            username VARCHAR(120) NOT NULL UNIQUE,
            email VARCHAR(180) NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(30) NOT NULL DEFAULT "vendedor",
            active TINYINT(1) NOT NULL DEFAULT 1,
            participates_in_rotation TINYINT(1) NOT NULL DEFAULT 0,
            rotation_weight INT NOT NULL DEFAULT 1,
            last_assigned_at DATETIME NULL,
            access_schedule_enabled TINYINT(1) NOT NULL DEFAULT 1,
            access_start_time TIME NOT NULL DEFAULT "09:00:00",
            access_end_time TIME NOT NULL DEFAULT "18:00:00",
            access_saturday_enabled TINYINT(1) NOT NULL DEFAULT 1,
            access_sunday_enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_crm_users_role_active (role, active),
            INDEX idx_crm_users_email (email),
            INDEX idx_crm_users_rotation (participates_in_rotation, active, last_assigned_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS crm_password_reset_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            request_ip VARCHAR(45) NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_password_reset_user (user_id, used_at, expires_at),
            INDEX idx_password_reset_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS lead_assignment_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lead_id VARCHAR(32) NOT NULL,
            from_user_id INT NULL,
            to_user_id INT NULL,
            action VARCHAR(40) NOT NULL,
            reason VARCHAR(255) NULL,
            created_by_user_id INT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_lead_assignment_logs_lead (lead_id, created_at),
            INDEX idx_lead_assignment_logs_to_user (to_user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS crm_push_subscriptions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            endpoint_hash CHAR(64) NOT NULL UNIQUE,
            endpoint VARCHAR(2048) NOT NULL,
            p256dh VARCHAR(255) NOT NULL,
            auth VARCHAR(255) NOT NULL,
            user_agent VARCHAR(500) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            last_used_at DATETIME NULL,
            INDEX idx_crm_push_user (user_id, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS crm_push_notification_events (
            event_hash CHAR(64) PRIMARY KEY,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            INDEX idx_crm_push_notification_events_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS whatsapp_conversation_reads (
            user_id INT NOT NULL,
            conversation_key VARCHAR(80) NOT NULL,
            read_incoming_count INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (user_id, conversation_key),
            INDEX idx_whatsapp_conversation_reads_user (user_id, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    crm_ensure_user_columns($pdo);
    crm_seed_default_admin_user($pdo);
    crm_seed_default_kanban_columns($pdo);

    if (crm_table_exists($pdo, 'leads')) {
        crm_ensure_lead_columns($pdo);
        crm_ensure_flexible_lead_status($pdo);
    }

    if (crm_table_exists($pdo, 'followup_steps')) {
        crm_ensure_followup_step_columns($pdo);
    }

    $checked = true;
}

function crm_ensure_followup_step_columns(PDO $pdo): void
{
    $columns = [
        'message_type' => 'VARCHAR(20) NOT NULL DEFAULT "text" AFTER delay_minutes',
        'template_id' => 'INT NULL AFTER message_type',
        'variable_mapping' => 'LONGTEXT NULL AFTER template_id',
    ];

    foreach ($columns as $column => $definition) {
        if (!crm_column_exists($pdo, 'followup_steps', $column)) {
            $pdo->exec(sprintf('ALTER TABLE followup_steps ADD COLUMN %s %s', $column, $definition));
        }
    }

    if (!crm_index_exists($pdo, 'followup_steps', 'idx_followup_steps_template')) {
        $pdo->exec('ALTER TABLE followup_steps ADD INDEX idx_followup_steps_template (template_id)');
    }
}

function crm_read_whatsapp_templates(bool $activeOnly = false): array
{
    $where = $activeOnly ? ' WHERE active = 1' : '';
    $stmt = crm_db()->query(
        'SELECT * FROM whatsapp_templates' . $where . ' ORDER BY active DESC, updated_at DESC, name ASC'
    );

    return $stmt->fetchAll();
}

function crm_find_whatsapp_template(int $id): ?array
{
    $stmt = crm_db()->prepare('SELECT * FROM whatsapp_templates WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $template = $stmt->fetch();

    return is_array($template) ? $template : null;
}

function crm_save_whatsapp_template(array $template): int
{
    $id = (int) ($template['id'] ?? 0);
    $name = trim((string) ($template['name'] ?? ''));
    $language = trim((string) ($template['language'] ?? 'pt_BR')) ?: 'pt_BR';
    $category = strtoupper(trim((string) ($template['category'] ?? 'UTILITY')));
    $body = trim((string) ($template['body_text'] ?? ''));
    $now = date('Y-m-d H:i:s');

    if ($id > 0) {
        $stmt = crm_db()->prepare(
            'UPDATE whatsapp_templates
             SET name = :name, language = :language, category = :category,
                 header_text = :header_text, body_text = :body_text, footer_text = :footer_text,
                 status = :status, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'language' => $language,
            'category' => $category,
            'header_text' => trim((string) ($template['header_text'] ?? '')) ?: null,
            'body_text' => $body,
            'footer_text' => trim((string) ($template['footer_text'] ?? '')) ?: null,
            'status' => trim((string) ($template['status'] ?? 'draft')) ?: 'draft',
            'updated_at' => $now,
        ]);

        return $id;
    }

    $stmt = crm_db()->prepare(
        'INSERT INTO whatsapp_templates
         (name, language, category, header_text, body_text, footer_text, status, created_by, created_at, updated_at)
         VALUES (:name, :language, :category, :header_text, :body_text, :footer_text, :status, :created_by, :created_at, :updated_at)'
    );
    $stmt->execute([
        'name' => $name,
        'language' => $language,
        'category' => $category,
        'header_text' => trim((string) ($template['header_text'] ?? '')) ?: null,
        'body_text' => $body,
        'footer_text' => trim((string) ($template['footer_text'] ?? '')) ?: null,
        'status' => trim((string) ($template['status'] ?? 'draft')) ?: 'draft',
        'created_by' => (int) ($template['created_by'] ?? 0) ?: null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return (int) crm_db()->lastInsertId();
}

function crm_update_whatsapp_template_meta(int $id, array $meta): bool
{
    $stmt = crm_db()->prepare(
        'UPDATE whatsapp_templates
         SET status = :status, meta_template_id = :meta_template_id,
             meta_status = :meta_status, meta_rejection_reason = :meta_rejection_reason,
             updated_at = :updated_at
         WHERE id = :id'
    );

    return $stmt->execute([
        'id' => $id,
        'status' => trim((string) ($meta['status'] ?? 'draft')) ?: 'draft',
        'meta_template_id' => trim((string) ($meta['meta_template_id'] ?? '')) ?: null,
        'meta_status' => trim((string) ($meta['meta_status'] ?? '')) ?: null,
        'meta_rejection_reason' => trim((string) ($meta['meta_rejection_reason'] ?? '')) ?: null,
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}

function crm_delete_whatsapp_template(int $id): bool
{
    if ($id <= 0) {
        return false;
    }

    $stmt = crm_db()->prepare('DELETE FROM whatsapp_templates WHERE id = :id');
    $stmt->execute(['id' => $id]);

    return $stmt->rowCount() > 0;
}

function crm_ensure_user_columns(PDO $pdo): void
{
    $columns = [
        'email' => 'VARCHAR(180) NULL AFTER username',
        'role' => 'VARCHAR(30) NOT NULL DEFAULT "vendedor" AFTER password_hash',
        'active' => 'TINYINT(1) NOT NULL DEFAULT 1 AFTER role',
        'participates_in_rotation' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER active',
        'rotation_weight' => 'INT NOT NULL DEFAULT 1 AFTER participates_in_rotation',
        'last_assigned_at' => 'DATETIME NULL AFTER rotation_weight',
        'access_schedule_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1 AFTER last_assigned_at',
        'access_start_time' => 'TIME NOT NULL DEFAULT "09:00:00" AFTER access_schedule_enabled',
        'access_end_time' => 'TIME NOT NULL DEFAULT "18:00:00" AFTER access_start_time',
        'access_saturday_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1 AFTER access_end_time',
        'access_sunday_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1 AFTER access_saturday_enabled',
    ];

    foreach ($columns as $column => $definition) {
        if (!crm_column_exists($pdo, 'crm_users', $column)) {
            $pdo->exec(sprintf('ALTER TABLE crm_users ADD COLUMN %s %s', $column, $definition));
        }
    }

    if (!crm_index_exists($pdo, 'crm_users', 'idx_crm_users_rotation')) {
        $pdo->exec('ALTER TABLE crm_users ADD INDEX idx_crm_users_rotation (participates_in_rotation, active, last_assigned_at)');
    }

    if (!crm_index_exists($pdo, 'crm_users', 'idx_crm_users_email')) {
        $pdo->exec('ALTER TABLE crm_users ADD INDEX idx_crm_users_email (email)');
    }
}

function crm_seed_default_admin_user(PDO $pdo): void
{
    $config = require dirname(__DIR__) . '/config.php';
    $username = trim((string) ($config['admin_user'] ?? 'admin'));
    $passwordHash = trim((string) ($config['admin_password_hash'] ?? ''));

    if ($username === '' || $passwordHash === '') {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO crm_users
        (name, username, email, password_hash, role, active, participates_in_rotation, rotation_weight, created_at, updated_at)
        VALUES
        (:name, :username, NULL, :password_hash, "admin", 1, 0, 1, :created_at, :updated_at)
        ON DUPLICATE KEY UPDATE
            role = "admin",
            active = 1,
            updated_at = VALUES(updated_at)'
    );
    $stmt->execute([
        'name' => 'Administrador',
        'username' => $username,
        'password_hash' => $passwordHash,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}

function crm_ensure_lead_columns(PDO $pdo): void
{
    $columns = [
        'cpf' => 'VARCHAR(14) NULL AFTER whatsapp',
        'profile_picture_url' => 'TEXT NULL AFTER whatsapp',
        'utm_source' => 'VARCHAR(120) NULL AFTER page',
        'utm_medium' => 'VARCHAR(120) NULL AFTER utm_source',
        'utm_campaign' => 'VARCHAR(180) NULL AFTER utm_medium',
        'utm_content' => 'VARCHAR(180) NULL AFTER utm_campaign',
        'utm_term' => 'VARCHAR(180) NULL AFTER utm_content',
        'referrer' => 'TEXT NULL AFTER utm_term',
        'landing_path' => 'TEXT NULL AFTER referrer',
        'tags' => 'TEXT NULL AFTER notes',
        'kanban_position' => 'INT NOT NULL DEFAULT 0 AFTER status',
        'form_id' => 'VARCHAR(32) NULL AFTER landing_path',
        'form_answers' => 'LONGTEXT NULL AFTER form_id',
        'lead_score' => 'INT NULL AFTER form_answers',
        'lead_temperature' => 'VARCHAR(20) NULL AFTER lead_score',
        'score_reasons' => 'TEXT NULL AFTER lead_temperature',
        'commercial_notes' => 'TEXT NULL AFTER notes',
        'assigned_user_id' => 'INT NULL AFTER tags',
        'assigned_at' => 'DATETIME NULL AFTER assigned_user_id',
        'last_activity_at' => 'DATETIME NULL AFTER assigned_at',
        'last_activity_type' => 'VARCHAR(60) NULL AFTER last_activity_at',
        'sla_last_checked_at' => 'DATETIME NULL AFTER last_activity_type',
        'estimated_value' => 'DECIMAL(12,2) NULL AFTER sla_last_checked_at',
        'proposal_value' => 'DECIMAL(12,2) NULL AFTER estimated_value',
        'expected_close_date' => 'DATE NULL AFTER proposal_value',
        'lost_reason' => 'VARCHAR(180) NULL AFTER expected_close_date',
        'first_contact_at' => 'DATETIME NULL AFTER lost_reason',
        'closed_at' => 'DATETIME NULL AFTER first_contact_at',
        'lost_at' => 'DATETIME NULL AFTER closed_at',
    ];

    foreach ($columns as $column => $definition) {
        if (!crm_column_exists($pdo, 'leads', $column)) {
            $pdo->exec(sprintf('ALTER TABLE leads ADD COLUMN %s %s', $column, $definition));
        }
    }

    foreach (['segment', 'advertises'] as $column) {
        if (crm_column_character_length($pdo, 'leads', $column) < 180) {
            $pdo->exec(sprintf('ALTER TABLE leads MODIFY COLUMN %s VARCHAR(180) NULL', $column));
        }
    }

    if (!crm_index_exists($pdo, 'leads', 'idx_leads_assigned_user')) {
        $pdo->exec('ALTER TABLE leads ADD INDEX idx_leads_assigned_user (assigned_user_id, status, updated_at)');
    }

    if (!crm_index_exists($pdo, 'leads', 'idx_leads_activity')) {
        $pdo->exec('ALTER TABLE leads ADD INDEX idx_leads_activity (last_activity_at, status)');
    }
}

function crm_ensure_flexible_lead_status(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        'SELECT DATA_TYPE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = "leads"
          AND COLUMN_NAME = "status"
        LIMIT 1'
    );
    $stmt->execute();

    if ((string) $stmt->fetchColumn() !== 'varchar') {
        $pdo->exec('ALTER TABLE leads MODIFY COLUMN status VARCHAR(80) NOT NULL DEFAULT "novo"');
    }
}

function crm_default_kanban_columns(): array
{
    return [
        ['status' => 'novo', 'label' => 'Novo', 'position' => 10],
        ['status' => 'contatado', 'label' => 'Contatado', 'position' => 20],
        ['status' => 'followup', 'label' => 'Follow-up', 'position' => 30],
        ['status' => 'proposta', 'label' => 'Proposta enviada', 'position' => 40],
        ['status' => 'fechado', 'label' => 'Fechado', 'position' => 50],
        ['status' => 'perdido', 'label' => 'Perdido', 'position' => 60],
    ];
}

function crm_seed_default_kanban_columns(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO kanban_columns (status, label, position, is_system, active, created_at, updated_at)
        VALUES (:status, :label, :position, 1, 1, :created_at, :updated_at)
        ON DUPLICATE KEY UPDATE
            label = IF(label = "", VALUES(label), label),
            position = IF(position = 0, VALUES(position), position),
            active = 1,
            updated_at = VALUES(updated_at)'
    );

    foreach (crm_default_kanban_columns() as $column) {
        $stmt->execute([
            'status' => $column['status'],
            'label' => $column['label'],
            'position' => $column['position'],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

function crm_read_kanban_columns(bool $onlyActive = true): array
{
    $sql = 'SELECT * FROM kanban_columns';

    if ($onlyActive) {
        $sql .= ' WHERE active = 1';
    }

    $sql .= ' ORDER BY position ASC, created_at ASC';

    return crm_db()->query($sql)->fetchAll();
}

function crm_kanban_status_exists(string $status): bool
{
    $stmt = crm_db()->prepare('SELECT COUNT(*) FROM kanban_columns WHERE status = :status AND active = 1');
    $stmt->execute(['status' => $status]);

    return (int) $stmt->fetchColumn() > 0;
}

function crm_slugify_status(string $label): string
{
    $label = trim($label);
    $ascii = function_exists('iconv') ? (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) : $label;
    $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $ascii));
    $slug = trim($slug, '-');

    return $slug !== '' ? substr($slug, 0, 56) : 'coluna';
}

function crm_create_kanban_column(string $label): ?string
{
    $label = trim($label);

    if ($label === '') {
        return null;
    }

    $baseStatus = crm_slugify_status($label);
    $status = $baseStatus;
    $suffix = 2;

    $exists = crm_db()->prepare('SELECT COUNT(*) FROM kanban_columns WHERE status = :status');

    while (true) {
        $exists->execute(['status' => $status]);

        if ((int) $exists->fetchColumn() === 0) {
            break;
        }

        $status = substr($baseStatus, 0, 52) . '-' . $suffix;
        $suffix++;
    }

    $position = (int) crm_db()->query('SELECT COALESCE(MAX(position), 0) + 10 FROM kanban_columns')->fetchColumn();
    $stmt = crm_db()->prepare(
        'INSERT INTO kanban_columns (status, label, position, is_system, active, created_at, updated_at)
        VALUES (:status, :label, :position, 0, 1, :created_at, :updated_at)'
    );
    $stmt->execute([
        'status' => $status,
        'label' => $label,
        'position' => $position,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    return $status;
}

function crm_update_kanban_columns(array $columns, array $removeStatuses = []): void
{
    $db = crm_db();
    $update = $db->prepare(
        'UPDATE kanban_columns
        SET label = :label,
            position = :position,
            updated_at = :updated_at
        WHERE status = :status'
    );
    $position = 10;

    foreach ($columns as $column) {
        $status = trim((string) ($column['status'] ?? ''));
        $label = trim((string) ($column['label'] ?? ''));

        if ($status === '' || $label === '') {
            continue;
        }

        $update->execute([
            'status' => $status,
            'label' => $label,
            'position' => $position,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $position += 10;
    }

    foreach ($removeStatuses as $status) {
        crm_archive_kanban_column((string) $status);
    }
}

function crm_archive_kanban_column(string $status): bool
{
    $status = trim($status);

    if ($status === '' || $status === 'novo') {
        return false;
    }

    $stmt = crm_db()->prepare('SELECT is_system FROM kanban_columns WHERE status = :status LIMIT 1');
    $stmt->execute(['status' => $status]);
    $column = $stmt->fetch();

    if (!is_array($column) || (int) ($column['is_system'] ?? 0) === 1) {
        return false;
    }

    $moveLeads = crm_db()->prepare('UPDATE leads SET status = "novo", updated_at = :updated_at WHERE status = :status');
    $moveLeads->execute([
        'status' => $status,
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $archive = crm_db()->prepare('UPDATE kanban_columns SET active = 0, updated_at = :updated_at WHERE status = :status');
    $archive->execute([
        'status' => $status,
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    return $archive->rowCount() > 0;
}

function crm_decode_lead_tags(array $lead): array
{
    $raw = trim((string) ($lead['tags'] ?? ''));

    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    if (is_array($decoded)) {
        return array_values(array_filter(array_map('strval', $decoded), static fn(string $tag): bool => trim($tag) !== ''));
    }

    return crm_parse_tags($raw);
}

function crm_parse_tags(string $tags): array
{
    $parts = preg_split('/[,;\n]+/', $tags) ?: [];
    $normalized = [];

    foreach ($parts as $part) {
        $tag = trim((string) $part);

        if ($tag === '') {
            continue;
        }

        $key = function_exists('mb_strtolower') ? mb_strtolower($tag, 'UTF-8') : strtolower($tag);
        $normalized[$key] = substr($tag, 0, 40);
    }

    return array_values($normalized);
}

function crm_encode_tags(string $tags): string
{
    return json_encode(crm_parse_tags($tags), JSON_UNESCAPED_UNICODE) ?: '[]';
}

function crm_normalize_money($value): ?string
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    $value = preg_replace('/[^\d,.\-]/', '', $value) ?? '';

    if ($value === '' || $value === '-') {
        return null;
    }

    if (str_contains($value, ',') && str_contains($value, '.')) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } elseif (str_contains($value, ',')) {
        $value = str_replace(',', '.', $value);
    }

    return is_numeric($value) ? number_format((float) $value, 2, '.', '') : null;
}

function crm_normalize_date($value): ?string
{
    $value = trim((string) $value);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }

    [$year, $month, $day] = array_map('intval', explode('-', $value));

    return checkdate($month, $day, $year) ? $value : null;
}

function crm_read_available_tags(array $leads): array
{
    $tags = [];

    foreach ($leads as $lead) {
        foreach (crm_decode_lead_tags($lead) as $tag) {
            $key = function_exists('mb_strtolower') ? mb_strtolower($tag, 'UTF-8') : strtolower($tag);
            $tags[$key] = $tag;
        }
    }

    natcasesort($tags);

    return array_values($tags);
}

function crm_user_roles(): array
{
    return [
        'admin' => 'Administrador',
        'gestor' => 'Gestor comercial',
        'vendedor' => 'Vendedor',
    ];
}

function crm_normalize_user_role(string $role): string
{
    return array_key_exists($role, crm_user_roles()) ? $role : 'vendedor';
}

function crm_find_user_by_username(string $username): ?array
{
    $username = trim($username);

    if ($username === '') {
        return null;
    }

    $stmt = crm_db()->prepare('SELECT * FROM crm_users WHERE username = :username LIMIT 1');
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    return is_array($user) ? $user : null;
}

function crm_find_user_by_login(string $login): ?array
{
    $login = trim($login);

    if ($login === '') {
        return null;
    }

    // Keep username precedence for backwards compatibility, then allow the
    // e-mail saved in the commercial user form as the login identifier too.
    $user = crm_find_user_by_username($login);

    if (is_array($user)) {
        return $user;
    }

    $stmt = crm_db()->prepare(
        'SELECT * FROM crm_users
         WHERE email = :email
         LIMIT 1'
    );
    $stmt->execute(['email' => $login]);
    $user = $stmt->fetch();

    return is_array($user) ? $user : null;
}

function crm_find_user_by_email(string $email): ?array
{
    $email = trim($email);

    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return null;
    }

    $stmt = crm_db()->prepare(
        'SELECT * FROM crm_users
         WHERE email = :email AND active = 1
         LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    return is_array($user) ? $user : null;
}

function crm_create_password_reset_token(int $userId, ?string $requestIp = null): ?string
{
    if ($userId <= 0) {
        return null;
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $now = date('Y-m-d H:i:s');
    $expiresAt = date('Y-m-d H:i:s', time() + 3600);
    $pdo = crm_db();

    $pdo->beginTransaction();

    try {
        $cleanup = $pdo->prepare(
            'DELETE FROM crm_password_reset_tokens
             WHERE user_id = :user_id OR expires_at <= :now'
        );
        $cleanup->execute([
            'user_id' => $userId,
            'now' => $now,
        ]);

        $insert = $pdo->prepare(
            'INSERT INTO crm_password_reset_tokens
             (user_id, token_hash, expires_at, request_ip, created_at)
             VALUES (:user_id, :token_hash, :expires_at, :request_ip, :created_at)'
        );
        $insert->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'request_ip' => $requestIp !== null ? substr($requestIp, 0, 45) : null,
            'created_at' => $now,
        ]);
        $pdo->commit();

        return $token;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }
}

function crm_password_reset_token_is_valid(string $token): bool
{
    $token = trim($token);

    if ($token === '' || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
        return false;
    }

    $stmt = crm_db()->prepare(
        'SELECT COUNT(*)
         FROM crm_password_reset_tokens AS prt
         INNER JOIN crm_users AS account ON account.id = prt.user_id
         WHERE prt.token_hash = :token_hash
           AND prt.used_at IS NULL
           AND prt.expires_at > :now
           AND account.active = 1'
    );
    $stmt->execute([
        'token_hash' => hash('sha256', $token),
        'now' => date('Y-m-d H:i:s'),
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function crm_consume_password_reset_token(string $token, string $password): array
{
    $token = trim($token);

    if ($token === '' || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
        return ['ok' => false, 'error' => 'Este link é inválido ou expirou.'];
    }

    if (strlen($password) < 6) {
        return ['ok' => false, 'error' => 'Use uma senha com pelo menos 6 caracteres.'];
    }

    $pdo = crm_db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'SELECT prt.id, prt.user_id
             FROM crm_password_reset_tokens AS prt
             INNER JOIN crm_users AS account ON account.id = prt.user_id
             WHERE prt.token_hash = :token_hash
               AND prt.used_at IS NULL
               AND prt.expires_at > :now
               AND account.active = 1
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([
            'token_hash' => hash('sha256', $token),
            'now' => date('Y-m-d H:i:s'),
        ]);
        $reset = $stmt->fetch();

        if (!is_array($reset)) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Este link é inválido ou expirou.'];
        }

        $now = date('Y-m-d H:i:s');
        $updateUser = $pdo->prepare(
            'UPDATE crm_users
             SET password_hash = :password_hash, updated_at = :updated_at
             WHERE id = :id'
        );
        $updateUser->execute([
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'updated_at' => $now,
            'id' => (int) $reset['user_id'],
        ]);

        $consume = $pdo->prepare(
            'UPDATE crm_password_reset_tokens
             SET used_at = :used_at
             WHERE id = :id'
        );
        $consume->execute([
            'used_at' => $now,
            'id' => (int) $reset['id'],
        ]);

        $pdo->commit();

        return ['ok' => true];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }
}

function crm_find_user_by_id(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $stmt = crm_db()->prepare('SELECT * FROM crm_users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $user = $stmt->fetch();

    return is_array($user) ? $user : null;
}

function crm_read_users(bool $includeInactive = true): array
{
    $sql = 'SELECT * FROM crm_users';

    if (!$includeInactive) {
        $sql .= ' WHERE active = 1';
    }

    $sql .= ' ORDER BY active DESC, role ASC, name ASC, username ASC';

    return crm_db()->query($sql)->fetchAll();
}

function crm_read_assignable_users(bool $rotationOnly = false, ?int $excludeUserId = null): array
{
    $sql = 'SELECT * FROM crm_users
        WHERE active = 1
          AND role IN ("gestor", "vendedor")';
    $params = [];

    if ($rotationOnly) {
        $sql .= ' AND participates_in_rotation = 1';
    }

    if ($excludeUserId !== null && $excludeUserId > 0) {
        $sql .= ' AND id <> :exclude_user_id';
        $params['exclude_user_id'] = $excludeUserId;
    }

    if ($rotationOnly) {
        $sql .= ' ORDER BY (TIMESTAMPDIFF(SECOND, COALESCE(last_assigned_at, "1970-01-01 00:00:00"), NOW()) * GREATEST(rotation_weight, 1)) DESC, name ASC, id ASC';
    } else {
        $sql .= ' ORDER BY name ASC, username ASC, id ASC';
    }

    $stmt = crm_db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function crm_user_label(?array $user): string
{
    if (!is_array($user)) {
        return 'Sem vendedor';
    }

    $name = trim((string) ($user['name'] ?? ''));

    if ($name !== '') {
        return $name;
    }

    return trim((string) ($user['username'] ?? 'Usuário'));
}

function crm_current_storage_user(): ?array
{
    if (!function_exists('crm_current_user')) {
        return null;
    }

    $user = crm_current_user();

    return is_array($user) ? $user : null;
}

function crm_user_can_manage_lead_scope(?array $user): bool
{
    $role = (string) ($user['role'] ?? '');
    return in_array($role, ['admin', 'gestor'], true);
}

function crm_lead_access_sql(string $alias = 'leads', bool $applyAccess = true): array
{
    if (!$applyAccess) {
        return ['', []];
    }

    $user = crm_current_storage_user();

    if ($user === null) {
        if (function_exists('crm_is_logged_in') && crm_is_logged_in()) {
            return [' AND 1 = 0', []];
        }

        return ['', []];
    }

    if (crm_user_can_manage_lead_scope($user)) {
        return ['', []];
    }

    $userId = (int) ($user['id'] ?? 0);

    if ($userId <= 0) {
        return [' AND 1 = 0', []];
    }

    return [' AND ' . $alias . '.assigned_user_id = :viewer_user_id', ['viewer_user_id' => $userId]];
}

function crm_user_can_access_lead(array $lead): bool
{
    $user = crm_current_storage_user();

    if ($user === null) {
        return !(function_exists('crm_is_logged_in') && crm_is_logged_in());
    }

    if (crm_user_can_manage_lead_scope($user)) {
        return true;
    }

    return (int) ($lead['assigned_user_id'] ?? 0) === (int) ($user['id'] ?? 0);
}

function crm_validate_assignable_user_id(?int $userId): ?int
{
    if ($userId === null || $userId <= 0) {
        return null;
    }

    $user = crm_find_user_by_id($userId);

    if (!is_array($user) || (int) ($user['active'] ?? 0) !== 1) {
        return null;
    }

    return in_array((string) ($user['role'] ?? ''), ['gestor', 'vendedor'], true) ? (int) $user['id'] : null;
}

function crm_pick_rotation_user(?int $excludeUserId = null): ?array
{
    $users = crm_read_assignable_users(true, $excludeUserId);

    if (count($users) > 0) {
        return $users[0];
    }

    if ($excludeUserId !== null) {
        $fallback = crm_read_assignable_users(true);
        return $fallback[0] ?? null;
    }

    return null;
}

function crm_pick_manager_user(?int $excludeUserId = null): ?array
{
    $sql = 'SELECT * FROM crm_users WHERE active = 1 AND role = "gestor"';
    $params = [];

    if ($excludeUserId !== null && $excludeUserId > 0) {
        $sql .= ' AND id <> :exclude_user_id';
        $params['exclude_user_id'] = $excludeUserId;
    }

    $sql .= ' ORDER BY name ASC, username ASC, id ASC LIMIT 1';
    $stmt = crm_db()->prepare($sql);
    $stmt->execute($params);
    $user = $stmt->fetch();

    return is_array($user) ? $user : null;
}

function crm_record_lead_assignment(string $leadId, ?int $fromUserId, ?int $toUserId, string $action, string $reason = '', ?int $createdByUserId = null): void
{
    $stmt = crm_db()->prepare(
        'INSERT INTO lead_assignment_logs
        (lead_id, from_user_id, to_user_id, action, reason, created_by_user_id, created_at)
        VALUES
        (:lead_id, :from_user_id, :to_user_id, :action, :reason, :created_by_user_id, :created_at)'
    );
    $stmt->execute([
        'lead_id' => $leadId,
        'from_user_id' => $fromUserId,
        'to_user_id' => $toUserId,
        'action' => substr(trim($action), 0, 40),
        'reason' => substr(trim($reason), 0, 255),
        'created_by_user_id' => $createdByUserId,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

function crm_touch_user_assigned_at(?int $userId): void
{
    if ($userId === null || $userId <= 0) {
        return;
    }

    $stmt = crm_db()->prepare('UPDATE crm_users SET last_assigned_at = :assigned_at, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        'id' => $userId,
        'assigned_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}

function crm_assign_lead_to_user(string $leadId, ?int $toUserId, string $action, string $reason = '', ?int $createdByUserId = null, bool $applyAccess = true): bool
{
    $lead = crm_find_lead($leadId, $applyAccess);

    if ($lead === null) {
        return false;
    }

    $toUserId = crm_validate_assignable_user_id($toUserId);
    $fromUserId = isset($lead['assigned_user_id']) && $lead['assigned_user_id'] !== null ? (int) $lead['assigned_user_id'] : null;

    if ($fromUserId === $toUserId) {
        return true;
    }

    [$accessSql, $accessParams] = crm_lead_access_sql('leads', $applyAccess);
    $stmt = crm_db()->prepare(
        'UPDATE leads
        SET assigned_user_id = :assigned_user_id,
            assigned_at = :assigned_at,
            last_activity_at = :last_activity_at,
            last_activity_type = :last_activity_type,
            updated_at = :updated_at
        WHERE id = :id' . $accessSql
    );
    $now = date('Y-m-d H:i:s');
    $stmt->execute([
        'id' => $leadId,
        'assigned_user_id' => $toUserId,
        'assigned_at' => $toUserId !== null ? $now : null,
        'last_activity_at' => $now,
        'last_activity_type' => $action,
        'updated_at' => $now,
    ] + $accessParams);

    if ($stmt->rowCount() === 0) {
        return false;
    }

    crm_record_lead_assignment($leadId, $fromUserId, $toUserId, $action, $reason, $createdByUserId);
    crm_touch_user_assigned_at($toUserId);

    return true;
}

function crm_save_user(array $payload): array
{
    $id = max(0, (int) ($payload['id'] ?? 0));
    $name = trim((string) ($payload['name'] ?? ''));
    $username = trim((string) ($payload['username'] ?? ''));
    $email = trim((string) ($payload['email'] ?? ''));
    $password = (string) ($payload['password'] ?? '');
    $role = crm_normalize_user_role((string) ($payload['role'] ?? 'vendedor'));
    $active = !empty($payload['active']) ? 1 : 0;
    $participates = !empty($payload['participates_in_rotation']) ? 1 : 0;
    $weight = max(1, min(10, (int) ($payload['rotation_weight'] ?? 1)));
    $accessScheduleEnabled = !empty($payload['access_schedule_enabled']) ? 1 : 0;
    $accessStartTime = crm_normalize_user_access_time((string) ($payload['access_start_time'] ?? ''), '09:00:00');
    $accessEndTime = crm_normalize_user_access_time((string) ($payload['access_end_time'] ?? ''), '18:00:00');
    $accessSaturdayEnabled = !empty($payload['access_saturday_enabled']) ? 1 : 0;
    $accessSundayEnabled = !empty($payload['access_sunday_enabled']) ? 1 : 0;

    if ($name === '' || $username === '') {
        return ['ok' => false, 'error' => 'Informe nome e usuário.'];
    }

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return ['ok' => false, 'error' => 'Informe um e-mail válido.'];
    }

    if ($id === 0 && trim($password) === '') {
        return ['ok' => false, 'error' => 'Informe uma senha para criar o usuário.'];
    }

    if (trim($password) !== '' && strlen($password) < 6) {
        return ['ok' => false, 'error' => 'Use uma senha com pelo menos 6 caracteres.'];
    }

    if ($accessScheduleEnabled === 1 && $accessStartTime >= $accessEndTime) {
        return ['ok' => false, 'error' => 'O início do acesso deve ser anterior ao fim do acesso.'];
    }

    try {
        if ($id > 0) {
            $existing = crm_find_user_by_id($id);

            if ($existing === null) {
                return ['ok' => false, 'error' => 'Usuário não encontrado.'];
            }

            $currentUser = crm_current_storage_user();

            if (is_array($currentUser) && (int) ($currentUser['id'] ?? 0) === $id && ($active !== 1 || $role !== 'admin')) {
                return ['ok' => false, 'error' => 'Você não pode remover seu próprio acesso de administrador.'];
            }

            $fields = [
                'name = :name',
                'username = :username',
                'email = :email',
                'role = :role',
                'active = :active',
                'participates_in_rotation = :participates_in_rotation',
                'rotation_weight = :rotation_weight',
                'access_schedule_enabled = :access_schedule_enabled',
                'access_start_time = :access_start_time',
                'access_end_time = :access_end_time',
                'access_saturday_enabled = :access_saturday_enabled',
                'access_sunday_enabled = :access_sunday_enabled',
                'updated_at = :updated_at',
            ];
            $params = [
                'id' => $id,
                'name' => $name,
                'username' => $username,
                'email' => $email !== '' ? $email : null,
                'role' => $role,
                'active' => $active,
                'participates_in_rotation' => $participates,
                'rotation_weight' => $weight,
                'access_schedule_enabled' => $accessScheduleEnabled,
                'access_start_time' => $accessStartTime,
                'access_end_time' => $accessEndTime,
                'access_saturday_enabled' => $accessSaturdayEnabled,
                'access_sunday_enabled' => $accessSundayEnabled,
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if (trim($password) !== '') {
                $fields[] = 'password_hash = :password_hash';
                $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }

            $stmt = crm_db()->prepare('UPDATE crm_users SET ' . implode(', ', $fields) . ' WHERE id = :id');
            $stmt->execute($params);

            return ['ok' => true, 'id' => $id];
        }

        $stmt = crm_db()->prepare(
            'INSERT INTO crm_users
            (name, username, email, password_hash, role, active, participates_in_rotation, rotation_weight,
             access_schedule_enabled, access_start_time, access_end_time,
             access_saturday_enabled, access_sunday_enabled, created_at, updated_at)
            VALUES
            (:name, :username, :email, :password_hash, :role, :active, :participates_in_rotation, :rotation_weight,
             :access_schedule_enabled, :access_start_time, :access_end_time,
             :access_saturday_enabled, :access_sunday_enabled, :created_at, :updated_at)'
        );
        $stmt->execute([
            'name' => $name,
            'username' => $username,
            'email' => $email !== '' ? $email : null,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'active' => $active,
            'participates_in_rotation' => $participates,
            'rotation_weight' => $weight,
            'access_schedule_enabled' => $accessScheduleEnabled,
            'access_start_time' => $accessStartTime,
            'access_end_time' => $accessEndTime,
            'access_saturday_enabled' => $accessSaturdayEnabled,
            'access_sunday_enabled' => $accessSundayEnabled,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return ['ok' => true, 'id' => (int) crm_db()->lastInsertId()];
    } catch (PDOException $error) {
        if ($error->getCode() === '23000') {
            return ['ok' => false, 'error' => 'Este usuário já está em uso.'];
        }

        throw $error;
    }
}

function crm_count_active_admins(): int
{
    $stmt = crm_db()->query('SELECT COUNT(*) FROM crm_users WHERE role = "admin" AND active = 1');
    return (int) $stmt->fetchColumn();
}

function crm_delete_user(int $id): array
{
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'Usuário inválido.'];
    }

    $user = crm_find_user_by_id($id);

    if ($user === null) {
        return ['ok' => false, 'error' => 'Usuário não encontrado.'];
    }

    $currentUser = crm_current_storage_user();

    if (is_array($currentUser) && (int) ($currentUser['id'] ?? 0) === $id) {
        return ['ok' => false, 'error' => 'Você não pode excluir o próprio acesso.'];
    }

    if ((string) ($user['role'] ?? '') === 'admin' && crm_count_active_admins() <= 1) {
        return ['ok' => false, 'error' => 'Mantenha pelo menos um administrador ativo no CRM.'];
    }

    $db = crm_db();
    $db->beginTransaction();

    try {
        $assignedLeads = $db->prepare('SELECT id FROM leads WHERE assigned_user_id = :user_id');
        $assignedLeads->execute(['user_id' => $id]);
        $leads = $assignedLeads->fetchAll();

        foreach ($leads as $lead) {
            crm_record_lead_assignment(
                (string) ($lead['id'] ?? ''),
                $id,
                null,
                'user_deleted',
                'Usuário excluído da área comercial.',
                is_array($currentUser) ? (int) ($currentUser['id'] ?? 0) ?: null : null
            );
        }

        $clearLeads = $db->prepare(
            'UPDATE leads
            SET assigned_user_id = NULL,
                assigned_at = NULL,
                last_activity_at = :last_activity_at,
                last_activity_type = "user_deleted",
                updated_at = :updated_at
            WHERE assigned_user_id = :user_id'
        );
        $now = date('Y-m-d H:i:s');
        $clearLeads->execute([
            'user_id' => $id,
            'last_activity_at' => $now,
            'updated_at' => $now,
        ]);

        $delete = $db->prepare('DELETE FROM crm_users WHERE id = :id');
        $delete->execute(['id' => $id]);

        $db->commit();

        return ['ok' => true];
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        throw $error;
    }
}

function crm_read_leads(): array
{
    [$accessSql, $accessParams] = crm_lead_access_sql('leads');
    $stmt = crm_db()->prepare(
        'SELECT leads.*, crm_users.name AS assigned_user_name, crm_users.username AS assigned_username
        FROM leads
        LEFT JOIN crm_users ON crm_users.id = leads.assigned_user_id
        WHERE 1 = 1' . $accessSql . '
        ORDER BY leads.status ASC, leads.kanban_position ASC, leads.created_at DESC'
    );
    $stmt->execute($accessParams);

    return $stmt->fetchAll();
}

function crm_read_lead_feed_version(): string
{
    [$accessSql, $accessParams] = crm_lead_access_sql('leads');
    $stmt = crm_db()->prepare(
        'SELECT leads.id, leads.status, leads.assigned_user_id,
                leads.updated_at, leads.last_activity_at
         FROM leads
         WHERE 1 = 1' . $accessSql . '
         ORDER BY leads.status ASC, leads.kanban_position ASC, leads.created_at DESC'
    );
    $stmt->execute($accessParams);

    $versionRows = [];

    foreach ($stmt->fetchAll() as $lead) {
        $versionRows[] = [
            (string) ($lead['id'] ?? ''),
            (string) ($lead['status'] ?? ''),
            (string) ($lead['assigned_user_id'] ?? ''),
            (string) ($lead['updated_at'] ?? ''),
            (string) ($lead['last_activity_at'] ?? ''),
        ];
    }

    return hash(
        'sha256',
        json_encode($versionRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''
    );
}

function crm_read_lead_summaries(): array
{
    [$accessSql, $accessParams] = crm_lead_access_sql('leads');
    $stmt = crm_db()->prepare(
        'SELECT leads.id, leads.name, leads.whatsapp, leads.profile_picture_url,
                leads.cpf, leads.message, leads.notes, leads.tags, leads.status,
                leads.kanban_position, leads.assigned_user_id, leads.created_at,
                leads.updated_at, leads.last_activity_at, leads.page,
                leads.utm_source, leads.landing_path,
                crm_users.name AS assigned_user_name, crm_users.username AS assigned_username
        FROM leads
        LEFT JOIN crm_users ON crm_users.id = leads.assigned_user_id
        WHERE 1 = 1' . $accessSql . '
        ORDER BY leads.status ASC, leads.kanban_position ASC, leads.created_at DESC'
    );
    $stmt->execute($accessParams);

    return $stmt->fetchAll();
}

function crm_read_whatsapp_conversation_count(int $userId, string $conversationKey): ?int
{
    if ($userId <= 0 || trim($conversationKey) === '') {
        return null;
    }

    $stmt = crm_db()->prepare(
        'SELECT read_incoming_count
         FROM whatsapp_conversation_reads
         WHERE user_id = :user_id AND conversation_key = :conversation_key
         LIMIT 1'
    );
    $stmt->execute([
        'user_id' => $userId,
        'conversation_key' => trim($conversationKey),
    ]);
    $count = $stmt->fetchColumn();

    return $count === false ? null : max(0, (int) $count);
}

/**
 * Reads all inbox read markers in one query. The WhatsApp inbox can contain
 * many conversations, so querying the marker table once per row makes every
 * page navigation progressively slower.
 *
 * @param list<string> $conversationKeys
 * @return array<string, int>
 */
function crm_read_whatsapp_conversation_counts(int $userId, array $conversationKeys): array
{
    if ($userId <= 0) {
        return [];
    }

    $keys = array_values(array_unique(array_filter(
        array_map(static fn($key): string => trim((string) $key), $conversationKeys),
        static fn(string $key): bool => $key !== ''
    )));

    if ($keys === []) {
        return [];
    }

    $placeholders = [];
    $params = ['user_id' => $userId];

    foreach ($keys as $index => $key) {
        $parameter = 'conversation_key_' . $index;
        $placeholders[] = ':' . $parameter;
        $params[$parameter] = $key;
    }

    $stmt = crm_db()->prepare(
        'SELECT conversation_key, read_incoming_count
         FROM whatsapp_conversation_reads
         WHERE user_id = :user_id
           AND conversation_key IN (' . implode(', ', $placeholders) . ')'
    );
    $stmt->execute($params);

    $counts = [];

    foreach ($stmt->fetchAll() as $row) {
        $key = trim((string) ($row['conversation_key'] ?? ''));

        if ($key !== '') {
            $counts[$key] = max(0, (int) ($row['read_incoming_count'] ?? 0));
        }
    }

    return $counts;
}

function crm_register_new_whatsapp_conversation(int $userId, string $conversationKey): bool
{
    $conversationKey = trim($conversationKey);

    if ($userId <= 0 || $conversationKey === '') {
        return false;
    }

    $stmt = crm_db()->prepare(
        'INSERT INTO whatsapp_conversation_reads
            (user_id, conversation_key, read_incoming_count, updated_at)
         VALUES (:user_id, :conversation_key, 0, :updated_at)
         ON DUPLICATE KEY UPDATE
            read_incoming_count = 0,
            updated_at = VALUES(updated_at)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'conversation_key' => $conversationKey,
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    return true;
}

function crm_register_new_whatsapp_conversation_for_active_users(string $conversationKey): void
{
    $conversationKey = trim($conversationKey);

    if ($conversationKey === '') {
        return;
    }

    $users = crm_db()->query('SELECT id FROM crm_users WHERE active = 1')->fetchAll();

    foreach ($users as $user) {
        crm_register_new_whatsapp_conversation((int) ($user['id'] ?? 0), $conversationKey);
    }
}

function crm_mark_whatsapp_conversation_read(int $userId, string $conversationKey, int $incomingCount): bool
{
    $conversationKey = trim($conversationKey);

    if ($userId <= 0 || $conversationKey === '') {
        return false;
    }

    $stmt = crm_db()->prepare(
        'INSERT INTO whatsapp_conversation_reads
            (user_id, conversation_key, read_incoming_count, updated_at)
         VALUES (:user_id, :conversation_key, :read_incoming_count, :updated_at)
         ON DUPLICATE KEY UPDATE
            read_incoming_count = VALUES(read_incoming_count),
            updated_at = VALUES(updated_at)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'conversation_key' => $conversationKey,
        'read_incoming_count' => max(0, $incomingCount),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    return true;
}

function crm_normalize_lead_whatsapp(string $phone): string
{
    return crm_whatsapp_number_variants($phone)[0] ?? '';
}

function crm_normalize_profile_picture_url(string $url): string
{
    $url = trim($url);

    if ($url === '' || strlen($url) > 4000 || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return '';
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

    return in_array($scheme, ['http', 'https'], true) ? $url : '';
}

function crm_normalize_cpf(string $cpf): string
{
    $digits = preg_replace('/\D+/', '', $cpf) ?? '';

    return substr($digits, 0, 11);
}

function crm_lead_has_cpf(array $lead): bool
{
    return strlen(crm_normalize_cpf((string) ($lead['cpf'] ?? ''))) === 11;
}

function crm_format_cpf(string $cpf): string
{
    $digits = crm_normalize_cpf($cpf);

    if (strlen($digits) !== 11) {
        return $digits;
    }

    return substr($digits, 0, 3) . '.'
        . substr($digits, 3, 3) . '.'
        . substr($digits, 6, 3) . '-'
        . substr($digits, 9, 2);
}

function crm_find_lead_by_whatsapp(string $whatsapp): ?array
{
    $variants = crm_whatsapp_number_variants($whatsapp);

    if ($variants === []) {
        return null;
    }

    $stmt = crm_db()->query('SELECT * FROM leads ORDER BY created_at DESC');

    foreach ($stmt->fetchAll() as $lead) {
        $leadVariants = crm_whatsapp_number_variants((string) ($lead['whatsapp'] ?? ''));

        if (array_intersect($variants, $leadVariants) !== []) {
            return $lead;
        }
    }

    return null;
}

function crm_create_lead(array $payload): array
{
    $payload = crm_apply_page_attribution($payload);
    $settings = crm_sales_distribution_settings();
    $assignedUserId = crm_validate_assignable_user_id(isset($payload['assigned_user_id']) ? (int) $payload['assigned_user_id'] : null);
    $assignmentAction = $assignedUserId !== null ? 'manual_assignment' : '';
    $assignmentReason = $assignedUserId !== null ? 'Atribuição informada na criação do lead.' : '';

    if ($assignedUserId === null && $settings['rotation_enabled']) {
        $rotationUser = crm_pick_rotation_user();

        if (is_array($rotationUser)) {
            $assignedUserId = (int) $rotationUser['id'];
            $assignmentAction = 'rotation_assignment';
            $assignmentReason = 'Lead atribuído automaticamente pela roleta.';
        }
    }

    $now = date('Y-m-d H:i:s');
    $lead = [
        'id' => bin2hex(random_bytes(8)),
        'name' => trim((string) ($payload['name'] ?? '')),
        'whatsapp' => crm_normalize_lead_whatsapp((string) ($payload['whatsapp'] ?? '')),
        'profile_picture_url' => crm_normalize_profile_picture_url((string) ($payload['profile_picture_url'] ?? '')),
        'cpf' => crm_normalize_cpf((string) ($payload['cpf'] ?? '')),
        'company' => trim((string) ($payload['company'] ?? '')),
        'segment' => trim((string) ($payload['segment'] ?? '')),
        'advertises' => trim((string) ($payload['advertises'] ?? '')),
        'message' => trim((string) ($payload['message'] ?? '')),
        'page' => trim((string) ($payload['page'] ?? '')),
        'utm_source' => trim((string) ($payload['utm_source'] ?? '')),
        'utm_medium' => trim((string) ($payload['utm_medium'] ?? '')),
        'utm_campaign' => trim((string) ($payload['utm_campaign'] ?? '')),
        'utm_content' => trim((string) ($payload['utm_content'] ?? '')),
        'utm_term' => trim((string) ($payload['utm_term'] ?? '')),
        'referrer' => trim((string) ($payload['referrer'] ?? '')),
        'landing_path' => trim((string) ($payload['landing_path'] ?? '')),
        'form_id' => trim((string) ($payload['form_id'] ?? '')) ?: null,
        'form_answers' => json_encode($payload['form_answers'] ?? [], JSON_UNESCAPED_UNICODE) ?: '[]',
        'lead_score' => isset($payload['lead_score']) ? max(0, min(100, (int) $payload['lead_score'])) : null,
        'lead_temperature' => trim((string) ($payload['lead_temperature'] ?? '')) ?: null,
        'score_reasons' => json_encode($payload['score_reasons'] ?? [], JSON_UNESCAPED_UNICODE) ?: '[]',
        'status' => crm_kanban_status_exists(trim((string) ($payload['status'] ?? '')))
            ? trim((string) ($payload['status'] ?? ''))
            : 'novo',
        'notes' => '',
        'commercial_notes' => '',
        'tags' => crm_encode_tags((string) ($payload['tags'] ?? '')),
        'assigned_user_id' => $assignedUserId,
        'assigned_at' => $assignedUserId !== null ? $now : null,
        'last_activity_at' => $now,
        'last_activity_type' => 'created',
        'sla_last_checked_at' => null,
        'estimated_value' => crm_normalize_money($payload['estimated_value'] ?? ''),
        'proposal_value' => crm_normalize_money($payload['proposal_value'] ?? ''),
        'expected_close_date' => crm_normalize_date($payload['expected_close_date'] ?? ''),
        'lost_reason' => trim((string) ($payload['lost_reason'] ?? '')) ?: null,
        'first_contact_at' => null,
        'closed_at' => null,
        'lost_at' => null,
        'whatsapp_status' => 'pendente',
        'whatsapp_sent_at' => null,
        'whatsapp_error' => null,
        'followup_flow_id' => null,
        'followup_started_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ];

    $stmt = crm_db()->prepare(
        'INSERT INTO leads
        (id, name, whatsapp, profile_picture_url, cpf, company, segment, advertises, message, page, utm_source, utm_medium, utm_campaign, utm_content, utm_term, referrer, landing_path, form_id, form_answers, lead_score, lead_temperature, score_reasons, status, notes, commercial_notes, tags, assigned_user_id, assigned_at, last_activity_at, last_activity_type, sla_last_checked_at, estimated_value, proposal_value, expected_close_date, lost_reason, first_contact_at, closed_at, lost_at, whatsapp_status, whatsapp_sent_at, whatsapp_error, followup_flow_id, followup_started_at, created_at, updated_at)
        VALUES
        (:id, :name, :whatsapp, :profile_picture_url, :cpf, :company, :segment, :advertises, :message, :page, :utm_source, :utm_medium, :utm_campaign, :utm_content, :utm_term, :referrer, :landing_path, :form_id, :form_answers, :lead_score, :lead_temperature, :score_reasons, :status, :notes, :commercial_notes, :tags, :assigned_user_id, :assigned_at, :last_activity_at, :last_activity_type, :sla_last_checked_at, :estimated_value, :proposal_value, :expected_close_date, :lost_reason, :first_contact_at, :closed_at, :lost_at, :whatsapp_status, :whatsapp_sent_at, :whatsapp_error, :followup_flow_id, :followup_started_at, :created_at, :updated_at)'
    );
    $stmt->execute($lead);

    if ($assignedUserId !== null) {
        crm_record_lead_assignment((string) $lead['id'], null, $assignedUserId, $assignmentAction, $assignmentReason, null);
        crm_touch_user_assigned_at($assignedUserId);
    }

    if ($lead['whatsapp'] !== '') {
        crm_register_new_whatsapp_conversation_for_active_users(
            crm_normalize_lead_whatsapp((string) $lead['whatsapp'])
        );
    }

    return $lead;
}

function crm_create_lead_once(array $payload): array
{
    $whatsapp = (string) ($payload['whatsapp'] ?? '');
    $normalizedWhatsapp = crm_normalize_lead_whatsapp($whatsapp);

    // A consulta seguida do INSERT precisa ser protegida como uma única
    // operação. Webhooks e envios repetidos podem chegar ao mesmo tempo.
    if ($normalizedWhatsapp === '') {
        $lead = crm_create_lead($payload);
        crm_notify_created_lead_push($lead);

        return ['lead' => $lead, 'created' => true];
    }

    $db = crm_db();
    $lockName = 'crm_lead_whatsapp_' . substr(hash('sha256', $normalizedWhatsapp), 0, 40);
    $lockStmt = $db->prepare('SELECT GET_LOCK(:lock_name, 10)');
    $lockStmt->execute(['lock_name' => $lockName]);
    $lockAcquired = (int) $lockStmt->fetchColumn() === 1;

    if (!$lockAcquired) {
        throw new RuntimeException('Não foi possível reservar o WhatsApp para deduplicação.');
    }

    try {
        $existingLead = crm_find_lead_by_whatsapp($whatsapp);

        if ($existingLead !== null) {
            return [
                'lead' => $existingLead,
                'created' => false,
            ];
        }

        $lead = crm_create_lead($payload);
        crm_notify_created_lead_push($lead);

        return ['lead' => $lead, 'created' => true];
    } finally {
        $unlockStmt = $db->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $unlockStmt->execute(['lock_name' => $lockName]);
    }
}

function crm_notify_created_lead_push(array $lead): void
{
    try {
        require_once __DIR__ . '/push.php';
        $result = crm_push_notify_lead_created($lead);

        if (($result['ok'] ?? false) !== true && ($result['skipped'] ?? false) !== true) {
            error_log('Erro ao enviar push do Lead ' . (string) ($lead['id'] ?? '') . ': ' . json_encode($result, JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $error) {
        // A notificação não pode impedir o lead de ser salvo no CRM.
        error_log('Erro ao enviar push do Lead ' . (string) ($lead['id'] ?? '') . ': ' . $error->getMessage());
    }
}

function crm_notify_lead_reply_push(array $lead, string $message = ''): void
{
    try {
        require_once __DIR__ . '/push.php';
        $result = crm_push_notify_lead_reply($lead, $message);

        if (($result['ok'] ?? false) !== true && ($result['skipped'] ?? false) !== true) {
            error_log('Erro ao enviar push de resposta do Lead ' . (string) ($lead['id'] ?? '') . ': ' . json_encode($result, JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $error) {
        // A notificação não pode impedir a resposta de ser registrada no CRM.
        error_log('Erro ao enviar push de resposta do Lead ' . (string) ($lead['id'] ?? '') . ': ' . $error->getMessage());
    }
}

function crm_append_lead_note(string $id, string $note): bool
{
    $note = trim($note);

    if ($note === '') {
        return false;
    }

    $lead = crm_find_lead($id);

    if ($lead === null) {
        return false;
    }

    $existingNotes = trim((string) ($lead['notes'] ?? ''));
    $notes = $existingNotes === '' ? $note : $existingNotes . "\n\n" . $note;
    $firstContactSql = '';
    $firstContactParams = [];

    if (
        (string) ($lead['first_contact_at'] ?? '') === ''
        && (
            str_starts_with($note, 'Mensagem enviada')
            || str_starts_with($note, 'Agendamento criado')
        )
    ) {
        $firstContactSql = ', first_contact_at = :first_contact_at';
        $firstContactParams['first_contact_at'] = date('Y-m-d H:i:s');
    }

    [$accessSql, $accessParams] = crm_lead_access_sql('leads');
    $stmt = crm_db()->prepare(
        'UPDATE leads
        SET notes = :notes,
            last_activity_at = :last_activity_at,
            last_activity_type = :last_activity_type,
            updated_at = :updated_at' . $firstContactSql . '
        WHERE id = :id' . $accessSql
    );
    $now = date('Y-m-d H:i:s');
    $stmt->execute([
        'id' => $id,
        'notes' => $notes,
        'last_activity_at' => $now,
        'last_activity_type' => 'note',
        'updated_at' => $now,
    ] + $firstContactParams + $accessParams);

    return $stmt->rowCount() > 0;
}

function crm_lead_has_whatsapp_message_id(array $lead, string $messageId): bool
{
    $messageId = trim($messageId);

    if ($messageId === '') {
        return false;
    }

    $notes = (string) ($lead['notes'] ?? '');
    $lineMarker = 'CRM message ID: ' . $messageId;

    if (preg_match('/(?:^|\R)' . preg_quote($lineMarker, '/') . '(?:\R|$)/u', $notes) === 1) {
        return true;
    }

    // Media messages recorded by older CRM versions use the message ID
    // inside their structured [crm_media] payload.
    $jsonMarker = json_encode($messageId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($jsonMarker)
        && str_contains($notes, '"crm_message_id":' . $jsonMarker);
}

function crm_find_lead_by_pilot_status_message_id(string $messageId): ?array
{
    $messageId = trim($messageId);

    if ($messageId === '') {
        return null;
    }

    $stmt = crm_db()->prepare(
        'SELECT *
        FROM leads
        WHERE notes LIKE :message_marker
        ORDER BY updated_at DESC
        LIMIT 1'
    );
    $stmt->execute(['message_marker' => '%Pilot Status ID: ' . $messageId . '%']);
    $lead = $stmt->fetch();

    return is_array($lead) ? $lead : null;
}

function crm_update_lead_profile_picture(string $id, string $url): bool
{
    $url = crm_normalize_profile_picture_url($url);

    if ($url === '') {
        return false;
    }

    [$accessSql, $accessParams] = crm_lead_access_sql('leads', false);
    $stmt = crm_db()->prepare(
        'UPDATE leads
         SET profile_picture_url = :profile_picture_url,
             updated_at = :updated_at
         WHERE id = :id' . $accessSql
    );
    $stmt->execute([
        'id' => $id,
        'profile_picture_url' => $url,
        'updated_at' => date('Y-m-d H:i:s'),
    ] + $accessParams);

    return $stmt->rowCount() > 0;
}

function crm_update_lead(string $id, array $updates): bool
{
    $lead = crm_find_lead($id);

    if ($lead === null) {
        return false;
    }

    $status = trim((string) ($updates['status'] ?? 'novo'));

    if (!crm_kanban_status_exists($status)) {
        $status = 'novo';
    }

    $nextCpf = array_key_exists('cpf', $updates)
        ? crm_normalize_cpf((string) ($updates['cpf'] ?? ''))
        : crm_normalize_cpf((string) ($lead['cpf'] ?? ''));

    if ((string) ($lead['status'] ?? '') !== 'fechado' && $status === 'fechado' && strlen($nextCpf) !== 11) {
        return false;
    }

    $now = date('Y-m-d H:i:s');
    $fields = [
        'status = :status',
        'last_activity_at = :last_activity_at',
        'last_activity_type = :last_activity_type',
        'updated_at = :updated_at',
    ];
    $params = [
        'id' => $id,
        'status' => $status,
        'last_activity_at' => $now,
        'last_activity_type' => (string) ($lead['status'] ?? '') !== $status ? 'status_update' : 'lead_update',
        'updated_at' => $now,
    ];

    if (array_key_exists('notes', $updates)) {
        $fields[] = 'notes = :notes';
        $params['notes'] = trim((string) ($updates['notes'] ?? ''));
    }

    if (array_key_exists('commercial_notes', $updates)) {
        $fields[] = 'commercial_notes = :commercial_notes';
        $params['commercial_notes'] = trim((string) ($updates['commercial_notes'] ?? ''));
    }

    if (array_key_exists('name', $updates)) {
        $name = trim((string) ($updates['name'] ?? ''));

        if ($name !== '') {
            $fields[] = 'name = :name';
            $params['name'] = $name;
        }
    }

    if (array_key_exists('whatsapp', $updates)) {
        $fields[] = 'whatsapp = :whatsapp';
        $params['whatsapp'] = crm_normalize_lead_whatsapp((string) ($updates['whatsapp'] ?? ''));
    }

    if (array_key_exists('cpf', $updates)) {
        $fields[] = 'cpf = :cpf';
        $params['cpf'] = crm_normalize_cpf((string) ($updates['cpf'] ?? ''));
    }

    if (array_key_exists('tags', $updates)) {
        $fields[] = 'tags = :tags';
        $params['tags'] = crm_encode_tags((string) ($updates['tags'] ?? ''));
    }

    if (array_key_exists('estimated_value', $updates)) {
        $fields[] = 'estimated_value = :estimated_value';
        $params['estimated_value'] = crm_normalize_money($updates['estimated_value']);
    }

    if (array_key_exists('proposal_value', $updates)) {
        $fields[] = 'proposal_value = :proposal_value';
        $params['proposal_value'] = crm_normalize_money($updates['proposal_value']);
    }

    if (array_key_exists('expected_close_date', $updates)) {
        $fields[] = 'expected_close_date = :expected_close_date';
        $params['expected_close_date'] = crm_normalize_date($updates['expected_close_date']);
    }

    if (array_key_exists('lost_reason', $updates)) {
        $fields[] = 'lost_reason = :lost_reason';
        $params['lost_reason'] = trim((string) ($updates['lost_reason'] ?? '')) ?: null;
    }

    if ((string) ($lead['first_contact_at'] ?? '') === '' && ($status !== 'novo' || array_key_exists('notes', $updates))) {
        $fields[] = 'first_contact_at = :first_contact_at';
        $params['first_contact_at'] = $now;
    }

    if ($status === 'fechado' && (string) ($lead['closed_at'] ?? '') === '') {
        $fields[] = 'closed_at = :closed_at';
        $params['closed_at'] = $now;
    }

    if ($status === 'perdido' && (string) ($lead['lost_at'] ?? '') === '') {
        $fields[] = 'lost_at = :lost_at';
        $params['lost_at'] = $now;
    }

    $assignedUserChanged = false;
    $assignedUserId = null;

    if (array_key_exists('assigned_user_id', $updates) && function_exists('crm_current_user_can_manage_sales') && crm_current_user_can_manage_sales()) {
        $assignedUserId = crm_validate_assignable_user_id((int) ($updates['assigned_user_id'] ?? 0));
        $currentAssignedUserId = isset($lead['assigned_user_id']) && $lead['assigned_user_id'] !== null ? (int) $lead['assigned_user_id'] : null;
        $assignedUserChanged = $currentAssignedUserId !== $assignedUserId;
        $fields[] = 'assigned_user_id = :assigned_user_id';
        $fields[] = 'assigned_at = :assigned_at';
        $params['assigned_user_id'] = $assignedUserId;
        $params['assigned_at'] = $assignedUserId !== null ? $now : null;
        $params['last_activity_type'] = 'manual_assignment';
    }

    [$accessSql, $accessParams] = crm_lead_access_sql('leads');
    $stmt = crm_db()->prepare('UPDATE leads SET ' . implode(', ', $fields) . ' WHERE id = :id' . $accessSql);
    $stmt->execute($params + $accessParams);
    $changed = $stmt->rowCount() > 0;

    if ($assignedUserChanged && $changed) {
        $currentUser = crm_current_storage_user();
        crm_record_lead_assignment(
            $id,
            isset($lead['assigned_user_id']) && $lead['assigned_user_id'] !== null ? (int) $lead['assigned_user_id'] : null,
            $assignedUserId,
            'manual_assignment',
            'Atribuição alterada manualmente no CRM.',
            is_array($currentUser) ? (int) ($currentUser['id'] ?? 0) ?: null : null
        );
        crm_touch_user_assigned_at($assignedUserId);
    }

    return $changed;
}

function crm_move_lead(string $id, string $status, array $orders): bool
{
    if (!crm_kanban_status_exists($status)) {
        return false;
    }

    [$accessSql, $accessParams] = crm_lead_access_sql('leads');
    $pdo = crm_db();
    $pdo->beginTransaction();

    try {
        $find = $pdo->prepare('SELECT id, status, cpf, first_contact_at, closed_at, lost_at FROM leads WHERE id = :id' . $accessSql . ' FOR UPDATE');
        $find->execute(['id' => $id] + $accessParams);
        $lead = $find->fetch();

        if (!is_array($lead)) {
            $pdo->rollBack();
            return false;
        }

        if ((string) ($lead['status'] ?? '') !== 'fechado' && $status === 'fechado' && !crm_lead_has_cpf($lead)) {
            $pdo->rollBack();
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $dateFields = '';
        $dateParams = [];

        if ((string) ($lead['first_contact_at'] ?? '') === '' && $status !== 'novo') {
            $dateFields .= ', first_contact_at = :first_contact_at';
            $dateParams['first_contact_at'] = $now;
        }

        if ($status === 'fechado' && (string) ($lead['closed_at'] ?? '') === '') {
            $dateFields .= ', closed_at = :closed_at';
            $dateParams['closed_at'] = $now;
        }

        if ($status === 'perdido' && (string) ($lead['lost_at'] ?? '') === '') {
            $dateFields .= ', lost_at = :lost_at';
            $dateParams['lost_at'] = $now;
        }

        $updateLead = $pdo->prepare(
            'UPDATE leads
            SET status = :status,
                last_activity_at = :last_activity_at,
                last_activity_type = :last_activity_type,
                updated_at = :updated_at' . $dateFields . '
            WHERE id = :id' . $accessSql
        );
        $updateLead->execute([
            'id' => $id,
            'status' => $status,
            'last_activity_at' => $now,
            'last_activity_type' => 'kanban_move',
            'updated_at' => $now,
        ] + $dateParams + $accessParams);

        $updatePosition = $pdo->prepare(
            'UPDATE leads
            SET kanban_position = :position
            WHERE id = :id AND status = :status' . $accessSql
        );

        foreach ($orders as $orderStatus => $leadIds) {
            $orderStatus = trim((string) $orderStatus);

            if (!is_array($leadIds) || !crm_kanban_status_exists($orderStatus)) {
                continue;
            }

            $position = 10;

            foreach (array_unique(array_map('strval', $leadIds)) as $orderedLeadId) {
                $updatePosition->execute([
                    'id' => trim($orderedLeadId),
                    'status' => $orderStatus,
                    'position' => $position,
                ] + $accessParams);
                $position += 10;
            }
        }

        $pdo->commit();
        return true;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }
}

function crm_read_followup_flows(bool $onlyActive = false): array
{
    $sql = 'SELECT * FROM followup_flows';

    if ($onlyActive) {
        $sql .= ' WHERE active = 1';
    }

    $sql .= ' ORDER BY created_at DESC';

    return crm_db()->query($sql)->fetchAll();
}

function crm_find_followup_flow(int $id): ?array
{
    $stmt = crm_db()->prepare('SELECT * FROM followup_flows WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $flow = $stmt->fetch();

    return is_array($flow) ? $flow : null;
}

function crm_read_followup_steps(int $flowId): array
{
    $stmt = crm_db()->prepare(
        'SELECT s.*, t.name AS template_name, t.body_text AS template_body,
                t.status AS template_status, t.meta_status AS template_meta_status,
                t.active AS template_active
         FROM followup_steps s
         LEFT JOIN whatsapp_templates t ON t.id = s.template_id
         WHERE s.flow_id = :flow_id
         ORDER BY s.step_order ASC'
    );
    $stmt->execute(['flow_id' => $flowId]);

    return $stmt->fetchAll();
}

function crm_create_followup_flow(string $name, string $description, array $steps): int
{
    $db = crm_db();
    $db->beginTransaction();

    try {
        $stmt = $db->prepare(
            'INSERT INTO followup_flows (name, description, active, created_at, updated_at)
            VALUES (:name, :description, 1, :created_at, :updated_at)'
        );
        $stmt->execute([
            'name' => $name,
            'description' => $description,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $flowId = (int) $db->lastInsertId();
        crm_replace_followup_steps($flowId, $steps);
        $db->commit();

        return $flowId;
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

function crm_update_followup_flow(int $id, string $name, string $description, array $steps): bool
{
    $db = crm_db();
    $db->beginTransaction();

    try {
        $stmt = $db->prepare(
            'UPDATE followup_flows
            SET name = :name, description = :description, updated_at = :updated_at
            WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        crm_replace_followup_steps($id, $steps);
        crm_reschedule_followup_flow($id);
        $db->commit();

        return true;
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

function crm_replace_followup_steps(int $flowId, array $steps): void
{
    $db = crm_db();
    $delete = $db->prepare('DELETE FROM followup_steps WHERE flow_id = :flow_id');
    $delete->execute(['flow_id' => $flowId]);

    $insert = $db->prepare(
        'INSERT INTO followup_steps
            (flow_id, step_order, delay_minutes, message, message_type, template_id, variable_mapping, created_at)
        VALUES
            (:flow_id, :step_order, :delay_minutes, :message, :message_type, :template_id, :variable_mapping, :created_at)'
    );

    $order = 1;

    foreach ($steps as $step) {
        $message = trim((string) ($step['message'] ?? ''));
        $messageType = trim((string) ($step['message_type'] ?? 'text')) === 'template' ? 'template' : 'text';
        $templateId = max(0, (int) ($step['template_id'] ?? 0));
        $variableMapping = is_array($step['variable_mapping'] ?? null)
            ? json_encode($step['variable_mapping'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        if ($messageType === 'template' && $templateId > 0) {
            $template = crm_find_whatsapp_template($templateId);

            if ($template !== null) {
                $message = trim((string) ($template['body_text'] ?? ''));
            }
        }

        if ($message === '' && $messageType !== 'template') {
            continue;
        }

        $insert->execute([
            'flow_id' => $flowId,
            'step_order' => $order,
            'delay_minutes' => max(0, (int) ($step['delay_minutes'] ?? 0)),
            'message' => $message,
            'message_type' => $messageType,
            'template_id' => $templateId > 0 ? $templateId : null,
            'variable_mapping' => $variableMapping,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $order++;
    }
}

function crm_reschedule_followup_flow(int $flowId): void
{
    $db = crm_db();
    $leads = $db->prepare(
        'SELECT id FROM leads WHERE status = "followup" AND followup_flow_id = :flow_id'
    );
    $leads->execute(['flow_id' => $flowId]);
    $activeLeads = $leads->fetchAll();

    if (count($activeLeads) === 0) {
        return;
    }

    $steps = crm_read_followup_steps($flowId);
    $insert = $db->prepare(
        'INSERT INTO followup_queue (lead_id, flow_id, step_id, step_order, scheduled_at, status, created_at)
        VALUES (:lead_id, :flow_id, :step_id, :step_order, :scheduled_at, "pendente", :created_at)'
    );
    $alreadyHandled = $db->prepare(
        'SELECT COUNT(*) FROM followup_queue
        WHERE lead_id = :lead_id
          AND flow_id = :flow_id
          AND step_order = :step_order
          AND status IN ("pendente", "enviado")'
    );
    $alreadySent = $db->prepare(
        'SELECT COUNT(*) FROM followup_step_history
        WHERE lead_id = :lead_id
          AND flow_id = :flow_id
          AND step_order = :step_order
          AND status = "enviado"'
    );

    foreach ($activeLeads as $lead) {
        $elapsedMinutes = 0;

        foreach ($steps as $step) {
            $elapsedMinutes += max(0, (int) $step['delay_minutes']);

            $alreadySent->execute([
                'lead_id' => $lead['id'],
                'flow_id' => $flowId,
                'step_order' => $step['step_order'],
            ]);

            if ((int) $alreadySent->fetchColumn() > 0) {
                continue;
            }

            $alreadyHandled->execute([
                'lead_id' => $lead['id'],
                'flow_id' => $flowId,
                'step_order' => $step['step_order'],
            ]);

            if ((int) $alreadyHandled->fetchColumn() > 0) {
                continue;
            }

            $insert->execute([
                'lead_id' => $lead['id'],
                'flow_id' => $flowId,
                'step_id' => $step['id'],
                'step_order' => $step['step_order'],
                'scheduled_at' => date('Y-m-d H:i:s', time() + ($elapsedMinutes * 60)),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
}

function crm_delete_followup_flow(int $id): bool
{
    $stmt = crm_db()->prepare('DELETE FROM followup_flows WHERE id = :id');
    $stmt->execute(['id' => $id]);

    return $stmt->rowCount() > 0;
}

function crm_assign_followup_flow(string $leadId, int $flowId): bool
{
    $flow = crm_find_followup_flow($flowId);

    if ($flow === null) {
        return false;
    }

    $lead = crm_find_lead($leadId);

    if ($lead === null) {
        return false;
    }

    $db = crm_db();
    $db->beginTransaction();

    try {
        [$accessSql, $accessParams] = crm_lead_access_sql('leads');
        $firstContactSql = (string) ($lead['first_contact_at'] ?? '') === '' ? ', first_contact_at = :first_contact_at' : '';
        $update = $db->prepare(
            'UPDATE leads
            SET status = "followup",
                followup_flow_id = :flow_id,
                followup_started_at = :started_at,
                last_activity_at = :last_activity_at,
                last_activity_type = :last_activity_type,
                updated_at = :updated_at' . $firstContactSql . '
            WHERE id = :id' . $accessSql
        );
        $now = date('Y-m-d H:i:s');
        $updateParams = [
            'id' => $leadId,
            'flow_id' => $flowId,
            'started_at' => $now,
            'last_activity_at' => $now,
            'last_activity_type' => 'followup_assignment',
            'updated_at' => $now,
        ];

        if ($firstContactSql !== '') {
            $updateParams['first_contact_at'] = $now;
        }

        $update->execute($updateParams + $accessParams);

        $clear = $db->prepare('DELETE FROM followup_queue WHERE lead_id = :lead_id AND status = "pendente"');
        $clear->execute(['lead_id' => $leadId]);

        $steps = crm_read_followup_steps($flowId);
        $insert = $db->prepare(
            'INSERT INTO followup_queue (lead_id, flow_id, step_id, step_order, scheduled_at, status, created_at)
            VALUES (:lead_id, :flow_id, :step_id, :step_order, :scheduled_at, "pendente", :created_at)'
        );

        $elapsedMinutes = 0;

        foreach ($steps as $step) {
            $elapsedMinutes += max(0, (int) $step['delay_minutes']);
            $scheduledAt = date('Y-m-d H:i:s', time() + ($elapsedMinutes * 60));

            $insert->execute([
                'lead_id' => $leadId,
                'flow_id' => $flowId,
                'step_id' => $step['id'],
                'step_order' => $step['step_order'],
                'scheduled_at' => $scheduledAt,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $db->commit();
        return $update->rowCount() > 0;
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

function crm_stop_followup_after_incoming_reply(string $leadId): array
{
    $leadId = trim($leadId);

    if ($leadId === '') {
        return [
            'stopped' => false,
            'cancelled' => 0,
        ];
    }

    $db = crm_db();
    $db->beginTransaction();

    try {
        $leadStmt = $db->prepare(
            'SELECT id, status
             FROM leads
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );
        $leadStmt->execute(['id' => $leadId]);
        $lead = $leadStmt->fetch();

        if (!is_array($lead) || (string) ($lead['status'] ?? '') !== 'followup') {
            $db->commit();

            return [
                'stopped' => false,
                'cancelled' => 0,
            ];
        }

        $now = date('Y-m-d H:i:s');
        $updateLead = $db->prepare(
            'UPDATE leads
             SET status = "contatado",
                 followup_flow_id = NULL,
                 followup_started_at = NULL,
                 last_activity_at = :last_activity_at,
                 last_activity_type = "incoming_reply",
                 updated_at = :updated_at
             WHERE id = :id
               AND status = "followup"'
        );
        $updateLead->execute([
            'id' => $leadId,
            'last_activity_at' => $now,
            'updated_at' => $now,
        ]);

        $cancelQueue = $db->prepare(
            'UPDATE followup_queue
             SET status = "cancelado",
                 sent_at = NULL,
                 error = :error
             WHERE lead_id = :lead_id
               AND status = "pendente"'
        );
        $cancelQueue->execute([
            'lead_id' => $leadId,
            'error' => 'Cancelado automaticamente após resposta do lead.',
        ]);

        $db->commit();

        return [
            'stopped' => $updateLead->rowCount() > 0,
            'cancelled' => $cancelQueue->rowCount(),
        ];
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

function crm_read_due_followups(int $limit = 20): array
{
    $stmt = crm_db()->prepare(
        'SELECT q.*, s.message, s.message_type, s.template_id, s.variable_mapping,
                l.name, l.whatsapp, l.company, l.segment,
                l.created_at AS lead_created_at, l.message AS lead_message, l.notes AS lead_notes,
                t.name AS template_name, t.body_text AS template_body,
                t.language AS template_language, t.status AS template_status,
                t.meta_status AS template_meta_status, t.meta_template_id,
                crm_users.name AS assigned_user_name, crm_users.username AS assigned_username
        FROM followup_queue q
        JOIN followup_steps s ON s.id = q.step_id
        JOIN leads l ON l.id = q.lead_id
        LEFT JOIN whatsapp_templates t ON t.id = s.template_id
        LEFT JOIN crm_users ON crm_users.id = l.assigned_user_id
        WHERE q.status = "pendente"
          AND l.status = "followup"
          AND q.scheduled_at <= :now_due
          AND NOT EXISTS (
            SELECT 1
            FROM followup_queue q2
            WHERE q2.lead_id = q.lead_id
              AND q2.flow_id = q.flow_id
              AND q2.status = "pendente"
              AND q2.scheduled_at <= :now_previous
              AND q2.step_order < q.step_order
          )
          AND NOT EXISTS (
            SELECT 1
            FROM followup_step_history h
            WHERE h.lead_id = q.lead_id
              AND h.flow_id = q.flow_id
              AND h.status = "enviado"
              AND h.sent_at >= :recent_sent_at
          )
        ORDER BY q.scheduled_at ASC
        LIMIT ' . max(1, $limit)
    );
    $now = time();
    $stmt->execute([
        'now_due' => date('Y-m-d H:i:s', $now),
        'now_previous' => date('Y-m-d H:i:s', $now),
        'recent_sent_at' => date('Y-m-d H:i:s', $now - 55),
    ]);

    return $stmt->fetchAll();
}

function crm_update_followup_queue_item(int $id, string $status, ?string $error = null): bool
{
    $itemStmt = crm_db()->prepare('SELECT * FROM followup_queue WHERE id = :id LIMIT 1');
    $itemStmt->execute(['id' => $id]);
    $item = $itemStmt->fetch();

    $stmt = crm_db()->prepare(
        'UPDATE followup_queue
        SET status = :status,
            sent_at = :sent_at,
            error = :error
        WHERE id = :id'
    );
    $stmt->execute([
        'id' => $id,
        'status' => $status,
        'sent_at' => $status === 'enviado' ? date('Y-m-d H:i:s') : null,
        'error' => $error,
    ]);

    if (is_array($item) && in_array($status, ['enviado', 'falhou'], true)) {
        crm_record_followup_step_history($item, $status, $error);
    }

    return $stmt->rowCount() > 0;
}

function crm_record_followup_step_history(array $queueItem, string $status, ?string $error = null): void
{
    $stmt = crm_db()->prepare(
        'INSERT INTO followup_step_history
        (lead_id, flow_id, step_order, status, sent_at, error, created_at, updated_at)
        VALUES
        (:lead_id, :flow_id, :step_order, :status, :sent_at, :error, :created_at, :updated_at)
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            sent_at = VALUES(sent_at),
            error = VALUES(error),
            updated_at = VALUES(updated_at)'
    );
    $stmt->execute([
        'lead_id' => $queueItem['lead_id'],
        'flow_id' => $queueItem['flow_id'],
        'step_order' => $queueItem['step_order'],
        'status' => $status,
        'sent_at' => $status === 'enviado' ? date('Y-m-d H:i:s') : null,
        'error' => $error,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}

function crm_delete_lead(string $id): bool
{
    $lead = crm_find_lead($id);
    [$accessSql, $accessParams] = crm_lead_access_sql('leads');
    $stmt = crm_db()->prepare('DELETE FROM leads WHERE id = :id' . $accessSql);
    $stmt->execute(['id' => $id] + $accessParams);

    if ($stmt->rowCount() > 0 && is_array($lead)) {
        $whatsapp = crm_normalize_lead_whatsapp((string) ($lead['whatsapp'] ?? ''));

        if ($whatsapp !== '' && crm_find_lead_by_whatsapp($whatsapp) === null) {
            $conversationKey = crm_whatsapp_number_variants($whatsapp)[0] ?? $whatsapp;
            $deleteReads = crm_db()->prepare(
                'DELETE FROM whatsapp_conversation_reads
                 WHERE conversation_key = :conversation_key'
            );
            $deleteReads->execute(['conversation_key' => $conversationKey]);
        }
    }

    return $stmt->rowCount() > 0;
}

function crm_find_lead(string $id, bool $applyAccess = true): ?array
{
    [$accessSql, $accessParams] = crm_lead_access_sql('leads', $applyAccess);
    $stmt = crm_db()->prepare(
        'SELECT leads.*, crm_users.name AS assigned_user_name, crm_users.username AS assigned_username
        FROM leads
        LEFT JOIN crm_users ON crm_users.id = leads.assigned_user_id
        WHERE leads.id = :id' . $accessSql . '
        LIMIT 1'
    );
    $stmt->execute(['id' => $id] + $accessParams);
    $lead = $stmt->fetch();

    return is_array($lead) ? $lead : null;
}

function crm_update_whatsapp_status(string $id, string $status, ?string $error = null): bool
{
    $stmt = crm_db()->prepare(
        'UPDATE leads
        SET whatsapp_status = :status,
            whatsapp_sent_at = :sent_at,
            whatsapp_error = :error,
            updated_at = :updated_at
        WHERE id = :id'
    );
    $stmt->execute([
        'id' => $id,
        'status' => $status,
        'sent_at' => in_array($status, ['aguardando', 'enviado', 'entregue', 'lido', 'notifica_enviada'], true) ? date('Y-m-d H:i:s') : null,
        'error' => $error,
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    return $stmt->rowCount() > 0;
}

function crm_sla_timezone(): DateTimeZone
{
    // crm_config() applies the CRM timezone before the SLA reads any clock.
    if (function_exists('crm_config')) {
        crm_config();
    }

    return new DateTimeZone(date_default_timezone_get());
}

function crm_sla_access_user_from_lead(array $lead): array
{
    return [
        'role' => (string) ($lead['assigned_user_role'] ?? ''),
        'access_schedule_enabled' => (int) ($lead['assigned_access_schedule_enabled'] ?? 1),
        'access_start_time' => (string) ($lead['assigned_access_start_time'] ?? '09:00:00'),
        'access_end_time' => (string) ($lead['assigned_access_end_time'] ?? '18:00:00'),
        'access_saturday_enabled' => (int) ($lead['assigned_access_saturday_enabled'] ?? 1),
        'access_sunday_enabled' => (int) ($lead['assigned_access_sunday_enabled'] ?? 1),
    ];
}

function crm_sla_access_window_seconds(DateTimeImmutable $from, DateTimeImmutable $to, array $user): int
{
    if ($to <= $from) {
        return 0;
    }

    if ((string) ($user['role'] ?? '') !== 'vendedor' || !crm_user_access_schedule_enabled($user)) {
        return $to->getTimestamp() - $from->getTimestamp();
    }

    $start = crm_normalize_user_access_time((string) ($user['access_start_time'] ?? ''), '09:00:00');
    $end = crm_normalize_user_access_time((string) ($user['access_end_time'] ?? ''), '18:00:00');

    // Match the login rule: an invalid/equal interval is treated as unrestricted
    // so a malformed user setting cannot freeze the SLA forever.
    if ($start >= $end) {
        return $to->getTimestamp() - $from->getTimestamp();
    }

    [$startHour, $startMinute, $startSecond] = array_map('intval', explode(':', $start));
    [$endHour, $endMinute, $endSecond] = array_map('intval', explode(':', $end));
    $total = 0;
    $day = $from->setTime(0, 0, 0);
    $lastDay = $to->setTime(0, 0, 0);

    while ($day <= $lastDay) {
        $weekday = (int) $day->format('N');

        if (crm_user_access_day_enabled($user, $weekday)) {
            $windowStart = $day->setTime($startHour, $startMinute, $startSecond);
            $windowEnd = $day->setTime($endHour, $endMinute, $endSecond);
            $intersectionStart = $from > $windowStart ? $from : $windowStart;
            $intersectionEnd = $to < $windowEnd ? $to : $windowEnd;

            if ($intersectionEnd > $intersectionStart) {
                $total += $intersectionEnd->getTimestamp() - $intersectionStart->getTimestamp();
            }
        }

        $day = $day->modify('+1 day');
    }

    return $total;
}

function crm_sla_lead_activity_datetime(array $lead, DateTimeZone $timezone): ?DateTimeImmutable
{
    $value = trim((string) (($lead['last_activity_at'] ?? '') ?: ($lead['updated_at'] ?? '') ?: ($lead['created_at'] ?? '')));

    if ($value === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($value, $timezone);
    } catch (Throwable $error) {
        return null;
    }
}

function crm_sla_lead_is_overdue(array $lead, int $inactivityMinutes, DateTimeImmutable $now): bool
{
    $activityAt = crm_sla_lead_activity_datetime($lead, $now->getTimezone());

    if (!$activityAt) {
        return false;
    }

    $elapsedSeconds = crm_sla_access_window_seconds(
        $activityAt,
        $now,
        crm_sla_access_user_from_lead($lead)
    );

    return $elapsedSeconds >= ($inactivityMinutes * 60);
}

function crm_read_sla_overdue_leads(int $limit = 50): array
{
    $settings = crm_sales_distribution_settings();

    if (!$settings['sla_enabled']) {
        return [];
    }

    $statuses = array_values(array_filter($settings['sla_statuses'], static fn(string $status): bool => crm_kanban_status_exists($status)));

    if (count($statuses) === 0) {
        return [];
    }

    $placeholders = [];
    $now = new DateTimeImmutable('now', crm_sla_timezone());
    $params = [
        // Wall-clock cutoff is only a candidate filter. The final decision
        // below uses the seller's available access windows when enabled.
        'cutoff' => $now->modify('-' . (int) $settings['sla_inactivity_minutes'] . ' minutes')->format('Y-m-d H:i:s'),
    ];

    foreach ($statuses as $index => $status) {
        $key = 'status_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $status;
    }

    $sql =
        'SELECT leads.*, crm_users.name AS assigned_user_name, crm_users.username AS assigned_username,
                crm_users.role AS assigned_user_role,
                crm_users.access_schedule_enabled AS assigned_access_schedule_enabled,
                crm_users.access_start_time AS assigned_access_start_time,
                crm_users.access_end_time AS assigned_access_end_time,
                crm_users.access_saturday_enabled AS assigned_access_saturday_enabled,
                crm_users.access_sunday_enabled AS assigned_access_sunday_enabled
        FROM leads
        LEFT JOIN crm_users ON crm_users.id = leads.assigned_user_id
        WHERE leads.assigned_user_id IS NOT NULL
          AND COALESCE(leads.last_activity_at, leads.updated_at, leads.created_at) <= :cutoff
          AND leads.status IN (' . implode(', ', $placeholders) . ')
        ORDER BY COALESCE(leads.last_activity_at, leads.updated_at, leads.created_at) ASC';

    if (!$settings['sla_respect_access_schedule']) {
        $sql .= ' LIMIT ' . max(1, $limit);
    }

    $stmt = crm_db()->prepare($sql);
    $stmt->execute($params);

    $candidates = $stmt->fetchAll();

    if (!$settings['sla_respect_access_schedule']) {
        return array_slice($candidates, 0, max(1, $limit));
    }

    $overdue = [];

    foreach ($candidates as $lead) {
        if (crm_sla_lead_is_overdue($lead, (int) $settings['sla_inactivity_minutes'], $now)) {
            $overdue[] = $lead;

            if (count($overdue) >= max(1, $limit)) {
                break;
            }
        }
    }

    return $overdue;
}

function crm_process_sla_reassignments(int $limit = 50): array
{
    $settings = crm_sales_distribution_settings();
    $processed = 0;
    $reassigned = 0;
    $skipped = 0;
    $details = [];

    if (!$settings['sla_enabled']) {
        return [
            'processed' => 0,
            'reassigned' => 0,
            'skipped' => 0,
            'message' => 'SLA de inatividade está desativado.',
            'details' => [],
        ];
    }

    foreach (crm_read_sla_overdue_leads($limit) as $lead) {
        $processed++;
        $leadId = (string) ($lead['id'] ?? '');
        $currentUserId = isset($lead['assigned_user_id']) ? (int) $lead['assigned_user_id'] : null;

        $nextUser = $settings['sla_action'] === 'manager_review'
            ? crm_pick_manager_user($currentUserId)
            : crm_pick_rotation_user($currentUserId);

        if (!is_array($nextUser) || (int) ($nextUser['id'] ?? 0) <= 0 || (int) $nextUser['id'] === (int) $currentUserId) {
            $skipped++;
            $details[] = [
                'lead_id' => $leadId,
                'lead' => (string) ($lead['name'] ?? ''),
                'status' => 'sem_vendedor',
                'reason' => $settings['sla_action'] === 'manager_review'
                    ? 'Nenhum gestor ativo disponível.'
                    : 'Nenhum outro vendedor ativo na roleta.',
            ];
            continue;
        }

        $previousUserName = trim((string) ($lead['assigned_user_name'] ?? ''));
        $previousUserUsername = trim((string) ($lead['assigned_username'] ?? ''));
        $previousUserLabel = $previousUserName !== ''
            ? $previousUserName
            : ($previousUserUsername !== '' ? $previousUserUsername : 'Usuário anterior não encontrado');
        $reason = 'Redistribuição automática por inatividade acima de ' . (int) $settings['sla_inactivity_minutes'] . ' minutos.';
        $action = $settings['sla_action'] === 'manager_review' ? 'sla_manager_review' : 'sla_reassignment';

        if (crm_assign_lead_to_user($leadId, (int) $nextUser['id'], $action, $reason, null, false)) {
            crm_append_lead_note(
                $leadId,
                'Lead redistribuído automaticamente em ' . date('d/m/Y H:i') . ".\nMotivo: " . $reason . "\nVendedor anterior: " . $previousUserLabel . "\nNovo vendedor: " . crm_user_label($nextUser)
            );
            $reassigned++;
            $details[] = [
                'lead_id' => $leadId,
                'lead' => (string) ($lead['name'] ?? ''),
                'status' => 'redistribuido',
                'to_user' => crm_user_label($nextUser),
            ];
            continue;
        }

        $skipped++;
        $details[] = [
            'lead_id' => $leadId,
            'lead' => (string) ($lead['name'] ?? ''),
            'status' => 'falhou',
            'reason' => 'Não foi possível alterar o responsável.',
        ];
    }

    return [
        'processed' => $processed,
        'reassigned' => $reassigned,
        'skipped' => $skipped,
        'details' => $details,
    ];
}
