-- ============================================================
-- Email Escalation Tracker - Consolidated Schema
-- (Base schema + migrations 2-6 merged into one file)
-- Fresh installs only need to import THIS ONE FILE.
-- The individual migration_*.sql files are kept for history/reference.
-- ============================================================

CREATE DATABASE IF NOT EXISTS escalation_tracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE escalation_tracker;

-- ------------------------------------------------------------
-- Departments (per HR's keyword matrix: Design, Costing, PPC,
-- Projects, Production, Sales - pilot department)
-- ------------------------------------------------------------
CREATE TABLE departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    mailbox_email VARCHAR(255) DEFAULT NULL,   -- shared mailbox tied to this department, if any
    hod_user_id INT DEFAULT NULL,              -- Head of Department (set after users table exists)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO departments (name) VALUES
('Design'), ('Costing'), ('PPC'), ('Projects'), ('Production'), ('Sales'), ('Marketing'), ('HR');

-- ------------------------------------------------------------
-- Users (Super Admin / Admin / User) with reporting manager chain
-- ------------------------------------------------------------
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('super_admin', 'admin', 'user') NOT NULL DEFAULT 'user',
    department_id INT DEFAULT NULL,
    reporting_manager_id INT DEFAULT NULL,      -- primary manager / Level 2 recipient
    reporting_manager_2_id INT DEFAULT NULL,    -- secondary manager / optional Level 2 recipient
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    FOREIGN KEY (reporting_manager_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (reporting_manager_2_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Now that users exists, wire up the HOD foreign key on departments
ALTER TABLE departments
    ADD CONSTRAINT fk_hod_user FOREIGN KEY (hod_user_id)
        REFERENCES users(id) ON DELETE SET NULL;

-- ------------------------------------------------------------
-- Working Hours (per day of week, used for SLA calculation)
-- ------------------------------------------------------------
CREATE TABLE working_hours (
    id INT AUTO_INCREMENT PRIMARY KEY,
    day_of_week TINYINT NOT NULL,   -- 0=Sunday ... 6=Saturday
    is_working_day TINYINT(1) DEFAULT 1,
    start_time TIME DEFAULT '10:00:00',
    end_time TIME DEFAULT '19:00:00'
);

-- ------------------------------------------------------------
-- Holidays (dates excluded from SLA calculation)
-- ------------------------------------------------------------
CREATE TABLE holidays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE NOT NULL UNIQUE,
    name VARCHAR(150) DEFAULT NULL
);

-- ------------------------------------------------------------
-- SLA Settings (per department, e.g. 48 working hours)
-- ------------------------------------------------------------
CREATE TABLE sla_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    response_hours INT NOT NULL DEFAULT 48,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
);

INSERT INTO sla_settings (department_id, response_hours)
SELECT id, 48 FROM departments;

-- ------------------------------------------------------------
-- HR / Admin contacts (who gets Level 3 final escalation emails)
-- ------------------------------------------------------------
CREATE TABLE escalation_contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_type ENUM('founder', 'hr', 'admin') NOT NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(255) NOT NULL,
    department_id INT DEFAULT NULL,   -- NULL = applies to all departments
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
);

INSERT INTO escalation_contacts (role_type, name, email, department_id) VALUES
('hr', 'Priya Singh', 'corporatehr@afcindia.in', NULL),
('admin', 'Admin', 'admin@example.com', NULL);

-- ------------------------------------------------------------
-- Keyword Categories (used to classify incoming email SUBJECTS -
-- a match flags the ticket "important" and queues it for escalation)
-- ------------------------------------------------------------
CREATE TABLE keyword_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category ENUM('customer_project', 'ppc_internal', 'escalation_trigger') NOT NULL,
    keyword VARCHAR(150) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_cat_kw (category, keyword)
);

-- Category 1: External Customer / Project Emails (Highest Priority)
INSERT INTO keyword_categories (category, keyword) VALUES
('customer_project','Quotation'), ('customer_project','Quote'), ('customer_project','RFQ'),
('customer_project','Request for Quotation'), ('customer_project','Enquiry'), ('customer_project','Price Request'),
('customer_project','Commercial Offer'), ('customer_project','Proposal'), ('customer_project','BOQ'),
('customer_project','Tender'), ('customer_project','Technical Proposal'), ('customer_project','Design Proposal'),
('customer_project','Drawing'), ('customer_project','Revised Drawing'), ('customer_project','Client Approval'),
('customer_project','Customer Approval'), ('customer_project','Sample Approval'), ('customer_project','Mock-up'),
('customer_project','Finalisation'), ('customer_project','PO'),
('customer_project','Purchase Order'), ('customer_project','Project Requirement'), ('customer_project','Project Query'),
('customer_project','Client Query'), ('customer_project','Customer Query'), ('customer_project','Clarification Required'),
('customer_project','Technical Clarification'), ('customer_project','NEW ORDER'), ('customer_project','ORDER REQUEST'),
('customer_project','ORDER CONFIRMATION');

-- Category 2: PPC / Internal Project-Critical Emails
INSERT INTO keyword_categories (category, keyword) VALUES
('ppc_internal','Production Requirement'), ('ppc_internal','PPC Requirement'), ('ppc_internal','Production Clarification'),
('ppc_internal','Design Clarification'), ('ppc_internal','Drawing Required'), ('ppc_internal','Drawing Revision'),
('ppc_internal','Drawing Approval'), ('ppc_internal','Specification'), ('ppc_internal','Specification Clarification'),
('ppc_internal','Material Specification'), ('ppc_internal','Technical Details'), ('ppc_internal','BOM'),
('ppc_internal','BOM Clarification'), ('ppc_internal','Cutting Plan'), ('ppc_internal','CNC Data'),
('ppc_internal','Manufacturing Drawing'), ('ppc_internal','Release for Production'), ('ppc_internal','Production Release'),
('ppc_internal','Dispatch Requirement'), ('ppc_internal','Site Requirement'), ('ppc_internal','Site Issue'),
('ppc_internal','Installation Issue'), ('ppc_internal','Urgent Design Input'), ('ppc_internal','Design Confirmation'),
('ppc_internal','Design Change'), ('ppc_internal','Revision Required');

-- Category 3: Strong Escalation Trigger Words
INSERT INTO keyword_categories (category, keyword) VALUES
('escalation_trigger','URGENT'), ('escalation_trigger','CRITICAL'), ('escalation_trigger','IMMEDIATE ACTION'),
('escalation_trigger','ACTION REQUIRED'), ('escalation_trigger','RESPONSE REQUIRED'), ('escalation_trigger','REPLY REQUIRED'),
('escalation_trigger','RESPONSE PENDING'), ('escalation_trigger','AWAITING RESPONSE'), ('escalation_trigger','AWAITING CONFIRMATION'),
('escalation_trigger','PENDING CONFIRMATION'), ('escalation_trigger','FINAL DEADLINE'), ('escalation_trigger','DEADLINE'),
('escalation_trigger','TODAY'), ('escalation_trigger','EOD'), ('escalation_trigger','ASAP'),
('escalation_trigger','CLIENT FOLLOW-UP'), ('escalation_trigger','CUSTOMER FOLLOW-UP'), ('escalation_trigger','CLIENT ESCALATION'),
('escalation_trigger','CUSTOMER ESCALATION'), ('escalation_trigger','PROJECT DELAY'), ('escalation_trigger','ORDER AT RISK');

-- ------------------------------------------------------------
-- Tickets (each = one email thread being tracked through the
-- 3-level escalation flow: Employee -> Manager/HOD -> Director/HR)
-- ------------------------------------------------------------
CREATE TABLE tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    subject VARCHAR(500) NOT NULL,
    sender_name VARCHAR(255) DEFAULT NULL,
    sender_email VARCHAR(255) NOT NULL,
    to_recipients_json TEXT DEFAULT NULL,      -- original To recipients; CC is intentionally excluded
    conversation_id VARCHAR(500) NOT NULL,     -- Graph API conversationId, used to match replies
    graph_message_id VARCHAR(500) DEFAULT NULL, -- original message id from Graph API
    source_mailbox VARCHAR(255) DEFAULT NULL,   -- mailbox containing the original Graph message
    status ENUM('open', 'replied', 'closed', 'escalated') NOT NULL DEFAULT 'open',
    escalation_level TINYINT NOT NULL DEFAULT 0,
        -- 0 = Level 1 (assigned employee), 1 = Level 2 (manager/HOD), 2 = Level 3 (director/HR, terminal)
    priority_tag ENUM('customer_project','ppc_internal','escalation_trigger','normal') DEFAULT 'normal',
    matched_keyword VARCHAR(150) DEFAULT NULL,
    is_important TINYINT(1) NOT NULL DEFAULT 0,      -- subject matched a keyword
    escalation_queued TINYINT(1) NOT NULL DEFAULT 0, -- eligible for Level 2/3 escalation
    assigned_to INT DEFAULT NULL,
    received_at DATETIME NOT NULL,
    stage_started_at DATETIME DEFAULT NULL,    -- when the CURRENT escalation level's clock started
    replied_at DATETIME DEFAULT NULL,
    closed_at DATETIME DEFAULT NULL,
    sla_due_at DATETIME DEFAULT NULL,          -- deadline for the CURRENT escalation level
    reminder_sent_at DATETIME DEFAULT NULL,    -- halfway-point reminder for the CURRENT level
    snoozed_until DATETIME DEFAULT NULL,       -- pause reminders/escalation until this time
    stop_employee_notifications TINYINT(1) NOT NULL DEFAULT 0,  -- pause Level 1 emails (admin/super_admin)
    stop_manager_notifications TINYINT(1) NOT NULL DEFAULT 0,   -- pause Level 2 emails (super_admin only)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_conversation (conversation_id),
    INDEX idx_status (status),
    INDEX idx_important_queue (is_important, escalation_queued)
);

-- ------------------------------------------------------------
-- Timeline Events (audit trail per ticket: received/replied/
-- reminders/escalated/closed)
-- ------------------------------------------------------------
CREATE TABLE timeline_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    event_type ENUM('received', 'replied', 'no_reply_warning', 'escalated', 'closed', 'reopened') NOT NULL,
    event_time DATETIME NOT NULL,
    note TEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,   -- NULL if system-generated (cron)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- ------------------------------------------------------------
-- Escalations (Level 3 Founder/HR notification log)
-- ------------------------------------------------------------
CREATE TABLE escalations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    escalated_to_contact_id INT NOT NULL,
    escalated_at DATETIME NOT NULL,
    email_sent TINYINT(1) DEFAULT 0,
    email_sent_at DATETIME DEFAULT NULL,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (escalated_to_contact_id) REFERENCES escalation_contacts(id) ON DELETE CASCADE
);

-- ------------------------------------------------------------
-- Weekly report delivery tracking (retry failed sends; prevent duplicates)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS weekly_report_deliveries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_key VARCHAR(320) NOT NULL UNIQUE,
    week_start DATE NOT NULL,
    week_end DATE NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    report_scope ENUM('department', 'full') NOT NULL,
    department_id INT DEFAULT NULL,
    delivery_status ENUM('sent', 'failed') NOT NULL,
    attempted_at DATETIME NOT NULL,
    sent_at DATETIME DEFAULT NULL,
    last_error VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_weekly_report_period (week_start, week_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Default working hours (Mon-Sat 10am-7pm, Sunday off) - adjust as needed
-- ------------------------------------------------------------
INSERT INTO working_hours (day_of_week, is_working_day, start_time, end_time) VALUES
(0, 0, '10:00:00', '19:00:00'), -- Sunday off
(1, 1, '10:00:00', '19:00:00'),
(2, 1, '10:00:00', '19:00:00'),
(3, 1, '10:00:00', '19:00:00'),
(4, 1, '10:00:00', '19:00:00'),
(5, 1, '10:00:00', '19:00:00'),
(6, 1, '10:00:00', '19:00:00');
