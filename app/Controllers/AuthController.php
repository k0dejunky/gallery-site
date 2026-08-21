<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Photo;
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
     */
    public function login(): void
    {
        $email    = $this->request->input('email');
        $password = (string) $this->request->post('password', '');

        $result = Auth::attempt($email, $password, $this->request->ip());

        if ($result === true) {
            $this->flash('success', 'Welcome back!');
            $this->redirect(Auth::homePath() . ($this->request->query('se', '') === '1' ? '?se=1' : ''));
        }

        $message = is_string($result) ? $result : 'Invalid email or password.';
        $this->flash('error', $message);
        $this->redirect('/login' . ($this->request->query('se', '') === '1' ? '?se=1' : ''));
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

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('error', 'A valid email address is required.');
            $this->redirect('/signup');
        }

        if (strlen($password) < 8) {
            $this->flash('error', 'Password must be at least 8 characters.');
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

        User::create($email, $password, 'user', $dob);
        $userId = (int) User::findByEmail($email)['id'];

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

        $this->flash('success', 'Account created. Welcome to ' . config('app.site_name') . '!');
        $this->redirect('/membership' . ($this->request->query('se', '') === '1' ? '?se=1' : ''));
    }

    /**
     * Destroy the session and return to the login page.
     */
    public function logout(): void
    {
        Auth::logout();
        $this->redirect('/login' . ($this->request->query('se', '') === '1' ? '?se=1' : ''));
    }
}
