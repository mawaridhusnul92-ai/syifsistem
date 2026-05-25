<?php
/**
 * csrf.php - CROSS-SITE REQUEST FORGERY GUARD
 * Dihasilkan otomatis oleh Sovereign Auto-Heal Engine
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