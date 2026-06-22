<?php

declare(strict_types=1);

use MailingApp\Database;
use MailingApp\LeadMeta;
use MailingApp\LeadRepository;
use MailingApp\Support;

function public_contact_label(string $status): string
{
    return [
        'new' => 'new',
        'draft_ready' => 'draft ready',
        'approved' => 'approved',
        'client_review' => 'review in progress',
        'published' => 'published',
        'sent' => 'sent',
        'replied' => 'replied',
        'skipped' => 'skipped',
        'failed' => 'failed',
    ][$status] ?? $status;
}

function public_approval_label(string $status): string
{
    return [
        'pending' => 'pending',
        'approved' => 'approved',
        'rejected' => 'rejected',
    ][$status] ?? $status;
}

$config = require dirname(__DIR__) . '/bootstrap.php';

$leadId = isset($_GET['lead']) ? (int) $_GET['lead'] : 0;
$action = trim((string) ($_GET['action'] ?? 'open'));
$token = trim((string) ($_GET['token'] ?? ''));

$allowedActions = ['open', 'approve', 'approve_polish'];
$signedAction = 'review_' . $action;
$success = '';
$error = '';
$requiresConfirmation = (bool) ($config['app']['require_click_confirmation'] ?? false);
$confirmationMode = $requiresConfirmation && in_array($action, ['approve', 'approve_polish'], true);

if ($leadId <= 0 || !in_array($action, $allowedActions, true) || !Support::verifyLeadActionToken($config, $leadId, $signedAction, $token)) {
    http_response_code(400);
    $title = 'Invalid review link';
    $message = 'This review link is invalid or has expired.';
} else {
    $database = new Database($config);
    $repository = new LeadRepository($database->pdo());
    $lead = $repository->findLeadById($leadId);

    if ($lead === null) {
        http_response_code(404);
        $title = 'Listing not found';
        $message = 'We could not find the requested draft listing.';
    } else {
        $meta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
        $listingTitle = trim((string) ($meta['listing_title'] ?? ''));
        $listingBody = trim((string) ($meta['listing_body'] ?? ''));
        if ($listingTitle === '') {
            $listingTitle = trim((string) ($lead['email_subject'] ?? ''));
        }
        if ($listingBody === '') {
            $listingBody = trim((string) ($lead['email_final'] ?? ''));
        }
        if ($listingBody === '') {
            $listingBody = trim((string) ($lead['email_draft'] ?? ''));
        }

        if (in_array($action, ['approve', 'approve_polish'], true)
            && (!$confirmationMode || $_SERVER['REQUEST_METHOD'] === 'POST')
        ) {
            if ($action === 'approve') {
                $repository->markLeadPublicationApproved($leadId, 'en');
                $success = 'Thank you. Your listing has been approved for publication.';
            } else {
                try {
                    $repository->requestLeadPolishTranslation($leadId);
                    $success = 'Thank you. We will prepare the Polish version and send it to you for final review before publication.';
                    $confirmationMode = false;
                } catch (\Throwable $exception) {
                    $error = 'We could not save the Polish version request. Please contact us and we will review it manually.';
                }
            }

            $lead = $repository->findLeadById($leadId);
            $meta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
            $listingTitle = trim((string) ($meta['listing_title'] ?? $listingTitle));
            $listingBody = trim((string) ($meta['listing_body'] ?? $listingBody));
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $intent = trim((string) ($_POST['intent'] ?? ''));
            $allowedIntents = ['save', 'approve', 'approve_polish'];
            if (!in_array($intent, $allowedIntents, true)) {
                $error = 'Invalid draft action.';
            } else {
                $currentNotes = trim((string) ($lead['notes'] ?? ''));
                $clientNotes = trim((string) ($_POST['client_notes'] ?? ''));
                $notes = $currentNotes;
                if ($clientNotes !== '') {
                    $notes = rtrim($currentNotes);
                    $notes .= ($notes === '' ? '' : "\n") . '[' . date('Y-m-d H:i:s') . '] Client notes: ' . $clientNotes;
                }

                $languageMode = trim((string) ($_POST['language_mode'] ?? 'en'));
                $languageMap = ['en' => 'en', 'pl' => 'pl', 'bilingual' => 'en+pl'];
                $languageMode = $languageMap[$languageMode] ?? 'en';

                $listingTitle = trim((string) ($_POST['listing_title'] ?? $listingTitle));
                $listingBody = trim((string) ($_POST['listing_body'] ?? $listingBody));

                $repository->updateLeadDraftReview($leadId, [
                    'contact_status' => $intent === 'save' || $intent === 'approve_polish' ? 'client_review' : 'approved',
                    'approval_status' => $intent === 'save' || $intent === 'approve_polish' ? 'pending' : 'approved',
                    'campaign_id' => (string) ($lead['campaign_id'] ?? ''),
                    'notes' => $notes,
                    'email_subject' => trim((string) ($lead['email_subject'] ?? '')),
                    'email_draft' => trim((string) ($lead['email_draft'] ?? '')),
                    'email_final' => trim((string) ($lead['email_final'] ?? '')),
                    'meta' => [
                        'client_intent' => $intent,
                        'draft_language' => $intent === 'approve_polish' ? 'en+pl' : $languageMode,
                        'publication_status' => $intent === 'save' ? 'drafted' : ($intent === 'approve_polish' ? 'translation_requested' : 'approved'),
                        'account_status' => $intent === 'save' ? 'not_created' : ($intent === 'approve_polish' ? 'translation_requested' : 'pending_publication'),
                        'client_requested_polish' => $intent === 'approve_polish' || $languageMode !== 'en',
                        'translation_status' => $intent === 'approve_polish' ? 'requested' : (string) ($meta['translation_status'] ?? ''),
                        'translation_requested_at' => $intent === 'approve_polish' ? date('Y-m-d H:i:s') : (string) ($meta['translation_requested_at'] ?? ''),
                        'listing_title' => $listingTitle,
                        'listing_body' => $listingBody,
                    ],
                ], 'client');

                if ($intent === 'approve') {
                    $repository->markLeadPublicationApproved($leadId, $languageMode);
                    $success = 'Thank you. Your listing has been approved for publication.';
                } elseif ($intent === 'approve_polish') {
                    try {
                        $repository->requestLeadPolishTranslation($leadId);
                        $success = 'Thank you. We will prepare the Polish version and send it to you for final review before publication.';
                    } catch (\Throwable $exception) {
                        $error = 'We could not save the Polish version request. Please contact us and we will review it manually.';
                    }
                } else {
                    $success = 'Your changes have been saved. You can come back later and approve publication when everything looks right.';
                }

                $lead = $repository->findLeadById($leadId);
                $meta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
            }
        }

        $title = (string) ($lead['company_name'] ?? 'Draft review');
        if ($confirmationMode && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $message = $action === 'approve'
                ? 'Please confirm that this draft should be approved for publication.'
                : 'Please confirm that you want us to prepare a Polish version before publication.';
        } else {
            $message = 'Review your draft listing, make any changes you want, and decide how you would like it published.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= Support::escape($title ?? 'Draft review') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= Support::escape(Support::baseUrl($config, 'assets/app.css')) ?>">
</head>
<body>
<div class="shell">
    <section class="hero">
        <p class="muted">Polonads.com listing review.</p>
        <h1><?= Support::escape($title ?? 'Draft review') ?></h1>
        <p><?= Support::escape($message ?? '') ?></p>
    </section>

    <?php if (!empty($success)): ?>
        <div class="flash success"><?= Support::escape($success) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="flash error"><?= Support::escape($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($lead) && is_array($lead)): ?>
        <section class="grid">
            <article class="card">
                <h2>Company</h2>
                <p><strong>Name:</strong> <?= Support::escape((string) $lead['company_name']) ?></p>
                <p><strong>Location:</strong> <?= Support::escape(trim((string) $lead['city'] . ', ' . (string) $lead['state'], ', ')) ?></p>
                <p><strong>Website:</strong> <?= Support::escape((string) $lead['website']) ?></p>
                <p><strong>Email:</strong> <?= Support::escape((string) $lead['primary_email']) ?></p>
            </article>

            <article class="card">
                <h2>Status</h2>
                <p><strong>Contact:</strong> <?= Support::escape(public_contact_label((string) $lead['contact_status'])) ?></p>
                <p><strong>Approval:</strong> <?= Support::escape(public_approval_label((string) $lead['approval_status'])) ?></p>
                <p><strong>Publication:</strong> <?= Support::escape((string) ($meta['publication_status'] ?? 'not_started')) ?></p>
                <p><strong>Language:</strong> <?= Support::escape((string) ($meta['draft_language'] ?? 'en')) ?></p>
            </article>
        </section>

        <?php if ($confirmationMode): ?>
            <section class="card" style="margin-top: 24px;">
                <h2><?= $action === 'approve' ? 'Approve publication' : 'Request Polish translation' ?></h2>
                <p>
                    <?= $action === 'approve'
                        ? 'Use the button below to confirm publication approval for this listing.'
                        : 'Use the button below to confirm that you want a Polish version prepared before publication.' ?>
                </p>
                <form class="actions" method="post">
                    <button type="submit" class="button"><?= $action === 'approve' ? 'Confirm approval' : 'Confirm Polish version' ?></button>
                    <a class="button soft" href="<?= Support::escape(Support::buildReviewUrl($config, $leadId, 'open')) ?>">Go back to draft</a>
                </form>
            </section>
        <?php else: ?>
            <section class="card" style="margin-top: 24px;">
                <h2>Review listing</h2>
                <form class="form-grid" method="post">
                    <div>
                        <label for="listing_title">Listing title</label>
                        <input id="listing_title" name="listing_title" type="text" value="<?= Support::escape($listingTitle) ?>">
                    </div>

                    <div>
                        <label for="listing_body">Listing content</label>
                        <textarea id="listing_body" name="listing_body" rows="14"><?= Support::escape($listingBody) ?></textarea>
                    </div>

                    <div>
                        <label for="language_mode">Publication mode</label>
                        <select id="language_mode" name="language_mode">
                            <?php foreach (['en' => 'English only', 'pl' => 'Polish only', 'bilingual' => 'English and Polish'] as $value => $label): ?>
                                <option value="<?= Support::escape($value) ?>" <?= (($meta['draft_language'] ?? 'en') === ($value === 'bilingual' ? 'en+pl' : $value)) ? 'selected' : '' ?>>
                                    <?= Support::escape($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="client_notes">Additional notes</label>
                        <textarea id="client_notes" name="client_notes" rows="6" placeholder="Add your edits, photo requests, promotion ideas, or any other notes here."></textarea>
                    </div>

                    <div class="actions">
                        <button type="submit" name="intent" value="save" class="button soft">Save changes</button>
                        <button type="submit" name="intent" value="approve" class="button">Approve publication</button>
                        <button type="submit" name="intent" value="approve_polish" class="button secondary">Publish in Polish too</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
