-- ============================================================
-- Migration 4: Departments from HR keyword matrix only
-- Run after migration_3_multilevel_escalation.sql
-- Allowed departments: Design, Costing, PPC, Projects, Production, Sales
-- ============================================================
USE escalation_tracker;

-- Add only departments that are explicitly covered by the HR keyword email.
INSERT INTO departments (name)
SELECT 'Design' WHERE NOT EXISTS (SELECT 1 FROM departments WHERE name = 'Design');
INSERT INTO departments (name)
SELECT 'Costing' WHERE NOT EXISTS (SELECT 1 FROM departments WHERE name = 'Costing');
INSERT INTO departments (name)
SELECT 'PPC' WHERE NOT EXISTS (SELECT 1 FROM departments WHERE name = 'PPC');
INSERT INTO departments (name)
SELECT 'Projects' WHERE NOT EXISTS (SELECT 1 FROM departments WHERE name = 'Projects');
INSERT INTO departments (name)
SELECT 'Production' WHERE NOT EXISTS (SELECT 1 FROM departments WHERE name = 'Production');
INSERT INTO departments (name)
SELECT 'Sales' WHERE NOT EXISTS (SELECT 1 FROM departments WHERE name = 'Sales');

-- Keep SLA rows aligned with the allowed departments.
INSERT INTO sla_settings (department_id, response_hours)
SELECT d.id, 48
FROM departments d
WHERE d.name IN ('Design','Costing','PPC','Projects','Production','Sales')
  AND NOT EXISTS (SELECT 1 FROM sla_settings s WHERE s.department_id = d.id);

-- Remove departments not covered by the HR keyword matrix.
-- Foreign keys are configured with ON DELETE SET NULL/CASCADE, so related
-- user/ticket references are cleared safely rather than pointing to invalid IDs.
DELETE FROM departments
WHERE name NOT IN ('Design','Costing','PPC','Projects','Production','Sales');
