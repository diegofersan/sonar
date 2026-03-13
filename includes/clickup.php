<?php
/**
 * ClickUp API helper functions -- pure PHP, no external dependencies.
 *
 * Uses curl when available, falls back to file_get_contents with stream context.
 */

require_once __DIR__ . '/../config.php';

// ---------------------------------------------------------------------------
// Low-level HTTP helpers
// ---------------------------------------------------------------------------

/**
 * Perform an HTTP request and return the decoded JSON response.
 *
 * @param string      $url     Full URL
 * @param string      $method  HTTP method (GET, POST, ...)
 * @param array|null  $data    Body payload (will be JSON-encoded for POST/PUT)
 * @param string|null $token   Bearer token for Authorization header
 *
 * @return array{ok: bool, status: int, body: mixed, error: string|null}
 */
function clickup_http(string $url, string $method = 'GET', ?array $data = null, ?string $token = null): array
{
    $headers = ['Content-Type: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: ' . $token;
    }

    if (function_exists('curl_init')) {
        return clickup_http_curl($url, $method, $data, $headers);
    }

    return clickup_http_stream($url, $method, $data, $headers);
}

/**
 * HTTP via curl extension.
 */
function clickup_http_curl(string $url, string $method, ?array $data, array $headers): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CUSTOMREQUEST  => $method,
    ]);

    if ($data !== null && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response   = curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError  = curl_error($ch);
    // curl_close is deprecated in PHP 8.5+ (no-op since 8.0)

    if ($response === false) {
        return ['ok' => false, 'status' => 0, 'body' => null, 'error' => $curlError];
    }

    $decoded = json_decode($response, true);

    return [
        'ok'     => $statusCode >= 200 && $statusCode < 300,
        'status' => $statusCode,
        'body'   => $decoded,
        'error'  => $statusCode >= 400 ? ($decoded['err'] ?? $decoded['error'] ?? 'HTTP ' . $statusCode) : null,
    ];
}

/**
 * HTTP via file_get_contents + stream context (fallback when curl is unavailable).
 */
function clickup_http_stream(string $url, string $method, ?array $data, array $headers): array
{
    $opts = [
        'http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'timeout'       => 30,
            'ignore_errors' => true,  // don't throw on 4xx/5xx
        ],
    ];

    if ($data !== null && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
        $opts['http']['content'] = json_encode($data);
    }

    $context  = stream_context_create($opts);
    $response = @file_get_contents($url, false, $context);

    // Parse status code from response headers
    $statusCode = 0;
    $responseHeaders = http_get_last_response_headers();
    if (!empty($responseHeaders[0]) && preg_match('/\d{3}/', $responseHeaders[0], $m)) {
        $statusCode = (int) $m[0];
    }

    if ($response === false) {
        return ['ok' => false, 'status' => $statusCode, 'body' => null, 'error' => 'Request failed'];
    }

    $decoded = json_decode($response, true);

    return [
        'ok'     => $statusCode >= 200 && $statusCode < 300,
        'status' => $statusCode,
        'body'   => $decoded,
        'error'  => $statusCode >= 400 ? ($decoded['err'] ?? $decoded['error'] ?? 'HTTP ' . $statusCode) : null,
    ];
}

// ---------------------------------------------------------------------------
// ClickUp-specific helpers
// ---------------------------------------------------------------------------

/**
 * Exchange an authorization code for an access token.
 *
 * @return array{ok: bool, token: string|null, error: string|null}
 */
function clickup_exchange_token(string $code): array
{
    $url  = CLICKUP_API_BASE . '/oauth/token';
    $data = [
        'client_id'     => CLICKUP_CLIENT_ID,
        'client_secret' => CLICKUP_CLIENT_SECRET,
        'code'          => $code,
    ];

    $result = clickup_http($url, 'POST', $data);

    if ($result['ok'] && !empty($result['body']['access_token'])) {
        return ['ok' => true, 'token' => $result['body']['access_token'], 'error' => null];
    }

    return [
        'ok'    => false,
        'token' => null,
        'error' => $result['error'] ?? 'Failed to exchange token',
    ];
}

/**
 * Authenticated GET request to the ClickUp API.
 *
 * @param string $endpoint  Path relative to API base (e.g. "/team")
 * @param string $token     Access token
 */
function clickup_api_get(string $endpoint, string $token): array
{
    $url = CLICKUP_API_BASE . '/' . ltrim($endpoint, '/');
    return clickup_http($url, 'GET', null, $token);
}

/**
 * Authenticated POST request to the ClickUp API.
 *
 * @param string $endpoint  Path relative to API base
 * @param array  $data      Request body
 * @param string $token     Access token
 */
function clickup_api_post(string $endpoint, array $data, string $token): array
{
    $url = CLICKUP_API_BASE . '/' . ltrim($endpoint, '/');
    return clickup_http($url, 'POST', $data, $token);
}

/**
 * Fetch the authenticated user's workspaces (teams).
 */
function clickup_get_workspaces(string $token): array
{
    return clickup_api_get('/team', $token);
}

/**
 * Fetch the authenticated user's profile.
 */
function clickup_get_user(string $token): array
{
    return clickup_api_get('/user', $token);
}
