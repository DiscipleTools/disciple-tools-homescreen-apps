<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
} // Exit if accessed directly.

/**
 * Class Disciple_Tools_Homescreen_Apps_My_Contacts_Magic_Link
 *
 * A magic link for contacts to view their subassigned contacts and
 * contacts accessible to their corresponding user.
 * Features a two-panel layout with contact list and details/comments/activity.
 */
class Disciple_Tools_Homescreen_Apps_My_Contacts_Magic_Link extends DT_Magic_Url_Base {

    public $page_title = 'My Contacts';
    public $page_description = 'View and manage your contacts.';
    public $root = 'homescreen_apps';
    public $type = 'my_contacts';
    public $post_type = 'contacts';
    private $meta_key = '';

    private static $_instance = null;

    public $meta = [];
    public $translatable = [];

    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function __construct() {
        $this->meta = [
            'app_type'          => 'magic_link',
            'post_type'         => $this->post_type,
            'contacts_only'     => true,
            'supports_create'   => false,
            'icon'              => 'mdi mdi-account-group',
            'show_in_home_apps' => true,
        ];

        $this->meta_key = $this->root . '_' . $this->type . '_magic_key';
        parent::__construct();

        add_filter( 'dt_settings_apps_list', [ $this, 'dt_settings_apps_list' ], 10, 1 );
        add_action( 'rest_api_init', [ $this, 'add_endpoints' ] );

        $url = dt_get_url_path();
        if ( strpos( $url, $this->root . '/' . $this->type ) === false ) {
            return;
        }

        if ( ! $this->check_parts_match() ) {
            return;
        }

        add_action( 'dt_blank_body', [ $this, 'body' ] );
        add_filter( 'dt_magic_url_base_allowed_css', [ $this, 'dt_magic_url_base_allowed_css' ], 10, 1 );
        add_filter( 'dt_magic_url_base_allowed_js', [ $this, 'dt_magic_url_base_allowed_js' ], 10, 1 );
        add_action( 'wp_enqueue_scripts', [ $this, 'wp_enqueue_scripts' ] );
    }

    public function dt_settings_apps_list( $apps_list ) {
        $apps_list[ $this->meta_key ] = [
            'key'              => $this->meta_key,
            'url_base'         => $this->root . '/' . $this->type,
            'label'            => $this->page_title,
            'description'      => $this->page_description,
            'settings_display' => true
        ];
        return $apps_list;
    }

    public function dt_magic_url_base_allowed_js( $allowed_js ) {
        $allowed_js = [];
        $allowed_js[] = 'dt-web-components';
        $allowed_js[] = 'my-contacts-js';
        return $allowed_js;
    }

    public function dt_magic_url_base_allowed_css( $allowed_css ) {
        $allowed_css = [];
        $allowed_css[] = 'dt-web-components-css';
        $allowed_css[] = 'my-contacts-css';
        return $allowed_css;
    }

    /**
     * Enqueue scripts and styles
     */
    public function wp_enqueue_scripts() {
        $theme_uri = get_template_directory_uri();
        $theme_dir = get_template_directory();

        // Enqueue DT web components JS
        $components_js = 'dt-assets/build/components/index.js';
        if ( file_exists( $theme_dir . '/' . $components_js ) ) {
            wp_enqueue_script(
                'dt-web-components',
                $theme_uri . '/' . $components_js,
                [],
                filemtime( $theme_dir . '/' . $components_js ),
                true
            );
        }

        // Enqueue DT web components CSS
        $components_css = 'dt-assets/build/css/light.min.css';
        if ( file_exists( $theme_dir . '/' . $components_css ) ) {
            wp_enqueue_style(
                'dt-web-components-css',
                $theme_uri . '/' . $components_css,
                [],
                filemtime( $theme_dir . '/' . $components_css )
            );
        }

        // Enqueue magic link JS
        wp_enqueue_script(
            'my-contacts-js',
            trailingslashit( plugin_dir_url( __FILE__ ) ) . 'my-contacts.js',
            [],
            filemtime( plugin_dir_path( __FILE__ ) . 'my-contacts.js' ),
            true
        );
        wp_localize_script(
            'my-contacts-js',
            'myContactsApp',
            [
                'root'  => esc_url_raw( rest_url() ),
                'nonce' => wp_create_nonce( 'wp_rest' ),
                'parts' => $this->parts,
            ]
        );

        // Enqueue magic link CSS
        wp_enqueue_style(
            'my-contacts-css',
            trailingslashit( plugin_dir_url( __FILE__ ) ) . 'my-contacts.css',
            [],
            filemtime( plugin_dir_path( __FILE__ ) . 'my-contacts.css' )
        );
    }

    /**
     * Register REST API endpoints
     */
    public function add_endpoints() {
        $namespace = $this->root . '/v1';

        $permission_callback = function ( WP_REST_Request $request ) {
            $magic = new DT_Magic_URL( $this->root );
            $valid_parts = $magic->verify_rest_endpoint_permissions_on_post( $request, true );
            if ( ! $valid_parts ) {
                return false;
            }
            $this->parts = $valid_parts;
            return true;
        };

        register_rest_route(
            $namespace, '/' . $this->type . '/contacts', [
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'get_my_contacts' ],
                    'permission_callback' => $permission_callback,
                ],
            ]
        );

        register_rest_route(
            $namespace, '/' . $this->type . '/contact', [
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'get_contact_details' ],
                    'permission_callback' => $permission_callback,
                ],
            ]
        );

        register_rest_route(
            $namespace, '/' . $this->type . '/comment', [
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'add_comment' ],
                    'permission_callback' => $permission_callback,
                ],
            ]
        );

        register_rest_route(
            $namespace, '/' . $this->type . '/users-mention', [
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'get_users_for_mention' ],
                    'permission_callback' => $permission_callback,
                ],
            ]
        );

        register_rest_route(
            $namespace, '/' . $this->type . '/update-field', [
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'update_field' ],
                    'permission_callback' => $permission_callback,
                ],
            ]
        );

        register_rest_route(
            $namespace, '/' . $this->type . '/field-options', [
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'get_field_options' ],
                    'permission_callback' => $permission_callback,
                ],
            ]
        );
    }

    /**
     * Get contacts for this magic link owner
     */
    public function get_my_contacts( WP_REST_Request $request ) {
        $owner_contact_id = $this->parts['post_id'];

        $contacts = [];
        $contact_ids_added = [];

        // Get the owner contact to find subassigned contacts and corresponding user
        $owner_contact = DT_Posts::get_post( 'contacts', $owner_contact_id, true, false );
        if ( is_wp_error( $owner_contact ) ) {
            return new WP_Error( 'contact_not_found', 'Owner contact not found', [ 'status' => 404 ] );
        }

        // 1. Get subassigned contacts (contacts where this contact is in their subassigned field)
        if ( ! empty( $owner_contact['subassigned'] ) ) {
            foreach ( $owner_contact['subassigned'] as $subassigned ) {
                $subassigned_id = $subassigned['ID'] ?? null;
                if ( $subassigned_id && ! in_array( $subassigned_id, $contact_ids_added ) ) {
                    $contact = DT_Posts::get_post( 'contacts', $subassigned_id, true, false );
                    if ( ! is_wp_error( $contact ) ) {
                        $contacts[] = $this->format_contact_for_list( $contact, 'subassigned' );
                        $contact_ids_added[] = $subassigned_id;
                    }
                }
            }
        }

        // 2. Get contacts the corresponding user has access to
        $corresponds_to_user = $owner_contact['corresponds_to_user'] ?? null;
        if ( $corresponds_to_user ) {
            $user_id = is_array( $corresponds_to_user ) ? ( $corresponds_to_user['ID'] ?? null ) : $corresponds_to_user;

            if ( $user_id ) {
                // Get contacts assigned to this user
                $user_contacts = DT_Posts::list_posts( 'contacts', [
                    'assigned_to' => [ $user_id ],
                    'sort'        => '-last_modified',
                    'limit'       => 100,
                ], false );

                if ( ! is_wp_error( $user_contacts ) && isset( $user_contacts['posts'] ) ) {
                    foreach ( $user_contacts['posts'] as $contact ) {
                        if ( ! in_array( $contact['ID'], $contact_ids_added ) && $contact['ID'] !== $owner_contact_id ) {
                            $contacts[] = $this->format_contact_for_list( $contact, 'assigned' );
                            $contact_ids_added[] = $contact['ID'];
                        }
                    }
                }
            }
        }

        // Sort by last_modified (most recent first)
        usort( $contacts, function( $a, $b ) {
            return ( $b['last_modified_timestamp'] ?? 0 ) - ( $a['last_modified_timestamp'] ?? 0 );
        });

        return [
            'contacts'         => $contacts,
            'total'            => count( $contacts ),
            'owner_contact_id' => $owner_contact_id,
        ];
    }

    /**
     * Format a contact for the list display
     */
    private function format_contact_for_list( $contact, $source = '' ) {
        $overall_status = '';
        $overall_status_color = '';
        if ( ! empty( $contact['overall_status'] ) ) {
            $overall_status = $contact['overall_status']['label'] ?? '';
            $overall_status_color = $contact['overall_status']['color'] ?? '';
        }

        $seeker_path = '';
        if ( ! empty( $contact['seeker_path'] ) ) {
            $seeker_path = $contact['seeker_path']['label'] ?? '';
        }

        $last_modified = '';
        $last_modified_timestamp = 0;
        if ( ! empty( $contact['last_modified']['timestamp'] ) ) {
            $last_modified_timestamp = $contact['last_modified']['timestamp'];
            $last_modified = $contact['last_modified']['formatted'] ?? '';
        }

        return [
            'ID'                      => $contact['ID'],
            'name'                    => $contact['name'] ?? 'Unknown',
            'overall_status'          => $overall_status,
            'overall_status_color'    => $overall_status_color,
            'seeker_path'             => $seeker_path,
            'last_modified'           => $last_modified,
            'last_modified_timestamp' => $last_modified_timestamp,
            'source'                  => $source,
        ];
    }

    /**
     * Get contact details with comments and activity
     */
    public function get_contact_details( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        $params = dt_recursive_sanitize_array( $params );

        $owner_contact_id = $this->parts['post_id'];
        $contact_id = intval( $params['contact_id'] ?? 0 );
        if ( ! $contact_id ) {
            return new WP_Error( 'missing_contact_id', 'Contact ID is required', [ 'status' => 400 ] );
        }

        // Verify access - the contact must be in subassigned or accessible by user
        if ( ! $this->verify_contact_access( $owner_contact_id, $contact_id ) ) {
            return new WP_Error( 'access_denied', 'You do not have access to this contact', [ 'status' => 403 ] );
        }

        $contact = DT_Posts::get_post( 'contacts', $contact_id, true, false );
        if ( is_wp_error( $contact ) ) {
            return new WP_Error( 'contact_not_found', 'Contact not found', [ 'status' => 404 ] );
        }

        $field_settings = DT_Posts::get_post_field_settings( 'contacts' );
        $tile_settings = DT_Posts::get_post_tiles( 'contacts' );

        // Get comments and activity
        $comments = DT_Posts::get_post_comments( 'contacts', $contact_id, true, 'all', [ 'number' => 50 ] );
        $activity = DT_Posts::get_post_activity( 'contacts', $contact_id );

        // Fields to skip (internal/system fields)
        $skip_fields = [ 'corresponds_to_user', 'duplicate_data', 'duplicate_of', 'post_author', 'record_picture', 'name' ];

        // Group fields by tile
        $tiles_with_fields = [];
        foreach ( $field_settings as $field_key => $field_setting ) {
            // Skip hidden, internal, or system fields
            if ( in_array( $field_key, $skip_fields ) ) {
                continue;
            }
            if ( isset( $field_setting['hidden'] ) && $field_setting['hidden'] === true ) {
                continue;
            }

            // Skip fields without a tile
            $tile_key = $field_setting['tile'] ?? '';
            if ( empty( $tile_key ) ) {
                continue;
            }

            // Skip if tile doesn't exist in tile settings
            if ( ! isset( $tile_settings[ $tile_key ] ) ) {
                continue;
            }

            $value = $contact[ $field_key ] ?? null;
            $formatted_value = $this->format_field_value( $value, $field_setting );

            // Initialize tile if not exists
            if ( ! isset( $tiles_with_fields[ $tile_key ] ) ) {
                $tile_order = $tile_settings[ $tile_key ]['tile_priority'] ?? 100;
                $tiles_with_fields[ $tile_key ] = [
                    'key'    => $tile_key,
                    'label'  => $tile_settings[ $tile_key ]['label'] ?? $tile_key,
                    'order'  => is_numeric( $tile_order ) ? intval( $tile_order ) : 100,
                    'fields' => [],
                ];
            }

            $field_order = $field_setting['in_create_form'] ?? 100;
            $field_type = $field_setting['type'] ?? 'text';

            // Prepare raw value for editing
            $raw_value = $this->prepare_raw_value( $value, $field_type );

            // Prepare options for select fields
            $options = [];
            if ( in_array( $field_type, [ 'key_select', 'multi_select' ] ) && isset( $field_setting['default'] ) ) {
                foreach ( $field_setting['default'] as $option_key => $option_data ) {
                    // Skip deleted or hidden options
                    if ( ! empty( $option_data['deleted'] ) || ! empty( $option_data['hidden'] ) ) {
                        continue;
                    }
                    // Ensure we have a valid key
                    if ( $option_key === '' || $option_key === null ) {
                        continue;
                    }
                    $label = $option_data['label'] ?? (string) $option_key;
                    // Ensure label is never empty
                    if ( empty( $label ) ) {
                        $label = (string) $option_key;
                    }
                    $options[] = [
                        'id'    => (string) $option_key,
                        'label' => $label,
                        'color' => $option_data['color'] ?? null,
                        'icon'  => $option_data['icon'] ?? null,
                    ];
                }
            }

            $field_data = [
                'key'       => $field_key,
                'label'     => $field_setting['name'] ?? $field_key,
                'value'     => $formatted_value,
                'raw_value' => $raw_value,
                'type'      => $field_type,
                'options'   => $options,
                'icon'      => $field_setting['icon'] ?? '',
                'font_icon' => $field_setting['font-icon'] ?? '',
                'order'     => is_numeric( $field_order ) ? intval( $field_order ) : 100,
            ];

            // Add post_type for connection fields
            if ( $field_type === 'connection' && isset( $field_setting['post_type'] ) ) {
                $field_data['post_type'] = $field_setting['post_type'];
            }

            $tiles_with_fields[ $tile_key ]['fields'][] = $field_data;
        }

        // Sort tiles by order
        uasort( $tiles_with_fields, function( $a, $b ) {
            $order_a = is_numeric( $a['order'] ?? 100 ) ? intval( $a['order'] ) : 100;
            $order_b = is_numeric( $b['order'] ?? 100 ) ? intval( $b['order'] ) : 100;
            return $order_a - $order_b;
        });

        // Sort fields within each tile by order
        foreach ( $tiles_with_fields as &$tile ) {
            usort( $tile['fields'], function( $a, $b ) {
                $order_a = is_numeric( $a['order'] ?? 100 ) ? intval( $a['order'] ) : 100;
                $order_b = is_numeric( $b['order'] ?? 100 ) ? intval( $b['order'] ) : 100;
                return $order_a - $order_b;
            });
        }

        // Convert to indexed array
        $tiles = array_values( $tiles_with_fields );

        // Merge comments and activity, sort by date
        $all_activity = $this->merge_comments_and_activity( $comments['comments'] ?? [], $activity );

        return [
            'ID'            => $contact['ID'],
            'name'          => $contact['name'] ?? 'Unknown',
            'tiles'         => $tiles,
            'created'       => $contact['post_date']['formatted'] ?? '',
            'last_modified' => $contact['last_modified']['formatted'] ?? '',
            'activity'      => $all_activity,
        ];
    }

    /**
     * Verify the owner has access to view the requested contact
     */
    private function verify_contact_access( $owner_contact_id, $contact_id ) {
        // Don't allow viewing self
        if ( $owner_contact_id === $contact_id ) {
            return false;
        }

        $owner_contact = DT_Posts::get_post( 'contacts', $owner_contact_id, true, false );
        if ( is_wp_error( $owner_contact ) ) {
            return false;
        }

        // Check if contact is in subassigned
        if ( ! empty( $owner_contact['subassigned'] ) ) {
            foreach ( $owner_contact['subassigned'] as $subassigned ) {
                if ( ( $subassigned['ID'] ?? null ) === $contact_id ) {
                    return true;
                }
            }
        }

        // Check if corresponding user has access
        $corresponds_to_user = $owner_contact['corresponds_to_user'] ?? null;
        if ( $corresponds_to_user ) {
            $user_id = is_array( $corresponds_to_user ) ? ( $corresponds_to_user['ID'] ?? null ) : $corresponds_to_user;

            if ( $user_id ) {
                // Check if contact is assigned to this user
                $contact = DT_Posts::get_post( 'contacts', $contact_id, true, false );
                if ( ! is_wp_error( $contact ) ) {
                    $assigned_to = $contact['assigned_to'] ?? null;
                    if ( $assigned_to ) {
                        $assigned_user_id = is_array( $assigned_to ) ? ( $assigned_to['id'] ?? null ) : $assigned_to;
                        if ( intval( $assigned_user_id ) === intval( $user_id ) ) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Merge comments and activity into a single timeline
     */
    private function merge_comments_and_activity( $comments, $activity ) {
        $merged = [];

        // Add comments
        foreach ( $comments as $comment ) {
            $timestamp = strtotime( $comment['comment_date_gmt'] ?? '' );
            $merged[] = [
                'type'      => 'comment',
                'id'        => $comment['comment_ID'] ?? 0,
                'content'   => $comment['comment_content'] ?? '',
                'author'    => $comment['comment_author'] ?? '',
                'date'      => $comment['comment_date'] ?? '',
                'timestamp' => $timestamp,
            ];
        }

        // Add activity from DT_Posts::get_post_activity() format
        $activity_items = $activity['activity'] ?? [];
        foreach ( $activity_items as $item ) {
            // Skip comment actions as they're already in comments
            if ( ( $item['action'] ?? '' ) === 'comment' ) {
                continue;
            }

            // Use object_note as the description
            $description = $item['object_note'] ?? '';
            if ( empty( $description ) ) {
                continue;
            }

            // Get user display name
            $user_name = $item['name'] ?? 'System';

            $timestamp = intval( $item['hist_time'] ?? 0 );

            $merged[] = [
                'type'      => 'activity',
                'id'        => $item['histid'] ?? 0,
                'content'   => $description,
                'author'    => $user_name,
                'date'      => $item['date'] ?? '',
                'timestamp' => $timestamp,
            ];
        }

        // Sort by timestamp descending (most recent first)
        usort( $merged, function( $a, $b ) {
            return ( $b['timestamp'] ?? 0 ) - ( $a['timestamp'] ?? 0 );
        });

        return $merged;
    }

    /**
     * Format field value based on field type
     */
    private function format_field_value( $value, $field_setting ) {
        $type = $field_setting['type'] ?? 'text';

        // Handle null/empty values - return empty string to display field with no value
        if ( $value === null || $value === '' || ( is_array( $value ) && empty( $value ) ) ) {
            return '';
        }

        switch ( $type ) {
            case 'text':
            case 'textarea':
            case 'number':
                return is_string( $value ) || is_numeric( $value ) ? $value : '';

            case 'boolean':
                return $value ? 'Yes' : 'No';

            case 'key_select':
                return $value['label'] ?? '';

            case 'multi_select':
            case 'tags':
                if ( is_array( $value ) ) {
                    $labels = [];
                    $options = $field_setting['default'] ?? [];
                    foreach ( $value as $item ) {
                        if ( isset( $item['label'] ) ) {
                            $labels[] = $item['label'];
                        } elseif ( is_string( $item ) ) {
                            if ( isset( $options[ $item ]['label'] ) ) {
                                $labels[] = $options[ $item ]['label'];
                            } else {
                                $labels[] = $item;
                            }
                        }
                    }
                    return implode( ', ', $labels );
                }
                return '';

            case 'communication_channel':
                if ( is_array( $value ) ) {
                    $channels = [];
                    foreach ( $value as $item ) {
                        if ( isset( $item['value'] ) && ! empty( $item['value'] ) ) {
                            $channels[] = $item['value'];
                        }
                    }
                    return implode( ', ', $channels );
                }
                return '';

            case 'location':
            case 'location_grid':
            case 'location_meta':
                if ( is_array( $value ) ) {
                    $locations = [];
                    foreach ( $value as $item ) {
                        if ( isset( $item['label'] ) ) {
                            $locations[] = $item['label'];
                        }
                    }
                    return implode( "\n", $locations );
                }
                return '';

            case 'connection':
                if ( is_array( $value ) ) {
                    $connections = [];
                    foreach ( $value as $item ) {
                        if ( isset( $item['post_title'] ) ) {
                            $connections[] = $item['post_title'];
                        }
                    }
                    return implode( ', ', $connections );
                }
                return '';

            case 'user_select':
                if ( isset( $value['display'] ) ) {
                    return $value['display'];
                }
                return '';

            case 'date':
                if ( isset( $value['formatted'] ) ) {
                    return $value['formatted'];
                }
                return '';

            case 'link':
                if ( is_array( $value ) ) {
                    $links = [];
                    foreach ( $value as $item ) {
                        if ( isset( $item['value'] ) ) {
                            $links[] = $item['value'];
                        }
                    }
                    return implode( ', ', $links );
                }
                return '';

            default:
                if ( is_array( $value ) ) {
                    if ( isset( $value['label'] ) ) {
                        return $value['label'];
                    }
                    return '';
                }
                return is_string( $value ) ? $value : '';
        }
    }

    /**
     * Prepare raw value for editing (JSON-safe format for DT components)
     */
    private function prepare_raw_value( $value, $field_type ) {
        if ( $value === null || $value === '' ) {
            return null;
        }

        switch ( $field_type ) {
            case 'text':
            case 'textarea':
            case 'number':
                return is_string( $value ) || is_numeric( $value ) ? $value : '';

            case 'boolean':
                return (bool) $value;

            case 'key_select':
                return $value['key'] ?? '';

            case 'multi_select':
            case 'tags':
                // Value should be an array of string IDs for the component
                if ( is_array( $value ) ) {
                    $result = [];
                    foreach ( $value as $item ) {
                        if ( is_string( $item ) ) {
                            $result[] = $item;
                        } elseif ( is_array( $item ) && isset( $item['value'] ) ) {
                            $result[] = $item['value'];
                        } elseif ( is_array( $item ) && isset( $item['key'] ) ) {
                            $result[] = $item['key'];
                        }
                    }
                    return $result;
                }
                return [];

            case 'communication_channel':
                if ( is_array( $value ) ) {
                    return array_values( $value );
                }
                return [];

            case 'connection':
                if ( is_array( $value ) ) {
                    return array_map( function( $item ) {
                        return [
                            'id'     => (int) ( $item['ID'] ?? 0 ),
                            'label'  => $item['post_title'] ?? '',
                            'link'   => $item['permalink'] ?? '',
                            'status' => $item['status'] ?? null,
                        ];
                    }, $value );
                }
                return [];

            case 'location':
            case 'location_meta':
                if ( is_array( $value ) ) {
                    return array_values( $value );
                }
                return [];

            case 'user_select':
                if ( isset( $value['id'] ) ) {
                    return $value['id'];
                }
                return null;

            case 'date':
                if ( isset( $value['timestamp'] ) ) {
                    return $value['timestamp'];
                }
                return null;

            case 'link':
                if ( is_array( $value ) ) {
                    return array_values( $value );
                }
                return [];

            default:
                return $value;
        }
    }

    /**
     * Add a comment to a contact
     */
    public function add_comment( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        $params = dt_recursive_sanitize_array( $params );

        $owner_contact_id = $this->parts['post_id'];
        $contact_id = intval( $params['contact_id'] ?? 0 );
        $comment = $params['comment'] ?? '';

        if ( ! $contact_id || empty( $comment ) ) {
            return new WP_Error( 'missing_params', 'Contact ID and comment are required', [ 'status' => 400 ] );
        }

        // Verify access
        if ( ! $this->verify_contact_access( $owner_contact_id, $contact_id ) ) {
            return new WP_Error( 'access_denied', 'You do not have access to this contact', [ 'status' => 403 ] );
        }

        $result = DT_Posts::add_post_comment( 'contacts', $contact_id, $comment, 'comment', [], false, true );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return [
            'success'    => true,
            'contact_id' => $contact_id,
            'comment_id' => $result,
        ];
    }

    /**
     * Get users for @mention autocomplete
     * Uses the existing Disciple_Tools_Users::get_assignable_users_compact() function
     */
    public function get_users_for_mention( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        $params = dt_recursive_sanitize_array( $params );

        $owner_contact_id = $this->parts['post_id'];
        $search = $params['search'] ?? '';

        // Get the corresponding user for the magic link owner
        $owner_contact = DT_Posts::get_post( 'contacts', $owner_contact_id, true, false );
        if ( is_wp_error( $owner_contact ) ) {
            return [ 'users' => [] ];
        }

        $corresponds_to_user = $owner_contact['corresponds_to_user'] ?? null;
        if ( ! $corresponds_to_user ) {
            return [ 'users' => [] ];
        }

        $user_id = is_array( $corresponds_to_user ) ? ( $corresponds_to_user['ID'] ?? null ) : $corresponds_to_user;
        if ( ! $user_id ) {
            return [ 'users' => [] ];
        }

        // Temporarily set the current user to get assignable users
        $original_user_id = get_current_user_id();
        wp_set_current_user( $user_id );

        $users = Disciple_Tools_Users::get_assignable_users_compact( $search );

        // Restore the original user
        wp_set_current_user( $original_user_id );

        if ( is_wp_error( $users ) ) {
            return [ 'users' => [] ];
        }

        return [
            'users' => $users,
        ];
    }

    /**
     * Update a field on a contact
     */
    public function update_field( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        $params = dt_recursive_sanitize_array( $params );

        $owner_contact_id = $this->parts['post_id'];
        $contact_id = intval( $params['contact_id'] ?? 0 );
        $field_key = $params['field_key'] ?? '';
        $field_value = $params['field_value'] ?? null;

        if ( ! $contact_id || empty( $field_key ) ) {
            return new WP_Error( 'missing_params', 'Contact ID and field key are required', [ 'status' => 400 ] );
        }

        // Verify access
        if ( ! $this->verify_contact_access( $owner_contact_id, $contact_id ) ) {
            return new WP_Error( 'access_denied', 'You do not have access to this contact', [ 'status' => 403 ] );
        }

        // Get field settings
        $field_settings = DT_Posts::get_post_field_settings( 'contacts' );
        $field_setting = $field_settings[ $field_key ] ?? [];

        // The JS uses ComponentService.convertValue() to format the value correctly for DT_Posts
        // so we can pass it directly without transformation
        $update_data = [ $field_key => $field_value ];
        $result = DT_Posts::update_post( 'contacts', $contact_id, $update_data, true, false );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Get the updated field value
        $updated_value = $result[ $field_key ] ?? null;
        $formatted_value = $this->format_field_value( $updated_value, $field_setting );

        return [
            'success'         => true,
            'contact_id'      => $contact_id,
            'field_key'       => $field_key,
            'value'           => $formatted_value,
            'raw_value'       => $updated_value,
        ];
    }

    /**
     * Get field options for typeahead components (connections, locations, tags)
     */
    public function get_field_options( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        $params = dt_recursive_sanitize_array( $params );

        $field_key = $params['field'] ?? '';
        $query = $params['query'] ?? '';

        if ( empty( $field_key ) ) {
            return new WP_Error( 'missing_field', 'Field key is required', [ 'status' => 400 ] );
        }

        // Get field settings
        $field_settings = DT_Posts::get_post_field_settings( 'contacts' );
        $field_setting = $field_settings[ $field_key ] ?? [];
        $field_type = $field_setting['type'] ?? '';

        $options = [];

        switch ( $field_type ) {
            case 'connection':
                // Get the post type this field connects to
                $connected_post_type = $field_setting['post_type'] ?? 'contacts';

                // Use get_viewable_compact which handles sorting properly
                // (recently viewed first for empty search, recently modified for search)
                $search_results = DT_Posts::get_viewable_compact( $connected_post_type, $query, [
                    'field_key' => $field_key,
                ] );

                if ( ! is_wp_error( $search_results ) && isset( $search_results['posts'] ) ) {
                    foreach ( $search_results['posts'] as $post ) {
                        $status = null;
                        if ( isset( $post['status'] ) ) {
                            $status = $post['status'];
                        }
                        $options[] = [
                            'id' => (int) $post['ID'],
                            'label' => $post['name'] ?? '',
                            'link' => get_permalink( $post['ID'] ),
                            'status' => $status,
                        ];
                    }
                }
                break;

            case 'location':
            case 'location_meta':
                // Search location grid
                $search_results = Disciple_Tools_Mapping_Queries::search_location_grid_by_name( [
                    's' => $query,
                    'limit' => 20,
                ] );

                if ( ! empty( $search_results ) && isset( $search_results['location_grid'] ) ) {
                    foreach ( $search_results['location_grid'] as $location ) {
                        $options[] = [
                            'id' => strval( $location['grid_id'] ?? $location['ID'] ),
                            'label' => $location['name'] ?? $location['label'] ?? '',
                        ];
                    }
                }
                break;

            case 'tags':
                // Get existing tags for this field
                $existing_tags = Disciple_Tools_Posts::get_multi_select_options( 'contacts', $field_key, false );
                if ( ! empty( $existing_tags ) ) {
                    foreach ( $existing_tags as $tag ) {
                        // Filter by query if provided
                        if ( empty( $query ) || stripos( $tag, $query ) !== false ) {
                            $options[] = [
                                'id' => $tag,
                                'label' => $tag,
                            ];
                        }
                    }
                }
                break;
        }

        return [
            'success' => true,
            'options' => $options,
        ];
    }

    /**
     * Custom header styles
     */
    public function header_style() {
        ?>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@mdi/font@6.6.96/css/materialdesignicons.min.css">
        <?php
    }

    /**
     * Page body content
     */
    public function body() {
        ?>
        <div class="my-contacts-container">
            <!-- Left Panel: Contacts List -->
            <div class="panel" id="contacts-panel">
                <div class="panel-header">
                    My Contacts <span id="contacts-count">(0)</span>
                    <input type="text" class="search-input" id="contacts-search" placeholder="Search contacts..." oninput="filterContacts(this.value)">
                </div>
                <div class="panel-content" id="contacts-list">
                    <div class="loading">
                        <div class="loading-spinner" style="margin: 0 auto;"></div>
                        <p>Loading contacts...</p>
                    </div>
                </div>
            </div>

            <!-- Right Panel: Contact Details -->
            <div class="panel" id="details-panel">
                <div class="panel-header">
                    <button class="mobile-back-btn mobile-only" onclick="hideMobileDetails()">&larr;</button>
                    <span class="desktop-only">Contact Details</span>
                    <span class="mobile-only" id="mobile-contact-name"></span>
                </div>
                <div class="panel-content" id="contact-details">
                    <div class="empty-state">
                        <div class="empty-state-icon">&#128100;</div>
                        <p>Select a contact to view details</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Success Toast -->
        <div class="success-toast" id="success-toast">
            Comment added successfully!
        </div>

        <?php
    }
}

// Initialize the magic link
Disciple_Tools_Homescreen_Apps_My_Contacts_Magic_Link::instance();
