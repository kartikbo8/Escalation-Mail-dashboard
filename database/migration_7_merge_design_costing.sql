-- ============================================================
-- Migration 7: Merge Design and Costing into one department
-- Run after migration_4_department_cleanup.sql
-- ============================================================
USE escalation_tracker;

START TRANSACTION;

INSERT INTO departments (name)
SELECT 'Design & Costing'
WHERE NOT EXISTS (SELECT 1 FROM departments WHERE name = 'Design & Costing');

-- Move users, tickets, and contacts before removing the old departments.
UPDATE users u
JOIN departments old_d ON old_d.id = u.department_id
JOIN departments new_d ON new_d.name = 'Design & Costing'
SET u.department_id = new_d.id
WHERE old_d.name IN ('Design', 'Costing');

UPDATE tickets t
JOIN departments old_d ON old_d.id = t.department_id
JOIN departments new_d ON new_d.name = 'Design & Costing'
SET t.department_id = new_d.id
WHERE old_d.name IN ('Design', 'Costing');

UPDATE escalation_contacts c
JOIN departments old_d ON old_d.id = c.department_id
JOIN departments new_d ON new_d.name = 'Design & Costing'
SET c.department_id = new_d.id
WHERE old_d.name IN ('Design', 'Costing');

-- Keep one SLA row for the merged department. Prefer the new department's value;
-- otherwise retain the Design value, then Costing, then the default of 48 hours.
INSERT INTO sla_settings (department_id, response_hours)
SELECT new_d.id,
       COALESCE(
           (SELECT response_hours FROM sla_settings s JOIN departments d ON d.id = s.department_id WHERE d.name = 'Design & Costing' LIMIT 1),
           (SELECT response_hours FROM sla_settings s JOIN departments d ON d.id = s.department_id WHERE d.name = 'Design' LIMIT 1),
           (SELECT response_hours FROM sla_settings s JOIN departments d ON d.id = s.department_id WHERE d.name = 'Costing' LIMIT 1),
           48
       )
FROM departments new_d
WHERE new_d.name = 'Design & Costing'
  AND NOT EXISTS (SELECT 1 FROM sla_settings s WHERE s.department_id = new_d.id);

DELETE old_sla
FROM sla_settings old_sla
JOIN departments old_d ON old_d.id = old_sla.department_id
WHERE old_d.name IN ('Design', 'Costing');

-- Preserve an existing HOD; if none exists, use the old department's HOD.
UPDATE departments new_d
LEFT JOIN departments design_d ON design_d.name = 'Design'
LEFT JOIN departments costing_d ON costing_d.name = 'Costing'
SET new_d.hod_user_id = COALESCE(new_d.hod_user_id, design_d.hod_user_id, costing_d.hod_user_id)
WHERE new_d.name = 'Design & Costing';

DELETE FROM departments WHERE name IN ('Design', 'Costing');

COMMIT;
