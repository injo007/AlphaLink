<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $identifierField = $request->filled('username') ? 'username' : 'email';
        $credentials = $request->validate([
            $identifierField => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $identifier = Str::lower(trim($credentials[$identifierField]));
        $credentialField = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $attemptCredentials = [
            $credentialField => $identifier,
            'password' => $credentials['password'],
        ];
        $key = 'login:'.hash('sha256', $identifier.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            return back()
                ->withErrors([$identifierField => "Too many sign-in attempts. Try again in {$seconds} seconds."])
                ->onlyInput($identifierField);
        }

        if (! Auth::attempt($attemptCredentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);

            return back()
                ->withErrors([$identifierField => 'The username or password was not recognized.'])
                ->onlyInput($identifierField);
        }

        if (! Auth::user()?->is_active) {
            Auth::logout();

            return back()
                ->withErrors([$identifierField => 'This account is disabled. Contact your administrator.'])
                ->onlyInput($identifierField);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->route('console.dashboard.main');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
