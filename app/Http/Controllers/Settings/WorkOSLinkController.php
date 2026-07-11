<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Auth\BeginWorkOSAuthentication;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkOSLinkController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, BeginWorkOSAuthentication $begin): RedirectResponse
    {
        return $begin->handle($request, BeginWorkOSAuthentication::INTENT_LINK);
    }
}
