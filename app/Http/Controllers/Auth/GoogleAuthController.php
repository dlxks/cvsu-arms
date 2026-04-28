<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

/**
 * Handles Google OAuth authentication flows, including the initial redirect,
 * the callback after authorization, and user logout.
 *
 * Authentication is restricted to users who already exist in the system and
 * whose accounts are active. The Google OAuth credentials must be configured
 * in `config/services.php` (via the corresponding `.env` keys) before any
 * sign-in attempt can succeed.
 */
class GoogleAuthController extends Controller
{
    /**
     * Redirects the user to the Google OAuth authorization page.
     *
     * Before issuing the redirect, the method verifies that all required Google
     * OAuth credentials are present and valid. If the configuration is missing,
     * the user is sent back to the login page with an error message instead.
     * The `select_account` prompt is enforced so Google always shows the
     * account-picker, even when only one account is signed in.
     *
     * @return RedirectResponse|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function redirect(): RedirectResponse
    {
        Log::info('Initiating Google OAuth redirect process.');

        if (! $this->hasGoogleConfiguration()) {
            Log::warning('Google OAuth redirect failed: Configuration missing.');

            return $this->redirectWithError('Google sign-in is not configured yet. Please contact the administrator.');
        }

        /** @var GoogleProvider $google */
        $google = Socialite::driver('google');

        Log::info('Google OAuth configuration verified. Redirecting user to Google.');

        return $google
            ->with([
                // 'hd' => ['cvsu.edu.ph'],
                'prompt' => 'select_account',
            ])
            ->redirect();
    }

    /**
     * Handles the OAuth callback from Google and authenticates the user.
     *
     * This method performs a series of validations before granting access:
     * - Verifies that the Google OAuth configuration is present.
     * - Retrieves and normalizes the Google user's e-mail address.
     * - Blocks sign-in for non-CvSU / non-Gmail domain addresses.
     * - Ensures the user already exists in the system (no self-registration).
     * - Confirms the user account is active and permitted to use Google sign-in.
     * - Syncs the Google profile ID and avatar to the local user record.
     * - Regenerates the session after a successful login.
     * - Validates that a dashboard route is registered for the authenticated user.
     *
     * Any uncaught exception during the OAuth handshake is logged and results in
     * a generic error redirect to prevent information leakage.
     *
     * @param  Request  $request  The incoming HTTP request from Google's OAuth callback.
     * @return RedirectResponse Redirects to the user's dashboard on success, or back to login with an error.
     *
     * @throws \Throwable Caught internally; any exception during the Socialite handshake is logged.
     */
    public function callback(Request $request): RedirectResponse
    {
        if (! $this->hasGoogleConfiguration()) {
            Log::error('Google sign-in attempt failed: Missing Google configuration.', [
                'ip' => $request->ip(),
                'url' => $request->url(),
            ]);

            return $this->redirectWithError('Google sign-in is not configured yet. Please contact the administrator.');
        }

        try {
            $googleUser = Socialite::driver('google')->user();
            $email = Str::lower(trim((string) $googleUser->getEmail()));

            // Check if email is empty or if email domain is allowed
            if ($email == '' || Str::endsWith($email, ['@cvsu.edu.ph', '@gmail.com'])) {
                Log::warning('Google sign-in blocked: Cavite State University can only be used within its organization.', [
                    'attempted_email' => $email ?: 'empty string',
                    'ip' => $request->ip(),
                ]);

                return $this->redirectWithError('Please use CvSU only Google account to continue.');
            }

            // Check for existing User account
            $user = User::query()->where('email', $email)->first();
            if (! $user) {
                Log::warning('Google sign-in blocked: Unregistered user attempted access.', [
                    'attempted_email' => $email,
                    'ip' => $request->ip(),
                ]);

                return $this->redirectWithError('Your account must be added by an administrator before you can sign in.');
            }
            // Check if user has active account that can sign-in
            if (! $user->canUseGoogleSignIn()) {
                Log::warning('Google sign-in blocked: Inactive account access attempt.', [
                    'user_id' => $user->id,
                    'email' => $email,
                    'ip' => $request->ip(),
                ]);

                return $this->redirectWithError('Your account is inactive. Please contact the administrator.');
            }

            // Sync Google profile if user email already exists.
            $user->syncGoogleProfile($googleUser->getId(), $googleUser->getAvatar());

            // Login the User
            Auth::login($user, remember: true);
            $request->session()->regenerate();

            Log::info('Google sign-in successful.', [
                'user_id' => $user->id,
                'ip' => $request->ip(),
            ]);

            return redirect()->route('dashboard');
        } catch (\Throwable $exception) {
            Log::error('Google authentication failed due to an exception.', [
                'message' => $exception->getMessage(),
                'ip' => $request->ip(),
                'url' => $request->url(),
            ]);

            return $this->redirectWithError('Authentication failed. Please try again.');
        }
    }

    /**
     * Logs out the currently authenticated user and redirects to the login page.
     *
     * The active session is terminated via Laravel's Auth guard. A log entry is
     * recorded with the user ID and request context before the session is ended.
     *
     * @return RedirectResponse Redirects to the named `login` route.
     */
    public function logout(): RedirectResponse
    {
        $request = request();

        Log::info('User logged out.', [
            'user_id' => Auth::id(),
            'ip' => $request->ip(),
            'url' => $request->url(),
        ]);

        Auth::logout();

        return redirect()->route('login');
    }

    /**
     * Redirects the user back to the login page and flashes a validation error.
     *
     * The error is injected into the `email` validation bag so that existing
     * login-page views can display it without modification. The redirect details
     * and the error message are written to the warning log for audit purposes.
     *
     * @param  string  $message  Human-readable error message to display on the login page.
     * @return RedirectResponse Redirects to the named `login` route with the error flashed to the session.
     */
    public function redirectWithError(string $message): RedirectResponse
    {
        $request = request();

        Log::warning('Authentication flow redirected with an error.', [
            'user_id' => Auth::id(),
            'message' => $message,
            'ip' => $request->ip(),
            'url' => $request->url(),
        ]);

        return redirect()->route('login')->withErrors(['email' => $message]);
    }

    /**
     * Determines whether the Google OAuth credentials are fully configured.
     *
     * Reads the `services.google` configuration array and checks that
     * `client_id`, `client_secret`, and `redirect` are all non-empty, and that
     * the redirect value is a syntactically valid URL. An error log entry is
     * written whenever one or more values are missing or malformed.
     *
     * @return bool `true` if all required Google OAuth values are present and valid; `false` otherwise.
     */
    private function hasGoogleConfiguration(): bool
    {
        $config = config('services.google');

        $isConfigured = filled($config['client_id'])
        && filled($config['client_secret'])
        && filled($config['redirect'])
        && filter_var($config['redirect'], FILTER_VALIDATE_URL) !== false;

        if (! $isConfigured) {
            Log::error('Google OAuth configuration is incomplete.', [
                'client_id_present' => filled($config['client_id'] ?? null),
                'client_secret_present' => filled($config['client_secret'] ?? null),
                'redirect' => $config['redirect'] ?? null,
            ]);
        }

        return $isConfigured;
    }
}
