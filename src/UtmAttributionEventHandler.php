<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

/**
 * Listener that hands an attribution event to the service.
 *
 * The package does **not** subscribe it: wiring listeners is the application's
 * decision, and a core package that subscribes on its own would fire without a
 * repository bound.
 *
 * @api
 */
final readonly class UtmAttributionEventHandler
{
    public function __construct(
        private UtmAttributionService $service,
    ) {}

    public function __invoke(UtmAttributionEvent $event): void
    {
        $this->service->record($event);
    }
}
