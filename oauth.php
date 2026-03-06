<?php

/**
 * OAuth2 Authorization Server for MCP
 *
 * Implements a minimal OAuth2 Authorization Code + PKCE flow so that clients
 * like Claude.ai (which only expose OAuth fields) can obtain a Bearer token
 * for the DokuWiki remote API.
 *
 * The user is presented with a form to enter their DokuWiki JWT token. That
 * token is then handed back to the client as an OAuth2 access_token.
 */

use dokuwiki\Logger;

if (!defined('DOKU_INC')) define('DOKU_INC', __DIR__ . '/../../../');
require_once(DOKU_INC . 'inc/init.php');
session_write_close();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Route POST requests
if ($method === 'POST') {
    switch ($action) {
        case 'authorize':
            handleAuthorizeSubmit();
            exit;
        case 'token':
            handleToken();
            exit;
        case 'register':
            handleRegister();
            exit;
    }
}

// Route GET requests
$baseUrl = rtrim(DOKU_URL, '/') . '/lib/plugins/mcp/';

switch ($action) {
    case 'resource-metadata':
        // RFC 9728 Protected Resource Metadata
        header('Content-Type: application/json');
        echo json_encode([
            'resource' => $baseUrl . 'mcp.php',
            'authorization_servers' => [$baseUrl . 'oauth.php'],
            'bearer_methods_supported' => ['header'],
        ], JSON_PRETTY_PRINT);
        break;

    case 'authorize':
        showAuthorizeForm();
        break;

    case 'token':
    case 'register':
        http_response_code(405);
        header('Allow: POST');
        break;

    default:
        // Authorization Server Metadata (RFC 8414)
        header('Content-Type: application/json');
        echo json_encode([
            'issuer' => $baseUrl . 'oauth.php',
            'authorization_endpoint' => $baseUrl . 'oauth.php?action=authorize',
            'token_endpoint' => $baseUrl . 'oauth.php?action=token',
            'registration_endpoint' => $baseUrl . 'oauth.php?action=register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'none'],
        ], JSON_PRETTY_PRINT);
        break;
}

// --- Temporary code storage ---------------------------------------------------

function getCodeStorePath()
{
    global $conf;
    $path = $conf['tmpdir'] . '/mcp_oauth/';
    if (!is_dir($path)) {
        mkdir($path, 0700, true);
    }
    return $path;
}

function storeCode($code, $data)
{
    $path = getCodeStorePath();
    // Purge codes older than 5 minutes
    foreach (glob($path . '*.json') as $file) {
        if (filemtime($file) < time() - 300) {
            @unlink($file);
        }
    }
    file_put_contents($path . $code . '.json', json_encode($data));
}

function retrieveCode($code)
{
    $code = preg_replace('/[^a-zA-Z0-9_-]/', '', $code);
    $file = getCodeStorePath() . $code . '.json';
    if (!file_exists($file) || filemtime($file) < time() - 300) {
        if (file_exists($file)) @unlink($file);
        return null;
    }
    $data = json_decode(file_get_contents($file), true);
    @unlink($file); // single use
    return $data;
}

// --- Authorize ----------------------------------------------------------------

function showAuthorizeForm()
{
    global $conf;

    $redirect_uri           = $_GET['redirect_uri'] ?? '';
    $state                  = $_GET['state'] ?? '';
    $code_challenge         = $_GET['code_challenge'] ?? '';
    $code_challenge_method  = $_GET['code_challenge_method'] ?? '';

    $title                  = htmlspecialchars($conf['title']);
    $redirect_uri_h         = htmlspecialchars($redirect_uri);
    $state_h                = htmlspecialchars($state);
    $code_challenge_h       = htmlspecialchars($code_challenge);
    $code_challenge_method_h = htmlspecialchars($code_challenge_method);

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Authorize MCP Access &ndash; {$title}</title>
    <style>
        body { font-family: -apple-system, system-ui, sans-serif; max-width: 420px; margin: 80px auto; padding: 0 20px; background: #f5f5f5; color: #111; }
        .card { background: #fff; border-radius: 12px; padding: 32px; box-shadow: 0 2px 8px rgba(0,0,0,.1); }
        h1 { font-size: 1.25em; margin-top: 0; }
        label { display: block; margin-top: 16px; font-weight: 600; font-size: .9em; }
        input[type=password] { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; margin-top: 4px; box-sizing: border-box; font-size: .95em; }
        button { margin-top: 24px; width: 100%; padding: 12px; background: #2563eb; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 1em; font-weight: 600; }
        button:hover { background: #1d4ed8; }
        .hint { color: #666; font-size: .85em; margin-top: 8px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Authorize MCP Access to&nbsp;{$title}</h1>
        <p class="hint">Enter your DokuWiki API token from <a href="/start?do=profile" target="_blank">here</a> to grant the MCP client access.</p>
        <form method="POST" action="">
            <input type="hidden" name="redirect_uri" value="{$redirect_uri_h}">
            <input type="hidden" name="state" value="{$state_h}">
            <input type="hidden" name="code_challenge" value="{$code_challenge_h}">
            <input type="hidden" name="code_challenge_method" value="{$code_challenge_method_h}">
            <label for="token">API Token</label>
            <input type="password" id="token" name="token" required placeholder="Paste your JWT here">
            <button type="submit">Authorize</button>
        </form>
    </div>
</body>
</html>
HTML;
}

function handleAuthorizeSubmit()
{
    $token          = $_POST['token'] ?? '';
    $redirect_uri   = $_POST['redirect_uri'] ?? '';
    $state          = $_POST['state'] ?? '';
    $code_challenge = $_POST['code_challenge'] ?? '';
    $code_challenge_method = $_POST['code_challenge_method'] ?? '';

    if ($token === '' || $redirect_uri === '') {
        http_response_code(400);
        echo 'Missing token or redirect_uri';
        return;
    }

    $code = bin2hex(random_bytes(32));

    storeCode($code, [
        'token'                 => $token,
        'redirect_uri'          => $redirect_uri,
        'code_challenge'        => $code_challenge,
        'code_challenge_method' => $code_challenge_method,
    ]);

    $params = ['code' => $code];
    if ($state !== '') {
        $params['state'] = $state;
    }

    $glue = (strpos($redirect_uri, '?') !== false) ? '&' : '?';
    header('Location: ' . $redirect_uri . $glue . http_build_query($params, '', '&'));
    exit;
}

// --- Token --------------------------------------------------------------------

function handleToken()
{
    header('Content-Type: application/json');

    // Support both form-encoded and JSON bodies
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
    } else {
        $input = $_POST;
    }

    $grant_type    = $input['grant_type'] ?? '';
    $code          = $input['code'] ?? '';
    $code_verifier = $input['code_verifier'] ?? '';
    $redirect_uri  = $input['redirect_uri'] ?? '';

    if ($grant_type !== 'authorization_code') {
        http_response_code(400);
        echo json_encode(['error' => 'unsupported_grant_type']);
        return;
    }

    $data = retrieveCode($code);
    if ($data === null) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_grant', 'error_description' => 'Invalid or expired authorization code']);
        return;
    }

    // Verify redirect_uri matches
    if ($redirect_uri !== '' && $data['redirect_uri'] !== $redirect_uri) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_grant', 'error_description' => 'redirect_uri mismatch']);
        return;
    }

    // PKCE verification
    if ($data['code_challenge'] !== '') {
        if ($code_verifier === '') {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_grant', 'error_description' => 'code_verifier required']);
            return;
        }

        $expected = rtrim(strtr(base64_encode(hash('sha256', $code_verifier, true)), '+/', '-_'), '=');
        if (!hash_equals($data['code_challenge'], $expected)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_grant', 'error_description' => 'PKCE verification failed']);
            return;
        }
    }

    echo json_encode([
        'access_token' => $data['token'],
        'token_type'   => 'Bearer',
    ]);
}

// --- Dynamic Client Registration (RFC 7591) -----------------------------------

function handleRegister()
{
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    header('Content-Type: application/json');
    http_response_code(201);

    echo json_encode([
        'client_id'                  => bin2hex(random_bytes(16)),
        'client_name'                => $input['client_name'] ?? 'MCP Client',
        'redirect_uris'              => $input['redirect_uris'] ?? [],
        'grant_types'                => ['authorization_code'],
        'response_types'             => ['code'],
        'token_endpoint_auth_method' => 'none',
    ], JSON_PRETTY_PRINT);
}
