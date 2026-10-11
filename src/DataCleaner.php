<?php
declare(strict_types=1);

require_once __DIR__ . '/AiCategorizer.php';

final class DataCleaner
{
    public const MAX_FILE_BYTES = 5 * 1024 * 1024;
    private const MAX_ROWS = 10000;

    private const HEADER_ALIASES = [
        'nombre' => 'Nombre',
        'nombres' => 'Nombre',
        'name' => 'Nombre',
        'first name' => 'Nombre',
        'apellido' => 'Apellidos',
        'apellidos' => 'Apellidos',
        'surname' => 'Apellidos',
        'last name' => 'Apellidos',
        'email' => 'Email',
        'correo' => 'Email',
        'correo electronico' => 'Email',
        'e mail' => 'Email',
        'telefono' => 'Teléfono',
        'telefonos' => 'Teléfono',
        'phone' => 'Teléfono',
        'movil' => 'Teléfono',
        'dni' => 'DNI / NIF',
        'nif' => 'DNI / NIF',
        'nie' => 'DNI / NIF',
        'documento' => 'DNI / NIF',
        'id' => 'ID',
        'identificador' => 'ID',
        'referencia' => 'Referencia',
        'expediente' => 'Expediente',
        'codigo' => 'Código',
        'categoria' => 'Categoría',
        'category' => 'Categoría',
        'tipo' => 'Tipo',
        'type' => 'Tipo',
        'descripcion' => 'Descripción',
        'description' => 'Descripción',
        'asunto' => 'Asunto',
        'subject' => 'Asunto',
        'solicitante' => 'Solicitante',
        'responsable' => 'Responsable',
        'cliente' => 'Cliente',
        'empresa' => 'Empresa',
        'fecha' => 'Fecha',
        'date' => 'Fecha',
    ];

    private AiCategorizer $aiCategorizer;

    public function __construct(?AiCategorizer $aiCategorizer = null)
    {
        $this->aiCategorizer = $aiCategorizer ?? new AiCategorizer();
    }

    public function cleanFile(
        string $path,
        string $extension,
        bool $useAi = false,
        bool $reviewWithAi = false
    ): array
    {
        $fileSize = filesize($path);
        if ($fileSize === false || $fileSize === 0 || $fileSize > self::MAX_FILE_BYTES) {
            throw new RuntimeException('El archivo está vacío o supera el límite de 5 MB.');
        }

        $table = match ($extension) {
            'csv' => $this->readCsv($path),
            'xlsx' => $this->readXlsx($path),
            default => throw new RuntimeException('Formato no compatible. Sube un archivo .csv o .xlsx.'),
        };

        if (count($table) < 2) {
            throw new RuntimeException('El archivo necesita una fila de encabezados y al menos una fila de datos.');
        }

        $headers = $this->normalizeHeaders(array_shift($table));
        if ($headers === []) {
            throw new RuntimeException('No se encontraron encabezados válidos en la primera fila.');
        }

        if (count($table) > self::MAX_ROWS) {
            throw new RuntimeException('El archivo supera el máximo de 10.000 filas de datos.');
        }

        if (!in_array('Categoría', $headers, true)) {
            $headers[] = 'Categoría';
        }

        $uniqueRows = [];
        $seenIdentifiers = [];
        $rowsNeedingCategory = [];
        $duplicates = 0;
        $received = 0;

        foreach ($table as $cells) {
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === 'Categoría' && !array_key_exists($index, $cells)) {
                    $row[$header] = '';
                    continue;
                }
                $row[$header] = $this->normalizeValue($header, (string) ($cells[$index] ?? ''));
            }
            $row = $this->normalizeKnownFields($row);

            if (count(array_filter($row, static fn (string $value): bool => $value !== '')) === 0) {
                continue;
            }
            $received++;

            if ($this->isDuplicate($row, $seenIdentifiers)) {
                $duplicates++;
                continue;
            }

            if (($row['Categoría'] ?? '') === '') {
                $row['Categoría'] = $this->categorize($row);
                $rowsNeedingCategory[] = count($uniqueRows);
            }
            $uniqueRows[] = $row;
        }

        if ($uniqueRows === []) {
            throw new RuntimeException('No hay filas con datos para limpiar.');
        }

        $aiUsed = false;
        if ($useAi && $rowsNeedingCategory !== []) {
            $suggestions = $this->aiCategorizer->categorize($uniqueRows, $rowsNeedingCategory);
            foreach ($suggestions as $rowIndex => $category) {
                $uniqueRows[$rowIndex]['Categoría'] = $category;
            }
            $aiUsed = true;
        }

        $aiReviewUsed = false;
        if ($reviewWithAi) {
            $reviewHeader = 'Revisión IA';
            $suffix = 2;
            while (in_array($reviewHeader, $headers, true)) {
                $reviewHeader = 'Revisión IA ' . $suffix++;
            }
            $headers[] = $reviewHeader;

            $allRowIndexes = array_keys($uniqueRows);
            $reviews = $this->aiCategorizer->reviewQuality($uniqueRows, $allRowIndexes);

            foreach ($reviews as $rowIndex => $note) {
                $uniqueRows[$rowIndex][$reviewHeader] = $note;
            }
            $aiReviewUsed = true;
        }

        return [
            'headers' => $headers,
            'rows' => $uniqueRows,
            'ai_used' => $aiUsed,
            'ai_review_used' => $aiReviewUsed,
            'stats' => [
                'received' => $received,
                'duplicates' => $duplicates,
                'exported' => count($uniqueRows),
            ],
        ];
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('No se pudo leer el archivo CSV.');
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            throw new RuntimeException('El archivo CSV está vacío.');
        }
        $delimiter = $this->detectDelimiter($firstLine);
        rewind($handle);

        $table = [];
        while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($table === [] && isset($cells[0])) {
                $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]) ?? (string) $cells[0];
            }
            $table[] = array_map(fn ($cell): string => $this->ensureUtf8((string) $cell), $cells);
            if (count($table) > self::MAX_ROWS + 1) {
                fclose($handle);
                throw new RuntimeException('El archivo supera el máximo de 10.000 filas de datos.');
            }
        }
        fclose($handle);
        return $table;
    }

    private function detectDelimiter(string $line): string
    {
        $counts = [];
        foreach ([',', ';', "\t", '|'] as $delimiter) {
            $counts[$delimiter] = count(str_getcsv($line, $delimiter, '"', ''));
        }
        arsort($counts);
        return (string) array_key_first($counts);
    }

    private function readXlsx(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false || substr($contents, 0, 2) !== "PK") {
            throw new RuntimeException('El archivo XLSX no parece ser un libro de Excel válido.');
        }

        $entries = $this->readZipEntries($contents);
        if (!isset($entries['xl/workbook.xml'])) {
            throw new RuntimeException('El archivo no contiene un libro XLSX válido.');
        }

        $sheetPath = 'xl/worksheets/sheet1.xml';
        $workbook = $this->parseXml($entries['xl/workbook.xml']);
        if ($workbook === false) {
            throw new RuntimeException('No se pudo leer la estructura del libro XLSX.');
        }
        if (isset($entries['xl/_rels/workbook.xml.rels'])) {
            $relationshipId = '';
            $workbook->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $sheets = $workbook->xpath('//main:sheets/main:sheet');
            if ($sheets !== false && isset($sheets[0])) {
                $attributes = $sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $relationshipId = (string) ($attributes['id'] ?? '');
            }
            if ($relationshipId !== '') {
                $relationships = $this->parseXml($entries['xl/_rels/workbook.xml.rels']);
                if ($relationships === false) {
                    throw new RuntimeException('No se pudieron leer las hojas del libro XLSX.');
                }
                foreach ($relationships->Relationship as $relationship) {
                    if ((string) $relationship['Id'] === $relationshipId) {
                        $target = (string) $relationship['Target'];
                        $sheetPath = $this->resolveSheetPath($target);
                        break;
                    }
                }
            }
        }

        if (!isset($entries[$sheetPath])) {
            throw new RuntimeException('No se encontró la primera hoja de cálculo en el archivo XLSX.');
        }

        $sharedStrings = [];
        if (isset($entries['xl/sharedStrings.xml'])) {
            $stringsXml = $this->parseXml($entries['xl/sharedStrings.xml']);
            if ($stringsXml === false) {
                throw new RuntimeException('No se pudieron leer los textos del XLSX.');
            }
            foreach ($stringsXml->si as $string) {
                $sharedStrings[] = $this->xmlText($string);
            }
        }

        $sheet = $this->parseXml($entries[$sheetPath]);
        if ($sheet === false) {
            throw new RuntimeException('No se pudo interpretar la primera hoja del XLSX.');
        }
        $sheet->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xmlRows = $sheet->xpath('//main:sheetData/main:row');
        if ($xmlRows === false) {
            throw new RuntimeException('No se encontraron filas en la primera hoja del XLSX.');
        }

        $table = [];
        foreach ($xmlRows as $xmlRow) {
            $cells = [];
            foreach ($xmlRow->c as $cell) {
                $position = $this->columnIndex((string) $cell['r']);
                $type = (string) $cell['t'];
                if ($type === 'inlineStr') {
                    $value = $this->xmlText($cell->is);
                } else {
                    $value = (string) $cell->v;
                    if ($type === 's') {
                        $value = $sharedStrings[(int) $value] ?? '';
                    }
                }
                $cells[$position] = $value;
            }
            if ($cells !== []) {
                ksort($cells);
                $table[] = array_replace(array_fill(0, max(array_keys($cells)) + 1, ''), $cells);
            }
            if (count($table) > self::MAX_ROWS + 1) {
                throw new RuntimeException('El archivo supera el máximo de 10.000 filas de datos.');
            }
        }
        return $table;
    }

    private function readZipEntries(string $contents): array
    {
        $endPosition = strrpos($contents, "PK\x05\x06");
        if ($endPosition === false || strlen($contents) < $endPosition + 22) {
            throw new RuntimeException('El archivo XLSX está dañado o incompleto.');
        }
        $end = unpack('vDisk/vDirectoryDisk/vDiskEntries/vEntries/VDirectorySize/VDirectoryOffset/vCommentLength', substr($contents, $endPosition + 4, 18));
        if ($end === false || $end['Entries'] > 2000 || $end['DirectoryOffset'] + $end['DirectorySize'] > strlen($contents)) {
            throw new RuntimeException('El archivo XLSX contiene una estructura ZIP no válida.');
        }

        $entries = [];
        $totalUncompressed = 0;
        $offset = (int) $end['DirectoryOffset'];
        for ($index = 0; $index < $end['Entries']; $index++) {
            if (substr($contents, $offset, 4) !== "PK\x01\x02") {
                throw new RuntimeException('El archivo XLSX contiene una estructura ZIP no válida.');
            }
            $header = unpack('vMade/vNeeded/vFlags/vMethod/vTime/vDate/VChecksum/VCompressed/VUncompressed/vNameLength/vExtraLength/vCommentLength/vDisk/vInternal/VExternal/VLocalOffset', substr($contents, $offset + 4, 42));
            if ($header === false || $header['Compressed'] > self::MAX_FILE_BYTES || $header['Uncompressed'] > self::MAX_FILE_BYTES) {
                throw new RuntimeException('El contenido descomprimido del XLSX es demasiado grande.');
            }
            $totalUncompressed += $header['Uncompressed'];
            if ($totalUncompressed > self::MAX_FILE_BYTES * 4) {
                throw new RuntimeException('El contenido descomprimido del XLSX es demasiado grande.');
            }
            $nameStart = $offset + 46;
            $name = substr($contents, $nameStart, $header['NameLength']);
            $localOffset = (int) $header['LocalOffset'];
            if (substr($contents, $localOffset, 4) !== "PK\x03\x04") {
                throw new RuntimeException('El archivo XLSX contiene una entrada ZIP no válida.');
            }
            $local = unpack('vVersion/vFlags/vMethod/vTime/vDate/VChecksum/VCompressed/VUncompressed/vNameLength/vExtraLength', substr($contents, $localOffset + 4, 26));
            if ($local === false) {
                throw new RuntimeException('El archivo XLSX contiene una entrada ZIP incompleta.');
            }
            $dataStart = $localOffset + 30 + $local['NameLength'] + $local['ExtraLength'];
            if ($dataStart + $header['Compressed'] > strlen($contents)) {
                throw new RuntimeException('El archivo XLSX está incompleto o dañado.');
            }
            $compressed = substr($contents, $dataStart, $header['Compressed']);
            $value = match ($header['Method']) {
                0 => $compressed,
                8 => @gzinflate($compressed),
                default => false,
            };
            if ($value === false) {
                throw new RuntimeException('No se pudo descomprimir una parte del XLSX.');
            }
            if (strlen($value) !== $header['Uncompressed']) {
                throw new RuntimeException('El archivo XLSX está incompleto o dañado.');
            }
            $entries[$name] = $value;
            $offset = $nameStart + $header['NameLength'] + $header['ExtraLength'] + $header['CommentLength'];
        }
        return $entries;
    }

    private function resolveSheetPath(string $target): string
    {
        if (str_starts_with($target, '/')) {
            return ltrim($target, '/');
        }
        $parts = [];
        foreach (explode('/', 'xl/' . $target) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }
        return implode('/', $parts);
    }

    private function xmlText(SimpleXMLElement $element): string
    {
        $text = '';

        $children = $element->children();
        if (!$children instanceof SimpleXMLElement) {
            throw new RuntimeException('No se pudieron leer los textos del archivo XLSX.');
        }

        foreach ($children as $child) {
            if ($child->count() === 0) {
                $text .= (string) $child;
            } else {
                $text .= $this->xmlText($child);
            }
        }
        return $text !== '' ? $text : (string) $element;
    }

    private function parseXml(string $xml): SimpleXMLElement|false
    {
        $previous = libxml_use_internal_errors(true);
        try {
            return simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function columnIndex(string $cellReference): int
    {
        preg_match('/^[A-Z]+/', strtoupper($cellReference), $match);
        $index = 0;
        foreach (str_split($match[0] ?? 'A') as $letter) {
            $index = $index * 26 + ord($letter) - 64;
        }
        return $index - 1;
    }

    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $index => $header) {
            $name = trim(preg_replace('/\s+/u', ' ', (string) $header) ?? '');
            if ($name === '') {
                $name = 'Campo ' . ($index + 1);
            }
            $key = $this->plainText($this->unicodeLower($name));
            $canonical = self::HEADER_ALIASES[$key] ?? $name;
            $candidate = $canonical;
            $suffix = 2;
            while (in_array($candidate, $normalized, true)) {
                $candidate = $canonical . ' ' . $suffix++;
            }
            $normalized[] = $candidate;
        }
        return $normalized;
    }

    private function normalizeKnownFields(array $row): array
    {
        foreach ($row as $header => $value) {
            if (in_array($header, ['Nombre', 'Apellidos', 'Solicitante', 'Responsable', 'Cliente', 'Empresa'], true)) {
                $row[$header] = $this->titleCase($value);
            } elseif ($header === 'Email' && $value !== '') {
                $row[$header] = strtolower($value);
            } elseif ($header === 'Teléfono' && $value !== '') {
                $row[$header] = preg_replace('/[^\d+]/u', '', $value) ?? $value;
            } elseif (in_array($header, ['DNI / NIF', 'ID', 'Referencia', 'Expediente', 'Código'], true) && $value !== '') {
                $row[$header] = strtoupper(preg_replace('/[\s-]+/u', '', $value) ?? $value);
            }
        }
        return $row;
    }

    private function normalizeValue(string $header, string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if ($header === 'Categoría' && $value !== '') {
            return $this->titleCase($value);
        }
        return $value;
    }

    private function ensureUtf8(string $value): string
    {
        if (preg_match('//u', $value) === 1) {
            return $value;
        }
        $converted = iconv('Windows-1252', 'UTF-8', $value);
        if ($converted === false) {
            throw new RuntimeException('No se pudo interpretar la codificación del archivo. Guarda el CSV como UTF-8 e inténtalo de nuevo.');
        }
        return $converted;
    }

    private function titleCase(string $value): string
    {
        $value = $this->unicodeLower($value);
        return preg_replace_callback('/(^|[\s\-\'’])([^\s\-\'’])/u', function (array $match): string {
            return $match[1] . $this->unicodeUpper($match[2]);
        }, $value) ?? $value;
    }

    private function unicodeLower(string $value): string
    {
        return strtr(strtolower($value), ['Á' => 'á', 'É' => 'é', 'Í' => 'í', 'Ó' => 'ó', 'Ú' => 'ú', 'Ü' => 'ü', 'Ñ' => 'ñ', 'Ç' => 'ç']);
    }

    private function unicodeUpper(string $value): string
    {
        return strtr(strtoupper($value), ['á' => 'Á', 'é' => 'É', 'í' => 'Í', 'ó' => 'Ó', 'ú' => 'Ú', 'ü' => 'Ü', 'ñ' => 'Ñ', 'ç' => 'Ç']);
    }

    private function plainText(string $value): string
    {
        $value = $this->unicodeLower($value);
        return strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c']);
    }

    private function isDuplicate(array $row, array &$seenIdentifiers): bool
    {
        $keys = [];
        foreach (['Email', 'DNI / NIF', 'ID', 'Referencia', 'Expediente', 'Código'] as $identifier) {
            $value = $row[$identifier] ?? '';
            if ($value === '') {
                continue;
            }
            $key = $identifier . ':' . $this->unicodeLower($value);
            if (isset($seenIdentifiers[$key])) {
                $duplicate = true;
            }
            $keys[] = $key;
        }

        if (isset($duplicate)) {
            return true;
        }
        if ($keys !== []) {
            foreach ($keys as $key) {
                $seenIdentifiers[$key] = true;
            }
            return false;
        }

        $fingerprint = hash('sha256', json_encode(array_map([$this, 'unicodeLower'], $row), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if (isset($seenIdentifiers['row:' . $fingerprint])) {
            return true;
        }
        $seenIdentifiers['row:' . $fingerprint] = true;
        return false;
    }

    private function categorize(array $row): string
    {
        $content = $this->plainText(implode(' ', array_values($row)));
        $rules = [
            'Finanzas' => ['factura', 'pago', 'presupuesto', 'contabilidad', 'impuesto', 'cobro', 'abono'],
            'Recursos humanos' => ['nomina', 'contratacion', 'empleado', 'personal', 'formacion', 'vacaciones'],
            'Compras y contratos' => ['proveedor', 'compra', 'pedido', 'contrato', 'licitacion', 'suministro'],
            'Legal y cumplimiento' => ['legal', 'proteccion de datos', 'privacidad', 'rgpd', 'reclamacion'],
            'Atención y servicios' => ['incidencia', 'solicitud', 'consulta', 'cita', 'servicio', 'soporte'],
        ];
        foreach ($rules as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($content, $keyword)) {
                    return $category;
                }
            }
        }
        return 'General';
    }
}
