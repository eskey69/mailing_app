<?php

declare(strict_types=1);

use MailingApp\CampaignRepository;
use MailingApp\Database;
use MailingApp\PolonadsPublicationGateway;
use MailingApp\Support;

$config = require dirname(__DIR__) . '/bootstrap.php';

$database = new Database($config);
$campaignRepository = new CampaignRepository($database->pdo());

$polonadsDatabase = new Database($config, 'polonads_db');
$gateway = new PolonadsPublicationGateway(
    $polonadsDatabase->pdo(),
    (string) ($config['polonads_db']['prefix'] ?? 'jost3_')
);

$categories = $gateway->fetchCategories();
$campaigns = [];
$flashSuccess = (string) ($_GET['success'] ?? '');
$error = '';

try {
    $campaigns = $campaignRepository->fetchAllCampaigns();
} catch (\Throwable $exception) {
    $error = 'Tabela campaigns nie jest jeszcze gotowa. Uruchom SQL migracyjny i odswiez strone.';
}

$categoryIndex = [];
foreach ($categories as $category) {
    $categoryIndex[(int) $category['id']] = $category;
}

function category_option_label(array $category, array $index): string
{
    $parts = [];
    $current = $category;

    while (true) {
        $parts[] = (string) ($current['name'] ?? '');
        $parentId = (int) ($current['parent_id'] ?? 0);
        if ($parentId <= 0 || !isset($index[$parentId])) {
            break;
        }

        $current = $index[$parentId];
    }

    $parts = array_reverse(array_filter($parts, static fn (string $part): bool => trim($part) !== ''));

    return implode(' > ', $parts);
}

function slugify_campaign_id(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';

    return trim($value, '_');
}

$form = [
    'id' => '',
    'name' => '',
    'polonads_category_id' => 0,
    'is_active' => 1,
    'notes' => '',
];

$editId = trim((string) ($_GET['edit'] ?? ''));
if ($editId !== '') {
    foreach ($campaigns as $campaignRow) {
        if ((string) ($campaignRow['id'] ?? '') !== $editId) {
            continue;
        }

        $form = [
            'id' => (string) ($campaignRow['id'] ?? ''),
            'name' => (string) ($campaignRow['name'] ?? ''),
            'polonads_category_id' => (int) ($campaignRow['polonads_category_id'] ?? 0),
            'is_active' => (int) ($campaignRow['is_active'] ?? 0),
            'notes' => (string) ($campaignRow['notes'] ?? ''),
        ];
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = [
        'id' => trim((string) ($_POST['id'] ?? '')),
        'name' => trim((string) ($_POST['name'] ?? '')),
        'mail_template_id' => '',
        'polonads_category_id' => (int) ($_POST['polonads_category_id'] ?? 0),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'notes' => trim((string) ($_POST['notes'] ?? '')),
    ];

    if ($payload['id'] === '' && $payload['name'] !== '') {
        $payload['id'] = slugify_campaign_id($payload['name']);
    }

    $form = [
        'id' => $payload['id'],
        'name' => $payload['name'],
        'polonads_category_id' => $payload['polonads_category_id'],
        'is_active' => $payload['is_active'],
        'notes' => $payload['notes'],
    ];

    if ($payload['name'] === '') {
        $error = 'Podaj nazwe kampanii.';
    } elseif ($payload['id'] === '') {
        $error = 'Nie udalo sie wygenerowac ID kampanii. Wpisz je recznie.';
    } elseif ($payload['polonads_category_id'] <= 0) {
        $error = 'Wybierz kategorie DJ-Classifieds dla calej kampanii.';
    } else {
        try {
            $campaignRepository->saveCampaign($payload);
            header('Location: ' . Support::baseUrl($config, 'campaigns.php?success=' . urlencode('Kampania zapisana')));
            exit;
        } catch (\Throwable $exception) {
            $error = 'Nie udalo sie zapisac kampanii. Sprawdz, czy tabela campaigns istnieje w bazie mailing_app.';
        }
    }
}
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>Kampanie</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= Support::escape(Support::baseUrl($config, 'assets/app.css')) ?>">
</head>
<body>
<div class="shell">
    <section class="hero">
        <p class="muted">Jedna kampania = jedna kategoria publikacji.</p>
        <h1>Kampanie</h1>
        <p>Na poczatku ustawiasz kategorie dla calej kampanii. Potem wszystkie zaakceptowane ogloszenia z tej kampanii trafiaja do tej samej kategorii na Polonads.</p>
        <div class="actions">
            <a class="button secondary" href="<?= Support::escape(Support::baseUrl($config, 'index.php')) ?>">Dashboard</a>
            <a class="button secondary" href="<?= Support::escape(Support::baseUrl($config, 'leads.php')) ?>">Leady</a>
        </div>
    </section>

    <?php if ($flashSuccess !== ''): ?>
        <div class="flash success"><?= Support::escape($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="flash error"><?= Support::escape($error) ?></div>
    <?php endif; ?>

    <section class="card" style="margin-top: 24px;">
        <h2><?= $editId !== '' ? 'Edycja kampanii' : 'Nowa kampania' ?></h2>
        <form class="form-grid" method="post">
            <div class="filter-grid">
                <div>
                    <label for="name">Nazwa kampanii</label>
                    <input id="name" name="name" type="text" value="<?= Support::escape((string) $form['name']) ?>" placeholder="np. Oferty pracy Chicago - maj 2026">
                </div>
                <div>
                    <label for="id">ID kampanii</label>
                    <input id="id" name="id" type="text" value="<?= Support::escape((string) $form['id']) ?>" placeholder="opcjonalne, wygeneruje sie z nazwy">
                </div>
                <div>
                    <label for="polonads_category_id">Kategoria DJ-Classifieds</label>
                    <select id="polonads_category_id" name="polonads_category_id">
                        <option value="0">Wybierz kategorie</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= Support::escape((string) $category['id']) ?>" <?= (int) $form['polonads_category_id'] === (int) $category['id'] ? 'selected' : '' ?>>
                                <?= Support::escape((string) $category['id']) ?> | <?= Support::escape(category_option_label($category, $categoryIndex)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label><input type="checkbox" name="is_active" value="1" <?= (int) $form['is_active'] === 1 ? 'checked' : '' ?>> Kampania aktywna</label>
                </div>
            </div>

            <div>
                <label for="notes">Notatki</label>
                <textarea id="notes" name="notes" rows="4" placeholder="Opcjonalne uwagi dla operatora"><?= Support::escape((string) $form['notes']) ?></textarea>
            </div>

            <div class="actions">
                <button type="submit">Zapisz kampanie</button>
                <?php if ($editId !== ''): ?>
                    <a class="button secondary" href="<?= Support::escape(Support::baseUrl($config, 'campaigns.php')) ?>">Nowa kampania</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="card" style="margin-top: 24px;">
        <h2>Zapisane kampanie</h2>
        <table>
            <thead>
            <tr>
                <th>ID</th>
                <th>Nazwa</th>
                <th>Kategoria</th>
                <th>Aktywna</th>
                <th>Notatki</th>
                <th>Akcja</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($campaigns === []): ?>
                <tr><td colspan="6" class="muted">Brak zapisanych kampanii.</td></tr>
            <?php else: ?>
                <?php foreach ($campaigns as $campaign): ?>
                    <?php $category = $categoryIndex[(int) ($campaign['polonads_category_id'] ?? 0)] ?? null; ?>
                    <tr>
                        <td><?= Support::escape((string) $campaign['id']) ?></td>
                        <td><?= Support::escape((string) $campaign['name']) ?></td>
                        <td>
                            <?php if (is_array($category)): ?>
                                <?= Support::escape((string) $campaign['polonads_category_id']) ?> | <?= Support::escape(category_option_label($category, $categoryIndex)) ?>
                            <?php else: ?>
                                <?= Support::escape((string) ($campaign['polonads_category_id'] ?? '')) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= Support::escape(Support::yesNo((int) ($campaign['is_active'] ?? 0) === 1)) ?></td>
                        <td><?= Support::escape((string) $campaign['notes']) ?></td>
                        <td><a href="<?= Support::escape(Support::baseUrl($config, 'campaigns.php?edit=' . rawurlencode((string) $campaign['id']))) ?>">Edytuj</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </section>
</div>
</body>
</html>
