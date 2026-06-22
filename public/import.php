<?php

declare(strict_types=1);

use MailingApp\CsvLeadImporter;
use MailingApp\Database;
use MailingApp\LeadRepository;
use MailingApp\Support;

$config = require dirname(__DIR__) . '/bootstrap.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['csv_file']) || !is_array($_FILES['csv_file'])) {
        $error = 'Nie wgrano pliku CSV.';
    } elseif ((int) $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Wgrywanie nie powiodło się. Spróbuj ponownie z poprawnym plikiem CSV.';
    } else {
        $uploadDir = $config['app']['upload_dir'];
        $originalName = (string) $_FILES['csv_file']['name'];
        $storedFilename = date('Ymd_His') . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName);
        $storedPath = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $storedFilename;

        if (!move_uploaded_file((string) $_FILES['csv_file']['tmp_name'], $storedPath)) {
            $error = 'Nie udało się zapisać pliku na serwerze.';
        } else {
            try {
                $importer = new CsvLeadImporter();
                $result = $importer->import($storedPath);

                $database = new Database($config);
                $repository = new LeadRepository($database->pdo());
                $batchId = $repository->saveImportBatch($originalName, $storedFilename, $result['rows'], $result['issues']);

                $success = sprintf(
                    'Import #%d zapisany. Zaimportowano %d wierszy, z czego %d oznaczono jako gotowe do mailingu.',
                    $batchId,
                    count($result['rows']),
                    count(array_filter($result['rows'], static fn ($row): bool => $row->isMailable))
                );

                header('Location: ' . Support::baseUrl($config, 'index.php') . '?success=' . urlencode($success));
                exit;
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    }
}
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>Import CSV</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= Support::escape(Support::baseUrl($config, 'assets/app.css')) ?>">
</head>
<body>
<div class="shell">
    <section class="hero">
        <p class="muted">Wgrywanie leadów do panelu mailingowego.</p>
        <h1>Import CSV z leadami</h1>
        <p>
            Wgraj sprawdzony plik CSV. Importer zapisze rekordy w MariaDB
            i oznaczy leady gotowe do kontrolowanego mailingu.
        </p>
        <div class="actions">
            <a class="button secondary" href="<?= Support::escape(Support::baseUrl($config, 'index.php')) ?>">Powrót do dashboardu</a>
        </div>
    </section>

    <?php if ($error !== ''): ?>
        <div class="flash error"><?= Support::escape($error) ?></div>
    <?php endif; ?>

    <section class="card" style="margin-top: 24px;">
        <h2>Oczekiwane kolumny CSV</h2>
        <p class="muted">
            company_name, category, city, state, phone, address, website, yp_url, primary_email,
            all_emails, email_count, email_source, status, ready_for_import, source, imported
        </p>

        <form class="form-grid" method="post" enctype="multipart/form-data">
            <label for="csv_file">Plik CSV</label>
            <input id="csv_file" name="csv_file" type="file" accept=".csv,text/csv" required>
            <button type="submit">Import CSV</button>
        </form>
    </section>
</div>
</body>
</html>
