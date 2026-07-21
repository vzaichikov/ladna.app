<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $expectedDatabase = (string) getenv('LADNA_TEST_DATABASE');
        $configuredDatabase = (string) getenv('DB_DATABASE');

        if (! preg_match('/(?:^|_)(?:test|testing)(?:_|$)/', $expectedDatabase)
            || ! hash_equals($expectedDatabase, $configuredDatabase)) {
            throw new RuntimeException(
                'Refusing to run tests without an explicit dedicated test database. '
                .'DB_DATABASE and LADNA_TEST_DATABASE must match a database name containing test or testing.',
            );
        }

        $application = parent::createApplication();
        $connectionName = (string) $application['config']->get('database.default');
        $connection = $application['db']->connection($connectionName);

        if ($connection->getDriverName() !== 'mysql'
            || ! hash_equals($expectedDatabase, $connection->getDatabaseName())) {
            throw new RuntimeException(
                "Refusing to run tests on database [{$connection->getDatabaseName()}]. "
                ."Expected the dedicated MySQL test database [{$expectedDatabase}].",
            );
        }

        return $application;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
