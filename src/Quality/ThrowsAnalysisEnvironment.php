<?php

declare(strict_types=1);

/*
 * This file is part of PSBits QA.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Qa\Quality;

use Composer\InstalledVersions;
use FilesystemIterator;
use RuntimeException;
use UnexpectedValueException;

use function array_merge;
use function array_values;
use function chmod;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function in_array;
use function is_array;
use function is_dir;
use function json_decode;
use function json_encode;
use function mkdir;
use function rmdir;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function unlink;

/**
 * A disposable repository copy with its own Composer installation. Source
 * files are copied, never linked, so dependency installation and bootstraps
 * cannot write back to the development installation through our links.
 */
final class ThrowsAnalysisEnvironment
{
    /** @var array<string, string> repository-relative path => original real path */
    private array $files = [];

    public function __construct(
        private readonly string $repoRoot,
        public readonly string  $root,
    ) {
    }

    /**
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public static function remove(string $directory): void
    {
        if (is_link($directory) || !is_dir($directory)) {
            if (file_exists($directory) || is_link($directory)) {
                if (!unlink($directory)) {
                    throw new RuntimeException('could not remove ' . $directory);
                }
            }

            return;
        }

        foreach (new FilesystemIterator($directory) as $item) {
            self::remove($item->getPathname());
        }

        if (!rmdir($directory)) {
            throw new RuntimeException('could not remove ' . $directory);
        }
    }

    /**
     * Translate only files that were copied from this repository. Dependency
     * files, traversal paths and replacement symlinks are never write targets.
     *
     * @throws RuntimeException
     */
    public function originalFile(string $file): string
    {
        $absolute = str_starts_with($file, '/') ? $file : $this->root . '/' . $file;
        $resolved = realpath($absolute);

        if (false === $resolved || !str_starts_with($resolved, $this->root . '/')) {
            throw new RuntimeException('analysis reported a file outside the repository copy: ' . $file);
        }

        $relative = substr($resolved, strlen($this->root) + 1);
        $original = $this->files[$relative] ?? null;

        if (null === $original || realpath($original) !== $original || is_link($absolute)) {
            throw new RuntimeException('analysis reported a file not copied from the repository: ' . $file);
        }

        return $original;
    }

    /**
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function prepare(string $core, string $tooling): void
    {
        $this->copyTree($this->repoRoot, $this->root, true);
        $data = json_decode((string) file_get_contents($this->repoRoot . '/composer.json'), true);

        if (!is_array($data)) {
            throw new RuntimeException('invalid composer.json');
        }

        $data['require']['typo3/cms-core']     = '^' . $core;
        $data['require-dev']['psbits/qa']      = '*@dev';
        $data['config']['vendor-dir']          = '.Build/vendor';
        $data['config']['bin-dir']             = '.Build/bin';
        $data['extra']['typo3/cms']['web-dir'] = '.Build/public';
        unset($data['scripts']);

        foreach (['autoload', 'autoload-dev'] as $section) {
            foreach (['psr-4', 'psr-0', 'classmap', 'files', 'exclude-from-classmap'] as $kind) {
                foreach ($data[$section][$kind] ?? [] as $key => $paths) {
                    $mapped = [];

                    foreach ((array) $paths as $path) {
                        if (str_starts_with($path, $this->repoRoot . '/')) {
                            $path = substr($path, strlen($this->repoRoot) + 1);
                        }

                        if (str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
                            throw new RuntimeException('autoload path outside the repository is not supported: ' . $path);
                        }

                        $mapped[] = $path;
                    }

                    $data[$section][$kind][$key] = is_array($paths) ? $mapped : $mapped[0];
                }
            }
        }

        /*
         * Path repositories retain their original meaning, but Composer must
         * mirror them rather than linking to a developer's working directory.
         */
        $repositories = $data['repositories'] ?? [];

        foreach ($repositories as &$repository) {
            if (!is_array($repository)) {
                continue;
            }

            if ('path' === ($repository['type'] ?? null)) {
                $url                              = $repository['url'];
                $repository['url']                = str_starts_with($url, '/') ? $url : $this->repoRoot . '/' . $url;
                $repository['options']['symlink'] = false;
            }
        }

        unset($repository);

        $version              = InstalledVersions::isInstalled('psbits/qa') ? InstalledVersions::getPrettyVersion('psbits/qa') : 'dev-main';
        $data['repositories'] = array_merge([
            ['type' => 'path', 'url' => $tooling, 'options' => ['symlink' => false, 'versions' => ['psbits/qa' => $version ?? 'dev-main']]],
        ], array_values($repositories));
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (false === $encoded) {
            throw new RuntimeException('could not encode isolated composer.json');
        }

        $this->write($this->root . '/composer.json', $encoded . "\n");
    }

    /** @throws RuntimeException */
    public function refreshSources(): void
    {
        foreach ($this->files as $relative => $original) {
            // Composer owns the isolated manifest, not the source snapshot.
            if ('composer.json' === $relative) {
                continue;
            }

            if (realpath($original) !== $original || is_link($original)) {
                throw new RuntimeException('source file changed its location: ' . $original);
            }

            $destination = $this->root . '/' . $relative;

            if (realpath($destination) !== $destination || is_link($destination)) {
                throw new RuntimeException('copied source file changed its location: ' . $destination);
            }

            $content = file_get_contents($original);

            if (false === $content) {
                throw new RuntimeException('could not read ' . $original);
            }

            /*
             * Absolute references into this repository in NEON configs must
             * refer to the isolated installation, too. Relative includes work
             * unchanged because the directory structure is preserved.
             */
            if (str_ends_with($relative, '.neon') || str_ends_with($relative, '.neon.dist')) {
                $content = str_replace($this->repoRoot . '/', $this->root . '/', $content);
            }

            if (file_get_contents($destination) !== $content) {
                $this->write($destination, $content);
            }
        }
    }

    /**
     * Copy the current QA code as well, excluding its installed dependencies.
     *
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function snapshotTooling(string $source, string $destination): void
    {
        $this->copyTree($source, $destination, false);
    }

    /**
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    private function copyTree(string $source, string $destination, bool $track, string $relative = ''): void
    {
        if (!is_dir($destination) && !mkdir($destination, 0755, true)) {
            throw new RuntimeException('could not create ' . $destination);
        }

        foreach (new FilesystemIterator($source) as $item) {
            $name = $item->getFilename();

            if (in_array($name, ['.git', '.Build', '.opencode', '.phpstan', '_assets', 'vendor', 'node_modules', 'var', 'composer.lock', 'composer-matrix.json', 'composer-matrix.lock'], true)) {
                continue;
            }

            $path = '' === $relative ? $name : $relative . '/' . $name;

            if ($item->isLink()) {
                throw new RuntimeException('source symlinks are not supported in isolated analysis: ' . $item->getPathname());
            }

            if ($item->isDir()) {
                $this->copyTree($item->getPathname(), $destination . '/' . $name, $track, $path);
            } elseif ($item->isFile()) {
                if (!copy($item->getPathname(), $destination . '/' . $name) || !chmod($destination . '/' . $name, $item->getPerms() & 0777)) {
                    throw new RuntimeException('could not copy ' . $item->getPathname());
                }

                if ($track) {
                    $this->files[$path] = $item->getPathname();
                }
            }
        }
    }

    /** @throws RuntimeException */
    private function write(string $file, string $content): void
    {
        if (false === file_put_contents($file, $content)) {
            throw new RuntimeException('could not write ' . $file);
        }
    }
}
