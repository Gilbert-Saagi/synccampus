-- Saagi SyncCampus schema for PostgreSQL 13+ (Neon in production).
-- Run with `php bin/migrate.php`, or paste into the Neon SQL editor.
-- Safe to run more than once, including on a database created by the
-- earlier Python version of the system (the table layout is the same).

CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL UNIQUE,
    password_hash VARCHAR(300) NOT NULL,             -- unused: everyone signs in with an emailed link
    role VARCHAR(20) NOT NULL,                       -- admin | president | vice_guild | delegate
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT (now() AT TIME ZONE 'utc')
);

CREATE TABLE IF NOT EXISTS staff_invitations (
    id SERIAL PRIMARY KEY,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    role VARCHAR(20) NOT NULL,
    invitee_name VARCHAR(120) NOT NULL DEFAULT '',
    invitee_email VARCHAR(160),
    portfolio VARCHAR(120) NOT NULL DEFAULT '',
    categories_handled VARCHAR(300) NOT NULL DEFAULT '',
    max_open_load INTEGER NOT NULL DEFAULT 5,
    campus VARCHAR(60) NOT NULL DEFAULT '',
    created_by INTEGER NOT NULL REFERENCES users(id),
    created_at TIMESTAMP NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    redeemed_at TIMESTAMP,
    revoked_at TIMESTAMP
);

CREATE TABLE IF NOT EXISTS categories (
    id SERIAL PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE,
    base_severity DOUBLE PRECISION NOT NULL DEFAULT 0.5,
    is_sensitive BOOLEAN NOT NULL DEFAULT FALSE
);

CREATE TABLE IF NOT EXISTS delegates (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL UNIQUE REFERENCES users(id),
    portfolio VARCHAR(120) NOT NULL DEFAULT '',
    categories_handled VARCHAR(300) NOT NULL DEFAULT '',
    max_open_load INTEGER NOT NULL DEFAULT 5,
    campus VARCHAR(60) NOT NULL DEFAULT '',
    is_available BOOLEAN NOT NULL DEFAULT TRUE,
    show_public BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS related_groups (
    id SERIAL PRIMARY KEY,
    master_concern_id INTEGER NOT NULL,
    reports_count INTEGER NOT NULL DEFAULT 2,
    created_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS concerns (
    id SERIAL PRIMARY KEY,
    reference VARCHAR(20) NOT NULL UNIQUE,
    access_code_hash VARCHAR(100) NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    category_id INTEGER NOT NULL REFERENCES categories(id),
    campus VARCHAR(60) NOT NULL DEFAULT '',
    location VARCHAR(120) NOT NULL DEFAULT '',
    urgency INTEGER NOT NULL DEFAULT 2,
    safety_flag BOOLEAN NOT NULL DEFAULT FALSE,
    academic_impact BOOLEAN NOT NULL DEFAULT FALSE,
    financial_impact BOOLEAN NOT NULL DEFAULT FALSE,
    students_affected INTEGER NOT NULL DEFAULT 1,
    is_public BOOLEAN NOT NULL DEFAULT FALSE,
    status VARCHAR(20) NOT NULL DEFAULT 'new',
    is_overdue BOOLEAN NOT NULL DEFAULT FALSE,
    priority_score DOUBLE PRECISION NOT NULL DEFAULT 0,
    priority_level VARCHAR(10) NOT NULL DEFAULT 'Low',
    group_id INTEGER REFERENCES related_groups(id),
    created_at TIMESTAMP NOT NULL,
    first_response_at TIMESTAMP,
    resolved_at TIMESTAMP,
    reopened_count INTEGER NOT NULL DEFAULT 0,
    satisfaction_rating INTEGER
);
CREATE INDEX IF NOT EXISTS ix_concerns_status_score ON concerns (status, priority_score);
CREATE INDEX IF NOT EXISTS ix_concerns_category_created ON concerns (category_id, created_at);

CREATE TABLE IF NOT EXISTS assignments (
    id SERIAL PRIMARY KEY,
    concern_id INTEGER NOT NULL REFERENCES concerns(id),
    delegate_id INTEGER NOT NULL REFERENCES delegates(id),
    recommended_score DOUBLE PRECISION NOT NULL DEFAULT 0,
    was_overridden BOOLEAN NOT NULL DEFAULT FALSE,
    assigned_by INTEGER NOT NULL REFERENCES users(id),
    assigned_at TIMESTAMP NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    completed_at TIMESTAMP,
    seen_at TIMESTAMP
);
CREATE INDEX IF NOT EXISTS ix_assignments_delegate_status ON assignments (delegate_id, status);

CREATE TABLE IF NOT EXISTS concern_updates (
    id SERIAL PRIMARY KEY,
    concern_id INTEGER NOT NULL REFERENCES concerns(id),
    author_user_id INTEGER REFERENCES users(id),
    author_label VARCHAR(60) NOT NULL DEFAULT 'Student',
    visibility VARCHAR(10) NOT NULL DEFAULT 'private',
    message TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS escalation_events (
    id SERIAL PRIMARY KEY,
    concern_id INTEGER NOT NULL REFERENCES concerns(id),
    type VARCHAR(15) NOT NULL,
    reason VARCHAR(200) NOT NULL,
    triggered_at TIMESTAMP NOT NULL,
    acknowledged_at TIMESTAMP
);

CREATE TABLE IF NOT EXISTS audit_log (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id),
    action VARCHAR(60) NOT NULL,
    entity VARCHAR(40) NOT NULL,
    entity_id INTEGER,
    created_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS handover_records (
    id SERIAL PRIMARY KEY,
    term_label VARCHAR(60) NOT NULL,
    snapshot TEXT NOT NULL,
    generated_by INTEGER NOT NULL REFERENCES users(id),
    generated_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS workspace_items (
    id SERIAL PRIMARY KEY,
    kind VARCHAR(20) NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    visibility VARCHAR(12) NOT NULL DEFAULT 'staff',
    starts_at TIMESTAMP,
    ends_at TIMESTAMP,
    due_at TIMESTAMP,
    location VARCHAR(160) NOT NULL DEFAULT '',
    url VARCHAR(1000) NOT NULL DEFAULT '',
    created_by INTEGER NOT NULL REFERENCES users(id),
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_workspace_items_kind ON workspace_items (kind);

CREATE TABLE IF NOT EXISTS project_tasks (
    id SERIAL PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES workspace_items(id),
    title VARCHAR(200) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    assigned_to_user_id INTEGER REFERENCES users(id),
    due_at TIMESTAMP,
    created_by INTEGER NOT NULL REFERENCES users(id),
    created_at TIMESTAMP NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_project_tasks_project_id ON project_tasks (project_id);

CREATE TABLE IF NOT EXISTS student_suggestions (
    id SERIAL PRIMARY KEY,
    reference VARCHAR(20) NOT NULL UNIQUE,
    access_code_hash VARCHAR(100) NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'new',
    created_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS suggestion_updates (
    id SERIAL PRIMARY KEY,
    suggestion_id INTEGER NOT NULL REFERENCES student_suggestions(id),
    author_user_id INTEGER REFERENCES users(id),
    author_label VARCHAR(120) NOT NULL DEFAULT 'Guild',
    message TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_suggestion_updates_suggestion_id ON suggestion_updates (suggestion_id);

CREATE TABLE IF NOT EXISTS surveys (
    id SERIAL PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    question TEXT NOT NULL,
    options_json TEXT NOT NULL,
    is_published BOOLEAN NOT NULL DEFAULT FALSE,
    closes_at TIMESTAMP,
    created_by INTEGER NOT NULL REFERENCES users(id),
    created_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS survey_responses (
    id SERIAL PRIMARY KEY,
    survey_id INTEGER NOT NULL REFERENCES surveys(id),
    respondent_hash VARCHAR(64) NOT NULL,
    answer VARCHAR(500) NOT NULL,
    comment TEXT NOT NULL,
    submitted_at TIMESTAMP NOT NULL,
    CONSTRAINT uq_survey_respondent UNIQUE (survey_id, respondent_hash)
);

CREATE TABLE IF NOT EXISTS internal_messages (
    id SERIAL PRIMARY KEY,
    author_user_id INTEGER NOT NULL REFERENCES users(id),
    message TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS request_throttle (
    throttle_key VARCHAR(200) PRIMARY KEY,
    attempts INTEGER NOT NULL DEFAULT 0,
    locked_until TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS sessions (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id),
    label VARCHAR(200) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL,
    last_seen_at TIMESTAMP NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    revoked_at TIMESTAMP
);
CREATE INDEX IF NOT EXISTS ix_sessions_user ON sessions (user_id);

CREATE TABLE IF NOT EXISTS login_links (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id),
    portal VARCHAR(10) NOT NULL,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    device_hash VARCHAR(64) NOT NULL,
    device_label VARCHAR(200) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP
);

INSERT INTO categories (name, base_severity, is_sensitive) VALUES
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
    ('Other', 0.40, FALSE)
ON CONFLICT (name) DO NOTHING;
