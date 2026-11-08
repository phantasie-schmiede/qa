<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use PSBits\Qa\Testing\Support\SiteBasedTestTrait;

final class SiteBasedTestTraitTest extends TestCase
{
    /**
     * @throws ExpectationFailedException
     */
    public function testBuildSiteConfigurationReturnsTheSiteMap(): void
    {
        self::assertSame(
            ['base' => '/', 'rootPageId' => 42],
            $this->createCarrier()->build(42, '/')
        );
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testDumpYamlRendersAnEmptyMap(): void
    {
        self::assertSame("{}\n", $this->createCarrier()->dump([]));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testDumpYamlRendersNestedMapsAndLists(): void
    {
        $yaml = $this->createCarrier()->dump([
            'empty'      => [],
            'languages'  => [
                [
                    'label'      => 'German',
                    'languageId' => 1,
                ],
            ],
            'rootPageId' => 1,
        ]);

        self::assertSame(
            "empty: {}\n"
            . "languages:\n"
            . "  -\n"
            . "    label: 'German'\n"
            . "    languageId: 1\n"
            . "rootPageId: 1\n",
            $yaml
        );
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testDumpYamlRendersScalars(): void
    {
        $yaml = $this->createCarrier()->dump([
            'base'       => '/',
            'disabled'   => false,
            'enabled'    => true,
            'label'      => "it's",
            'missing'    => null,
            'ratio'      => 1.5,
            'rootPageId' => 1,
        ]);

        self::assertSame(
            "base: '/'\n"
            . "disabled: false\n"
            . "enabled: true\n"
            . "label: 'it''s'\n"
            . "missing: null\n"
            . "ratio: 1.5\n"
            . "rootPageId: 1\n",
            $yaml
        );
    }

    private function createCarrier(): object
    {
        return new class () {
            use SiteBasedTestTrait;

            /**
             * @return array{base: string, rootPageId: int}
             */
            public function build(int $rootPageId, string $base = ''): array
            {
                return $this->buildSiteConfiguration($rootPageId, $base);
            }

            /**
             * @param array<array-key, mixed> $data
             * @throws ReflectionException
             */
            public function dump(array $data): string
            {
                $method = new ReflectionMethod($this, 'dumpYaml');

                return $method->invoke($this, $data);
            }
        };
    }
}
