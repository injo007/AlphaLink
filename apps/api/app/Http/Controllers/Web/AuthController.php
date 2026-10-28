<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function profile(): View
    {
        return view('auth.profile');
    }

    public function changePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ]);

        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! Hash::check($validated['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'The current password is incorrect.']);
        }

        if (Hash::check($validated['password'], $user->password)) {
            return back()->withErrors(['password' => 'Choose a password different from your current password.']);
        }

        $user->password = $validated['password'];
        $user->save();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Password changed. Sign in with your new password.');
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

        $authenticated = false;
        if ($credentialField === 'username') {
            $user = User::query()->whereRaw('LOWER(username) = LOWER(?)', [$identifier])->first();
            $authenticated = $user instanceof User && Hash::check($credentials['password'], $user->password);

            if ($authenticated) {
                Auth::login($user, $request->boolean('remember'));
            }
        } else {
            $authenticated = Auth::attempt($attemptCredentials, $request->boolean('remember'));
        }

        if (! $authenticated) {
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

        $authenticatedUser = Auth::user();
        if (! $authenticatedUser instanceof User) {
            abort(401);
        }

        Auth::logoutOtherDevices($credentials['password']);
        $authenticatedUser->refresh();
        $request->session()->put(
            'password_hash_'.Auth::getDefaultDriver(),
            Auth::guard()->hashPasswordForCookie($authenticatedUser->getAuthPassword()),
        );

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
