<?php

namespace App\Controllers;

use App\Core\Controller;

/**
 * The 18+ entry gate. Guests confirm they are an adult once per session from
 * the overlay rendered by the shared layout; members are never shown it.
 */
class AgeGateController extends Controller
{
    public function verify(): void
    {
        $_SESSION['age_verified'] = ['at' => time()];

        $this->redirect(safe_return_to($this->request->post('return_to', '')) ?? '/');
    }
}