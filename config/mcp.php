<?php

// laravel/mcp: OAuth client registration for /mcp/account (see routes/ai.php).
return [
    // Any https redirect: each agent app has its own callback, and the
    // consent page names the client and where it sends the person back to.
    'redirect_domains' => ['*'],

    // Desktop and editor agents that call back on their own URL scheme.
    'custom_schemes' => ['claude', 'cursor', 'vscode', 'vscode-insiders', 'windsurf', 'zed'],

    'authorization_server' => null,
];
