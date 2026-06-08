<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Pest Bootstrap
|--------------------------------------------------------------------------
|
| Binds the package's base TestCase (which wires Orchestra Testbench) to
| every test under tests/Feature and tests/Unit. Feature tests additionally
| use RefreshDatabase so each test starts against a fresh `sms_dispatches`
| table.
|
*/

uses(TestCase::class)->in('Feature', 'Unit');
uses(RefreshDatabase::class)->in('Feature');
