<?php

// Included by every fixture that boots Laravel, before bootstrap/app.php.
require_once dirname(__DIR__, 2).'/vendor/autoload.php';

Tests\Support\PreBootGuard::assertTestProcess();
