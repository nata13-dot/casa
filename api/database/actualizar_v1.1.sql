-- ============================================================================
--  Casa · actualización a la versión 1.1 (servidor)
-- ============================================================================
--  - Perritos: comida mañana y noche, agua, y pastillas de Mushu mañana y noche.
--  - Pasar la cuenta a otro dispositivo con un código temporal.
--  (Equivale a migrations/003_dog_tasks.sql + 004_device_transfer.sql.)
--
--  Uso:   sudo mariadb casa < actualizar_v1.1.sql
--
--  Se puede correr más de una vez: si ya se aplicó, no cambia nada.
--  Las marcas de hoy se conservan (la comida marcada pasa a "Mañana").
-- ============================================================================

SET NAMES utf8mb4;

-- 1) Catálogo de tareas por perro.
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

-- 2) dog_checks pasa a apuntar a una tarea. Solo si todavía tiene el formato viejo (columna "type").
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

-- 3) Códigos para pasar la cuenta a otro dispositivo.
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
