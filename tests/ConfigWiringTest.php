<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Utm\AllowAllConsentPolicy;
use Rasuvaeff\Yii3Utm\Channel;
use Rasuvaeff\Yii3Utm\ChannelResolver;
use Rasuvaeff\Yii3Utm\ConsentPolicy;
use Rasuvaeff\Yii3Utm\CookieUtmHistoryStore;
use Rasuvaeff\Yii3Utm\DefaultChannelResolver;
use Rasuvaeff\Yii3Utm\DefaultLandingPageSanitizer;
use Rasuvaeff\Yii3Utm\InMemoryUtmAttributionRepository;
use Rasuvaeff\Yii3Utm\LandingPageSanitizer;
use Rasuvaeff\Yii3Utm\Tests\Support\FrozenClock;
use Rasuvaeff\Yii3Utm\UtmAttributionEventHandler;
use Rasuvaeff\Yii3Utm\UtmAttributionRepository;
use Rasuvaeff\Yii3Utm\UtmAttributionService;
use Rasuvaeff\Yii3Utm\UtmCaptureMiddleware;
use Rasuvaeff\Yii3Utm\UtmCookieCodec;
use Rasuvaeff\Yii3Utm\UtmHistoryStore;
use Rasuvaeff\Yii3Utm\UtmParameters;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

/**
 * `config/di.php` and `config/params.php` sit outside `src`, so neither cs,
 * psalm nor the rest of the unit suite ever looks at them. Without this test a
 * mistake there surfaces at deploy time.
 *
 * The assertions are positive on purpose: an "everything resolves except this
 * list" test would exempt exactly the definition most likely to be broken.
 */
#[Test]
#[CoversNothing]
final class ConfigWiringTest
{
    public function captureStackResolves(): void
    {
        $container = $this->container();

        Assert::instanceOf($container->get(UtmCookieCodec::class), UtmCookieCodec::class);
        Assert::instanceOf($container->get(LandingPageSanitizer::class), DefaultLandingPageSanitizer::class);
        Assert::instanceOf($container->get(ChannelResolver::class), DefaultChannelResolver::class);
        Assert::instanceOf($container->get(ConsentPolicy::class), AllowAllConsentPolicy::class);
        Assert::instanceOf($container->get(UtmHistoryStore::class), CookieUtmHistoryStore::class);
        Assert::instanceOf($container->get(UtmCaptureMiddleware::class), UtmCaptureMiddleware::class);
    }

    /**
     * The one-source rule: a core package that binds the swappable key makes
     * `yiisoft/config` throw `Duplicate key` as soon as a backend binds it too.
     */
    public function coreDoesNotBindTheRepository(): void
    {
        Assert::false(\array_key_exists(UtmAttributionRepository::class, $this->definitions()));
    }

    /**
     * `UtmAttributionEventHandler` is defined for the application to wire up,
     * but the core must never subscribe itself: an application without a
     * dispatcher for `UtmAttributionEvent` would otherwise silently record
     * nothing while looking correctly configured.
     */
    public function coreDoesNotRegisterAnEventsGroup(): void
    {
        /** @var array<string, mixed> $composerJson */
        $composerJson = \json_decode(
            \file_get_contents(\dirname(__DIR__) . '/composer.json') ?: '{}',
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
        /** @var array<string, mixed> $configPlugin */
        $configPlugin = $composerJson['extra']['config-plugin'] ?? [];

        foreach (\array_keys($configPlugin) as $group) {
            Assert::false(\str_contains((string) $group, 'events'));
        }
    }

    /**
     * The service is defined even though its dependency is not: the container
     * resolves lazily, so an application without a backend fails loudly on
     * first use instead of writing attribution nowhere.
     */
    public function serviceResolvesOnceARepositoryIsSupplied(): void
    {
        Assert::true(\array_key_exists(UtmAttributionService::class, $this->definitions()));

        $container = $this->container(withRepository: true);

        Assert::instanceOf($container->get(UtmAttributionService::class), UtmAttributionService::class);
        Assert::instanceOf($container->get(UtmAttributionEventHandler::class), UtmAttributionEventHandler::class);
    }

    public function paramsCoverEveryKeyTheDefinitionsRead(): void
    {
        $params = $this->params()['rasuvaeff/yii3-utm'];

        Assert::same(
            \array_keys($params),
            ['capture', 'sanitizer', 'channel', 'cookie', 'attribution'],
        );
        Assert::same(
            \array_keys($params['capture']),
            [
                'enabled', 'ignoredPaths', 'maxTouchpoints', 'maxTouchpointAge',
                'similarity', 'updateExisting', 'captureOrganic', 'clearHistoryWithoutConsent', 'sources',
            ],
        );
        Assert::same(\array_keys($params['capture']['sources']), ['query', 'header', 'body']);
        Assert::same($params['cookie']['httpOnly'], true);
        Assert::same($params['cookie']['secure'], true);
    }

    public function configurableServicesUseParams(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-utm']['sanitizer']['allowedQueryKeys'] = ['keep'];
        $params['rasuvaeff/yii3-utm']['channel']['paidMediums'] = ['sponsored'];
        $container = $this->container(params: $params);

        $sanitizer = $container->get(LandingPageSanitizer::class);
        $resolver = $container->get(ChannelResolver::class);
        $touchpoint = UtmTouchpoint::of(
            utm: new UtmParameters(medium: 'sponsored'),
            occurredAt: new \DateTimeImmutable('2026-08-01 12:00:00', new \DateTimeZone('UTC')),
        );

        Assert::same($sanitizer->sanitize('https://example.com/?keep=yes&drop=no'), 'https://example.com/?keep=yes');
        Assert::same($resolver->resolve($touchpoint), Channel::Paid);
    }

    /**
     * @return array<string, mixed>
     */
    private function definitions(?array $params = null): array
    {
        $params ??= $this->params();

        /** @var array<string, mixed> $definitions */
        $definitions = require \dirname(__DIR__) . '/config/di.php';

        return $definitions;
    }

    /**
     * @return array<string, mixed>
     */
    private function params(): array
    {
        /** @var array<string, mixed> $params */
        $params = require \dirname(__DIR__) . '/config/params.php';

        return $params;
    }

    private function container(bool $withRepository = false, ?array $params = null): Container
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-08-01 12:00:00', new \DateTimeZone('UTC')));

        $definitions = $this->definitions($params);
        $definitions[ClockInterface::class] = static fn(): ClockInterface => $clock;

        if ($withRepository) {
            $definitions[UtmAttributionRepository::class] = static fn(): UtmAttributionRepository
                => new InMemoryUtmAttributionRepository($clock);
        }

        return new Container(ContainerConfig::create()->withDefinitions($definitions));
    }
}
