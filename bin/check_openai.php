<?php

declare(strict_types=1);

use MailingApp\OpenAiListingClient;

$config = require dirname(__DIR__) . '/bootstrap.php';

$argv = $_SERVER['argv'] ?? [];
$options = array_slice($argv, 1);
$configOnly = in_array('--config-only', $options, true) || in_array('--no-network', $options, true);

$client = new OpenAiListingClient($config);
$result = [
    'configuration' => $client->getConfigurationStatus(),
];

if ($configOnly) {
    $result['mode'] = 'config-only';
    $result['status'] = ($result['configuration']['configured'] ?? false) ? 'configured' : 'missing_api_key';
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(($result['configuration']['configured'] ?? false) ? 0 : 1);
}

if (!$client->isConfigured()) {
    $result['status'] = 'missing_api_key';
    $result['message'] = 'OpenAI API key is not configured. Add it in OPENAI_API_KEY or config/app.local.php.';
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

try {
    $result['status'] = 'ok';
    $result['connection'] = $client->checkConnection();
    $result['http'] = $client->getLastHttpMeta();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    $result['status'] = 'error';
    $result['message'] = $exception->getMessage();
    $result['http'] = $client->getLastHttpMeta();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
