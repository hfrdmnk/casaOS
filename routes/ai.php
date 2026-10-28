<?php

use App\Mcp\Servers\FinanceServer;
use Laravel\Mcp\Facades\Mcp;

/*
 * One MCP server per module, each authenticated with a Sanctum personal access token
 * (Settings → API tokens) sent as `Authorization: Bearer <token>`.
 */
Mcp::web('/mcp/finance', FinanceServer::class)->middleware(['auth:sanctum', 'throttle:120,1']);
