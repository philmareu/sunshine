<?php

use App\Mcp\Servers\SunshineServer;
use Laravel\Mcp\Facades\Mcp;

// Sunshine for Claude, acting as the user whose token it is, from the Connect Claude page.
Mcp::web('/mcp', SunshineServer::class)->middleware('auth:sanctum');
