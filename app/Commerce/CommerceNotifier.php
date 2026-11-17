<?php

namespace App\Commerce;

use App\Services\Outbox\OutboxDispatcher;
use Illuminate\Support\Facades\Log;

final class CommerceNotifier
{
    public function queue(string $event, string $reference, string $message): void
    {
        $recipient = config('commerce.notify_email');
        if (! is_string($recipient) || $recipient === '') {
            return;
        }
        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            Log::warning('commerce.notification_recipient_invalid');

            return;
        }
        app(OutboxDispatcher::class)->email($recipient, [
            'subject' => 'USB Stock · '.$event,
            'message' => $message."\nReferencia: ".$reference."\nConsulta el panel administrativo para revisar los detalles.",
        ], 'commerce', 'commerce:'.$event.':'.$reference, $reference);
    }
}
