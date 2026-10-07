-- ============================================================
-- Migration 2: Escalation Matrix (keywords, reminders, Sales dept)
-- Run this in phpMyAdmin SQL tab AFTER the original schema.sql
-- ============================================================
USE escalation_tracker;

-- ------------------------------------------------------------
-- Add Sales department (pilot department per HR's request)
-- ------------------------------------------------------------
INSERT INTO departments (name) VALUES ('Sales');
INSERT INTO sla_settings (department_id, response_hours)
SELECT id, 48 FROM departments WHERE name = 'Sales';

-- ------------------------------------------------------------
-- New ticket columns: priority tag + reminder tracking
-- ------------------------------------------------------------
ALTER TABLE tickets
    ADD COLUMN priority_tag ENUM('customer_project','ppc_internal','escalation_trigger','normal')
        DEFAULT 'normal' AFTER status,
    ADD COLUMN matched_keyword VARCHAR(150) DEFAULT NULL AFTER priority_tag,
    ADD COLUMN reminder_sent_at DATETIME DEFAULT NULL AFTER sla_due_at;

-- ------------------------------------------------------------
-- Keyword categories (used to classify incoming/outgoing emails)
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
('customer_project','Finalisation'), ('customer_project','Order Confirmation'), ('customer_project','PO'),
('customer_project','Purchase Order'), ('customer_project','Project Requirement'), ('customer_project','Project Query'),
('customer_project','Client Query'), ('customer_project','Customer Query'), ('customer_project','Clarification Required'),
('customer_project','Technical Clarification');

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
-- Seed escalation contacts: HR (Priya Singh) + Admin
-- Update emails/names below if different.
-- ------------------------------------------------------------
INSERT INTO escalation_contacts (role_type, name, email, department_id) VALUES
('hr', 'Priya Singh', 'corporatehr@afcindia.in', NULL),
('admin', 'Admin', 'admin@example.com', NULL);
