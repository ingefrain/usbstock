<?php

namespace App\Commerce;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class SupplierCsv
{
    public function parse(string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > config('commerce.sync.max_bytes') || ! mb_check_encoding($bytes, 'UTF-8') || str_contains($bytes, "\0")) {
            throw ValidationException::withMessages(['file' => 'El archivo debe ser UTF-8 válido, no vacío y de hasta 8 MiB.']);
        }
        $content = str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3) : $bytes;
        $this->syntax($content);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $content);
        rewind($stream);
        try {
            if (fgetcsv($stream, null, ',', '"', '') !== ['SKU', 'PIEZAS', 'DESCRIPCION', 'PRECIO_SIN_IVA']) {
                throw ValidationException::withMessages(['file' => 'Encabezado requerido: SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA.']);
            }
            $rows = [];
            $errors = [];
            $warnings = [];
            $sourceRows = [];
            $duplicateSkus = [];
            $received = $duplicates = $emptyDescriptions = $errorCount = 0;
            $number = 1;
            while (($cells = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $number++;
                if ($cells === [null]) {
                    continue;
                }
                if ($number > config('commerce.sync.max_rows') + 1) {
                    throw ValidationException::withMessages(['file' => 'Se excedió el límite de registros.']);
                }
                $received++;
                try {
                    if (count($cells) !== 4) {
                        throw new InvalidArgumentException('Se requieren cuatro columnas.');
                    }
                    [$sku, $stock, $description, $price] = $cells;
                    $sku = str_replace(' ', '', strtoupper(trim($sku)));
                    if (! preg_match('/\A[A-Z0-9]{1,80}-[A-Z0-9-]{1,40}\z/', $sku) || strlen($sku) > 100) {
                        throw new InvalidArgumentException('SKU inválido.');
                    }
                    $sourceRows[$sku][] = $number;
                    if (! preg_match('/\A[0-9]{1,8}\z/', $stock)) {
                        throw new InvalidArgumentException('Existencia inválida.');
                    }
                    if (mb_strlen($description) > 4000) {
                        throw new InvalidArgumentException('Descripción demasiado extensa.');
                    }
                    [$model, $color] = explode('-', $sku, 2);
                    $row = ['sku' => $sku, 'model' => $model, 'color_code' => $color, 'supplier_description' => trim($description) === '' ? '' : $description, 'stock' => (int) $stock, 'supplier_price_minor' => Money::parse($price)];
                    if ($row['supplier_description'] === '') {
                        $emptyDescriptions++;
                        $warnings[] = ['code' => 'empty_description', 'sku' => $sku, 'rows' => [$number], 'message' => 'La entrega no proporciona descripción. Se conserva texto válido previo o queda pendiente de contenido.'];
                    }
                    if (isset($rows[$sku])) {
                        if ($rows[$sku] !== $row) {
                            $errorCount++;
                            if (count($errors) < 100) {
                                $errors[] = ['row' => $number, 'rows' => $sourceRows[$sku], 'sku' => $sku, 'message' => 'SKU duplicado contradictorio después de normalizar.'];
                            }
                        } else {
                            $duplicates++;
                            $duplicateSkus[$sku] = true;
                        }
                    } else {
                        $rows[$sku] = $row;
                    }
                } catch (InvalidArgumentException $error) {
                    $errorCount++;
                    if (count($errors) < 100) {
                        $errors[] = ['row' => $number, 'message' => $error->getMessage()];
                    }
                }
            }
            if ($rows === [] && $errors === []) {
                $errors[] = ['row' => 2, 'message' => 'No hay registros.'];
                $errorCount++;
            }
            foreach ($duplicateSkus as $sku => $_) {
                $warnings[] = ['code' => 'identical_duplicate', 'sku' => $sku, 'rows' => $sourceRows[$sku], 'message' => 'Duplicado idéntico consolidado en una sola variante.'];
            }
            foreach ($errors as &$error) {
                if (isset($error['sku'])) {
                    $error['rows'] = $sourceRows[$error['sku']];
                }
            }
            unset($error);
            ksort($rows);

            return ['rows' => $rows, 'errors' => $errors, 'sha256' => hash('sha256', $bytes),
                'warnings' => $warnings, 'source_rows' => $sourceRows, 'received_count' => $received,
                'unique_count' => count($sourceRows), 'duplicates_consolidated' => $duplicates,
                'empty_descriptions' => $emptyDescriptions, 'error_count' => $errorCount];
        } finally {
            fclose($stream);
        }
    }

    private function syntax(string $csv): void
    {
        $state = 0;
        for ($i = 0, $length = strlen($csv); $i < $length; $i++) {
            $char = $csv[$i];
            if ($state === 2) {
                if ($char === '"') {
                    $state = 3;
                }

                continue;
            }
            if ($state === 3 && $char === '"') {
                $state = 2;

                continue;
            }
            if ($char === ',' || $char === "\n" || $char === "\r") {
                $state = 0;

                continue;
            }
            if ($state === 3 || ($state === 1 && $char === '"')) {
                throw ValidationException::withMessages(['file' => 'Comillas CSV mal formadas.']);
            }
            $state = $char === '"' ? 2 : 1;
        }
        if ($state === 2) {
            throw ValidationException::withMessages(['file' => 'Campo CSV sin comilla de cierre.']);
        }
    }
}
