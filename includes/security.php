<?php
/**
 * Security headers for the Sonar application.
 */

/**
 * Send security headers for HTML pages.
 * Call this at the top of user-facing pages (dashboard, login, workspace).
 */
function send_security_headers(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' https:; connect-src 'self'");
}

/**
 * Send minimal security headers for API/JSON endpoints.
 */
function send_api_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
}
