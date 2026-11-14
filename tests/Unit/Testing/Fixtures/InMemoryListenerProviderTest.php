<?php

declare(strict_types=1);

/*
 * This file is part of PSBits QA.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use PSBits\Qa\Testing\Fixtures\InMemoryListenerProvider;

final class InMemoryListenerProviderTest extends TestCase
{
    /**
     * @throws ExpectationFailedException
     */
    public function testNoListenersAreReturnedForAnUnknownEvent(): void
    {
        $provider = new InMemoryListenerProvider();

        self::assertSame([], iterator_to_array($provider->getListenersForEvent(new ProviderTestEvent())));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testTheListenerOfTheEventClassIsReturned(): void
    {
        $provider = new InMemoryListenerProvider();
        $listener = static fn(): string => 'listener';
        $provider->addListener(ProviderTestEvent::class, $listener);

        self::assertSame([$listener], iterator_to_array($provider->getListenersForEvent(new ProviderTestEvent())));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testTheListenersOfParentClassesAndInterfacesAreReturned(): void
    {
        $provider  = new InMemoryListenerProvider();
        $parent    = static fn(): string => 'parent';
        $interface = static fn(): string => 'interface';
        $provider->addListener(ProviderTestEvent::class, $parent);
        $provider->addListener(ProviderTestEventInterface::class, $interface);

        $listeners = iterator_to_array($provider->getListenersForEvent(new ProviderTestSubEvent()));

        self::assertSame([$parent, $interface], $listeners);
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
