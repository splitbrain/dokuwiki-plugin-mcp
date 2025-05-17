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
try {
    $result = $server->serve();
} catch (\Exception $e) {
    ErrorHandler::logException($e);
    $result = $server->returnError($e);
}

$result = json_encode($result, JSON_THROW_ON_ERROR);
Logger::debug('MCP Response', $result);
echo $result;
