<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;

class HelpController extends Controller
{
    /**
     * The help/documentation page is only visible to admins.
     */
    public function __construct(Request $request)
    {
        parent::__construct($request);
        Auth::requireAdmin();
    }

    /**
     * Render the help page (site documentation for admins).
     */
    public function index(): void
    {
        $this->viewAdmin('help');
    }
}
