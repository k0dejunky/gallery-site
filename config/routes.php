<?php

// Route table: [HTTP method, path pattern, Controller@action].
// {param} segments are captured and passed to the action by name.

return [
    // Public (guests can reach these)
    ['GET', '/', 'AuthController@loginForm'],
    ['GET', '/galleries', 'GalleryController@index'],
    ['GET', '/galleries/category/{slug}', 'GalleryController@category'],
    ['GET', '/galleries/{id}', 'GalleryController@show'],
    ['GET', '/images/{id}', 'ImageController@show'],
    ['GET', '/videos/{id}', 'VideoController@show'],
    ['GET', '/files/{file}', 'StorageController@serve'],

    // Biller postbacks (server-to-server; no session, no CSRF — see Router)
    ['GET', '/webhooks/{provider}', 'WebhookController@handle'],
    ['POST', '/webhooks/{provider}', 'WebhookController@handle'],

    // Auth
    ['GET', '/login', 'AuthController@loginForm'],
    ['POST', '/login', 'AuthController@login'],
    ['GET', '/signup', 'AuthController@signupForm'],
    ['POST', '/signup', 'AuthController@signup'],
    ['POST', '/logout', 'AuthController@logout'],

    // Favorites (logged in)
    ['POST', '/favorites/categories/{categoryId}/toggle', 'FavoriteController@toggle'],

    // Admin
    ['GET', '/admin', 'AdminController@dashboard'],
    ['POST', '/admin', 'AdminController@login'],
    ['GET', '/admin/search', 'SearchController@index'],
    ['GET', '/admin/categories', 'CategoryController@index'],
    ['POST', '/admin/categories', 'CategoryController@store'],
    ['GET', '/admin/categories/{id}/edit', 'CategoryController@edit'],
    ['POST', '/admin/categories/{id}', 'CategoryController@update'],
    ['POST', '/admin/categories/{id}/delete', 'CategoryController@destroy'],
    ['GET', '/admin/galleries', 'GalleryController@index'],
    ['GET', '/admin/galleries/create', 'GalleryController@create'],
    ['POST', '/admin/galleries/pending/upload', 'GalleryController@pendingUpload'],
    ['POST', '/admin/galleries/pending/{file}/rotate', 'GalleryController@pendingRotate'],
    ['POST', '/admin/galleries/pending/{file}/delete', 'GalleryController@pendingDelete'],
    ['GET', '/admin/galleries/pending/{file}', 'GalleryController@pendingFile'],
    ['POST', '/admin/galleries', 'GalleryController@store'],
    ['GET', '/admin/galleries/{id}', 'AdminController@manageGallery'],
    ['GET', '/admin/galleries/{id}/edit', 'GalleryController@edit'],
    ['POST', '/admin/galleries/bulk', 'GalleryController@bulk'],
    ['POST', '/admin/galleries/{id}', 'GalleryController@update'],
    ['POST', '/admin/galleries/{id}/delete', 'GalleryController@destroy'],
    ['POST', '/admin/galleries/{galleryId}/photos', 'PhotoController@upload'],
    ['POST', '/admin/galleries/{galleryId}/photos/{photoId}/caption', 'PhotoController@updateCaption'],
    ['POST', '/admin/galleries/{galleryId}/photos/{photoId}/delete', 'PhotoController@destroy'],
    ['POST', '/admin/galleries/{galleryId}/photos/{photoId}/move', 'PhotoController@move'],
    ['POST', '/admin/galleries/{galleryId}/photos/{photoId}/rotate', 'PhotoController@rotate'],
    ['GET', '/admin/photos/{id}/edit', 'PhotoController@edit'],
    ['POST', '/admin/photos/{id}/edit', 'PhotoController@applyEdit'],
    ['GET', '/admin/videos/{id}/edit', 'VideoEditorController@edit'],
    ['GET', '/admin/videos', 'VideoEditorController@videoList'],
    ['GET', '/admin/video-projects', 'VideoEditorController@dashboard'],
    ['POST', '/admin/video-projects/{id}', 'VideoEditorController@save'],
    ['POST', '/admin/video-projects/{id}/export', 'VideoEditorController@export'],
    ['GET', '/admin/video-exports/{id}', 'VideoEditorController@status'],
    ['GET', '/admin/video-exports/{id}/stream', 'VideoEditorController@stream'],
    ['GET', '/admin/video-exports/{id}/download', 'VideoEditorController@download'],
    ['POST', '/admin/video-exports/{id}/delete', 'VideoEditorController@deleteExport'],
    ['POST', '/admin/video-exports/{id}/purge', 'VideoEditorController@purgeExport'],
    ['GET', '/admin/video-exports/{id}/create-gallery', 'VideoEditorController@createGalleryFromExport'],
    ['POST', '/admin/video-exports/{id}/create-gallery', 'VideoEditorController@createGalleryFromExport'],

    // Users (admin only)
    ['GET', '/admin/users', 'UserController@index'],
    ['GET', '/admin/users/create', 'UserController@create'],
    ['POST', '/admin/users', 'UserController@store'],
    ['POST', '/admin/users/bulk', 'UserController@bulk'],
    ['POST', '/admin/users/{id}/impersonate', 'UserController@impersonate'],
    ['POST', '/admin/users/{id}/status', 'UserController@setStatus'],
    ['POST', '/admin/users/{id}/reset-password', 'UserController@resetPassword'],
    ['POST', '/admin/users/{id}/logout-everywhere', 'UserController@logoutEverywhere'],
    ['POST', '/admin/users/{id}/notes', 'UserController@addNote'],
    ['POST', '/admin/users/{id}/flag', 'UserController@setFlag'],
    ['GET', '/admin/users/{id}/edit', 'UserController@edit'],
    ['POST', '/admin/users/{id}', 'UserController@update'],
    ['POST', '/admin/users/{id}/delete', 'UserController@destroy'],
    ['GET', '/admin/users/{id}', 'UserController@show'],
    ['POST', '/admin/impersonate/exit', 'UserController@exitImpersonation'],

    // System maintenance (admin only)
    ['GET', '/admin/system', 'SystemController@index'],
    ['POST', '/admin/system/cleanup/pending', 'SystemController@cleanupPending'],
    ['POST', '/admin/system/cleanup/orphans', 'SystemController@cleanupOrphans'],
    ['POST', '/admin/system/backup', 'SystemController@backupCreate'],
    ['GET', '/admin/system/backups/{file}', 'SystemController@backupDownload'],
    ['POST', '/admin/system/backups/{file}/delete', 'SystemController@backupDelete'],
    ['POST', '/admin/system/variants', 'SystemController@variantsRegenerate'],
    ['POST', '/admin/system/db/optimize', 'SystemController@dbOptimize'],
    ['POST', '/admin/system/maintenance', 'SystemController@maintenanceToggle'],
    ['POST', '/admin/system/housekeeping', 'SystemController@housekeepingRun'],
    ['GET', '/admin/export/users', 'ExportController@users'],
    ['GET', '/admin/export/subscriptions', 'ExportController@subscriptions'],

    // Unattended cron (secret-key protected, no session)
    ['GET', '/cron/housekeeping', 'CronController@run'],

    // Theme + docs (admin only)
    ['GET', '/admin/theme', 'ThemeController@index'],
    ['POST', '/admin/theme', 'ThemeController@update'],
    ['POST', '/admin/theme/presets/save', 'ThemeController@savePreset'],
    ['POST', '/admin/theme/presets/apply', 'ThemeController@applyPreset'],
    ['POST', '/admin/theme/presets/delete', 'ThemeController@deletePreset'],
    ['GET', '/admin/site-editor', 'SiteEditorController@editor'],
    ['GET', '/admin/site-editor/templates', 'SiteEditorController@templates'],
    ['POST', '/admin/site-editor/save', 'SiteEditorController@save'],
    ['POST', '/admin/site-editor/update/{id}', 'SiteEditorController@update'],
    ['POST', '/admin/site-editor/delete/{id}', 'SiteEditorController@delete'],
    ['POST', '/admin/site-editor/activate/{id}', 'SiteEditorController@activate'],
    ['POST', '/admin/site-editor/deactivate', 'SiteEditorController@deactivate'],
    ['GET', '/admin/help', 'HelpController@index'],
    ['GET', '/admin/trends', 'TrendsController@index'],
    ['POST', '/admin/trends/promote', 'TrendsController@approvePromotion'],
    ['GET', '/admin/logs', 'LogsController@index'],
    ['GET', '/admin/error-logs', 'LogsController@errorIndex'],
    ['POST', '/admin/logs/{id}/rollback', 'LogsController@rollback'],
    ['POST', '/admin/logs/{id}/purge', 'LogsController@purgeGallery'],

    // Settings (logged in)
    ['GET', '/settings', 'SettingsController@show'],
    ['POST', '/settings/password', 'SettingsController@updatePassword'],
    ['POST', '/settings/favorites', 'SettingsController@updateFavorites'],
    ['POST', '/settings/theme', 'SettingsController@updateTheme'],

    // Membership (logged in)
    ['GET', '/membership', 'MembershipController@index'],
    ['GET', '/membership/my', 'MembershipController@my'],
    ['POST', '/membership/subscribe', 'MembershipController@subscribe'],
    ['POST', '/membership/cancel', 'MembershipController@cancel'],

    // Plans (admin only)
    ['GET', '/admin/plans', 'PlanController@index'],
    ['GET', '/admin/plans/create', 'PlanController@create'],
    ['POST', '/admin/plans', 'PlanController@store'],
    ['GET', '/admin/plans/{id}/edit', 'PlanController@edit'],
    ['POST', '/admin/plans/{id}', 'PlanController@update'],
    ['POST', '/admin/plans/{id}/delete', 'PlanController@destroy'],
    ['POST', '/admin/plans/{id}/toggle-active', 'PlanController@toggleActive'],

    // Membership sales (admin only)
    ['GET', '/admin/sales', 'SalesController@index'],
    ['POST', '/admin/sales', 'SalesController@store'],
    ['POST', '/admin/sales/{id}/toggle', 'SalesController@toggleActive'],
    ['POST', '/admin/sales/{id}/delete', 'SalesController@destroy'],
    ['POST', '/admin/sales/{id}/codes', 'SalesController@generateCode'],
    ['POST', '/admin/sale-codes', 'SalesController@generateStandaloneCode'],

    // Subscriptions (admin only)
    ['GET', '/admin/subscriptions', 'SubscriptionController@index'],
    ['POST', '/admin/subscriptions', 'SubscriptionController@store'],
    ['POST', '/admin/subscriptions/{id}/approve', 'SubscriptionController@approve'],
    ['POST', '/admin/subscriptions/{id}/cancel', 'SubscriptionController@cancel'],
    ['POST', '/admin/subscriptions/{id}/delete', 'SubscriptionController@destroy'],

    // Payment processors (admin only)
    ['GET', '/admin/payment-processors', 'PaymentProcessorsController@index'],
    ['POST', '/admin/payment-processors', 'PaymentProcessorsController@store'],
    ['POST', '/admin/payment-processors/{id}', 'PaymentProcessorsController@update'],
    ['POST', '/admin/payment-processors/{id}/toggle', 'PaymentProcessorsController@toggle'],
    ['POST', '/admin/payment-processors/{id}/delete', 'PaymentProcessorsController@destroy'],

    // Auto poster (admin only)
    ['GET', '/admin/auto-poster', 'AutoPosterController@index'],
    ['POST', '/admin/auto-poster/settings', 'AutoPosterController@saveSettings'],
    ['GET', '/admin/auto-poster/reddit/authorize', 'AutoPosterController@authorizeReddit'],
    ['GET', '/admin/auto-poster/reddit/callback', 'AutoPosterController@callbackReddit'],
    ['POST', '/admin/auto-poster/post/reddit', 'AutoPosterController@postReddit'],
    ['POST', '/admin/auto-poster/post/twitter', 'AutoPosterController@postTwitter'],
    ['POST', '/admin/auto-poster/clear-log', 'AutoPosterController@clearLog'],
];
