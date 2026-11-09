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

Signed-in users can see followed creators and unsubscribe from **Subscriptions** in the main navigation. The page can filter subscribed creators who uploaded within the last 7 days, as well as sort the list. Subscription relationships are stored per user in `user_subscriptions` and follow them across sessions and devices. For databases created before this feature, run `database/migrations/20261008_create_user_subscriptions.sql` once.

## Continue watching

Signed-in viewers can resume unfinished videos from the **Continue watching** section on the home page or from **Watch history**. Playback position is saved to the viewer's watch history and resumes automatically on the next visit. Pausing watch history also stops playback progress from being recorded. The history table adds its progress columns automatically; no separate migration is required.

## Premium benefits

Active Premium and Premium Plus accounts do not receive configured ad code on the home feed, video watch pages, or Shorts. They can also view private Creator Analytics for their own published content. Analytics reports recorded video/Short page opens, current likes and subscribers; it does not claim unique viewers or watch time. Playlist, Watch Later, download, VIP badge, and daily upload benefits are enforced by the application.

The upload form saves unfinished draft details in the current browser, with file recovery when browser storage supports it. Large files may exceed browser storage limits; in that case the draft details remain saved and the file must be selected again.

## Playlists and discovery

Signed-in users can create private or public playlists and save videos to them from a video's watch page. Run `database/migrations/20261016_create_playlists.sql` once to enable persistent playlists. Public creator channel pages are available from creator links on the home feed and watch pages. The home feed offers **For you**, **Latest**, and **Popular** ordering; **For you** prioritizes followed creators when subscription data is available.

## Publishing

Deploy the PHP application to PHP 8+ hosting with MySQL/MariaDB and HTTPS; this is a server-rendered PHP app, not a static-only site. Upload the project files without changing their case (including `Signup.php`), copy `connection.example.php` to `connection.php` on the host (`connection.php` is intentionally excluded from Git), import the existing `vibestream` database, and configure the hosting provider's PHP environment with `APP_ENV=production`, `DB_HOST`, `DB_PORT` (optional; defaults to `3306`), `DB_NAME`, `DB_USER`, and `DB_PASSWORD`. Production must use a dedicated non-root database account with a non-empty password; the application refuses to start with root, missing, or passwordless production credentials. Error display is disabled by default; keep `APP_DEBUG` unset or false in production, and set `display_errors=Off` and `display_startup_errors=Off` in the hosting provider's PHP configuration. `APP_DEBUG=true` enables page error display only outside production and should be used only for local development. For databases created before video dislikes were supported, run `database/migrations/20261007_add_video_dislikes.sql` once to enable dislike voting; likes continue to work without that optional column. Run `database/migrations/20261008_create_user_subscriptions.sql` once to enable persistent channel subscriptions and `database/migrations/20261016_create_playlists.sql` once to enable playlists. Configure the production Google OAuth client ID and enable Google sign-in only after allowlisting the production origin. Set `YOUTUBE_API_KEY` on the server if YouTube metadata lookups are needed. Serve the project from its root path (or adjust manifest and worker paths for a subdirectory) so the manifest scope and service-worker scope include the app. Test sign-in, uploads, and the install prompt on the production HTTPS domain before release.

## Admin access roles

The site owner can assign roles to admins from **Admin panel → Admins → Set access**. Unassigned admins keep their existing full admin access. **Content Moderator** can manage videos, shorts, categories, and reports; **Support** can manage users, profiles, and messages; **Finance** can manage Premium requests and view profit/loss. **Full Admin** restores the existing full admin access. Backups and payroll remain owner-only and are not changed by these roles.

## Admin chat calls

Admin one-to-one calls work with the built-in STUN server. For better connectivity behind restrictive firewalls and NATs, configure a TURN service that supports Coturn-style REST credentials and set `VIBESTREAM_TURN_URLS` to a comma-separated list of `turn:` or `turns:` URLs and `VIBESTREAM_TURN_SHARED_SECRET` to that service's shared secret. Keep the secret in the PHP/server environment only; the application generates one-hour, user-specific credentials for the browser. Example URL values: `turn:turn.example.com:3478?transport=udp,turns:turn.example.com:5349?transport=tcp`. The TURN service must be separately deployed and configured with the same shared secret, and its relay ports must be reachable from clients. Do not put static TURN usernames, passwords, or the shared secret in JavaScript or source control. The Messages page also shows missed one-to-one calls from the last 30 days.

## Backup integrity

The Admin Panel → Backups page can download a SHA-256 checksum beside each backup. After copying a backup and its `.sha256` file to a Windows computer, run `Get-FileHash .\vibestream-backup-YYYY-MM-DD_HHMMSS.zip -Algorithm SHA256` with the actual backup filename; its `Hash` value should match the first value in the checksum file. The **Check** action also compares the backup with its saved checksum when backup history is available, in addition to checking that the archive and SQL are readable.