<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
} // Exit if accessed directly.

/**
 * Class Disciple_Tools_Homescreen_Apps_Dispatcher_Contacts_Magic_Link
 */
class Disciple_Tools_Homescreen_Apps_Dispatcher_Contacts_Magic_Link {

    public $page_title = 'Dispatcher Contacts';
    public $root = "homescreen_apps";
    public $type = 'dispatcher_contacts';
    public $type_name = 'Dispatcher Contacts';
    public $post_type = 'user';
    public $record_post_type = 'contacts';
    public $type_actions = [
        '' => "Dispatcher Contacts",
    ];

    private static $_instance = null;
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function __construct() {
        // add_filter( 'dt_magic_url_register_types', [ $this, 'dt_magic_url_register_types' ], 10, 1 );
        add_filter( 'dt_magic_link_templates', [ $this, 'register_template' ], 10, 1 );
    }

    /**
     * Register the magic link type
     */
    public function dt_magic_url_register_types( array $types ) : array {
        if ( ! isset( $types[$this->root] ) ) {
            $types[$this->root] = [];
        }
        $types[$this->root][$this->type] = [
            'name' => $this->type_name,
            'root' => $this->root,
            'type' => $this->type,
            'meta_key' => $this->root . '_' . $this->type . '_magic_key',
            'actions' => $this->type_actions,
            'post_type' => $this->post_type,
        ];
        return $types;
    }

    /**
     * Register the template configuration
     */
    public function register_template( array $templates ) : array {
        if ( ! isset( $templates['contacts'] ) ) {
            $templates['contacts'] = [];
        }

        $templates['contacts']['templates_dispatcher_contacts'] = [
            'id' => 'templates_dispatcher_contacts',
            'enabled' => true,
            'name' => 'Dispatcher Contacts',
            'title' => 'Dispatch Needed Contacts',
            'title_translations' => [],
            'type' => 'list-template',
            'post_type' => 'contacts',
            'record_type' => 'contacts',
            'message' => 'View and manage contacts that need to be dispatched.',
            'query' => [
                'overall_status' => [ 'unassigned' ]
            ],
            'fields' => [
                [
                    'id' => 'name',
                    'type' => 'dt',
                    'enabled' => true,
                    'label' => 'Name',
                    'translations' => []
                ],
                [
                    'id' => 'assigned_to',
                    'type' => 'dt',
                    'enabled' => true,
                    'label' => 'Assigned To',
                    'translations' => []
                ],
                [
                    'id' => 'overall_status',
                    'type' => 'dt',
                    'enabled' => true,
                    'label' => 'Overall Status',
                    'translations' => []
                ]
            ],
            'show_recent_comments' => 1000,
            'send_submission_notifications' => true,
            'supports_create' => false
        ];

        return $templates;
    }
}

// Initialize the magic link
Disciple_Tools_Homescreen_Apps_Dispatcher_Contacts_Magic_Link::instance();
