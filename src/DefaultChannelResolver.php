<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

/**
 * Rule-based classification with configurable vocabularies.
 *
 * Order of the rules is the contract: a click identifier outranks everything
 * (auto-tagged paid traffic often carries no `utm_medium` at all), then the
 * declared medium, then the referrer.
 *
 * @api
 */
final readonly class DefaultChannelResolver implements ChannelResolver
{
    /**
     * @var list<string>
     */
    public const array PAID_MEDIUMS = [
        'cpc', 'ppc', 'paidsearch', 'paid', 'cpm', 'cpv', 'cpa', 'display', 'banner', 'retargeting',
    ];

    /**
     * @var list<string>
     */
    public const array EMAIL_MEDIUMS = ['email', 'e-mail', 'newsletter', 'mail'];

    /**
     * @var list<string>
     */
    public const array SOCIAL_MEDIUMS = ['social', 'social-network', 'social_network', 'sm', 'smm'];

    /**
     * @var list<string>
     */
    public const array SOCIAL_HOSTS = [
        'facebook.com', 'instagram.com', 'twitter.com', 'x.com', 't.co', 'vk.com', 'ok.ru',
        'linkedin.com', 'tiktok.com', 'pinterest.com', 'reddit.com', 'youtube.com', 't.me',
    ];

    /**
     * @var list<string>
     */
    public const array SEARCH_HOSTS = [
        'google.com', 'yandex.ru', 'bing.com', 'duckduckgo.com', 'yahoo.com', 'baidu.com',
    ];

    /**
     * @param list<string> $paidMediums
     * @param list<string> $emailMediums
     * @param list<string> $socialMediums
     * @param list<string> $socialHosts
     * @param list<string> $searchHosts
     */
    public function __construct(
        private array $paidMediums = self::PAID_MEDIUMS,
        private array $emailMediums = self::EMAIL_MEDIUMS,
        private array $socialMediums = self::SOCIAL_MEDIUMS,
        private array $socialHosts = self::SOCIAL_HOSTS,
        private array $searchHosts = self::SEARCH_HOSTS,
    ) {}

    #[\Override]
    public function resolve(UtmTouchpoint $touchpoint): Channel
    {
        if (!$touchpoint->clickIds->isEmpty()) {
            return Channel::Paid;
        }

        $medium = $touchpoint->utm->medium === null ? null : \mb_strtolower($touchpoint->utm->medium);

        if ($medium !== null) {
            $byMedium = $this->fromMedium($medium);

            if ($byMedium instanceof Channel) {
                return $byMedium;
            }
        }

        $referrer = $touchpoint->referrer;

        if (!$referrer instanceof Referrer) {
            return $touchpoint->utm->isEmpty() ? Channel::Direct : Channel::Other;
        }

        if ($this->matchesHost($referrer->host, $this->socialHosts)) {
            return Channel::Social;
        }

        if ($this->matchesHost($referrer->host, $this->searchHosts)) {
            return Channel::Organic;
        }

        return Channel::Referral;
    }

    private function fromMedium(string $medium): ?Channel
    {
        if (\in_array($medium, $this->paidMediums, true)) {
            return Channel::Paid;
        }

        if (\in_array($medium, $this->emailMediums, true)) {
            return Channel::Email;
        }

        if (\in_array($medium, $this->socialMediums, true)) {
            return Channel::Social;
        }

        if ($medium === 'organic') {
            return Channel::Organic;
        }

        if ($medium === 'referral') {
            return Channel::Referral;
        }

        return null;
    }

    /**
     * @param list<string> $hosts
     */
    private function matchesHost(string $host, array $hosts): bool
    {
        foreach ($hosts as $known) {
            if ($host === $known || \str_ends_with($host, '.' . $known)) {
                return true;
            }
        }

        return false;
    }
}
