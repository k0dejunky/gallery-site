<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Mailer;
use App\Core\RateLimiter;
use App\Models\Photo;
use App\Models\PasswordReset;
use App\Models\User;

class AuthController extends Controller
{
    /**
     * The login page. Guests also see recently uploaded pictures and videos
     * as a preview; already-logged-in users are sent straight to their home.
     */
    public function loginForm(): void
    {
        if (Auth::check() && empty($_GET['se'])) {
            $this->redirect(Auth::homePath() . ($this->request->query('se', '') === '1' ? '?se=1' : ''));
        }

        $this->view('auth/login', [
            // Only the most recent uploads are shown on the login page.
            'recentImages' => Photo::recentImages(10),
            'recentVideos' => Photo::recentVideos(10),
        ]);
    }

    /**
     * Handle the login POST and redirect to the user's role-appropriate home.
     * Accounts with two-factor enabled are sent to the two-factor page after
     * their password is verified.
     */
    public function login(): void
    {
        $email    = $this->request->input('email');
        $password = (string) $this->request->post('password', '');
        $remember = $this->request->post('remember_me', null) !== null;

        $result = Auth::attempt($email, $password, $this->request->ip());

        if ($result === '2fa') {
            $_SESSION['2fa_remember'] = $remember ? 1 : 0;
            $this->redirect('/login/2fa');
        }

        if ($result === true) {
            $this->flash('success', 'Welcome back!');
            $this->redirect(Auth::homePath() . ($this->request->query('se', '') === '1' ? '?se=1' : ''));
        }

        $message = is_string($result) ? $result : 'Invalid email or password.';
        $this->flash('error', $message);
        $this->redirect('/login' . ($this->request->query('se', '') === '1' ? '?se=1' : ''));
    }

    /**
     * Two-factor verification page shown after a valid password on an
     * account with TOTP enabled.
     */
    public function twoFactorForm(): void
    {
        if (!Auth::twoFactorPending()) {
            $this->redirect('/login');
        }

        $this->view('auth/2fa', []);
    }

    /**
     * Verify the TOTP code and complete the two-factor login.
     */
    public function twoFactorVerify(): void
    {
        if (!Auth::twoFactorPending()) {
            $this->redirect('/login');
        }

        $code = (string) $this->request->post('code', '');

        if (Auth::completeTwoFactor($code)) {
            $this->flash('success', 'Welcome back!');
            $this->redirect(Auth::homePath());
        }

        $this->flash('error', 'The verification code is invalid or has expired.');
        $this->redirect('/login/2fa');
    }

    /**
     * The signup form. Mirrors the login page theme, including a preview of
     * the most recent uploads.
     */
    public function signupForm(): void
    {
        if (Auth::check()) {
            $this->redirect(Auth::homePath() . ($this->request->query('se', '') === '1' ? '?se=1' : ''));
        }

        $this->view('auth/signup', [
            'recentImages' => Photo::recentImages(10),
            'recentVideos' => Photo::recentVideos(10),
        ]);
    }

    /**
     * Register a new standard user. Validates email/password, runs a
     * honeypot trap for bots, blocks duplicate accounts and logs the user in.
     */
    public function signup(): void
    {
        $email    = trim($this->request->input('email'));
        $password = (string) $this->request->post('password', '');
        $confirm  = (string) $this->request->post('password_confirm', '');
        $honey    = (string) $this->request->input('website');
        $dob      = $this->request->input('date_of_birth') ?: null;

        if ($honey !== '') {
            $this->redirect('/signup');
        }

        $errors = \App\Core\Validator::validate([
            'email'           => $email,
            'password'        => $password,
            'date_of_birth'   => (string) $dob,
        ], [
            'email'         => 'required|email',
            'password'      => 'required|min:8',
            'date_of_birth' => 'required|date',
        ]);

        if ($errors !== []) {
            $this->flash('error', implode(' ', $errors));
            $this->redirect('/signup');
        }

        if ($password !== $confirm) {
            $this->flash('error', 'Passwords do not match.');
            $this->redirect('/signup');
        }

        if (User::findByEmail($email) !== null) {
            $this->flash('error', 'An account with that email already exists.');
            $this->redirect('/login');
        }

        $dobDate = date_create($dob);
        if ($dobDate === false) {
            $this->flash('error', 'Invalid date of birth.');
            $this->redirect('/signup');
        }

        $age = (new \DateTime())->diff($dobDate)->y;
        if ($age < 18) {
            $this->flash('error', 'You must be at least 18 years old to create an account.');
            $this->redirect('/signup');
        }

        User::create($email, $password, 'user', $dob);
        $userId = (int) User::findByEmail($email)['id'];
        $verificationToken = User::createVerificationToken($userId);
        $verificationUrl = rtrim(env_value('APP_URL', url('/')), '/')
            . '/verify-email?token=' . rawurlencode($verificationToken);
        $mailSent = Mailer::send(
            $email,
            'Verify your ' . config('app.site_name') . ' email address',
            "Thanks for creating an account with " . config('app.site_name') . ".\n\n"
            . "Please verify your email address by opening this link:\n"
            . $verificationUrl . "\n\n"
            . "If you did not create this account, you can ignore this email."
        );

        $bFn   = $this->request->input('billing_first_name');
        $bLn   = $this->request->input('billing_last_name');
        $bA1   = $this->request->input('billing_address_line1');
        $bA2   = $this->request->input('billing_address_line2');
        $bCity = $this->request->input('billing_city');
        $bSt   = $this->request->input('billing_state');
        $bZip  = $this->request->input('billing_zip');
        $bCo   = $this->request->input('billing_country');

        if ($bFn || $bLn || $bA1 || $bCity) {
            User::updateProfile($userId, $email, 'user', $dob, false, $bFn, $bLn, $bA1, $bA2, $bCity, $bSt, $bZip, $bCo);
        }

        Auth::loginUser($userId);

         $this->flash('success', $mailSent
             ? 'Account created. Welcome! Check your email to verify your address.'
             : 'Account created. We could not send the verification email yet. You can resend it from your account settings.');
         $this->redirect('/membership' . ($this->request->query('se', '') === '1' ? '?se=1' : ''));
    }

    /**
     * Consume a valid email verification link.
     */
    public function verifyEmail(): void
    {
        $token = trim((string) $this->request->query('token', ''));
        $user = User::findByVerificationToken($token);

        if ($user === null) {
            $this->flash('error', 'That email verification link is invalid or has already been used.');
            $this->redirect(Auth::check() ? Auth::homePath() : '/login');
        }

        User::markEmailVerified((int) $user['id']);
        $this->flash('success', 'Your email address has been verified.');
        $this->redirect(Auth::check() ? Auth::homePath() : '/login');
    }

    /**
     * Send a replacement verification link to the current unverified user.
     */
    public function resendVerification(): void
    {
        Auth::requireLogin();
        $user = Auth::user();

        $limit = (int) config('app.auth.verification_rate_limit', 5);
        $window = (int) config('app.auth.recovery_rate_window_seconds', 3600);
        $account = $user === null ? 'unknown' : (string) $user['id'];
        $allowed = RateLimiter::allow([
            'verification-ip:' . $this->request->ip(),
            'verification-account:' . $account,
        ], $limit, $window);

        $now = time();
        $lastSent = (int) ($_SESSION['email_verification_sent_at'] ?? 0);
        if (!$allowed || $now - $lastSent < 60
            || $user === null
            || in_array($user['role'] ?? '', Auth::ADMIN_ROLES, true)
            || !empty($user['email_verified_at'])) {
            // Do not disclose whether the account needs a message or is rate limited.
            $this->flash('success', 'If verification is required, a new email will be sent shortly.');
            $this->redirect('/settings');
        }

        $token = User::createVerificationToken((int) $user['id']);
        $verificationUrl = rtrim(env_value('APP_URL', url('/')), '/')
            . '/verify-email?token=' . rawurlencode($token);
        $sent = Mailer::send(
            (string) $user['email'],
            'Verify your ' . config('app.site_name') . ' email address',
            "Please verify your email address by opening this link:\n"
            . $verificationUrl . "\n\nIf you did not request this, you can ignore this email."
        );
        $_SESSION['email_verification_sent_at'] = $now;

        $this->flash('success', 'If verification is required, a new email will be sent shortly.');
        $this->redirect('/settings');
    }

    /**
     * Destroy the session and return to the login page.
     */
    public function logout(): void
    {
        Auth::logout();
        $this->redirect('/login' . ($this->request->query('se', '') === '1' ? '?se=1' : ''));
    }

    public function forgotForm(): void
    {
        $this->view('auth/forgot_password');
    }

    public function forgot(): void
    {
        $email = trim($this->request->input('email'));
        $limit = (int) config('app.auth.reset_rate_limit', 5);
        $window = (int) config('app.auth.recovery_rate_window_seconds', 3600);
        $allowed = RateLimiter::allow([
            'reset-ip:' . $this->request->ip(),
            'reset-account:' . strtolower($email),
        ], $limit, $window);

        $user = $allowed && filter_var($email, FILTER_VALIDATE_EMAIL) ? User::findByEmail($email) : null;
        if ($user !== null) {
            $token = bin2hex(random_bytes(32));
            \App\Models\PasswordReset::create($email, $token);
            $resetUrl = rtrim(env_value('APP_URL', url('/')), '/')
                . '/reset-password?token=' . rawurlencode($token);
            $sent = Mailer::send(
                $email,
                'Reset your ' . config('app.site_name') . ' password',
                "A password reset was requested for your account.\n\n"
                . "Open this link within one hour to choose a new password:\n"
                . $resetUrl . "\n\nIf you did not request this, you can ignore this email."
            );

            if (!$sent) {
                error_log('[PASSWORD RESET] Could not send reset email to ' . $email);
            }
        }

        \App\Models\PasswordReset::cleanup();
        // Always show the same message to prevent email enumeration.
        $this->flash('success', 'If an account exists with that email, you will receive a password reset link shortly.');
        $this->redirect('/login');
    }

    public function resetForm(): void
    {
        $token = $this->request->query('token', '');
        $record = \App\Models\PasswordReset::findByToken($token);

        if ($record === null) {
            $this->flash('error', 'Invalid or expired reset token.');
            $this->redirect('/login');
        }

        $this->view('auth/reset_password', ['token' => $token]);
    }

    public function reset(): void
    {
        $token    = $this->request->input('token');
        $password = (string) $this->request->post('password', '');
        $confirm  = (string) $this->request->post('password_confirm', '');

        $record = \App\Models\PasswordReset::findByToken($token);
        if ($record === null) {
            $this->flash('error', 'Invalid or expired reset token.');
            $this->redirect('/login');
        }

        if (strlen($password) < 8) {
            $this->flash('error', 'Password must be at least 8 characters.');
            $this->redirect('/reset-password?token=' . urlencode($token));
        }

        if ($password !== $confirm) {
            $this->flash('error', 'Passwords do not match.');
            $this->redirect('/reset-password?token=' . urlencode($token));
        }

        $user = \App\Models\User::findByEmail($record['email']);
        if ($user !== null) {
            \App\Models\User::updatePassword((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
            Auth::logoutEverywhere((int) $user['id']);
            try {
                \App\Models\AuditLog::record(null, 'update', 'user_password', (int) $user['id'], 'Reset account password');
            } catch (\Throwable $exception) {
                error_log('[auth] password audit failed: ' . $exception->getMessage());
            }
        }

        \App\Models\PasswordReset::deleteByToken($token);

        $this->flash('success', 'Your password has been reset. You can now log in.');
        $this->redirect('/login');
    }
}
