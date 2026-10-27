<?php

declare(strict_types=1);

namespace PSBits\Qa\Testing\Support;

use Closure;
use PSBits\Qa\Testing\Fixtures\FakeLanguageServiceFactory;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Localization\Locale;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Trait LanguageStackSeeder
 *
 * Pre-seeds the language service factory singleton with a language service that serves caller-supplied
 * labels, which the core language stack cannot load in unit tests. Omit the labels to seed a language
 * service with the default mock responses. The consuming test case must set
 * `$resetSingletonInstances = true` so the seeded singletons do not leak between tests.
 */
trait LanguageStackSeeder
{
    /**
     * @param Closure(string): string|null $labels
     */
    protected function seedLanguageStack(?Closure $labels = null): void
    {
        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('getLocale')
            ->willReturn(new Locale('en'));

        if (null !== $labels) {
            $languageService->method('sL')
                ->willReturnCallback($labels);

            /*
             * On v14, the language service serves labels through `translate()`; the method does not exist on v13.
             */
            if (method_exists(LanguageService::class, 'translate')) {
                $languageService->method('translate')
                    ->willReturnCallback($labels);
            }
        }

        GeneralUtility::setSingletonInstance(
            LanguageServiceFactory::class,
            new FakeLanguageServiceFactory($languageService)
        );

        /*
         * The core keeps the language service in the runtime cache between lookups, which is not configured in unit
         * tests. A stubbed cache manager serves the pre-seeded language service instead.
         */
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')
            ->willReturn($languageService);

        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')
            ->willReturn($cache);

        GeneralUtility::setSingletonInstance(CacheManager::class, $cacheManager);
    }
}
