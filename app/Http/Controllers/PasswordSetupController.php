<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class PasswordSetupController
{
    public function show(Request $request, User $user, string $hash): View
    {
        $this->ensureValid($user, $hash);

        return view('auth.setup', ['user' => $user, 'action' => $request->fullUrl()]);
    }

    public function store(Request $request, User $user, string $hash): RedirectResponse
    {
        $this->ensureValid($user, $hash);

        $request->validate([
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        $user->forceFill(['password' => $request->string('password')->value()])->save();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/');
    }

    protected function ensureValid(User $user, string $hash): void
    {
        abort_unless(hash_equals(sha1($user->getAuthPassword()), $hash), 403, 'Deze link is al gebruikt of verlopen.');
    }
}
