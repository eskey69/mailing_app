<?php

declare(strict_types=1);

use MailingApp\AiDraftExchangeService;
use MailingApp\Database;
use MailingApp\LeadRepository;
use MailingApp\OpenAiListingClient;
use MailingApp\WebsiteContentExtractor;

$config = require dirname(__DIR__) . '/bootstrap.php';

$leadId = 0;
$includeWebsite = true;
$networkCheck = false;

foreach ($_SERVER['argv'] ?? [] as $argument) {
    if (str_starts_with($argument, '--lead=')) {
        $leadId = max(0, (int) substr($argument, 7));
    } elseif ($argument === '--no-website') {
        $includeWebsite = false;
    } elseif ($argument === '--network-check') {
        $networkCheck = true;
    }
}

if ($leadId <= 0) {
    fwrite(STDERR, "Usage: php bin/inspect_ai_lead.php --lead=1236 [--no-website] [--network-check]\n");
    exit(1);
}

$client = new OpenAiListingClient($config);
$result = [
    'status' => 'ok',
    'lead_id' => $leadId,
    'openai' => [
        'configuration' => $client->getConfigurationStatus(),
    ],
];

try {
    $database = new Database($config);
    $repository = new LeadRepository($database->pdo(), $config);
    $lead = $repository->findLeadById($leadId);

    if ($lead === null) {
        throw new RuntimeException('Lead not found.');
    }

    $service = new AiDraftExchangeService($repository, $config);
    $payload = $service->exportLeadPackage($leadId);
    $translationRequested = ((bool) ($payload['translation_request']['requested'] ?? false)) === true;
    $websiteContext = [
        'page_count' => 0,
        'source_urls' => [],
        'combined_text_chars' => 0,
    ];

    if ($includeWebsite && !$translationRequested) {
        $rawWebsiteContext = (new WebsiteContentExtractor($config))->extract(
            is_array($payload['company'] ?? null) ? $payload['company'] : []
        );
        $websiteContext = [
            'page_count' => is_array($rawWebsiteContext['pages'] ?? null) ? count($rawWebsiteContext['pages']) : 0,
            'source_urls' => is_array($rawWebsiteContext['source_urls'] ?? null) ? array_values($rawWebsiteContext['source_urls']) : [],
            'combined_text_chars' => mb_strlen((string) ($rawWebsiteContext['combined_text'] ?? '')),
        ];
    }

    $result['company'] = [
        'name' => (string) ($payload['company']['name'] ?? ''),
        'website' => (string) ($payload['company']['website'] ?? ''),
        'primary_email' => (string) ($payload['company']['primary_email'] ?? ''),
    ];
    $result['translation_requested'] = $translationRequested;
    $result['payload_version'] = (int) ($payload['payload_version'] ?? 0);
    $result['existing_listing_draft'] = [
        'title' => (string) ($payload['existing_listing_draft']['title'] ?? ''),
        'body_chars' => mb_strlen((string) ($payload['existing_listing_draft']['body'] ?? '')),
        'language' => (string) ($payload['existing_listing_draft']['language'] ?? ''),
        'source_urls' => is_array($payload['existing_listing_draft']['source_urls'] ?? null)
            ? array_values($payload['existing_listing_draft']['source_urls'])
            : [],
    ];
    $result['website_context'] = $websiteContext;
} catch (Throwable $exception) {
    $result['status'] = 'error';
    $result['message'] = $exception->getMessage();
}

if ($networkCheck) {
    try {
        $result['openai']['connection'] = $client->checkConnection();
        $result['openai']['http'] = $client->getLastHttpMeta();
    } catch (Throwable $exception) {
        $result['status'] = 'error';
        $result['openai']['message'] = $exception->getMessage();
        $result['openai']['http'] = $client->getLastHttpMeta();
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit($result['status'] === 'ok' ? 0 : 1);
