<?php

declare(strict_types=1);

use MailingApp\AiDraftExchangeService;
use MailingApp\CampaignRepository;
use MailingApp\Database;
use MailingApp\LeadRepository;
use MailingApp\LeadMeta;
use MailingApp\MailTemplateFactory;
use MailingApp\PublicationPayloadBuilder;
use MailingApp\PublicationService;
use MailingApp\Support;

$config = require dirname(__DIR__) . '/bootstrap.php';

$leadId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($leadId <= 0) {
    http_response_code(400);
    echo 'Brak poprawnego ID leada.';
    exit;
}

$database = new Database($config);
$repository = new LeadRepository($database->pdo());
$campaignRepository = new CampaignRepository($database->pdo());
$availableCampaigns = [];
try {
    $availableCampaigns = $campaignRepository->fetchActiveCampaigns();
} catch (\Throwable $exception) {
    $availableCampaigns = [];
}

$error = '';

/**
 * @param array<string, string> $payload
 * @return array<string, string>
 */
function apply_operator_action(array $payload, string $action): array
{
    switch ($action) {
        case 'reset_and_prepare_intro':
            $payload['contact_status'] = 'approved';
            $payload['approval_status'] = 'approved';
            if (trim($payload['email_final']) === '') {
                $payload['email_final'] = $payload['email_draft'];
            }
            break;
        case 'copy_draft_to_final':
            $payload['email_final'] = $payload['email_draft'];
            if ($payload['contact_status'] === 'new' && trim($payload['email_subject']) !== '' && trim($payload['email_draft']) !== '') {
                $payload['contact_status'] = 'draft_ready';
            }
            break;
        case 'mark_draft_ready':
            $payload['contact_status'] = 'draft_ready';
            break;
        case 'approve_for_sending':
            $payload['approval_status'] = 'approved';
            $payload['contact_status'] = 'approved';
            if (trim($payload['email_final']) === '') {
                $payload['email_final'] = $payload['email_draft'];
            }
            break;
        case 'return_to_editing':
            $payload['approval_status'] = 'pending';
            $payload['contact_status'] = 'new';
            break;
        case 'mark_rejected':
            $payload['approval_status'] = 'rejected';
            $payload['contact_status'] = 'skipped';
            break;
        case 'simulate_ai_draft':
        case 'generate_ai_draft':
            break;
    }

    return $payload;
}

/**
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $lead
 * @return array<string, mixed>
 */
function apply_template(array $payload, array $lead, string $templateId): array
{
    global $config, $repository;

    if (in_array($templateId, ['polonads_draft_review_v1', 'polonads_self_publish_v1'], true)) {
        $aiDraftService = new AiDraftExchangeService($repository, $config);
        if (!$aiDraftService->hasListingDraft($lead)) {
            $aiDraftService->generateDraftForLead((int) ($lead['id'] ?? 0));
            $refetchedLead = $repository->findLeadById((int) ($lead['id'] ?? 0));
            if (is_array($refetchedLead)) {
                $lead = $refetchedLead;
            }
        }

        $publicationService = new PublicationService($repository, new PublicationPayloadBuilder($config), $config);
        $publicationService->prepareDraftListing((int) ($lead['id'] ?? 0));
        $refetchedLead = $repository->findLeadById((int) ($lead['id'] ?? 0));
        if (is_array($refetchedLead)) {
            $lead = $refetchedLead;
        }
    }

    $template = MailTemplateFactory::build($templateId, $lead, $config);
    $mailTemplateId = trim((string) ($template['mail_template_id'] ?? ''));
    if ($mailTemplateId === '') {
        return $payload;
    }

    $payload['email_subject'] = trim((string) ($template['email_subject'] ?? ''));
    $payload['email_draft'] = trim((string) ($template['email_draft'] ?? ''));
    $payload['meta'] = array_replace_recursive(
        is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
        ['mail_template_id' => $mailTemplateId]
    );
    $templateFinal = trim((string) ($template['email_final'] ?? ''));

    if ($templateFinal !== '') {
        $payload['email_final'] = $templateFinal;
    } elseif (in_array($templateId, ['polonads_published_v1', 'polonads_self_publish_v1', 'polonads_unsubscribe_confirm_v1'], true)) {
        $payload['email_final'] = trim((string) ($template['email_draft'] ?? ''));
    } else {
        $payload['email_final'] = '';
    }

    if ($payload['contact_status'] === 'new') {
        $payload['contact_status'] = 'draft_ready';
    }

    return $payload;
}

$lead = $repository->findLeadById($leadId);
if ($lead === null) {
    http_response_code(404);
    echo 'Nie znaleziono leada.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = [
        'contact_status' => trim((string) ($_POST['contact_status'] ?? 'new')),
        'approval_status' => trim((string) ($_POST['approval_status'] ?? 'pending')),
        'campaign_id' => trim((string) ($_POST['campaign_id'] ?? '')),
        'notes' => trim((string) ($_POST['notes'] ?? '')),
        'email_subject' => trim((string) ($_POST['email_subject'] ?? '')),
        'email_draft' => trim((string) ($_POST['email_draft'] ?? '')),
        'email_final' => trim((string) ($_POST['email_final'] ?? '')),
    ];
    $operatorAction = trim((string) ($_POST['operator_action'] ?? ''));
    $templateAction = trim((string) ($_POST['template_action'] ?? ''));

    $allowedApproval = ['pending', 'approved', 'rejected'];
    $allowedContact = ['new', 'draft_ready', 'approved', 'client_review', 'published', 'sent', 'replied', 'skipped', 'failed'];
    $allowedActions = ['', 'copy_draft_to_final', 'mark_draft_ready', 'approve_for_sending', 'return_to_editing', 'mark_rejected', 'simulate_ai_draft', 'generate_ai_draft', 'reset_test_flow', 'reset_and_prepare_intro'];
    $allowedTemplates = ['', 'polonads_intro_v1', 'polonads_followup_v1', 'polonads_interest_reply_v1', 'polonads_draft_review_v1', 'polonads_published_v1', 'polonads_self_publish_v1', 'polonads_unsubscribe_confirm_v1'];

    if (!in_array($operatorAction, $allowedActions, true)) {
        $error = 'Nieprawidłowa akcja operatora.';
    } elseif (!in_array($templateAction, $allowedTemplates, true)) {
        $error = 'Nieprawidłowy szablon maila.';
    } elseif (!in_array($payload['approval_status'], $allowedApproval, true)) {
        $error = 'Nieprawidłowy status zatwierdzenia.';
    } elseif (!in_array($payload['contact_status'], $allowedContact, true)) {
        $error = 'Nieprawidłowy status kontaktu.';
    } else {
        try {
            if ($operatorAction === 'reset_test_flow' || $operatorAction === 'reset_and_prepare_intro') {
                $repository->resetLeadForFreshOutreach($leadId);
                $lead = $repository->findLeadById($leadId) ?? $lead;

                if ($operatorAction === 'reset_and_prepare_intro') {
                    $resetPayload = [
                        'contact_status' => (string) ($lead['contact_status'] ?? 'new'),
                        'approval_status' => (string) ($lead['approval_status'] ?? 'pending'),
                        'campaign_id' => (string) ($lead['campaign_id'] ?? ''),
                        'notes' => (string) ($lead['notes'] ?? ''),
                        'email_subject' => '',
                        'email_draft' => '',
                        'email_final' => '',
                    ];
                    $resetPayload = apply_template($resetPayload, $lead, 'polonads_intro_v1');
                    $resetPayload = apply_operator_action($resetPayload, 'reset_and_prepare_intro');
                    if (isset($resetPayload['meta']) && is_array($resetPayload['meta'])) {
                        $repository->updateLeadDraftReview($leadId, $resetPayload, 'operator');
                    } else {
                        $repository->updateLeadWorkflow($leadId, $resetPayload);
                    }
                    header('Location: ' . Support::baseUrl($config, 'lead.php?id=' . $leadId . '&success=' . urlencode('Lead zresetowany i przygotowany do ponownej wysylki zaproszenia')));
                    exit;
                }

                header('Location: ' . Support::baseUrl($config, 'lead.php?id=' . $leadId . '&success=' . urlencode('Lead zresetowany do stanu po imporcie CSV')));
                exit;
            }

            if ($operatorAction === 'simulate_ai_draft') {
                $aiDraftService = new AiDraftExchangeService($repository, $config);
                $aiDraftService->simulateDraftForLead($leadId);
                header('Location: ' . Support::baseUrl($config, 'lead.php?id=' . $leadId . '&success=' . urlencode('Symulowany draft AI zapisany')));
                exit;
            }

            if ($operatorAction === 'generate_ai_draft') {
                $aiDraftService = new AiDraftExchangeService($repository, $config);
                $aiDraftService->generateDraftForLead($leadId);
                header('Location: ' . Support::baseUrl($config, 'lead.php?id=' . $leadId . '&success=' . urlencode('Draft AI wygenerowany na podstawie strony WWW')));
                exit;
            }

            if ($templateAction !== '') {
                $payload = apply_template($payload, $lead, $templateAction);
            }
            $payload = apply_operator_action($payload, $operatorAction);
            if (isset($payload['meta']) && is_array($payload['meta'])) {
                $repository->updateLeadDraftReview($leadId, $payload, 'operator');
            } else {
                $repository->updateLeadWorkflow($leadId, $payload);
            }
            if ($templateAction !== '') {
                $success = 'Szablon maila wstawiony';
            } elseif ($operatorAction !== '') {
                $success = 'Akcja operatora wykonana';
            } else {
                $success = 'Workflow leada zapisany';
            }
            header('Location: ' . Support::baseUrl($config, 'lead.php?id=' . $leadId . '&success=' . urlencode($success)));
            exit;
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
            $lead = $repository->findLeadById($leadId) ?? $lead;
        }
    }
}

$workflowEvents = $repository->fetchWorkflowEvents($leadId);
$sendAttempts = $repository->fetchSendAttempts($leadId);
$leadMeta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
$aiExportUrl = Support::publicUrl(
    $config,
    'ai_export.php?lead=' . $leadId . '&token=' . rawurlencode(Support::signLeadAction($config, $leadId, 'ai_export'))
);
$polonadsCategory = is_array($leadMeta['polonads_category'] ?? null) ? $leadMeta['polonads_category'] : [];
$polonadsRegion = is_array($leadMeta['polonads_region'] ?? null) ? $leadMeta['polonads_region'] : [];
$publicationPayload = (new PublicationPayloadBuilder($config))->build($lead);
$effectiveCategory = is_array($publicationPayload['trace']['category_mapping'] ?? null) ? $publicationPayload['trace']['category_mapping'] : [];
$autoCategory = is_array($publicationPayload['trace']['auto_category_mapping'] ?? null) ? $publicationPayload['trace']['auto_category_mapping'] : [];

$flashSuccess = $_GET['success'] ?? '';
$campaignNames = [];
foreach ($availableCampaigns as $campaign) {
    $campaignNames[(string) $campaign['id']] = (string) ($campaign['name'] ?? $campaign['id']);
}
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title><?= Support::escape($lead['company_name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= Support::escape(Support::baseUrl($config, 'assets/app.css')) ?>">
</head>
<body>
<div class="shell">
    <section class="hero">
        <p class="muted">Szczegóły workflow leada.</p>
        <h1><?= Support::escape($lead['company_name']) ?></h1>
        <p>
            <?= Support::escape($lead['primary_email']) ?>
            <?php if ($lead['website'] !== ''): ?>
                | <a href="<?= Support::escape($lead['website']) ?>" target="_blank" rel="noreferrer">Strona WWW</a>
            <?php endif; ?>
            <?php if ($lead['yp_url'] !== ''): ?>
                | <a href="<?= Support::escape($lead['yp_url']) ?>" target="_blank" rel="noreferrer">Yellow Pages</a>
            <?php endif; ?>
        </p>
        <div class="actions">
            <a class="button secondary" href="<?= Support::escape(Support::baseUrl($config, 'leads.php')) ?>">Powrót do leadów</a>
            <a class="button secondary" href="<?= Support::escape(Support::baseUrl($config, 'publication.php?id=' . $leadId)) ?>">Podgląd publikacji</a>
        </div>
    </section>

    <?php if ($flashSuccess !== ''): ?>
        <div class="flash success"><?= Support::escape($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="flash error"><?= Support::escape($error) ?></div>
    <?php endif; ?>

    <section class="grid">
        <article class="card">
            <h2>Dane leada</h2>
            <p><strong>Lokalizacja:</strong> <?= Support::escape(trim($lead['city'] . ', ' . $lead['state'], ', ')) ?></p>
            <p><strong>Kategoria źródłowa:</strong> <?= Support::escape($lead['category']) ?></p>
            <p><strong>Telefon:</strong> <?= Support::escape($lead['phone']) ?></p>
            <p><strong>Adres:</strong> <?= Support::escape($lead['address']) ?></p>
            <p><strong>Wszystkie emaile:</strong> <?= Support::escape($lead['all_emails']) ?></p>
            <p><strong>Liczba emaili:</strong> <?= Support::escape((string) $lead['email_count']) ?></p>
            <p><strong>Do mailingu:</strong> <?= Support::escape(Support::yesNo((int) $lead['is_mailable'] === 1)) ?></p>
            <p><strong>Status źródła:</strong> <?= Support::escape($lead['source_status']) ?></p>
        </article>

        <article class="card">
            <h2>Stan workflow</h2>
            <p><strong>Zatwierdzenie:</strong> <?= Support::escape(Support::approvalLabel($lead['approval_status'])) ?></p>
            <p><strong>Kontakt:</strong> <?= Support::escape(Support::contactLabel($lead['contact_status'])) ?></p>
            <p><strong>Kampania:</strong> <?= Support::escape($campaignNames[(string) $lead['campaign_id']] ?? (string) $lead['campaign_id']) ?></p>
            <p><strong>Próby wysyłki:</strong> <?= Support::escape((string) $lead['send_attempts']) ?></p>
            <p><strong>Ostatni błąd:</strong> <?= Support::escape($lead['last_error']) ?></p>
            <p><strong>Ostatni kontakt:</strong> <?= Support::escape((string) $lead['last_contacted_at']) ?></p>
        </article>
    </section>

    <section class="grid" style="margin-top: 24px;">
        <article class="card">
            <h2>Kategoria publikacji</h2>
            <p><strong>Aktywna:</strong> <?= Support::escape((string) ($effectiveCategory['name'] ?? '')) ?><?= isset($effectiveCategory['id']) ? ' (' . Support::escape((string) $effectiveCategory['id']) . ')' : '' ?></p>
            <p><strong>Źródło:</strong> <?= Support::escape((string) ($effectiveCategory['source'] ?? '')) ?></p>
            <p><strong>Powód:</strong> <?= Support::escape((string) ($effectiveCategory['reason'] ?? '')) ?></p>
            <p><strong>Wymaga sprawdzenia:</strong> <?= Support::escape(Support::yesNo(($effectiveCategory['requires_review'] ?? false) === true)) ?></p>
            <?php if ($autoCategory !== []): ?>
                <hr>
                <p><strong>Sugestia z importu:</strong> <?= Support::escape((string) ($autoCategory['name'] ?? '')) ?><?= isset($autoCategory['id']) ? ' (' . Support::escape((string) $autoCategory['id']) . ')' : '' ?></p>
            <?php endif; ?>
        </article>

        <article class="card">
            <h2>Region Polonads</h2>
            <p><strong>Sugerowany:</strong> <?= Support::escape((string) ($polonadsRegion['name'] ?? '')) ?><?= isset($polonadsRegion['id']) ? ' (' . Support::escape((string) $polonadsRegion['id']) . ')' : '' ?></p>
            <p><strong>Poziom:</strong> <?= Support::escape((string) ($polonadsRegion['level'] ?? '')) ?></p>
            <p><strong>Pewność:</strong> <?= Support::escape((string) ($polonadsRegion['confidence'] ?? '')) ?></p>
            <p><strong>Dopasowany stan:</strong> <?= Support::escape((string) ($polonadsRegion['matched_state'] ?? '')) ?></p>
            <p><strong>Wymaga sprawdzenia:</strong> <?= Support::escape(Support::yesNo(($polonadsRegion['requires_review'] ?? false) === true)) ?></p>
            <p><strong>Powód:</strong> <?= Support::escape((string) ($polonadsRegion['reason'] ?? '')) ?></p>
        </article>
    </section>

    <section class="card" style="margin-top: 24px;">
        <h2>Draft AI / ogłoszenia</h2>
        <p><strong>Status draftu AI:</strong> <?= Support::escape((string) ($leadMeta['ai_draft_status'] ?? 'brak')) ?></p>
        <p><strong>Tytuł ogłoszenia:</strong> <?= Support::escape((string) ($leadMeta['listing_title'] ?? '')) ?></p>
        <p><strong>Język ogłoszenia:</strong> <?= Support::escape(Support::workflowValueLabel('listing_language', (string) ($leadMeta['listing_language'] ?? ''))) ?></p>
        <p><strong>Wygenerowano:</strong> <?= Support::escape((string) ($leadMeta['ai_generated_at'] ?? '')) ?></p>
        <p><strong>Źródło draftu:</strong> <?= Support::escape(Support::workflowValueLabel('ai_provider', (string) ($leadMeta['ai_provider'] ?? ''))) ?></p>
        <p><strong>API export JSON:</strong> <a href="<?= Support::escape($aiExportUrl) ?>" target="_blank" rel="noreferrer">pobierz payload dla modułu AI</a></p>
        <div class="actions">
            <form method="post" class="inline-action-form">
                <input type="hidden" name="contact_status" value="<?= Support::escape($lead['contact_status']) ?>">
                <input type="hidden" name="approval_status" value="<?= Support::escape($lead['approval_status']) ?>">
                <input type="hidden" name="campaign_id" value="<?= Support::escape($lead['campaign_id']) ?>">
                <input type="hidden" name="notes" value="<?= Support::escape($lead['notes']) ?>">
                <input type="hidden" name="email_subject" value="<?= Support::escape($lead['email_subject']) ?>">
                <input type="hidden" name="email_draft" value="<?= Support::escape($lead['email_draft']) ?>">
                <input type="hidden" name="email_final" value="<?= Support::escape($lead['email_final']) ?>">
                <input type="hidden" name="operator_action" value="generate_ai_draft">
                <input type="hidden" name="template_action" value="">
                <button type="submit" class="button secondary">Generuj draft AI z WWW</button>
            </form>
        </div>
        <?php if (trim((string) ($leadMeta['listing_body'] ?? '')) !== ''): ?>
            <details>
                <summary>Pokaż aktualny draft ogłoszenia</summary>
                <pre><?= Support::escape((string) ($leadMeta['listing_body'] ?? '')) ?></pre>
            </details>
        <?php endif; ?>
    </section>

    <section class="card" style="margin-top: 24px;">
        <h2>Dane do publikacji</h2>
        <p><strong>Status:</strong> <?= Support::escape((string) ($publicationPayload['status'] ?? '')) ?></p>
        <?php $payloadWarnings = is_array($publicationPayload['warnings'] ?? null) ? $publicationPayload['warnings'] : []; ?>
        <?php if ($payloadWarnings === []): ?>
            <p class="muted">Dane wyglądają na kompletne do kolejnego etapu publikacji.</p>
        <?php else: ?>
            <p><strong>Ostrzeżenia:</strong></p>
            <ul>
                <?php foreach ($payloadWarnings as $warning): ?>
                    <li><?= Support::escape((string) $warning) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <details>
            <summary>Pokaż techniczny podgląd danych</summary>
            <pre><?= Support::escape(json_encode($publicationPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}') ?></pre>
        </details>
    </section>

    <section class="card" style="margin-top: 24px;">
        <h2>Akcje publikacji</h2>
        <p class="muted">Otwórz ekran publikacji, aby utworzyć lub zaktualizować konto Joomla, profil DJCF i ogłoszenie DJCF.</p>
        <div class="actions">
            <a class="button" href="<?= Support::escape(Support::baseUrl($config, 'publication.php?id=' . $leadId)) ?>">Otwórz ekran publikacji</a>
        </div>
    </section>

    <section class="card" style="margin-top: 24px;">
        <h2>Edycja workflow</h2>
        <p class="muted">
            Draft to wersja robocza operatora. Final email to dopracowana wersja, którą sender wybierze w pierwszej kolejności.
        </p>
        <div class="template-actions">
            <form method="post" class="inline-action-form">
                <input type="hidden" name="contact_status" value="<?= Support::escape($lead['contact_status']) ?>">
                <input type="hidden" name="approval_status" value="<?= Support::escape($lead['approval_status']) ?>">
                <input type="hidden" name="campaign_id" value="<?= Support::escape($lead['campaign_id']) ?>">
                <input type="hidden" name="notes" value="<?= Support::escape($lead['notes']) ?>">
                <input type="hidden" name="email_subject" value="<?= Support::escape($lead['email_subject']) ?>">
                <input type="hidden" name="email_draft" value="<?= Support::escape($lead['email_draft']) ?>">
                <input type="hidden" name="email_final" value="<?= Support::escape($lead['email_final']) ?>">
                <input type="hidden" name="operator_action" value="">
                <input type="hidden" name="template_action" value="polonads_intro_v1">
                <button type="submit" class="button">Wstaw mail z zapytaniem</button>
            </form>

            <form method="post" class="inline-action-form">
                <input type="hidden" name="contact_status" value="<?= Support::escape($lead['contact_status']) ?>">
                <input type="hidden" name="approval_status" value="<?= Support::escape($lead['approval_status']) ?>">
                <input type="hidden" name="campaign_id" value="<?= Support::escape($lead['campaign_id']) ?>">
                <input type="hidden" name="notes" value="<?= Support::escape($lead['notes']) ?>">
                <input type="hidden" name="email_subject" value="<?= Support::escape($lead['email_subject']) ?>">
                <input type="hidden" name="email_draft" value="<?= Support::escape($lead['email_draft']) ?>">
                <input type="hidden" name="email_final" value="<?= Support::escape($lead['email_final']) ?>">
                <input type="hidden" name="operator_action" value="">
                <input type="hidden" name="template_action" value="polonads_followup_v1">
                <button type="submit" class="button secondary">Wstaw follow-up</button>
            </form>

            <form method="post" class="inline-action-form">
                <input type="hidden" name="contact_status" value="<?= Support::escape($lead['contact_status']) ?>">
                <input type="hidden" name="approval_status" value="<?= Support::escape($lead['approval_status']) ?>">
                <input type="hidden" name="campaign_id" value="<?= Support::escape($lead['campaign_id']) ?>">
                <input type="hidden" name="notes" value="<?= Support::escape($lead['notes']) ?>">
                <input type="hidden" name="email_subject" value="<?= Support::escape($lead['email_subject']) ?>">
                <input type="hidden" name="email_draft" value="<?= Support::escape($lead['email_draft']) ?>">
                <input type="hidden" name="email_final" value="<?= Support::escape($lead['email_final']) ?>">
                <input type="hidden" name="operator_action" value="">
                <input type="hidden" name="template_action" value="polonads_interest_reply_v1">
                <button type="submit" class="button soft">Wstaw odpowiedź na zainteresowanie</button>
            </form>

            <form method="post" class="inline-action-form">
                <input type="hidden" name="contact_status" value="<?= Support::escape($lead['contact_status']) ?>">
                <input type="hidden" name="approval_status" value="<?= Support::escape($lead['approval_status']) ?>">
                <input type="hidden" name="campaign_id" value="<?= Support::escape($lead['campaign_id']) ?>">
                <input type="hidden" name="notes" value="<?= Support::escape($lead['notes']) ?>">
                <input type="hidden" name="email_subject" value="<?= Support::escape($lead['email_subject']) ?>">
                <input type="hidden" name="email_draft" value="<?= Support::escape($lead['email_draft']) ?>">
                <input type="hidden" name="email_final" value="<?= Support::escape($lead['email_final']) ?>">
                <input type="hidden" name="operator_action" value="">
                <input type="hidden" name="template_action" value="polonads_draft_review_v1">
                <button type="submit" class="button">Wstaw mail z draftem do akceptacji</button>
            </form>

            <form method="post" class="inline-action-form">
                <input type="hidden" name="contact_status" value="<?= Support::escape($lead['contact_status']) ?>">
                <input type="hidden" name="approval_status" value="<?= Support::escape($lead['approval_status']) ?>">
                <input type="hidden" name="campaign_id" value="<?= Support::escape($lead['campaign_id']) ?>">
                <input type="hidden" name="notes" value="<?= Support::escape($lead['notes']) ?>">
                <input type="hidden" name="email_subject" value="<?= Support::escape($lead['email_subject']) ?>">
                <input type="hidden" name="email_draft" value="<?= Support::escape($lead['email_draft']) ?>">
                <input type="hidden" name="email_final" value="<?= Support::escape($lead['email_final']) ?>">
                <input type="hidden" name="operator_action" value="">
                <input type="hidden" name="template_action" value="polonads_published_v1">
                <button type="submit" class="button secondary">Wstaw mail po publikacji</button>
            </form>

            <form method="post" class="inline-action-form">
                <input type="hidden" name="contact_status" value="<?= Support::escape($lead['contact_status']) ?>">
                <input type="hidden" name="approval_status" value="<?= Support::escape($lead['approval_status']) ?>">
                <input type="hidden" name="campaign_id" value="<?= Support::escape($lead['campaign_id']) ?>">
                <input type="hidden" name="notes" value="<?= Support::escape($lead['notes']) ?>">
                <input type="hidden" name="email_subject" value="<?= Support::escape($lead['email_subject']) ?>">
                <input type="hidden" name="email_draft" value="<?= Support::escape($lead['email_draft']) ?>">
                <input type="hidden" name="email_final" value="<?= Support::escape($lead['email_final']) ?>">
                <input type="hidden" name="operator_action" value="">
                <input type="hidden" name="template_action" value="polonads_self_publish_v1">
                <button type="submit" class="button soft">Wstaw mail self-publish</button>
            </form>

            <form method="post" class="inline-action-form">
                <input type="hidden" name="contact_status" value="<?= Support::escape($lead['contact_status']) ?>">
                <input type="hidden" name="approval_status" value="<?= Support::escape($lead['approval_status']) ?>">
                <input type="hidden" name="campaign_id" value="<?= Support::escape($lead['campaign_id']) ?>">
                <input type="hidden" name="notes" value="<?= Support::escape($lead['notes']) ?>">
                <input type="hidden" name="email_subject" value="<?= Support::escape($lead['email_subject']) ?>">
                <input type="hidden" name="email_draft" value="<?= Support::escape($lead['email_draft']) ?>">
                <input type="hidden" name="email_final" value="<?= Support::escape($lead['email_final']) ?>">
                <input type="hidden" name="operator_action" value="">
                <input type="hidden" name="template_action" value="polonads_unsubscribe_confirm_v1">
                <button type="submit" class="button danger">Wstaw potwierdzenie wypisu</button>
            </form>
        </div>
        <div class="quick-actions">
            <form method="post" class="inline-action-form">
                <input type="hidden" name="contact_status" value="<?= Support::escape($lead['contact_status']) ?>">
                <input type="hidden" name="approval_status" value="<?= Support::escape($lead['approval_status']) ?>">
                <input type="hidden" name="campaign_id" value="<?= Support::escape($lead['campaign_id']) ?>">
                <input type="hidden" name="notes" value="<?= Support::escape($lead['notes']) ?>">
                <input type="hidden" name="email_subject" value="<?= Support::escape($lead['email_subject']) ?>">
                <input type="hidden" name="email_draft" value="<?= Support::escape($lead['email_draft']) ?>">
                <input type="hidden" name="email_final" value="<?= Support::escape($lead['email_final']) ?>">
                <input type="hidden" name="operator_action" value="reset_test_flow">
                <button type="submit" class="button soft">Reset do stanu po imporcie</button>
            </form>

            <form method="post" class="inline-action-form">
                <input type="hidden" name="contact_status" value="<?= Support::escape($lead['contact_status']) ?>">
                <input type="hidden" name="approval_status" value="<?= Support::escape($lead['approval_status']) ?>">
                <input type="hidden" name="campaign_id" value="<?= Support::escape($lead['campaign_id']) ?>">
                <input type="hidden" name="notes" value="<?= Support::escape($lead['notes']) ?>">
                <input type="hidden" name="email_subject" value="<?= Support::escape($lead['email_subject']) ?>">
                <input type="hidden" name="email_draft" value="<?= Support::escape($lead['email_draft']) ?>">
                <input type="hidden" name="email_final" value="<?= Support::escape($lead['email_final']) ?>">
                <input type="hidden" name="operator_action" value="reset_and_prepare_intro">
                <button type="submit" class="button">Reset i przygotuj zaproszenie</button>
            </form>

            <form method="post" class="inline-action-form">
                <input type="hidden" name="contact_status" value="<?= Support::escape($lead['contact_status']) ?>">
                <input type="hidden" name="approval_status" value="<?= Support::escape($lead['approval_status']) ?>">
                <input type="hidden" name="campaign_id" value="<?= Support::escape($lead['campaign_id']) ?>">
                <input type="hidden" name="notes" value="<?= Support::escape($lead['notes']) ?>">
                <input type="hidden" name="email_subject" value="<?= Support::escape($lead['email_subject']) ?>">
                <input type="hidden" name="email_draft" value="<?= Support::escape($lead['email_draft']) ?>">
                <input type="hidden" name="email_final" value="<?= Support::escape($lead['email_final']) ?>">
                <input type="hidden" name="operator_action" value="copy_draft_to_final">
                <button type="submit" class="button secondary">Kopiuj draft do final</button>
            </form>

            <form method="post" class="inline-action-form">
                <input type="hidden" name="contact_status" value="<?= Support::escape($lead['contact_status']) ?>">
                <input type="hidden" name="approval_status" value="<?= Support::escape($lead['approval_status']) ?>">
                <input type="hidden" name="campaign_id" value="<?= Support::escape($lead['campaign_id']) ?>">
                <input type="hidden" name="notes" value="<?= Support::escape($lead['notes']) ?>">
                <input type="hidden" name="email_subject" value="<?= Support::escape($lead['email_subject']) ?>">
                <input type="hidden" name="email_draft" value="<?= Support::escape($lead['email_draft']) ?>">
                <input type="hidden" name="email_final" value="<?= Support::escape($lead['email_final']) ?>">
                <input type="hidden" name="operator_action" value="mark_draft_ready">
                <button type="submit" class="button secondary">Oznacz draft jako gotowy</button>
            </form>

            <form method="post" class="inline-action-form">
                <input type="hidden" name="contact_status" value="<?= Support::escape($lead['contact_status']) ?>">
                <input type="hidden" name="approval_status" value="<?= Support::escape($lead['approval_status']) ?>">
                <input type="hidden" name="campaign_id" value="<?= Support::escape($lead['campaign_id']) ?>">
                <input type="hidden" name="notes" value="<?= Support::escape($lead['notes']) ?>">
                <input type="hidden" name="email_subject" value="<?= Support::escape($lead['email_subject']) ?>">
                <input type="hidden" name="email_draft" value="<?= Support::escape($lead['email_draft']) ?>">
                <input type="hidden" name="email_final" value="<?= Support::escape($lead['email_final']) ?>">
                <input type="hidden" name="operator_action" value="approve_for_sending">
                <button type="submit" class="button">Zatwierdź do wysyłki</button>
            </form>

            <form method="post" class="inline-action-form">
                <input type="hidden" name="contact_status" value="<?= Support::escape($lead['contact_status']) ?>">
                <input type="hidden" name="approval_status" value="<?= Support::escape($lead['approval_status']) ?>">
                <input type="hidden" name="campaign_id" value="<?= Support::escape($lead['campaign_id']) ?>">
                <input type="hidden" name="notes" value="<?= Support::escape($lead['notes']) ?>">
                <input type="hidden" name="email_subject" value="<?= Support::escape($lead['email_subject']) ?>">
                <input type="hidden" name="email_draft" value="<?= Support::escape($lead['email_draft']) ?>">
                <input type="hidden" name="email_final" value="<?= Support::escape($lead['email_final']) ?>">
                <input type="hidden" name="operator_action" value="return_to_editing">
                <button type="submit" class="button soft">Wróć do edycji</button>
            </form>

            <form method="post" class="inline-action-form">
                <input type="hidden" name="contact_status" value="<?= Support::escape($lead['contact_status']) ?>">
                <input type="hidden" name="approval_status" value="<?= Support::escape($lead['approval_status']) ?>">
                <input type="hidden" name="campaign_id" value="<?= Support::escape($lead['campaign_id']) ?>">
                <input type="hidden" name="notes" value="<?= Support::escape($lead['notes']) ?>">
                <input type="hidden" name="email_subject" value="<?= Support::escape($lead['email_subject']) ?>">
                <input type="hidden" name="email_draft" value="<?= Support::escape($lead['email_draft']) ?>">
                <input type="hidden" name="email_final" value="<?= Support::escape($lead['email_final']) ?>">
                <input type="hidden" name="operator_action" value="mark_rejected">
                <button type="submit" class="button danger">Odrzuć lead</button>
            </form>
        </div>
        <form class="form-grid" method="post">
            <input type="hidden" name="operator_action" value="">
            <input type="hidden" name="template_action" value="">
            <div class="filter-grid">
                <div>
                    <label for="approval_status">Status zatwierdzenia</label>
                    <select id="approval_status" name="approval_status">
                        <?php foreach (['pending', 'approved', 'rejected'] as $status): ?>
                            <option value="<?= Support::escape($status) ?>" <?= $lead['approval_status'] === $status ? 'selected' : '' ?>>
                                <?= Support::escape(Support::approvalLabel($status)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="contact_status">Status kontaktu</label>
                    <select id="contact_status" name="contact_status">
                        <?php foreach (['new', 'draft_ready', 'approved', 'client_review', 'published', 'sent', 'replied', 'skipped', 'failed'] as $status): ?>
                            <option value="<?= Support::escape($status) ?>" <?= $lead['contact_status'] === $status ? 'selected' : '' ?>>
                                <?= Support::escape(Support::contactLabel($status)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="campaign_id">Kampania</label>
                    <select id="campaign_id" name="campaign_id">
                        <option value="">Brak kampanii</option>
                        <?php foreach ($availableCampaigns as $campaign): ?>
                            <option value="<?= Support::escape((string) $campaign['id']) ?>" <?= (string) $lead['campaign_id'] === (string) $campaign['id'] ? 'selected' : '' ?>>
                                <?= Support::escape((string) $campaign['name']) ?> | <?= Support::escape((string) $campaign['id']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label for="email_subject">Temat emaila</label>
                <input id="email_subject" name="email_subject" type="text" value="<?= Support::escape($lead['email_subject']) ?>">
            </div>

            <div>
                <label for="email_draft">Draft emaila</label>
                <textarea id="email_draft" name="email_draft" rows="10"><?= Support::escape($lead['email_draft']) ?></textarea>
            </div>

            <div>
                <label for="email_final">Finalna wersja emaila</label>
                <textarea id="email_final" name="email_final" rows="10"><?= Support::escape($lead['email_final']) ?></textarea>
            </div>

            <div>
                <label for="notes">Notatki wewnętrzne</label>
                <textarea id="notes" name="notes" rows="6"><?= Support::escape($lead['notes']) ?></textarea>
            </div>

            <div class="actions">
                <button type="submit">Zapisz workflow</button>
            </div>
        </form>
    </section>

    <section class="grid" style="margin-top: 24px;">
        <article class="card">
            <h2>Historia workflow</h2>
            <table class="workflow-table">
                <thead>
                <tr>
                    <th>Kiedy</th>
                    <th>Typ</th>
                    <th>Zmiana</th>
                    <th>Komunikat</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($workflowEvents === []): ?>
                    <tr><td colspan="4" class="muted">Brak zdarzeń workflow.</td></tr>
                <?php else: ?>
                    <?php foreach ($workflowEvents as $event): ?>
                        <tr>
                            <td><?= Support::escape($event['created_at']) ?></td>
                            <td><?= Support::escape(Support::workflowEventLabel((string) $event['event_type'])) ?></td>
                            <td>
                                <?= Support::escape(Support::workflowValueLabel((string) $event['event_type'], (string) $event['from_value'])) ?>
                                &rarr;
                                <?= Support::escape(Support::workflowValueLabel((string) $event['event_type'], (string) $event['to_value'])) ?>
                            </td>
                            <td><?= Support::escape(Support::workflowMessageLabel((string) $event['message'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2>Próby wysyłki</h2>
            <table>
                <thead>
                <tr>
                    <th>Kiedy</th>
                    <th>Status</th>
                    <th>Odbiorca</th>
                    <th>Komunikat</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($sendAttempts === []): ?>
                    <tr><td colspan="4" class="muted">Brak prób wysyłki.</td></tr>
                <?php else: ?>
                    <?php foreach ($sendAttempts as $attempt): ?>
                        <tr>
                            <td><?= Support::escape($attempt['created_at']) ?></td>
                            <td><?= Support::escape($attempt['status']) ?></td>
                            <td><?= Support::escape($attempt['recipient_email']) ?></td>
                            <td><?= Support::escape($attempt['error_message'] !== '' ? $attempt['error_message'] : 'Wysłano poprawnie.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </article>
    </section>
</div>
</body>
</html>
