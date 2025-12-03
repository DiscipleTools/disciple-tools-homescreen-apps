<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
} // Exit if accessed directly.

/**
 * Class Disciple_Tools_Homescreen_Apps_Create_Contact_Magic_Link
 */
class Disciple_Tools_Homescreen_Apps_Create_Contact_Magic_Link {

    public $page_title = 'Create Contact';
    public $root = 'homescreen_apps';
    public $type = 'create_contact';
    public $type_name = 'Create Contact';
    public $post_type = 'contacts';
    public $type_actions = [
        '' => 'Create Contact',
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
        if ( ! isset( $templates['contacts'] ) ) {
            $templates['contacts'] = [];
        }

        $templates['contacts']['templates_create_contact'] = [
            'id' => 'templates_create_contact',
            'enabled' => true,
            'name' => 'Create Contact',
            'title' => 'Create New Contact',
            'title_translations' => [],
            'type' => 'create-record',
            'post_type' => 'contacts',
            'record_type' => 'contacts',
            'message' => 'Use this form to create a new contact record.',
            'icon' => 'mdi mdi-account-plus',
            'fields' => [
                [
                    'id' => 'name',
                    'type' => 'dt',
                    'enabled' => true,
                    'label' => 'Name',
                    'translations' => []
                ],
                [
                    'id' => 'contact_email',
                    'type' => 'dt',
                    'enabled' => true,
                    'label' => 'Contact Email',
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
Disciple_Tools_Homescreen_Apps_Create_Contact_Magic_Link::instance();
