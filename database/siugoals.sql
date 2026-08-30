-- SIUGOALS COMPLETE DATABASE
-- Full clean schema for a NEW EMPTY MySQL/MariaDB database.
-- Do NOT import this file over an existing SIUGOALS database.
-- Hostinger: create a new empty database, select it in phpMyAdmin, then Import this file.
-- This schema contains the complete Stage 1-5 structure plus the 5.5 Builder Workflow, Fix Center and Runtime indexes.
--
-- This file creates schema and reference data only (plans, quick questions,
-- migration ledger). It intentionally does NOT create any user account. See
-- DEPLOYMENT.md "Make yourself a platform administrator" for how to grant
-- your own signed-up account admin access after installing.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 email VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 name VARCHAR(190) NOT NULL,
 token_balance INT NOT NULL DEFAULT 1000,
 bonus_tokens INT NOT NULL DEFAULT 0,
 token_cycle_allocation INT NOT NULL DEFAULT 1000,
 token_reset_at DATETIME NULL,
 referral_code VARCHAR(32) NOT NULL UNIQUE,
 referred_by BIGINT UNSIGNED NULL,
 referral_rewarded_at DATETIME NULL,
 last_login_at DATETIME NULL,
 auth_provider VARCHAR(30) NULL,
 provider_id VARCHAR(190) NULL,
 avatar_url VARCHAR(500) NULL,
 email_verified_at DATETIME NULL,
 locale ENUM('en','ar') NOT NULL DEFAULT 'en',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_users_referred_by FOREIGN KEY (referred_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE projects (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 owner_user_id BIGINT UNSIGNED NOT NULL,
 slug VARCHAR(190) NOT NULL UNIQUE,
 name VARCHAR(190) NOT NULL,
 url VARCHAR(500) NOT NULL,
 tagline VARCHAR(200) NULL,
 toolchain VARCHAR(100) NULL,
 ai_provider VARCHAR(100) NULL,
 hosting_platform VARCHAR(100) NULL,
 database_platform VARCHAR(100) NULL,
 github_repo VARCHAR(255) NULL,
 monthly_token_cap INT NULL,
 readiness_score INT NOT NULL DEFAULT 0,
 public_score INT NULL,
 runtime_status ENUM('disconnected','connected') NOT NULL DEFAULT 'disconnected',
 runtime_last_seen_at DATETIME NULL,
 trust_badge_enabled TINYINT(1) NOT NULL DEFAULT 0,
 error_capture_enabled TINYINT(1) NOT NULL DEFAULT 1,
 session_replay_enabled TINYINT(1) NOT NULL DEFAULT 0,
 paid_access_enabled TINYINT(1) NOT NULL DEFAULT 0,
 api_secret_enc TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_projects_owner(owner_user_id),
 CONSTRAINT fk_projects_owner FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE project_members (
 project_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 role ENUM('viewer','developer','admin','owner') NOT NULL DEFAULT 'viewer',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(project_id,user_id),
 CONSTRAINT fk_pm_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
 CONSTRAINT fk_pm_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE platform_admins (
 user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 note VARCHAR(255) NULL,
 CONSTRAINT fk_platform_admin_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE scans (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id BIGINT UNSIGNED NOT NULL,
 type ENUM('public','deep','url','source','runtime') NOT NULL,
 status ENUM('queued','running','completed','failed') NOT NULL DEFAULT 'queued',
 score INT NULL,
 tokens_cost INT NOT NULL DEFAULT 0,
 started_at DATETIME NULL,
 completed_at DATETIME NULL,
 meta_json JSON NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_scans_project(project_id,created_at),
 CONSTRAINT fk_scans_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE checks (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id BIGINT UNSIGNED NOT NULL,
 check_key VARCHAR(120) NOT NULL,
 category ENUM('security','operations','code_quality','legal_compliance') NOT NULL,
 title VARCHAR(255) NOT NULL,
 description TEXT NULL,
 status ENUM('ready','need_attention','pending','question_required','not_applicable') NOT NULL DEFAULT 'pending',
 severity ENUM('critical','high','medium','low','info') NOT NULL DEFAULT 'medium',
 remediation TEXT NULL,
 verification_method TEXT NULL,
 first_detected_at DATETIME NULL,
 last_checked_at DATETIME NULL,
 resolved_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_check(project_id,check_key),
 INDEX idx_checks_project_status(project_id,status),
 CONSTRAINT fk_checks_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE evidence (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id BIGINT UNSIGNED NOT NULL,
 check_id BIGINT UNSIGNED NOT NULL,
 source ENUM('url','http','dns','ssl','user_answer','zip','github','runtime','historical','configuration','ai') NOT NULL,
 summary TEXT NOT NULL,
 confidence DECIMAL(5,2) NOT NULL DEFAULT 1.00,
 meta_json JSON NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_evidence_check(check_id),
 CONSTRAINT fk_evidence_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
 CONSTRAINT fk_evidence_check FOREIGN KEY(check_id) REFERENCES checks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE quick_questions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 check_key VARCHAR(120) NOT NULL,
 question TEXT NOT NULL,
 options_json JSON NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE question_answers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id BIGINT UNSIGNED NOT NULL,
 question_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 answer_key VARCHAR(120) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_answer(project_id,question_id),
 CONSTRAINT fk_qa_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
 CONSTRAINT fk_qa_question FOREIGN KEY(question_id) REFERENCES quick_questions(id) ON DELETE CASCADE,
 CONSTRAINT fk_qa_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE source_scans (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id BIGINT UNSIGNED NOT NULL,
 source_type ENUM('zip','github') NOT NULL,
 status ENUM('queued','running','completed','failed') NOT NULL DEFAULT 'queued',
 original_name VARCHAR(255) NULL,
 sha256 CHAR(64) NULL,
 total_files INT NOT NULL DEFAULT 0,
 total_bytes BIGINT NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 completed_at DATETIME NULL,
 CONSTRAINT fk_ss_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE source_findings (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 source_scan_id BIGINT UNSIGNED NOT NULL,
 severity ENUM('critical','high','medium','low','info') NOT NULL,
 category VARCHAR(100) NOT NULL,
 title VARCHAR(255) NOT NULL,
 description TEXT NOT NULL,
 evidence TEXT NULL,
 file_path VARCHAR(500) NULL,
 line_number INT NULL,
 fix_prompt MEDIUMTEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_sf_scan(source_scan_id),
 CONSTRAINT fk_sf_scan FOREIGN KEY(source_scan_id) REFERENCES source_scans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE github_connections (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id BIGINT UNSIGNED NOT NULL UNIQUE,
 github_user_id VARCHAR(100) NULL,
 installation_id BIGINT UNSIGNED NULL,
 maintenance_installation_id BIGINT UNSIGNED NULL,
 repo_full_name VARCHAR(255) NULL,
 access_token_enc TEXT NULL,
 refresh_token_enc TEXT NULL,
 token_expires_at DATETIME NULL,
 write_enabled TINYINT(1) NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_gc_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE runtime_sessions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id BIGINT UNSIGNED NOT NULL,
 session_key VARCHAR(80) NOT NULL,
 anonymous_id VARCHAR(80) NULL,
 identity_id VARCHAR(190) NULL,
 identity_email VARCHAR(190) NULL,
 identity_name VARCHAR(190) NULL,
 os VARCHAR(100) NULL,
 browser VARCHAR(100) NULL,
 device VARCHAR(100) NULL,
 country_code CHAR(2) NULL,
 country_name VARCHAR(100) NULL,
 ip_hash CHAR(64) NULL,
 user_agent VARCHAR(500) NULL,
 started_at DATETIME NOT NULL,
 last_seen_at DATETIME NOT NULL,
 ended_at DATETIME NULL,
 duration_seconds INT NOT NULL DEFAULT 0,
 error_count INT NOT NULL DEFAULT 0,
 signal_count INT NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_runtime_session(project_id,session_key),
 INDEX idx_runtime_sessions_project_started(project_id,started_at),
 INDEX idx_runtime_sessions_identity(project_id,identity_id),
 CONSTRAINT fk_rs_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE runtime_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id BIGINT UNSIGNED NOT NULL,
 runtime_session_id BIGINT UNSIGNED NULL,
 event_type ENUM('heartbeat','pageview','click','scroll','snapshot','mutation','error','network_error','signal','identify','session_end') NOT NULL,
 signal_type VARCHAR(80) NULL,
 page_url VARCHAR(1000) NULL,
 message TEXT NULL,
 stack_text MEDIUMTEXT NULL,
 meta_json JSON NULL,
 occurred_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_re_project_time(project_id,occurred_at),
 INDEX idx_re_session(runtime_session_id,occurred_at),
 CONSTRAINT fk_re_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
 CONSTRAINT fk_re_session FOREIGN KEY(runtime_session_id) REFERENCES runtime_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE error_issues (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id BIGINT UNSIGNED NOT NULL,
 fingerprint CHAR(64) NOT NULL,
 title VARCHAR(255) NOT NULL,
 error_type VARCHAR(100) NULL,
 first_seen_at DATETIME NOT NULL,
 last_seen_at DATETIME NOT NULL,
 occurrences INT NOT NULL DEFAULT 1,
 affected_sessions INT NOT NULL DEFAULT 1,
 resolved_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_error_issue(project_id,fingerprint),
 INDEX idx_error_project_last(project_id,last_seen_at),
 CONSTRAINT fk_ei_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE error_issue_sessions (
 issue_id BIGINT UNSIGNED NOT NULL,
 runtime_session_id BIGINT UNSIGNED NOT NULL,
 first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(issue_id,runtime_session_id),
 CONSTRAINT fk_eis_issue FOREIGN KEY(issue_id) REFERENCES error_issues(id) ON DELETE CASCADE,
 CONSTRAINT fk_eis_session FOREIGN KEY(runtime_session_id) REFERENCES runtime_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE uptime_checks (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id BIGINT UNSIGNED NOT NULL,
 status ENUM('good','degraded','down') NOT NULL,
 status_code INT NULL,
 response_ms INT NULL,
 checked_at DATETIME NOT NULL,
 error_message VARCHAR(500) NULL,
 INDEX idx_uptime_project_time(project_id,checked_at),
 CONSTRAINT fk_uc_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE incidents (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id BIGINT UNSIGNED NOT NULL,
 status ENUM('ongoing','resolved') NOT NULL DEFAULT 'ongoing',
 title VARCHAR(255) NOT NULL,
 opened_at DATETIME NOT NULL,
 resolved_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_incident_project(project_id,status,opened_at),
 CONSTRAINT fk_inc_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE trust_reports (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id BIGINT UNSIGNED NOT NULL,
 report_id VARCHAR(40) NOT NULL UNIQUE,
 verified_checks INT NOT NULL,
 applicable_checks INT NOT NULL,
 score INT NOT NULL,
 generated_at DATETIME NOT NULL,
 payload_json JSON NOT NULL,
 CONSTRAINT fk_tr_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE project_agents (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id BIGINT UNSIGNED NOT NULL,
 agent_key ENUM('security_auditor','ops_inspector','code_reviewer','compliance_checker') NOT NULL,
 enabled TINYINT(1) NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_agent(project_id,agent_key),
 CONSTRAINT fk_pa_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE agent_runs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id BIGINT UNSIGNED NOT NULL,
 agent_key VARCHAR(80) NOT NULL,
 status ENUM('queued','running','completed','failed') NOT NULL DEFAULT 'queued',
 input_json JSON NULL,
 output_json JSON NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 completed_at DATETIME NULL,
 CONSTRAINT fk_ar_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE client_pricing (
 project_id BIGINT UNSIGNED PRIMARY KEY,
 monthly_price_cents INT NOT NULL DEFAULT 999,
 access_days INT NOT NULL DEFAULT 30,
 currency CHAR(3) NOT NULL DEFAULT 'USD',
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_cp_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE stripe_connections (
 project_id BIGINT UNSIGNED PRIMARY KEY,
 account_id VARCHAR(100) NULL,
 onboarding_status ENUM('not_connected','incomplete','complete') NOT NULL DEFAULT 'not_connected',
 charges_enabled TINYINT(1) NOT NULL DEFAULT 0,
 payouts_enabled TINYINT(1) NOT NULL DEFAULT 0,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_sc_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE clients (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id BIGINT UNSIGNED NOT NULL,
 email VARCHAR(190) NOT NULL,
 external_customer_id VARCHAR(100) NULL,
 status ENUM('active','expired','canceled','pending') NOT NULL DEFAULT 'pending',
 access_started_at DATETIME NULL,
 access_expires_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_clients_project_status(project_id,status),
 CONSTRAINT fk_clients_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE plans (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 plan_key VARCHAR(40) NOT NULL UNIQUE,
 name VARCHAR(80) NOT NULL,
 monthly_price_cents INT NOT NULL,
 annual_price_cents INT NULL,
 token_allocation INT NOT NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE subscriptions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 plan_id BIGINT UNSIGNED NOT NULL,
 status ENUM('active','past_due','canceled','trialing') NOT NULL DEFAULT 'active',
 billing_cycle ENUM('monthly','annual') NOT NULL DEFAULT 'monthly',
 external_subscription_id VARCHAR(100) NULL,
 current_period_end DATETIME NULL,
 cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_sub_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_sub_plan FOREIGN KEY(plan_id) REFERENCES plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE usage_ledger (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 project_id BIGINT UNSIGNED NULL,
 action_key VARCHAR(80) NOT NULL,
 description VARCHAR(255) NOT NULL,
 tokens_delta INT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_usage_user_time(user_id,created_at),
 CONSTRAINT fk_ul_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_ul_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE referrals (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 referrer_user_id BIGINT UNSIGNED NOT NULL,
 referred_user_id BIGINT UNSIGNED NOT NULL,
 status ENUM('pending','activated') NOT NULL DEFAULT 'pending',
 reward_tokens INT NOT NULL DEFAULT 1000,
 reward_cycle_key CHAR(7) NULL,
 activated_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_referral_referred(referred_user_id),
 CONSTRAINT fk_ref_referrer FOREIGN KEY(referrer_user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_ref_referred FOREIGN KEY(referred_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notification_preferences (
 user_id BIGINT UNSIGNED PRIMARY KEY,
 onboarding TINYINT(1) NOT NULL DEFAULT 1,
 weekly_digest TINYINT(1) NOT NULL DEFAULT 1,
 alerts TINYINT(1) NOT NULL DEFAULT 1,
 promotional TINYINT(1) NOT NULL DEFAULT 1,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_np_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NULL,
 project_id BIGINT UNSIGNED NULL,
 action_key VARCHAR(100) NOT NULL,
 ip_hash CHAR(64) NULL,
 meta_json JSON NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_audit_user_time(user_id,created_at),
 CONSTRAINT fk_al_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT fk_al_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE stripe_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 event_id VARCHAR(120) NOT NULL UNIQUE,
 event_type VARCHAR(120) NOT NULL,
 processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE rate_limits (
 bucket_key CHAR(64) PRIMARY KEY,
 count INT NOT NULL DEFAULT 0,
 window_started_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE password_resets (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 token_hash CHAR(64) NOT NULL UNIQUE,
 expires_at DATETIME NOT NULL,
 used_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_pr_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE billing_transactions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 kind ENUM('subscription','topup') NOT NULL,
 description VARCHAR(255) NOT NULL,
 amount_cents INT NOT NULL,
 currency CHAR(3) NOT NULL DEFAULT 'USD',
 status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
 external_checkout_id VARCHAR(120) NULL UNIQUE,
 external_payment_id VARCHAR(120) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 paid_at DATETIME NULL,
 INDEX idx_bt_user_time(user_id,created_at),
 CONSTRAINT fk_bt_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 project_id BIGINT UNSIGNED NULL,
 type VARCHAR(80) NOT NULL,
 title VARCHAR(190) NOT NULL,
 message TEXT NOT NULL,
 action_url VARCHAR(500) NULL,
 read_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_notifications_user_time(user_id,created_at),
 CONSTRAINT fk_notifications_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_notifications_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notification_deliveries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 delivery_key VARCHAR(120) NOT NULL,
 sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_notification_delivery(user_id,delivery_key),
 CONSTRAINT fk_nd_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE schema_migrations (
 migration_key VARCHAR(120) PRIMARY KEY,
 applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_migrations(migration_key) VALUES ('stage5_final'),('stage5_3_platform_admin'),('stage5_4_ai_evidence_scan'),('stage5_5_builder_workflow');

INSERT INTO plans(plan_key,name,monthly_price_cents,annual_price_cents,token_allocation) VALUES
('free','Free plan',0,0,1000),
('pro','Pro',2500,25000,10000);

INSERT INTO quick_questions(check_key,question,options_json) VALUES
('backups','Do you have automatic, restorable backups?', JSON_ARRAY(
  JSON_OBJECT('key','yes','label','Yes, automatic backups are enabled','status','ready','evidence','Owner confirmed automatic backups.'),
  JSON_OBJECT('key','no','label','No, there are no automatic backups','status','need_attention','evidence','Owner reported no automatic backups.'),
  JSON_OBJECT('key','unknown','label','I am not sure','status','question_required','evidence','Backup status remains unknown.')
)),
('role_separation','Does your app need different permission levels?', JSON_ARRAY(
  JSON_OBJECT('key','configured','label','Yes, and roles are already set up','status','ready','evidence','Owner confirmed role separation is configured.'),
  JSON_OBJECT('key','needed','label','Yes, I need different roles','status','need_attention','evidence','Owner confirmed role separation is needed but not configured.'),
  JSON_OBJECT('key','same','label','No, everyone should have the same access','status','not_applicable','evidence','Owner confirmed separate roles are not applicable to this product.')
)),
('rollback','Can you roll back a bad production release?', JSON_ARRAY(
  JSON_OBJECT('key','yes','label','Yes, a tested rollback path exists','status','ready','evidence','Owner confirmed a rollback path exists.'),
  JSON_OBJECT('key','no','label','No, there is no reliable rollback path','status','need_attention','evidence','Owner reported no reliable rollback path.'),
  JSON_OBJECT('key','unknown','label','I am not sure','status','question_required','evidence','Rollback status remains unknown.')
)),
('cost_cap','Do you have spending or usage limits?', JSON_ARRAY(
  JSON_OBJECT('key','yes','label','Yes, limits are configured','status','ready','evidence','Owner confirmed spending or usage limits.'),
  JSON_OBJECT('key','no','label','No, usage can grow without a cap','status','need_attention','evidence','Owner reported no spending or usage limits.'),
  JSON_OBJECT('key','unknown','label','I am not sure','status','question_required','evidence','Cost-control status remains unknown.')
)),
('ai_ownership','Have you verified the commercial rights for AI-generated code/content used by this app?', JSON_ARRAY(
  JSON_OBJECT('key','verified','label','Yes, the rights are verified','status','ready','evidence','Owner confirmed AI-generated asset rights were reviewed.'),
  JSON_OBJECT('key','not_verified','label','No, I have not verified this','status','need_attention','evidence','Owner has not verified AI-generated asset rights.'),
  JSON_OBJECT('key','not_applicable','label','The app does not use AI-generated code/content','status','not_applicable','evidence','Owner marked AI ownership as not applicable.')
));

-- Stage 4 performance indexes for Sessions/Clients filters.
ALTER TABLE runtime_sessions ADD INDEX idx_rs_project_env (project_id, os, browser);
ALTER TABLE runtime_sessions ADD INDEX idx_rs_project_country (project_id, country_code);
ALTER TABLE runtime_sessions ADD INDEX idx_rs_project_duration (project_id, duration_seconds);
ALTER TABLE runtime_events ADD INDEX idx_re_session_signal (runtime_session_id, event_type, signal_type);
ALTER TABLE clients ADD INDEX idx_clients_project_email (project_id, email);
ALTER TABLE scans ADD INDEX idx_scans_project_type_status (project_id, type, status, created_at);
ALTER TABLE source_scans ADD INDEX idx_source_project_status (project_id, status, completed_at);
ALTER TABLE runtime_events ADD INDEX idx_re_project_signal_time (project_id, event_type, signal_type, occurred_at);
ALTER TABLE error_issues ADD INDEX idx_error_project_open (project_id, resolved_at, last_seen_at);

-- No default/admin user is seeded here on purpose: a shared or well-known
-- credential shipped in a public schema file is a standing security risk.
-- Sign up for your own account through the running site, then promote it to
-- platform administrator with the one-line statement in DEPLOYMENT.md.
