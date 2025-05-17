<?php

namespace dokuwiki\plugin\mcp;

use dokuwiki\Remote\OpenApiDoc\OpenAPIGenerator;

/**
 * Generate the JSON schema for MCP tools descriptions
 *
 * This is a thin wrapper around the OpenAPIGenerator
 */
class SchemaGenerator extends OpenAPIGenerator
{
    /**
     * Get the list of available API calls as tools
     *
     * @return array
     */
    public function getTools()
    {
        $tools = [];

        $methods = $this->api->getMethods();


        $nullSchema = [
            "type" => "object",
            "properties" => (object)[],
            "required" => (object)[]
        ];

        foreach ($methods as $method => $call) {
            $args = $call->getArgs();

            $tools[] = [
                'name' => $method,
                'description' => $call->getDescription(),
                'inputSchema' => $args ? $this->getMethodArguments($args)['schema'] : $nullSchema
            ];
        }

        return $tools;
    }
}
