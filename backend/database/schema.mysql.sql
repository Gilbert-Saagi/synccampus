-- Saagi SyncCampus schema for MySQL 8 / MariaDB 10.4+ (XAMPP, shared hosting).
-- Create an empty database first (e.g. "synccampus" in phpMyAdmin), then import
-- this file or run `php bin/migrate.php`. Safe to run more than once.

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL UNIQUE,
    password_hash VARCHAR(300) NOT NULL,
    role VARCHAR(20) NOT NULL,                       -- admin | president | vice_guild | delegate
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_invitations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    role VARCHAR(20) NOT NULL,
    invitee_name VARCHAR(120) NOT NULL DEFAULT '',
    invitee_email VARCHAR(160) NULL,
    portfolio VARCHAR(120) NOT NULL DEFAULT '',
    categories_handled VARCHAR(300) NOT NULL DEFAULT '',
    max_open_load INT NOT NULL DEFAULT 5,
    campus VARCHAR(60) NOT NULL DEFAULT '',
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    redeemed_at DATETIME NULL,
    revoked_at DATETIME NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE,
    base_severity DOUBLE NOT NULL DEFAULT 0.5,        -- 0..1, the S factor of the priority score
    is_sensitive BOOLEAN NOT NULL DEFAULT FALSE        -- always private, never in public statistics
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delegates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    portfolio VARCHAR(120) NOT NULL DEFAULT '',
    categories_handled VARCHAR(300) NOT NULL DEFAULT '',  -- comma separated category names, or "General"
    max_open_load INT NOT NULL DEFAULT 5,
    campus VARCHAR(60) NOT NULL DEFAULT '',                 -- empty = covers every campus
    is_available BOOLEAN NOT NULL DEFAULT TRUE,
    show_public BOOLEAN NOT NULL DEFAULT TRUE,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS related_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    master_concern_id INT NOT NULL,
    reports_count INT NOT NULL DEFAULT 2,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS concerns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(20) NOT NULL UNIQUE,
    access_code_hash VARCHAR(100) NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    category_id INT NOT NULL,
    campus VARCHAR(60) NOT NULL DEFAULT '',
    location VARCHAR(120) NOT NULL DEFAULT '',
    urgency INT NOT NULL DEFAULT 2,                  -- 1 low .. 4 critical
    safety_flag BOOLEAN NOT NULL DEFAULT FALSE,
    academic_impact BOOLEAN NOT NULL DEFAULT FALSE,
    financial_impact BOOLEAN NOT NULL DEFAULT FALSE,
    students_affected INT NOT NULL DEFAULT 1,
    is_public BOOLEAN NOT NULL DEFAULT FALSE,
    status VARCHAR(20) NOT NULL DEFAULT 'new',       -- new | assigned | in_progress | resolved | closed
    is_overdue BOOLEAN NOT NULL DEFAULT FALSE,
    priority_score DOUBLE NOT NULL DEFAULT 0,
    priority_level VARCHAR(10) NOT NULL DEFAULT 'Low',
    group_id INT NULL,
    created_at DATETIME NOT NULL,
    first_response_at DATETIME NULL,
    resolved_at DATETIME NULL,
    reopened_count INT NOT NULL DEFAULT 0,
    satisfaction_rating INT NULL,                    -- 1..5, given by the student when closing
    INDEX ix_concerns_status_score (status, priority_score),
    INDEX ix_concerns_category_created (category_id, created_at),
    FOREIGN KEY (category_id) REFERENCES categories(id),
    FOREIGN KEY (group_id) REFERENCES related_groups(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    concern_id INT NOT NULL,
    delegate_id INT NOT NULL,
    recommended_score DOUBLE NOT NULL DEFAULT 0,
    was_overridden BOOLEAN NOT NULL DEFAULT FALSE,   -- leader picked someone other than the top suggestion
    assigned_by INT NOT NULL,
    assigned_at DATETIME NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',    -- active | completed | reassigned
    completed_at DATETIME NULL,
    seen_at DATETIME NULL,                           -- when the delegate first opened it
    INDEX ix_assignments_delegate_status (delegate_id, status),
    FOREIGN KEY (concern_id) REFERENCES concerns(id),
    FOREIGN KEY (delegate_id) REFERENCES delegates(id),
    FOREIGN KEY (assigned_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS concern_updates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    concern_id INT NOT NULL,
    author_user_id INT NULL,
    author_label VARCHAR(60) NOT NULL DEFAULT 'Student',
    visibility VARCHAR(10) NOT NULL DEFAULT 'private', -- public | private (student sees) | internal (staff only)
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (concern_id) REFERENCES concerns(id),
    FOREIGN KEY (author_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS escalation_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    concern_id INT NOT NULL,
    type VARCHAR(15) NOT NULL,                       -- reminder | escalation
    reason VARCHAR(200) NOT NULL,
    triggered_at DATETIME NOT NULL,
    acknowledged_at DATETIME NULL,
    FOREIGN KEY (concern_id) REFERENCES concerns(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(60) NOT NULL,
    entity VARCHAR(40) NOT NULL,
    entity_id INT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS handover_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    term_label VARCHAR(60) NOT NULL,
    snapshot LONGTEXT NOT NULL,                      -- JSON
    generated_by INT NOT NULL,
    generated_at DATETIME NOT NULL,
    FOREIGN KEY (generated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kind VARCHAR(20) NOT NULL,                       -- project | announcement | event | media
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    visibility VARCHAR(12) NOT NULL DEFAULT 'staff', -- staff | public
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    due_at DATETIME NULL,
    location VARCHAR(160) NOT NULL DEFAULT '',
    url VARCHAR(1000) NOT NULL DEFAULT '',
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX ix_workspace_items_kind (kind),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',      -- open | in_progress | completed
    assigned_to_user_id INT NULL,
    due_at DATETIME NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX ix_project_tasks_project_id (project_id),
    FOREIGN KEY (project_id) REFERENCES workspace_items(id),
    FOREIGN KEY (assigned_to_user_id) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_suggestions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(20) NOT NULL UNIQUE,
    access_code_hash VARCHAR(100) NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'new',       -- new | reviewing | accepted | declined | implemented
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS suggestion_updates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    suggestion_id INT NOT NULL,
    author_user_id INT NULL,
    author_label VARCHAR(120) NOT NULL DEFAULT 'Guild',
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX ix_suggestion_updates_suggestion_id (suggestion_id),
    FOREIGN KEY (suggestion_id) REFERENCES student_suggestions(id),
    FOREIGN KEY (author_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS surveys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    question TEXT NOT NULL,
    options_json TEXT NOT NULL,
    is_published BOOLEAN NOT NULL DEFAULT FALSE,
    closes_at DATETIME NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS survey_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    survey_id INT NOT NULL,
    respondent_hash VARCHAR(64) NOT NULL,
    answer VARCHAR(500) NOT NULL,
    comment TEXT NOT NULL,
    submitted_at DATETIME NOT NULL,
    UNIQUE KEY uq_survey_respondent (survey_id, respondent_hash),
    FOREIGN KEY (survey_id) REFERENCES surveys(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS internal_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    author_user_id INT NOT NULL,
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (author_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS request_throttle (
    throttle_key VARCHAR(200) PRIMARY KEY,           -- e.g. "concern:SC-7KQ2XP" or "login:name@example.com"
    attempts INT NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    label VARCHAR(200) NOT NULL DEFAULT '',          -- e.g. "Chrome on Windows"
    created_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    INDEX ix_sessions_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_login_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    device_hash VARCHAR(64) NOT NULL,                -- the link only works in the browser that asked for it
    device_label VARCHAR(200) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO categories (name, base_severity, is_sensitive) VALUES
    ('Academic issues', 0.60, FALSE),
    ('Examinations', 0.80, FALSE),
    ('Registration', 0.55, FALSE),
    ('Timetables', 0.50, FALSE),
    ('Tuition / fees', 0.65, FALSE),
    ('ICT / Internet', 0.55, FALSE),
    ('Campus facilities', 0.45, FALSE),
    ('Security', 0.90, FALSE),
    ('Student welfare', 0.60, FALSE),
    ('Administration', 0.45, FALSE),
    ('Student activities / events', 0.30, FALSE),
    ('Harassment / discrimination', 0.95, TRUE),
    ('Other', 0.40, FALSE);
