<?php

declare(strict_types=1);

use Rasuvaeff\Yii3Utm\CookieUtmHistoryStore;
use Rasuvaeff\Yii3Utm\BodyUtmSource;
use Rasuvaeff\Yii3Utm\ClickIds;
use Rasuvaeff\Yii3Utm\DefaultChannelResolver;
use Rasuvaeff\Yii3Utm\DefaultLandingPageSanitizer;
use Rasuvaeff\Yii3Utm\HeaderUtmSource;
use Rasuvaeff\Yii3Utm\QueryUtmSource;
use Rasuvaeff\Yii3Utm\UtmCaptureMiddleware;
use Rasuvaeff\Yii3Utm\UtmCookieCodec;
use Rasuvaeff\Yii3Utm\UtmSimilarity;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;
use Yiisoft\Cookies\Cookie;

return [
    'rasuvaeff/yii3-utm' => [
        'capture' => [
            'enabled' => true,
            'ignoredPaths' => [],
            'maxTouchpoints' => 5,
            'maxTouchpointAge' => UtmCaptureMiddleware::DEFAULT_MAX_TOUCHPOINT_AGE,
            'similarity' => UtmSimilarity::Full,
            'updateExisting' => false,
            'captureOrganic' => false,
            'clearHistoryWithoutConsent' => false,
            'sources' => [
                'query' => [
                    'utmKeys' => QueryUtmSource::DEFAULT_UTM_KEYS,
                    'clickIdKeys' => ClickIds::KNOWN_KEYS,
                ],
                'header' => [
                    'prefix' => HeaderUtmSource::DEFAULT_PREFIX,
                    'clickIdKeys' => ClickIds::KNOWN_KEYS,
                ],
                'body' => [
                    'key' => BodyUtmSource::DEFAULT_KEY,
                    'clickIdKeys' => ClickIds::KNOWN_KEYS,
                ],
            ],
        ],
        'sanitizer' => [
            'allowedQueryKeys' => DefaultLandingPageSanitizer::DEFAULT_ALLOWED_QUERY_KEYS,
            'maxLength' => UtmTouchpoint::MAX_LANDING_PAGE_LENGTH,
        ],
        'channel' => [
            'paidMediums' => DefaultChannelResolver::PAID_MEDIUMS,
            'emailMediums' => DefaultChannelResolver::EMAIL_MEDIUMS,
            'socialMediums' => DefaultChannelResolver::SOCIAL_MEDIUMS,
            'socialHosts' => DefaultChannelResolver::SOCIAL_HOSTS,
            'searchHosts' => DefaultChannelResolver::SEARCH_HOSTS,
        ],
        'cookie' => [
            'name' => CookieUtmHistoryStore::DEFAULT_NAME,
            'ttlDays' => CookieUtmHistoryStore::DEFAULT_TTL_DAYS,
            'secure' => true,
            // A server cookie by default. `false` is the client profile: only
            // for same-domain SPA reads, and spoofable by definition.
            'httpOnly' => true,
            'sameSite' => Cookie::SAME_SITE_LAX,
            'path' => '/',
            'domain' => null,
            'maxLength' => UtmCookieCodec::DEFAULT_MAX_LENGTH,
        ],
        'attribution' => [
            'similarity' => UtmSimilarity::Full,
            'maxTouchpoints' => 5,
        ],
    ],
];
