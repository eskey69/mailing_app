<?php

declare(strict_types=1);

use MailingApp\Database;
use MailingApp\LeadRepository;
use MailingApp\Support;

$config = require dirname(__DIR__) . '/bootstrap.php';

$database = new Database($config);
$repository = new LeadRepository($database->pdo());

$summary = $repository->fetchSendQueueSummary();
$readyLeads = $repository->fetchSendQueueReady();
$blockedLeads = $repository->fetchSendQueueBlocked();
$sentLeads = $repository->fetchRecentlySent();
$failedLeads = $repository->fetchRecentlyFailed();

function queue_block_reason(array $lead): string
{
    $reasons = [];

    if (trim((string) $lead['email_subject']) === '') {
        $reasons[] = 'brak tematu';
    }

    if (trim((string) $lead['email_final']) === '' && trim((string) $lead['email_draft']) === '') {
        $reasons[] = 'brak treści draft/final';
    }

    return implode(', ', $reasons);
}
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>Kolejka wysyłki</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= Support::escape(Support::baseUrl($config, 'assets/app.css')) ?>">
</head>
<body>
<div class="shell">
    <section class="hero">
        <p class="muted">Kontrola rekordów przed wysyłką SMTP.</p>
        <h1>Kolejka wysyłki</h1>
        <p>
            Ten ekran pokazuje, które zatwierdzone leady są gotowe do wysłania,
            które są zablokowane oraz które próby wysyłki zakończyły się sukcesem lub błędem.
        </p>
        <div class="actions">
            <a class="button secondary" href="<?= Support::escape(Support::baseUrl($config, 'index.php')) ?>">Dashboard</a>
            <a class="button secondary" href="<?= Support::escape(Support::baseUrl($config, 'leads.php')) ?>">Leady</a>
        </div>
    </section>

    <section class="grid">
        <article class="card">
            <h2>Gotowe do wysyłki</h2>
            <strong><?= Support::escape((string) ($summary['ready_to_send'] ?? 0)) ?></strong>
        </article>
        <article class="card">
            <h2>Zablokowane</h2>
            <strong><?= Support::escape((string) ($summary['blocked_missing_content'] ?? 0)) ?></strong>
        </article>
        <article class="card">
            <h2>Wysłane</h2>
            <strong><?= Support::escape((string) ($summary['sent_count'] ?? 0)) ?></strong>
        </article>
        <article class="card">
            <h2>Błędy</h2>
            <strong><?= Support::escape((string) ($summary['failed_count'] ?? 0)) ?></strong>
        </article>
    </section>

    <section class="stack">
        <article class="card">
            <h2>Zatwierdzone i gotowe</h2>
            <table>
                <thead>
                <tr>
                    <th>Firma</th>
                    <th>Email</th>
                    <th>Lokalizacja</th>
                    <th>Kampania</th>
                    <th>Temat</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($readyLeads === []): ?>
                    <tr><td colspan="5" class="muted">Brak zatwierdzonych leadów gotowych do wysyłki.</td></tr>
                <?php else: ?>
                    <?php foreach ($readyLeads as $lead): ?>
                        <tr>
                            <td>
                                <a href="<?= Support::escape(Support::baseUrl($config, 'lead.php?id=' . $lead['id'])) ?>">
                                    <?= Support::escape($lead['company_name']) ?>
                                </a>
                            </td>
                            <td><?= Support::escape($lead['primary_email']) ?></td>
                            <td><?= Support::escape(trim($lead['city'] . ', ' . $lead['state'], ', ')) ?></td>
                            <td><?= Support::escape($lead['campaign_id']) ?></td>
                            <td><?= Support::escape($lead['email_subject']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2>Zatwierdzone, ale zablokowane</h2>
            <table>
                <thead>
                <tr>
                    <th>Firma</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Powód blokady</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($blockedLeads === []): ?>
                    <tr><td colspan="4" class="muted">Brak zatwierdzonych leadów z brakującą treścią.</td></tr>
                <?php else: ?>
                    <?php foreach ($blockedLeads as $lead): ?>
                        <tr>
                            <td>
                                <a href="<?= Support::escape(Support::baseUrl($config, 'lead.php?id=' . $lead['id'])) ?>">
                                    <?= Support::escape($lead['company_name']) ?>
                                </a>
                            </td>
                            <td><?= Support::escape($lead['primary_email']) ?></td>
                            <td><?= Support::escape(Support::contactLabel($lead['contact_status'])) ?></td>
                            <td><?= Support::escape(queue_block_reason($lead)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2>Ostatnio wysłane</h2>
            <table>
                <thead>
                <tr>
                    <th>Firma</th>
                    <th>Email</th>
                    <th>Kampania</th>
                    <th>Wysłano</th>
                    <th>Próby</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($sentLeads === []): ?>
                    <tr><td colspan="5" class="muted">Brak wysłanych leadów.</td></tr>
                <?php else: ?>
                    <?php foreach ($sentLeads as $lead): ?>
                        <tr>
                            <td>
                                <a href="<?= Support::escape(Support::baseUrl($config, 'lead.php?id=' . $lead['id'])) ?>">
                                    <?= Support::escape($lead['company_name']) ?>
                                </a>
                            </td>
                            <td><?= Support::escape($lead['primary_email']) ?></td>
                            <td><?= Support::escape($lead['campaign_id']) ?></td>
                            <td><?= Support::escape((string) $lead['sent_at']) ?></td>
                            <td><?= Support::escape((string) $lead['send_attempts']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2>Ostatnie błędy</h2>
            <table>
                <thead>
                <tr>
                    <th>Firma</th>
                    <th>Email</th>
                    <th>Próby</th>
                    <th>Ostatnia próba</th>
                    <th>Błąd</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($failedLeads === []): ?>
                    <tr><td colspan="5" class="muted">Brak błędów wysyłki.</td></tr>
                <?php else: ?>
                    <?php foreach ($failedLeads as $lead): ?>
                        <tr>
                            <td>
                                <a href="<?= Support::escape(Support::baseUrl($config, 'lead.php?id=' . $lead['id'])) ?>">
                                    <?= Support::escape($lead['company_name']) ?>
                                </a>
                            </td>
                            <td><?= Support::escape($lead['primary_email']) ?></td>
                            <td><?= Support::escape((string) $lead['send_attempts']) ?></td>
                            <td><?= Support::escape((string) $lead['last_contacted_at']) ?></td>
                            <td><?= Support::escape($lead['last_error']) ?></td>
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
