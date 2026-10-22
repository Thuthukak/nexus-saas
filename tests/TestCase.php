<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        foreach (['pgsql', 'pgsql_migrate'] as $conn) {
            $db = (string) config("database.connections.{$conn}.database");
            if (! str_ends_with($db, '_test')) {
                throw new \RuntimeException(
                    "Refusing to run tests against '{$db}' on '{$conn}'. The test database name must end in _test."
                );
            }
        }
    }

    //  public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    // {
    //     app(TenantContext::class)->clear(); // each request starts like a real one

    //     return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    // }

}
