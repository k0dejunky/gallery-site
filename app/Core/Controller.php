<?php

namespace App\Core;

use App\Models\FavoriteCategory;

/**
 * Base controller. Holds the shared request instance and the rendering
 * helpers used by every controller action.
 */
class Controller
{
    protected Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Render a user-facing view inside the main layout. View data is
     * extracted so templates and the layout both see the variables. For
     * logged-in users the layout also receives the favourite-category
     * sidebar, so every user page shares the same left navigation (a
     * controller may override it by passing its own navCategories).
     */
    protected function view(string $template, array $data = []): void
    {
        $data['sidebarNav'] = $data['sidebarNav'] ?? Auth::check();

        if ($data['sidebarNav'] && !isset($data['navCategories'])) {
            $data['navCategories'] = $this->navCategories();
        }

        extract($data);

        $content = __DIR__ . '/../../views/' . $template . '.php';

        require __DIR__ . '/../../views/layout.php';
    }

    /**
     * The left-nav list: every one of the logged-in user's favourite
     * categories, whether or not they contain galleries yet, in the order
     * they were added. The layout moves the currently displayed category to
     * the top of the list.
     */
    private function navCategories(): array
    {
        $user = Auth::user();

        if ($user === null) {
            return [];
        }

        if (!Auth::hasActiveSubscription()) {
            return [];
        }

        return FavoriteCategory::forUser((int) $user['id']);
    }

    /**
     * Render a view inside the admin layout (admin panel pages).
     */
    protected function viewAdmin(string $template, array $data = []): void
    {
        extract($data);

        $content = __DIR__ . '/../../views/admin/' . $template . '.php';

        require __DIR__ . '/../../views/admin/layout.php';
    }

    /**
     * Render a standalone view with no shared layout (e.g. the video player).
     */
    protected function viewStandalone(string $template, array $data = []): void
    {
        extract($data);

        require __DIR__ . '/../../views/' . $template . '.php';
    }

    /**
     * Send a redirect to another app route. Exits immediately since a
     * redirect response has no body.
     */
    protected function redirect(string $path): void
    {
        // Absolute URLs (e.g. hosted biller checkouts) are passed through
        // untouched; everything else is prefixed with the app's base path.
        if (preg_match('#^https?://#i', $path)) {
            header('Location: ' . $path);
            exit;
        }

        header('Location: ' . url($path));
        exit;
    }

    /**
     * Queue a one-time flash message rendered on the next page load.
     */
    protected function flash(string $type, string $message): void
    {
        Flash::set($type, $message);
    }

    /**
     * Respond with a 404 page. Used when a route matched but the record it
     * targets does not exist (or is not visible to the caller).
     */
    protected function notFound(): void
    {
        http_response_code(404);

        require __DIR__ . '/../../views/errors/404.php';
    }
}
