<?php

namespace XHGui\ServiceProvider;

use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Manager;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
use RuntimeException;
use XHGui\Saver\MongoSaver;
use XHGui\Searcher\MongoSearcher;

class MongoStorageProvider implements ServiceProviderInterface
{
    public function register(Container $app): void
    {
        // NOTE: db.host, db.options, db.driverOptions, db.db are @deprecated and will be removed in the future
        $app['mongodb.database'] = static function ($app) {
            $config = $app['config'];
            $mongodb = $config['mongodb'] ?? [];

            return $config['db.db'] ?? $mongodb['database'] ?? 'xhgui';
        };

        $app[Database::class] = static function ($app) {
            $database = $app['mongodb.database'];
            /** @var Client $client */
            $client = $app[Client::class];
            $mongoDB = $client->selectDatabase($database);
            $mongoDB->results->findOne();

            return $mongoDB;
        };

        $app[Client::class] = static function ($app) {
            if (!class_exists(Manager::class)) {
                throw new RuntimeException('Required extension ext-mongodb missing');
            }

            $config = $app['config'];
            $mongodb = $config['mongodb'] ?? [];
            $options = $config['db.options'] ?? $mongodb['options'] ?? [];
            $driverOptions = $config['db.driverOptions'] ?? $mongodb['driverOptions'] ?? [];
            $server = $config['db.host'] ?? sprintf('mongodb://%s:%s', $mongodb['hostname'], $mongodb['port']);

            // Ensure all results are returned as arrays
            $driverOptions['typeMap'] = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

            return new Client($server, $options, $driverOptions);
        };

        $app['searcher.mongodb'] = static fn($app) => new MongoSearcher($app[Database::class]);

        $app['saver.mongodb'] = static function ($app) {
            /** @var Database $mongoDB */
            $mongoDB = $app[Database::class];
            /** @var Collection $collection */
            $collection = $mongoDB->results;

            return new MongoSaver($collection);
        };
    }
}
