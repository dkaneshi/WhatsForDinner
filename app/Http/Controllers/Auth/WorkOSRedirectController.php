<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\BeginWorkOSAuthentication;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkOSRedirectController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, BeginWorkOSAuthentication $begin): RedirectResponse
    {
        return $begin->handle($request, BeginWorkOSAuthentication::INTENT_LOGIN);
    }
}
