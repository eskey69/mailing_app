<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Warsaw');

define('APP_ROOT', __DIR__);

spl_autoload_register(static function (string $class): void {
    $prefix = 'MailingApp\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = APP_ROOT . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$configFile = APP_ROOT . '/config/app.php';
if (!is_file($configFile)) {
    throw new RuntimeException('Missing configuration file: config/app.php');
}

$config = require $configFile;
if (!is_array($config)) {
    throw new RuntimeException('Invalid configuration format.');
}

$localConfigFile = APP_ROOT . '/config/app.local.php';
if (is_file($localConfigFile)) {
    $localConfig = require $localConfigFile;
    if (!is_array($localConfig)) {
        throw new RuntimeException('Invalid local configuration format in config/app.local.php.');
    }

    $config = array_replace_recursive($config, $localConfig);
}

$timezone = $config['app']['timezone'] ?? 'UTC';
date_default_timezone_set($timezone);

if (!isset($config['app']['upload_dir'])) {
    throw new RuntimeException('Missing app.upload_dir in configuration.');
}

if (!is_dir($config['app']['upload_dir'])) {
    mkdir($config['app']['upload_dir'], 0775, true);
}

return $config;
