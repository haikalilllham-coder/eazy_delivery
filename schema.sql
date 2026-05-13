
CREATE DATABASE IF NOT EXISTS eazy_delivery CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE eazy_delivery;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  phone VARCHAR(30) DEFAULT NULL,
  role ENUM('admin','driver') NOT NULL DEFAULT 'driver',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS members (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  balance BIGINT NOT NULL DEFAULT 0,
  points INT NOT NULL DEFAULT 0,
  discount_percent INT NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value TEXT NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id INT NULL,
  driver_id INT NULL,
  customer_name VARCHAR(150) NOT NULL,
  customer_phone VARCHAR(30) NOT NULL,
  service_type VARCHAR(100) NOT NULL,
  payment_method ENUM('cash','non_cash','member_balance') NOT NULL DEFAULT 'cash',
  pickup_address TEXT NOT NULL,
  drop_address TEXT NOT NULL,
  notes TEXT NULL,
  extra_stops INT NOT NULL DEFAULT 0,
  base_fee INT NOT NULL DEFAULT 10000,
  extra_fee INT NOT NULL DEFAULT 0,
  member_discount INT NOT NULL DEFAULT 0,
  total_fee INT NOT NULL DEFAULT 10000,
  is_priority TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('pending','accepted','picked_up','completed','rejected') NOT NULL DEFAULT 'pending',
  accepted_at DATETIME NULL,
  rejected_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_orders_created(created_at),
  INDEX idx_orders_driver(driver_id),
  INDEX idx_orders_member(member_id),
  CONSTRAINT fk_orders_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL,
  CONSTRAINT fk_orders_driver FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  status VARCHAR(30) NOT NULL,
  note TEXT NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_order_logs_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_rejections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  driver_id INT NOT NULL,
  reason TEXT NULL,
  rejection_date DATETIME NOT NULL,
  INDEX idx_reject_driver_date(driver_id, rejection_date),
  CONSTRAINT fk_rejections_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_rejections_driver FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  attendance_date DATE NOT NULL,
  status ENUM('hadir','telat','izin','lembur') NOT NULL DEFAULT 'hadir',
  note TEXT NULL,
  photo_path VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_attendance_date(attendance_date),
  CONSTRAINT fk_attendance_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS member_transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id INT NOT NULL,
  order_id INT NULL,
  type ENUM('credit','debit') NOT NULL,
  amount BIGINT NOT NULL DEFAULT 0,
  note TEXT NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_member_transactions_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  CONSTRAINT fk_member_transactions_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO users (username, password_hash, full_name, phone, role, is_active, created_at) VALUES
('admin', '$2y$10$B2jLzFqA4w5g7jN0K7eN7e2rVqf6d2v6bXfP7JfJY8X9e3o7OqB2m', 'Admin EAZY DELIVERY', '081200000001', 'admin', 1, NOW()),
('driver1', '$2y$10$B2jLzFqA4w5g7jN0K7eN7e2rVqf6d2v6bXfP7JfJY8X9e3o7OqB2m', 'Driver 1', '081200000002', 'driver', 1, NOW()),
('driver2', '$2y$10$B2jLzFqA4w5g7jN0K7eN7e2rVqf6d2v6bXfP7JfJY8X9e3o7OqB2m', 'Driver 2', '081200000003', 'driver', 1, NOW())
ON DUPLICATE KEY UPDATE username=username;

INSERT INTO settings (setting_key, setting_value) VALUES
('company_name', 'EAZY DELIVERY'),
('company_discount_percent', '0')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
