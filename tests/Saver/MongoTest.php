<?php

namespace XHGui\Test\Saver;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Driver\WriteConcern;
use MongoDB\InsertOneResult;
use XHGui\Saver\MongoSaver;
use XHGui\Test\TestCase;

class MongoTest extends TestCase
{
    public function testSave(): void
    {
        $this->skipIfPdo('This is MongoDB test');

        $data = $this->loadFixture('normalized.json');

        $savedDocuments = [];
        $savedOptions = [];

        // Create a mock InsertOneResult
        $insertResult = $this->getMockBuilder(InsertOneResult::class)
            ->disableOriginalConstructor()
            ->getMock();

        $collection = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['insertOne'])
            ->getMock();

        $collection
            ->expects($this->exactly(count($data)))
            ->method('insertOne')
            ->willReturnCallback(function ($document, $options) use (&$savedDocuments, &$savedOptions, $insertResult) {
                $savedDocuments[] = $document;
                $savedOptions[] = $options;
                return $insertResult;
            });

        $saver = new MongoSaver($collection);

        foreach ($data as $profile) {
            $saver->save($profile, $profile['_id'] ?? null);
        }

        // Verify all documents were saved with correct structure
        $this->assertCount(count($data), $savedDocuments);
        foreach ($savedDocuments as $doc) {
            $this->assertIsArray($doc);
            $this->assertArrayHasKey('_id', $doc);
            $this->assertInstanceOf(ObjectId::class, $doc['_id']);
            $this->assertArrayHasKey('meta', $doc);
            $this->assertArrayHasKey('profile', $doc);
            $this->assertInstanceOf(UTCDateTime::class, $doc['meta']['request_ts']);
            $this->assertInstanceOf(UTCDateTime::class, $doc['meta']['request_ts_micro']);
        }

        // Verify write concern was passed
        foreach ($savedOptions as $opts) {
            $this->assertArrayHasKey('writeConcern', $opts);
            $this->assertInstanceOf(WriteConcern::class, $opts['writeConcern']);
        }
    }
}
