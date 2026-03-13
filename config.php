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
        defined('CLICKUP_CLIENT_ID')      || define('CLICKUP_CLIENT_ID',      $config['client_id']      ?? '');
        defined('CLICKUP_CLIENT_SECRET')   || define('CLICKUP_CLIENT_SECRET',  $config['client_secret']  ?? '');
        defined('CLICKUP_REDIRECT_URI')    || define('CLICKUP_REDIRECT_URI',   $config['redirect_uri']   ?? 'http://localhost:8080/oauth/callback.php');
    }
}

// Fall back to environment variables, then defaults
defined('CLICKUP_CLIENT_ID')      || define('CLICKUP_CLIENT_ID',      getenv('CLICKUP_CLIENT_ID')      ?: '');
defined('CLICKUP_CLIENT_SECRET')  || define('CLICKUP_CLIENT_SECRET',  getenv('CLICKUP_CLIENT_SECRET')  ?: '');
defined('CLICKUP_REDIRECT_URI')   || define('CLICKUP_REDIRECT_URI',   getenv('CLICKUP_REDIRECT_URI')   ?: 'http://localhost:8080/oauth/callback.php');

// ClickUp API base URL
defined('CLICKUP_API_BASE') || define('CLICKUP_API_BASE', 'https://api.clickup.com/api/v2');
defined('CLICKUP_AUTH_URL')  || define('CLICKUP_AUTH_URL',  'https://app.clickup.com/api');
