<?php
if (!defined('AVATAR_DIR')) {
    define('AVATAR_DIR', __DIR__ . '/uploads/avatars');
}

if (!function_exists('avatar_key')) {

    function avatar_key($username) {
        return md5(mb_strtolower(trim((string)$username)));
    }


    function avatar_path($username) {
        if (trim((string)$username) === '') return '';
        foreach (['jpg', 'png', 'webp', 'gif'] as $ext) {
            $p = AVATAR_DIR . '/' . avatar_key($username) . '.' . $ext;
            if (is_file($p)) return $p;
        }
        return '';
    }


    function avatar_url($username) {
        $p = avatar_path($username);
        if ($p === '') return '';

        $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $dir  = str_replace('\\', '/', AVATAR_DIR);

        if ($root !== '' && stripos($dir, $root) === 0) {
            $base = substr($dir, strlen($root));
        } else {
            $base = 'uploads/avatars';
        }
        return $base . '/' . basename($p) . '?v=' . filemtime($p);
    }

    function avatar_html($username, $size = 36, $extraClass = '') {
        $username = trim((string)$username);
        $px  = (int)$size;
        $cls = htmlspecialchars('avatar ' . $extraClass);
        $box = 'width:' . $px . 'px;height:' . $px . 'px;border-radius:50%;flex-shrink:0;';
        $url = avatar_url($username);

        if ($url !== '') {
            return '<img class="' . $cls . '" src="' . htmlspecialchars($url) . '" alt="" style="' . $box . 'object-fit:cover;display:block;">';
        }

        $letter = $username !== '' ? mb_strtoupper(mb_substr($username, 0, 1)) : 'G';
        $hue = function_exists('avatar_hue') ? (int)avatar_hue($username) : 210;
        return '<span class="' . $cls . '" style="' . $box . 'display:inline-flex;align-items:center;justify-content:center;'
             . 'font-weight:700;color:#fff;font-size:' . round($px * 0.42) . 'px;background:hsl(' . $hue . ' 55% 42%);">'
             . htmlspecialchars($letter) . '</span>';
    }


    function avatar_delete($username) {
        foreach (['jpg', 'png', 'webp', 'gif'] as $ext) {
            $p = AVATAR_DIR . '/' . avatar_key($username) . '.' . $ext;
            if (is_file($p)) @unlink($p);
        }
    }

    function avatar_save($username, $file) {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return 'Please choose an image first.';
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            return 'That image is too large (max 2 MB).';
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return 'Upload failed, please try again.';
        }
        if ($file['size'] > 2 * 1024 * 1024) {
            return 'That image is too large (max 2 MB).';
        }

        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $mime = '';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $mime = (string)finfo_file($fi, $file['tmp_name']);
            finfo_close($fi);
        }
        if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) {
            return 'Only JPG, PNG, WEBP or GIF images are allowed.';
        }

        if (!is_dir(AVATAR_DIR) && !@mkdir(AVATAR_DIR, 0775, true)) {
            return 'Could not create the uploads/avatars folder.';
        }

        avatar_delete($username);
        $dest = AVATAR_DIR . '/' . avatar_key($username) . '.' . $allowed[$mime];
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return 'Could not save the image.';
        }
        return '';
    }
}
