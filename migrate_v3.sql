-- DigiTracker v3 migration
-- Adds payment_mode and card_last4 tracking to expenses table
-- Run once on the server: mysql -u digiuser -p digitracker < migrate_v3.sql

ALTER TABLE expenses
  ADD COLUMN payment_mode ENUM('cash','bank_transfer','card','upi') NOT NULL DEFAULT 'cash' AFTER notes,
  ADD COLUMN card_last4 VARCHAR(4) NULL DEFAULT NULL AFTER payment_mode;
