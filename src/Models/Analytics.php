<?php
namespace CampChat\Models;

use CampChat\Config\Config;

class Analytics {
    private $collection;
    private $redis;

    public function __construct() {
        $this->collection = Config::getMongoDB()->selectDatabase('campchat')->selectCollection('analytics');
        $this->redis = Config::getRedis();
    }

    public function incrementUsers() {
        $this->redis->incr('campchat:users:total');
        $this->collection->updateOne(
            ['metric' => 'total_users'],
            ['$inc' => ['count' => 1]],
            ['upsert' => true]
        );
    }

    public function incrementGroups() {
        $this->redis->incr('campchat:groups:total');
        $this->collection->updateOne(
            ['metric' => 'total_groups'],
            ['$inc' => ['count' => 1]],
            ['upsert' => true]
        );
    }

    public function getTotals() {
        return [
            'users' => $this->redis->get('campchat:users:total') ?: 0,
            'groups' => $this->redis->get('campchat:groups:total') ?: 0
        ];
    }
}
?>