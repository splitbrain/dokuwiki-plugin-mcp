<?php

namespace dokuwiki\plugin\mcp;

use dokuwiki\Remote\AccessDeniedException;
use dokuwiki\Remote\JsonRpcServer;
use dokuwiki\Remote\RemoteException;

/**
 * Implementation of the Model Context Protocol (MCP) server
 *
 * This assumes the streaming HTTP transport. It's a thin wrapper around the JsonRpcServer
 */
class McpServer extends JsonRpcServer
{
    /** @var mixed The id of the current request, kept to correlate error responses with it */
    protected $requestId;

    /** @inheritdoc */
    public function call($methodname, $args)
    {
        switch ($methodname) {
            case 'initialize':
                return $this->mcpInitialize();
            case 'tools/list':
                return $this->mcpToolsList();
            case 'tools/call':
                return $this->mcpToolsCall($args);
            case 'ping':
            case 'notifications/initialized':
            case 'notifications/cancelled':
                return $this->mcpNOP($args);
            default:
                return parent::call($methodname, $args);
        }
    }

    /** @inheritdoc */
    protected function createResponse($data)
    {
        $this->requestId = $data['id'] ?? null;
        return parent::createResponse($data);
    }

    /**
     * Create an error response
     *
     * Every error is correlated with the request it belongs to, so clients are able to report
     * it. An access error only ever gets here when nobody is authenticated, because a tool call
     * handles a denied user itself, so it explains the state and challenges the client.
     *
     * @param \Throwable $exception
     * @return array
     */
    public function returnError($exception)
    {
        global $conf;

        if ($exception instanceof AccessDeniedException) {
            http_status(401);
            header('WWW-Authenticate: Bearer realm="' . str_replace(['\\', '"'], '', $conf['title']) . '"');

            $exception = new RemoteException(
                $this->explain($exception->getMessage()),
                $exception->getCode() ?: -32604,
                $exception
            );
        }

        $return = parent::returnError($exception);
        $return['jsonrpc'] = '2.0';
        $return['id'] = $this->requestId;
        return $return;
    }

    /**
     * Handle the MCP call `initialize`
     *
     * @link https://modelcontextprotocol.io/specification/2025-03-26/basic/lifecycle#initialization
     * @return array
     */
    protected function mcpInitialize()
    {
        global $conf;
        /** @var \helper_plugin_mcp $helper */
        $helper = plugin_load('helper', 'mcp');
        $info = $helper->getInfo();

        return [
            "protocolVersion" => "2025-03-26",
            "capabilities" => [
                // FIXME it might be possible to make pages and media available as resources
                "tools" => ["listChanged" => false]
            ],
            "serverInfo" => [
                "name" => "DokuWiki MCP",
                "version" => $info['date'],
            ],
            "instructions" => sprintf(
                "Access and interact with the DokuWiki instance called '%s'. %s",
                $conf['title'],
                $this->authStatus()
            ),
        ];
    }

    /**
     * Handle the MCP call `tools/list`
     *
     * @link https://modelcontextprotocol.io/specification/2025-03-26/server/tools#listing-tools
     * @return array
     */
    protected function mcpToolsList()
    {
        return [
            "tools" => (new SchemaGenerator())->getTools()
        ];
    }

    /**
     * Handle the MCP call `tools/call`
     *
     * A tool that fails is reported as a tool result, so the model sees what went wrong and
     * can act on it. Only a request the server cannot make sense of raises a protocol error.
     *
     * @link https://modelcontextprotocol.io/specification/2025-03-26/server/tools#calling-tools
     * @param array $args
     * @return array
     * @throws AccessDeniedException when no user is authenticated
     * @throws RemoteException when no such tool exists
     */
    protected function mcpToolsCall($args)
    {
        global $INPUT;
        $name = $args['name'] ?? '';

        // Some LLMs (e.g. Claude) don't allow underscores in method names, so we replace them with dots.
        // We have to convert them back to underscores for the actual call.
        $method = str_replace('_', '.', $name);

        if (!isset($this->remote->getMethods()[$method])) {
            throw new RemoteException(sprintf('There is no tool called %s', $name), -32602);
        }

        try {
            $result = $this->remote->call($method, $args['arguments'] ?? []);
        } catch (\Throwable $e) {
            // missing credentials are for the client to fix, anything else the model can work around
            if ($e instanceof AccessDeniedException && $INPUT->server->str('REMOTE_USER') === '') throw $e;
            return $this->mcpToolResult($this->explain($e->getMessage()), true);
        }

        return $this->mcpToolResult($result);
    }

    /**
     * Wrap the outcome of a tool call as an MCP tool result
     *
     * @param mixed $value The value the tool returned or, for a failure, the reason it failed
     * @param bool $isError Whether the call failed
     * @return array
     */
    protected function mcpToolResult($value, $isError = false)
    {
        # MCP only supports Text, Image and Audio. Complex types will be returned as JSON.
        // FIXME: we could support image and audio in the core.getMedia call
        return [
            "content" => [
                [
                    "type" => "text",
                    "text" => is_scalar($value) ? (string)$value : json_encode($value, JSON_PRETTY_PRINT)
                ]
            ],
            "isError" => $isError,
        ];
    }

    /**
     * Handle the MCP calls that only need to be acknowledged, but do not require any response.
     *
     * @return object
     */
    protected function mcpNOP()
    {
        return (object)[];
    }

    /**
     * Describe the authentication state of the current request
     *
     * DokuWiki authenticates a request once while initializing, long before any MCP method is
     * dispatched. This only reports the outcome, so a caller can say whether credentials were
     * rejected or never sent in the first place.
     *
     * @return string
     */
    public function authStatus()
    {
        global $INPUT;

        $user = $INPUT->server->str('REMOTE_USER');
        if ($user !== '') return sprintf("You are authenticated as '%s'.", $user);

        // the token login prefers the custom header over the Authorization one
        $header = '';
        if ($INPUT->server->str('HTTP_AUTHORIZATION') !== '') $header = 'Authorization';
        if ($INPUT->server->str('HTTP_X_DOKUWIKI_TOKEN') !== '') $header = 'X-DOKUWIKI-TOKEN';

        if ($header !== '') {
            return sprintf(
                'The credentials sent in the %s header were not accepted. Any tool call that needs ' .
                'a user will fail.',
                $header
            );
        }

        return 'No API token was sent and no other credentials were accepted. Any tool call that ' .
            'needs a user will fail. Send a token in either an "Authorization: Bearer <token>" or ' .
            'an "X-DOKUWIKI-TOKEN: <token>" header.';
    }

    /**
     * Amend the reason a call failed with the authentication state when that may be the cause
     *
     * @param string $message The reason reported by the failing call
     * @return string
     */
    protected function explain($message)
    {
        global $INPUT;

        if ($INPUT->server->str('REMOTE_USER') !== '') return $message;
        return rtrim($message, '.') . '. ' . $this->authStatus();
    }
}
