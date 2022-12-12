<?php

namespace S3_Uploads;

/**
 * Class S3_Uploads_Cache_Layer.
 *
 * \Aws\CacheIntefrace implementation using WordPress object cache.
 */

class Cache_Layer implements \Aws\CacheInterface, \Countable {

	/**
	 * Get object from cache.
	 *
	 * @param string $key Cache key.
	 *
	 * @return mixed|null
	 */
	public function get( $key ) {
		return wp_cache_get( $key, 's3-uploads-cache' );
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
	public function set( $key, $value, $ttl = 0 ) {
		wp_cache_set( $key, $value, 's3-uploads-cache', $ttl );
	}

	/**
	 * Remove key from cache.
	 *
	 * @param string $key Cache key.
	 *
	 * @return mixed
	 */
	public function remove( $key ) {
		wp_cache_delete( $key, 's3-uploads-cache' );
	}

	/**
	 * Function provided here only for the compatibility with original \Aws\LruArrayCache example.
	 *
	 * @return int
	 */
	public function count():int {
		return 0;
	}

}