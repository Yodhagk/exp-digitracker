-- DigiTracker v2 migration
-- Adds payment_mode and card_last4 tracking to loans table

ALTER TABLE loans
  ADD COLUMN payment_mode ENUM('cash','bank_transfer','card','upi') NOT NULL DEFAULT 'cash' AFTER notes,
  ADD COLUMN card_last4 VARCHAR(4) NULL DEFAULT NULL AFTER payment_mode;
