<?php

namespace App\Commerce\Api;

use App\Commerce\Money;
use App\Models\Quote;
use App\Models\QuoteVersion;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;

/** An explicit public projection of one persisted commercial issue, never a calculator. */
final class QuotePresenter
{
    public function present(Quote $quote, QuoteVersion $version, int $publicId, array $pdf): array
    {
        $snapshot = $version->snapshot;
        if ($publicId < 1 || ! is_array($snapshot) || (int) $version->quote_id !== (int) $quote->id
            || ! isset($snapshot['version']) || ! is_int($snapshot['version']) || $snapshot['version'] !== (int) $version->version
            || ! is_array($snapshot['customer'] ?? null) || ! array_key_exists('name', $snapshot['customer'])
            || ! array_key_exists('email', $snapshot['customer']) || ! is_array($snapshot['items'] ?? null)
            || ! array_is_list($snapshot['items']) || ! is_array($snapshot['totals'] ?? null)) {
            throw new QuoteUnavailable;
        }

        $folio = $this->text($snapshot['folio'] ?? null);
        if ($folio === null) {
            throw new QuoteUnavailable;
        }
        $issuedAt = $this->issuedAt($snapshot['issued_at'] ?? null);
        $customer = $snapshot['customer'];
        $totals = $snapshot['totals'];
        if (($totals['currency'] ?? null) !== 'MXN') {
            throw new QuoteUnavailable;
        }
        foreach (['subtotal', 'discount', 'tax', 'shipping', 'total'] as $key) {
            $this->minor($totals[$key] ?? null);
        }
        if ($totals['discount'] > $totals['subtotal']) {
            throw new QuoteUnavailable;
        }
        // The authorized net projection uses persisted aggregates; IVA and prices are not recalculated.
        $saleAmount = $totals['subtotal'] - $totals['discount'];
        if ($this->sum([$saleAmount, $totals['tax'], $totals['shipping']]) !== $totals['total']) {
            throw new QuoteUnavailable;
        }

        $items = [];
        foreach ($snapshot['items'] as $line) {
            if (! is_array($line) || ! is_array($line['product'] ?? null)
                || ! is_int($line['quantity'] ?? null) || $line['quantity'] < 1) {
                throw new QuoteUnavailable;
            }
            $product = $line['product'];
            $items[] = [
                'id' => $this->identifier($line['id'] ?? null),
                'sku' => $this->text($product['sku'] ?? null),
                'variant_sku' => $this->text($product['variant_sku'] ?? null),
                'name' => $this->text($product['name'] ?? null),
                'description' => $this->text($product['description'] ?? null),
                'color' => $this->text($product['color'] ?? null),
                'quantity' => $line['quantity'],
                'printing' => $this->text($line['printing'] ?? null),
            ];
        }
        $unlisted = array_key_exists('unlisted_items', $snapshot) ? $snapshot['unlisted_items'] : [];
        if (! is_array($unlisted) || ! array_is_list($unlisted) || ($items === [] && $unlisted === [])) {
            throw new QuoteUnavailable;
        }
        $unlisted = array_map(function ($item): array {
            if (! is_array($item)) {
                throw new QuoteUnavailable;
            }

            return ['id' => $this->identifier($item['id'] ?? null), 'name' => $this->text($item['name'] ?? null),
                'description' => $this->text($item['description'] ?? null), 'color' => $this->text($item['color'] ?? null)];
        }, $unlisted);

        $request = $snapshot['source_request'] ?? null;
        if ($request !== null && ! is_array($request)) {
            throw new QuoteUnavailable;
        }
        $environment = config('quote_api.environment', 'local');
        if (! in_array($environment, ['local', 'development', 'production'], true)) {
            throw new RuntimeException('Entorno API no configurado.');
        }
        $createdAt = $quote->created_at;
        if ($createdAt !== null && ! $createdAt instanceof DateTimeInterface) {
            throw new QuoteUnavailable;
        }

        return [
            'id' => $publicId,
            'folio' => $folio,
            'source' => ['system' => 'USB Stock', 'environment' => $environment],
            'dates' => ['created_at' => $createdAt === null ? null : $this->date($createdAt), 'elaborated_at' => $issuedAt],
            'customer' => ['id' => $this->identifier($snapshot['client_id'] ?? null),
                'name' => $this->text($customer['name']), 'company' => $this->text($customer['company'] ?? null),
                'email' => $this->text($customer['email']), 'phone' => $this->text($customer['phone'] ?? null),
                'secondary_phone' => $this->text($customer['secondary_phone'] ?? null)],
            'items' => $items,
            'unlisted_items' => $unlisted,
            'items_html' => $this->itemsHtml($snapshot, $items, $unlisted),
            'sale_amount_available' => true,
            'sale_amount_unavailable_reason' => null,
            'amounts_basis' => 'last_confirmed_quote_send',
            'amounts_recorded_at' => $issuedAt,
            'amounts' => ['currency' => 'MXN', 'sale_amount' => $this->number($saleAmount),
                'shipping' => $this->number($totals['shipping']), 'tax' => $this->number($totals['tax']), 'total' => $this->number($totals['total'])],
            'archived' => false,
            'comments' => $this->text($request['message'] ?? null),
            'pdf' => $this->pdf($pdf),
        ];
    }

    private function itemsHtml(array $snapshot, array $items, array $unlisted): ?string
    {
        $lineTotals = $snapshot['totals']['line_totals'] ?? null;
        // Descriptive unlisted rows have no standard persisted price layout. Do not silently omit them.
        if ($unlisted !== [] || ! is_array($lineTotals) || ! array_is_list($lineTotals) || count($lineTotals) !== count($items)) {
            return null;
        }
        $rows = [];
        $discounts = [];
        foreach ($snapshot['items'] as $index => $line) {
            foreach (['unit_minor', 'printing_minor', 'discount_minor'] as $key) {
                if (! isset($line[$key]) || ! is_int($line[$key]) || $line[$key] < 0) {
                    return null;
                }
            }
            if (! is_int($lineTotals[$index]) || $lineTotals[$index] < 0 || $line['discount_minor'] > $lineTotals[$index]) {
                return null;
            }
            $discounts[] = $line['discount_minor'];
            $rows[] = $items[$index] + [
                'unit_price' => Money::format($line['unit_minor']), 'printing_price' => Money::format($line['printing_minor']),
                'amount' => Money::format($lineTotals[$index]), 'discount' => $line['discount_minor'] === 0 ? null : Money::format($line['discount_minor']),
            ];
        }
        if ($this->sum($lineTotals) !== $snapshot['totals']['subtotal'] || $this->sum($discounts) !== $snapshot['totals']['discount']) {
            return null;
        }

        return trim(view('api.quote-items', ['rows' => $rows])->render());
    }

    private function pdf(array $pdf): array
    {
        if (! is_bool($pdf['available'] ?? null) || ! array_key_exists('url', $pdf)
            || ($pdf['url'] !== null && ! is_string($pdf['url']))
            || ($pdf['available'] && ($pdf['url'] === null || $pdf['url'] === ''))
            || (! $pdf['available'] && $pdf['url'] !== null)) {
            throw new RuntimeException('Referencia documental no válida.');
        }

        return ['available' => $pdf['available'], 'url' => $pdf['url'], 'basis' => 'latest_generated_quote_pdf'];
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || preg_match('//u', $value) !== 1) {
            throw new QuoteUnavailable;
        }
        $value = preg_replace('/^[\s\x{00a0}]+|[\s\x{00a0}]+$/u', '', $value);

        return $value === '' ? null : $value;
    }

    private function identifier(mixed $value): ?int
    {
        if ($value !== null && (! is_int($value) || $value < 1)) {
            throw new QuoteUnavailable;
        }

        return $value;
    }

    private function minor(mixed $value): int
    {
        if (! is_int($value) || $value < 0) {
            throw new QuoteUnavailable;
        }

        return $value;
    }

    private function sum(array $amounts): int
    {
        $sum = 0;
        foreach ($amounts as $amount) {
            $this->minor($amount);
            if ($amount > PHP_INT_MAX - $sum) {
                throw new QuoteUnavailable;
            }
            $sum += $amount;
        }

        return $sum;
    }

    private function number(int $minor): float
    {
        $decimal = Money::decimal($minor);
        $number = (float) $decimal;
        // Float is used only at the JSON boundary; reject values that cannot retain their cents.
        if (! is_finite($number) || sprintf('%.2F', $number) !== $decimal) {
            throw new QuoteUnavailable;
        }

        return $number;
    }

    private function issuedAt(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)\z/', $value)) {
            throw new QuoteUnavailable;
        }
        $format = str_contains($value, '.') ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP';
        $date = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
            throw new QuoteUnavailable;
        }

        return $this->date($date);
    }

    private function date(DateTimeInterface $date): string
    {
        return DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone('America/Mexico_City'))->format(DateTimeInterface::ATOM);
    }
}
