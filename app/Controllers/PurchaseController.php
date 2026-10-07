<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Gallery;
use App\Models\Notification;
use App\Models\Purchase;
use App\Models\UnlockCode;

/**
 * One-off commerce: gallery unlocks (redeem a creator-sold code) and tips.
 * There is no live payment gateway on this build, so the merchant settles the
 * purchase off-site and the code/purchase rows reconcile on the admin page.
 */
class PurchaseController extends Controller
{
    public function unlock(int $id): void
    {
        Auth::requireLogin();
        $user = Auth::user();

        $gallery = Gallery::find($id);
        if ($gallery === null) {
            $this->notFound();
            return;
        }

        $code = strtoupper(trim((string) $this->request->post('unlock_code', '')));

        if ($code === '') {
            $this->flash('error', 'Please enter the unlock code you were given.');
            $this->redirect('/galleries/' . $id);
            return;
        }

        if (Purchase::userUnlocked((int) $user['id'], $id)) {
            $this->flash('success', 'This gallery is already unlocked on your account.');
            $this->redirect('/galleries/' . $id);
            return;
        }

        $valid = UnlockCode::findValid($code, $id);
        if ($valid === null) {
            $this->flash('error', 'That unlock code is invalid, has expired, or is for a different gallery.');
            $this->redirect('/galleries/' . $id);
            return;
        }

        if (!UnlockCode::redeem((int) $valid['id'])) {
            $this->flash('error', 'That unlock code has already been used.');
            $this->redirect('/galleries/' . $id);
            return;
        }

        $amount = $valid['amount'] ?? null;
        if ($amount === null) {
            $amount = $gallery['ppv_price'] ?? 0;
        }

        Purchase::create(
            (int) $user['id'],
            Purchase::TYPE_GALLERY,
            $id,
            (float) $amount,
            'code',
            (string) $valid['id'],
            'Unlocked "' . $gallery['title'] . '" with code ' . $valid['code']
        );

        Notification::add(
            (int) $user['id'],
            'purchase',
            'Gallery unlocked',
            'You unlocked "' . $gallery['title'] . '". Enjoy!',
            '/galleries/' . $id
        );

        $this->flash('success', 'Gallery unlocked — enjoy full access.');
        $this->redirect('/galleries/' . $id);
    }

    public function tip(): void
    {
        Auth::requireLogin();
        $user = Auth::user();

        $amount = (float) str_replace(',', '.', trim((string) $this->request->post('amount', '0')));
        $note   = trim((string) $this->request->post('note', ''));
        $back   = $this->request->header('HTTP_REFERER') ?: '/account';

        if ($amount <= 0 || $amount > 10000) {
            $this->flash('error', 'Please enter a tip amount between 0.01 and 10,000.');
            $this->redirect($back);
            return;
        }

        if ($note === '') {
            $this->flash('error', 'Please add a short note with your tip.');
            $this->redirect($back);
            return;
        }

        Purchase::create(
            (int) $user['id'],
            Purchase::TYPE_TIP,
            0,
            round($amount, 2),
            'offline',
            null,
            $note,
            Purchase::STATUS_PAID
        );

        $this->flash('success', 'Thank you! Your tip has been recorded and will be acknowledged.');
        $this->redirect($back);
    }
}