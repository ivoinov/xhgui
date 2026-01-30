<?php

namespace XHGui\Test;

use MongoDB\Database;

class MongoHelper
{
    /** @var array */
    private $indexes = [];

    public function __construct(private Database $mongodb)
    {
    }

    public function dropCollection(string $collectionName): void
    {
        $collection = $this->mongodb->selectCollection($collectionName);
        $collection->drop();
    }

    public function createCollection(string $collectionName, array $indexes): void
    {
        $this->mongodb->createCollection($collectionName);
        $collection = $this->mongodb->selectCollection($collectionName);

        foreach ($indexes as [$keys, $options]) {
            $collection->createIndex($keys, $options);
        }
        $this->indexes[$collectionName] = $indexes;
    }

    public function getIndexes(string $collectionName): iterable
    {
        $collection = $this->mongodb->selectCollection($collectionName);
        $expectedIndexes = $this->indexes[$collectionName];

        foreach ($collection->listIndexes() as $offset => $index) {
            yield [
                $index['key'],
                $index['name'],
                $expectedIndexes[$offset][0],
                $expectedIndexes[$offset][1],
            ];
        }
    }
}
