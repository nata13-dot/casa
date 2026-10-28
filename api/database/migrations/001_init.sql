-- Casa · esquema inicial
-- Ejecutar:  mysql -u casa_user -p casa < database/migrations/001_init.sql
-- Es idempotente (IF NOT EXISTS / INSERT IGNORE): se puede volver a correr sin perder datos.

SET NAMES utf8mb4;

-- Un usuario = un dispositivo. El token de sesión se guarda solo como hash SHA-256.
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(60)  NOT NULL,
  phone         VARCHAR(20)  NOT NULL COMMENT 'Solo dígitos, con lada de país (ej. 525512345678)',
  device_id     VARCHAR(100) NOT NULL,
  token_hash    CHAR(64)     NOT NULL,
  fcm_token     VARCHAR(255) NULL,
  platform      VARCHAR(20)  NULL,
  last_seen_at  DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_phone (phone),
  UNIQUE KEY uq_users_device (device_id),
  UNIQUE KEY uq_users_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dogs (
  id          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(40)  NOT NULL,
  photo_path  VARCHAR(160) NULL,
  sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dogs_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO dogs (name, sort_order) VALUES ('Luna', 1), ('Romeo', 2), ('Mushu', 3);

-- Una fila por perro / día / tipo. Si existe la fila, está marcado.
-- El reinicio diario es implícito: un nuevo día no tiene filas.
CREATE TABLE IF NOT EXISTS dog_checks (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  dog_id      TINYINT UNSIGNED NOT NULL,
  check_date  DATE         NOT NULL,
  type        ENUM('food','water') NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  checked_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_check (dog_id, check_date, type),
  KEY idx_check_date (check_date),
  CONSTRAINT fk_check_dog  FOREIGN KEY (dog_id)  REFERENCES dogs (id)  ON DELETE CASCADE,
  CONSTRAINT fk_check_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- category se valida en la API (ver src/Categories.php) para poder añadir
-- categorías sin migrar la BD.
CREATE TABLE IF NOT EXISTS announcements (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  category    VARCHAR(20)  NOT NULL,
  priority    ENUM('normal','importante','urgente') NOT NULL DEFAULT 'normal',
  title       VARCHAR(120) NOT NULL,
  body        TEXT         NULL,
  done_by     INT UNSIGNED NULL,
  done_at     DATETIME     NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ann_created (created_at),
  KEY idx_ann_done (done_at),
  CONSTRAINT fk_ann_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_ann_done FOREIGN KEY (done_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Enterado": quién ya vio el aviso.
CREATE TABLE IF NOT EXISTS announcement_acks (
  announcement_id INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  created_at      DATETIME     NOT NULL,
  PRIMARY KEY (announcement_id, user_id),
  CONSTRAINT fk_ack_ann  FOREIGN KEY (announcement_id) REFERENCES announcements (id) ON DELETE CASCADE,
  CONSTRAINT fk_ack_user FOREIGN KEY (user_id)         REFERENCES users (id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
