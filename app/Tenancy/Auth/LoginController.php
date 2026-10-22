<?php

declare(strict_types=1);

namespace App\Tenancy\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workspace login on a tenant host. The workspace guard's user provider is tenant-scoped (global
 * scope + RLS), so credentials can only ever match a user of THIS tenant. Failures are generic:
 * unknown email, wrong password and deactivated account are indistinguishable.
 */
final class LoginController
{
    private const GENERIC = 'These credentials do not match our records.';

    public function create(Request $request): Response|RedirectResponse
    {
        if (Auth::guard('workspace')->check()) {
            return redirect('/dashboard');
        }

        return Inertia::render('Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:254'],
            'password' => ['required', 'string', 'max:72'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $email = mb_strtolower(trim($data['email']));
        $tenant = $request->attributes->get('tenant');
        $key = 'login|'.$tenant->id.'|'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ])->status(429);
        }

        $guard = Auth::guard('workspace');
        if (! $guard->attempt(['email' => $email, 'password' => $data['password'], 'is_active' => true], (bool) ($data['remember'] ?? false))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => self::GENERIC]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $guard->user()->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended('/dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('workspace')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
