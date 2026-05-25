<?php
/**
 * csrf.php - CROSS-SITE REQUEST FORGERY GUARD
 * Melindungi form dan eksekusi URL dari injeksi peretas.
 */
function csrf_token() {
    if(empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_validate($token) {
    return hash_equals($_SESSION['_csrf'] ?? '', $token);
}
?>