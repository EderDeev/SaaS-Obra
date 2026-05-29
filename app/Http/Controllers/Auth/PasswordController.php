<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\PasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', ...PasswordPolicy::rules()],
        ], PasswordPolicy::messages());

        $request->user()->update([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
            'temporary_password_created_at' => null,
        ]);

        return back();
    }
}
