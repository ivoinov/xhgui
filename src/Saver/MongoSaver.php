<?php

namespace XHGui\Saver;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Driver\WriteConcern;

class MongoSaver implements SaverInterface
{
    public function __construct(private Collection $_collection)
    {
    }

    public function save(array $data, string $id = null): string
    {
        // build 'request_ts' and 'request_date' from 'request_ts_micro'
        $ts = $data['meta']['request_ts_micro'];
        $sec = $ts['sec'];
        $usec = $ts['usec'];

        $meta = [
            'url' => $data['meta']['url'],
            'get' => $data['meta']['get'],
            'env' => $data['meta']['env'],
            'SERVER' => $data['meta']['SERVER'],
            'simple_url' => $data['meta']['simple_url'],
            'request_ts' => new UTCDateTime($sec * 1000),
            'request_ts_micro' => new UTCDateTime($sec * 1000 + intdiv($usec, 1000)),
            'request_date' => date('Y-m-d', $sec),
        ];

        $objectId = $id !== null ? new ObjectId($id) : new ObjectId();

        $a = [
            '_id' => $objectId,
            'meta' => $meta,
            'profile' => $this->encodeProfile($data['profile']),
        ];

        $this->_collection->insertOne($a, ['writeConcern' => new WriteConcern(0)]);

        return (string)$a['_id'];
    }

    /**
     * MongoDB can't save keys with values containing a dot:
     *
     *   InvalidArgumentException: invalid document for insert: keys cannot contain ".":
     *   "Zend_Controller_Dispatcher_Standard::loadClass==>load::controllers/ArticleController.php"
     *
     * Replace the dots with underscore in keys.
     *
     * @see https://github.com/perftools/xhgui/issues/209
     */
    private function encodeProfile(array $profile): array
    {
        $results = [];
        foreach ($profile as $k => $v) {
            if (str_contains($k, '.')) {
                $k = str_replace('.', '_', $k);
            }
            $results[$k] = $v;
        }

        return $results;
    }
}
