<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\PasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ForcedPasswordChangeController extends Controller
{
    public function edit(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->must_change_password) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/ForcePasswordChange');
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->must_change_password, 403);

        $validated = $request->validate([
            'password' => ['required', 'confirmed', ...PasswordPolicy::rules()],
        ], PasswordPolicy::messages());

        if (Hash::check($validated['password'], $request->user()->password)) {
            throw ValidationException::withMessages([
                'password' => 'A nova senha precisa ser diferente da senha provisoria.',
            ]);
        }

        $request->user()->forceFill([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
            'temporary_password_created_at' => null,
            'remember_token' => Str::random(60),
        ])->save();

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false))
            ->with('success', 'Senha alterada com sucesso.');
    }
}
