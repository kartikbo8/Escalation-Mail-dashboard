-- Migration 10: Add Marketing department
USE escalation_tracker;

INSERT INTO departments (name)
SELECT 'Marketing'
WHERE NOT EXISTS (SELECT 1 FROM departments WHERE name = 'Marketing');

INSERT INTO sla_settings (department_id, response_hours)
SELECT d.id, 48
FROM departments d
WHERE d.name = 'Marketing'
  AND NOT EXISTS (
      SELECT 1 FROM sla_settings s WHERE s.department_id = d.id
  );