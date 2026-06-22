<?php

declare(strict_types=1);

use MailingApp\Database;
use MailingApp\LeadRepository;
use MailingApp\MailerService;
use MailingApp\SmtpMailer;

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
$repository = new LeadRepository($database->pdo(), $config);
$selectedLeads = $repository->fetchApprovedLeadsForSending($limit ?? (int) (($config['mail']['batch_size'] ?? 3)));

if ($dryRun) {
    echo sprintf("Dry run selected %d lead(s):\n", count($selectedLeads));
    foreach ($selectedLeads as $lead) {
        echo sprintf(
            "- #%d | %s | %s | %s\n",
            (int) $lead['id'],
            (string) $lead['company_name'],
            (string) $lead['primary_email'],
            (string) $lead['email_subject']
        );
    }
    exit(0);
}

$mailer = new SmtpMailer($config['mail'] ?? []);
$service = new MailerService($repository, $mailer, $config['mail'] ?? []);

$results = $service->sendApprovedBatch($limit);

echo sprintf(
    "Selected: %d | Sent: %d | Redirected: %d | Failed: %d\n",
    $results['selected'],
    $results['sent'],
    $results['redirected'] ?? 0,
    $results['failed']
);
