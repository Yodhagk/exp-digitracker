-- DigiTracker v6 migration
-- Adds role-based access control and account suspension.
-- Run once on the server: mysql digitracker < migrate_v6.sql

ALTER TABLE users
  ADD COLUMN role   ENUM('admin','user') NOT NULL DEFAULT 'user'   AFTER password,
  ADD COLUMN status ENUM('active','suspended') NOT NULL DEFAULT 'active' AFTER role;

-- Bootstrap: the earliest-created account becomes the first admin, so there is
-- always at least one admin able to manage the Users page after this migration.
UPDATE users SET role = 'admin' ORDER BY id ASC LIMIT 1;
