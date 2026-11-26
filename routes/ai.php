<?php

use App\Mcp\Servers\SunshineServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

// Discovery and client registration for MCP clients such as Claude. Registration is open (each client registers
// itself), so it's rate limited, and config/mcp.php only allows Claude's redirect domains.
Route::middleware('throttle:30,1')->group(function (): void {
    Mcp::oauthRoutes();
});

// Sunshine for Claude, acting as the user who approved it on the consent screen.
Mcp::web('/mcp', SunshineServer::class)
    ->middleware('auth:api')
    ->name('mcp');
