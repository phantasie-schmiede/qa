<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PSBits\Qa\Testing\Fixtures\FakeLanguageServiceFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\Locale;

final class FakeLanguageServiceFactoryTest extends TestCase
{
    public function testCreateReturnsTheProvidedLanguageService(): void
    {
        $languageService = $this->createMock(LanguageService::class);
        $factory         = new FakeLanguageServiceFactory($languageService);

        self::assertSame($languageService, $factory->create(new Locale('en')));
    }

    public function testCreateFromUserPreferencesReturnsTheProvidedLanguageService(): void
    {
        $languageService = $this->createMock(LanguageService::class);
        $factory         = new FakeLanguageServiceFactory($languageService);

        self::assertSame($languageService, $factory->createFromUserPreferences(null));
    }
}
