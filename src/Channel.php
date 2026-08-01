<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

/**
 * Marketing channel a touchpoint is classified into.
 *
 * Derived on read by a {@see ChannelResolver} and deliberately not stored:
 * classification rules change far more often than a major release allows.
 *
 * @psalm-immutable
 *
 * @api
 */
enum Channel: string
{
    case Paid = 'paid';
    case Organic = 'organic';
    case Social = 'social';
    case Email = 'email';
    case Referral = 'referral';
    case Direct = 'direct';
    case Other = 'other';
}
