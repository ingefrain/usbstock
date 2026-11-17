<?php

namespace App\Commerce;

use App\Services\Outbox\OutboxDispatcher;
use Illuminate\Support\Facades\Log;

final class CommerceNotifier
{
    public function queue(string $event, string $reference, string $message): void
    {
        if (config('commerce.sync.read_only')) {
            return;
        }
        $recipient = config('commerce.notify_email');
        if (! is_string($recipient) || $recipient === '') {
            return;
        }
        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            Log::warning('commerce.notification_recipient_invalid');

            return;
        }
        try {
            app(OutboxDispatcher::class)->email($recipient, [
                'subject' => 'USB Stock · '.$event,
                'message' => $message."\nReferencia: ".$reference."\nConsulta el panel administrativo para revisar los detalles.",
            ], 'commerce', 'commerce:'.$event.':'.$reference, $reference);
        } catch (\Throwable) {
            Log::warning('commerce.notification_enqueue_failed', ['reference' => $reference]);
        }
    }
}
