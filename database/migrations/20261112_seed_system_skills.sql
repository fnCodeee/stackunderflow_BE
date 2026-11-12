-- Migration: seed the static system skills (2026-11-12)
-- The 8 template role tags of the New Project modal (Coding, UI Design,
-- Backend, Testing, DevOps, Mobile, Data, Writing) as scope='system',
-- created_by=NULL rows: visible to every user via GET /api/skills,
-- usable by everyone in a collab, and impossible to delete (the DELETE
-- endpoint answers 403 for system skills). Idempotent: a name that
-- already exists as a system skill is skipped.
-- Applies to: db_stack_underflow
INSERT INTO skills (id, skill_name, scope, created_by)
SELECT UUID(), 'Coding', 'system', NULL
WHERE NOT EXISTS (SELECT 1 FROM skills WHERE scope = 'system' AND skill_name = 'Coding');

INSERT INTO skills (id, skill_name, scope, created_by)
SELECT UUID(), 'UI Design', 'system', NULL
WHERE NOT EXISTS (SELECT 1 FROM skills WHERE scope = 'system' AND skill_name = 'UI Design');

INSERT INTO skills (id, skill_name, scope, created_by)
SELECT UUID(), 'Backend', 'system', NULL
WHERE NOT EXISTS (SELECT 1 FROM skills WHERE scope = 'system' AND skill_name = 'Backend');

INSERT INTO skills (id, skill_name, scope, created_by)
SELECT UUID(), 'Testing', 'system', NULL
WHERE NOT EXISTS (SELECT 1 FROM skills WHERE scope = 'system' AND skill_name = 'Testing');

INSERT INTO skills (id, skill_name, scope, created_by)
SELECT UUID(), 'DevOps', 'system', NULL
WHERE NOT EXISTS (SELECT 1 FROM skills WHERE scope = 'system' AND skill_name = 'DevOps');

INSERT INTO skills (id, skill_name, scope, created_by)
SELECT UUID(), 'Mobile', 'system', NULL
WHERE NOT EXISTS (SELECT 1 FROM skills WHERE scope = 'system' AND skill_name = 'Mobile');

INSERT INTO skills (id, skill_name, scope, created_by)
SELECT UUID(), 'Data', 'system', NULL
WHERE NOT EXISTS (SELECT 1 FROM skills WHERE scope = 'system' AND skill_name = 'Data');

INSERT INTO skills (id, skill_name, scope, created_by)
SELECT UUID(), 'Writing', 'system', NULL
WHERE NOT EXISTS (SELECT 1 FROM skills WHERE scope = 'system' AND skill_name = 'Writing');
