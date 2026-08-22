<?php $title = 'Documentation'; ?>

<h1>Site Documentation</h1>

<p class="muted">How the site is put together and how to modify the code. The app runs on the server at
<code>/var/www/gallery</code> and is served under the <code>/gallery</code> URL prefix.</p>

<h2 class="section-title">Admin roles and permissions</h2>
<ul>
    <li><strong>Super Admin</strong> — unrestricted access, including role and permission management. Assigned to <code>fidjiter@gmail.com</code>.</li>
    <li><strong>Admin</strong> — dashboard, trends, galleries, videos, categories, users, memberships, theme, Site Editor, logs, and documentation.</li>
    <li><strong>Editor</strong> — dashboard, trends, galleries, videos, categories, and documentation. Site Editor access is not included.</li>
    <li><strong>Moderator</strong> — dashboard, users, memberships, logs, and documentation.</li>
    <li><strong>Viewer</strong> — read-only dashboard, trends, and documentation access.</li>
</ul>

<h2 class="section-title">Admin dashboard sections</h2>
<ul>
    <li><strong>Dashboard</strong> — seven stat cards (<em>Total Views</em>, <em>Photos</em>, <em>Videos</em>, <em>Galleries</em>, <em>Members</em>, <em>Users</em>, <em>Logged In Members</em>; all computed over non-deleted galleries and active subscriptions via <code>Stats::summary()</code>) followed by a list of all galleries with photo counts, separate <strong>Total Views</strong> and <strong>Unique Views</strong> columns, plus create / edit / delete actions. Deleting a gallery <strong>soft-deletes</strong> it (hidden from the site, restorable from <em>Logs</em>).</li>
    <li><strong>Manage gallery</strong> — upload photos and videos, set captions/links, reorder, remove a file from the current gallery, and rotate images 90&deg; left or right (original and thumbnail are both rewritten in place). Removing a shared file only detaches it from this gallery; its files are deleted only when no gallery references remain.</li>
    <li><strong>Edit photo/video</strong> — every image and video in the admin panel (manage table and edit-gallery file grid) has an <em>Edit</em> button. <strong>Images</strong> open the photo editor at <code>/admin/photos/{id}/edit</code>, a Photoshop-style realtime canvas with live brightness, contrast, sharpen, saturation, blur, grayscale, sepia, mouse-selected cropping, rotation, flipping, text overlay, undo/redo, reset, save, and cancel controls. The canvas uses the original image dimensions with no application-side longest-side cap; saving replaces the original with the rendered canvas. <strong>Videos</strong> open the video editor (NLE) at <code>/admin/videos/{id}/edit</code>, which provides the full editing timeline plus thumbnail tools: upload a custom image, capture a frame at a chosen second (<code>create_video_frame()</code> via ffmpeg), or regenerate from the first second.</li>
    <li><strong>Gallery type</strong> — every gallery is either an <strong>Image Gallery</strong> or a <strong>Video Gallery</strong>, chosen when the gallery is created (and changeable when editing). The type is stored in <code>galleries.type</code> and is <strong>enforced on upload</strong> (<code>PhotoController::validate()</code>): image galleries reject video files and video galleries reject image files with a clear flash error, and the manage page's file picker filters accordingly (<code>accept</code> attribute). Existing galleries were backfilled from their content (video-only galleries became video galleries, everything else an image gallery).</li>
    <li><strong>New Gallery</strong> — create a gallery (title, description, categories) with a two-column layout.</li>
    <li><strong>Edit gallery</strong> — change the title/description/categories <em>and</em> lists every file currently in the gallery as a thumbnail grid (images show their thumb, videos show a short clip with their poster), so you can see what a gallery contains without opening the manage page. Each tile also has an <em>Edit</em> button linking to the photo editor (images) or video editor (videos). Rotating/removing files is still done on the manage page.</li>
    <li><strong>Categories</strong> — add, <strong>search</strong> and delete categories; galleries can be tagged with any number of them. Duplicate names are rejected (checked case-insensitively, with a database backstop). The management page has a <strong>search box</strong> (<code>?q=</code>) that narrows the table to categories whose name contains the term (<code>Category::all($search)</code>), and each row's <strong>Edit</strong> button (<code>/admin/categories/{id}/edit</code>) swaps the Add form for a pre-filled rename form (<code>POST /admin/categories/{id}</code>, which also regenerates the URL slug). The active search is preserved through editing and clearing.</li>
    <li><strong>Users</strong> — list, search, create, and edit user accounts. The user table shows email, role, last login, created date, and action buttons (Edit, Delete). Users can have billing information, age verification status, and membership subscriptions. You cannot delete yourself or the last admin.</li>
    <li><strong>Plans</strong> — manage membership plans. Create, edit, toggle active/inactive, and delete plans. Plans have a name, price, billing cycle (monthly/yearly/lifetime), access level (1&ndash;4), description, and sort order. Inactive plans are dimmed in the table but still visible. Seven plans are seeded by default: Silver ($5/mo, level 1), Gold ($10/mo, level 2), Platinum ($20/mo, level 3), OnlyFans ($25/mo, level 4), Monthly ($9.99/mo, level 1), Yearly ($99.99/yr, level 1), Lifetime ($249.99, level 1).</li>
    <li><strong>Subscriptions</strong> — view all subscription requests, approve pending ones, cancel active ones, or delete records. New user subscriptions start as <em>pending</em> and require admin approval. Approving sets the subscription active and computes the expiry from the plan's billing cycle (lifetime plans never expire).</li>
    <li><strong>Theme</strong> — restyle the site and/or the admin panel with full customisation. The editor has two independent scopes (<em>Site theme</em> for the public galleries, <em>Admin theme</em> for the admin panel) with a live WYSIWYG preview that updates as you change settings. Includes custom colour pickers (128-colour palette grid + hex text input), 28 layout/typography settings across 5 sections (Border &amp; Radius, Typography, Spacing, Effects, Component Styles), a configurable title image (upload or reset to default), and a presets system (save/load/apply/delete named theme configurations). Changes preview live and apply when saved.</li>
    <li><strong>Trends</strong> — a dedicated page (<code>/admin/trends</code>) holding the <em>Trending Categories</em> and <em>Missed Searches</em> panels with a shared period selector (Daily, Weekly, Monthly, Yearly, All time). Also includes a <em>Category Promotion</em> panel: missed search terms that exceed a configurable threshold can be promoted to real categories with one click.</li>
    <li><strong>Logs</strong> — records <strong>only actions that actually alter the site</strong> (gallery, category, photo, plan, user, subscription, and theme changes), not every page visit. Each change shows what was modified (field-by-field <em>before &rarr; after</em>); gallery/category/plan/subscription edits store snapshots and can be rolled back once. A deleted gallery is <strong>soft-deleted</strong>: it disappears from the site but keeps its photos, categories and view counts, and its delete entry offers <strong>Restore</strong> (brings it back exactly as it was) or <strong>Delete permanently</strong> (purges the gallery and its now-orphaned files). Passwords and file contents are never stored.</li>
    <li><strong>Error Logs</strong> (<code>/admin/error-logs</code>) — a separate page that surfaces failed video exports plus the tail of the Apache, PHP-FPM, MySQL and application logs. <code>LogsController::errorIndex()</code> parses a sortable timestamp out of every line (ISO-8601, Apache <code>[Fri Aug 21 … 2026]</code>, and PHP-FPM <code>[21-Aug-2026 …]</code> formats) and orders <strong>newest first, oldest last</strong>. Entries that cannot be timestamped are kept newest-first via their insertion order.</li>
    <li><strong>Settings</strong> — change your password and pick your favorite categories (renders inside the admin layout for admins; each user has their own set).</li>
    <li><strong>Documentation</strong> — this page.</li>
</ul>

<h2 class="section-title">Admin navigation and actions</h2>
<ul>
    <li><strong>Dashboard</strong> — view site statistics and the gallery list. Use <em>Create</em> to add a gallery, <em>Manage</em> to upload or reorder media, <em>Edit</em> to change gallery details, and <em>Delete</em> to soft-delete a gallery.</li>
    <li><strong>Trends</strong> — review trending categories and missed searches. Use the period controls to change the reporting window and <em>Promote</em> to turn a missed search into a category.</li>
    <li><strong>New Gallery</strong> — create a gallery by entering its title, description, type, and categories, then save it before uploading media.</li>
    <li><strong>Video Projects</strong> — open video projects in the editor. Use <em>Save</em> to store edits, <em>Export</em> to create an FFmpeg output, and <em>Download</em> when processing is complete.</li>
    <li><strong>Categories</strong> — add, rename, search, and delete categories. <em>Edit</em> changes the name and slug; <em>Delete</em> removes the category association without deleting galleries.</li>
    <li><strong>Users</strong> — search, create, edit, and delete accounts. <em>Edit</em> changes profile, billing, role, and verification data; <em>Delete</em> permanently removes the account and its related records. The last admin-level account cannot be deleted.</li>
    <li><strong>Membership</strong> — manage membership plans. Use <em>Add</em> or <em>Edit</em> for plan details, <em>Toggle</em> to enable or disable a plan, and <em>Delete</em> to permanently remove a plan and its subscriptions.</li>
    <li><strong>Subscriptions</strong> — manage individual membership records. <em>Approve</em> activates a pending request and calculates its expiry; <em>Cancel</em> ends an active membership according to its expiry rules; <em>Delete</em> permanently removes the subscription record and its history.</li>
    <li><strong>Theme</strong> — edit site and admin colors, layout values, title images, and presets. <em>Save</em> applies the current values to the selected scope; <em>Load</em> fills the editor without applying; <em>Apply</em> activates a saved preset; <em>Delete</em> removes a preset.</li>
    <li><strong>Site Editor</strong> — choose User Site or Admin Site, select elements in the preview, hide/delete/move them, add new content, and save a template. <em>Load</em> previews a template, <em>Set Active</em> makes it live, <em>Off</em> deactivates it, and <em>Del</em> permanently removes it. Use <em>Back to Admin</em> to leave fullscreen editing.</li>
    <li><strong>Logs</strong> — review changes and pending actions. <em>Approve</em> activates a pending membership and returns to Logs; <em>Deny</em> deletes a pending request; rollback actions restore supported previous records.</li>
    <li><strong>Documentation</strong> — read this guide and the current permission matrix.</li>
    <li><strong>View Site</strong> — open the user-facing gallery pages in a separate navigation context.</li>
    <li><strong>Settings</strong> — update the logged-in account password, favorite categories, and eligible personal theme selection.</li>
    <li><strong>Logout</strong> — ends the current admin session. Site Editor user-preview sessions are separate and are not ended by logging out of the admin panel.</li>
</ul>

<h2 class="section-title">Common workflows</h2>
<ul>
    <li><strong>Approve a membership:</strong> open Logs or Subscriptions, choose <em>Approve</em>, confirm the request becomes active, then use the Subscriptions tab for later cancellation or deletion.</li>
    <li><strong>Make a site change live:</strong> open Site Editor, choose the correct scope, make changes, enter a template name, choose <em>Save as Template</em>, then choose <em>Set Active</em>. Refresh live pages to see the active template.</li>
    <li><strong>Preview a user site:</strong> choose User Site and use the separate preview session. The admin session remains isolated from user-preview login and settings data.</li>
    <li><strong>Change the global appearance:</strong> open Theme, select Site Theme or Admin Theme, edit values, and save the selected scope. Personal user theme choices are only available to eligible members and are stored per account.</li>
</ul>

<h2 class="section-title">Membership &amp; subscriptions</h2>
<ul>
    <li><strong>Plans</strong> — stored in the <code>plans</code> table with: name, slug, price, billing cycle (<code>monthly</code>, <code>yearly</code>, <code>lifetime</code>), access level (1&ndash;4), description, sort order, and active flag. The <code>level</code> field determines what content a plan grants access to: Silver = 1, Gold = 2, Platinum = 3, OnlyFans = 4.</li>
    <li><strong>Subscriptions</strong> — stored in the <code>subscriptions</code> table with: user_id, plan_id, status (<code>pending</code>, <code>active</code>, <code>cancelled</code>, <code>expired</code>), starts_at, expires_at. Lifecycle: user subscribes &rarr; <em>pending</em> &rarr; admin approves &rarr; <em>active</em> (expiry computed from billing cycle; lifetime plans never expire) &rarr; user cancels or time passes &rarr; <em>cancelled</em>/<em>expired</em>.</li>
    <li><strong>User-facing pages</strong> — <code>/membership</code> shows available plans with a Subscribe button; <code>/membership/my</code> shows the user's subscription history and current status with a Cancel button.</li>
    <li><strong>Content gating</strong> — non-members can browse and search galleries; opening a gallery (<code>/galleries/{id}</code>) requires an active subscription via <code>Auth::requireSubscription()</code>. Viewing individual images (<code>/images/{id}</code>) and videos (<code>/videos/{id}</code>) also require a subscription. The storage controller gates original files and web variants; thumbnails are public for login-page previews.</li>
    <li><strong>Favourite-category gating</strong> — favouriting a category requires Silver level or above (<code>Auth::requireMembershipLevel(1)</code>).</li>
    <li><strong>Admin bypass</strong> — users with <code>role = 'admin'</code> bypass all membership checks (they always have access).</li>
    <li><strong>Grandfathering</strong> — existing users with active subscriptions before the membership system was introduced are preserved via manual admin grants.</li>
</ul>

<h2 class="section-title">User fields</h2>
<ul>
    <li><strong>Billing</strong> — first name, last name, address line 1, address line 2, city, state, zip, country (2-letter code). Editable on the admin user edit page and optionally collected during signup.</li>
    <li><strong>Age verification</strong> — date of birth (required at signup), age_verified flag, age_verified_at timestamp. Admins can toggle the verified checkbox on the user edit page.</li>
    <li><strong>Payment info</strong> — payment_customer_id, card_last_four, card_brand, card_exp_month, card_exp_year. Placeholder fields for future payment integration.</li>
    <li><strong>Last login</strong> — <code>users.last_login_at</code> is updated on every successful login via <code>Auth::loginUser()</code>.</li>
</ul>

<h2 class="section-title">Statistics</h2>
<ul>
    <li><strong>What is tracked</strong> — the admin dashboard opens with seven lifetime stat cards computed by <code>Stats::summary()</code>: <em>Total Views</em> (summed gallery views), <em>Photos</em> (distinct photos in active galleries), <em>Videos</em> (distinct video files in active galleries), <em>Galleries</em> (non-deleted galleries), <em>Members</em> (users with active subscriptions at level &ge; 1), <em>Users</em> (accounts with role = 'user'), <em>Logged In Members</em> (active subscribers who have logged in at least once). The separate <strong>Trends</strong> page holds the two trending panels: <em>Trending Categories</em> (every category, ordered by views in the current window) and <em>Missed Searches</em> (search terms that found nothing). Raw events live in two tables, <code>category_views</code> (category page visits) and <code>search_stats</code> (missed search terms), each row carrying the acting user (nullable) and a <code>created_at</code> timestamp.</li>
    <li><strong>Recording</strong> — <code>Stats::recordCategoryView()</code> fires on every category page render in <code>GalleryController@category</code>. <code>Stats::recordMissedSearch()</code> fires only when a search genuinely finds nothing: the favourites-scoped result is empty <em>and</em> an unrestricted database search also returns zero rows, so a term hidden by category restrictions is never falsely marked as missing. Terms are stored exactly as typed.</li>
    <li><strong>Trending</strong> — a <strong>period selector</strong> on the <em>Trends</em> page switches both panels between <em>Daily</em> (last 24 hours vs the 24 before), <em>Weekly</em> (7 days), <em>Monthly</em> (30 days), <em>Yearly</em> (365 days) and <em>All time</em>. <code>Stats::categoryTrends()</code> / <code>Stats::searchTrends()</code> take the period key and compare the current window with the same-length window before it. Each row shows current and previous counts and a badge: <strong>New</strong> (activity only in the current window), <strong>&#9650;pct</strong> / <strong>&#9660;pct</strong> (rounded percentage change vs. the previous window), or <strong>&mdash;</strong> (no change). <em>All time</em> reports lifetime totals with no comparison.</li>
    <li><strong>Category promotion</strong> — the <em>Trends</em> page includes a <em>Category Promotion</em> panel listing missed search terms that exceed a configurable threshold. Clicking <em>Promote</em> creates a new category from the search term and removes those search stats rows.</li>
    <li><strong>Privacy</strong> — viewer identity is stored only to allow future analysis; the dashboard displays counts and terms only, never who did what. Deleting a category or user cleans up its stats rows via the foreign keys (<code>ON DELETE CASCADE</code> / <code>SET NULL</code>).</li>
</ul>

<h2 class="section-title">View tracking</h2>
<ul>
    <li><strong>Every gallery and photo records two counters</strong> — <code>views</code> (total page loads) and <code>unique_views</code> (one per user). The columns live on <code>galleries</code> and <code>photos</code> (default 0); who-has-seen-what is stored in the <code>gallery_viewers</code> / <code>photo_viewers</code> tables (composite primary key <code>(user_id, target_id)</code>, cascade-deleted with the row).</li>
    <li><strong>Recording</strong> — <code>Gallery::recordView()</code> / <code>Photo::recordView()</code> bump the total counter on every call and the unique counter only the first time the logged-in user views it (an INSERT into the viewer table signals a new unique viewer). The counters are incremented in <code>GalleryController@show</code>, <code>ImageController@show</code> and <code>VideoController@show</code> — all behind <code>Auth::requireLogin()</code>, so the unique key is always the logged-in user's id.</li>
    <li><strong>Where the numbers show up</strong> — the gallery page (under the title), each image/video page (next to the caption), gallery cards (a small muted line), and the admin <em>Dashboard</em> (separate <strong>Total Views</strong> and <strong>Unique Views</strong> columns) and <em>Manage gallery</em> table (as &ldquo;total / unique&rdquo;).</li>
</ul>

<h2 class="section-title">How the code is organized</h2>
<pre>
gallery-mvc/
├── public/index.php              Entry point (loads helpers, router, dispatches the request)
├── config/
│   ├── app.php                   Site config: base_path, site_name, upload limits, auth limits
│   ├── database.php              PDO settings loaded from the non-public .env file
│   └── routes.php                URL → Controller@method map (95 routes)
├── app/
│   ├── Core/                     Framework plumbing
│   │   ├── Router.php            Matches URLs to routes
│   │   ├── Request.php           Wraps $_GET/$_POST/files
│   │   ├── Controller.php        Base class: view(), viewAdmin(), redirect(), flash(), notFound()
│   │   ├── Auth.php              Login/session checks: check(), user(), isAdmin(), requireLogin(),
│   │   │                         requireAdmin(), requireSubscription(), requireMembershipLevel()
│   │   ├── Flash.php             One-time success/error messages
│   │   ├── Csrf.php              CSRF token generation + verification for all POST requests
│   │   ├── Database.php          PDO wrapper around MySQL
│   │   ├── ImageEditor.php       GD image editing: blur, sharpen, resize, rotate, crop, text,
│   │   │                         watermark, EXIF normalize
│   │   └── helpers.php           e(), url(), file_url(), is_video(), config(), csrf_field(),
│   │                             slugify(), create_thumbnail(), create_web_image(),
│   │                             create_video_thumbnail(), create_video_frame(), save_image()
│   ├── Controllers/              Per-feature HTTP handlers
│   │   ├── AuthController.php          User login, signup, logout
│   │   ├── GalleryController.php       Browse galleries, search, show one gallery, category pages
│   │   ├── PhotoController.php         Upload/manage photos & videos; image rotation; photo editor
│   │   ├── ImageController.php         Full-size image page in the site layout (/images/{id})
│   │   ├── VideoController.php         In-page video player in the site layout (/videos/{id})
│   │   ├── StorageController.php       Serves uploaded images/videos (+ thumbnails + web variants)
│   │   ├── FavoriteController.php      Star/unstar favorite categories
│   │   ├── SettingsController.php      /settings page (favorite categories + password)
│   │   ├── UserController.php          Admin user CRUD + billing + age verification
│   │   ├── CategoryController.php      Admin category management (searchable list, edit/rename)
│   │   ├── PlanController.php          Admin membership plan CRUD + toggle active
│   │   ├── SubscriptionController.php  Admin subscription list, grant, approve, cancel
│   │   ├── MembershipController.php    Public pricing, user subscribe/cancel
│   │   ├── ThemeController.php         Admin theme editor, presets, title image upload
│   │   ├── TrendsController.php        Trending categories, missed searches, category promotion
│   │   ├── LogsController.php          Audit log viewer, rollback, purge
│   │   ├── AdminController.php         Admin login at /admin + dashboard + gallery management
│   │   └── HelpController.php          This documentation page
│   └── Models/                   Database access
│       ├── User.php              Account CRUD, billing fields, age verification, subscription join
│       ├── Gallery.php           Paginated browse, search, soft-delete, view tracking
│       ├── Photo.php             Upload, dedup, thumbnail/web variants, view tracking
│       ├── Category.php          CRUD with slug, search
│       ├── FavoriteCategory.php  User favorites
│       ├── Plan.php              Membership plans, tiers, toggle active
│       ├── Subscription.php      Subscription lifecycle, status, approval, cancellation
│       ├── Theme.php             47 colours + 28 layout settings, presets, title image, CSS output
│       ├── Stats.php             Analytics, trends, category views, missed searches, summary
│       ├── AuditLog.php          Admin action recording, field-level diffs, rollback support
│       └── LoginAttempt.php      Brute-force tracking
├── views/
│   ├── layout.php                Site layout (header, nav, sidebar, flash)
│   ├── settings.php              Favorite categories + password change
│   ├── auth/                     login.php, signup.php
│   ├── gallery/                  index.php (home), show.php, category.php, image_full.php
│   ├── video/player.php          In-page video player inside the site layout
│   ├── membership/               index.php (pricing page), my.php (subscription history)
│   ├── partials/                 gallery_card.php, pagination.php, media_nav.php
│   ├── errors/                   404.php, error.php
│   └── admin/
│       ├── layout.php            Admin layout (left sidebar nav, themed CSS, $user ?? Auth::user())
│       ├── login.php             Standalone admin login (no layout wrapper)
│       ├── dashboard.php         7 stat cards + gallery table with photo counts
│       ├── categories.php        Category list with search + edit/rename
│       ├── create.php            New gallery form (two-column layout)
│       ├── edit.php              Edit gallery form with thumbnail grid
│       ├── manage.php            Photo/video upload, captions, reorder, rotate, delete
│       ├── photo_edit.php        Canvas-based image editor (videos use the video editor)
│       ├── users.php             User list with hero search bar + "Add New User"
│       ├── user_create.php       Create user form (three-column layout)
│       ├── user_edit.php         Edit user form (account, billing, age, subscription)
│       ├── plans.php             Plan list + toggle active + "Add New Plan"
│       ├── plan_create.php       Create plan form (three-column layout)
│       ├── plan_edit.php         Edit plan form (three-column layout)
│       ├── subscriptions.php     Subscription list + grant/approve/cancel
│       ├── theme.php             Colour picker, layout settings, presets, title image, preview
│       ├── trends.php            Trending + missed searches + category promotion
│       ├── logs.php              Audit log + pending actions + rollback/purge
│       ├── settings.php          Admin wrapper (includes views/settings.php)
│       └── help.php              This documentation
└── storage/
    ├── uploads/                  Originals + web_* + thumb_* variants (www-data owned)
    ├── themes/                   Saved theme presets as JSON files (www-data owned)
    ├── theme.json                Site colour + layout overrides
    ├── site-layout.json          Site layout overrides
    ├── admin-theme.json          Admin colour + layout overrides
    └── admin-layout.json         Admin layout overrides
</pre>

<h2 class="section-title">Routing</h2>
<p>Every page maps to a controller method in <code>config/routes.php</code> (95 routes total):</p>
<pre>['GET', '/admin/theme', 'ThemeController@index'],</pre>
<p>means <code>GET /gallery/admin/theme</code> calls <code>ThemeController::index()</code>.
Placeholders like <code>{id}</code> become method arguments. All <code>POST</code> routes are CSRF-checked automatically.
The site root <code>/</code> routes to <code>AuthController::loginForm()</code>, so guests see the login page.</p>

<h3>Route groups</h3>
<ul>
    <li><strong>Public</strong> (7 routes) — gallery browsing, images, videos, file serving. Gallery listing and search are open to guests; opening a gallery requires a subscription.</li>
    <li><strong>Auth</strong> (5 routes) — login, signup, logout.</li>
    <li><strong>Favorites</strong> (1 route) — toggle favourite category (requires login + Silver level).</li>
    <li><strong>Admin</strong> (20 routes) — dashboard, gallery CRUD, photo management, photo editor, category CRUD.</li>
    <li><strong>Users</strong> (6 routes) — admin user list, create, edit, update, delete.</li>
    <li><strong>Theme + docs</strong> (9 routes) — theme editor, theme presets (save/apply/delete), help, trends, promotion, audit log + rollback/purge.</li>
    <li><strong>Settings</strong> (3 routes) — password change, favourite categories.</li>
    <li><strong>Membership</strong> (4 routes) — pricing page, subscription history, subscribe, cancel.</li>
    <li><strong>Plans</strong> (7 routes) — admin plan CRUD, toggle active.</li>
    <li><strong>Subscriptions</strong> (5 routes) — admin subscription list, grant, approve, cancel, delete.</li>
</ul>

<h2 class="section-title">Access control</h2>
<ul>
    <li><strong>User area</strong> (galleries, settings, favorites) — requires a login via <code>Auth::requireLogin()</code>.</li>
    <li><strong>Content gating</strong> — opening a gallery, viewing an image, or watching a video requires an active subscription via <code>Auth::requireSubscription()</code>. Non-members can browse the gallery listing and search, but cannot open any gallery.</li>
    <li><strong>Favourite-category gating</strong> — favouriting a category requires Silver level or above via <code>Auth::requireMembershipLevel(1)</code>.</li>
    <li><strong>Admin bypass</strong> — users with <code>role = 'admin'</code> bypass all membership and subscription checks.</li>
    <li><strong>Media files</strong> (<code>/files/*</code>) — thumbnail variants (<code>?size=thumb</code>) are public for login-page previews. Web-sized variants and original files require authentication; video playback supports HTTP range requests for seeking. The authentication and range logic live in PHP (<code>StorageController::serve()</code>), but the actual bytes are streamed by the web server via <strong>mod_xsendfile</strong> (the controller emits an <code>X-Sendfile</code> header that Apache resolves), so large media never flows through the PHP-FPM workers.</li>
    <li><strong>Session security</strong> — session cookies use <code>HttpOnly</code> and <code>SameSite=Lax</code>; every POST remains protected by the shared CSRF token.</li>
    <li><strong>Admin area</strong> (<code>/admin/*</code>) — requires an admin account via <code>Auth::requireAdmin()</code>.</li>
    <li><strong>Admin login</strong> — lives at <code>/admin</code> (POST <code>/admin</code> validates credentials and checks the <code>admin</code> role).</li>
    <li><strong>Admins can still browse</strong> the user area.</li>
</ul>

<h2 class="section-title">Views &amp; layouts</h2>
<ul>
    <li><code>views/layout.php</code> wraps every <em>user</em> page. It outputs the title image header (via <code>Theme::titleImageUrl()</code>), nav, flash messages, and the page's own content. Accepts a <code>$topContent</code> slot for extra content above the main area (used by the theme editor to inject the scope switcher).</li>
    <li>The top-nav <strong>Login</strong> button is hidden while the visitor is on the login page itself (the <code>$isLoginPage</code> flag in <code>views/layout.php</code> controls this).</li>
    <li><code>views/admin/layout.php</code> wraps every <em>admin</em> page with a left sidebar nav listing each admin section. Uses <code>$user = $user ?? Auth::user()</code> so that <code>$user</code> passed by a controller is not overwritten by the layout's own fetch.</li>
    <li><code>Controller::view('name', $data)</code> renders <code>views/name.php</code> inside the user layout;
        <code>Controller::viewAdmin('name', $data)</code> renders <code>views/admin/name.php</code> inside the admin layout;
        <code>viewStandalone()</code> renders a full page with no layout (used for the admin login and the video player).</li>
    <li><strong>Video playback</strong> — a gallery's video tile (with a play badge) links to <code>/videos/{id}</code> (<code>VideoController@show</code>), which renders <code>views/video/player.php</code> inside the site layout: a centered player with the caption below.</li>
    <li>Variables passed to a view are extracted as local variables (e.g. <code>$gallery</code>).</li>
    <li><code>views/admin/settings.php</code> just includes <code>views/settings.php</code>, so the same settings form works in both layouts.</li>
</ul>

<h2 class="section-title">Home page &amp; favorites</h2>
<ul>
    <li>Every user has their <strong>own set of favorite categories</strong>, stored in <code>user_favorite_categories</code> keyed by <code>user_id</code> (managed in <code>/settings</code> or with the star on each category chip). In Settings, selected categories use a purple shade and unselected categories retain the current theme color.</li>
    <li>The home page (<code>views/gallery/index.php</code>) shows the search box, the All/Images/Videos chips, and one section per favorite category with its galleries. <strong>The home page only shows galleries from the user's favorite categories</strong> — the search/type-filtered paginator is restricted to those categories too (<code>Gallery::paginate</code> supports a <code>category_ids</code> filter, supplied by <code>GalleryController@index</code>), so no non-favorite gallery ever appears there. The left navigation area is part of the shared user layout and appears on every logged-in user page; all content renders in the right-hand panel (<code>.home-main</code>).</li>
    <li>The left navigation has two matching themed panels. The <strong>Menu</strong> panel (<code>.home-nav-actions</code>) appears above Favorites and contains <strong>Galleries</strong>, <strong>Membership</strong> (shows &ldquo;Subscribe&rdquo; or the current plan name), <strong>Admin</strong> (admin accounts only), <strong>Settings</strong> and <strong>Logout</strong>. The <strong>Favorites</strong> panel (<code>.home-nav</code>) lists every favorite category, including categories with no galleries yet. The current category is highlighted and moved to the top. The navigation area is sticky on desktop and stacks normally on mobile.</li>
    <li><strong>Filters are maintained across pages</strong>: sidebar links and the home page's <em>View all</em> links carry the current <code>type</code> (images/videos) filter, and the home page applies it throughout. <strong>Category pages (<code>gallery/category.php</code>) have their own <em>All Galleries</em> / <em>Image Galleries</em> / <em>Video Galleries</em> chips</strong> (mirroring the home page): <em>All</em> shows both sections side by side, while an <em>Image</em> or <em>Video</em> chip hides the other section so only that type's <code>imagePaginator</code>/<code>videoPaginator</code> builds and renders. Each section lists the galleries of that type in the category; a <code>q</code> search narrows the visible sections and is preserved when switching chips (<code>?q=…&amp;type=…</code>), as is the <code>type</code> in each section's pagination links. The <code>type</code> filter classifies galleries <strong>by content only</strong> (<code>Gallery::mediaTypeCondition()</code>): <em>Image Galleries</em> shows galleries containing images and nothing else, <em>Video Galleries</em> shows galleries containing videos and nothing else, and galleries mixing both types appear only under <em>All Galleries</em>. An empty section shows a muted message.</li>
    <li>The home page only shows galleries from the user's favorite categories — the page body still skips favorite categories <strong>with no galleries</strong> (they have no section to show), but the sidebar always lists every favorite.</li>
    <li>A gallery that belongs to several favorite categories is shown only once: <code>GalleryController::index()</code> tracks seen gallery ids and skips duplicates in later sections.</li>
    <li>While searching (<code>?q=</code>), the sections are hidden and only the <em>Search results</em> grid is shown, so no gallery ever appears twice on the page.</li>
    <li>The <strong>login page</strong> shows two recent-upload strips for guests: <em>Recent Pictures</em> and <em>Recent Videos</em> (only the last 10 uploads of each — <code>AuthController@loginForm</code> passes <code>Photo::recentImages(10)</code> / <code>Photo::recentVideos(10)</code>), each item rendered as a gallery card. Each strip is a single horizontal row; a small inline script measures the row width and hides any card that would not fit completely, so a thumbnail is never displayed cut off. Strips with fewer than 4 thumbnails are centered with equal spacing; strips of 4 or more are justified across the row. Clicking a card opens the item's own in-page viewer — <code>/images/{id}</code> for pictures, <code>/videos/{id}</code> for videos. Every card image carries an <code>onerror</code> fallback that swaps in an inline SVG placeholder.</li>
</ul>

<h2 class="section-title">The theme</h2>
<ul>
    <li>The theme system provides <strong>47 colour variables</strong> across 6 palette groups and <strong>28 layout/typography settings</strong> across 5 sections, all exposed as CSS custom properties. Two independent scopes (site and admin) can be themed separately.</li>
    <li><strong>Palette groups</strong>: Pink (8 colours: pink-100 to pink-900), Purple (8 colours: purple-200 to purple-900), Button (7 colours: btn-bg, btn-color, btn-hover-bg, btn-danger-bg, btn-danger-color, btn-danger-hover-bg, btn-danger-border), Sidebar (10 colours: sidebar-bg, sidebar-border, sidebar-heading, sidebar-link-bg, sidebar-link-color, sidebar-link-border, sidebar-link-hover, sidebar-active-bg, sidebar-active-color, sidebar-active-border), Card (8 colours: card-bg, card-border, card-placeholder-bg, card-thumb-bg, card-thumb-color, card-title-color, card-text-color, card-cat-link-color).</li>
    <li><strong>Layout settings</strong> (28 keys): Border &amp; Radius (3: border-radius-sm, border-radius, border-radius-lg), Typography (6: font-size-xs through font-size-h1, line-height), Spacing (5: spacing-xs through spacing-xl), Effects (1: shadow), Component Styles (12: btn-padding, btn-radius, btn-font-size, input-padding, input-radius, input-border-width, card-radius, card-padding, chip-radius, chip-padding, table-radius).</li>
    <li><strong>Colour pickers</strong> — the editor uses custom dropdown pickers (not native browser colour dialogs). Each picker opens a 128-colour palette grid (16 rows &times; 8 columns) with a hex text input and Set button below. The swatch displays the current colour. Works reliably in Firefox and Chrome.</li>
    <li><strong>Title image</strong> — configurable via the theme editor. Upload a custom image or reset to the default (<code>/assets/images/AmethystTitleImage.png</code>). Stored as the <code>_title_image</code> key in the scope's theme JSON file. Uploaded images are saved to <code>storage/uploads/</code>. Used in both the site layout and the admin layout (independently per scope).</li>
    <li><strong>Layout settings</strong> — the editor has collapsible sections for each group (Border &amp; Radius, Typography, Spacing, Effects, Component Styles). Each setting has a label and text input. All values are emitted as CSS custom properties alongside the colour palette.</li>
    <li><strong>Saved Themes (presets)</strong> — the theme editor includes a <em>Saved Themes</em> section at the top. Enter a name and click Save to capture the current scope's colours, layout, and title image as a named preset. Presets are stored as JSON files in <code>storage/themes/{slug}.json</code>. Each preset card shows its name, scope, creation date, a row of colour swatches, and Load/Apply/Delete buttons. <strong>Load</strong> populates the editor with the preset's values (no save). <strong>Apply</strong> overwrites the scope's active theme with the preset. <strong>Delete</strong> removes the preset file.</li>
    <li><strong>Live preview</strong> — the theme page includes a mock site layout and mock admin layout in the preview area, showing how the chosen colours and layout settings will look. The preview updates in real time as you change colours or layout values.</li>
    <li><strong>Storage files</strong> — colour overrides live in <code>storage/theme.json</code> (site) and <code>storage/admin-theme.json</code> (admin). Layout overrides live in <code>storage/site-layout.json</code> and <code>storage/admin-layout.json</code>. Title image paths are stored in the colour theme JSON under the <code>_title_image</code> key. All storage files must be owned by <code>www-data:www-data</code> for the web server to write them.</li>
    <li><strong>CSS output</strong> — <code>Theme::css($scope)</code> renders the palette as CSS custom properties targeting <code>:root</code> (site) or <code>.admin-theme</code> (admin). <code>Theme::cssLayout($scope)</code> renders the layout settings similarly. Both are called in <code>views/layout.php</code> and <code>views/admin/layout.php</code>.</li>
    <li><strong>To change colours by hand</strong>: edit <code>storage/theme.json</code> / <code>storage/admin-theme.json</code> on the server, or edit the defaults in <code>app/Models/Theme.php</code> (the <code>COLORS</code> and <code>LAYOUT_DEFAULTS</code> constants).</li>
</ul>

<h2 class="section-title">Audit log &amp; rollback</h2>
<ul>
    <li><strong>What is recorded</strong> — every action that modifies site data: gallery create/edit/delete, category create/edit/delete, photo caption/link change, plan create/edit/delete/toggle, user create/edit/delete, subscription approve/cancel, and theme save. Each entry stores: admin user id, action, entity type, entity id, description, before_json (snapshot before the change), after_json (snapshot after the change), and timestamp.</li>
    <li><strong>Field-level diffs</strong> — <code>AuditLog::diff()</code> compares before/after snapshots and returns only the fields that actually changed. Theme diffs show palette keys prefixed with &ldquo;color:&rdquo; or &ldquo;layout:&rdquo;; only changed keys are listed. Gallery diffs show Title, Description, Type, Categories. Plan diffs show Name, Cycle, Price, Level, Description, Sort order, Active.</li>
    <li><strong>Rollback</strong> — the Logs page shows a <em>Rollback</em> button for create, update, and delete entries. Rollback behaviour depends on the action: <strong>create</strong> &rarr; deletes the created entity; <strong>update</strong> &rarr; restores the entity to its before_json state; <strong>delete</strong> &rarr; recreates the entity from before_json (gallery, category, plan only). Each entry can only be rolled back once; after rollback, the entry is marked with the rollback admin's email and timestamp.</li>
    <li><strong>Pending actions</strong> — the Logs page shows a <em>Pending Actions</em> section listing soft-deleted galleries. Each shows the delete entry with <em>Restore</em> (rollback the delete) or <em>Delete permanently</em> (purge the gallery and its orphaned files from disk and database).</li>
    <li><strong>Purge</strong> — <em>Delete permanently</em> on a soft-deleted gallery removes all its photos from disk (original, web, thumb variants), then deletes the gallery and its orphaned photos from the database.</li>
    <li><strong>Privacy</strong> — passwords and file contents are never stored in snapshots. Only metadata (titles, descriptions, settings, status fields) is captured.</li>
</ul>

<h2 class="section-title">Code comments</h2>
<p>Every class and method in <code>app/</code> carries a docblock explaining what it does and why it exists, and views/config files have inline comments where the intent isn't obvious. Comments were added across <code>Core/</code>, <code>Models/</code>, <code>Controllers/</code>, <code>views/</code>, <code>config/</code>, and <code>public/index.php</code>; keep them accurate when changing behavior.</p>

<h2 class="section-title">How to modify the site code</h2>
<ol>
    <li>Edit the files in the project folder (e.g. <code>views/gallery/index.php</code> for the home page, <code>config/routes.php</code> for URLs).</li>
    <li>Check PHP syntax: <code>php -l &lt;file&gt;</code> on each changed file. On the remote server: <code>sshpass -p 'Km011758!!' ssh k0dejunky@192.168.1.110 'echo Km011758!! | sudo -S -p "" php -l /var/www/gallery/&lt;file&gt;'</code></li>
    <li>Deploy one file at a time with <code>scp</code>:<br>
        <code>scp &lt;local-file&gt; k0dejunky@192.168.1.110:/tmp/&lt;filename&gt;</code><br>
        Then copy into place on the server with sudo:<br>
        <code>sshpass -p 'Km011758!!' ssh -t k0dejunky@192.168.1.110 'echo Km011758!! | sudo -S -p "" cp /tmp/&lt;filename&gt; /var/www/gallery/&lt;path&gt;'</code></li>
    <li>Storage files (<code>storage/theme.json</code>, <code>storage/admin-theme.json</code>, <code>storage/site-layout.json</code>, <code>storage/admin-layout.json</code>, <code>storage/themes/*.json</code>, <code>storage/uploads/*</code>) must be owned by <code>www-data:www-data</code>. After creating or replacing them: <code>echo Km011758!! | sudo -S -p "" chown www-data:www-data /var/www/gallery/storage/&lt;file&gt;</code></li>
    <li>If PHP opcache is enabled and changes aren't reflecting, reset it: <code>echo Km011758!! | sudo -S -p "" php -r 'opcache_reset();' 2>/dev/null || true</code></li>
    <li>Refresh the page. PHP errors appear in <code>/var/log/apache2/error.log</code> on the server.</li>
</ol>

<h2 class="section-title">Search &amp; database</h2>
<ul>
    <li>The home page and category pages search galleries by title, description, and category name. Searches of <strong>3+ characters</strong> use MySQL <strong>FULLTEXT</strong> indexes (<code>MATCH&hellip;AGAINST&hellip;IN BOOLEAN MODE</code>, prefix matching like <code>wed*</code>), which is far faster than <code>LIKE '%term%'</code>. Shorter or unusual terms automatically fall back to <code>LIKE</code>.</li>
    <li><strong>Media type is denormalized</strong>: every <code>photos</code> row carries an <code>is_video</code> flag (set from the file extension at upload time by <code>Photo::create()</code>), so type filters, the <code>video_count</code> per gallery, content classification and the login page's recent strips filter on an indexed column instead of running <code>LIKE</code>/<code>REGEXP</code> scans over filenames. Backed by <code>idx_photos_media_created (is_video, created_at, id)</code>.</li>
    <li><strong>Listings are index-backed</strong>: <code>galleries</code> has <code>idx_galleries_active_created (deleted_at, created_at)</code> so every active-only, newest-first listing scans the index in reverse (no filesort). Category lookups by <code>slug</code>, favorite categories by <code>user_id</code>, and gallery&harr;category joins are also index-backed.</li>
    <li>All of these indexes are part of the database schema — they are <em>not</em> created by the app. If you rebuild the database, use <code>schema.sql</code> or <code>schema.sqlite.sql</code> so these definitions are preserved.</li>
</ul>

<h3>Database tables</h3>
<ul>
    <li><code>users</code> — id, email, password_hash, role (admin/user), created_at, last_login_at, date_of_birth, age_verified, age_verified_at, billing_* fields, payment_customer_id, card_*</li>
    <li><code>galleries</code> — id, title, description, type (images/videos), views, unique_views, created_at, deleted_at (soft-delete). FULLTEXT on (title, description).</li>
    <li><code>photos</code> — id, filename, is_video, hash (SHA-1, unique for dedup), caption, link, views, unique_views, created_at.</li>
    <li><code>gallery_photo</code> — composite PK (gallery_id, photo_id) + position. Many-to-many join.</li>
    <li><code>categories</code> — id, name, slug (unique), created_at. FULLTEXT on name.</li>
    <li><code>gallery_category</code> — composite PK (gallery_id, category_id). Many-to-many join.</li>
    <li><code>user_favorite_categories</code> — composite PK (user_id, category_id). Cascade-deleted with user or category.</li>
    <li><code>gallery_viewers</code> / <code>photo_viewers</code> — composite PK (user_id, target_id). Tracks unique views.</li>
    <li><code>admin_logs</code> — audit trail: id, user_id, action, entity_type, entity_id, description, before_json, after_json, created_at, rolled_back_at, rollback_by.</li>
    <li><code>category_views</code> — category page view events: id, category_id, user_id, created_at.</li>
    <li><code>search_stats</code> — missed search terms: id, term, user_id, created_at.</li>
    <li><code>plans</code> — membership plans: id, name, slug, price, billing_cycle (monthly/yearly/lifetime), description, sort_order, level (1&ndash;4), active, created_at.</li>
    <li><code>subscriptions</code> — user subscriptions: id, user_id, plan_id, status (pending/active/cancelled/expired), starts_at, expires_at, payment_processor_id (FK), transaction_ref, created_at, updated_at.</li>
    <li><code>payment_processors</code> — configured gateways: id, name, provider (stripe/paypal/coinbase/square/venmo/cashapp/bitcoin), api_key, secret_key, webhook_secret, mode (test/live), currency, enabled, is_default, created_at, updated_at.</li>
    <li><code>login_attempts</code> — brute-force tracking: id, email, ip, attempted_at.</li>
</ul>

<h2 class="section-title">Payment processors</h2>
<ul>
    <li>The <strong>Payments</strong> tab (<code>/admin/payment-processors</code>, admin sidebar) manages the gateways visitors can pick when subscribing. Each processor has a name, provider, API key / secret key / optional webhook secret (stored as-is; always displayed masked via <code>PaymentProcessor::maskSecret()</code>), mode (test/live), currency, and an enabled switch.</li>
    <li>Exactly one processor can be <strong>default</strong> — enabling "Set as default" clears the flag on all others; it is pre-selected on the membership checkout form.</li>
    <li>Supported providers: Stripe, PayPal, Coinbase Commerce, Square, Venmo, Cash App, Bitcoin. Deleting a processor is blocked while subscriptions still reference it.</li>
    <li>Subscribing stores the chosen gateway in <code>subscriptions.payment_processor_id</code> plus a placeholder <code>transaction_ref</code> (<code>PENDING-&lt;random&gt;</code>). The admin Subscriptions page shows a Payment column; the member's My Membership page shows the gateway name next to each subscription.</li>
    <li>This is gateway <em>record-keeping only</em> — no money moves until real provider SDK/webhook integration is added.</li>
</ul>

<h2 class="section-title">Uploads &amp; storage</h2>
<ul>
    <li>Uploads are stored in <code>storage/uploads/</code> (owned by <code>www-data</code>) and served through <code>StorageController</code> at <code>/files/...</code>. Every file is also tracked in the <code>photos</code> table so shared files are stored once.</li>
    <li><strong>Three variants per image</strong> — the <em>original full-size file</em> (<code>/files/&lt;name&gt;</code>, kept byte-for-byte as uploaded), a <em>fast-loading web variant</em> (<code>web_&lt;name&gt;</code>, scaled to fit <code>uploads.web_max_width</code> = 1600px), and a <em>thumbnail</em> (<code>thumb_&lt;name&gt;</code>, 400&times;300, <code>?size=thumb</code>). <code>file_url($name, $size)</code> builds the URL: <code>''</code> = original, <code>'thumb'</code>, <code>'web'</code>. Deleting an orphan photo removes all three files (<code>Photo::deleteIfOrphan()</code>).</li>
    <li><strong>Variants are generated in a single pass</strong> — on upload, <code>create_image_variants()</code> produces the web variant and thumbnail in one ffmpeg invocation (one decode feeds both sizes via a <code>filter_complex</code> split), cutting large-photo processing from ~30&nbsp;s to ~3&nbsp;s. EXIF orientation is read in PHP and baked into the pixels with explicit transpose filters (ffmpeg ignores rotation metadata); sources already within the display cap are passed through untouched. GIF/PNG/WebP inputs — and any ffmpeg failure — fall back to a single-decode GD path (<code>_variants_gd()</code>) that derives the thumbnail from the web-sized copy instead of re-decoding the original.</li>
    <li><strong>Galleries show the fast-loading variant</strong> — <code>views/gallery/show.php</code> renders each image with <code>file_url($name, 'web')</code> in the grid. Each image or video opens its own in-page viewer. Images open <code>/images/{id}</code>: the page starts with the web variant and a <em>View full size</em> button swaps in the original. Videos open <code>/videos/{id}</code>: the original file plays in a centered player. Both pages share <code>views/partials/media_nav.php</code>, a Previous / Back / Next button row.</li>
    <li><strong>Photo editor</strong> (<code>views/admin/photo_edit.php</code>) — images only; videos are redirected to the video editor at <code>/admin/videos/{id}/edit</code>. Each image tool posts an <code>operation</code> to <code>/admin/photos/{id}/edit</code>. Image operations first normalize EXIF orientation, apply the edit in place with GD, then regenerate web variant + thumbnail. Thumbnail ops only rewrite the thumbnail. The video editor exposes the same three thumbnail ops (upload a custom thumbnail, capture a frame at a chosen second, or regenerate from the first second) via the <em>Thumbnail</em> button, submitted with fetch and a JSON reply.</li>
    <li><strong>Thumbnails</strong> live next to each upload as <code>thumb_&lt;name&gt;</code> and are served via <code>/files/&lt;name&gt;?size=thumb</code>. Gallery cards show the thumbnail of the first image or video in the gallery.</li>
    <li><strong>Thumbnail correctness</strong> — <code>create_thumbnail()</code> reads EXIF orientation (for JPEGs), swaps width/height for rotations 5&ndash;8, applies the orientation with GD, and center-crops to the 400&times;300 ratio. The crop scale uses <code>min(srcW/targetW, srcH/targetH)</code> so the aspect ratio is always preserved.</li>
    <li><strong>Gallery card</strong> (<code>views/partials/gallery_card.php</code>) shows the cover thumbnail, title, description, and a muted view-count line. Category chips sit in a <code>.card-cats</code> wrapper: when they don't fit on one row, a script collapses the list and adds a <em>Show more (N)</em> toggle.</li>
    <li><strong>Gallery card click target</strong> — every card opens its gallery at <code>/galleries/{id}</code> in the current tab, including video galleries. The gallery page dynamically renders image and video items with their appropriate viewers. Video covers retain a play badge, and category chips below each card still link to their category pages.</li>
    <li>Image thumbnails are created with GD (<code>create_thumbnail()</code>); video thumbnails are JPEG frame-grabs produced by <code>create_video_thumbnail()</code> using <strong>ffmpeg</strong> (<code>/usr/bin/ffmpeg</code> must be installed). Thumbnail responses are sniffed so a video's frame-grab is always delivered as <code>image/jpeg</code>.</li>
    <li><strong>Upload limit</strong> — each file may be up to <strong>10 GiB</strong>. The application limit is in <code>config/app.php</code> (<code>uploads.max_size</code>). Under PHP-FPM the <code>upload_max_filesize=10G</code> and <code>post_max_size=11G</code> values live in the FPM pool (<code>/etc/php/7.4/fpm/pool.d/www.conf</code>) — FPM does not honour <code>php_value</code> directives in <code>.htaccess</code>, so <code>public/.htaccess</code> no longer carries them. Large uploads also require sufficient free space in <code>storage/uploads/</code>.</li>
    <li><strong>Title images</strong> — the theme editor can upload a custom title image to <code>storage/uploads/</code>. The path is stored in the theme JSON file under the <code>_title_image</code> key and rendered via <code>Theme::titleImageUrl()</code> in both layouts.</li>
    <li><strong>Theme presets</strong> — saved as JSON files in <code>storage/themes/</code> (must be <code>www-data:www-data</code> owned). Each file contains: name, scope, colours, layout, title_image, created_at.</li>
    <li><strong>Database credentials</strong> — production credentials are kept in the non-public <code>.env</code> file at the project root and are loaded by <code>config/database.php</code>. Do not commit or copy <code>.env</code> into <code>public/</code>.</li>
</ul>

<h2 class="section-title">Server &amp; performance</h2>
<ul>
    <li><strong>PHP is served by PHP-FPM, not mod_php</strong> — Apache runs the <code>mpm_event</code> MPM and forwards <code>.php</code> requests to the PHP-FPM pool socket via <code>mod_proxy_fcgi</code> (<code>/etc/apache2/conf-available/php7.4-fpm.conf</code>; pool at <code>/etc/php/7.4/fpm/pool.d/www.conf</code>). This keeps Apache workers tiny and pools PHP processes so memory use is far lower than the old <code>mod_php</code> + <code>mpm_prefork</code> setup.</li>
    <li><strong>OPcache</strong> — enabled for the web SAPI with <code>validate_timestamps=1</code> and <code>revalidate_freq=2</code>, so code changes are picked up within ~2 seconds without an explicit reset (important because the Site Editor writes PHP templates at runtime).</li>
    <li><strong>mod_xsendfile for media</strong> — large originals and videos are streamed by Apache (<code>XSendFile On</code> + <code>XSendFilePath /var/www/gallery/storage</code> in the vhost) instead of being read through PHP, and the controller's Range handling is delegated to Apache so video seeking returns proper <code>206 Partial Content</code> without loading the file into a PHP worker.</li>
    <li><strong>MySQL tuning</strong> — a dedicated config (<code>/etc/mysql/mysql.conf.d/99-gallery-tuning.cnf</code>) sets <code>innodb_buffer_pool_size=256M</code> (fits the whole database in RAM), <code>innodb_flush_log_at_trx_commit=2</code>, <code>O_DIRECT</code>, larger temp/heap tables, and enables the slow-query log (<code>long_query_time=2</code>) at <code>/var/log/mysql/mysql-slow.log</code>.</li>
    <li><strong>Compression &amp; caching</strong> — <code>mod_deflate</code> compresses HTML/CSS/JS (a typical 57&nbsp;KB page becomes ~8.6&nbsp;KB), and <code>mod_expires</code>/<code>mod_headers</code> set long cache lifetimes on static assets under <code>/gallery/assets</code>.</li>
    <li><strong>N+1 elimination</strong> — the gallery home page (<code>GalleryController::index()</code>) bulk-loads every favourite category's galleries in one query via <code>Gallery::inCategories()</code> instead of running one query per favourite category, cutting a home-page load from ~78 SQL statements to ~20.</li>
</ul>
