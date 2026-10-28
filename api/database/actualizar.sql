-- ============================================================================
--  Casa · actualizar la base de datos del servidor a la última versión
-- ============================================================================
--  Úsalo SIEMPRE que actualices la API (después de subir los archivos nuevos):
--
--      sudo mariadb casa < actualizar.sql
--
--  - Sirve desde cualquier versión anterior (1.0 o la instalación original).
--  - Se puede correr las veces que quieras: lo que ya está aplicado se salta.
--  - No borra datos. Haz respaldo antes de todos modos:
--      sudo mariadb-dump casa > ~/casa_respaldo_$(date +%Y%m%d_%H%M).sql
--
--  Versión del esquema: 1.1  (incluye migrations/002, 003 y 004)
-- ============================================================================

SET NAMES utf8mb4;

-- ─── v1.0 · seguridad: roles, aprobación de dispositivos, intentos, historial ───
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

-- ─── v1.1 · tareas de los perritos y pasar la cuenta a otro dispositivo ───

-- Catálogo de tareas por perro.
CREATE TABLE IF NOT EXISTS dog_tasks (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dog_id      TINYINT UNSIGNED NOT NULL,
  kind        ENUM('food','water','medicine') NOT NULL,
  label       VARCHAR(40)  NOT NULL COMMENT 'Momento del día: Mañana, Noche, Hoy…',
  sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  active      TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = ya no se muestra (conserva historial)',
  PRIMARY KEY (id),
  UNIQUE KEY uq_task (dog_id, kind, label),
  CONSTRAINT fk_task_dog FOREIGN KEY (dog_id) REFERENCES dogs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO dog_tasks (dog_id, kind, label, sort_order)
SELECT id, 'food',  'Mañana', 1 FROM dogs
UNION ALL SELECT id, 'food',  'Noche', 2 FROM dogs
UNION ALL SELECT id, 'water', 'Hoy',   3 FROM dogs;

INSERT IGNORE INTO dog_tasks (dog_id, kind, label, sort_order)
SELECT id, 'medicine', 'Mañana', 4 FROM dogs WHERE name = 'Mushu'
UNION ALL SELECT id, 'medicine', 'Noche', 5 FROM dogs WHERE name = 'Mushu';

-- dog_checks pasa a apuntar a una tarea. Solo si todavía tiene el formato viejo (columna "type").
DROP PROCEDURE IF EXISTS casa_migrar_dog_checks;
DELIMITER //
CREATE PROCEDURE casa_migrar_dog_checks()
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dog_checks' AND COLUMN_NAME = 'type') THEN

    ALTER TABLE dog_checks ADD COLUMN task_id SMALLINT UNSIGNED NULL AFTER id;

    UPDATE dog_checks c
      JOIN dog_tasks t
        ON t.dog_id = c.dog_id
       AND t.kind = c.type
       AND t.label = IF(c.type = 'food', 'Mañana', 'Hoy')
       SET c.task_id = t.id;

    DELETE FROM dog_checks WHERE task_id IS NULL;

    ALTER TABLE dog_checks
      DROP FOREIGN KEY fk_check_dog,
      DROP INDEX uq_check,
      DROP COLUMN dog_id,
      DROP COLUMN type,
      MODIFY task_id SMALLINT UNSIGNED NOT NULL,
      ADD UNIQUE KEY uq_check (task_id, check_date),
      ADD CONSTRAINT fk_check_task FOREIGN KEY (task_id) REFERENCES dog_tasks (id) ON DELETE CASCADE;
  END IF;
END //
DELIMITER ;
CALL casa_migrar_dog_checks();
DROP PROCEDURE casa_migrar_dog_checks;

-- Códigos para pasar la cuenta a otro dispositivo.
CREATE TABLE IF NOT EXISTS device_transfers (
  user_id     INT UNSIGNED NOT NULL,
  code_hash   CHAR(64)     NOT NULL,
  expires_at  DATETIME     NOT NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_transfer_code (code_hash),
  CONSTRAINT fk_transfer_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Resultado: debe mostrar las tareas de cada perro y "dog_checks listo".
SELECT d.name AS perro,
       GROUP_CONCAT(CONCAT(t.kind, ' ', t.label) ORDER BY t.sort_order SEPARATOR ', ') AS tareas
  FROM dogs d JOIN dog_tasks t ON t.dog_id = d.id
 GROUP BY d.id ORDER BY d.sort_order;

SELECT IF(COUNT(*) = 1, 'dog_checks listo', 'REVISAR dog_checks') AS estado
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dog_checks' AND COLUMN_NAME = 'task_id';
