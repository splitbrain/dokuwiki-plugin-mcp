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
            "required" => []
        ];

        foreach ($methods as $method => $call) {
            $args = $call->getArgs();

            // Some LLMs (e.g. Claude) don't allow underscores in method names, so we replace them with dots.
            $tools[] = [
                'name' => str_replace('.', '_', $method),
                'description' => $call->getDescription(),
                'inputSchema' => $args ? $this->getMethodArguments($args)['schema'] : $nullSchema
            ];
        }

        return $tools;
    }
}
