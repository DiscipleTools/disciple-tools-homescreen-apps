<?php
/**
 * Adds a Bible.com link to the home screen apps
 *
 * @package Disciple_Tools_Homescreen_Apps
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Add a Bible.com link to the home screen apps
 */
add_filter( 'dt_home_apps', function( $apps ) {
    /**
     * Build array containing Bible.com app config
     */
    $bible_app_config = [
        'name' => 'Bible',
        'type' => 'Link',
        'creation_type' => 'code',
        'icon' => plugin_dir_url( dirname( __FILE__ ) ) . 'assets/bible.png',
        'url' => 'https://www.bible.com/bible/111/MAT.1.NIV',
        'sort' => 10,
        'slug' => 'bible-com-link',
        'is_hidden' => false,
        'open_in_new_tab' => true
    ];

    /**
     * Check if proposed slug already exists
     */
    $dup_apps_by_slug = array_filter( $apps, function ( $app ) use ( $bible_app_config ) {
        return ( isset( $app['slug'] ) && ( $app['slug'] === $bible_app_config['slug'] ) );
    } );

    /**
     * Only append custom app if slug has not already been used
     */
    if ( count( $dup_apps_by_slug ) === 0 ) {
        $apps[] = $bible_app_config;
    }

    return $apps;
} );
