CREATE DATABASE IF NOT EXISTS publi_ai_crm
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publi_ai_crm.leads (
  id VARCHAR(32) PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  whatsapp VARCHAR(40) NOT NULL,
  profile_picture_url TEXT NULL,
  cpf VARCHAR(14) NULL,
  company VARCHAR(180) NOT NULL,
  segment VARCHAR(180) NULL,
  advertises VARCHAR(180) NULL,
  message TEXT NULL,
  page TEXT NULL,
  utm_source VARCHAR(120) NULL,
  utm_medium VARCHAR(120) NULL,
  utm_campaign VARCHAR(180) NULL,
  utm_content VARCHAR(180) NULL,
  utm_term VARCHAR(180) NULL,
  referrer TEXT NULL,
  landing_path TEXT NULL,
  form_id VARCHAR(32) NULL,
  form_answers LONGTEXT NULL,
  lead_score INT NULL,
  lead_temperature VARCHAR(20) NULL,
  score_reasons TEXT NULL,
  status VARCHAR(80) NOT NULL DEFAULT 'novo',
  kanban_position INT NOT NULL DEFAULT 0,
  notes TEXT NULL,
  commercial_notes TEXT NULL,
  tags TEXT NULL,
  assigned_user_id INT NULL,
  assigned_at DATETIME NULL,
  last_activity_at DATETIME NULL,
  last_activity_type VARCHAR(60) NULL,
  sla_last_checked_at DATETIME NULL,
  estimated_value DECIMAL(12,2) NULL,
  proposal_value DECIMAL(12,2) NULL,
  expected_close_date DATE NULL,
  lost_reason VARCHAR(180) NULL,
  first_contact_at DATETIME NULL,
  closed_at DATETIME NULL,
  lost_at DATETIME NULL,
  whatsapp_status VARCHAR(30) NOT NULL DEFAULT 'pendente',
  whatsapp_sent_at DATETIME NULL,
  whatsapp_error TEXT NULL,
  followup_flow_id INT NULL,
  followup_started_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_leads_assigned_user (assigned_user_id, status, updated_at),
  INDEX idx_leads_activity (last_activity_at, status)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publi_ai_crm.crm_settings (
  setting_key VARCHAR(120) PRIMARY KEY,
  setting_value LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publi_ai_crm.whatsapp_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(512) NOT NULL UNIQUE,
  language VARCHAR(20) NOT NULL DEFAULT 'pt_BR',
  category VARCHAR(30) NOT NULL DEFAULT 'UTILITY',
  header_text TEXT NULL,
  body_text TEXT NOT NULL,
  footer_text TEXT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'draft',
  meta_template_id VARCHAR(100) NULL,
  meta_status VARCHAR(40) NULL,
  meta_rejection_reason TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_whatsapp_templates_active (active, status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publi_ai_crm.crm_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  username VARCHAR(120) NOT NULL UNIQUE,
  email VARCHAR(180) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(30) NOT NULL DEFAULT 'vendedor',
  active TINYINT(1) NOT NULL DEFAULT 1,
  participates_in_rotation TINYINT(1) NOT NULL DEFAULT 0,
  rotation_weight INT NOT NULL DEFAULT 1,
  last_assigned_at DATETIME NULL,
  access_schedule_enabled TINYINT(1) NOT NULL DEFAULT 1,
  access_start_time TIME NOT NULL DEFAULT '09:00:00',
  access_end_time TIME NOT NULL DEFAULT '18:00:00',
  access_saturday_enabled TINYINT(1) NOT NULL DEFAULT 1,
  access_sunday_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_crm_users_role_active (role, active),
  INDEX idx_crm_users_email (email),
  INDEX idx_crm_users_rotation (participates_in_rotation, active, last_assigned_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publi_ai_crm.crm_password_reset_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  request_ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_password_reset_user (user_id, used_at, expires_at),
  INDEX idx_password_reset_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publi_ai_crm.lead_assignment_logs (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publi_ai_crm.crm_push_subscriptions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publi_ai_crm.crm_push_notification_events (
  event_hash CHAR(64) PRIMARY KEY,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  INDEX idx_crm_push_notification_events_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publi_ai_crm.whatsapp_conversation_reads (
  user_id INT NOT NULL,
  conversation_key VARCHAR(80) NOT NULL,
  read_incoming_count INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, conversation_key),
  INDEX idx_whatsapp_conversation_reads_user (user_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publi_ai_crm.lead_forms (
  id VARCHAR(32) PRIMARY KEY,
  slug VARCHAR(100) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  draft_config LONGTEXT NOT NULL,
  published_config LONGTEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publi_ai_crm.kanban_columns (
  status VARCHAR(80) PRIMARY KEY,
  label VARCHAR(120) NOT NULL,
  position INT NOT NULL DEFAULT 0,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO publi_ai_crm.kanban_columns (status, label, position, is_system, active, created_at, updated_at)
VALUES
  ('novo', 'Novo', 10, 1, 1, NOW(), NOW()),
  ('contatado', 'Contatado', 20, 1, 1, NOW(), NOW()),
  ('followup', 'Follow-up', 30, 1, 1, NOW(), NOW()),
  ('proposta', 'Proposta enviada', 40, 1, 1, NOW(), NOW()),
  ('fechado', 'Fechado', 50, 1, 1, NOW(), NOW()),
  ('perdido', 'Perdido', 60, 1, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  position = VALUES(position),
  active = 1,
  updated_at = VALUES(updated_at);

CREATE TABLE IF NOT EXISTS publi_ai_crm.followup_flows (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  description TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publi_ai_crm.followup_steps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  flow_id INT NOT NULL,
  step_order INT NOT NULL,
  delay_minutes INT NOT NULL DEFAULT 0,
  message TEXT NOT NULL,
  message_type VARCHAR(20) NOT NULL DEFAULT 'text',
  template_id INT NULL,
  variable_mapping LONGTEXT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_followup_steps_template (template_id),
  FOREIGN KEY (flow_id) REFERENCES publi_ai_crm.followup_flows(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publi_ai_crm.followup_queue (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lead_id VARCHAR(32) NOT NULL,
  flow_id INT NOT NULL,
  step_id INT NOT NULL,
  step_order INT NOT NULL,
  scheduled_at DATETIME NOT NULL,
  sent_at DATETIME NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'pendente',
  error TEXT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_due (status, scheduled_at),
  FOREIGN KEY (flow_id) REFERENCES publi_ai_crm.followup_flows(id) ON DELETE CASCADE,
  FOREIGN KEY (step_id) REFERENCES publi_ai_crm.followup_steps(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publi_ai_crm.followup_step_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lead_id VARCHAR(32) NOT NULL,
  flow_id INT NOT NULL,
  step_order INT NOT NULL,
  status VARCHAR(30) NOT NULL,
  sent_at DATETIME NULL,
  error TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uniq_lead_flow_order (lead_id, flow_id, step_order),
  INDEX idx_history_lookup (lead_id, flow_id, step_order, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
