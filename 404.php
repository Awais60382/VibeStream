<?php
http_response_code(404);
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/page-loader-render.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#080304">
    <title>Page not found | VibeStream</title>
    <style>
        :root { color-scheme: dark; font-family: Inter, "Segoe UI", Arial, sans-serif; background:#080304; color:#fff; }
        * { box-sizing:border-box; }
        body {
            min-height:100vh; margin:0; display:grid; place-items:center; padding:24px;
            background:radial-gradient(ellipse at 50% 35%,rgba(190,12,24,.2),transparent 42%),#080304;
        }
        main { width:min(620px,100%); text-align:center; }
        .mark { display:inline-grid; place-items:center; width:72px; height:72px; margin-bottom:22px; border:1px solid rgba(255,54,64,.55); border-radius:22px; background:linear-gradient(145deg,#ff343c,#8f090f); box-shadow:0 0 34px rgba(255,26,26,.28); }
        .mark svg { width:42px; height:42px; }
        .code { display:block; color:#ff3038; font-size:clamp(88px,20vw,148px); font-weight:900; line-height:.9; letter-spacing:-.08em; text-shadow:0 0 42px rgba(255,26,26,.3); }
        h1 { margin:22px 0 10px; font-size:clamp(24px,5vw,36px); letter-spacing:-.04em; }
        p { margin:0 auto; max-width:440px; color:#b9a9aa; font-size:16px; line-height:1.7; }
        .actions { display:flex; flex-wrap:wrap; justify-content:center; gap:12px; margin-top:28px; }
        a { display:inline-flex; min-height:46px; align-items:center; justify-content:center; gap:9px; padding:0 20px; border:1px solid rgba(255,54,64,.45); border-radius:999px; color:#fff; font-size:14px; font-weight:750; text-decoration:none; transition:transform .18s,box-shadow .18s,background .18s; }
        a:hover { transform:translateY(-2px); box-shadow:0 0 22px rgba(255,26,26,.25); }
        a.primary { border-color:#ff343c; background:linear-gradient(135deg,#ff343c,#a50a11); }
        a.secondary { background:#120809; }
        a:focus-visible { outline:3px solid #ff7076; outline-offset:3px; }
    </style>
    <?php vs_page_loader_assets(); ?>
</head>
<body>
    <?php vs_page_loader_markup(); ?>
    <main>
        <div class="mark" aria-hidden="true">
            <svg viewBox="0 0 100 100" fill="none"><path d="M22 13c-5-3-11 0-11 6v62c0 6 6 9 11 6l62-31c5-3 5-9 0-12L22 13Z" fill="#fff"/><path d="M40 36v28l25-14-25-14Z" fill="#d10d18"/></svg>
        </div>
        <span class="code" aria-label="Error 404">404</span>
        <h1>This page went off the air</h1>
        <p>We couldn’t find the page you were looking for. It may have moved, or the link may be incorrect.</p>
        <div class="actions">
            <a class="primary" href="index.php">Return to VibeStream</a>
        </div>
    </main>
</body>
</html>
