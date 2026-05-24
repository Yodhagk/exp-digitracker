-- DigiTracker v4 migration
-- Adds tenure tracking to loans and EMI linkage to expenses

-- Loan tenure (in months)
ALTER TABLE loans
  ADD COLUMN tenure_months INT NULL DEFAULT NULL AFTER card_last4;

-- Expense EMI linkage (which loan generated this entry, and whether auto-generated)
ALTER TABLE expenses
  ADD COLUMN loan_ref_id INT NULL DEFAULT NULL AFTER card_last4,
  ADD COLUMN auto_generated TINYINT(1) NOT NULL DEFAULT 0 AFTER loan_ref_id;
