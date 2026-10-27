<?php

declare(strict_types=1);

namespace PSBits\Qa\Testing\Fixtures;

use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Class InMemoryListenerProvider
 *
 * PSR-14 listener provider with listeners registered at runtime, for use with the core EventDispatcher in
 * unit and functional tests.
 */
class InMemoryListenerProvider implements ListenerProviderInterface
{
    /** @var array<class-string, list<callable>> */
    private array $listeners = [];

    public function addListener(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function getListenersForEvent(object $event): iterable
    {
        $eventClasses = array_merge(
            [get_class($event)],
            array_values(class_parents($event)),
            array_values(class_implements($event))
        );

        foreach ($eventClasses as $eventClass) {
            foreach ($this->listeners[$eventClass] ?? [] as $listener) {
                yield $listener;
            }
        }
    }
}
