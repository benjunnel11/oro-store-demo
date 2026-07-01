<?php
// Network auth disabled for demo deployment
session_start();

// Access guard — limit concurrent visitors + rate limit
require_once __DIR__ . '/access_guard.php';
return;