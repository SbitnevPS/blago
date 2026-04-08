<?php
// Тест работы .htaccess
echo "REQUEST_URI: " . $_SERVER['REQUEST_URI'] . "\n";
echo "SCRIPT_NAME: " . $_SERVER['SCRIPT_NAME'] . "\n";
echo "REDIRECT_STATUS: " . ($_SERVER['REDIRECT_STATUS'] ?? 'не установлен') . "\n";
phpinfo();
