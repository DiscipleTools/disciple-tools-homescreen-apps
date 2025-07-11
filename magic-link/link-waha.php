<?php
/**
 * Adds a WAHA app link to the home screen apps
 *
 * @package Disciple_Tools_Homescreen_Apps
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Add a WAHA app link to the home screen apps
 */
add_filter( 'dt_home_apps', function( $apps ) {
    /**
     * Build array containing WAHA app config
     */
    $waha_app_config = [
        "name" => "WAHA",
        "type" => "Link",
        'creation_type' => 'code',
        "icon" => plugin_dir_url( dirname( __FILE__ ) ) . 'assets/waha.webp',
        'url' => 'https://web.waha.app/eng/01.001/01.001.001',
        "sort" => 11,
        "slug" => "waha-app-link",
        "is_hidden" => false,
        'open_in_new_tab' => true
    ];

    /**
     * Check if proposed slug already exists
     */
    $dup_apps_by_slug = array_filter( $apps, function ( $app ) use ( $waha_app_config ) {
        return ( isset( $app['slug'] ) && ( $app['slug'] === $waha_app_config['slug'] ) );
    } );

    /**
     * Only append custom app if slug has not already been used
     */
    if ( count( $dup_apps_by_slug ) === 0 ) {
        $apps[] = $waha_app_config;
    }

    return $apps;
} );
