<?php
/*
 * VibeStream admin panel - admin titles (Admin / Manager / CEO).
 * Put this file in the adminpanel folder. The owner sets a title on the Admins page (admins.php).
 * Titles are labels only: they do not change what an admin is allowed to do.
 * Each title also has a salary (VS_SALARIES). The salary is only shown to the owner.
 * Saved in adminpanel/data/admin-titles.json.
 */
const VS_TITLES = ['Admin', 'Manager', 'CEO'];

// Salary per title. Change the numbers here (and VS_CURRENCY for the symbol).
const VS_SALARIES = ['CEO' => 500, 'Manager' => 400, 'Admin' => 300];
const VS_CURRENCY = '$';

/** Salary for a title, or null when it has none (for example the Owner). */
function vs_salary_for(string $title): ?int
{
    return VS_SALARIES[$title] ?? null;
}

function vs_salary_fmt(?int $amount): string
{
    return $amount === null ? '-' : VS_CURRENCY . number_format($amount);
}

function vs_title_file(): string
{
    return __DIR__ . '/data/admin-titles.json';
}

/** lowercase username => ['name' => 'Sam', 'title' => 'Manager'] (only Manager / CEO are stored) */
function vs_title_all(): array
{
    $f = vs_title_file();
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : [];
    $out = [];
    foreach (is_array($d) ? $d : [] as $k => $row) {
        if (is_array($row) && in_array($row['title'] ?? '', ['Manager', 'CEO'], true) && trim((string)($row['name'] ?? '')) !== '') {
            $out[(string)$k] = ['name' => (string)$row['name'], 'title' => (string)$row['title']];
        }
    }
    return $out;
}

function vs_title_get(string $username): string
{
    return vs_title_all()[mb_strtolower(trim($username))]['title'] ?? 'Admin';
}

function vs_title_set(string $username, string $title): void
{
    $username = trim($username);
    if ($username === '' || !in_array($title, VS_TITLES, true)) {
        throw new RuntimeException('Choose a valid title.');
    }
    $all = vs_title_all();
    $key = mb_strtolower($username);
    if ($title === 'Admin') {
        unset($all[$key]);
    } else {
        $all[$key] = ['name' => $username, 'title' => $title];
    }
    $dir = __DIR__ . '/data';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the data folder.');
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    if (@file_put_contents(vs_title_file(), json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
        throw new RuntimeException('Could not save the title. Check folder permissions.');
    }
}