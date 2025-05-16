<?php

namespace dokuwiki\plugin\mcp;

use dokuwiki\Remote\JsonRpcServer;

class McpServer extends JsonRpcServer
{
    public function call($methodname, $args)
    {
        switch ($methodname) {
            case 'initialize':
                return $this->mcpInitialize($args);
            case 'tools/list':
                return $this->mcpToolsList($args);
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


    protected function mcpInitialize($args)
    {
        return [
            "protocolVersion" => "2025-03-26",
            "capabilities" => [
//                "logging" => (object)[],
//                "prompts" => (object)[],
//                "resources" => (object)[],
                "tools" => ["listChanged" => false]
            ],
            "serverInfo" => [
                "name" => "DokuWiki",
                "version" => "1.0.0",
            ],
            "instructions" => "Optional instructions for the client"
        ];
    }

    protected function mcpToolsList($args)
    {
        return [
            "tools" => [
                [
                    "name" => "core.getWikiVersion",
                    "description" => "Get the current version of DokuWiki",
                    "inputSchema" => [
                        "type" => "object",
                        "properties" => (object) [],
                        "required" => (object) []
                    ],
                ],
            ]
        ];
    }

    protected function mcpToolsCall($args)
    {
        $method = $args['name'];
        $params = $args['arguments'];
        $result = parent::call($method, $params);

        # FIXME result needs to be parsed into the correct structure
        return [
            "content" => [
                [
                    "type" => "text",
                    "text" => $result
                ]
            ],
            "isError" => false,
        ];
    }

    protected function mcpNOP($args)
    {
        return (object)[];
    }
}
