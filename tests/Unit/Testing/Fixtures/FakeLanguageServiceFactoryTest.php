<?php

declare(strict_types=1);

use PHPUnit\Event\NoPreviousThrowableException;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\InvalidArgumentException;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use PSBits\Qa\Testing\Fixtures\FakeLanguageServiceFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\Locale;

final class FakeLanguageServiceFactoryTest extends TestCase
{
    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws InvalidArgumentException
     * @throws NoPreviousThrowableException
     */
    public function testCreateFromUserPreferencesReturnsTheProvidedLanguageService(): void
    {
        $languageService = $this->createMock(LanguageService::class);
        $factory         = new FakeLanguageServiceFactory($languageService);

        self::assertSame($languageService, $factory->createFromUserPreferences(null));
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws InvalidArgumentException
     * @throws NoPreviousThrowableException
     */
    public function testCreateReturnsTheProvidedLanguageService(): void
    {
        $languageService = $this->createMock(LanguageService::class);
        $factory         = new FakeLanguageServiceFactory($languageService);

        self::assertSame($languageService, $factory->create(new Locale('en')));
    }
}
