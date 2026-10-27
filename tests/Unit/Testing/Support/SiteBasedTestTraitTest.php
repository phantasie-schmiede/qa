<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PSBits\Qa\Testing\Support\SiteBasedTestTrait;
use ReflectionMethod;

final class SiteBasedTestTraitTest extends TestCase
{
    private function createCarrier(): object
    {
        return new class {
            use SiteBasedTestTrait;

            public function build(int $rootPageId, string $base = ''): array
            {
                return $this->buildSiteConfiguration($rootPageId, $base);
            }

            public function dump(array $data): string
            {
                $method = new ReflectionMethod($this, 'dumpYaml');

                return $method->invoke($this, $data);
            }
        };
    }

    public function testBuildSiteConfigurationReturnsTheSiteMap(): void
    {
        self::assertSame(
            ['rootPageId' => 42, 'base' => '/'],
            $this->createCarrier()->build(42, '/')
        );
    }

    public function testDumpYamlRendersScalars(): void
    {
        $yaml = $this->createCarrier()->dump([
            'rootPageId' => 1,
            'base'       => '/',
            'enabled'    => true,
            'disabled'   => false,
            'missing'    => null,
            'ratio'      => 1.5,
            'label'      => "it's",
        ]);

        self::assertSame(
            "rootPageId: 1\n"
            . "base: '/'\n"
            . "enabled: true\n"
            . "disabled: false\n"
            . "missing: null\n"
            . "ratio: 1.5\n"
            . "label: 'it''s'\n",
            $yaml
        );
    }

    public function testDumpYamlRendersNestedMapsAndLists(): void
    {
        $yaml = $this->createCarrier()->dump([
            'rootPageId' => 1,
            'languages'  => [
                [
                    'languageId' => 1,
                    'label'      => 'German',
                ],
            ],
            'empty'      => [],
        ]);

        self::assertSame(
            "rootPageId: 1\n"
            . "languages:\n"
            . "  -\n"
            . "    languageId: 1\n"
            . "    label: 'German'\n"
            . "empty: {}\n",
            $yaml
        );
    }

    public function testDumpYamlRendersAnEmptyMap(): void
    {
        self::assertSame("{}\n", $this->createCarrier()->dump([]));
    }
}
