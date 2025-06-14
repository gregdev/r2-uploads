<?php

/*
 * Plugin Name: R2 Uploads
 * Description: Store uploads in Cloudflare R2
 * Author: Greg Smith / Human Made Limited
 * Version: 1.0.0
 */

require_once __DIR__ . '/inc/namespace.php';

add_action('plugins_loaded', 'R2_Uploads\init', 0);
