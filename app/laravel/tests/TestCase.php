<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Indicates whether the default seeder should run before each test.
     */
    protected bool $seed = true;

    /**
     * Run a specific seeder before each test.
     */
    protected string $seeder = \Database\Seeders\BrandSettingsSeeder::class;
}
