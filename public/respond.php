<?php

declare(strict_types=1);

use MailingApp\Database;
use MailingApp\LeadRepository;
use MailingApp\Support;

$config = require dirname(__DIR__) . '/bootstrap.php';

$leadId = isset($_GET['lead']) ? (int) $_GET['lead'] : 0;
$action = trim((string) ($_GET['action'] ?? ''));
$token = trim((string) ($_GET['token'] ?? ''));

$allowedActions = ['request_draft', 'self_publish', 'contact_later', 'unsubscribe'];
$button = null;
$requiresConfirmation = (bool) ($config['app']['require_click_confirmation'] ?? false);

if ($leadId <= 0 || !in_array($action, $allowedActions, true) || !Support::verifyLeadActionToken($config, $leadId, $action, $token)) {
    http_response_code(400);
    $title = 'Invalid response link';
    $message = 'This response link is invalid or has expired.';
} else {
    $database = new Database($config);
        $repository = new LeadRepository($database->pdo(), $config);
    $lead = $repository->findLeadById($leadId);

    if ($lead === null) {
        http_response_code(404);
        $title = 'Record not found';
        $message = 'We could not find the requested record.';
    } else {
        $messages = [
            'request_draft' => [
                'confirm_title' => 'Confirm your choice',
                'confirm_message' => 'If you would like us to prepare a free trial draft listing, please confirm below.',
                'title' => 'Thank you',
                'message' => 'We will prepare a draft listing for your free trial posting. If you would like to reach the Polish community more directly, we can also prepare a Polish version.',
                'button' => null,
            ],
            'self_publish' => [
                'confirm_title' => 'Confirm your choice',
                'confirm_message' => 'If you prefer to create and publish the listing yourself, please confirm below.',
                'title' => 'Create your listing',
                'message' => 'Thank you. You can create and publish your listing yourself using the link below.',
                'button' => [
                    'label' => 'Open listing form',
                    'url' => (string) ($config['app']['self_publish_url'] ?? 'https://polonads.com/index.php/en-us/dodajogloszenie-2'),
                ],
            ],
            'contact_later' => [
                'confirm_title' => 'Confirm your choice',
                'confirm_message' => 'If you would like us to reach out again later, please confirm below.',
                'title' => 'No problem',
                'message' => 'Thank you. We will follow up later and resend the offer.',
                'button' => null,
            ],
            'unsubscribe' => [
                'confirm_title' => 'Confirm removal',
                'confirm_message' => 'If you want to stop receiving outreach from us, please confirm below.',
                'title' => 'You have been removed',
                'message' => 'Thank you for letting us know. We apologize for the inconvenience, and your address has been removed from our mailing list.',
                'button' => null,
            ],
        ];

        if (!$requiresConfirmation || $_SERVER['REQUEST_METHOD'] === 'POST') {
            $repository->registerLeadResponse($leadId, $action);
            $title = $messages[$action]['title'];
            $message = $messages[$action]['message'];
            $button = $messages[$action]['button'];
        } else {
            $title = $messages[$action]['confirm_title'];
            $message = $messages[$action]['confirm_message'];
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= Support::escape($title ?? 'Response') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= Support::escape(Support::baseUrl($config, 'assets/app.css')) ?>">
</head>
<body>
<div class="shell">
    <section class="hero">
        <p class="muted">Polonads.com response center.</p>
        <h1><?= Support::escape($title ?? 'Response') ?></h1>
        <p><?= Support::escape($message ?? '') ?></p>
        <?php if ($requiresConfirmation && $leadId > 0 && $action !== '' && $_SERVER['REQUEST_METHOD'] !== 'POST' && http_response_code() < 400): ?>
            <form method="post" class="actions">
                <button type="submit" class="button">Confirm</button>
            </form>
        <?php endif; ?>
        <?php if (isset($button) && is_array($button)): ?>
            <div class="actions">
                <a class="button" href="<?= Support::escape((string) $button['url']) ?>" target="_blank" rel="noreferrer">
                    <?= Support::escape((string) $button['label']) ?>
                </a>
            </div>
        <?php endif; ?>
    </section>
</div>
</body>
</html>
