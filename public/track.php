<?php

declare(strict_types=1);

use MailingApp\Database;
use MailingApp\LeadRepository;
use MailingApp\Support;

$config = require dirname(__DIR__) . '/bootstrap.php';

$leadId = isset($_GET['lead']) ? (int) $_GET['lead'] : 0;
$eventType = trim((string) ($_GET['event'] ?? ''));
$mailTemplateId = trim((string) ($_GET['template'] ?? ''));
$token = trim((string) ($_GET['token'] ?? ''));

if ($leadId > 0 && in_array($eventType, ['open', 'click'], true) && Support::verifyTrackingToken($config, $leadId, $eventType, $mailTemplateId, $token)) {
    try {
        $database = new Database($config);
        $repository = new LeadRepository($database->pdo(), $config);

        if ($eventType === 'open') {
            $repository->registerEmailOpen($leadId, $mailTemplateId);
        } elseif ($eventType === 'click') {
            $repository->registerEmailClick($leadId, $mailTemplateId);
        }
    } catch (Throwable $exception) {
        // Tracking should never break email rendering.
    }
}

header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

echo base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
