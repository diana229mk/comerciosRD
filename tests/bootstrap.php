<?php

require dirname(__DIR__).'/vendor/autoload.php';

// Refuse to run any test unless the process is the authorized isolated runtime.
Tests\Support\PreBootGuard::assertTestProcess();
