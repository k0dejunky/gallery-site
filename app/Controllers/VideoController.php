<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Models\MediaProgress;

class VideoController extends ImageController
{
    /**
     * In-page video player — delegates to the shared media viewer.
     */
    public function show(int $id): void
    {
        $this->showMedia($id, true);
    }

    /**
     * Remember where the member stopped watching.
     *
     * The player's position used to live in localStorage only - per device,
     * invisible to the site. This is the server-side copy: the position
     * follows the member to another device, and the dashboard can show what
     * they were watching.
     */
    public function progress(): void
    {
        Auth::requireLogin();

        $photoId = (int) $this->request->post('photo_id', 0);
        $position = (float) $this->request->post('position', 0);
        $duration = $this->request->post('duration', null);
        $finished = (int) $this->request->post('finished', 0) === 1;

        if ($photoId <= 0) {
            $this->json(['error' => 'Which video?'], 400);
            return;
        }

        $userId = (int) Auth::user()['id'];

        if ($finished) {
            // A finished video is not "in progress" any more.
            MediaProgress::forget($userId, $photoId);
            $this->json(['ok' => true, 'finished' => true]);
            return;
        }

        MediaProgress::save($userId, $photoId, $position, $duration !== null ? (float) $duration : null);

        $this->json(['ok' => true, 'position' => $position]);
    }
}
