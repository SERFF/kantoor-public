<?php

use App\Mcp\Servers\OfficeServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::oauthRoutes();

Mcp::web('/mcp', OfficeServer::class)
    ->middleware(['auth:api', 'throttle:mcp']);

Mcp::local('kantoor', OfficeServer::class);
