<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Models\Category;
use App\Models\FavoriteCategory;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Theme;
use App\Models\User;

class SettingsController extends Controller
{
    private function settingsPath(): string
    {
        return in_array($this->request->query('se', ''), ['1', 'user'], true) ? '/settings?se=user' : '/settings';
    }

    private function siteEditorPreview(): bool
    {
        return $this->request->query('se', '') === 'user';
    }

    /**
     * Settings require an authenticated user (unless previewing in site editor).
     */
    public function __construct(Request $request)
    {
        parent::__construct($request);
        if (!$this->siteEditorPreview()) {
            Auth::requireLogin();
        }
    }

    /**
     * Show the settings page (favourites + password change). Admins get the
     * admin-styled variant, regular users the standard layout.
     */
    public function show(): void
    {
        $isPreview = $this->siteEditorPreview();
        $user = $isPreview ? ['id' => 0, 'email' => '', 'theme_preset' => '', 'billing_first_name' => ''] : Auth::user();
        $active = $isPreview ? null : Subscription::activeFor((int) $user['id']);
        $themeEligible = $isPreview ? false : Auth::canUseCustomTheme();
        $themePresets = [];

        if ($themeEligible) {
            foreach (Theme::presets() as $preset) {
                if (($preset['scope'] ?? Theme::SCOPE_SITE) !== Theme::SCOPE_SITE) {
                    continue;
                }
                $full = Theme::loadPreset($preset['slug']);
                if ($full !== null) {
                    $theme = Theme::userTheme($preset['slug']);
                    $theme['title_image_url'] = url($theme['title_image']);
                    $themePresets[] = [
                        'slug' => $preset['slug'],
                        'name' => $preset['name'],
                        'theme' => $theme,
                    ];
                }
            }
        }

        $data = [
            'categories'      => $isPreview ? [] : Category::all(),
            'favorites'       => $isPreview ? [] : FavoriteCategory::forUser((int) $user['id']),
            'favoritesLocked' => $isPreview ? true : !Auth::hasMembershipLevel(Plan::SILVER_LEVEL),
            'themeEligible'   => $themeEligible,
            'themePresets'    => $themePresets,
            'themeSelected'   => $user['theme_preset'] ?? '',
            'themeDefault'    => (static function (): array {
                $theme = Theme::userTheme();
                $theme['title_image_url'] = url($theme['title_image']);
                return $theme;
            })(),
            'emailUnverified' => false,
            'user'            => $user,
            'siteEditorPreview' => $isPreview,
        ];

        if (Auth::isAdmin() && !$isPreview) {
            $this->viewAdmin('settings', $data);
            return;
        }

        $this->view('settings', $data);
    }

    /**
     * Save the selected site preset for an eligible yearly or lifetime member.
     */
    public function updateTheme(): void
    {
        $user = Auth::user();
        if (!Auth::canUseCustomTheme()) {
            $this->flash('error', 'Theme selection requires an active yearly or lifetime membership.');
            $this->redirect($this->settingsPath());
        }

        $slug = trim((string) $this->request->post('theme_preset', ''));
        if ($slug !== '') {
            $preset = Theme::loadPreset($slug);
            if (!is_array($preset) || ($preset['scope'] ?? Theme::SCOPE_SITE) !== Theme::SCOPE_SITE) {
                $this->flash('error', 'That user theme is not available.');
                $this->redirect($this->settingsPath());
            }
        }

        User::updateThemePreset((int) $user['id'], $slug !== '' ? $slug : null);
        $this->flash('success', 'User theme updated.');
        $this->redirect($this->settingsPath());
    }

    /**
     * Change the logged-in user's password after verifying the current one.
     */
    public function updatePassword(): void
    {
        $current = (string) $this->request->post('current_password', '');
        $new     = (string) $this->request->post('new_password', '');
        $confirm = (string) $this->request->post('confirm_password', '');

        if ($new !== $confirm) {
            $this->flash('error', 'New passwords do not match.');
            $this->redirect($this->settingsPath());
        }

        $error = Auth::changePassword(Auth::user()['id'], $current, $new);

        if ($error !== null) {
            $this->flash('error', $error);
            $this->redirect($this->settingsPath());
        }

        $this->flash('success', 'Password changed.');
        $this->redirect($this->settingsPath());
    }

    /**
     * Replace the current user's favourite categories from the settings form.
     */
    public function updateFavorites(): void
    {
        Auth::requireMembershipLevel(
            Plan::SILVER_LEVEL,
            'Selecting favorite categories requires at least a Silver level membership.'
        );

        $categoryIds = $this->request->post('categories', []);
        $categoryIds = is_array($categoryIds) ? $categoryIds : [];

        FavoriteCategory::replace((int) Auth::user()['id'], $categoryIds);

        $this->flash('success', 'Favorite categories updated.');
        $this->redirect($this->settingsPath());
    }

    public function updateProfile(): void
    {
        $user = Auth::user();
        $fields = ['billing_first_name', 'billing_last_name', 'billing_address_line1', 'billing_address_line2', 'billing_city', 'billing_state', 'billing_zip', 'billing_country'];
        $values = [];
        foreach ($fields as $field) {
            $value = trim((string) $this->request->post($field, ''));
            if (mb_strlen($value) > ($field === 'billing_address_line1' || $field === 'billing_address_line2' ? 255 : 100)) {
                $this->flash('error', 'Please keep profile fields within their allowed length.');
                $this->redirect($this->settingsPath());
            }
            $values[$field] = $value;
        }
        if ($values['billing_country'] !== '' && !preg_match('/\A[A-Za-z]{2}\z/', $values['billing_country'])) {
            $this->flash('error', 'Country must be a two-letter code.');
            $this->redirect($this->settingsPath());
        }
        User::updateBillingProfile((int) $user['id'], ...array_values($values));
        $this->flash('success', 'Profile details updated.');
        $this->redirect($this->settingsPath());
    }

    public function logoutEverywhere(): void
    {
        $user = Auth::user();
        Auth::logoutEverywhere((int) $user['id']);
        Auth::logout();
        header('Location: ' . url('/login'));
        exit;
    }
}
