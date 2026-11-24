<?php

namespace App\Commerce\Api;

use App\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** One environment-configured consumer; no administrative session is involved. */
final class QuoteApiConsumer
{
    public function active(): bool
    {
        $token = config('quote_api.bearer_token');

        return config('quote_api.enabled') === true
            && is_string($token) && strlen($token) >= 32 && strlen($token) <= 512
            && preg_match('/^[A-Za-z0-9._~+\/=\-]+$/D', $token) === 1
            && config('quote_api.consumer') === 'grupotalento'
            && in_array(config('quote_api.environment'), ['local', 'development', 'production'], true);
    }

    public function authenticates(Request $request): bool
    {
        $header = $request->headers->get('Authorization');
        if (! $this->active() || ! is_string($header) || strlen($header) > 1024
            || preg_match('/^Bearer[ \t]+([A-Za-z0-9._~+\/=\-]+)[ \t]*$/Di', $header, $match) !== 1) {
            return false;
        }

        return hash_equals(config('quote_api.bearer_token'), $match[1]);
    }

    public function canRead(Quote $quote): bool
    {
        $grants = config('quote_api.allowed_quote_uuids');
        if (! $this->active() || ! is_array($grants) || ! Str::isUuid($quote->uuid)) {
            return false;
        }

        return in_array($quote->uuid, $grants, true);
    }

    public function fingerprint(): string
    {
        if (! $this->active()) {
            throw new QuoteUnavailable;
        }

        return hash_hmac('sha256', 'usb-stock:quote-pdf:'.config('quote_api.consumer').':'.config('quote_api.environment'), config('quote_api.bearer_token'));
    }
}
