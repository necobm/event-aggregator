<?php declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

// Force APP_ENV=test *before* Dotenv resolves env files, so that it beats the
// APP_ENV=dev that the Dockerfile bakes into the container image via `ENV`.
putenv('APP_ENV=test');
$_SERVER['APP_ENV'] = 'test';
$_ENV['APP_ENV'] = 'test';

require dirname(__DIR__) . '/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

// Recreate the test schema before DAMA opens its long-lived transaction. Using
// the Symfony console is the cleanest way to ensure the DDL runs in its own
// connection and is committed before tests begin (Postgres DDL is
// transactional, so running it inside the DAMA transaction would roll back).
$projectDir = dirname(__DIR__);
$console = escapeshellarg($projectDir . '/bin/console');

$commands = [
    'doctrine:database:create --if-not-exists --env=test --no-interaction',
    'doctrine:schema:drop --force --full-database --env=test --no-interaction',
    'doctrine:schema:create --env=test --no-interaction',
];

foreach ($commands as $cmd) {
    $full = 'php ' . $console . ' ' . $cmd . ' 2>&1';
    exec($full, $output, $exitCode);
    if ($exitCode !== 0) {
        fwrite(STDERR, "Test bootstrap failed: {$cmd}\n" . implode("\n", $output) . "\n");
        exit(1);
    }
    $output = [];
}
