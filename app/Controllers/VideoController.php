<?php

namespace App\Controllers;

class VideoController extends ImageController
{
    /**
     * In-page video player — delegates to the shared media viewer.
     */
    public function show(int $id): void
    {
        $this->showMedia($id, true);
    }
}
