<?php

use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', App\Mcp\Servers\McpServer::class)
    ->middleware(['toggle:mcp', 'auth:sanctum', 'ability:mcp']);

if (app()->isLocal()) {
    Mcp::local('mcp-stdio', App\Mcp\Servers\McpServer::class);
}
