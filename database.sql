-- ============================================================
-- ONOW Enable OKR Management System — Database Schema v4
-- ============================================================
-- Run this ONCE on a fresh database.
-- After import, visit: http://localhost/onow_v4/fix_passwords.php
-- then DELETE fix_passwords.php immediately.
-- ============================================================

CREATE DATABASE IF NOT EXISTS onow_enable_okr CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE onow_enable_okr;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    full_name     VARCHAR(100)  NOT NULL,
    email         VARCHAR(150)  NOT NULL UNIQUE,
    password_hash VARCHAR(255)  NOT NULL,
    role          ENUM('Admin','Manager','Member') NOT NULL DEFAULT 'Member',
    phone         VARCHAR(30),
    department    VARCHAR(100),
    job_title     VARCHAR(100),
    avatar_color  VARCHAR(7)    DEFAULT '#2563EB',
    is_active     TINYINT(1)    DEFAULT 1,
    last_login    DATETIME,
    created_at    DATETIME      DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS teams (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    description TEXT,
    created_by  INT NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_team_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS team_members (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    team_id     INT NOT NULL,
    user_id     INT NOT NULL,
    assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tm_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    CONSTRAINT fk_tm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_team_member (team_id, user_id)
);

CREATE TABLE IF NOT EXISTS objectives (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    title               VARCHAR(255) NOT NULL,
    description         TEXT,
    type                ENUM('Organisational','Team','Personal') NOT NULL,
    status              ENUM('On Track','At Risk','Behind','Completed','Not Started') DEFAULT 'Not Started',
    time_period         VARCHAR(50)  NOT NULL,
    start_date          DATE,
    end_date            DATE,
    owner_id            INT NOT NULL,
    created_by          INT NOT NULL,
    parent_objective_id INT          DEFAULT NULL,
    team_id             INT          DEFAULT NULL,
    progress            DECIMAL(5,2) DEFAULT 0.00,
    deleted_at          DATETIME     DEFAULT NULL,
    created_at          DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_obj_owner   FOREIGN KEY (owner_id)            REFERENCES users(id)      ON DELETE RESTRICT,
    CONSTRAINT fk_obj_creator FOREIGN KEY (created_by)          REFERENCES users(id)      ON DELETE RESTRICT,
    CONSTRAINT fk_obj_parent  FOREIGN KEY (parent_objective_id) REFERENCES objectives(id) ON DELETE RESTRICT,
    CONSTRAINT fk_obj_team    FOREIGN KEY (team_id)             REFERENCES teams(id)      ON DELETE SET NULL
);
CREATE INDEX idx_obj_deleted ON objectives(deleted_at);
CREATE INDEX idx_obj_owner   ON objectives(owner_id);
CREATE INDEX idx_obj_team    ON objectives(team_id);

CREATE TABLE IF NOT EXISTS objective_members (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    objective_id INT NOT NULL,
    user_id      INT NOT NULL,
    assigned_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_om_objective FOREIGN KEY (objective_id) REFERENCES objectives(id) ON DELETE RESTRICT,
    CONSTRAINT fk_om_user      FOREIGN KEY (user_id)      REFERENCES users(id)      ON DELETE CASCADE,
    UNIQUE KEY uq_obj_member (objective_id, user_id)
);

CREATE TABLE IF NOT EXISTS key_results (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    objective_id  INT NOT NULL,
    title         VARCHAR(255)  NOT NULL,
    description   TEXT,
    metric_type   ENUM('Percentage','Number','Currency','Boolean') DEFAULT 'Percentage',
    target_value  DECIMAL(15,2) NOT NULL DEFAULT 100,
    current_value DECIMAL(15,2) NOT NULL DEFAULT 0,
    unit          VARCHAR(50),
    progress      DECIMAL(5,2)  DEFAULT 0.00,
    status        ENUM('On Track','At Risk','Behind','Completed','Not Started') DEFAULT 'Not Started',
    owner_id      INT NOT NULL,
    deleted_at    DATETIME DEFAULT NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_kr_objective FOREIGN KEY (objective_id) REFERENCES objectives(id) ON DELETE RESTRICT,
    CONSTRAINT fk_kr_owner     FOREIGN KEY (owner_id)     REFERENCES users(id)      ON DELETE RESTRICT
);
CREATE INDEX idx_kr_deleted ON key_results(deleted_at);
CREATE INDEX idx_kr_obj     ON key_results(objective_id);

CREATE TABLE IF NOT EXISTS progress_history (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    key_result_id  INT NOT NULL,
    previous_value DECIMAL(15,2),
    new_value      DECIMAL(15,2),
    note           TEXT,
    updated_by     INT NOT NULL,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ph_kr   FOREIGN KEY (key_result_id) REFERENCES key_results(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ph_user FOREIGN KEY (updated_by)    REFERENCES users(id)       ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS objective_attachments (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    objective_id  INT NOT NULL,
    file_name     VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_type     VARCHAR(100),
    file_size     INT,
    uploaded_by   INT NOT NULL,
    uploaded_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_oa_objective FOREIGN KEY (objective_id) REFERENCES objectives(id) ON DELETE RESTRICT,
    CONSTRAINT fk_oa_user      FOREIGN KEY (uploaded_by)  REFERENCES users(id)      ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS activity_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT,
    action      VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50),
    entity_id   INT,
    description TEXT,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

SET FOREIGN_KEY_CHECKS = 1;

-- Seed users (placeholder hash — run fix_passwords.php after import)
INSERT INTO users (full_name, email, password_hash, role, department, job_title, avatar_color) VALUES
('System Administrator',  'admin@onow-enable.org',          'PLACEHOLDER', 'Admin',   'IT',                   'System Admin',      '#1e40af'),
('Sarah Johnson',         'sarah.johnson@onow-enable.org',  'PLACEHOLDER', 'Manager', 'Programme Management', 'Programme Manager', '#059669'),
('James Chen',            'james.chen@onow-enable.org',     'PLACEHOLDER', 'Member',  'Programme Management', 'Programme Officer', '#7c3aed'),
('Aisha Mohammed',        'aisha.mohammed@onow-enable.org', 'PLACEHOLDER', 'Member',  'Outreach',             'Community Liaison', '#dc2626');

INSERT INTO teams (name, description, created_by) VALUES
('Programme Management', 'Core programme delivery team', 1),
('Community Outreach',   'Community engagement team', 1);

INSERT INTO team_members (team_id, user_id) VALUES
(1, 2), (1, 3),
(2, 2), (2, 4);
