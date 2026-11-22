<?php

// Used by `composer test`: refuse to run the suite outside the isolated Docker test runtime.

require dirname(__DIR__).'/vendor/autoload.php';

App\Support\ProcessEnvironment::capture();
$failure = App\Support\TestRuntime::failure();

if ($failure !== null) {
    fwrite(STDERR, 'composer test bloqueado: '.$failure.PHP_EOL
        .'Use: docker compose --profile testing --env-file .env.testing.docker -f compose.testing.yaml run --rm phpunit'.PHP_EOL);
    exit(1);
}
