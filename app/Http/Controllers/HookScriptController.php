<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class HookScriptController
{
    public const SCRIPTS = [
        'install.sh' => 'hooks/install.sh',
        'kantoor-hook.sh' => 'hooks/kantoor-hook.sh',
        'kantoor-usage.sh' => 'statusline/kantoor-usage.sh',
        'kantoor-desktop-usage.sh' => 'statusline/kantoor-desktop-usage.sh',
    ];

    public function __invoke(string $script): Response
    {
        abort_unless(isset(self::SCRIPTS[$script]), 404);

        $body = str_replace('__KANTOOR_URL__', rtrim(config('app.url'), '/'), file_get_contents(resource_path(self::SCRIPTS[$script])));

        return response($body, 200, [
            'Content-Type' => 'text/x-shellscript; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
