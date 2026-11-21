-- Runs once when the MySQL volume is first created.
-- The test database is separate so test runs never touch development data.
CREATE DATABASE IF NOT EXISTS albabook_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON albabook_test.* TO 'albabook'@'%';
FLUSH PRIVILEGES;
