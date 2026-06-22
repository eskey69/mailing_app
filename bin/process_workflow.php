<?php

declare(strict_types=1);

use MailingApp\Database;
use MailingApp\AiDraftExchangeService;
use MailingApp\LeadMeta;
use MailingApp\LeadRepository;
use MailingApp\MailerService;
use MailingApp\MailTemplateFactory;
use MailingApp\PublicationPayloadBuilder;
use MailingApp\PublicationService;
use MailingApp\SmtpMailer;

$config = require dirname(__DIR__) . '/bootstrap.php';

$limit = 50;
$execute = false;
$allowSend = false;
$allowPublish = false;
$phase = 'all';

foreach ($argv ?? [] as $argument) {
    if (str_starts_with($argument, '--limit=')) {
        $limit = max(1, (int) substr($argument, 8));
    } elseif ($argument === '--execute') {
        $execute = true;
    } elseif ($argument === '--send') {
        $allowSend = true;
    } elseif ($argument === '--publish') {
        $allowPublish = true;
    } elseif (str_starts_with($argument, '--phase=')) {
        $phase = trim((string) substr($argument, 8));
    }
}

$allowedPhases = ['all', 'intro', 'followup', 'ai_draft', 'review', 'translation_review', 'send', 'publish'];
if (!in_array($phase, $allowedPhases, true)) {
    fwrite(STDERR, sprintf("Invalid phase \"%s\". Allowed: %s\n", $phase, implode(', ', $allowedPhases)));
    exit(1);
}

$database = new Database($config);
$repository = new LeadRepository($database->pdo(), $config);

$stats = [
    'mode' => $execute ? 'execute' : 'dry-run',
    'phase' => $phase,
    'limit' => $limit,
    'intro_prepared' => 0,
    'followup_prepared' => 0,
    'ai_drafts_generated' => 0,
    'ai_drafts_failed' => 0,
    'ai_translations_generated' => 0,
    'ai_translations_failed' => 0,
    'review_prepared' => 0,
    'translation_review_prepared' => 0,
    'send_selected' => 0,
    'send_sent' => 0,
    'send_redirected' => 0,
    'send_failed' => 0,
    'publish_selected' => 0,
    'published' => 0,
    'publish_failed' => 0,
    'skipped' => [],
];

if ($phase === 'all' || $phase === 'intro') {
    foreach ($repository->fetchIntroPreparationCandidates($limit) as $lead) {
        if (!hasRecipientEmail($lead)) {
            $stats['skipped'][] = describeLead($lead) . ' missing recipient email for intro';
            continue;
        }

        if ($execute) {
            applyMailTemplateForSending($repository, $lead, $config, 'polonads_intro_v1');
        }
        $stats['intro_prepared']++;
    }
}

if ($phase === 'all' || $phase === 'followup') {
    foreach ($repository->fetchDueFollowUpCandidates($limit) as $lead) {
        if (!hasRecipientEmail($lead)) {
            $stats['skipped'][] = describeLead($lead) . ' missing recipient email for follow-up';
            continue;
        }

        if ($execute) {
            applyMailTemplateForSending($repository, $lead, $config, 'polonads_followup_v1');
        }
        $stats['followup_prepared']++;
    }
}

if ($phase === 'all' || $phase === 'ai_draft') {
    $draftService = new AiDraftExchangeService($repository, $config);
    $remaining = $limit;

    foreach ($repository->fetchRequestedAiDraftCandidates($remaining) as $lead) {
        if ($execute) {
            try {
                $draftService->generateDraftForLead((int) $lead['id']);
                $stats['ai_drafts_generated']++;
            } catch (Throwable $exception) {
                $stats['ai_drafts_failed']++;
                $stats['skipped'][] = describeLead($lead) . ' ai draft failed: ' . $exception->getMessage();
            }
        } else {
            $stats['ai_drafts_generated']++;
        }

        $remaining--;
        if ($remaining <= 0) {
            break;
        }
    }

    if ($remaining > 0) {
        foreach ($repository->fetchRequestedTranslationCandidates($remaining) as $lead) {
            if ($execute) {
                try {
                    $draftService->autoTranslatePolishDraft((int) $lead['id']);
                    $stats['ai_translations_generated']++;
                } catch (Throwable $exception) {
                    $stats['ai_translations_failed']++;
                    $stats['skipped'][] = describeLead($lead) . ' polish translation failed: ' . $exception->getMessage();
                }
            } else {
                $stats['ai_translations_generated']++;
            }

            $remaining--;
            if ($remaining <= 0) {
                break;
            }
        }
    }
}

if ($phase === 'all' || $phase === 'review') {
    foreach ($repository->fetchRequestedDraftReviewCandidates($limit) as $lead) {
        if (!hasListingDraft($lead)) {
            $stats['skipped'][] = describeLead($lead) . ' missing listing draft for review mail';
            continue;
        }

        if ($execute) {
            applyMailTemplateForSending($repository, $lead, $config, 'polonads_draft_review_v1');
        }
        $stats['review_prepared']++;
    }
}

if ($phase === 'all' || $phase === 'translation_review') {
    foreach ($repository->fetchTranslationReviewCandidates($limit) as $lead) {
        if (!hasListingDraft($lead)) {
            $stats['skipped'][] = describeLead($lead) . ' missing EN+PL listing draft for review mail';
            continue;
        }

        if ($execute) {
            applyMailTemplateForSending($repository, $lead, $config, 'polonads_draft_review_v1');
        }
        $stats['translation_review_prepared']++;
    }
}

if (($phase === 'all' || $phase === 'send') && $allowSend) {
    if ($execute) {
        $mailer = new SmtpMailer($config['mail'] ?? []);
        $service = new MailerService($repository, $mailer, $config['mail'] ?? []);
        $result = $service->sendApprovedBatch($limit);
        $stats['send_selected'] = (int) ($result['selected'] ?? 0);
        $stats['send_sent'] = (int) ($result['sent'] ?? 0);
        $stats['send_redirected'] = (int) ($result['redirected'] ?? 0);
        $stats['send_failed'] = (int) ($result['failed'] ?? 0);
    } else {
        $selectedLeads = $repository->fetchApprovedLeadsForSending($limit);
        $stats['send_selected'] = count($selectedLeads);
    }
}

if (($phase === 'all' || $phase === 'publish') && $allowPublish) {
    $publicationService = new PublicationService($repository, new PublicationPayloadBuilder($config), $config);
    $selectedLeads = $repository->fetchReadyForPublication($limit);
    $stats['publish_selected'] = count($selectedLeads);

    if ($execute) {
        foreach ($selectedLeads as $lead) {
            try {
                $publicationService->publishLead((int) $lead['id']);
                $stats['published']++;
            } catch (Throwable $exception) {
                $stats['publish_failed']++;
                $stats['skipped'][] = describeLead($lead) . ' publish failed: ' . $exception->getMessage();
            }
        }
    }
}

echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

function applyMailTemplateForSending(LeadRepository $repository, array $lead, array $config, string $templateId): void
{
    $template = MailTemplateFactory::build($templateId, $lead, $config);
    $subject = trim((string) ($template['email_subject'] ?? ''));
    $draft = trim((string) ($template['email_draft'] ?? ''));
    $mailTemplateId = trim((string) ($template['mail_template_id'] ?? ''));

    if ($subject === '' || $draft === '' || $mailTemplateId === '') {
        throw new RuntimeException('Mail template did not produce complete email content.');
    }

    $meta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
    $meta['mail_template_id'] = $mailTemplateId;

    $repository->updateLeadDraftReview((int) $lead['id'], [
        'contact_status' => 'approved',
        'approval_status' => 'approved',
        'campaign_id' => (string) ($lead['campaign_id'] ?? ''),
        'notes' => (string) ($lead['notes'] ?? ''),
        'email_subject' => $subject,
        'email_draft' => $draft,
        'email_final' => trim((string) ($template['email_final'] ?? '')) !== ''
            ? trim((string) ($template['email_final'] ?? ''))
            : $draft,
        'meta' => $meta,
    ], 'automation');
}

function hasRecipientEmail(array $lead): bool
{
    return trim((string) ($lead['primary_email'] ?? '')) !== '';
}

function hasListingDraft(array $lead): bool
{
    $meta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));

    return trim((string) ($meta['listing_title'] ?? '')) !== ''
        && trim((string) ($meta['listing_body'] ?? '')) !== '';
}

function describeLead(array $lead): string
{
    return sprintf(
        '#%d %s <%s>',
        (int) ($lead['id'] ?? 0),
        (string) ($lead['company_name'] ?? ''),
        (string) ($lead['primary_email'] ?? '')
    );
}
