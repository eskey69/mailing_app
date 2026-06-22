<?php

declare(strict_types=1);

use MailingApp\Database;
use MailingApp\LeadMeta;
use MailingApp\LeadRepository;
use MailingApp\PublicationPayloadBuilder;
use MailingApp\PublicationService;
use MailingApp\Support;

$config = require dirname(__DIR__) . '/bootstrap.php';

$database = new Database($config);
$repository = new LeadRepository($database->pdo());
$service = new PublicationService($repository, new PublicationPayloadBuilder($config), $config);

$flashSuccess = '';
$flashError = '';
$batchResults = [];

function publication_block_reason(array $lead): string
{
    $reasons = [];

    if (trim((string) ($lead['email_draft'] ?? '')) === '' && trim((string) ($lead['email_final'] ?? '')) === '') {
        $reasons[] = 'brak treści ogłoszenia';
    }

    $meta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
    $category = is_array($meta['polonads_category'] ?? null) ? $meta['polonads_category'] : [];
    $region = is_array($meta['polonads_region'] ?? null) ? $meta['polonads_region'] : [];

    if (($category['requires_review'] ?? false) === true) {
        $reasons[] = 'kategoria wymaga sprawdzenia';
    }

    if (($region['requires_review'] ?? false) === true) {
        $reasons[] = 'region wymaga sprawdzenia';
    }

    return $reasons === [] ? 'zalecane ręczne sprawdzenie' : implode(', ', $reasons);
}

function publication_meta(array $lead): array
{
    return LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedIds = array_values(array_unique(array_filter(
        array_map('intval', $_POST['lead_ids'] ?? []),
        static fn (int $leadId): bool => $leadId > 0
    )));

    if ($selectedIds === []) {
        $flashError = 'Wybierz co najmniej jeden gotowy lead przed startem publikacji.';
    } else {
        $readyIndex = [];
        foreach ($repository->fetchPublicationQueueReady(500) as $readyLead) {
            $readyIndex[(int) $readyLead['id']] = $readyLead;
        }

        $publishedCount = 0;
        $failedCount = 0;

        foreach ($selectedIds as $leadId) {
            if (!isset($readyIndex[$leadId])) {
                $failedCount++;
                $batchResults[] = [
                    'lead_id' => $leadId,
                    'company_name' => 'Lead #' . $leadId,
                    'status' => 'failed',
                    'message' => 'Lead nie jest już w kolejce gotowej do publikacji.',
                ];
                continue;
            }

            try {
                $result = $service->publishLead($leadId);
                $publishedCount++;
                $batchResults[] = [
                    'lead_id' => $leadId,
                    'company_name' => (string) $readyIndex[$leadId]['company_name'],
                    'status' => 'published',
                    'message' => sprintf(
                        'Opublikowano. Użytkownik Joomla #%d, ogłoszenie DJCF #%d.',
                        (int) ($result['user']['user']['id'] ?? 0),
                        (int) ($result['item']['item']['id'] ?? 0)
                    ),
                ];
            } catch (Throwable $exception) {
                $failedCount++;
                $batchResults[] = [
                    'lead_id' => $leadId,
                    'company_name' => (string) $readyIndex[$leadId]['company_name'],
                    'status' => 'failed',
                    'message' => $exception->getMessage(),
                ];
            }
        }

        if ($publishedCount > 0) {
            $flashSuccess = sprintf(
                'Partia zakończona. Opublikowano: %d. Błędy: %d.',
                $publishedCount,
                $failedCount
            );
        } elseif ($failedCount > 0) {
            $flashError = sprintf('Partia nie powiodła się. Błędne rekordy: %d.', $failedCount);
        }
    }
}

$summary = $repository->fetchPublicationQueueSummary();
$readyLeads = $repository->fetchPublicationQueueReady();
$blockedLeads = $repository->fetchPublicationQueueBlocked();
$publishedLeads = $repository->fetchRecentlyPublished();
$failedLeads = $repository->fetchRecentlyFailedPublication();
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>Kolejka publikacji</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= Support::escape(Support::baseUrl($config, 'assets/app.css')) ?>">
</head>
<body>
<div class="shell">
    <section class="hero">
        <p class="muted">Kontrola rekordów przed publikacją na Polonads.</p>
        <h1>Kolejka publikacji</h1>
        <p>
            Ten ekran pokazuje ogłoszenia zaakceptowane przez klienta i gotowe do publikacji,
            zablokowane rekordy oraz ostatnie sukcesy lub błędy publikacji.
        </p>
        <div class="actions">
            <a class="button secondary" href="<?= Support::escape(Support::baseUrl($config, 'index.php')) ?>">Dashboard</a>
            <a class="button secondary" href="<?= Support::escape(Support::baseUrl($config, 'leads.php')) ?>">Leady</a>
            <a class="button secondary" href="<?= Support::escape(Support::baseUrl($config, 'queue.php')) ?>">Kolejka wysyłki</a>
        </div>
    </section>

    <?php if ($flashSuccess !== ''): ?>
        <div class="flash success"><?= Support::escape($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
        <div class="flash error"><?= Support::escape($flashError) ?></div>
    <?php endif; ?>

    <section class="grid">
        <article class="card">
            <h2>Gotowe do publikacji</h2>
            <strong><?= Support::escape((string) ($summary['ready_to_publish'] ?? 0)) ?></strong>
        </article>
        <article class="card">
            <h2>Zablokowane</h2>
            <strong><?= Support::escape((string) ($summary['blocked_missing_listing'] ?? 0)) ?></strong>
        </article>
        <article class="card">
            <h2>Opublikowane</h2>
            <strong><?= Support::escape((string) ($summary['published_count'] ?? 0)) ?></strong>
        </article>
        <article class="card">
            <h2>Błędy</h2>
            <strong><?= Support::escape((string) ($summary['failed_count'] ?? 0)) ?></strong>
        </article>
    </section>

    <section class="stack">
        <article class="card">
            <h2>Zatwierdzone i gotowe</h2>
            <p class="muted">Wybierz ogłoszenia zaakceptowane przez klienta i opublikuj je w jednej partii.</p>
            <form method="post">
                <div class="actions">
                    <button type="submit" <?= $readyLeads === [] ? 'disabled' : '' ?>>Opublikuj wybrane</button>
                </div>
                <table>
                    <thead>
                    <tr>
                        <th>Wybierz</th>
                        <th>Firma</th>
                        <th>Email</th>
                        <th>Lokalizacja</th>
                        <th>Kategoria</th>
                        <th>Region</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($readyLeads === []): ?>
                        <tr><td colspan="6" class="muted">Brak leadów gotowych do publikacji.</td></tr>
                    <?php else: ?>
                        <?php foreach ($readyLeads as $lead): ?>
                            <?php $meta = publication_meta($lead); ?>
                            <?php $category = is_array($meta['polonads_category'] ?? null) ? $meta['polonads_category'] : []; ?>
                            <?php $region = is_array($meta['polonads_region'] ?? null) ? $meta['polonads_region'] : []; ?>
                            <tr>
                                <td><input type="checkbox" name="lead_ids[]" value="<?= Support::escape((string) $lead['id']) ?>"></td>
                                <td>
                                    <a href="<?= Support::escape(Support::baseUrl($config, 'publication.php?id=' . $lead['id'])) ?>">
                                        <?= Support::escape($lead['company_name']) ?>
                                    </a>
                                </td>
                                <td><?= Support::escape($lead['primary_email']) ?></td>
                                <td><?= Support::escape(trim($lead['city'] . ', ' . $lead['state'], ', ')) ?></td>
                                <td><?= Support::escape((string) ($category['name'] ?? '')) ?></td>
                                <td><?= Support::escape((string) ($region['name'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </form>
        </article>

        <?php if ($batchResults !== []): ?>
            <article class="card">
                <h2>Wynik ostatniej partii</h2>
                <table>
                    <thead>
                    <tr>
                        <th>Lead</th>
                        <th>Status</th>
                        <th>Komunikat</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($batchResults as $result): ?>
                        <tr>
                            <td>
                                <a href="<?= Support::escape(Support::baseUrl($config, 'publication.php?id=' . $result['lead_id'])) ?>">
                                    <?= Support::escape($result['company_name']) ?>
                                </a>
                            </td>
                            <td><?= Support::escape($result['status']) ?></td>
                            <td><?= Support::escape($result['message']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </article>
        <?php endif; ?>

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
                    <tr><td colspan="4" class="muted">Brak zablokowanych leadów do publikacji.</td></tr>
                <?php else: ?>
                    <?php foreach ($blockedLeads as $lead): ?>
                        <tr>
                            <td>
                                <a href="<?= Support::escape(Support::baseUrl($config, 'publication.php?id=' . $lead['id'])) ?>">
                                    <?= Support::escape($lead['company_name']) ?>
                                </a>
                            </td>
                            <td><?= Support::escape($lead['primary_email']) ?></td>
                            <td><?= Support::escape(Support::contactLabel($lead['contact_status'])) ?></td>
                            <td><?= Support::escape(publication_block_reason($lead)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2>Ostatnio opublikowane</h2>
            <table>
                <thead>
                <tr>
                    <th>Firma</th>
                    <th>Email</th>
                    <th>ID ogłoszenia</th>
                    <th>URL ogłoszenia</th>
                    <th>Aktualizacja</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($publishedLeads === []): ?>
                    <tr><td colspan="5" class="muted">Brak opublikowanych leadów.</td></tr>
                <?php else: ?>
                    <?php foreach ($publishedLeads as $lead): ?>
                        <?php $meta = LeadMeta::decode((string) ($lead['personalization_data'] ?? '')); ?>
                        <tr>
                            <td>
                                <a href="<?= Support::escape(Support::baseUrl($config, 'publication.php?id=' . $lead['id'])) ?>">
                                    <?= Support::escape($lead['company_name']) ?>
                                </a>
                            </td>
                            <td><?= Support::escape($lead['primary_email']) ?></td>
                            <td><?= Support::escape((string) ($meta['djcf_item_id'] ?? '')) ?></td>
                            <td><?= Support::escape((string) ($meta['listing_url'] ?? '')) ?></td>
                            <td><?= Support::escape((string) $lead['updated_at']) ?></td>
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
                    <th>Kiedy</th>
                    <th>Błąd</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($failedLeads === []): ?>
                    <tr><td colspan="4" class="muted">Brak błędów publikacji.</td></tr>
                <?php else: ?>
                    <?php foreach ($failedLeads as $lead): ?>
                        <tr>
                            <td>
                                <a href="<?= Support::escape(Support::baseUrl($config, 'publication.php?id=' . $lead['id'])) ?>">
                                    <?= Support::escape($lead['company_name']) ?>
                                </a>
                            </td>
                            <td><?= Support::escape($lead['primary_email']) ?></td>
                            <td><?= Support::escape((string) $lead['created_at']) ?></td>
                            <td><?= Support::escape((string) $lead['message']) ?></td>
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
