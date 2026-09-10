<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Backend feature tests must not depend on a running Vite server or built assets.
        $this->withoutVite();
    }
}
