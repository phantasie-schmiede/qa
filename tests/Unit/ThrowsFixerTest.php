<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use PSBits\Qa\PhpStan\ThrowsDocblockRule;
use PSBits\Qa\Quality\ThrowsFixer;

final class ThrowsFixerTest extends TestCase
{
    /**
     * @throws ExpectationFailedException
     */
    public function testCollectEntriesKeepsOnlyRuleMessages(): void
    {
        $data = [
            'files'  => [
                'src/App/Functions.php' => [
                    'messages' => [
                        [
                            'identifier' => ThrowsDocblockRule::IDENTIFIER,
                            'line'       => 3,
                            'message'    => 'Function App\helper() @throws do not match the thrown exceptions: expected none, declared \RuntimeException.',
                        ],
                    ],
                ],
                'src/App/Service.php'   => [
                    'messages' => [
                        [
                            'identifier' => ThrowsDocblockRule::IDENTIFIER,
                            'line'       => 12,
                            'message'    => 'Method App\Service::run() @throws do not match the thrown exceptions: expected \App\Error, declared none.',
                        ],
                        [
                            'identifier' => 'phpdoc.throws',
                            'line'       => 20,
                            'message'    => 'must not be reported',
                        ],
                    ],
                ],
            ],
            'totals' => ['errors' => 0],
        ];

        self::assertSame([
            [
                'file'    => 'src/App/Functions.php',
                'line'    => 3,
                'message' => 'Function App\helper() @throws do not match the thrown exceptions: expected none, declared \RuntimeException.',
            ],
            [
                'file'    => 'src/App/Service.php',
                'line'    => 12,
                'message' => 'Method App\Service::run() @throws do not match the thrown exceptions: expected \App\Error, declared none.',
            ],
        ], ThrowsFixer::collectEntries($data));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testCollectEntriesReturnsEmptyListForForeignOutput(): void
    {
        self::assertSame([], ThrowsFixer::collectEntries([
            'files'  => [
                'src/App/Service.php' => [
                    'messages' => [
                        [
                            'identifier' => 'missingType.missingReturnType',
                            'line'       => 7,
                            'message'    => 'Method App\Service::run() should return specifying the exact returned type.',
                        ],
                    ],
                ],
            ],
            'totals' => ['errors' => 0],
        ]));
        self::assertSame([], ThrowsFixer::collectEntries([]));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testCollectEntriesStripsTraitContextFromFileKey(): void
    {
        $data = [
            'files'  => [
                'src/App/Traits/Filler.php (in context of class App\Consumer\Element)' => [
                    'messages' => [
                        [
                            'identifier' => ThrowsDocblockRule::IDENTIFIER,
                            'line'       => 10,
                            'message'    => 'Method App\Consumer\Element::fillProperties() @throws do not match the thrown exceptions: expected \InvalidArgumentException, declared \ReflectionException.',
                        ],
                    ],
                ],
            ],
            'totals' => ['errors' => 0],
        ];

        self::assertSame([
            [
                'file'    => 'src/App/Traits/Filler.php',
                'line'    => 10,
                'message' => 'Method App\Consumer\Element::fillProperties() @throws do not match the thrown exceptions: expected \InvalidArgumentException, declared \ReflectionException.',
            ],
        ], ThrowsFixer::collectEntries($data));
    }
}
