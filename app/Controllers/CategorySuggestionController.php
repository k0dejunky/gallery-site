<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Models\CategorySuggestion;
use App\Models\Gallery;

/**
 * Admin surface for AI category suggestions: the /admin/category-suggestions
 * review page plus the accept/dismiss endpoints it and the gallery manage
 * page share. Proposals are only ever applied through accept() - an explicit
 * admin action, audited - never automatically.
 */
class CategorySuggestionController extends Controller
{
    public function __construct(Request $request)
    {
        parent::__construct($request);
        Auth::requirePermission('categories');
    }

    /**
     * Admin: review page - galleries with pending proposals, driver health
     * (queue depth + errors) and the uncategorized backfill control.
     */
    public function index(): void
    {
        $page    = (int) $this->request->query('page', '1');
        $listing = CategorySuggestion::pendingPage($page);

        $this->viewAdmin('category_suggestions', [
            'listing' => $listing,
            'stats'   => CategorySuggestion::stats(),
            'driver'  => CategorySuggestion::driver(),
        ]);
    }

    /** Accept one proposal: merges the category into the gallery. */
    public function accept(): void
    {
        $id = (int) $this->request->input('id');
        CategorySuggestion::accept($id, (int) Auth::user()['id']);

        $this->back('Suggestion accepted.');
    }

    /** Dismiss one proposal without changing the gallery. */
    public function dismiss(): void
    {
        $id = (int) $this->request->input('id');
        CategorySuggestion::dismiss($id, (int) Auth::user()['id']);

        $this->back('Suggestion dismissed.');
    }

    /** Accept every pending proposal for one gallery in a single merge. */
    public function acceptAll(): void
    {
        $galleryId = (int) $this->request->input('gallery_id');
        $count     = CategorySuggestion::acceptAll($galleryId, (int) Auth::user()['id']);

        $this->back($count > 0 ? "Accepted $count suggestion(s)." : 'Nothing pending.');
    }

    /**
     * Queue every gallery that has no categories yet (bounded batch).
     * The worker picks them up on its next tick.
     */
    public function backfill(): void
    {
        $queued = CategorySuggestion::enqueueUncategorized(200);

        $this->back(
            $queued > 0
                ? "Queued $queued uncategorized galler" . ($queued === 1 ? 'y' : 'ies') . ' for analysis.'
                : 'Nothing to queue (driver off, or everything already has categories/jobs).'
        );
    }

    /** Re-queue one gallery (its job row must currently be done or error). */
    public function reanalyze(): void
    {
        $galleryId = (int) $this->request->input('gallery_id');

        if (Gallery::find($galleryId) === null) {
            $this->back('Gallery not found.');
            return;
        }

        // enqueue() re-queues a finished/failed job itself; only an
        // exhausted failure (attempts at the cap) needs its row dropped first.
        $job = CategorySuggestion::jobFor($galleryId);
        if ($job !== null && $job['status'] === 'error' && (int) $job['attempts'] >= 3) {
            \App\Core\Database::run('DELETE FROM gallery_category_jobs WHERE gallery_id = ?', [$galleryId]);
        }

        $queued = CategorySuggestion::enqueue($galleryId);

        $this->back($queued ? 'Queued for analysis.' : 'Already queued or analysis is disabled.');
    }

    /** Redirect back to the calling page with a flash. */
    private function back(string $message): void
    {
        $this->flash('success', $message);
        $ref = $this->request->header('HTTP_REFERER') ?: '/admin/category-suggestions';
        $this->redirect($ref);
    }
}
