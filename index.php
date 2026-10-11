<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/src/DataCleaner.php';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function protectCsvValue(string $value): string
{
    // A spreadsheet may run cells that start with these characters as formulas.
    if (preg_match('/^[\s]*[=+\-@]/u', $value) === 1) {
        return "'" . $value;
    }

    return $value;
}

function renderUploadForm(): void
{
    ?>
    <form class="upload-form" action="./" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="MAX_FILE_SIZE" value="5242880">
        <label class="dropzone" for="data-file">
            <span class="upload-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5M5 15.5v3A1.5 1.5 0 0 0 6.5 20h11a1.5 1.5 0 0 0 1.5-1.5v-3"
                        stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>
            <span class="drop-title">Arrastra el archivo aquí</span>
            <span class="drop-subtitle">o <span>búscalo en tu dispositivo</span></span>
            <input id="data-file" name="data_file" type="file"
                accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
            <span class="file-name" aria-live="polite">CSV o XLSX</span>
        </label>
        <label class="ai-option" for="use-ai">
            <input id="use-ai" name="use_ai" type="checkbox" value="1">
            <span>
                <strong>Usar IA para sugerir categorías</strong>
                <small>Envía a OpenAI los campos que no parecen identificativos. No la uses con datos confidenciales.</small>
            </span>
        </label>
        <label class="ai-option" for="review-with-ai">
            <input id="review-with-ai" name="review_with_ai" type="checkbox" value="1">
            <span>
                <strong>Revisar calidad de los datos</strong>
                <small>Añade observaciones sobre valores vacíos o formatos dudosos, sin modificar esos datos.</small>
            </span>
        </label>
        <button class="primary-button" type="submit">Procesar archivo <span aria-hidden="true">→</span></button>
    </form>
    <p class="privacy-note">El archivo original no se modifica. El resultado se puede descargar como CSV.</p>
    <?php
}

function renderResults(array $result): void
{
    $headers = $result['headers'];
    $rows = $result['rows'];
    $stats = $result['stats'];
    ?>
    <div class="results-heading">
        <div>
            <span class="step">RESULTADO</span>
            <h2>Archivo procesado</h2>
        </div>
        <a class="text-link" href="?new=1">Limpiar otro archivo <span aria-hidden="true">↗</span></a>
    </div>

    <div class="stats">
        <div class="stat">
            <span>Filas revisadas</span>
            <strong><?= (int) $stats['received'] ?></strong>
        </div>
        <div class="stat">
            <span>Duplicados retirados</span>
            <strong><?= (int) $stats['duplicates'] ?></strong>
        </div>
        <div class="stat">
            <span>Filas listas</span>
            <strong class="stat-accent"><?= (int) $stats['exported'] ?></strong>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <?php foreach ($headers as $header): ?>
                        <th><?= escapeHtml((string) $header) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($rows, 0, 8) as $row): ?>
                    <tr>
                        <?php foreach ($headers as $header): ?>
                            <td><?= escapeHtml((string) ($row[$header] ?? '')) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (count($rows) > 8): ?>
            <div class="table-more">Mostrando 8 de <?= count($rows) ?> filas limpias</div>
        <?php endif; ?>
    </div>

    <a class="primary-button download-button" href="?export=csv">
        Descargar CSV <span aria-hidden="true">↓</span>
    </a>
    <p class="privacy-note">
        <?php if (!empty($result['ai_review_used'])): ?>
            Las observaciones de IA son sugerencias; no corrigen los datos automáticamente.
        <?php elseif (!empty($result['ai_used'])): ?>
            Categorías sugeridas por IA. Revísalas antes de usar el archivo.
        <?php else: ?>
            Categorías sugeridas mediante reglas locales. Revísalas antes de usar el archivo.
        <?php endif; ?>
    </p>
    <?php
}

function renderPage(array $result, string $error): void
{
    $rows = $result['rows'] ?? [];
    ?>
    <!doctype html>
    <html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Limpia y organiza hojas de cálculo administrativas sin enviar tus datos a servicios externos.">
        <title>Ordena — limpiador de datos administrativos</title>
        <link rel="stylesheet" href="assets/styles.css">
    </head>
    <body>
        <header class="topbar">
            <a class="brand" href="./" aria-label="Ordena, inicio">
                ordena<span class="brand-period">.</span>
            </a>
            <span class="privacy">CSV / XLSX <span aria-hidden="true">·</span> Máximo 5 MB</span>
        </header>

        <main class="shell">
            <section class="intro">
                <div class="eyebrow">HERRAMIENTA DE DATOS</div>
                <h1>Limpieza<br><span class="title-accent">de datos</span></h1>
                <p class="intro-copy">Normaliza campos, sugiere categorías y elimina filas duplicadas de una hoja de cálculo.</p>
            </section>

            <section class="workspace" aria-labelledby="upload-heading">
                <div class="section-heading">
                    <div>
                        <span class="step">ARCHIVO DE ENTRADA</span>
                        <h2 id="upload-heading">Selecciona un archivo</h2>
                    </div>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="alert" role="alert"><?= escapeHtml($error) ?></div>
                <?php endif; ?>

                <?php if ($rows === []): ?>
                    <?php renderUploadForm(); ?>
                <?php else: ?>
                    <?php renderResults($result); ?>
                <?php endif; ?>
            </section>
        </main>

        <footer>
            <span>Procesamiento en este servidor. Sin servicios externos.</span>
            <span>ordena<span class="brand-period">.</span></span>
        </footer>

        <script>
            const fileInput = document.querySelector('#data-file');

            if (fileInput) {
                const fileName = document.querySelector('.file-name');
                const dropzone = document.querySelector('.dropzone');

                fileInput.addEventListener('change', () => {
                    if (fileInput.files.length > 0) {
                        fileName.textContent = fileInput.files[0].name;
                    } else {
                        fileName.textContent = 'CSV o XLSX';
                    }
                });

                dropzone.addEventListener('dragover', (event) => {
                    event.preventDefault();
                });

                dropzone.addEventListener('drop', (event) => {
                    event.preventDefault();

                    if (event.dataTransfer.files.length > 0) {
                        fileInput.files = event.dataTransfer.files;
                        fileInput.dispatchEvent(new Event('change'));
                    }
                });
            }
        </script>
    </body>
    </html>
    <?php
}

function exportCleanedCsv(): void
{
    if (
        ($_GET['export'] ?? '') !== 'csv'
        || empty($_SESSION['cleaned_result']['rows'])
    ) {
        http_response_code(404);
        exit('No hay ningún archivo limpio para descargar.');
    }

    $result = $_SESSION['cleaned_result'];

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="datos-limpios.csv"');
    header('X-Content-Type-Options: nosniff');

    $output = fopen('php://output', 'wb');
    if ($output === false) {
        http_response_code(500);
        exit('No se pudo preparar el archivo de descarga.');
    }

    // The BOM helps Excel recognize UTF-8, and the semicolon matches common Spanish settings.
    fwrite($output, "\xEF\xBB\xBF");

    $safeHeaders = [];
    foreach ($result['headers'] as $header) {
        $safeHeaders[] = protectCsvValue((string) $header);
    }
    fputcsv($output, $safeHeaders, ';', '"', '');

    foreach ($result['rows'] as $row) {
        $csvRow = [];
        foreach ($result['headers'] as $header) {
            $value = (string) ($row[$header] ?? '');
            $csvRow[] = protectCsvValue($value);
        }
        fputcsv($output, $csvRow, ';', '"', '');
    }

    fclose($output);
    exit;
}

function processUploadedFile(): array
{
    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        return [
            'result' => [],
            'error' => 'La sesión del formulario ha caducado. Recarga la página e inténtalo de nuevo.',
        ];
    }

    if (!isset($_FILES['data_file']) || !is_array($_FILES['data_file'])) {
        return [
            'result' => [],
            'error' => 'Selecciona un archivo CSV o XLSX para continuar.',
        ];
    }

    $uploadedFile = $_FILES['data_file'];
    $uploadError = (int) ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($uploadError !== UPLOAD_ERR_OK) {
        if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
            $error = 'El archivo supera el límite de 5 MB.';
        } else {
            $error = 'No se pudo recibir el archivo. Vuelve a intentarlo.';
        }

        return ['result' => [], 'error' => $error];
    }

    $fileSize = (int) ($uploadedFile['size'] ?? 0);
    if ($fileSize > DataCleaner::MAX_FILE_BYTES) {
        return [
            'result' => [],
            'error' => 'El archivo supera el límite de 5 MB.',
        ];
    }

    $fileName = (string) ($uploadedFile['name'] ?? '');
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    if (!in_array($extension, ['csv', 'xlsx'], true)) {
        return [
            'result' => [],
            'error' => 'Formato no compatible. Sube un archivo .csv o .xlsx.',
        ];
    }

    try {
        $cleaner = new DataCleaner();
        $filePath = (string) ($uploadedFile['tmp_name'] ?? '');
        $useAi = ($_POST['use_ai'] ?? '') === '1';
        $reviewWithAi = ($_POST['review_with_ai'] ?? '') === '1';
        $result = $cleaner->cleanFile($filePath, $extension, $useAi, $reviewWithAi);
    } catch (RuntimeException $exception) {
        return ['result' => [], 'error' => $exception->getMessage()];
    }

    $_SESSION['cleaned_result'] = $result;

    return ['result' => $result, 'error' => ''];
}

if (isset($_GET['export'])) {
    exportCleanedCsv();
}

if (($_GET['new'] ?? '') === '1') {
    unset($_SESSION['cleaned_result']);
    header('Location: ./');
    exit;
}

$result = $_SESSION['cleaned_result'] ?? [];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Do not leave an older result available if the new upload fails.
    unset($_SESSION['cleaned_result']);

    $uploadResponse = processUploadedFile();
    $result = $uploadResponse['result'];
    $error = $uploadResponse['error'];
}

renderPage($result, $error);
