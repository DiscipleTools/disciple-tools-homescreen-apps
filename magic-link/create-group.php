<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
} // Exit if accessed directly.

/**
 * Class Disciple_Tools_Homescreen_Apps_Create_Group_Magic_Link
 */
class Disciple_Tools_Homescreen_Apps_Create_Group_Magic_Link {

    public $page_title = 'Create Group';
    public $root = 'homescreen_apps';
    public $type = 'create_group';
    public $type_name = 'Create Group';
    public $post_type = 'groups';
    public $type_actions = [
        '' => 'Create Group',
    ];

    private static $_instance = null;
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function __construct() {
        add_filter( 'dt_magic_link_templates', [ $this, 'register_template' ], 10, 1 );
    }

    /**
     * Register the template configuration
     */
    public function register_template( array $templates ) : array {
        if ( ! isset( $templates['groups'] ) ) {
            $templates['groups'] = [];
        }

        $templates['groups']['templates_create_group'] = [
            'id' => 'templates_create_group',
            'enabled' => true,
            'name' => 'Create Group',
            'title' => 'Create New Group',
            'title_translations' => [],
            'type' => 'create-record',
            'post_type' => 'groups',
            'record_type' => 'groups',
            'message' => 'Use this form to create a new group record.',
            'icon' => 'mdi mdi-home-group-plus',
            'fields' => [
                [
                    'id' => 'name',
                    'type' => 'dt',
                    'enabled' => true,
                    'label' => 'Name',
                    'translations' => []
                ],
                [
                    'id' => 'group_type',
                    'type' => 'dt',
                    'enabled' => true,
                    'label' => 'Group Type',
                    'translations' => []
                ],
                [
                    'id' => 'group_status',
                    'type' => 'dt',
                    'enabled' => true,
                    'label' => 'Group Status',
                    'translations' => []
                ],
                [
                    'id' => 'member_count',
                    'type' => 'dt',
                    'enabled' => true,
                    'label' => 'Member Count',
                    'translations' => []
                ]
            ],
            'show_recent_comments' => 0,
            'send_submission_notifications' => false,
            'supports_create' => true
        ];

        return $templates;
    }
}

// Initialize the magic link
Disciple_Tools_Homescreen_Apps_Create_Group_Magic_Link::instance();
