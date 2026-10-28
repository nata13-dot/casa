-- ============================================================================
--  Casa · instalación completa de la base de datos (servidor)
-- ============================================================================
--  Crea la base, el usuario de la app con permisos mínimos y todas las tablas
--  (equivale a 001_init.sql + 002_security.sql).
--
--  Uso en el servidor (Debian + MariaDB):
--      sudo mariadb < instalar_servidor.sql
--
--  Es idempotente: si se vuelve a ejecutar NO borra datos.
--  Compatible con MariaDB 10.5+ y MySQL 8.
--
--  PLANTILLA: copia como instalar_servidor.sql y reemplaza CAMBIA_ESTA_CONTRASENA
--  (2 veces) por el valor de DB_PASS de api/.env. Ese archivo NO se sube a git.
--  Bórralo del servidor después de usarlo:  shred -u instalar_servidor.sql
-- ============================================================================

SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS casa
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

-- Usuario de la app: solo por socket local, solo lectura/escritura de datos.
-- (Las migraciones futuras se corren como root, no con este usuario.)
CREATE USER IF NOT EXISTS 'casa_user'@'localhost' IDENTIFIED BY 'CAMBIA_ESTA_CONTRASENA';
ALTER USER 'casa_user'@'localhost' IDENTIFIED BY 'CAMBIA_ESTA_CONTRASENA';
GRANT SELECT, INSERT, UPDATE, DELETE ON casa.* TO 'casa_user'@'localhost';
FLUSH PRIVILEGES;

USE casa;

-- ---------------------------------------------------------------------------
-- Usuarios: un usuario = un dispositivo. El token se guarda solo como SHA-256.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(60)  NOT NULL,
  phone         VARCHAR(20)  NOT NULL COMMENT 'Solo dígitos, con lada de país (ej. 525512345678)',
  role          ENUM('admin','member') NOT NULL DEFAULT 'member',
  device_id     VARCHAR(100) NOT NULL,
  token_hash    CHAR(64)     NOT NULL,
  fcm_token     VARCHAR(255) NULL,
  platform      VARCHAR(20)  NULL,
  approved_by   INT UNSIGNED NULL,
  approved_at   DATETIME     NULL,
  last_seen_at  DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_phone (phone),
  UNIQUE KEY uq_users_device (device_id),
  UNIQUE KEY uq_users_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Perros y checklist diario (una fila por perro/día/tipo = marcado).
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- Avisos y "Enterado".
-- ---------------------------------------------------------------------------
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

CREATE TABLE IF NOT EXISTS announcement_acks (
  announcement_id INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  created_at      DATETIME     NOT NULL,
  PRIMARY KEY (announcement_id, user_id),
  CONSTRAINT fk_ack_ann  FOREIGN KEY (announcement_id) REFERENCES announcements (id) ON DELETE CASCADE,
  CONSTRAINT fk_ack_user FOREIGN KEY (user_id)         REFERENCES users (id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Seguridad: solicitudes de acceso, intentos, bitácora, ajustes.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS access_requests (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name             VARCHAR(60)  NOT NULL,
  phone            VARCHAR(20)  NOT NULL,
  device_id        VARCHAR(100) NOT NULL,
  token_hash       CHAR(64)     NOT NULL,
  platform         VARCHAR(20)  NULL,
  existing_user_id INT UNSIGNED NULL COMMENT 'Si el teléfono ya existe: cambio de celular',
  ip               VARCHAR(45)  NULL,
  created_at       DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_req_device (device_id),
  UNIQUE KEY uq_req_token (token_hash),
  KEY idx_req_phone (phone),
  CONSTRAINT fk_req_user FOREIGN KEY (existing_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_attempts (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip          VARCHAR(45)  NOT NULL,
  kind        ENUM('bad_code','request') NOT NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_attempt_ip (ip, kind, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_id    INT UNSIGNED NULL,
  actor_name  VARCHAR(60)  NULL COMMENT 'Copia del nombre: se conserva aunque el usuario se elimine',
  action      VARCHAR(40)  NOT NULL,
  summary     VARCHAR(255) NOT NULL,
  ip          VARCHAR(45)  NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  name        VARCHAR(40)  NOT NULL,
  value       VARCHAR(255) NOT NULL,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Resumen
SELECT 'Base de datos casa lista' AS resultado,
       (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'casa') AS tablas,
       (SELECT GROUP_CONCAT(name ORDER BY sort_order) FROM dogs) AS perros;
