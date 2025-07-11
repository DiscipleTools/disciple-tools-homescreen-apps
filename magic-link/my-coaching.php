<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
} // Exit if accessed directly.

/**
 * Class Disciple_Tools_Homescreen_Apps_My_Coaching_Magic_Link
 */
class Disciple_Tools_Homescreen_Apps_My_Coaching_Magic_Link {

    public $page_title = 'My Coaching';
    public $root = "homescreen_apps";
    public $type = 'my_coaching';
    public $type_name = 'My Coaching';
    public $post_type = 'contacts';
    public $record_post_type = 'contacts';
    public $type_actions = [
        '' => "My Coaching",
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

        $templates['contacts']['templates_my_coaching'] = [
            'id' => 'templates_my_coaching',
            'enabled' => true,
            'name' => 'My Coaching',
            'title' => 'My Coaching List',
            'title_translations' => [],
            'type' => 'post-connections',
            'post_type' => 'contacts',
            'record_type' => 'contacts',
            'message' => 'View and manage the contacts you are coaching.',
            'connection_fields' => [
                'coached_by'
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
                    'id' => 'seeker_path',
                    'type' => 'dt',
                    'enabled' => true,
                    'label' => 'Seeker Path',
                    'translations' => []
                ],
                [
                    'id' => 'milestones',
                    'type' => 'dt',
                    'enabled' => true,
                    'label' => 'Milestones',
                    'translations' => []
                ],
                [
                    'id' => 'last_modified',
                    'type' => 'dt',
                    'enabled' => true,
                    'label' => 'Last Modified',
                    'translations' => []
                ]
            ],
            'show_recent_comments' => 5,
            'send_submission_notifications' => true,
            'supports_create' => false
        ];

        return $templates;
    }
}

// Initialize the magic link
Disciple_Tools_Homescreen_Apps_My_Coaching_Magic_Link::instance();
