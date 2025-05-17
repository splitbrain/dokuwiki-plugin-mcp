<?php

namespace dokuwiki\plugin\mcp;

use dokuwiki\Remote\JsonRpcServer;

/**
 * Implementation of the Model Context Protocol (MCP) server
 *
 * This assumes the streaming HTTP transport. It's a thin wrapper around the JsonRpcServer
 */
class McpServer extends JsonRpcServer
{
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
                "Access and interact with the DokuWiki instance called '%s'.",
                $conf['title']
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
     * @link https://modelcontextprotocol.io/specification/2025-03-26/server/tools#calling-tools
     * @param array $args
     * @return array
     */
    protected function mcpToolsCall($args)
    {
        $method = $args['name'];
        $params = $args['arguments'];
        $result = parent::call($method, $params);

        # MCP only supports Text, Image and Audio. Complex types will be returned as JSON.
        // FIXME: we could support image and audio in the core.getMedia call
        return [
            "content" => [
                [
                    "type" => "text",
                    "text" => is_scalar($result) ? (string)$result : json_encode($result, JSON_PRETTY_PRINT)
                ]
            ],
            "isError" => false,
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
}
