<?php

use dokuwiki\ErrorHandler;
use dokuwiki\Logger;
use dokuwiki\plugin\mcp\McpServer;

if (!defined('DOKU_INC')) define('DOKU_INC', __DIR__ . '/../../../');

require_once(DOKU_INC . 'inc/init.php');
session_write_close();  //close session

header('Content-Type: application/json');

Logger::debug('MCP Request', file_get_contents('php://input'));

$server = new McpServer();

// When unauthenticated, return 401 with OAuth metadata so that
// MCP clients (like Claude.ai) can initiate the OAuth2 authorization flow.
if (empty($_SERVER['REMOTE_USER'])) {
    $baseUrl = rtrim(DOKU_URL, '/') . '/lib/plugins/mcp/';
    $metadataUrl = $baseUrl . 'oauth.php?action=resource-metadata';

    http_response_code(401);
    header('WWW-Authenticate: Bearer resource_metadata="' . $metadataUrl . '"');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized. Use OAuth2 to obtain a Bearer token.']);
    exit;
}

try {
    $result = $server->serve();
} catch (\Exception $e) {
    ErrorHandler::logException($e);
    $result = $server->returnError($e);
}

$result = json_encode($result, JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);
Logger::debug('MCP Response', $result);
echo $result;
