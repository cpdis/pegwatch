<?php

use App\Mcp\Servers\PegwatchServer;
use Laravel\Mcp\Facades\Mcp;

// The MCP server (Streamable HTTP), loaded by laravel/mcp with no session or
// cookies. Its card is /.well-known/mcp/server-card.json.
Mcp::web('mcp', PegwatchServer::class)->middleware('throttle:agents')->name('mcp');
