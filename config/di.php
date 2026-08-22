<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Utm\AllowAllConsentPolicy;
use Rasuvaeff\Yii3Utm\BodyUtmSource;
use Rasuvaeff\Yii3Utm\ChannelResolver;
use Rasuvaeff\Yii3Utm\ConsentPolicy;
use Rasuvaeff\Yii3Utm\CookieUtmHistoryStore;
use Rasuvaeff\Yii3Utm\DefaultChannelResolver;
use Rasuvaeff\Yii3Utm\DefaultLandingPageSanitizer;
use Rasuvaeff\Yii3Utm\HeaderUtmSource;
use Rasuvaeff\Yii3Utm\LandingPageSanitizer;
use Rasuvaeff\Yii3Utm\QueryUtmSource;
use Rasuvaeff\Yii3Utm\UtmAttributionEventHandler;
use Rasuvaeff\Yii3Utm\UtmAttributionRepository;
use Rasuvaeff\Yii3Utm\UtmAttributionService;
use Rasuvaeff\Yii3Utm\UtmCaptureMiddleware;
use Rasuvaeff\Yii3Utm\UtmCookieCodec;
use Rasuvaeff\Yii3Utm\UtmHistoryStore;

/**
 * The package binds its own building blocks and the facade services.
 *
 * It deliberately does **not** bind {@see UtmAttributionRepository}: that key
 * belongs to exactly one source — `rasuvaeff/yii3-utm-db` or the application.
 * Two vendor packages defining the same key in the same group is what makes
 * `yiisoft/config` throw `Duplicate key`.
 *
 * {@see UtmAttributionService} is defined but resolves lazily. Without a
 * repository the container fails loudly on first use, which beats silently
 * writing attribution nowhere.
 *
 * @var array $params
 */

return [
    // The codec takes the container's sanitizer, not its own default: the
    // cookie carries the same untrusted referrer and landing page a query
    // string does, and an application that configures a custom allow-list must
    // not end up with two different ones for the same data.
    UtmCookieCodec::class => static fn (
        LandingPageSanitizer $sanitizer,
    ): UtmCookieCodec => new UtmCookieCodec(
        maxLength: $params['rasuvaeff/yii3-utm']['cookie']['maxLength'],
        sanitizer: $sanitizer,
    ),

    LandingPageSanitizer::class => static fn (): LandingPageSanitizer => new DefaultLandingPageSanitizer(
        allowedQueryKeys: $params['rasuvaeff/yii3-utm']['sanitizer']['allowedQueryKeys'],
        maxLength: $params['rasuvaeff/yii3-utm']['sanitizer']['maxLength'],
    ),

    ChannelResolver::class => static fn (): ChannelResolver => new DefaultChannelResolver(
        paidMediums: $params['rasuvaeff/yii3-utm']['channel']['paidMediums'],
        emailMediums: $params['rasuvaeff/yii3-utm']['channel']['emailMediums'],
        socialMediums: $params['rasuvaeff/yii3-utm']['channel']['socialMediums'],
        socialHosts: $params['rasuvaeff/yii3-utm']['channel']['socialHosts'],
        searchHosts: $params['rasuvaeff/yii3-utm']['channel']['searchHosts'],
    ),

    ConsentPolicy::class => AllowAllConsentPolicy::class,

    UtmHistoryStore::class => static fn (
        UtmCookieCodec $codec,
        ClockInterface $clock,
    ): UtmHistoryStore => new CookieUtmHistoryStore(
        codec: $codec,
        clock: $clock,
        name: $params['rasuvaeff/yii3-utm']['cookie']['name'],
        ttlDays: $params['rasuvaeff/yii3-utm']['cookie']['ttlDays'],
        secure: $params['rasuvaeff/yii3-utm']['cookie']['secure'],
        httpOnly: $params['rasuvaeff/yii3-utm']['cookie']['httpOnly'],
        sameSite: $params['rasuvaeff/yii3-utm']['cookie']['sameSite'],
        path: $params['rasuvaeff/yii3-utm']['cookie']['path'],
        domain: $params['rasuvaeff/yii3-utm']['cookie']['domain'],
    ),

    UtmCaptureMiddleware::class => static fn (
        ClockInterface $clock,
        LandingPageSanitizer $sanitizer,
        UtmHistoryStore $store,
        ConsentPolicy $consentPolicy,
    ): UtmCaptureMiddleware => new UtmCaptureMiddleware(
        sources: [
            new QueryUtmSource(
                clock: $clock,
                sanitizer: $sanitizer,
                utmKeys: $params['rasuvaeff/yii3-utm']['capture']['sources']['query']['utmKeys'],
                clickIdKeys: $params['rasuvaeff/yii3-utm']['capture']['sources']['query']['clickIdKeys'],
            ),
            new HeaderUtmSource(
                clock: $clock,
                sanitizer: $sanitizer,
                prefix: $params['rasuvaeff/yii3-utm']['capture']['sources']['header']['prefix'],
                clickIdKeys: $params['rasuvaeff/yii3-utm']['capture']['sources']['header']['clickIdKeys'],
            ),
            new BodyUtmSource(
                clock: $clock,
                sanitizer: $sanitizer,
                key: $params['rasuvaeff/yii3-utm']['capture']['sources']['body']['key'],
                clickIdKeys: $params['rasuvaeff/yii3-utm']['capture']['sources']['body']['clickIdKeys'],
            ),
        ],
        store: $store,
        clock: $clock,
        consentPolicy: $consentPolicy,
        similarity: $params['rasuvaeff/yii3-utm']['capture']['similarity'],
        ignoredPaths: $params['rasuvaeff/yii3-utm']['capture']['ignoredPaths'],
        maxTouchpoints: $params['rasuvaeff/yii3-utm']['capture']['maxTouchpoints'],
        maxTouchpointAge: $params['rasuvaeff/yii3-utm']['capture']['maxTouchpointAge'],
        enabled: $params['rasuvaeff/yii3-utm']['capture']['enabled'],
        updateExisting: $params['rasuvaeff/yii3-utm']['capture']['updateExisting'],
        captureOrganic: $params['rasuvaeff/yii3-utm']['capture']['captureOrganic'],
        clearHistoryWithoutConsent: $params['rasuvaeff/yii3-utm']['capture']['clearHistoryWithoutConsent'],
    ),

    UtmAttributionService::class => static fn (
        UtmAttributionRepository $repository,
    ): UtmAttributionService => new UtmAttributionService(
        repository: $repository,
        similarity: $params['rasuvaeff/yii3-utm']['attribution']['similarity'],
        maxTouchpoints: $params['rasuvaeff/yii3-utm']['attribution']['maxTouchpoints'],
    ),

    UtmAttributionEventHandler::class => UtmAttributionEventHandler::class,
];
