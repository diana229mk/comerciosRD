<?php

namespace Tests;

use App\Support\TestingDatabaseGuard;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        TestingDatabaseGuard::assertIsolated($app);

        return $app;
    }
}
