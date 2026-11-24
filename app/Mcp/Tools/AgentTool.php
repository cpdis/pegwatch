<?php

namespace App\Mcp\Tools;

use App\Agent\Parse;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * A Pegwatch MCP tool: input validated by the same rules as the JSON API
 * (App\Agent\Parse), output as structured content from App\Agent\Lookup.
 */
abstract class AgentTool extends Tool
{
    /** @return array<string, mixed> validated input */
    protected function input(Request $request, array $rules): array
    {
        return Parse::validate($request->all(), $rules);
    }

    protected function result(array $data): ResponseFactory
    {
        return Response::structured($data);
    }

    protected function missing(string $message): Response
    {
        return Response::error($message);
    }
}
