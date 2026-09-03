-- DigiTracker v7 migration
-- Adds an uploaded card photo, shown on the card face instead of a flat color.
-- Run once on the server: mysql digitracker < migrate_v7.sql

ALTER TABLE credit_cards
  ADD COLUMN card_image VARCHAR(255) NULL DEFAULT NULL AFTER card_color;
