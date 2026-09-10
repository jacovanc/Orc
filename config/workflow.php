<?php

return [
    'notifications' => [
        'email_enabled' => env('WORKFLOW_EMAIL_NOTIFICATIONS_ENABLED', false),
        'queue' => env('WORKFLOW_EMAIL_QUEUE', 'workflow-notifications'),
    ],
];
