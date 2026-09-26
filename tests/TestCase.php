<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Pages render @vite tags; tests must not depend on a prior `npm run build`.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
