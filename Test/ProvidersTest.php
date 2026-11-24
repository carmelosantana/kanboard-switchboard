<?php

use Kanboard\Core\Plugin\Hook;
use Kanboard\Model\ConfigModel;
use Kanboard\Plugin\Switchboard\Model\ProviderFailed;
use Kanboard\Plugin\Switchboard\Model\Providers;
use Kanboard\Plugin\Switchboard\Model\UnknownProvider;
use PHPUnit\Framework\TestCase;
use Pimple\Container;
use Psr\Log\LoggerInterface;

final class ProvidersTest extends TestCase
{
    private const USER = ['id' => 7, 'role' => 'app-user'];
    private const CONTEXT = ['route' => ['plugin' => ''], 'project_id' => null];

    private Container $container;
    private array $warnings = [];
    private string $enabled = '[]';

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->container['hook'] = new Hook();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $warning): void {
            $this->warnings[] = $warning;
        });
        $this->container['logger'] = $logger;
        $config = $this->createMock(ConfigModel::class);
        $config->method('get')->with('switchboard_external_providers', '[]')
            ->willReturnCallback(fn(): string => $this->enabled);
        $this->container['configModel'] = $config;
    }

    public function testSensitiveListingRequiresItsPrefixOrPluginPage(): void
    {
        $this->listen([$this->provider('vault.entries', ['sensitive' => true, 'prefix' => '!'])]);
        $providers = new Providers($this->container);
        $rows = [['id' => 'vault.entries', 'title' => 'vault.entries', 'prefix' => '!']];

        $this->assertSame([], $providers->listFor(self::USER, self::CONTEXT, null));
        $this->assertSame([], $providers->listFor(self::USER, self::CONTEXT, '#'));
        $this->assertSame($rows, $providers->listFor(self::USER, self::CONTEXT, '!'));
        $this->assertSame($rows, $providers->listFor(self::USER, ['route' => ['plugin' => 'Vault']], null));
    }

    public function testSensitiveSearchChecksScopeAndStripsExtraFields(): void
    {
        $this->listen([$this->provider('vault.entries', [
            'sensitive' => true,
            'prefix' => '!',
            'search' => static fn(): array => ['entries' => [
                ['title' => 'Entry', 'url' => '/entry', 'subline' => 'Private', 'icon' => 'lock', 'secret' => 'Secret'],
            ], 'cursor' => null],
        ])]);
        $providers = new Providers($this->container);
        $expected = ['entries' => [['title' => 'Entry', 'url' => '/entry']], 'cursor' => null];

        $this->assertSame($expected, $providers->search('vault.entries', self::USER, self::CONTEXT + ['prefix' => '!'], 'entry', null, 10));
        $this->assertSame($expected, $providers->search('vault.entries', self::USER, ['route' => ['plugin' => 'Vault']], 'entry', null, 10));

        $this->expectException(UnknownProvider::class);
        $providers->search('vault.entries', self::USER, self::CONTEXT, '!entry', null, 10);
    }

    public function testListingFiltersAndSortsProviders(): void
    {
        $this->listen([
            $this->provider('acme.last', ['order' => static fn(array $ctx): int => $ctx['last_order']]),
            $this->provider('acme.external', ['external' => true]),
            $this->provider('acme.hidden', ['order' => static fn(): ?int => null]),
            $this->provider('acme.denied', ['permission' => 'app-admin']),
            $this->provider('acme.first', ['order' => static fn(): int => 5]),
        ]);
        $providers = new Providers($this->container);
        $context = self::CONTEXT + ['last_order' => 50];

        $this->assertSame(['acme.first', 'acme.last'], array_column($providers->listFor(self::USER, $context, null), 'id'));
        $this->enabled = '["acme.external"]';
        $this->assertSame(['acme.first', 'acme.external', 'acme.last'], array_column($providers->listFor(self::USER, $context, null), 'id'));
    }

    /** @dataProvider unlistedProviders */
    public function testSearchRefusesUnlistedProviders(string $id): void
    {
        $search = static fn() => throw new LogicException('Search must never run');
        $this->listen([
            $this->provider('acme.external', ['external' => true, 'search' => $search]),
            $this->provider('acme.hidden', ['order' => static fn(): ?int => null, 'search' => $search]),
            $this->provider('acme.denied', ['permission' => 'app-admin', 'search' => $search]),
        ]);

        $this->expectException(UnknownProvider::class);
        (new Providers($this->container))->search($id, self::USER, self::CONTEXT, '', null, 10);
    }

    public static function unlistedProviders(): array
    {
        return [['acme.external'], ['acme.hidden'], ['acme.denied'], ['acme.missing']];
    }

    public function testProviderFailuresLogOnlyTheExceptionClass(): void
    {
        $this->listen([$this->provider('acme.broken', [
            'search' => static fn() => throw new LogicException('secret-msg'),
        ])]);

        try {
            (new Providers($this->container))->search('acme.broken', self::USER, self::CONTEXT, '', null, 10);
            $this->fail('Expected ProviderFailed');
        } catch (ProviderFailed $exception) {
            $this->assertStringNotContainsString('secret-msg', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertSame(['Switchboard: provider "acme.broken" failed (LogicException)'], $this->warnings);
        }
    }

    public function testBrokenRegistrationsAreSkippedAndFirstRegistrationWins(): void
    {
        $this->container['hook']->on('switchboard:search', static fn() => throw new LogicException('secret-msg'));
        $this->container['hook']->on('switchboard:search', static fn(): string => 'garbage');
        $this->listen([
            $this->provider('plain'),
            $this->provider('acme.entries', ['title' => 'First']),
            $this->provider('acme.entries', ['title' => 'Duplicate']),
            $this->provider('acme.untitled', ['title' => ' ']),
            $this->provider('acme.bad-search', ['search' => null]),
        ]);

        $rows = (new Providers($this->container))->listFor(self::USER, self::CONTEXT, null);
        $this->assertSame([['id' => 'acme.entries', 'title' => 'First', 'prefix' => '#']], $rows);
        $this->assertSame([
            'Switchboard: skipped provider "" (listener failed)',
            'Switchboard: skipped provider "" (listener failed)',
            'Switchboard: skipped provider "plain" (invalid id)',
            'Switchboard: skipped provider "acme.entries" (duplicate id)',
            'Switchboard: skipped provider "acme.untitled" (missing title)',
            'Switchboard: skipped provider "acme.bad-search" (search not callable)',
        ], $this->warnings);
    }

    public function testSearchPassesArgumentsAndNormalizesAndCapsEntries(): void
    {
        $this->listen([$this->provider('acme.entries', [
            'search' => function (array $user, string $term, ?string $cursor, int $limit): array {
                $this->assertSame([self::USER, 'needle', '2', 2], [$user, $term, $cursor, $limit]);

                return ['entries' => [
                    ['title' => 'Missing URL'],
                    ['url' => '/missing-title'],
                    ['title' => 'First', 'url' => '/first', 'subline' => 'Match', 'icon' => 'star', 'extra' => 1],
                    ['title' => 'Second', 'url' => '/second'],
                    ['title' => 'Over limit', 'url' => '/third'],
                ], 'cursor' => '4'];
            },
        ])]);

        $this->assertSame(['entries' => [
            ['title' => 'First', 'subline' => 'Match', 'url' => '/first', 'icon' => 'star'],
            ['title' => 'Second', 'subline' => '', 'url' => '/second', 'icon' => ''],
        ], 'cursor' => '4'], (new Providers($this->container))->search(
            'acme.entries', self::USER, self::CONTEXT, 'needle', '2', 2
        ));
    }

    private function provider(string $id, array $overrides = []): array
    {
        return $overrides + [
            'id' => $id,
            'title' => $id,
            'prefix' => '#',
            'order' => static fn(): int => 10,
            'permission' => 'app-user',
            'search' => static fn(): array => ['entries' => [], 'cursor' => null],
        ];
    }

    private function listen(array $providers): void
    {
        $this->container['hook']->on('switchboard:search', static fn(array $ctx): array => $providers);
    }
}
