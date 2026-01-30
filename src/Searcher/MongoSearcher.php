<?php

namespace XHGui\Searcher;

use Exception;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use MongoDB\Driver\WriteConcern;
use XHGui\Db\Mapper;
use XHGui\Options\SearchOptions;
use XHGui\Profile;

/**
 * A Searcher for a MongoDB backend.
 */
class MongoSearcher implements SearcherInterface
{
    protected $_collection;

    protected $_watches;

    protected Mapper $_mapper;

    public function __construct(Database $db)
    {
        $this->_collection = $db->results;
        $this->_watches = $db->watches;
        $this->_mapper = new Mapper();
    }

    /**
     * {@inheritdoc}
     */
    public function latest()
    {
        $result = $this->_collection->findOne(
            [],
            ['sort' => ['meta.request_date' => -1]]
        );

        return $this->_wrap($result);
    }

    /**
     * {@inheritdoc}
     */
    public function query($conditions, $limit, $fields = [])
    {
        $options = ['limit' => $limit];
        if (!empty($fields)) {
            $options['projection'] = $fields;
        }
        $result = $this->_collection->find($conditions, $options);

        return iterator_to_array($result);
    }

    /**
     * {@inheritdoc}
     */
    public function get($id)
    {
        return $this->_wrap($this->_collection->findOne([
            '_id' => new ObjectId($id),
        ]));
    }

    /**
     * {@inheritdoc}
     */
    public function getForUrl($url, $options, $conditions = [])
    {
        $conditions = array_merge(
            (array)$conditions,
            ['simple_url' => $url]
        );
        $options = array_merge($options, [
            'conditions' => $conditions,
        ]);

        return $this->paginate($options);
    }

    /**
     * {@inheritdoc}
     */
    public function getPercentileForUrl($percentile, $url, $search = [])
    {
        $result = $this->_mapper->convert([
            'conditions' => $search + ['simple_url' => $url],
        ]);
        $match = $result['conditions'];

        $col = '$meta.request_date';
        if (!empty($search['limit']) && $search['limit'][0] === 'P') {
            $col = '$meta.request_ts';
        }

        $pipeline = [
            ['$match' => $match],
            [
                '$project' => [
                    'date' => $col,
                    'profile.main()' => 1,
                ],
            ],
            [
                '$group' => [
                    '_id' => '$date',
                    'row_count' => ['$sum' => 1],
                    'wall_times' => ['$push' => '$profile.main().wt'],
                    'cpu_times' => ['$push' => '$profile.main().cpu'],
                    'mu_times' => ['$push' => '$profile.main().mu'],
                    'pmu_times' => ['$push' => '$profile.main().pmu'],
                ],
            ],
            [
                '$project' => [
                    'date' => '$date',
                    'row_count' => '$row_count',
                    'raw_index' => [
                        '$multiply' => [
                            '$row_count',
                            $percentile / 100,
                        ],
                    ],
                    'wall_times' => '$wall_times',
                    'cpu_times' => '$cpu_times',
                    'mu_times' => '$mu_times',
                    'pmu_times' => '$pmu_times',
                ],
            ],
            ['$sort' => ['_id' => 1]],
        ];

        $cursor = $this->_collection->aggregate(
            $pipeline,
            ['cursor' => ['batchSize' => 0]]
        );

        $results = iterator_to_array($cursor);
        if (empty($results)) {
            return [];
        }
        $keys = [
            'wall_times' => 'wt',
            'cpu_times' => 'cpu',
            'mu_times' => 'mu',
            'pmu_times' => 'pmu',
        ];
        foreach ($results as &$result) {
            $result['date'] = ($result['_id'] instanceof UTCDateTime)
                ? $result['_id']->toDateTime()->format('Y-m-d H:i:s')
                : $result['_id'];
            unset($result['_id']);
            $index = max(round($result['raw_index']) - 1, 0);
            foreach ($keys as $key => $out) {
                sort($result[$key]);
                $result[$out] = $result[$key][$index] ?? null;
                unset($result[$key]);
            }
        }

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function getAvgsForUrl($url, $search = [])
    {
        $match = ['meta.simple_url' => $url];
        if (isset($search['date_start'])) {
            $match['meta.request_date']['$gte'] = (string)$search['date_start'];
        }
        if (isset($search['date_end'])) {
            $match['meta.request_date']['$lte'] = (string)$search['date_end'];
        }
        $cursor = $this->_collection->aggregate(
            [
                ['$match' => $match],
                [
                    '$project' => [
                        'date' => '$meta.request_date',
                        'profile.main()' => 1,
                    ],
                ],
                [
                    '$group' => [
                        '_id' => '$date',
                        'avg_wt' => ['$avg' => '$profile.main().wt'],
                        'avg_cpu' => ['$avg' => '$profile.main().cpu'],
                        'avg_mu' => ['$avg' => '$profile.main().mu'],
                        'avg_pmu' => ['$avg' => '$profile.main().pmu'],
                    ],
                ],
                ['$sort' => ['_id' => 1]],
            ],
            ['cursor' => ['batchSize' => 0]]
        );

        $results = iterator_to_array($cursor);
        if (empty($results)) {
            return [];
        }
        foreach ($results as $i => $result) {
            $results[$i]['date'] = $result['_id'];
            unset($results[$i]['_id']);
        }

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function getAll(SearchOptions $options): array
    {
        return $this->paginate($options->toArray());
    }

    /**
     * {@inheritdoc}
     */
    public function delete($id): void
    {
        $this->_collection->deleteOne(['_id' => new ObjectId($id)]);
    }

    public function truncate()
    {
        $this->_collection->deleteMany([]);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function saveWatch(array $data): bool
    {
        if (empty($data['name'])) {
            return false;
        }

        if (!empty($data['removed']) && isset($data['_id'])) {
            $this->_watches->deleteOne(
                ['_id' => new ObjectId($data['_id'])],
                ['writeConcern' => new WriteConcern(1)]
            );

            return true;
        }

        if (empty($data['_id'])) {
            $this->_watches->insertOne(
                $data,
                ['writeConcern' => new WriteConcern(1)]
            );

            return true;
        }

        $data['_id'] = new ObjectId($data['_id']);
        $this->_watches->replaceOne(
            ['_id' => $data['_id']],
            $data,
            ['writeConcern' => new WriteConcern(1)]
        );

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getAllWatches(): array
    {
        $cursor = $this->_watches->find();

        return array_values(iterator_to_array($cursor));
    }

    public function truncateWatches()
    {
        $this->_watches->deleteMany([]);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    private function paginate(array $options): array
    {
        $opts = $this->_mapper->convert($options);

        $totalRows = $this->_collection->countDocuments($opts['conditions']);

        $totalPages = max(ceil($totalRows / $opts['perPage']), 1);
        $page = 1;
        if (isset($options['page'])) {
            $page = min(max($options['page'], 1), $totalPages);
        }

        $projection = false;
        if (isset($options['projection'])) {
            if ($options['projection'] === true) {
                $projection = ['meta' => 1, 'profile.main()' => 1];
            } else {
                $projection = $options['projection'];
            }
        }

        $findOptions = [
            'sort' => $opts['sort'],
            'skip' => (int)($page - 1) * $opts['perPage'],
            'limit' => $opts['perPage'],
        ];

        if ($projection !== false) {
            $findOptions['projection'] = $projection;
        }

        $cursor = $this->_collection->find($opts['conditions'], $findOptions);

        return [
            'results' => $this->_wrap($cursor),
            'sort' => $opts['sort'],
            'direction' => $opts['direction'],
            'page' => $page,
            'perPage' => $opts['perPage'],
            'totalPages' => $totalPages,
        ];
    }

    /**
     * Converts arrays + Cursors into Profile instances.
     *
     * @param array|iterable $data the data to transform
     * @return Profile|Profile[] the transformed/wrapped results
     */
    private function _wrap($data)
    {
        if ($data === null) {
            throw new Exception('No profile data found.');
        }

        if (is_array($data)) {
            return new Profile($data);
        }
        $results = [];
        foreach ($data as $row) {
            $results[] = new Profile($row);
        }

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function stats(): array
    {
        return [
            'profiles' => 0,
            'latest' => 0,
            'bytes' => 0,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getAllServerNames(): ?array
    {
        return $this->_collection->distinct('meta.SERVER.SERVER_NAME');
    }
}
