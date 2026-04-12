<?php
// includes/init.php

require_once __DIR__ . '/../config.php';

$autoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
];

foreach ($autoloadCandidates as $autoloadPath) {
    if (is_file($autoloadPath)) {
        require_once $autoloadPath;
        break;
    }
}

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/email-templates.php';
require_once __DIR__ . '/diplomas.php';
require_once __DIR__ . '/vk-publications.php';

enforceForcedPasswordChangeRedirect();
