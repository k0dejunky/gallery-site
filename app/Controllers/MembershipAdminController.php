<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;

/**
 * Base for the membership admin area. Every controller in this area shares the
 * same guard: the operator must hold the 'membership' permission. Extending
 * this instead of duplicating the constructor keeps the guard in one place.
 */
abstract class MembershipAdminController extends Controller
{
    public function __construct(Request $request)
    {
        parent::__construct($request);
        Auth::requirePermission('membership');
    }
}