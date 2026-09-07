<?php

namespace App\Http\Middleware;

use App\Services\AmpSignature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyAmpCallbackSignature
{
    public function __construct(private readonly AmpSignature $signature) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.amp.callback_signing_secret');
        $eventId = (string) $request->header('X-Orc-Event-Id');
        $timestampHeader = (string) $request->header('X-Orc-Timestamp');
        $providedSignature = (string) $request->header('X-Orc-Signature');

        if ($secret === '' || $eventId === '' || ! ctype_digit($timestampHeader) || $providedSignature === '') {
            return response()->json(['message' => 'Unauthenticated Amp callback.'], 401);
        }

        $timestamp = (int) $timestampHeader;
        $allowedSkew = (int) config('services.amp.signature_tolerance_seconds', 300);
        if (abs(now()->getTimestamp() - $timestamp) > $allowedSkew) {
            return response()->json(['message' => 'Expired Amp callback.'], 401);
        }

        if (! $this->signature->verify(
            $request->getContent(),
            $eventId,
            $timestamp,
            $providedSignature,
            $secret,
        )) {
            return response()->json(['message' => 'Invalid Amp callback signature.'], 401);
        }

        $request->attributes->set('amp_event_id', $eventId);

        return $next($request);
    }
}
