<?php

namespace App\Http\Middleware;

use App\Models\AmpProjectConnection;
use App\Services\AmpSignature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyAmpConnectionSignature
{
    public function __construct(private readonly AmpSignature $signature) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $connection = $request->route('ampProjectConnection');
        if (! $connection instanceof AmpProjectConnection) {
            return response()->json(['message' => 'Unknown Amp project connection.'], 404);
        }
        $secret = (string) $connection->callback_signing_secret;
        $eventId = (string) $request->header('X-Orc-Event-Id');
        $timestampHeader = (string) $request->header('X-Orc-Timestamp');
        $providedSignature = (string) $request->header('X-Orc-Signature');
        if ($secret === '' || $eventId === '' || ! ctype_digit($timestampHeader) || $providedSignature === '') {
            return response()->json(['message' => 'Unauthenticated Amp callback.'], 401);
        }
        $timestamp = (int) $timestampHeader;
        if (abs(now()->getTimestamp() - $timestamp) > (int) config('services.amp.signature_tolerance_seconds', 300)) {
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
        $request->attributes->set('amp_project_connection', $connection);

        return $next($request);
    }
}
