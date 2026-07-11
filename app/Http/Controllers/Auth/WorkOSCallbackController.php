<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\CompleteWorkOSAuthentication;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkOSCallbackController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, CompleteWorkOSAuthentication $complete): RedirectResponse
    {
        return $complete->handle($request);
    }
}
