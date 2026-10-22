<?php

return [
    'brand' => env('KANTOOR_BRAND'),
    'sign' => env('KANTOOR_SIGN', 'KANTOOR'),
    'dog' => env('KANTOOR_DOG', 'Yuki'),

    'timeouts' => [
        'mcp' => (int) env('KANTOOR_TIMEOUT_MCP', 30),
        'claude_code' => (int) env('KANTOOR_TIMEOUT_CLAUDE_CODE', 20),
        'subagent' => (int) env('KANTOOR_TIMEOUT_SUBAGENT', 20),
    ],

    'done_to_idle_minutes' => (int) env('KANTOOR_DONE_TO_IDLE', 15),

    'idle_offline_after_minutes' => (int) env('KANTOOR_IDLE_OFFLINE_AFTER', 30),

    'offline_after_minutes' => (int) env('KANTOOR_OFFLINE_AFTER', 120),

    'forget_offline_after_days' => (int) env('KANTOOR_FORGET_OFFLINE_AFTER', 7),

    'keep_events_days' => (int) env('KANTOOR_KEEP_EVENTS', 30),

    'keep_log_days' => (int) env('KANTOOR_KEEP_LOG', 365),

    'alarm_minutes' => (int) env('KANTOOR_ALARM_MINUTES', 60),

    'keep_alarms_days' => (int) env('KANTOOR_KEEP_ALARMS', 30),

    'timezone' => env('KANTOOR_TIMEZONE', 'Europe/Amsterdam'),

    'keep_questions_days' => (int) env('KANTOOR_KEEP_QUESTIONS', 7),

    'websocket' => [
        'host' => env('KANTOOR_WS_HOST'),
        'port' => (int) env('KANTOOR_WS_PORT', 443),
        'scheme' => env('KANTOOR_WS_SCHEME', 'https'),
    ],

    'code_roots' => array_filter(array_map('trim', explode(',', env('KANTOOR_CODE_ROOTS', 'Code,Herd,Projects,Sites')))),

    'names' => [
        'man' => [
            'Bas', 'Ruben', 'Daan', 'Milan', 'Sem', 'Koen', 'Jesse', 'Luuk', 'Tim', 'Thijs',
            'Joost', 'Gijs', 'Pim', 'Wouter', 'Stijn', 'Bram', 'Niels', 'Jasper', 'Lars', 'Hidde', 'Ward',
        ],
        'woman' => [
            'Noor', 'Femke', 'Ilse', 'Lotte', 'Fleur', 'Iris', 'Mila', 'Sanne', 'Eva', 'Anouk',
            'Roos', 'Lisa', 'Maud', 'Floor', 'Esmee', 'Vera', 'Isa', 'Nina', 'Jet',
        ],
    ],

    'avatars' => [
        'man' => [0, 2, 4, 6, 8, 10, 11, 13, 14, 16, 18, 20, 22],
        'woman' => [1, 3, 5, 7, 9, 12, 15, 17, 19, 21, 23],
    ],

];
