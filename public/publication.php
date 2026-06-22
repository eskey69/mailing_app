<?php

declare(strict_types=1);

use MailingApp\Database;
use MailingApp\LeadRepository;
use MailingApp\PublicationPayloadBuilder;
use MailingApp\PublicationService;
use MailingApp\Support;

/**
 * @param array<string, mixed> $section
 * @return array<string, mixed>
 */
function publication_section(array $section, string $key): array
{
    $value = $section[$key] ?? [];
    return is_array($value) ? $value : [];
}

function publication_value(array $section, string $key): string
{
    $value = $section[$key] ?? '';
    if (is_bool($value)) {
        return $value ? 'tak' : 'nie';
    }

    if (is_scalar($value) || $value === null) {
        return trim((string) $value);
    }

    return '';
}

$config = require dirname(__DIR__) . '/bootstrap.php';

$leadId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($leadId <= 0) {
    http_response_code(400);
    echo 'Brak poprawnego ID leada.';
    exit;
}

$database = new Database($config);
$repository = new LeadRepository($database->pdo());
$service = new PublicationService($repository, new PublicationPayloadBuilder($config), $config);

$lead = $repository->findLeadById($leadId);
if ($lead === null) {
    http_response_code(404);
    echo 'Nie znaleziono leada.';
    exit;
}

$error = '';
$flashSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $result = $service->publishLead($leadId);
        $flashSuccess = sprintf(
            'Lead opublikowany. Użytkownik Joomla #%d, ogłoszenie DJCF #%d.',
            (int) ($result['user']['user']['id'] ?? 0),
            (int) ($result['item']['item']['id'] ?? 0)
        );
        $lead = $repository->findLeadById($leadId) ?? $lead;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$preview = $service->previewLead($leadId);
$logs = $repository->fetchPublicationLogs($leadId);
$joomlaUser = publication_section($preview, 'joomla_user');
$joomlaLookup = publication_section($joomlaUser, 'lookup');
$joomlaCreate = publication_section($joomlaUser, 'create');
$djcfProfile = publication_section($preview, 'djcf_profile');
$profileUpsert = publication_section($djcfProfile, 'upsert');
$djcfItem = publication_section($preview, 'djcf_item');
$itemCreate = publication_section($djcfItem, 'create');
$trace = publication_section($preview, 'trace');
$categoryMapping = publication_section($trace, 'category_mapping');
$regionMapping = publication_section($trace, 'region_mapping');
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>Podgląd publikacji</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= Support::escape(Support::baseUrl($config, 'assets/app.css')) ?>">
</head>
<body>
<div class="shell">
    <section class="hero">
        <p class="muted">Warstwa publikacji Joomla + DJ-Classifieds.</p>
        <h1><?= Support::escape($lead['company_name']) ?></h1>
        <p>Sprawdź przygotowane dane, a potem uruchom publikację konta Joomla, profilu DJCF i ogłoszenia DJCF.</p>
        <div class="actions">
            <a class="button secondary" href="<?= Support::escape(Support::baseUrl($config, 'lead.php?id=' . $leadId)) ?>">Powrót do leada</a>
        </div>
    </section>

    <?php if ($flashSuccess !== ''): ?>
        <div class="flash success"><?= Support::escape($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="flash error"><?= Support::escape($error) ?></div>
    <?php endif; ?>

    <section class="card">
        <h2>Gotowość do publikacji</h2>
        <p><strong>Status:</strong> <?= Support::escape((string) ($preview['status'] ?? '')) ?></p>
        <?php $warnings = is_array($preview['warnings'] ?? null) ? $preview['warnings'] : []; ?>
        <?php if ($warnings !== []): ?>
            <p><strong>Ostrzeżenia:</strong></p>
            <ul>
                <?php foreach ($warnings as $warning): ?>
                    <li><?= Support::escape((string) $warning) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="muted">Brak blokujących ostrzeżeń w przygotowanych danych.</p>
        <?php endif; ?>
        <form method="post">
            <button type="submit" <?= ($preview['status'] ?? '') !== 'ready' ? 'disabled' : '' ?>>Opublikuj na Polonads</button>
        </form>
    </section>

    <section class="grid" style="margin-top: 24px;">
        <article class="card compact-card">
            <h2>Użytkownik Joomla</h2>
            <p><strong>Nazwa:</strong> <?= Support::escape(publication_value($joomlaCreate, 'name')) ?></p>
            <p><strong>Email:</strong> <?= Support::escape(publication_value($joomlaLookup, 'email')) ?></p>
            <p><strong>Login:</strong> <?= Support::escape(publication_value($joomlaLookup, 'username')) ?></p>
            <p><strong>Grupa Registered:</strong> <?= Support::escape(publication_value(publication_section($joomlaUser, 'groups'), 'registered')) ?></p>
        </article>

        <article class="card compact-card">
            <h2>Profil DJCF</h2>
            <p><strong>ID grupy:</strong> <?= Support::escape(publication_value($profileUpsert, 'group_id')) ?></p>
            <p><strong>ID regionu:</strong> <?= Support::escape(publication_value($profileUpsert, 'region_id')) ?></p>
            <p><strong>Adres:</strong> <?= Support::escape(publication_value($profileUpsert, 'address')) ?></p>
            <p><strong>Kod pocztowy:</strong> <?= Support::escape(publication_value($profileUpsert, 'post_code')) ?></p>
        </article>

        <article class="card compact-card">
            <h2>Ogłoszenie DJCF</h2>
            <p><strong>Istniejące ID ogłoszenia:</strong> <?= Support::escape(publication_value($djcfItem, 'existing_item_id')) ?></p>
            <p><strong>ID kategorii:</strong> <?= Support::escape(publication_value($itemCreate, 'cat_id')) ?></p>
            <p><strong>ID typu:</strong> <?= Support::escape(publication_value($itemCreate, 'type_id')) ?></p>
            <p><strong>Tytuł:</strong> <?= Support::escape(publication_value($itemCreate, 'name')) ?></p>
            <p><strong>Opublikowane:</strong> <?= Support::escape(publication_value($itemCreate, 'published')) ?></p>
        </article>

        <article class="card compact-card">
            <h2>Dopasowana kategoria</h2>
            <p><strong>Nazwa:</strong> <?= Support::escape(publication_value($categoryMapping, 'name')) ?></p>
            <p><strong>Id:</strong> <?= Support::escape(publication_value($categoryMapping, 'id')) ?></p>
            <p><strong>Pewność:</strong> <?= Support::escape(publication_value($categoryMapping, 'confidence')) ?></p>
            <p><strong>Źródło:</strong> <?= Support::escape(publication_value($categoryMapping, 'source')) ?></p>
            <p><strong>Słowo kluczowe:</strong> <?= Support::escape(publication_value($categoryMapping, 'matched_keyword')) ?></p>
            <p><strong>Wymaga sprawdzenia:</strong> <?= Support::escape(publication_value($categoryMapping, 'requires_review')) ?></p>
        </article>

        <article class="card compact-card">
            <h2>Dopasowany region</h2>
            <p><strong>Nazwa:</strong> <?= Support::escape(publication_value($regionMapping, 'name')) ?></p>
            <p><strong>Id:</strong> <?= Support::escape(publication_value($regionMapping, 'id')) ?></p>
            <p><strong>Poziom:</strong> <?= Support::escape(publication_value($regionMapping, 'level')) ?></p>
            <p><strong>Pewność:</strong> <?= Support::escape(publication_value($regionMapping, 'confidence')) ?></p>
            <p><strong>Wymaga sprawdzenia:</strong> <?= Support::escape(publication_value($regionMapping, 'requires_review')) ?></p>
        </article>
    </section>

    <section class="card" style="margin-top: 24px;">
        <h2>Dane techniczne</h2>
        <p class="muted">Surowy podgląd techniczny używany do debugowania i weryfikacji.</p>
        <details>
            <summary>Pokaż dane techniczne</summary>
            <pre><?= Support::escape(json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}') ?></pre>
        </details>
    </section>

    <section class="card" style="margin-top: 24px;">
        <h2>Log publikacji</h2>
        <table>
            <thead>
            <tr>
                <th>Kiedy</th>
                <th>Status</th>
                <th>Komunikat</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($logs === []): ?>
                <tr><td colspan="3" class="muted">Brak prób publikacji.</td></tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= Support::escape((string) $log['created_at']) ?></td>
                        <td><?= Support::escape((string) $log['status']) ?></td>
                        <td><?= Support::escape((string) $log['message']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </section>
</div>
</body>
</html>
