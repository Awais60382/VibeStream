# VibeStream
this is my project

## Google sign-in

The login page uses Google Identity Services and verifies each credential on the server. Google sign-in is disabled by default and displays **Coming soon** until configured. Set the `GOOGLE_OAUTH_CLIENT_ID` and `GOOGLE_OAUTH_ENABLED=1` environment variables on the server only after adding the site's origin in Google Cloud under **Authorized JavaScript origins**. Origins include scheme, hostname, and port, but not a path; for example, `http://127.0.0.1:8765` and `http://localhost` are separate development origins. For production use the exact HTTPS domain.

Google sign-in requires the `users` table to have `username`, `email`, and `role` columns. New Google accounts receive the `user` role and a random unusable password when the table has a `password`, `pass`, or `user_password` column.

## Installable web app

VibeStream includes a dynamic web app manifest and a service worker. The worker only caches the static app shell and offline page; it never caches dynamic pages or account data. Dark/light appearance is available across the site and admin panels. The owner can set the default appearance; individual choices are remembered in each browser and are not overwritten by later default changes. HTTPS is required for service workers outside of localhost.

## App settings

The site owner can change the app logo, name, description, and default appearance from **Admin Panel → App settings**. Logo changes publish immediately across the site and installable app icon, and an in-app notification is shown in the admin header. The PHP process must be able to write to `uploads/site-branding`. Admin **Account settings** are limited to each admin's own account; site-wide settings are denied server-side to other admins. Set `VIBESTREAM_OWNER_EMAIL` on the server to the owner's email; if unset, the owner address defaults to `muhammadawaiscom0@gmail.com`.

## Channel subscriptions

Signed-in users can see followed creators and unsubscribe from **Subscriptions** in the main navigation. Subscription relationships are stored per user in `user_subscriptions` and follow them across sessions and devices. For databases created before this feature, run `database/migrations/20261008_create_user_subscriptions.sql` once.

## Publishing

Deploy the PHP application to PHP 8+ hosting with MySQL/MariaDB and HTTPS; this is a server-rendered PHP app, not a static-only site. Upload the project files without changing their case (including `Signup.php`), copy `connection.example.php` to `connection.php` on the host (`connection.php` is intentionally excluded from Git), import the existing `vibestream` database, and configure the hosting provider's PHP environment with `DB_HOST`, `DB_PORT` (optional; defaults to `3306`), `DB_NAME`, `DB_USER`, and `DB_PASSWORD`. For databases created before video dislikes were supported, run `database/migrations/20261007_add_video_dislikes.sql` once to enable dislike voting; likes continue to work without that optional column. Run `database/migrations/20261008_create_user_subscriptions.sql` once to enable persistent channel subscriptions. Configure the production Google OAuth client ID and enable Google sign-in only after allowlisting the production origin. Set `YOUTUBE_API_KEY` on the server if YouTube metadata lookups are needed. Serve the project from its root path (or adjust manifest and worker paths for a subdirectory) so the manifest scope and service-worker scope include the app. Test sign-in, uploads, and the install prompt on the production HTTPS domain before release.
