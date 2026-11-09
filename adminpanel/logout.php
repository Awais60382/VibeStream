<?php
    session_start();
    require_once __DIR__ . '/checkin-lib.php';
    if (function_exists('vs_checkin_mark_offline')) {
        vs_checkin_mark_offline();
    }
    session_unset();
    session_destroy();
    echo "
        <script>
        window.open('login.php','_self')
        </script>
    ";
?>
