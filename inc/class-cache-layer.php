<?php

namespace R2_Uploads;

/**
 * Class R2_Uploads_Cache_Layer.
 *
 * \Aws\CacheIntefrace implementation using WordPress object cache.
 */
class Cache_Layer implements \Aws\CacheInterface, \Countable
{
    /**
     * Get object from cache.
     *
     * @param string $key Cache key.
     *
     * @return mixed|null
     */
    public function get($key)
    {
        return get_transient($this->get_key($key));
    }

    /**
     * Set cache value for key.
     *
     * @param string $key   Cache key.
     * @param mixed  $value Cache value.
     * @param int    $ttl   Cache TTL.
     *
     * @return mixed
     */
    public function set($key, $value, $ttl = WEEK_IN_SECONDS)
    {
        set_transient($this->get_key($key), $value, $ttl);
    }

    /**
     * Remove key from cache.
     *
     * @param string $key Cache key.
     *
     * @return mixed
     */
    public function remove($key)
    {
        delete_transient($this->get_key($key));
    }

    /**
     * Function provided here only for the compatibility with original \Aws\LruArrayCache example.
     *
     * @return int
     */
    public function count(): int
    {
        return 0;
    }

    private function get_key(string $key): string
    {
        return 'offload_s3/' . sanitize_title($key);
    }
}
