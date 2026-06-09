<?php
class Router
{
    private array $routes = [];
    private string $base = '';

    public function __construct(string $base = '')
    {
        $this->base = rtrim($base, '/');
    }

    public function get(string $path, callable|array $handler): void
    {
        $this->routes['GET'][$this->normalize($path)] = $handler;
    }
    public function post(string $path, callable|array $handler): void
    {
        $this->routes['POST'][$this->normalize($path)] = $handler;
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if ($scriptDir !== '' && $scriptDir !== '/' && strncmp($uri, $scriptDir, strlen($scriptDir)) === 0) {
            $uri = substr($uri, strlen($scriptDir)) ?: '/';
        }
        if ($this->base !== '' && strncmp($uri, $this->base, strlen($this->base)) === 0) {
            $uri = substr($uri, strlen($this->base)) ?: '/';
        }
        $path = $this->normalize($uri);

        // Match estático
        if (isset($this->routes[$method][$path])) {
            $this->invoke($this->routes[$method][$path], []);
            return;
        }
        // Match com parâmetros {id}
        foreach (($this->routes[$method] ?? []) as $route => $handler) {
            $pattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $route);
            if (preg_match('#^' . $pattern . '$#', $path, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $this->invoke($handler, $params);
                return;
            }
        }
        http_response_code(404);
        echo 'Página não encontrada';
    }

    private function invoke(callable|array $handler, array $params): void
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $controller = new $class();
            call_user_func_array([$controller, $method], $params);
        } else {
            call_user_func_array($handler, $params);
        }
    }

    private function normalize(string $path): string
    {
        return rtrim($path, '/') ?: '/';
    }
}
