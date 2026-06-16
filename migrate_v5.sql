-- DigiTracker v5 migration
-- Adds: Credit Cards module + Shopping/Gmail module

-- ── Credit Cards ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `credit_cards` (
  `id`               INT NOT NULL AUTO_INCREMENT,
  `user_id`          INT NOT NULL,
  `card_holder`      VARCHAR(100) NOT NULL,
  `bank_name`        VARCHAR(100) NOT NULL,
  `card_name`        VARCHAR(100) NOT NULL,
  `card_last4`       CHAR(4) NOT NULL,
  `card_network`     ENUM('visa','mastercard','rupay','amex') DEFAULT 'visa',
  `credit_limit`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `current_balance`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `statement_date`   TINYINT NOT NULL DEFAULT 1,
  `payment_due_days` TINYINT NOT NULL DEFAULT 20,
  `interest_rate`    DECIMAL(5,2) DEFAULT 3.50,
  `card_color`       VARCHAR(7) DEFAULT '#1e3a5f',
  `status`           ENUM('active','inactive','blocked') DEFAULT 'active',
  `notes`            TEXT DEFAULT NULL,
  `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Monthly Card Bills ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `card_bills` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `user_id`       INT NOT NULL,
  `card_id`       INT NOT NULL,
  `bill_month`    VARCHAR(7) NOT NULL,
  `statement_date` DATE NOT NULL,
  `due_date`      DATE NOT NULL,
  `bill_amount`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `min_due`       DECIMAL(12,2) DEFAULT 0.00,
  `paid_amount`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status`        ENUM('pending','partially_paid','paid','overdue') DEFAULT 'pending',
  `paid_date`     DATE DEFAULT NULL,
  `payment_mode`  VARCHAR(50) DEFAULT NULL,
  `notes`         TEXT DEFAULT NULL,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `card_month` (`card_id`, `bill_month`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Gmail OAuth Tokens ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `gmail_tokens` (
  `id`               INT NOT NULL AUTO_INCREMENT,
  `user_id`          INT NOT NULL,
  `gmail_email`      VARCHAR(150) NOT NULL DEFAULT '',
  `access_token`     TEXT NOT NULL,
  `refresh_token`    TEXT NOT NULL,
  `token_expires_at` DATETIME NOT NULL,
  `last_sync_at`     DATETIME DEFAULT NULL,
  `sync_count`       INT DEFAULT 0,
  `connected_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Shopping Orders ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `shopping_orders` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `user_id`       INT NOT NULL,
  `platform`      ENUM('amazon','flipkart','myntra','swiggy','zomato','nykaa','meesho','other') DEFAULT 'other',
  `order_id`      VARCHAR(100) NOT NULL,
  `product_name`  VARCHAR(500) NOT NULL,
  `seller`        VARCHAR(200) DEFAULT NULL,
  `amount`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `order_date`    DATE NOT NULL,
  `status`        ENUM('ordered','shipped','delivered','cancelled','returned') DEFAULT 'ordered',
  `gmail_msg_id`  VARCHAR(200) DEFAULT NULL,
  `source`        ENUM('gmail','manual') DEFAULT 'manual',
  `notes`         TEXT DEFAULT NULL,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gmail_order` (`user_id`, `gmail_msg_id`),
  KEY `user_id` (`user_id`),
  KEY `platform` (`platform`),
  KEY `order_date` (`order_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
