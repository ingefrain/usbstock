<?php

namespace App\Commerce;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/** An encrypted convenience preference, never identity or authorization. */
final class SellerPreference
{
    public const COOKIE = 'usbstock_seller_preference';

    public function read(Request $request): ?string
    {
        $value = json_decode((string) $request->cookie(self::COOKIE, ''), true);
        if (! is_array($value) || ! is_int($value['expires'] ?? null) || $value['expires'] <= now()->timestamp
            || ! is_string($value['name'] ?? null) || mb_strlen($value['name']) > 180 || trim($value['name']) === '') {
            return null;
        }

        return $value['name'];
    }

    public function cookie(Request $request, ?string $name): Cookie
    {
        $days = max(1, min(365, (int) config('commerce.seller_cookie_days', 90)));
        $expires = $name === null ? now()->subYear() : now()->addDays($days);
        $value = $name === null ? '' : json_encode(['name' => $name, 'expires' => $expires->timestamp], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return new Cookie(self::COOKIE, $value, $expires, '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX);
    }
}
