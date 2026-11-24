<?php

namespace Kanboard\Plugin\Switchboard\Model;

use Closure;

final class ConfirmToken
{
    private const TTL = 120;
    private readonly Closure $clock;

    public function __construct(private readonly string $secret, callable $clock)
    {
        $this->clock = Closure::fromCallable($clock);
    }

    public function issue(int $userId, string $commandId, array $input, array $ctx): string
    {
        return $this->encode($userId, $commandId, $input, $ctx, ($this->clock)() + self::TTL);
    }

    public function verify(string $token, int $userId, string $commandId, array $input, array $ctx): bool
    {
        $payload = base64_decode(strtr($token, '-_', '+/'), true);
        if ($payload === false || !preg_match('/^([0-9]{1,19})\.[0-9a-f]{64}$/D', $payload, $parts)) {
            return false;
        }

        $expiry = (int) $parts[1];

        return $expiry >= ($this->clock)() && hash_equals($this->encode($userId, $commandId, $input, $ctx, $expiry), $token);
    }

    private function encode(int $userId, string $commandId, array $input, array $ctx, int $expiry): string
    {
        // Input has already been reduced to the flat object described by the command schema.
        ksort($input, SORT_STRING);
        $json = json_encode((object) $input, JSON_THROW_ON_ERROR);
        $context = json_encode([
            'project_id' => $ctx['project_id'] ?? null,
            'task_id' => $ctx['task_id'] ?? null,
        ], JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $userId.'|'.$commandId.'|'.$json.'|'.$context.'|'.$expiry, $this->secret);

        return rtrim(strtr(base64_encode($expiry.'.'.$signature), '+/', '-_'), '=');
    }
}
