<?php
declare(strict_types=1);

/**
 * Recordatorios de comida (y pastillas) de los perritos. Lo corre el servidor cada 5 minutos
 * (deploy/casa-recordatorios.timer):
 *
 *   - Mañana: desde las 10:00, mientras falte algo de "Mañana" por marcar en la app.
 *   - Noche:  desde las 21:30, mientras falte algo de "Noche".
 *
 * En cuanto alguien lo marca en la app, deja de sonar. Cada recordatorio reemplaza al
 * anterior en el celular (no se amontonan). Horas configurables en .env:
 *   RECORDATORIO_MANANA=10:00   RECORDATORIO_NOCHE=21:30
 *
 * Probar a mano (envía aunque no sea la hora):
 *   sudo -u casa php bin/recordatorios.php --probar
 */

namespace Casa;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../src/bootstrap.php';

$force = in_array('--probar', $argv, true);
$now = date('H:i');
$morning = env('RECORDATORIO_MANANA', '10:00');
$night = env('RECORDATORIO_NOCHE', '21:30');

// Las horas "HH:MM" se comparan como texto.
if ($now >= $night) {
    $slot = 'Noche';
} elseif ($now >= $morning || $force) {
    $slot = 'Mañana';
} else {
    exit(0);
}

$pending = Database::all(
    "SELECT d.name, t.kind
       FROM dog_tasks t
       JOIN dogs d ON d.id = t.dog_id
  LEFT JOIN dog_checks c ON c.task_id = t.id AND c.check_date = CURDATE()
      WHERE t.active = 1 AND t.kind IN ('food', 'medicine') AND t.label = ? AND c.task_id IS NULL
   ORDER BY d.sort_order, t.sort_order",
    [$slot],
);
if ($pending === []) {
    exit(0); // ya comieron todos
}

$byKind = [];
foreach ($pending as $row) {
    $byKind[$row['kind']][] = $row['name'];
}
$names = static fn(array $list): string => count($list) > 1
    ? implode(', ', array_slice($list, 0, -1)) . ' y ' . end($list)
    : $list[0];
$missing = [];
if (isset($byKind['food'])) {
    $missing[] = 'comida de ' . $names($byKind['food']);
}
if (isset($byKind['medicine'])) {
    $missing[] = 'pastilla de ' . $names($byKind['medicine']);
}

$recipients = array_values(array_filter(
    Database::all('SELECT id, fcm_token, notify_prefs FROM users WHERE fcm_token IS NOT NULL'),
    static fn($u) => Fcm::prefs($u['notify_prefs'])['dog_reminders'],
));

$body = 'Falta: ' . implode(' · ', $missing) . '. Márcalo en la app cuando les des.';
$results = Fcm::sendToMany(
    $recipients,
    $slot === 'Noche' ? '🌙 ¿Ya cenaron los perritos?' : '🐶 ¿Ya desayunaron los perritos?',
    $body,
    ['type' => 'dog_reminder', 'slot' => $slot],
    'perritos',
    'perritos-' . ($slot === 'Noche' ? 'noche' : 'manana'),
);

$ok = count(array_filter($results, static fn($r) => $r === 'ok'));
printf("[%s] %s — %s Enviado a %d de %d%s\n", date('Y-m-d H:i'), $slot, $body, $ok,
    count($recipients), Fcm::enabled() ? '' : ' (Firebase sin configurar)');
