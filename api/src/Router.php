<?php
declare(strict_types=1);

namespace Casa;

final class Router
{
    /** @var array<int, array{string, string, callable, bool}> */
    private array $routes = [];

    /** Registra una ruta. `{id}` captura un entero. $public = no requiere token. */
    public function add(string $method, string $pattern, callable $handler, bool $public = false): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>\d+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler, $public];
    }

    public function dispatch(string $method, string $path): void
    {
        $path = '/' . trim($path, '/');
        $methodAllowed = false;

        foreach ($this->routes as [$routeMethod, $regex, $handler, $public]) {
            if (!preg_match($regex, $path, $m)) {
                continue;
            }
            if ($routeMethod !== $method) {
                $methodAllowed = true;
                continue;
            }
            $params = array_map('intval', array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY));
            $user = $public ? null : Auth::require();
            $handler($params, $user);
            return;
        }

        throw $methodAllowed
            ? new HttpError(405, 'method_not_allowed', 'Método no permitido.')
            : new HttpError(404, 'not_found', 'Ruta no encontrada.');
    }
}
