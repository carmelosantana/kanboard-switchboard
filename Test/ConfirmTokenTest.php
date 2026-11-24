<?php

use Kanboard\Plugin\Switchboard\Model\ConfirmToken;
use PHPUnit\Framework\TestCase;

final class ConfirmTokenTest extends TestCase
{
    public function testTokenSignsSortedInputAndUsesAnUnpaddedBase64UrlEnvelope(): void
    {
        $tokens = new ConfirmToken('test-key', static fn(): int => 100);
        $token = $tokens->issue(7, 'test.command', ['b' => 'two', 'a' => 'one']);
        $payload = '220.'.hash_hmac('sha256', '7|test.command|{"a":"one","b":"two"}|220', 'test-key');

        $this->assertSame(rtrim(strtr(base64_encode($payload), '+/', '-_'), '='), $token);
        $this->assertTrue($tokens->verify($token, 7, 'test.command', ['a' => 'one', 'b' => 'two']));
        $this->assertFalse($tokens->verify($token, 7, 'test.other', ['a' => 'one', 'b' => 'two']));
    }

    public function testTamperingWithOneByteInvalidatesTheToken(): void
    {
        $tokens = new ConfirmToken('test-key', static fn(): int => 100);
        $token = $tokens->issue(7, 'test.command', []);
        $token[12] = $token[12] === 'A' ? 'B' : 'A';

        $this->assertFalse($tokens->verify($token, 7, 'test.command', []));
        $this->assertFalse($tokens->verify('invalid!', 7, 'test.command', []));
    }
}
