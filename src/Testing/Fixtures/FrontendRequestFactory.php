<?php

declare(strict_types=1);

namespace PSBits\Qa\Testing\Fixtures;

use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\TypoScript\AST\Node\RootNode;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScript;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request;

/**
 * Class FrontendRequestFactory
 *
 * Creates frontend requests with an initialized FrontendTypoScript, as the frontend request middlewares would.
 * The framework configuration of the extension under test is provided under its plugin key, and the extbase
 * request factory builds the request of a given controller action on top of it.
 */
final class FrontendRequestFactory
{
    /**
     * @param array<string, mixed> $viewSetup
     * @param array<string, mixed> $requestAttributes
     */
    public static function createFrontendRequest(
        string $pluginKey,
        array  $viewSetup = [],
        array  $requestAttributes = [],
    ): ServerRequest {
        $frontendTypoScript = new FrontendTypoScript(new RootNode(), [], [], []);
        $frontendTypoScript->setSetupArray([
            'plugin.' => [
                $pluginKey . '.' => [
                    'view.' => $viewSetup,
                ],
            ],
        ]);

        // The "config." section is always initialized by the frontend middlewares; link building consults it.
        $frontendTypoScript->setConfigArray([]);

        $request = (new ServerRequest(
            'http://example.com/',
            'GET',
            null,
            [],
            [
                'HTTP_HOST'   => 'example.com',
                'REQUEST_URI' => '/',
            ]
        ))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
            ->withAttribute('language', new SiteLanguage(0, 'en', new Uri('/'), ['title' => 'Default language']))
            ->withAttribute('frontend.typoscript', $frontendTypoScript);

        foreach ($requestAttributes as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $viewSetup
     * @param array<string, mixed> $requestAttributes
     */
    public static function createExtbaseRequest(
        string $controllerClass,
        string $pluginName,
        string $action,
        string $pluginKey,
        array  $viewSetup = [],
        array  $requestAttributes = [],
    ): Request {
        $extbaseRequestParameters = (new ExtbaseRequestParameters($controllerClass))
            ->setPluginName($pluginName)
            ->setControllerActionName($action);

        $request = self::createFrontendRequest($pluginKey, $viewSetup, $requestAttributes)
            ->withAttribute('extbase', $extbaseRequestParameters);

        return new Request($request);
    }
}
