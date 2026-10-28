<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Verification\Scenario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Logs a scenario user in from the signed URL printed by `verify:login`.
 */
class VerifyLoginController extends Controller
{
    public function __invoke(Request $request, User $user): RedirectResponse
    {
        abort_if(Scenario::idFromEmail($user->email) === null, 404);

        Auth::login($user);
        $request->session()->regenerate();

        $path = $request->string('path')->toString();

        return redirect(str_starts_with($path, '/') && ! str_starts_with($path, '//') ? $path : '/');
    }
}
