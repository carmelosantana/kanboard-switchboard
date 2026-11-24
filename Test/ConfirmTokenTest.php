<?php

use Kanboard\Plugin\Switchboard\Model\ConfirmToken;
use PHPUnit\Framework\TestCase;

final class ConfirmTokenTest extends TestCase
{
    public function testTokenSignsSortedInputAndUsesAnUnpaddedBase64UrlEnvelope(): void
    {
        $tokens = new ConfirmToken('test-key', static fn(): int => 100);
        $ctx = ['task_id' => 1, 'project_id' => 5];
        $token = $tokens->issue(7, 'test.command', ['b' => 'two', 'a' => 'one'], $ctx);
        $payload = '220.'.hash_hmac('sha256', '7|test.command|{"a":"one","b":"two"}|{"project_id":5,"task_id":1}|220', 'test-key');

        $this->assertSame(rtrim(strtr(base64_encode($payload), '+/', '-_'), '='), $token);
        $this->assertTrue($tokens->verify($token, 7, 'test.command', ['a' => 'one', 'b' => 'two'], array_reverse($ctx, true)));
        $this->assertFalse($tokens->verify($token, 7, 'test.other', ['a' => 'one', 'b' => 'two'], $ctx));
    }

    public function testTamperingWithOneByteInvalidatesTheToken(): void
    {
        $tokens = new ConfirmToken('test-key', static fn(): int => 100);
        $token = $tokens->issue(7, 'test.command', [], []);
        $token[12] = $token[12] === 'A' ? 'B' : 'A';

        $this->assertFalse($tokens->verify($token, 7, 'test.command', [], []));
        $this->assertFalse($tokens->verify('invalid!', 7, 'test.command', [], []));
    }
}
