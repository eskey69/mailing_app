<?php

declare(strict_types=1);

use MailingApp\Database;
use MailingApp\LeadRepository;
use MailingApp\PublicationPayloadBuilder;
use MailingApp\PublicationService;

$config = require dirname(__DIR__) . '/bootstrap.php';

$limit = null;
$dryRun = false;
foreach ($argv ?? [] as $argument) {
    if (str_starts_with($argument, '--limit=')) {
        $limit = (int) substr($argument, 8);
    }
    if ($argument === '--dry-run') {
        $dryRun = true;
    }
}

$database = new Database($config);
$repository = new LeadRepository($database->pdo());
$service = new PublicationService($repository, new PublicationPayloadBuilder($config), $config);

$selectedLeads = $repository->fetchReadyForPublication($limit ?? (int) (($config['publication']['batch_size'] ?? 5)));

if ($dryRun) {
    echo sprintf("Dry run selected %d lead(s) for publication:\n", count($selectedLeads));
    foreach ($selectedLeads as $lead) {
        $preview = $service->previewLead((int) $lead['id']);
        echo sprintf(
            "- #%d | %s | %s | status=%s\n",
            (int) $lead['id'],
            (string) $lead['company_name'],
            (string) $lead['primary_email'],
            (string) ($preview['status'] ?? 'unknown')
        );
    }
    exit(0);
}

$published = 0;
$failed = 0;
$pauseSeconds = (int) (($config['publication']['pause_seconds'] ?? 15));

foreach ($selectedLeads as $index => $lead) {
    try {
        $service->publishLead((int) $lead['id']);
        $published++;
    } catch (Throwable $exception) {
        $failed++;
        echo sprintf(
            "Failed lead #%d (%s): %s\n",
            (int) $lead['id'],
            (string) $lead['company_name'],
            $exception->getMessage()
        );
    }

    if ($index < count($selectedLeads) - 1 && $pauseSeconds > 0) {
        sleep($pauseSeconds);
    }
}

echo sprintf(
    "Selected: %d | Published: %d | Failed: %d\n",
    count($selectedLeads),
    $published,
    $failed
);
