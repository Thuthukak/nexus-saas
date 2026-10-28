<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Operator sign-in on the admin host. Generic failures; throttled per email+IP. Tenant users can never sign in here. */
final class OperatorLoginController
{
    private const GENERIC = 'These credentials do not match our records.';

    public function create(): Response|RedirectResponse
    {
        return Auth::guard('operator')->check() ? redirect('/tenants') : Inertia::render('ControlPlane/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:254'],
            'password' => ['required', 'string', 'max:72'],
        ]);
        $email = mb_strtolower(trim($data['email']));
        $key = 'operator-login|'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.'])->status(429);
        }

        $guard = Auth::guard('operator');
        if (! $guard->attempt(['email' => $email, 'password' => $data['password'], 'is_active' => true])) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => self::GENERIC]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $guard->user()->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended('/tenants');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('operator')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
