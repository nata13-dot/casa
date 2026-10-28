-- Casa · pasar la cuenta a otro dispositivo con un código temporal.
-- Ejecutar después de 003:  sudo mariadb casa < database/migrations/004_device_transfer.sql
-- Idempotente.

SET NAMES utf8mb4;

-- Un código vivo por usuario. Solo se guarda el hash; caduca a los 10 minutos.
CREATE TABLE IF NOT EXISTS device_transfers (
  user_id     INT UNSIGNED NOT NULL,
  code_hash   CHAR(64)     NOT NULL,
  expires_at  DATETIME     NOT NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_transfer_code (code_hash),
  CONSTRAINT fk_transfer_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
