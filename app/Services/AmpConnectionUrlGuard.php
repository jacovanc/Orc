<?php

namespace App\Services;

use App\Domain\Workflow\Exceptions\WorkflowConflict;

class AmpConnectionUrlGuard
{
    public function assertAllowed(string $url): void
    {
        $parts = parse_url(trim($url));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowedHosts = collect(config('services.amp.webhook_allowed_hosts', ['ampcode.com']))
            ->map(fn (string $allowed): string => strtolower(trim($allowed)))
            ->filter();
        $hostAllowed = $allowedHosts->contains(
            fn (string $allowed): bool => $host === $allowed || str_ends_with($host, '.'.$allowed)
        );

        if (
            ($parts['scheme'] ?? null) !== 'https'
            || $host === ''
            || ! $hostAllowed
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || empty($parts['path'])
        ) {
            throw new WorkflowConflict('The controller URL must be an HTTPS Amp webhook capability on an allowed host.');
        }
    }
}
