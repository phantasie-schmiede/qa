<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PSBits\Qa\Testing\Fixtures\InMemoryListenerProvider;

final class InMemoryListenerProviderTest extends TestCase
{
    public function testTheListenerOfTheEventClassIsReturned(): void
    {
        $provider = new InMemoryListenerProvider();
        $listener = static fn (): string => 'listener';
        $provider->addListener(ProviderTestEvent::class, $listener);

        self::assertSame([$listener], iterator_to_array($provider->getListenersForEvent(new ProviderTestEvent())));
    }

    public function testTheListenersOfParentClassesAndInterfacesAreReturned(): void
    {
        $provider  = new InMemoryListenerProvider();
        $parent    = static fn (): string => 'parent';
        $interface = static fn (): string => 'interface';
        $provider->addListener(ProviderTestEvent::class, $parent);
        $provider->addListener(ProviderTestEventInterface::class, $interface);

        $listeners = iterator_to_array($provider->getListenersForEvent(new ProviderTestSubEvent()));

        self::assertSame([$parent, $interface], $listeners);
    }

    public function testNoListenersAreReturnedForAnUnknownEvent(): void
    {
        $provider = new InMemoryListenerProvider();

        self::assertSame([], iterator_to_array($provider->getListenersForEvent(new ProviderTestEvent())));
    }
}

class ProviderTestEvent implements ProviderTestEventInterface
{
}

final class ProviderTestSubEvent extends ProviderTestEvent
{
}

interface ProviderTestEventInterface
{
}
