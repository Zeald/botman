<?php

namespace BotMan\BotMan\Tests;

use BotMan\BotMan\Cache\ArrayCache;
use BotMan\BotMan\Cache\RedisCache;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;

/**
 * @group integration
 */
class RedisCacheTest extends TestCase
{
    protected function setUp(): void
    {
        if (! extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension required');
        }
    }

    public function tearDown(): void
    {
        $script = sprintf("for i, name in ipairs(redis.call('KEYS', '%s*')) do redis.call('DEL', name); end", RedisCache::KEY_PREFIX);

        $redis = new Redis();
        $redis->connect('127.0.0.1');
        $redis->eval($script);
        $redis->close();

        $redis = new Redis();
        $redis->connect('127.0.0.1', 6380);
        $redis->auth('secret');
        $redis->eval($script);
        $redis->close();
    }

    /** @test */
    public function valid_auth()
    {
        $cache = new RedisCache('127.0.0.1', 6380, 'secret');
        $cache->put('foo', 'bar', 1);
        static::assertTrue($cache->has('foo'));
    }

    /**
     * @test
     * @expectedException RedisException
     */
    public function invalid_auth()
    {
        $cache = new RedisCache('127.0.0.1', 6380, 'invalid');
        $cache->put('foo', 'bar', 1);
    }

    /** @test */
    public function has()
    {
        $cache = new RedisCache();
        $cache->put('foo', 'bar', 1);
        static::assertTrue($cache->has('foo'));
    }

    /** @test */
    public function has_not()
    {
        $cache = new RedisCache();
        static::assertFalse($cache->has('foo'));
    }

    /** @test */
    public function get_existing_key()
    {
        $cache = new RedisCache();
        $cache->put('foo', 'bar', 5);
        static::assertTrue($cache->has('foo'));
        static::assertEquals('bar', $cache->get('foo'));
    }

    /** @test */
    public function get_non_existing_key()
    {
        $cache = new RedisCache();
        static::assertNull($cache->get('foo'));
    }

    /** @test */
    public function pull_existing_key()
    {
        $cache = new RedisCache();
        $cache->put('foo', 'bar', 5);
        static::assertTrue($cache->has('foo'));
        static::assertEquals('bar', $cache->pull('foo'));
        static::assertFalse($cache->has('foo'));
        static::assertNull($cache->get('foo'));
    }

    /** @test */
    public function pull_non_existing_key()
    {
        $cache = new RedisCache();
        static::assertNull($cache->pull('foo'));
    }

    /** @test */
    public function pull_non_existing_key_with_default_value()
    {
        $cache = new ArrayCache();
        static::assertEquals('bar', $cache->pull('foo', 'bar'));
    }
}
