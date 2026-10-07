<?php
/**
 * Plugin Name: RentADriver dev bridge (LOCAL ONLY)
 * Description: WordPress refuses wp_safe_remote_* requests to private hosts and to ports outside 80/443/8080, which
 * silently kills webhook delivery ("A valid URL was not provided") to a RentADriver API running on the developer's
 * machine. This lifts both rules for the dev harness only. Never install it on a real store.
 */
if (!defined('ABSPATH')) exit;
add_filter('http_request_host_is_external', '__return_true');
add_filter('http_allowed_safe_ports', function ($ports) { return array_values(array_unique(array_merge((array) $ports, [8787, 8443, 3000]))); });
