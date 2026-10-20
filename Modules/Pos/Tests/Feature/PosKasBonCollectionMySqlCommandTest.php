<?php

namespace Modules\Pos\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PosKasBonCollectionMySqlCommandTest extends TestCase
{
    public function test_command_rejects_execution_when_default_connection_is_not_mysql(): void
    {
        $this->assertEquals('sqlite', config('database.default'));

        $exitCode = Artisan::call('pos:verify-kas-bon-mysql');
        $output = Artisan::output();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString("Default database connection must be 'mysql'", $output);
    }
}
