<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use PSBits\Qa\Quality\ThrowsAnalysisEnvironment;
use Random\RandomException;

final class ThrowsAnalysisEnvironmentTest extends TestCase
{
    private string $root;

    /** @throws RandomException */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qa-analysis-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/original/Classes', 0755, true);
        mkdir($this->root . '/original/Build/Quality', 0755, true);
        file_put_contents($this->root . '/original/composer.json', '{}');
        file_put_contents($this->root . '/original/Classes/Service.php', '<?php // original');
        file_put_contents($this->root . '/original/Build/Quality/phpstan.neon.dist', 'includes: [' . $this->root . '/original/.Build/vendor/tool.neon]');
    }

    /** @throws RuntimeException */
    protected function tearDown(): void
    {
        ThrowsAnalysisEnvironment::remove($this->root);
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    public function testCopiesAreIndependentAndRefreshPreservesPaths(): void
    {
        $environment = new ThrowsAnalysisEnvironment($this->root . '/original', $this->root . '/copy');
        $environment->prepare('14.3', $this->root . '/tooling');
        $environment->refreshSources();
        self::assertSame($this->root . '/original/Classes/Service.php', $environment->originalFile('Classes/Service.php'));
        self::assertStringContainsString($this->root . '/copy/.Build/vendor', file_get_contents($this->root . '/copy/Build/Quality/phpstan.neon.dist'));
        file_put_contents($this->root . '/copy/Classes/Service.php', '<?php // isolated change');
        self::assertSame('<?php // original', file_get_contents($this->root . '/original/Classes/Service.php'));
        file_put_contents($this->root . '/original/Classes/Service.php', '<?php // new docblock');
        $environment->refreshSources();
        self::assertSame('<?php // new docblock', file_get_contents($this->root . '/copy/Classes/Service.php'));
        self::assertSame('^14.3', json_decode(file_get_contents($this->root . '/copy/composer.json'), true)['require']['typo3/cms-core']);
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    public function testRejectsDependencyAndExternalPaths(): void
    {
        $environment = new ThrowsAnalysisEnvironment($this->root . '/original', $this->root . '/copy');
        $environment->prepare('14.3', $this->root . '/tooling');
        mkdir($this->root . '/copy/.Build/vendor', 0755, true);
        file_put_contents($this->root . '/copy/.Build/vendor/Dependency.php', '<?php');

        foreach (['.Build/vendor/Dependency.php', '../original/Classes/Service.php', $this->root . '/original/Classes/Service.php'] as $path) {
            try {
                $environment->originalFile($path);
                self::fail('expected rejection: ' . $path);
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('analysis reported a file', $exception->getMessage());
            }
        }
    }

    /** @throws RuntimeException */
    public function testRejectsReplacedSourceSymlink(): void
    {
        $environment = new ThrowsAnalysisEnvironment($this->root . '/original', $this->root . '/copy');
        $environment->prepare('14.3', $this->root . '/tooling');
        unlink($this->root . '/copy/Classes/Service.php');
        symlink($this->root . '/original/Classes/Service.php', $this->root . '/copy/Classes/Service.php');
        $this->expectException(RuntimeException::class);
        $environment->originalFile('Classes/Service.php');
    }
}
