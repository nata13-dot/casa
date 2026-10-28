<?php
declare(strict_types=1);

namespace Casa;

/**
 * Catálogo de categorías de avisos. El frontend tiene su copia visual
 * (app/src/lib/categories.ts); las claves deben coincidir.
 */
final class Categories
{
    /** clave => [etiqueta, prioridad mínima] */
    public const ALL = [
        'general'       => ['General', 'normal'],
        'compra'        => ['Compras', 'normal'],
        'emergencia'    => ['Emergencia', 'urgente'],
        'salud'         => ['Salud y medicinas', 'normal'],
        'mascotas'      => ['Mascotas', 'normal'],
        'pago'          => ['Pagos y servicios', 'normal'],
        'tarea'         => ['Tareas del hogar', 'normal'],
        'mantenimiento' => ['Reparaciones', 'normal'],
        'visita'        => ['Visitas y paquetes', 'normal'],
        'evento'        => ['Eventos y reuniones', 'normal'],
        'recordatorio'  => ['Recordatorio', 'normal'],
    ];

    public const PRIORITIES = ['normal' => 0, 'importante' => 1, 'urgente' => 2];

    public static function label(string $key): string
    {
        return self::ALL[$key][0] ?? 'Aviso';
    }

    /** Devuelve la prioridad efectiva (una emergencia siempre es urgente). */
    public static function effectivePriority(string $category, string $priority): string
    {
        $min = self::ALL[$category][1];
        return self::PRIORITIES[$priority] >= self::PRIORITIES[$min] ? $priority : $min;
    }
}
