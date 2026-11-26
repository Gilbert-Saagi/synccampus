<?php
declare(strict_types=1);

namespace SyncCampus;

final class Router
{
    private array $routes = [];

    public function get(string $pattern, array|callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, array|callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function patch(string $pattern, array|callable $handler): void
    {
        $this->add('PATCH', $pattern, $handler);
    }

    private function add(string $method, string $pattern, array|callable $handler): void
    {
        // "/leader/concerns/{id}/assign" -> "#^/leader/concerns/(?<id>\d+)/assign$#"
        $regex = preg_replace('/\{(\w+)\}/', '(?<$1>\d+)', $pattern);
        $this->routes[] = [$method, "#^$regex$#", $handler];
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as [$method, $regex, $handler]) {
            if ($method !== $request->method || !preg_match($regex, $request->path, $matches)) {
                continue;
            }
            $request->params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            if (is_array($handler)) {
                [$class, $action] = $handler;
                $handler = [new $class(), $action];
            }
            $result = $handler($request);
            return $result instanceof Response ? $result : new Response($result);
        }
        throw new HttpError(404, 'Not Found');
    }
}
