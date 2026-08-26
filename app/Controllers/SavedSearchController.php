<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Category;
use App\Models\SavedSearch;

class SavedSearchController extends Controller
{
    private function member(): int
    {
        Auth::requireSubscription();
        return (int) Auth::user()['id'];
    }

    public function index(): void
    {
        $siteEditorPreview = $this->request->query('se', '') === 'user';
        if (!$siteEditorPreview) {
            $this->member();
        }
        $this->redirect('/favorites' . ($siteEditorPreview ? '?se=user' : ''));
    }

    public function store(): void
    {
        $userId = $this->member();
        $input = $this->request->post();
        $filters = SavedSearch::filters($input);

        if (!is_string($input['q'] ?? null) || $filters['q'] === '' || mb_strlen(trim($input['q'])) > 255) {
            $this->flash('error', 'Enter a search of 255 characters or fewer before saving it.');
            $this->redirect('/galleries');
        }
        if (($input['type'] ?? '') !== '' && !in_array($input['type'], ['images', 'videos'], true)) {
            $this->flash('error', 'Invalid gallery type filter.');
            $this->redirect('/galleries');
        }
        if (($input['sort'] ?? '') !== '' && !in_array($input['sort'], ['newest', 'views', 'title'], true)) {
            $this->flash('error', 'Invalid sort filter.');
            $this->redirect('/galleries');
        }
        if ($filters['category'] > 0 && Category::find($filters['category']) === null) {
            $this->flash('error', 'That category is no longer available.');
            $this->redirect('/galleries');
        }

        SavedSearch::create($userId, $filters);
        $this->flash('success', 'Search saved to your favorites.');
        $this->redirect($this->safeReturnTo());
    }

    public function destroy(int $id): void
    {
        SavedSearch::deleteForUser($id, $this->member());
        $this->flash('success', 'Saved search removed.');
        $this->redirect('/favorites');
    }

    private function safeReturnTo(): string
    {
        $returnTo = (string) $this->request->post('return_to', '/galleries');
        if (!preg_match('#^/(?!/)[^\r\n]*$#', $returnTo) || parse_url($returnTo, PHP_URL_HOST) !== null) {
            return '/galleries';
        }

        $base = rtrim((string) config('app.base_path'), '/');
        if ($base !== '' && strpos($returnTo, $base . '/') === 0) {
            $returnTo = substr($returnTo, strlen($base));
        }

        return $returnTo === '' ? '/galleries' : $returnTo;
    }
}
