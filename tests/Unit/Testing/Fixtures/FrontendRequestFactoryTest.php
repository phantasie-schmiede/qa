<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PSBits\Qa\Testing\Fixtures\FrontendRequestFactory;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScript;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request;

final class FrontendRequestFactoryTest extends TestCase
{
    public function testCreateFrontendRequestBuildsARequestWithTheFrameworkConfiguration(): void
    {
        $request = FrontendRequestFactory::createFrontendRequest('tx_testextension');

        self::assertSame(SystemEnvironmentBuilder::REQUESTTYPE_FE, $request->getAttribute('applicationType'));

        $language = $request->getAttribute('language');
        self::assertInstanceOf(SiteLanguage::class, $language);
        self::assertSame('en', $language->getLocale()->getLanguageCode());

        $typoscript = $request->getAttribute('frontend.typoscript');
        self::assertInstanceOf(FrontendTypoScript::class, $typoscript);
        self::assertSame([
            'plugin.' => [
                'tx_testextension.' => [
                    'view.' => [],
                ],
            ],
        ], $typoscript->getSetupArray());
        self::assertSame([], $typoscript->getConfigArray());
    }

    public function testTheViewSetupAndAdditionalAttributesAreProvided(): void
    {
        $request = FrontendRequestFactory::createFrontendRequest(
            'tx_testextension',
            ['partialRootPaths.' => ['10' => 'EXT:foo/Resources/Private/Partials/']],
            ['site' => 'site-instance'],
        );

        $typoscript = $request->getAttribute('frontend.typoscript');
        self::assertSame([
            'plugin.' => [
                'tx_testextension.' => [
                    'view.' => [
                        'partialRootPaths.' => ['10' => 'EXT:foo/Resources/Private/Partials/'],
                    ],
                ],
            ],
        ], $typoscript->getSetupArray());
        self::assertSame('site-instance', $request->getAttribute('site'));
    }

    public function testCreateExtbaseRequestWrapsTheFrontendRequest(): void
    {
        $request = FrontendRequestFactory::createExtbaseRequest(
            'PSBits\\Test\\Controller\\TestController',
            'TestPlugin',
            'show',
            'tx_testextension',
        );

        self::assertInstanceOf(Request::class, $request);

        $parameters = $request->getAttribute('extbase');
        self::assertInstanceOf(ExtbaseRequestParameters::class, $parameters);
        self::assertSame('PSBits\Test\Controller\TestController', $parameters->getControllerObjectName());
        self::assertSame('TestPlugin', $parameters->getPluginName());
        self::assertSame('show', $parameters->getControllerActionName());

        self::assertSame(SystemEnvironmentBuilder::REQUESTTYPE_FE, $request->getAttribute('applicationType'));
    }
}
