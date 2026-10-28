-- Casa · seguridad: roles, aprobación de dispositivos, límite de intentos, auditoría.
-- Ejecutar después de 001:  sudo mariadb casa < database/migrations/002_security.sql
-- Idempotente.

SET NAMES utf8mb4;

-- Rol del usuario. Los usuarios existentes quedan como miembros activos.
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS role ENUM('admin','member') NOT NULL DEFAULT 'member' AFTER phone,
  ADD COLUMN IF NOT EXISTS approved_by INT UNSIGNED NULL AFTER platform,
  ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL AFTER approved_by;

-- Si aún no hay admin, el usuario más antiguo lo es.
UPDATE users SET role = 'admin'
 WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM users) t)
   AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM users WHERE role = 'admin') a);

-- Solicitudes de acceso: alguien nuevo o un cambio de celular.
-- El token emitido solo sirve para consultar el estado hasta que un admin apruebe;
-- al aprobar, el mismo token pasa a users y se vuelve una sesión normal.
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

-- Intentos de registro para limitar fuerza bruta (se purgan solos después de 1 día).
CREATE TABLE IF NOT EXISTS auth_attempts (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip          VARCHAR(45)  NOT NULL,
  kind        ENUM('bad_code','request') NOT NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_attempt_ip (ip, kind, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bitácora de eventos importantes (visible para admins). Se conserva 1 año.
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

-- Ajustes editables desde la app (p. ej. hash del código de la casa).
CREATE TABLE IF NOT EXISTS settings (
  name        VARCHAR(40)  NOT NULL,
  value       VARCHAR(255) NOT NULL,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
