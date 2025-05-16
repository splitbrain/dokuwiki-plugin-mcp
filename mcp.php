<?php

use dokuwiki\Remote\JsonRpcServer;

if (!defined('DOKU_INC')) define('DOKU_INC', __DIR__ . '/../../../');

require_once(DOKU_INC . 'inc/init.php');
session_write_close();  //close session

header('Content-Type: application/json');

\dokuwiki\Logger::debug('MCP Request', file_get_contents('php://input'));


$server = new \dokuwiki\plugin\mcp\McpServer();
try {
    $result = $server->serve();
} catch (\Exception $e) {
    \dokuwiki\ErrorHandler::logException($e);
    $result = $server->returnError($e);
}

$result = json_encode($result, JSON_THROW_ON_ERROR);
\dokuwiki\Logger::debug('MCP Response', $result);
echo $result;
