<?php
/**
 * CSRF Protection Helper
 *
 * Usage:
 *   In forms:   echo csrfField();
 *   On submit:  if (!validateCSRF()) { die('Invalid request'); }
 */

function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . generateCSRFToken() . '">';
}

function validateCSRF() {
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        return false;
    }
    return true;
}
