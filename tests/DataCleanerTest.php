<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/DataCleaner.php';

$path = tempnam(sys_get_temp_dir(), 'ordena-');
if ($path === false) {
    throw new RuntimeException('No se pudo crear el archivo temporal de prueba.');
}

try {
    file_put_contents($path, " nombre ;CORREO;Descripción\n  mARIA   pérez ; MARIA@example.COM ;Factura pendiente\nAna Ruiz;ana@example.com;Solicitud de cita\nmaria pérez;maria@example.com;Otro texto\n");
    $result = (new DataCleaner())->cleanFile($path, 'csv');
    if ($result['headers'] !== ['Nombre', 'Email', 'Descripción', 'Categoría']) {
        throw new RuntimeException('No se normalizaron los encabezados esperados.');
    }
    if ($result['stats'] !== ['received' => 3, 'duplicates' => 1, 'exported' => 2]) {
        throw new RuntimeException('El resumen de filas o duplicados no es correcto.');
    }
    if ($result['rows'][0]['Nombre'] !== 'Maria Pérez' || $result['rows'][0]['Email'] !== 'maria@example.com') {
        throw new RuntimeException('No se normalizaron el nombre o el correo.');
    }
    if ($result['rows'][0]['Categoría'] !== 'Finanzas' || $result['rows'][1]['Categoría'] !== 'Atención y servicios') {
        throw new RuntimeException('La clasificación automática no es correcta.');
    }

    file_put_contents($path, "nombre;correo;id\nAna;ana@example.com;1\nAna;otra@example.com;1\nAna;ana@example.com;2\n");
    $identifierResult = (new DataCleaner())->cleanFile($path, 'csv');
    if ($identifierResult['stats'] !== ['received' => 3, 'duplicates' => 2, 'exported' => 1]) {
        throw new RuntimeException('La deduplicación no comparó todos los identificadores.');
    }

    file_put_contents($path, "nombre;correo\nJos\xE9;JOSE@example.com\n");
    $encodingResult = (new DataCleaner())->cleanFile($path, 'csv');
    if ($encodingResult['rows'][0]['Nombre'] !== 'José') {
        throw new RuntimeException('No se convirtió el CSV Windows-1252 a UTF-8.');
    }

    echo "OK: normalización, codificación, categorización y deduplicación CSV\n";
} finally {
    unlink($path);
}
