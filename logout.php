<?php
    session_start();
    session_unset();
    session_destroy();
    echo "
        <script>
            alert('Logout Successfully')
            window.open('./','_self')
        </script>
    ";
?>