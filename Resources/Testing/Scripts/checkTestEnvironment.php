<?php

declare(strict_types=1);

// Read-only checks: run before generating matrix files or changing dependencies.
$root         = $argv[1];
$database     = $argv[2];
$errors       = [];
$functional   = is_dir($root . '/Tests/Functional');
$composerFile = $root . '/composer.json';
$composer     = is_file($composerFile) ? json_decode((string) file_get_contents($composerFile), true) : null;

if (!is_array($composer)) {
    $errors[] = 'A readable, valid composer.json is required in ' . $root . '.';
}

if (!$functional && !is_dir($root . '/Tests/Unit')) {
    $errors[] = 'No Tests/Unit or Tests/Functional directory found; nothing to test.';
}

if (version_compare(PHP_VERSION, '8.3.0', '<')) {
    $errors[] = 'PHP >= 8.3 is required for the supported TYPO3 test matrix.';
}

// PHPUnit and Composer requirements, plus extensions declared by the repository.
$extensions = ['dom', 'json', 'libxml', 'mbstring', 'phar', 'tokenizer', 'xml', 'xmlwriter'];

foreach (['require', 'require-dev'] as $section) {
    foreach (array_keys($composer[$section] ?? []) as $package) {
        if (str_starts_with($package, 'ext-')) {
            $extensions[] = substr($package, 4);
        }
    }
}

if ($functional) {
    $extensions = array_merge($extensions, ['fileinfo', 'intl', 'session', 'simplexml', 'sodium']);
    $driver     = match ($database) {
        'mysql'    => 'pdo_mysql',
        'postgres' => 'pdo_pgsql',
        default    => 'pdo_sqlite',
    };
    $extensions[] = 'pdo';
    $extensions[] = $driver;
}

foreach (array_unique($extensions) as $extension) {
    if (!extension_loaded($extension)) {
        $errors[] = 'Missing PHP extension: ' . $extension . ' (enable it for ' . PHP_BINARY . ').';
    }
}

if ($functional && extension_loaded($driver)) {
    try {
        if ('sqlite' === $database) {
            new PDO('sqlite::memory:');
        } else {
            $host     = getenv('typo3DatabaseHost') ?: '127.0.0.1';
            $port     = getenv('typo3DatabasePort') ?: ('mysql' === $database ? '3306' : '5432');
            $user     = getenv('typo3DatabaseUsername') ?: ('mysql' === $database ? 'root' : 'postgres');
            $password = getenv('typo3DatabasePassword');

            if (false === $password) {
                $password = 'mysql' === $database ? '' : 'postgres';
            }
            // Connect to the server, not the test database: the framework creates it.
            $dsn = 'mysql' === $database
                ? sprintf('mysql:host=%s;port=%s', $host, $port)
                : sprintf('pgsql:host=%s;port=%s;dbname=postgres;connect_timeout=5', $host, $port);
            $connection = new PDO($dsn, $user, $password, [PDO::ATTR_TIMEOUT => 5]);

            if ('postgres' === $database) {
                $canCreate = $connection->query('SELECT rolcreatedb OR rolsuper FROM pg_roles WHERE rolname = current_user')->fetchColumn();

                if (!in_array($canCreate, [true, 1, '1', 't'], true)) {
                    $errors[] = 'PostgreSQL user needs CREATEDB permission for functional test databases.';
                }
            }
        }
    } catch (PDOException $exception) {
        $errors[] = 'Cannot connect to ' . $database . ': ' . $exception->getMessage();
    }
}

if ([] !== $errors) {
    fwrite(STDERR, "Test preparation failed; no test run started.\nPHP: " . PHP_BINARY . ' (' . PHP_VERSION . ")\n");

    foreach ($errors as $error) {
        fwrite(STDERR, '  - ' . $error . "\n");
    }

    fwrite(STDERR, "Inspect CLI extensions with php -m and php --ini.\n");

    if ($functional && 'sqlite' === $database && !extension_loaded('pdo_sqlite')) {
        fwrite(STDERR, 'Debian/Ubuntu: typically install/enable php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . "-sqlite3; verify with php --ri pdo_sqlite.\n");
    }

    if ($functional && 'sqlite' !== $database) {
        fwrite(STDERR, "Configure typo3DatabaseHost/Port/Username/Password; the user must be allowed to create and drop test databases.\n");
    }

    fwrite(STDERR, "Alternative: composer docker:images, then composer test:docker.\n");

    exit(1);
}

echo 'Test preparation OK — PHP ' . PHP_VERSION . ($functional ? ' / functional database: ' . $database : ' / unit-only (no database required)') . ". Dependency resolution needs Composer repository access.\n";
