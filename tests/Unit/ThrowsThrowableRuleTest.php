<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use PSBits\Qa\PhpStan\ThrowsThrowableRule;

final class ThrowsThrowableRuleTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            unlink($file);
        }
    }

    /**
     * @throws ExpectationFailedException
     * @throws JsonException
     */
    public function testMajorFromInstalledJsonResolvesTheCoreMajor(): void
    {
        $file = $this->writeInstalledJson((string) json_encode(['packages' => [
            ['name' => 'some/other', 'version' => 'v1.2.3'],
            ['name' => 'typo3/cms-core', 'version' => 'v14.3.10'],
        ]], JSON_THROW_ON_ERROR));

        self::assertSame(14, ThrowsThrowableRule::majorFromInstalledJson($file));

        $file = $this->writeInstalledJson((string) json_encode(['packages' => [
            ['name' => 'typo3/cms-core', 'version' => 'v13.4.37'],
        ]], JSON_THROW_ON_ERROR));

        self::assertSame(13, ThrowsThrowableRule::majorFromInstalledJson($file));
    }

    /**
     * @throws ExpectationFailedException
     * @throws JsonException
     */
    public function testMajorFromInstalledJsonWithoutTheCorePackage(): void
    {
        $payloads = [
            (string) json_encode(['packages' => [
                ['name' => 'some/other', 'version' => 'v14.3.10'],
            ]], JSON_THROW_ON_ERROR),
            (string) json_encode(['packages' => []], JSON_THROW_ON_ERROR),
            '{}',
            (string) json_encode(['packages' => [
                ['name' => 'typo3/cms-core', 'version' => 'dev-main'],
            ]], JSON_THROW_ON_ERROR),
            (string) json_encode(['packages' => [
                ['name' => 'typo3/cms-core', 'version' => ''],
            ]], JSON_THROW_ON_ERROR),
            '{ invalid json',
        ];

        foreach ($payloads as $payload) {
            $file = $this->writeInstalledJson($payload);

            self::assertNull(ThrowsThrowableRule::majorFromInstalledJson($file), $payload);
        }

        self::assertNull(ThrowsThrowableRule::majorFromInstalledJson(sys_get_temp_dir() . '/psbits-qa-not-installed.json'));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testMarkerOf(): void
    {
        self::assertSame(14, ThrowsThrowableRule::markerOf(' // t3:14'));
        self::assertSame(13, ThrowsThrowableRule::markerOf(' // t3:13'));
        self::assertSame(14, ThrowsThrowableRule::markerOf('   //  t3:14'));
        self::assertNull(ThrowsThrowableRule::markerOf(''));
        self::assertNull(ThrowsThrowableRule::markerOf(' the resource cannot be resolved'));
        self::assertNull(ThrowsThrowableRule::markerOf(' // t3:14: detail'));
        self::assertNull(ThrowsThrowableRule::markerOf(' // t3:99'));
        self::assertNull(ThrowsThrowableRule::markerOf(' // t3:'));
        self::assertNull(ThrowsThrowableRule::markerOf(' // t3:14 and more'));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testReportForTagPassesThrowableTypes(): void
    {
        self::assertNull(ThrowsThrowableRule::reportForTag(13, null, true, []));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testReportForTagReportsMarkersForTheInstalledMajor(): void
    {
        self::assertSame(
            'is not available on core major 13, where the line carries the marker',
            ThrowsThrowableRule::reportForTag(
                13,
                13,
                false,
                ['Missing\Exception']
            )
        );

        self::assertSame(
            'is not available on core major 14, where the line carries the marker',
            ThrowsThrowableRule::reportForTag(
                14,
                14,
                false,
                ['Missing\Exception']
            )
        );
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testReportForTagReportsResolvableNonThrowables(): void
    {
        /*
         * With no class the installed core does not provide, the tag type
         * resolves to a non-Throwable (or carries no class at all).
         */
        self::assertSame('is not a Throwable subtype', ThrowsThrowableRule::reportForTag(13, null, false, []));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testReportForTagReportsUnmarkedMissingClasses(): void
    {
        self::assertSame(
            'is not available on the installed core; mark the line for the core major that throws it',
            ThrowsThrowableRule::reportForTag(
                13,
                null,
                false,
                ['Missing\Exception']
            )
        );

        self::assertSame(
            'is not available on the installed core; mark the line for the core major that throws it',
            ThrowsThrowableRule::reportForTag(
                14,
                null,
                false,
                ['Missing\Exception']
            )
        );
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testReportForTagToleratesForeignMajorMarkers(): void
    {
        self::assertNull(ThrowsThrowableRule::reportForTag(
            13,
            14,
            false,
            ['Missing\Exception']
        ));

        self::assertNull(ThrowsThrowableRule::reportForTag(
            14,
            13,
            false,
            ['Missing\Exception']
        ));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testReportForTagWithoutAnInstalledCore(): void
    {
        /*
         * Without a determinable installed core every marker is tolerated,
         * but an unmarked missing class is still reported.
         */
        self::assertNull(ThrowsThrowableRule::reportForTag(
            null,
            13,
            false,
            ['Missing\Exception']
        ));

        self::assertNull(ThrowsThrowableRule::reportForTag(
            null,
            14,
            false,
            ['Missing\Exception']
        ));

        self::assertSame(
            'is not available on the installed core; mark the line for the core major that throws it',
            ThrowsThrowableRule::reportForTag(
                null,
                null,
                false,
                ['Missing\Exception']
            )
        );
    }

    /**
     * @throws ExpectationFailedException
     */
    private function writeInstalledJson(string $payload): string
    {
        $file              = (string) tempnam(sys_get_temp_dir(), 'psbits-qa-throws-throwable-');
        $this->tempFiles[] = $file;

        self::assertTrue(false !== file_put_contents($file, $payload));

        return $file;
    }
}
