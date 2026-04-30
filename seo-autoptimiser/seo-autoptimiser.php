<?php
/**
 * Plugin Name: SEO AUTOPTIMISER
 * Description: Analyze WordPress posts/pages and optimize content with SEO-friendly AI suggestions, keyword extraction, and optional automatic publishing updates.
 * Version: 1.0.0
 * Author: SEO AUTOPTIMISER
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SEO_AUTOPTIMISER_VERSION', '1.0.0');
define('SEO_AUTOPTIMISER_PATH', plugin_dir_path(__FILE__));
define('SEO_AUTOPTIMISER_URL', plugin_dir_url(__FILE__));

require_once SEO_AUTOPTIMISER_PATH . 'includes/class-seo-autoptimiser.php';

function seo_autoptimiser_bootstrap() {
    $plugin = new SEO_Autoptimiser();
    $plugin->init();
}

add_action('plugins_loaded', 'seo_autoptimiser_bootstrap');
