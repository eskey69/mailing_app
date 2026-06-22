<?php

declare(strict_types=1);

use MailingApp\Database;
use MailingApp\LeadMeta;
use MailingApp\LeadRepository;
use MailingApp\Support;

$config = require dirname(__DIR__) . '/bootstrap.php';

$database = new Database($config);
$repository = new LeadRepository($database->pdo());

$dashboard = $repository->fetchDashboardStats();
$workflowSummary = $repository->fetchWorkflowSummary();
$sendSummary = $repository->fetchSendQueueSummary();
$publicationSummary = $repository->fetchPublicationQueueSummary();
$latestIssues = $repository->fetchLatestIssues(8);
$attentionLeads = $repository->fetchAttentionLeads(6);
$waitingForClientLeads = $repository->fetchWaitingForClientLeads(6);
$readyToSendLeads = $repository->fetchSendQueueReady(6);
$readyToPublishLeads = $repository->fetchPublicationQueueReady(6);
$completedLeads = $repository->fetchCompletedLeads(6);

$flashSuccess = $_GET['success'] ?? '';
$flashError = $_GET['error'] ?? '';
$totals = $dashboard['totals'] ?? [];
$latestBatch = $dashboard['latest_batch'] ?? null;

function dashboard_lane_meta(array $lead): string
{
    $location = trim((string) ($lead['city'] ?? '') . ', ' . (string) ($lead['state'] ?? ''), ', ');
    $email = trim((string) ($lead['primary_email'] ?? ''));
    return implode(' • ', array_filter([$location, $email]));
}

function dashboard_lane_reason(array $lead): string
{
    $meta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
    $translationStatus = trim((string) ($meta['translation_status'] ?? ''));

    if ($translationStatus === 'requested') {
        return 'Czeka na uruchomienie tlumaczenia PL.';
    }
    if ($translationStatus === 'in_progress') {
        return 'AI przygotowuje wersje polska.';
    }
    if ($translationStatus === 'failed') {
        return 'Automatyczne tlumaczenie wymaga interwencji.';
    }
    if ((string) ($lead['contact_status'] ?? '') === 'failed') {
        return 'Ostatnia akcja zakonczyla sie bledem.';
    }
    if ((string) ($lead['approval_status'] ?? '') === 'pending') {
        return 'Lead czeka na decyzje lub dopracowanie tresci.';
    }

    $hasSubject = trim((string) ($lead['email_subject'] ?? '')) !== '';
    $hasBody = trim((string) ($lead['email_final'] ?? '')) !== '' || trim((string) ($lead['email_draft'] ?? '')) !== '';
    if (!$hasSubject || !$hasBody) {
        return 'Brakuje tematu albo tresci do wysylki.';
    }

    return 'Wymaga sprawdzenia przez operatora.';
}

function dashboard_client_state(array $lead): string
{
    $meta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
    $publicationStatus = trim((string) ($meta['publication_status'] ?? ''));
    $translationStatus = trim((string) ($meta['translation_status'] ?? ''));
    $parts = [];

    if ($publicationStatus !== '') {
        $parts[] = Support::workflowValueLabel('publication_status', $publicationStatus);
    }
    if ($translationStatus !== '') {
        $parts[] = Support::workflowValueLabel('translation_status', $translationStatus);
    }

    return $parts === [] ? Support::contactLabel((string) ($lead['contact_status'] ?? '')) : implode(' / ', $parts);
}
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title><?= Support::escape($config['app']['name'] ?? 'Mailing App') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= Support::escape(Support::baseUrl($config, 'assets/app.css')) ?>">
</head>
<body>
<div class="shell">
    <section class="hero">
        <div class="hero-grid">
            <div>
                <span class="eyebrow">Mailing Control Center</span>
                <h1><?= Support::escape($config['app']['name'] ?? 'Mailing App') ?></h1>
                <p>
                    Jeden pulpit do prostego workflow: import, uwaga operatora, wysylka, odpowiedzi klienta
                    i publikacja. Wersja PL po prosbie klienta uruchamia sie teraz automatycznie przez warstwe AI.
                </p>
                <div class="nav-strip">
                    <a class="button" href="<?= Support::escape(Support::baseUrl($config, 'import.php')) ?>">Import CSV</a>
                    <a class="button secondary" href="<?= Support::escape(Support::baseUrl($config, 'leads.php')) ?>">Leady</a>
                    <a class="button ghost" href="<?= Support::escape(Support::baseUrl($config, 'queue.php')) ?>">Gotowe do wysylki</a>
                    <a class="button ghost" href="<?= Support::escape(Support::baseUrl($config, 'publication_queue.php')) ?>">Gotowe do publikacji</a>
                    <a class="button ghost" href="<?= Support::escape(Support::baseUrl($config, 'campaigns.php')) ?>">Kampanie</a>
                </div>
            </div>

            <div class="hero-notes">
                <span class="tag">Aktywny test send: lead #1236</span>
                <span class="tag">SMTP guard: eskey69@gmail.com only</span>
                <?php if ($latestBatch !== null): ?>
                    <span class="tag">Ostatni import: <?= Support::escape((string) $latestBatch['original_filename']) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php if ($flashSuccess !== ''): ?>
        <div class="flash success"><?= Support::escape($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
        <div class="flash error"><?= Support::escape($flashError) ?></div>
    <?php endif; ?>

    <section class="grid">
        <article class="metric-card">
            <p class="metric-kicker">Wymaga Uwagi</p>
            <strong><?= Support::escape((string) ($workflowSummary['needs_attention'] ?? 0)) ?></strong>
            <p class="metric-meta">Pending, bledy, blokady tresci i kolejka tlumaczen.</p>
        </article>
        <article class="metric-card">
            <p class="metric-kicker">Gotowe Do Wysylki</p>
            <strong><?= Support::escape((string) ($sendSummary['ready_to_send'] ?? 0)) ?></strong>
            <p class="metric-meta">Leady zatwierdzone, z kompletnym tematem i trescia.</p>
        </article>
        <article class="metric-card">
            <p class="metric-kicker">Czeka Na Klienta</p>
            <strong><?= Support::escape((string) ($workflowSummary['waiting_for_client'] ?? 0)) ?></strong>
            <p class="metric-meta">Wyslane, w review albo po odpowiedzi klienta.</p>
        </article>
        <article class="metric-card">
            <p class="metric-kicker">Gotowe Do Publikacji</p>
            <strong><?= Support::escape((string) ($publicationSummary['ready_to_publish'] ?? 0)) ?></strong>
            <p class="metric-meta">Draft zaakceptowany i gotowy do wrzucenia na Polonads.</p>
        </article>
    </section>

    <section class="workflow-board">
        <article class="lane">
            <div class="lane-head">
                <div>
                    <h3>1. Wymaga uwagi</h3>
                    <p class="card-subtitle">Operator widzi tylko rekordy, ktore realnie blokuja proces.</p>
                </div>
                <div class="lane-count"><?= Support::escape((string) ($workflowSummary['needs_attention'] ?? 0)) ?></div>
            </div>
            <div class="lane-list">
                <?php if ($attentionLeads === []): ?>
                    <div class="empty-state">Brak otwartych blokad w workflow.</div>
                <?php else: ?>
                    <?php foreach ($attentionLeads as $lead): ?>
                        <article class="lane-item">
                            <h4><a href="<?= Support::escape(Support::baseUrl($config, 'lead.php?id=' . $lead['id'])) ?>"><?= Support::escape((string) $lead['company_name']) ?></a></h4>
                            <p><?= Support::escape(dashboard_lane_reason($lead)) ?></p>
                            <div class="micro"><?= Support::escape(dashboard_lane_meta($lead)) ?></div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </article>

        <article class="lane">
            <div class="lane-head">
                <div>
                    <h3>2. Gotowe do wysylki</h3>
                    <p class="card-subtitle">Rekordy gotowe do partii SMTP po stronie operatora.</p>
                </div>
                <div class="lane-count"><?= Support::escape((string) ($sendSummary['ready_to_send'] ?? 0)) ?></div>
            </div>
            <div class="lane-list">
                <?php if ($readyToSendLeads === []): ?>
                    <div class="empty-state">Brak leadow gotowych do wysylki.</div>
                <?php else: ?>
                    <?php foreach ($readyToSendLeads as $lead): ?>
                        <article class="lane-item">
                            <h4><a href="<?= Support::escape(Support::baseUrl($config, 'lead.php?id=' . $lead['id'])) ?>"><?= Support::escape((string) $lead['company_name']) ?></a></h4>
                            <p><?= Support::escape((string) ($lead['email_subject'] ?? '')) ?></p>
                            <div class="micro"><?= Support::escape(dashboard_lane_meta($lead)) ?></div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </article>

        <article class="lane">
            <div class="lane-head">
                <div>
                    <h3>3. Czeka na klienta</h3>
                    <p class="card-subtitle">Odpowiedzi, review i automatyczne tlumaczenie PL.</p>
                </div>
                <div class="lane-count"><?= Support::escape((string) ($workflowSummary['waiting_for_client'] ?? 0)) ?></div>
            </div>
            <div class="lane-list">
                <?php if ($waitingForClientLeads === []): ?>
                    <div class="empty-state">Brak rekordow oczekujacych na klienta.</div>
                <?php else: ?>
                    <?php foreach ($waitingForClientLeads as $lead): ?>
                        <article class="lane-item">
                            <h4><a href="<?= Support::escape(Support::baseUrl($config, 'lead.php?id=' . $lead['id'])) ?>"><?= Support::escape((string) $lead['company_name']) ?></a></h4>
                            <p><?= Support::escape(dashboard_client_state($lead)) ?></p>
                            <div class="micro"><?= Support::escape(dashboard_lane_meta($lead)) ?></div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </article>

        <article class="lane">
            <div class="lane-head">
                <div>
                    <h3>4. Gotowe do publikacji</h3>
                    <p class="card-subtitle">Zaakceptowane drafty gotowe do puszczenia na produkcje.</p>
                </div>
                <div class="lane-count"><?= Support::escape((string) ($publicationSummary['ready_to_publish'] ?? 0)) ?></div>
            </div>
            <div class="lane-list">
                <?php if ($readyToPublishLeads === []): ?>
                    <div class="empty-state">Brak rekordow gotowych do publikacji.</div>
                <?php else: ?>
                    <?php foreach ($readyToPublishLeads as $lead): ?>
                        <article class="lane-item">
                            <h4><a href="<?= Support::escape(Support::baseUrl($config, 'publication.php?id=' . $lead['id'])) ?>"><?= Support::escape((string) $lead['company_name']) ?></a></h4>
                            <p><?= Support::escape((string) ($lead['campaign_id'] ?? '')) ?></p>
                            <div class="micro"><?= Support::escape(dashboard_lane_meta($lead)) ?></div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </article>
    </section>

    <section class="stack">
        <article class="card">
            <h2>Snapshot procesu</h2>
            <div class="info-grid">
                <div class="info-tile">
                    <span class="muted">Wszystkie leady</span>
                    <strong><?= Support::escape((string) ($totals['total_leads'] ?? 0)) ?></strong>
                </div>
                <div class="info-tile">
                    <span class="muted">Do mailingu</span>
                    <strong><?= Support::escape((string) ($totals['mailable_leads'] ?? 0)) ?></strong>
                </div>
                <div class="info-tile">
                    <span class="muted">Zatwierdzone</span>
                    <strong><?= Support::escape((string) ($totals['approved_leads'] ?? 0)) ?></strong>
                </div>
                <div class="info-tile">
                    <span class="muted">Wyslane</span>
                    <strong><?= Support::escape((string) ($totals['sent_leads'] ?? 0)) ?></strong>
                </div>
                <div class="info-tile">
                    <span class="muted">Blokady SMTP</span>
                    <strong><?= Support::escape((string) ($sendSummary['blocked_missing_content'] ?? 0)) ?></strong>
                </div>
                <div class="info-tile">
                    <span class="muted">Publikacje live</span>
                    <strong><?= Support::escape((string) ($publicationSummary['published_count'] ?? 0)) ?></strong>
                </div>
            </div>
        </article>

        <article class="card">
            <h2>Ostatni import i problemy</h2>
            <?php if ($latestBatch === null): ?>
                <p class="empty-state">Brak importow. Zacznij od wrzucenia pierwszego CSV.</p>
            <?php else: ?>
                <p><strong><?= Support::escape((string) $latestBatch['original_filename']) ?></strong></p>
                <p class="card-subtitle">
                    Wiersze: <?= Support::escape((string) $latestBatch['imported_rows']) ?> |
                    Mailable: <?= Support::escape((string) $latestBatch['imported_eligible_rows']) ?> |
                    Problemy: <?= Support::escape((string) $latestBatch['issue_count']) ?>
                </p>
                <p class="muted">Zaimportowano: <?= Support::escape((string) $latestBatch['created_at']) ?></p>
            <?php endif; ?>

            <div class="table-wrap" style="margin-top: 18px;">
                <table>
                    <thead>
                    <tr>
                        <th>Firma</th>
                        <th>Waga</th>
                        <th>Kod</th>
                        <th>Komunikat</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($latestIssues === []): ?>
                        <tr><td colspan="4" class="muted">Brak swiezych problemow importu.</td></tr>
                    <?php else: ?>
                        <?php foreach ($latestIssues as $issue): ?>
                            <?php $pillClass = $issue['severity'] === 'error' ? 'error' : ($issue['severity'] === 'warning' ? 'warn' : 'ok'); ?>
                            <tr>
                                <td><?= Support::escape((string) $issue['company_name']) ?></td>
                                <td><span class="pill <?= Support::escape($pillClass) ?>"><?= Support::escape((string) $issue['severity']) ?></span></td>
                                <td><?= Support::escape((string) $issue['issue_code']) ?></td>
                                <td><?= Support::escape((string) $issue['message']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>

        <article class="card">
            <h2>Zakonczone ostatnio</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Firma</th>
                        <th>Lokalizacja</th>
                        <th>Email</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($completedLeads === []): ?>
                        <tr><td colspan="4" class="muted">Brak zamknietych rekordow.</td></tr>
                    <?php else: ?>
                        <?php foreach ($completedLeads as $lead): ?>
                            <tr>
                                <td><a href="<?= Support::escape(Support::baseUrl($config, 'lead.php?id=' . $lead['id'])) ?>"><?= Support::escape((string) $lead['company_name']) ?></a></td>
                                <td><?= Support::escape(trim((string) ($lead['city'] ?? '') . ', ' . (string) ($lead['state'] ?? ''), ', ')) ?></td>
                                <td><?= Support::escape((string) ($lead['primary_email'] ?? '')) ?></td>
                                <td><?= Support::escape(Support::contactLabel((string) ($lead['contact_status'] ?? ''))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>
</div>
</body>
</html>
