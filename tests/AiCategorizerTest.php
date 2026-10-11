<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/DataCleaner.php';

$oldApiKey = getenv('OPENAI_API_KEY');
putenv('OPENAI_API_KEY=test-key');

$path = tempnam(sys_get_temp_dir(), 'ordena-ai-');
if ($path === false) {
    throw new RuntimeException('No se pudo crear el archivo temporal de prueba.');
}

try {
    file_put_contents(
        $path,
        "nombre;correo;descripción\nAna Ruiz;ana@example.com;Solicitud sobre una factura pendiente\n"
    );

    $capturedPayload = [];
    $fakeRequest = static function (array $payload, string $apiKey) use (&$capturedPayload): string {
        if ($apiKey !== 'test-key') {
            throw new RuntimeException('La clave no llegó al transporte de prueba.');
        }

        $capturedPayload = $payload;
        return json_encode([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'categories' => [
                            ['row' => 0, 'category' => 'Finanzas'],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ],
            ]],
        ], JSON_THROW_ON_ERROR);
    };

    $cleaner = new DataCleaner(new AiCategorizer($fakeRequest));
    $result = $cleaner->cleanFile($path, 'csv', true);
    $categoryHeader = $result['headers'][3];

    if ($result['ai_used'] !== true || $result['rows'][0][$categoryHeader] !== 'Finanzas') {
        throw new RuntimeException('No se aplicó la categoría devuelta por la IA.');
    }

    $sentData = json_decode($capturedPayload['messages'][1]['content'], true, 512, JSON_THROW_ON_ERROR);
    if (isset($sentData[0]['data']['Nombre']) || isset($sentData[0]['data']['Email'])) {
        throw new RuntimeException('Se enviaron campos identificativos a la IA.');
    }
    if (($sentData[0]['data']['Descripción'] ?? '') !== 'Solicitud sobre una factura pendiente') {
        throw new RuntimeException('No se envió el texto necesario para categorizar.');
    }

    $reviewPayload = [];
    $reviewRequest = static function (array $payload, string $apiKey) use (&$reviewPayload): string {
        $reviewPayload = $payload;

        return json_encode([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'reviews' => [
                            ['row' => 0, 'note' => 'Fecha vacía; comprobar antes de exportar.'],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ],
            ]],
        ], JSON_THROW_ON_ERROR);
    };

    $reviewCleaner = new DataCleaner(new AiCategorizer($reviewRequest));
    $reviewResult = $reviewCleaner->cleanFile($path, 'csv', false, true);
    $reviewHeader = $reviewResult['headers'][4];

    if (
        $reviewResult['ai_review_used'] !== true
        || $reviewResult['rows'][0][$reviewHeader] !== 'Fecha vacía; comprobar antes de exportar.'
    ) {
        throw new RuntimeException('No se añadió la observación de calidad devuelta por la IA.');
    }

    $reviewData = json_decode($reviewPayload['messages'][1]['content'], true, 512, JSON_THROW_ON_ERROR);
    if (isset($reviewData[0]['data']['Nombre']) || isset($reviewData[0]['data']['Email'])) {
        throw new RuntimeException('La revisión de calidad envió campos identificativos.');
    }
    if (str_contains($reviewPayload['messages'][0]['content'], 'Devuelve una sugerencia')) {
        throw new RuntimeException('La revisión de calidad usó las instrucciones de categorización.');
    }

    putenv('OPENAI_API_KEY');
    try {
        (new AiCategorizer())->categorize([['Descripción' => 'Factura']], [0]);
        throw new RuntimeException('La IA debería exigir una clave de API.');
    } catch (RuntimeException $exception) {
        if (!str_contains($exception->getMessage(), 'OPENAI_API_KEY')) {
            throw $exception;
        }
    }

    echo "OK: categorías, revisión de calidad, filtro de datos y aviso por falta de clave\n";
} finally {
    if ($oldApiKey === false) {
        putenv('OPENAI_API_KEY');
    } else {
        putenv('OPENAI_API_KEY=' . $oldApiKey);
    }
    unlink($path);
}
