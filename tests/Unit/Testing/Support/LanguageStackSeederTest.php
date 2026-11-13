<?php

declare(strict_types=1);

use PHPUnit\Event\NoPreviousThrowableException;
use PHPUnit\Framework\Exception;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\InvalidArgumentException;
use PSBits\Qa\Testing\Fixtures\FakeLanguageServiceFactory;
use PSBits\Qa\Testing\Support\LanguageStackSeeder;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Localization\Locale;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class LanguageStackSeederTest extends UnitTestCase
{
    use LanguageStackSeeder;

    protected bool $resetSingletonInstances = true;

    /**
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws NoPreviousThrowableException
     * @throws NoSuchCacheException
     * @throws PHPUnit\Framework\MockObject\Exception
     */
    public function testTheCacheManagerSingletonServesThePreSeededService(): void
    {
        $this->seedLanguageStack();

        $cacheManager = GeneralUtility::getSingletonInstances()[CacheManager::class] ?? null;
        self::assertInstanceOf(CacheManager::class, $cacheManager);
        self::assertInstanceOf(FrontendInterface::class, $cacheManager->getCache('core'));
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws InvalidArgumentException
     * @throws NoPreviousThrowableException
     * @throws PHPUnit\Framework\MockObject\Exception
     */
    public function testTheLanguageServiceFactorySingletonServesTheLabelledService(): void
    {
        $this->seedLanguageStack(static fn(string $key): string => 'Label ' . $key);

        $factory = GeneralUtility::getSingletonInstances()[LanguageServiceFactory::class] ?? null;
        self::assertInstanceOf(FakeLanguageServiceFactory::class, $factory);

        $languageService = $factory->create(new Locale('en'));
        $label           = method_exists(LanguageService::class, 'translate')
            ? $languageService->translate('key', 'test-domain')
            : $languageService->sL('key');
        self::assertSame('Label key', $label);
    }
}
