<?php
/**
 * Configuration for ClickUp OAuth Dashboard
 *
 * Priority: config.local.php > environment variables > defaults
 */

// Load local config if it exists (gitignored)
$localConfig = __DIR__ . '/config.local.php';
if (file_exists($localConfig)) {
    $config = require $localConfig;
    if (is_array($config)) {
        defined('CLICKUP_CLIENT_ID')       || define('CLICKUP_CLIENT_ID',       $config['client_id']      ?? '');
        defined('CLICKUP_CLIENT_SECRET')   || define('CLICKUP_CLIENT_SECRET',   $config['client_secret']  ?? '');
        defined('CLICKUP_REDIRECT_URI')    || define('CLICKUP_REDIRECT_URI',    $config['redirect_uri']   ?? 'http://localhost:8080/oauth/callback.php');
        // F01 — department head + weekly hours config
        defined('DEPARTMENT_HEAD_USER_ID') || define('DEPARTMENT_HEAD_USER_ID', (string)($config['department_head_user_id'] ?? ''));
        defined('WEEKLY_HOURS_PER_USER')   || define('WEEKLY_HOURS_PER_USER',   (array)($config['weekly_hours_per_user']    ?? []));
        defined('DEFAULT_WEEKLY_HOURS')    || define('DEFAULT_WEEKLY_HOURS',    (int)  ($config['default_weekly_hours']     ?? 40));
    }
}

// Fall back to environment variables, then defaults
defined('CLICKUP_CLIENT_ID')       || define('CLICKUP_CLIENT_ID',       getenv('CLICKUP_CLIENT_ID')      ?: '');
defined('CLICKUP_CLIENT_SECRET')   || define('CLICKUP_CLIENT_SECRET',   getenv('CLICKUP_CLIENT_SECRET')  ?: '');
defined('CLICKUP_REDIRECT_URI')    || define('CLICKUP_REDIRECT_URI',    getenv('CLICKUP_REDIRECT_URI')   ?: 'http://localhost:8080/oauth/callback.php');
defined('DEPARTMENT_HEAD_USER_ID') || define('DEPARTMENT_HEAD_USER_ID', getenv('DEPARTMENT_HEAD_USER_ID') ?: '');
defined('WEEKLY_HOURS_PER_USER')   || define('WEEKLY_HOURS_PER_USER',   []);
defined('DEFAULT_WEEKLY_HOURS')    || define('DEFAULT_WEEKLY_HOURS',    (int)(getenv('DEFAULT_WEEKLY_HOURS') ?: 40));

// ClickUp API base URL
defined('CLICKUP_API_BASE') || define('CLICKUP_API_BASE', 'https://api.clickup.com/api/v2');
defined('CLICKUP_AUTH_URL')  || define('CLICKUP_AUTH_URL',  'https://app.clickup.com/api');
