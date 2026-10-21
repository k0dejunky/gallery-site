<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Comment;
use App\Models\Gallery;
use App\Models\Notification;
use App\Models\Purchase;
use App\Models\SaleCode;
use App\Models\Tag;
use App\Models\UnlockCode;
use App\Models\WallPost;

/**
 * Admin "Engagement" surface: compose wall posts, moderate comments, issue
 * and manage unlock codes + gift subscriptions, review the one-off purchase
 * ledger and curate tags.
 */
class ModerationController extends Controller
{
    public function index(): void
    {
        Auth::requirePermission('dashboard');

        $this->viewAdmin('engagement', [
            'posts'           => WallPost::allPublished(30),
            'comments'        => Comment::recent(40),
            'codes'           => UnlockCode::all(),
            'galleries'       => Gallery::all(['include_secret' => Auth::isSuperAdmin()]),
            'recentPurchases' => Purchase::recent(20),
            'tagCounts'       => Tag::withCounts(),
        ]);
    }

    public function wallStore(): void
    {
        Auth::requirePermission('membership');

        $body   = trim((string) $this->request->post('body', ''));
        $pinned = $this->request->post('pinned') === '1';

        if ($body === '') {
            $this->flash('error', 'Please write something before posting to the wall.');
            $this->redirect('/admin/engagement');
            return;
        }

        WallPost::create((int) Auth::user()['id'], $body, $pinned);

        // Fan out a notification to every active member so the wall actually
        // gets read; wall posts are short and infrequent.
        Notification::broadcastToMembers(
            'wall',
            'New wall post',
            mb_substr($body, 0, 90),
            '/wall'
        );

        $this->flash('success', 'Wall post published.');
        $this->redirect('/admin/engagement');
    }

    public function wallDelete(int $id): void
    {
        Auth::requirePermission('membership');
        WallPost::delete($id);

        $this->flash('success', 'Wall post removed.');
        $this->redirect('/admin/engagement');
    }

    public function commentDelete(int $id): void
    {
        Auth::requirePermission('membership');
        Comment::delete($id);

        $this->flash('success', 'Comment removed.');
        $this->redirect('/admin/engagement');
    }

    public function codeStore(): void
    {
        Auth::requirePermission('monetisation');

        $galleryId = $this->request->post('gallery_id', '');
        $galleryId = $galleryId !== '' && is_numeric($galleryId) ? (int) $galleryId : null;
        $amount    = $this->request->input('amount', '');
        $amount    = $amount === '' ? null : (float) str_replace(',', '.', $amount);
        $maxUses   = (int) $this->request->post('max_uses', '1');
        $maxUses   = $maxUses < 1 ? null : $maxUses;
        $note      = $this->request->input('note');

        if ($galleryId !== null && Gallery::find($galleryId) === null) {
            $this->flash('error', 'That gallery does not exist.');
            $this->redirect('/admin/engagement');
            return;
        }

        $code = UnlockCode::generate($galleryId, $amount, $maxUses, $note !== '' ? $note : null);

        $this->flash('success', 'Unlock code ' . $code['code'] . ' created.');
        $this->redirect('/admin/engagement');
    }

    public function codeToggle(int $id): void
    {
        Auth::requirePermission('monetisation');
        UnlockCode::toggle($id);

        $this->flash('success', 'Unlock code updated.');
        $this->redirect('/admin/engagement');
    }

    public function codeDelete(int $id): void
    {
        Auth::requirePermission('monetisation');
        UnlockCode::delete($id);

        $this->flash('success', 'Unlock code deleted.');
        $this->redirect('/admin/engagement');
    }

    /**
     * A gift subscription is a 100%-off sale code redeemable at signup,
     * granting the chosen tier for its max_uses number of members.
     */
    public function giftStore(): void
    {
        Auth::requirePermission('monetisation');

        $targetLevel = max(1, min(3, (int) $this->request->post('target_level', '1')));
        $maxUses     = (int) $this->request->post('max_uses', '1');
        $maxUses     = $maxUses < 1 ? 1 : $maxUses;

        $code = 'GIFT-' . strtoupper(bin2hex(random_bytes(5)));
        SaleCode::create(null, $code, $maxUses, 'percent', 100, null, $targetLevel, 'Gift subscription (tier ' . $targetLevel . ')');

        $this->flash('success', 'Gift code ' . $code . ' created (tier ' . $targetLevel . ', ' . $maxUses . ' redemption' . ($maxUses > 1 ? 's' : '') . ').');
        $this->redirect('/admin/engagement');
    }

    public function purchases(): void
    {
        Auth::requirePermission('dashboard');

        $this->viewAdmin('engagement_purchases', [
            'purchases' => Purchase::recent(500),
            'tipsTotal' => Purchase::totalFor(Purchase::TYPE_TIP),
            'unlockTotal' => Purchase::totalFor(Purchase::TYPE_GALLERY),
        ]);
    }

    public function purchaseStatus(int $id): void
    {
        Auth::requirePermission('monetisation');

        $status = (string) $this->request->post('status', '');
        if (!in_array($status, [Purchase::STATUS_PENDING, Purchase::STATUS_PAID, Purchase::STATUS_GRANTED, Purchase::STATUS_REFUNDED], true)) {
            $this->flash('error', 'Invalid purchase status.');
            $this->redirect('/admin/engagement/purchases');
            return;
        }

        // Moving a purchase to "refunded" must refund the MONEY. It used to
        // only flip the local status, so the member kept their card charge
        // while the unlock was revoked.
        if ($status === Purchase::STATUS_REFUNDED) {
            $gateway = Purchase::refundAtGateway($id);
            if ($gateway === false) {
                $this->flash('error', 'The purchase was marked refunded, but the payment processor did not confirm it. Check the processor and retry.');
                $this->redirect('/admin/engagement/purchases');
                return;
            }
            if ($gateway === null) {
                $this->flash('success', 'Purchase marked refunded (no money had moved at a processor).');
            } else {
                $this->flash('success', 'Purchase refunded at the processor and marked refunded.');
            }
            // The local row has to be marked too, or the unlock stays open
            // even though the money went back.
            Purchase::setStatus($id, Purchase::STATUS_REFUNDED, (int) Auth::user()['id']);
            $this->redirect('/admin/engagement/purchases');
            return;
        }

        Purchase::setStatus($id, $status, (int) Auth::user()['id']);

        $this->flash('success', 'Purchase updated.');
        $this->redirect('/admin/engagement/purchases');
    }

    public function tags(): void
    {
        Auth::requirePermission('dashboard');

        $this->viewAdmin('engagement_tags', [
            'tags' => Tag::withCounts(),
        ]);
    }

    public function tagStore(): void
    {
        Auth::requirePermission('dashboard');

        $name = trim((string) $this->request->post('name', ''));
        if ($name === '') {
            $this->flash('error', 'Please enter a tag name.');
            $this->redirect('/admin/engagement/tags');
            return;
        }

        Tag::findOrCreate($name);

        $this->flash('success', 'Tag "' . $name . '" added.');
        $this->redirect('/admin/engagement/tags');
    }

    public function tagDelete(int $id): void
    {
        Auth::requirePermission('dashboard');

        $rows = \App\Core\Database::run('SELECT name FROM tags WHERE id = ?', [$id])->fetch();
        if ($rows !== false) {
            $name = $rows['name'];
            \App\Core\Database::run('DELETE FROM tag_gallery WHERE tag_id = ?', [$id]);
            \App\Core\Database::run('DELETE FROM tags WHERE id = ?', [$id]);
            $this->flash('success', 'Tag "' . $name . '" deleted.');
        } else {
            $this->flash('error', 'Tag not found.');
        }

        $this->redirect('/admin/engagement/tags');
    }
}