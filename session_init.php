<?php
/**
 * Session Initialization
 * Sets the session save path to avoid Permission denied errors on Windows.
 * Include this file instead of calling session_start() directly.
 */
if (session_status() === PHP_SESSION_NONE) {
    $sessDir = __DIR__ . '/sessions';
    if (is_dir($sessDir) && is_writable($sessDir)) {
        ini_set('session.save_path', $sessDir);
    }
    session_start();
}
