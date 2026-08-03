<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Adapts an application consent callback — or a policy of another package — to
 * {@see ConsentPolicy}.
 *
 * @api
 */
final readonly class CallbackConsentPolicy implements ConsentPolicy
{
    /**
     * @var \Closure(ServerRequestInterface): bool
     */
    private \Closure $callback;

    /**
     * @param callable(ServerRequestInterface): bool $callback
     */
    public function __construct(callable $callback)
    {
        $this->callback = \Closure::fromCallable($callback);
    }

    #[\Override]
    public function allowsPersistence(ServerRequestInterface $request): bool
    {
        return ($this->callback)($request);
    }
}
