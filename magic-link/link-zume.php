<?php
/**
 * Adds a Zume Training link to the home screen apps
 *
 * @package Disciple_Tools_Homescreen_Apps
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Add a Zume Training link to the home screen apps
 */
add_filter( 'dt_home_apps', function( $apps ) {
    /**
     * Build array containing Zume Training app config
     */
    $zume_app_config = [
        'name' => 'Zume Training',
        'type' => 'Link',
        'creation_type' => 'code',
        'icon' => plugin_dir_url( dirname( __FILE__ ) ) . 'assets/zume-logo.png',
        'url' => 'https://zume.training/',
        'sort' => 11,
        'slug' => 'zume-training-link',
        'is_hidden' => false,
        'open_in_new_tab' => true
    ];

    /**
     * Check if proposed slug already exists
     */
    $dup_apps_by_slug = array_filter( $apps, function ( $app ) use ( $zume_app_config ) {
        return ( isset( $app['slug'] ) && ( $app['slug'] === $zume_app_config['slug'] ) );
    } );

    /**
     * Only append custom app if slug has not already been used
     */
    if ( count( $dup_apps_by_slug ) === 0 ) {
        $apps[] = $zume_app_config;
    }

    return $apps;
} );
