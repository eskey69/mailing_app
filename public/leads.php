<?php

declare(strict_types=1);

use MailingApp\Database;
use MailingApp\LeadRepository;
use MailingApp\Support;

$config = require dirname(__DIR__) . '/bootstrap.php';

$filters = [
    'is_mailable' => isset($_GET['is_mailable']) ? (string) $_GET['is_mailable'] : '',
    'approval_status' => isset($_GET['approval_status']) ? trim((string) $_GET['approval_status']) : '',
    'contact_status' => isset($_GET['contact_status']) ? trim((string) $_GET['contact_status']) : '',
    'search' => isset($_GET['search']) ? trim((string) $_GET['search']) : '',
];

$database = new Database($config);
$repository = new LeadRepository($database->pdo());
$leads = $repository->fetchLeads($filters);
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>Leady</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= Support::escape(Support::baseUrl($config, 'assets/app.css')) ?>">
</head>
<body>
<div class="shell">
    <section class="hero">
        <p class="muted">Panel pracy z rekordami do mailingu.</p>
        <h1>Leady</h1>
        <p>Filtruj leady, sprawdzaj etap pracy i otwieraj rekordy do przygotowania maila, draftu oraz publikacji.</p>
        <div class="actions">
            <a class="button secondary" href="<?= Support::escape(Support::baseUrl($config, 'index.php')) ?>">Dashboard</a>
            <a class="button" href="<?= Support::escape(Support::baseUrl($config, 'import.php')) ?>">Import CSV</a>
            <a class="button secondary" href="<?= Support::escape(Support::baseUrl($config, 'campaigns.php')) ?>">Kampanie</a>
            <a class="button secondary" href="<?= Support::escape(Support::baseUrl($config, 'queue.php')) ?>">Kolejka wysyłki</a>
        </div>
    </section>

    <section class="card" style="margin-top: 24px;">
        <h2>Filtry</h2>
        <form class="form-grid" method="get">
            <div class="filter-grid">
                <div>
                    <label for="search">Szukaj</label>
                    <input id="search" name="search" type="text" value="<?= Support::escape($filters['search']) ?>" placeholder="Firma, email, miasto">
                </div>
                <div>
                    <label for="is_mailable">Do mailingu</label>
                    <select id="is_mailable" name="is_mailable">
                        <option value="">Wszystkie</option>
                        <option value="1" <?= $filters['is_mailable'] === '1' ? 'selected' : '' ?>>Tak</option>
                        <option value="0" <?= $filters['is_mailable'] === '0' ? 'selected' : '' ?>>Nie</option>
                    </select>
                </div>
                <div>
                    <label for="approval_status">Zatwierdzenie</label>
                    <select id="approval_status" name="approval_status">
                        <option value="">Wszystkie</option>
                        <?php foreach (['pending', 'approved', 'rejected'] as $status): ?>
                            <option value="<?= Support::escape($status) ?>" <?= $filters['approval_status'] === $status ? 'selected' : '' ?>>
                                <?= Support::escape(Support::approvalLabel($status)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="contact_status">Kontakt</label>
                    <select id="contact_status" name="contact_status">
                        <option value="">Wszystkie</option>
                        <?php foreach (['new', 'draft_ready', 'approved', 'client_review', 'published', 'sent', 'replied', 'skipped', 'failed'] as $status): ?>
                            <option value="<?= Support::escape($status) ?>" <?= $filters['contact_status'] === $status ? 'selected' : '' ?>>
                                <?= Support::escape(Support::contactLabel($status)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="actions">
                <button type="submit">Zastosuj filtry</button>
                <a class="button secondary" href="<?= Support::escape(Support::baseUrl($config, 'leads.php')) ?>">Reset</a>
            </div>
        </form>
    </section>

    <section class="card" style="margin-top: 24px;">
        <h2>Lista leadów</h2>
        <table>
            <thead>
            <tr>
                <th>Firma</th>
                <th>Lokalizacja</th>
                <th>Email</th>
                <th>Liczba emaili</th>
                <th>Do mailingu</th>
                <th>Zatwierdzenie</th>
                <th>Kontakt</th>
                <th>Kampania</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($leads === []): ?>
                <tr><td colspan="8" class="muted">Brak leadów pasujących do filtrów.</td></tr>
            <?php else: ?>
                <?php foreach ($leads as $lead): ?>
                    <tr>
                        <td>
                            <a href="<?= Support::escape(Support::baseUrl($config, 'lead.php?id=' . $lead['id'])) ?>">
                                <?= Support::escape($lead['company_name']) ?>
                            </a>
                        </td>
                        <td><?= Support::escape(trim($lead['city'] . ', ' . $lead['state'], ', ')) ?></td>
                        <td><?= Support::escape($lead['primary_email']) ?></td>
                        <td><?= Support::escape((string) $lead['email_count']) ?></td>
                        <td>
                            <span class="pill <?= (int) $lead['is_mailable'] === 1 ? 'ok' : 'warn' ?>">
                                <?= Support::escape(Support::yesNo((int) $lead['is_mailable'] === 1)) ?>
                            </span>
                        </td>
                        <td><?= Support::escape(Support::approvalLabel($lead['approval_status'])) ?></td>
                        <td><?= Support::escape(Support::contactLabel($lead['contact_status'])) ?></td>
                        <td><?= Support::escape($lead['campaign_id']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </section>
</div>
</body>
</html>
