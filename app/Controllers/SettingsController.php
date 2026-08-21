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
    /**
     * Settings require an authenticated user.
     */
    public function __construct(Request $request)
    {
        parent::__construct($request);
        Auth::requireLogin();
    }

    /**
     * Show the settings page (favourites + password change). Admins get the
     * admin-styled variant, regular users the standard layout.
     */
    public function show(): void
    {
        $user = Auth::user();
        $active = Subscription::activeFor((int) $user['id']);
        $themeEligible = Auth::canUseCustomTheme();
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
            'categories'      => Category::all(),
            'favorites'       => FavoriteCategory::forUser((int) $user['id']),
            'favoritesLocked' => !Auth::hasMembershipLevel(Plan::SILVER_LEVEL),
            'themeEligible'   => $themeEligible,
            'themePresets'    => $themePresets,
            'themeSelected'   => $user['theme_preset'] ?? '',
            'themeDefault'    => (static function (): array {
                $theme = Theme::userTheme();
                $theme['title_image_url'] = url($theme['title_image']);
                return $theme;
            })(),
        ];

        $userPreview = in_array($this->request->query('se', ''), ['1', 'user'], true);
        if (Auth::isAdmin() && !$userPreview) {
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
}
