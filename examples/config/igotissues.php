<?php

/**
 * I got issues: copy to config/igotissues.php and adjust.
 *
 * Credentials belong in .env; this file only names the variables.
 */

return [
    '*' => [
        'githubToken' => '$IGOTISSUES_GITHUB_TOKEN',
        'githubRepo' => '$IGOTISSUES_GITHUB_REPO',
        'anthropicApiKey' => '$IGOTISSUES_ANTHROPIC_KEY',

        // Only for GitHub Enterprise Server: 'https://<host>/api/v3/'.
        'githubApiUrl' => 'https://api.github.com/',
        'model' => 'claude-opus-5-5',

        // How issues are written on this project.
        'draftInstructions' => '',

        // Who sees the report button. Admins always do.
        'reportPermission' => 'accessCp',
        'position' => 'bottom-right',

        // Templates without endBody() call {{ igotissues_widget() }} instead.
        'autoInject' => false,

        'captureErrors' => true,
        'captureInDevMode' => false,
        'captureConsole' => false,
        'reopenOnRecurrence' => true,
    ],
    'dev' => [
        // Turn on to see your own errors under Reports while developing.
        'captureInDevMode' => false,
    ],
];
