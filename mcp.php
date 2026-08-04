<?php

use dokuwiki\ErrorHandler;
use dokuwiki\Logger;
use dokuwiki\plugin\mcp\McpServer;

if (!defined('DOKU_INC')) define('DOKU_INC', __DIR__ . '/../../../');

require_once(DOKU_INC . 'inc/init.php');
session_write_close();  //close session

$server = new McpServer();

Logger::debug('MCP Request', [
    'auth' => $server->authStatus(),
    'body' => file_get_contents('php://input'),
]);

try {
    $result = $server->serve();
} catch (\Throwable $e) {
    ErrorHandler::logException($e);
    $result = $server->returnError($e);
}

if ($result === null) {
    // a notification has no response, it is only acknowledged with an empty 202
    header_remove('Content-Type');
    ini_set('default_mimetype', '');
    http_status(202);
    $body = '';
} else {
    header('Content-Type: application/json');
    $body = json_encode($result, JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);
}

Logger::debug('MCP Response', ['status' => http_response_code(), 'body' => $body]);
echo $body;
