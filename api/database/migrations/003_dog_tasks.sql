-- Casa · tareas diarias por perro (comida 2 veces al día, pastillas de Mushu).
--
-- Antes: dog_checks tenía (dog_id, type ENUM('food','water')) → 1 comida y 1 agua por día.
-- Ahora: cada perro tiene su lista en dog_tasks y dog_checks apunta a una tarea.
--
-- Ejecutar UNA vez, después de 002:
--     sudo mariadb casa < database/migrations/003_dog_tasks.sql
-- Las marcas que ya existan se conservan (la comida marcada pasa a "Mañana").

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

-- 2) Tareas: todos comen mañana y noche y tienen agua; Mushu toma pastilla mañana y noche.
INSERT IGNORE INTO dog_tasks (dog_id, kind, label, sort_order)
SELECT id, 'food',  'Mañana', 1 FROM dogs
UNION ALL SELECT id, 'food',  'Noche', 2 FROM dogs
UNION ALL SELECT id, 'water', 'Hoy',   3 FROM dogs;

INSERT IGNORE INTO dog_tasks (dog_id, kind, label, sort_order)
SELECT id, 'medicine', 'Mañana', 4 FROM dogs WHERE name = 'Mushu'
UNION ALL SELECT id, 'medicine', 'Noche', 5 FROM dogs WHERE name = 'Mushu';

-- 3) dog_checks pasa a apuntar a una tarea.
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

-- Resultado
SELECT d.name AS perro,
       GROUP_CONCAT(CONCAT(t.kind, ' ', t.label) ORDER BY t.sort_order SEPARATOR ', ') AS tareas
  FROM dogs d JOIN dog_tasks t ON t.dog_id = d.id
 GROUP BY d.id ORDER BY d.sort_order;
