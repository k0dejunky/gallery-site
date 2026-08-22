<?php

namespace App\Core;

/**
 * Matches an incoming request against the configured route table and invokes
 * the matching controller action, wiring up URL parameters and CSRF checks.
 */
class Router
{
    private array $routes;

    public function __construct(array $routes = [])
    {
        $this->routes = $routes;
    }

    /**
     * Dispatch one request: first verify CSRF on POSTs, then find the first
     * route whose verb and pattern match and call its controller action.
     * Falls through to a 404 when nothing matches.
     */
    public function dispatch(Request $request): void
    {
        // Biller postback endpoints are server-to-server calls with no
        // session and no CSRF token; they verify authenticity with their own
        // shared-secret digests instead.
        if ($request->isPost()
            && strpos($request->uri(), '/webhooks/') !== 0
            && !Csrf::verify($request->post('_token'))) {
            $this->error(419, 'CSRF token mismatch. Please go back and try again.');
            return;
        }

        foreach ($this->routes as [$method, $path, $handler]) {
            if ($method !== $request->method()) {
                continue;
            }

            $pattern = '#^' . $this->compile($path) . '/?$#';

            if (preg_match($pattern, $request->uri(), $matches)) {
                [$controller, $action] = explode('@', $handler);

                $controller = 'App\\Controllers\\' . $controller;
                $params     = array_filter(
                    $matches,
                    fn ($key) => is_string($key),
                    ARRAY_FILTER_USE_KEY
                );

                $instance = new $controller($request);
                $instance->$action(...array_values($params));
                return;
            }
        }

        $this->error(404, 'Page not found');
    }

    /**
     * Convert a route pattern like /galleries/{id} into a regex with named
     * capture groups so {id} arrives as a readable parameter.
     */
    private function compile(string $path): string
    {
        $pattern = preg_replace_callback(
            '#\{([a-zA-Z0-9_]+)\}#',
            fn ($m) => '(?P<' . $m[1] . '>[^/]+)',
            $path
        );

        return str_replace('/', '/', $pattern);
    }

    /**
     * Render the error page for a status code, falling back to plain text.
     */
    private function error(int $status, string $message): void
    {
        http_response_code($status);

        $view = __DIR__ . '/../../views/errors/' . ($status === 404 ? '404' : 'error') . '.php';

        if (is_file($view)) {
            require $view;
            return;
        }

        echo $status . ' ' . $message;
    }
}
