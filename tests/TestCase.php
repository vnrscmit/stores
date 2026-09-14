<?php

namespace Tests;

use App\Support\ParallelDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Resolve the test database before the app boots — MariaDB will not
     * create schemas implicitly. Under ParaTest (TEST_TOKEN present) this
     * switches the process to its own worker clone of the template DB (see
     * App\Support\ParallelDatabase); serial runs use the template directly.
     */
    protected function setUp(): void
    {
        ParallelDatabase::prepare();

        parent::setUp();
    }
}
