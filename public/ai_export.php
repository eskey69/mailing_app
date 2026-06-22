<?php

declare(strict_types=1);

use MailingApp\AiDraftExchangeService;
use MailingApp\Database;
use MailingApp\LeadRepository;
use MailingApp\Support;

$config = require dirname(__DIR__) . '/bootstrap.php';

$leadId = isset($_GET['lead']) ? (int) $_GET['lead'] : 0;
$token = trim((string) ($_GET['token'] ?? ''));

if ($leadId <= 0 || !Support::verifyLeadActionToken($config, $leadId, 'ai_export', $token)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid AI export token.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$database = new Database($config);
$repository = new LeadRepository($database->pdo());
$service = new AiDraftExchangeService($repository, $config);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(
    [
        'status' => 'ok',
        'exported_at' => date('Y-m-d H:i:s'),
        'import_endpoint' => Support::publicUrl($config, 'ai_import.php'),
        'import_token' => Support::signLeadAction($config, $leadId, 'ai_import'),
        'payload' => $service->exportLeadPackage($leadId),
    ],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
