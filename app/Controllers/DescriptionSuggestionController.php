<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Models\DescriptionSuggestion;
use App\Models\Gallery;

/**
 * Admin surface for AI gallery descriptions: the /admin/description-suggestions
 * review page plus the accept/dismiss/backfill/reanalyze endpoints it and the
 * gallery manage page share. Proposals are only ever applied through accept()
 * - an explicit admin action, audited - never automatically.
 */
class DescriptionSuggestionController extends Controller
{
    public function __construct(Request $request)
    {
        parent::__construct($request);
        Auth::requirePermission('galleries');
    }

    /**
     * Admin: review page - galleries with pending description proposals,
     * driver health and the "no description yet" backfill control.
     */
    public function index(): void
    {
        $page    = (int) $this->request->query('page', '1');
        $listing = DescriptionSuggestion::pendingPage($page);

        $this->viewAdmin('description_suggestions', [
            'listing' => $listing,
            'stats'   => DescriptionSuggestion::stats(),
            'driver'  => DescriptionSuggestion::driver(),
        ]);
    }

    /** Accept one proposal, using the admin's (possibly edited) textarea copy. */
    public function accept(): void
    {
        $id   = (int) $this->request->input('id');
        $text = $this->request->input('text');

        $ok = DescriptionSuggestion::accept(
            $id,
            (int) Auth::user()['id'],
            is_string($text) ? $text : null
        );

        $this->back($ok ? 'Description applied.' : 'Nothing to apply (the proposal was already decided).');
    }

    /** Dismiss one proposal without changing the gallery. */
    public function dismiss(): void
    {
        $id = (int) $this->request->input('id');
        DescriptionSuggestion::dismiss($id, (int) Auth::user()['id']);

        $this->back('Suggestion dismissed.');
    }

    /**
     * Queue every gallery without a description (bounded batch). The worker
     * picks them up on its next tick.
     */
    public function backfill(): void
    {
        $queued = DescriptionSuggestion::enqueueMissing(200);

        $this->back(
            $queued > 0
                ? "Queued $queued gallery/ies without a description for analysis."
                : 'Nothing to queue (driver off, or every gallery already has a description or a job).'
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
        $job = DescriptionSuggestion::jobFor($galleryId);
        if ($job !== null && $job['status'] === 'error' && (int) $job['attempts'] >= 3) {
            Database::run('DELETE FROM gallery_description_jobs WHERE gallery_id = ?', [$galleryId]);
        }

        $queued = DescriptionSuggestion::enqueue($galleryId);

        $this->back($queued ? 'Queued for analysis.' : 'Already queued or analysis is disabled.');
    }

    /** Redirect back to the calling page with a flash. */
    private function back(string $message): void
    {
        $this->flash('success', $message);
        $ref = $this->request->header('HTTP_REFERER') ?: '/admin/description-suggestions';
        $this->redirect($ref);
    }
}
