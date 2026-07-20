<?php

namespace dokuwiki\plugin\mcp;

use dokuwiki\Remote\ApiCall;
use dokuwiki\Remote\OpenApiDoc\OpenAPIGenerator;

/**
 * Generate the JSON schema for MCP tools descriptions
 *
 * This is a thin wrapper around the OpenAPIGenerator
 */
class SchemaGenerator extends OpenAPIGenerator
{
    /**
     * Category marking the deprecated legacy XML-RPC API methods. Tools for these
     * are not exposed because the modern core.* methods supersede them.
     *
     * @var string
     */
    protected const LEGACY_CATEGORY = 'legacy';

    /**
     * Method names (without their category prefix) that only read data and never
     * modify the wiki. Their tools are annotated as read-only so clients may run
     * them without asking the user for confirmation.
     *
     * @var string[]
     */
    protected const READ_ONLY = [
        'getAPIVersion', 'getWikiVersion', 'getWikiTitle', 'getWikiTime',
        'whoAmI', 'aclCheck',
        'listPages', 'searchPages', 'getRecentPageChanges',
        'getPage', 'getPageHTML', 'getPageInfo', 'getPageHistory',
        'getPageLinks', 'getPageBackLinks',
        'listMedia', 'getRecentMediaChanges', 'getMedia', 'getMediaInfo',
        'getMediaUsage', 'getMediaHistory',
    ];

    /**
     * Method names (without their category prefix) that modify the wiki but only in
     * an additive or reversible way, without destroying existing content. Any
     * modifying method not listed here is treated as destructive.
     *
     * @var string[]
     */
    protected const NON_DESTRUCTIVE = [
        'appendPage', 'lockPages', 'unlockPages', 'login', 'logoff',
    ];

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
            // skip the deprecated legacy XML-RPC API; the modern core.* methods replace it
            if ($call->getCategory() === self::LEGACY_CATEGORY) continue;

            $args = $call->getArgs();

            // Some LLMs (e.g. Claude) don't allow underscores in method names, so we replace them with dots.
            $tools[] = [
                'name' => str_replace('.', '_', $method),
                'description' => $call->getDescription(),
                'inputSchema' => $args ? $this->getMethodArguments($args)['schema'] : $nullSchema,
                'annotations' => $this->getAnnotations($method, $call),
            ];
        }

        return $tools;
    }

    /**
     * Build the MCP tool annotations describing the safety of a method call.
     *
     * Read-only methods are hinted as such so clients may skip user confirmation.
     * Modifying methods are marked destructive unless known to be additive or
     * reversible. Unknown methods default to destructive.
     *
     * @param string $method The full API method name including its category prefix
     * @param ApiCall $call The API call definition
     * @return array
     */
    protected function getAnnotations($method, ApiCall $call)
    {
        $pos = strrpos($method, '.');
        $name = $pos === false ? $method : substr($method, $pos + 1);

        $summary = (string)$call->getSummary();
        $annotations = [
            'title' => $summary !== '' ? $summary : str_replace('.', '_', $method),
        ];

        if (in_array($name, self::READ_ONLY, true)) {
            $annotations['readOnlyHint'] = true;
        } else {
            $annotations['readOnlyHint'] = false;
            $annotations['destructiveHint'] = !in_array($name, self::NON_DESTRUCTIVE, true);
        }

        return $annotations;
    }
}
