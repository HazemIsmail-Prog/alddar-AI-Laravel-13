<?php

namespace Tests;

use App\Services\Push\PushService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PushService::$sent = null;
    }
}
