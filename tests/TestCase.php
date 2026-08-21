<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $hash = crc32(static::class.'::'.$this->name());

        $this->withServerVariables([
            'REMOTE_ADDR' => sprintf(
                '10.%d.%d.%d',
                ($hash >> 16) & 255,
                ($hash >> 8) & 255,
                $hash & 255,
            ),
        ]);
    }
}
