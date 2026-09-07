<?php

namespace App\Services;

class AmpSignature
{
    public function sign(string $body, string $eventId, int $timestamp, string $secret): string
    {
        return 'sha256='.hash_hmac(
            'sha256',
            $timestamp.'.'.$eventId.'.'.$body,
            $secret,
        );
    }

    public function verify(
        string $body,
        string $eventId,
        int $timestamp,
        string $providedSignature,
        string $secret,
    ): bool {
        return hash_equals(
            $this->sign($body, $eventId, $timestamp, $secret),
            $providedSignature,
        );
    }
}
