<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Warsaw');

define('APP_ROOT', __DIR__);

function loadProjectEnvFile(string $filePath): void
{
    if (!is_file($filePath)) {
        return;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $raw = trim($line);
        if ($raw === '' || str_starts_with($raw, '#') || !str_contains($raw, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $raw, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");

        if ($key === '' || getenv($key) !== false) {
            continue;
        }

        putenv(sprintf('%s=%s', $key, $value));
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

loadProjectEnvFile(dirname(APP_ROOT) . DIRECTORY_SEPARATOR . 'mailing_app_openai.env');
loadProjectEnvFile(APP_ROOT . DIRECTORY_SEPARATOR . 'openai.env');

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
