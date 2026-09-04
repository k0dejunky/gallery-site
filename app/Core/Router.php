<?php

namespace App\Core;

/**
 * Matches an incoming request against the configured route table and invokes
 * the matching controller action, wiring up URL parameters and CSRF checks.
 */
class Router
{
    private array $routes;
    private Request $request;

    public function __construct(array $routes = [])
    {
        $this->routes = $routes;
    }

    /**
     * Dispatch one request: first verify CSRF on POSTs, then find the first
     * route whose verb and pattern match and call its controller action.
     * Falls through to a 404 when nothing matches, or 405 when the path
     * exists but not for the requested HTTP method.
     */
    public function dispatch(Request $request): void
    {
        $this->request = $request;

        // Biller postback endpoints are server-to-server calls with no
        // session and no CSRF token; they verify authenticity with their own
        // shared-secret digests instead.
        if ($request->isPost()
            && strpos($request->uri(), '/webhooks/') !== 0
            && !Csrf::verify($request->post('_token'))) {
            $this->error(419, 'CSRF token mismatch. Please go back and try again.');
            return;
        }

        // Dispatch in two passes. Literal paths (no {param} placeholders)
        // shadow wildcard routes, so "POST /admin/users/bulk" is NOT reached
        // by "GET /admin/users/{id}" (which would call show("bulk") and 500).
        // A path that exists only with the wrong verb is answered 405 instead
        // of falling through to a wildcard or a bare 404.
        $matchedMethod   = null;
        $matchedWildcard = null;

        foreach ($this->routes as $route) {
            $method  = $route[0];
            $path    = $route[1];
            $handler = $route[2];
            $perm    = $route[3] ?? null;

            $pattern = '#^' . $this->compile($path) . '/?$#';

            if (!preg_match($pattern, $request->uri(), $matches)) {
                continue;
            }

            $isLiteral = strpos($path, '{') === false;

            if ($method === $request->method()) {
                if ($perm !== null && !Auth::can($perm)) {
                    $this->denyAccess();
                    return;
                }

                if ($isLiteral) {
                    $this->invoke($handler, $matches);
                    return;
                }

                // Remember the first wildcard match in case no literal route
                // exists for this path.
                if ($matchedWildcard === null) {
                    $matchedWildcard = [$handler, $matches];
                }
            } elseif ($isLiteral && $matchedMethod === null) {
                // The exact path exists, but not for this HTTP verb.
                $matchedMethod = $method;
            }
        }

        // No literal route matched the verb. A literal path with the wrong
        // method shadows any wildcard (e.g. GET /admin/users/bulk must be 405,
        // never dispatched to /admin/users/{id}). Only when there is no
        // literal route for the path do we fall back to a matching wildcard.
        if ($matchedMethod !== null) {
            $this->error(405, 'Method Not Allowed');
            return;
        }

        if ($matchedWildcard !== null) {
            [$handler, $matches] = $matchedWildcard;
            $this->invoke($handler, $matches);
            return;
        }

        $this->error(404, 'Page not found');
    }

    /**
     * Instantiate the controller for a matched route and call its action,
     * casting numeric URL captures to int for strict typed signatures.
     */
    private function invoke(string $handler, array $matches): void
    {
        [$controller, $action] = explode('@', $handler);

        $controller = 'App\\Controllers\\' . $controller;
        $params     = array_filter(
            $matches,
            fn ($key) => is_string($key),
            ARRAY_FILTER_USE_KEY
        );

        // PHP 8.1+ enforces strict types on scalar parameters.
        // Route captures arrive as strings; cast numeric ones to
        // int so controller signatures (int $id) don't throw.
        $params = array_map(
            fn ($v) => (ctype_digit($v) && $v !== '' && (int) $v >= 0) ? (int) $v : $v,
            $params
        );

        $instance = new $controller($this->request);
        $instance->$action(...array_values($params));
    }

    /**
     * Enforce an admin-permission gate at the router layer. Authenticated
     * admins without the permission get a flash + redirect; everyone else is
     * sent to the admin login.
     */
    private function denyAccess(): void
    {
        if (Auth::check() && Auth::isAdmin()) {
            Flash::set('error', 'You do not have permission to do that.');
            header('Location: ' . url('/admin'));
            exit;
        }

        header('Location: ' . url('/admin'));
        exit;
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
