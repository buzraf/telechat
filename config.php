<?php
// TeleChat Configuration
define('DB_HOST',     getenv('DB_HOST')     ?: 'localhost');
define('DB_PORT',     getenv('DB_PORT')     ?: '5432');
define('DB_NAME',     getenv('DB_NAME')     ?: 'telechat');
define('DB_USER',     getenv('DB_USER')     ?: 'postgres');
define('DB_PASS',     getenv('DB_PASS')     ?: '');
define('JWT_SECRET',  getenv('JWT_SECRET')  ?: 'telechat_secret_change_in_production');
define('APP_URL',     getenv('APP_URL')     ?: 'http://localhost:8080');
define('SMTP_HOST',   getenv('SMTP_HOST')   ?: 'smtp.gmail.com');
define('SMTP_PORT',   getenv('SMTP_PORT')   ?: '587');
define('SMTP_USER',   getenv('SMTP_USER')   ?: '');
define('SMTP_PASS',   getenv('SMTP_PASS')   ?: '');
define('SMTP_FROM',   getenv('SMTP_FROM')   ?: 'noreply@telechat.app');
define('APP_NAME',    'TeleChat');
define('VERSION',     '1.0.0');

// CORS Headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
