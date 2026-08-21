<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Stats;

class TrendsController extends Controller
{
    /**
     * A missed search term must be searched more than this many times
     * (lifetime) before it is offered for promotion to a category.
     */
    private const PROMOTION_THRESHOLD = 5;

    /**
     * Trending pages are admin-only.
     */
    public function __construct(Request $request)
    {
        parent::__construct($request);
        Auth::requirePermission('trends');
    }

    /**
     * Admin: trending categories and missed searches over the selected period.
     * The pending approvals list is period-independent: it always shows every
     * missed search term whose lifetime count clears the promotion threshold,
     * so nothing needing approval is hidden by the current period filter.
     */
    public function index(): void
    {
        $period = Stats::normalizePeriod((string) $this->request->query('period', 'weekly'));

        $lifetimeCounts = Stats::missedSearchLifetimeCounts();

        $searchTrends = array_map(
            static function (array $row) use ($lifetimeCounts): array {
                $row['lifetime_count'] = $lifetimeCounts[$row['term_key']] ?? (int) $row['cur'];

                return $row;
            },
            Stats::searchTrends($period)
        );

        $pending = array_flip(array_map(
            static fn (array $row): string => $row['term_key'],
            Stats::pendingCategoryApprovals(self::PROMOTION_THRESHOLD)
        ));

        $searchTrends = array_values(array_filter(
            $searchTrends,
            static fn (array $row): bool => !isset($pending[$row['term_key']])
        ));

        $this->viewAdmin('trends', [
            'categoryTrends' => Stats::categoryTrends($period),
            'searchTrends'   => $searchTrends,
            'pendingApprovals' => Stats::pendingCategoryApprovals(self::PROMOTION_THRESHOLD),
            'periods'        => Stats::periods(),
            'currentPeriod'  => $period,
            'promotionThreshold' => self::PROMOTION_THRESHOLD,
        ]);
    }

    /**
     * Admin: turn a frequently missed search term into a real category after
     * the admin approves it. Re-checks the lifetime threshold and rejects
     * terms that already exist as a category (case-insensitive). On success
     * the new category is logged in the audit trail.
     */
    public function approvePromotion(): void
    {
        $term = trim((string) $this->request->post('term', ''));

        if ($term === '') {
            $this->flash('error', 'No search term provided.');
            $this->redirect('/admin/trends');
        }

        $lifetimeCounts = Stats::missedSearchLifetimeCounts();
        $count          = $lifetimeCounts[mb_strtolower($term)] ?? 0;

        if ($count <= self::PROMOTION_THRESHOLD) {
            $this->flash('error', 'This term has not been searched enough to become a category.');
            $this->redirect('/admin/trends');
        }

        $existing = Category::findByName($term);

        if ($existing !== null) {
            $this->flash('error', 'A category named "' . $existing['name'] . '" already exists.');
            $this->redirect('/admin/trends');
        }

        $categoryId = Category::create($term);

        AuditLog::record(
            (int) Auth::user()['id'],
            'create',
            'category',
            $categoryId,
            'Added from missed search "' . $term . '" (searched ' . $count . ' times)',
            null,
            ['name' => $term]
        );

        $this->flash('success', 'Category "' . $term . '" created from a missed search.');
        $this->redirect('/admin/trends');
    }
}
