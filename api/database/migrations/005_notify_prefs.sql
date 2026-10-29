-- Casa v1.2 · tonos de notificación por persona y recordatorios de los perritos.
-- Ejecutar después de 004 (o simplemente correr database/actualizar.sql, que ya lo incluye):
--     sudo mariadb casa < database/migrations/005_notify_prefs.sql

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS notify_prefs VARCHAR(255) NULL
      COMMENT 'JSON: {tones:{avisos,urgentes,perritos}, dog_reminders}; NULL = valores por defecto'
      AFTER fcm_token;
