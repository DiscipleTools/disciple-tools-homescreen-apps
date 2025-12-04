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
        return $allowed_js;
    }

    public function dt_magic_url_base_allowed_css( $allowed_css ) {
        return $allowed_css;
    }

    /**
     * Register REST API endpoints
     */
    public function add_endpoints() {
        $namespace = $this->root . '/v1';

        register_rest_route(
            $namespace, '/' . $this->type . '/contacts', [
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'get_my_contacts' ],
                    'permission_callback' => [ $this, 'check_permission' ],
                ],
            ]
        );

        register_rest_route(
            $namespace, '/' . $this->type . '/contact', [
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'get_contact_details' ],
                    'permission_callback' => [ $this, 'check_permission' ],
                ],
            ]
        );

        register_rest_route(
            $namespace, '/' . $this->type . '/comment', [
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'add_comment' ],
                    'permission_callback' => [ $this, 'check_permission' ],
                ],
            ]
        );

        register_rest_route(
            $namespace, '/' . $this->type . '/users-mention', [
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'get_users_for_mention' ],
                    'permission_callback' => [ $this, 'check_permission' ],
                ],
            ]
        );
    }

    /**
     * Check permission for REST endpoints
     */
    public function check_permission( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        $params = dt_recursive_sanitize_array( $params );

        if ( ! isset( $params['parts'], $params['parts']['public_key'], $params['parts']['meta_key'] ) ) {
            return false;
        }

        $post_id = $this->get_post_id_from_magic_key( $params['parts']['meta_key'], $params['parts']['public_key'] );
        return (bool) $post_id;
    }

    /**
     * Get post ID from magic key
     */
    private function get_post_id_from_magic_key( $meta_key, $public_key ) {
        global $wpdb;
        $post_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s",
            $meta_key,
            $public_key
        ) );
        return $post_id ? intval( $post_id ) : null;
    }

    /**
     * Get contacts for this magic link owner
     */
    public function get_my_contacts( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        $params = dt_recursive_sanitize_array( $params );

        $owner_contact_id = $this->get_post_id_from_magic_key( $params['parts']['meta_key'], $params['parts']['public_key'] );
        if ( ! $owner_contact_id ) {
            return new WP_Error( 'invalid_key', 'Invalid magic link', [ 'status' => 403 ] );
        }

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

        $owner_contact_id = $this->get_post_id_from_magic_key( $params['parts']['meta_key'], $params['parts']['public_key'] );
        if ( ! $owner_contact_id ) {
            return new WP_Error( 'invalid_key', 'Invalid magic link', [ 'status' => 403 ] );
        }

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
        $activity = $this->get_post_activity( $contact_id );

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
            $tiles_with_fields[ $tile_key ]['fields'][] = [
                'key'       => $field_key,
                'label'     => $field_setting['name'] ?? $field_key,
                'value'     => $formatted_value,
                'type'      => $field_setting['type'] ?? 'text',
                'icon'      => $field_setting['icon'] ?? '',
                'font_icon' => $field_setting['font-icon'] ?? '',
                'order'     => is_numeric( $field_order ) ? intval( $field_order ) : 100,
            ];
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
     * Get post activity log
     */
    private function get_post_activity( $post_id ) {
        global $wpdb;

        $activity = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $wpdb->dt_activity_log
             WHERE object_id = %d
             AND object_type = 'contacts'
             ORDER BY hist_time DESC
             LIMIT 50",
            $post_id
        ), ARRAY_A );

        $formatted_activity = [];
        foreach ( $activity as $item ) {
            // Skip certain action types that are not user-facing
            $skip_actions = [ 'connected to', 'disconnected from' ];
            if ( in_array( $item['action'] ?? '', $skip_actions ) ) {
                continue;
            }

            $formatted_activity[] = [
                'id'          => $item['histid'],
                'type'        => 'activity',
                'action'      => $item['action'] ?? '',
                'object_note' => $item['object_note'] ?? '',
                'meta_key'    => $item['meta_key'] ?? '',
                'meta_value'  => $item['meta_value'] ?? '',
                'old_value'   => $item['old_value'] ?? '',
                'user_id'     => $item['user_id'] ?? 0,
                'timestamp'   => intval( $item['hist_time'] ?? 0 ),
                'date'        => gmdate( 'Y-m-d H:i:s', intval( $item['hist_time'] ?? 0 ) ),
            ];
        }

        return $formatted_activity;
    }

    /**
     * Merge comments and activity into a single timeline
     */
    private function merge_comments_and_activity( $comments, $activity ) {
        $merged = [];

        // Add comments
        foreach ( $comments as $comment ) {
            $timestamp = strtotime( $comment['comment_date'] ?? '' );
            $merged[] = [
                'type'      => 'comment',
                'id'        => $comment['comment_ID'] ?? 0,
                'content'   => $comment['comment_content'] ?? '',
                'author'    => $comment['comment_author'] ?? '',
                'date'      => $comment['comment_date'] ?? '',
                'timestamp' => $timestamp,
            ];
        }

        // Add activity (only field changes, not comments which are already included)
        foreach ( $activity as $item ) {
            if ( $item['action'] === 'comment' ) {
                continue; // Skip comment actions as they're already in comments
            }

            $description = $this->format_activity_description( $item );
            if ( empty( $description ) ) {
                continue;
            }

            // Get user display name
            $user_name = 'System';
            if ( ! empty( $item['user_id'] ) ) {
                $user = get_userdata( $item['user_id'] );
                if ( $user ) {
                    $user_name = $user->display_name;
                }
            }

            $merged[] = [
                'type'      => 'activity',
                'id'        => $item['id'],
                'content'   => $description,
                'author'    => $user_name,
                'date'      => $item['date'],
                'timestamp' => $item['timestamp'],
            ];
        }

        // Sort by timestamp descending (most recent first)
        usort( $merged, function( $a, $b ) {
            return ( $b['timestamp'] ?? 0 ) - ( $a['timestamp'] ?? 0 );
        });

        return $merged;
    }

    /**
     * Format activity item into human-readable description
     */
    private function format_activity_description( $item ) {
        $action = $item['action'] ?? '';
        $meta_key = $item['meta_key'] ?? '';
        $object_note = $item['object_note'] ?? '';

        // If there's an object note, use it
        if ( ! empty( $object_note ) ) {
            return $object_note;
        }

        // Format based on action type
        switch ( $action ) {
            case 'field_update':
                $field_settings = DT_Posts::get_post_field_settings( 'contacts' );
                $field_name = $field_settings[ $meta_key ]['name'] ?? $meta_key;
                return sprintf( 'Updated %s', $field_name );

            case 'created':
                return 'Contact created';

            default:
                return '';
        }
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
     * Add a comment to a contact
     */
    public function add_comment( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        $params = dt_recursive_sanitize_array( $params );

        $owner_contact_id = $this->get_post_id_from_magic_key( $params['parts']['meta_key'], $params['parts']['public_key'] );
        if ( ! $owner_contact_id ) {
            return new WP_Error( 'invalid_key', 'Invalid magic link', [ 'status' => 403 ] );
        }

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
     */
    public function get_users_for_mention( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        $params = dt_recursive_sanitize_array( $params );

        $search = $params['search'] ?? '';

        global $wpdb;

        $users = $wpdb->get_results( $wpdb->prepare( "
            SELECT ID, display_name
            FROM $wpdb->users
            WHERE display_name LIKE %s
            ORDER BY display_name
            LIMIT 10
        ", '%' . $wpdb->esc_like( $search ) . '%' ), ARRAY_A );

        return [
            'users' => $users,
        ];
    }

    /**
     * Custom header styles
     */
    public function header_style() {
        ?>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@mdi/font@6.6.96/css/materialdesignicons.min.css">
        <style>
            :root {
                --primary-color: #3f729b;
                --success-color: #4caf50;
                --warning-color: #ff9800;
                --danger-color: #f44336;
                --border-color: #e0e0e0;
                --bg-color: #f5f5f5;
                --card-bg: #ffffff;
            }

            * {
                box-sizing: border-box;
            }

            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                margin: 0;
                padding: 0;
                background-color: var(--bg-color);
            }

            .my-contacts-container {
                display: grid;
                grid-template-columns: 320px 1fr;
                gap: 16px;
                height: 100vh;
                padding: 16px;
            }

            .panel {
                background: var(--card-bg);
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.12);
                display: flex;
                flex-direction: column;
                overflow: hidden;
            }

            .panel-header {
                padding: 16px;
                border-bottom: 1px solid var(--border-color);
                font-weight: 600;
                font-size: 16px;
                background: var(--card-bg);
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .panel-header span {
                color: #666;
                font-weight: normal;
                font-size: 14px;
            }

            .panel-content {
                flex: 1;
                overflow-y: auto;
                padding: 8px;
            }

            /* Search input */
            .search-input {
                display: block;
                width: 100%;
                margin-top: 8px;
                padding: 8px 12px;
                border: 1px solid var(--border-color);
                border-radius: 4px;
                font-size: 14px;
            }

            .search-input:focus {
                outline: none;
                border-color: var(--primary-color);
            }

            /* Contact List */
            .contact-item {
                padding: 12px;
                border: 1px solid var(--border-color);
                border-radius: 6px;
                margin-bottom: 8px;
                cursor: pointer;
                background: var(--card-bg);
                transition: all 0.2s ease;
            }

            .contact-item:hover {
                border-color: var(--primary-color);
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            }

            .contact-item.selected {
                border-color: var(--primary-color);
                background: #e3f2fd;
            }

            .contact-name {
                font-weight: 600;
                margin-bottom: 4px;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .contact-meta {
                font-size: 12px;
                color: #666;
            }

            .status-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 10px;
                font-size: 11px;
                font-weight: 500;
            }

            .source-badge {
                display: inline-block;
                padding: 2px 6px;
                border-radius: 8px;
                font-size: 10px;
                font-weight: 500;
                background: #e3f2fd;
                color: #1565c0;
            }

            /* Contact Details */
            .contact-name-header {
                font-size: 22px;
                font-weight: 700;
                color: var(--primary-color);
                margin: 0 0 20px 0;
                padding-bottom: 12px;
                border-bottom: 2px solid var(--primary-color);
            }

            .details-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 24px;
                height: 100%;
            }

            .details-column {
                overflow-y: auto;
            }

            .details-column h3 {
                margin: 0 0 16px 0;
                font-size: 14px;
                color: #333;
                border-bottom: 1px solid var(--border-color);
                padding-bottom: 8px;
            }

            .detail-tile {
                margin-bottom: 20px;
                padding-bottom: 16px;
                border-bottom: 1px solid var(--border-color);
            }

            .detail-tile:last-child {
                border-bottom: none;
            }

            .tile-header {
                font-size: 13px;
                font-weight: 600;
                color: var(--primary-color);
                margin-bottom: 12px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .detail-section {
                margin-bottom: 12px;
            }

            .detail-label {
                font-size: 11px;
                color: #888;
                text-transform: uppercase;
                margin-bottom: 2px;
                display: flex;
                align-items: center;
                gap: 4px;
            }

            .field-icon {
                color: #aaa;
                vertical-align: middle;
                opacity: 0.7;
            }

            img.field-icon {
                width: 12px;
                height: 12px;
                object-fit: contain;
                filter: grayscale(100%);
            }

            i.field-icon {
                font-size: 12px;
                width: 12px;
                text-align: center;
            }

            .detail-value {
                font-size: 14px;
                white-space: pre-line;
            }

            .detail-value.empty-value {
                color: #999;
            }

            .detail-empty {
                color: #999;
                font-style: italic;
            }

            /* Activity/Comments list */
            .activity-list {
                margin-top: 0;
            }

            .activity-item {
                padding: 12px;
                background: var(--bg-color);
                border-radius: 6px;
                margin-bottom: 8px;
            }

            .activity-item.type-comment {
                border-left: 3px solid var(--primary-color);
            }

            .activity-item.type-activity {
                border-left: 3px solid #999;
            }

            .activity-author {
                font-weight: 600;
                font-size: 13px;
            }

            .activity-date {
                font-size: 11px;
                color: #666;
                margin-left: 8px;
            }

            .activity-type-badge {
                font-size: 10px;
                padding: 2px 6px;
                border-radius: 8px;
                margin-left: 8px;
                text-transform: uppercase;
            }

            .activity-type-badge.comment {
                background: #e3f2fd;
                color: #1565c0;
            }

            .activity-type-badge.activity {
                background: #f5f5f5;
                color: #666;
            }

            .activity-content {
                margin-top: 6px;
                font-size: 14px;
                white-space: pre-wrap;
            }

            .activity-content a {
                color: var(--primary-color);
                word-break: break-all;
            }

            .mention-tag {
                color: var(--primary-color);
                font-weight: 500;
                background: #e3f2fd;
                padding: 1px 4px;
                border-radius: 3px;
            }

            /* Comment input */
            .comment-input-section {
                margin-bottom: 16px;
                padding-bottom: 16px;
                border-bottom: 1px solid var(--border-color);
            }

            .comment-input-wrapper {
                position: relative;
            }

            .comment-textarea {
                width: 100%;
                min-height: 80px;
                padding: 10px;
                border: 1px solid var(--border-color);
                border-radius: 6px;
                font-size: 14px;
                font-family: inherit;
                resize: vertical;
            }

            .comment-textarea:focus {
                outline: none;
                border-color: var(--primary-color);
            }

            .comment-actions {
                display: flex;
                justify-content: flex-end;
                gap: 8px;
                margin-top: 8px;
            }

            .comment-submit-btn {
                background: var(--primary-color);
                color: white;
                border: none;
                padding: 8px 16px;
                border-radius: 4px;
                font-size: 13px;
                cursor: pointer;
                transition: background 0.2s ease;
            }

            .comment-submit-btn:hover {
                background: #2d5a7b;
            }

            .comment-submit-btn:disabled {
                background: #ccc;
                cursor: not-allowed;
            }

            /* @mention dropdown */
            .mention-dropdown {
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                background: white;
                border: 1px solid var(--border-color);
                border-radius: 6px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                max-height: 200px;
                overflow-y: auto;
                display: none;
                z-index: 1000;
                margin-top: 4px;
            }

            .mention-dropdown.show {
                display: block;
            }

            .mention-item {
                padding: 10px 12px;
                cursor: pointer;
                font-size: 14px;
            }

            .mention-item:hover,
            .mention-item.active {
                background: #e3f2fd;
            }

            /* Empty states */
            .empty-state {
                text-align: center;
                padding: 40px 20px;
                color: #666;
            }

            .empty-state-icon {
                font-size: 48px;
                margin-bottom: 16px;
            }

            /* Loading */
            .loading {
                text-align: center;
                padding: 40px;
            }

            .loading-spinner {
                width: 40px;
                height: 40px;
                border: 4px solid var(--border-color);
                border-top-color: var(--primary-color);
                border-radius: 50%;
                animation: spin 1s linear infinite;
            }

            @keyframes spin {
                to { transform: rotate(360deg); }
            }

            /* Success message */
            .success-toast {
                position: fixed;
                bottom: 20px;
                right: 20px;
                background: var(--success-color);
                color: white;
                padding: 16px 24px;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.2);
                display: none;
                z-index: 1000;
            }

            .success-toast.show {
                display: block;
                animation: slideIn 0.3s ease;
            }

            @keyframes slideIn {
                from {
                    transform: translateY(100px);
                    opacity: 0;
                }
                to {
                    transform: translateY(0);
                    opacity: 1;
                }
            }

            /* Responsive */
            @media (max-width: 1024px) {
                .details-grid {
                    grid-template-columns: 1fr;
                }
            }

            /* Mobile Layout */
            @media (max-width: 768px) {
                .my-contacts-container {
                    grid-template-columns: 1fr;
                    height: 100vh;
                    padding: 0;
                    gap: 0;
                }

                /* Hide contacts list on mobile when contact is selected */
                #contacts-panel.mobile-hidden {
                    display: none;
                }

                /* Hide details panel by default on mobile */
                #details-panel {
                    display: none;
                    position: fixed;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    z-index: 1000;
                    border-radius: 0;
                }

                #details-panel.mobile-visible {
                    display: flex;
                }

                .panel {
                    max-height: none;
                    height: 100vh;
                    border-radius: 0;
                }

                .panel-header {
                    position: sticky;
                    top: 0;
                    z-index: 10;
                }

                .mobile-back-btn {
                    background: none;
                    border: none;
                    font-size: 18px;
                    cursor: pointer;
                    padding: 4px 8px;
                    margin-right: 8px;
                }

                .details-grid {
                    grid-template-columns: 1fr;
                }
            }

            .mobile-only {
                display: none;
            }

            @media (max-width: 768px) {
                .mobile-only {
                    display: inline-block;
                }

                .desktop-only {
                    display: none;
                }
            }
        </style>
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

    /**
     * Footer JavaScript
     */
    public function footer_javascript() {
        ?>
        <script>
            const myContactsApp = {
                root: '<?php echo esc_url_raw( rest_url() ); ?>',
                nonce: '<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>',
                parts: <?php echo wp_json_encode( $this->parts ); ?>
            };

            let selectedContactId = null;
            let contacts = [];
            let filteredContacts = [];

            // Initialize
            document.addEventListener('DOMContentLoaded', function() {
                loadContacts();
            });

            // Load contacts
            async function loadContacts() {
                try {
                    const response = await fetch(
                        `${myContactsApp.root}${myContactsApp.parts.root}/v1/${myContactsApp.parts.type}/contacts`,
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': myContactsApp.nonce
                            },
                            body: JSON.stringify({
                                parts: myContactsApp.parts
                            })
                        }
                    );
                    const data = await response.json();
                    contacts = data.contacts || [];
                    filteredContacts = contacts;
                    renderContacts();
                } catch (error) {
                    console.error('Error loading contacts:', error);
                    document.getElementById('contacts-list').innerHTML =
                        '<div class="empty-state"><p>Error loading contacts</p></div>';
                }
            }

            // Render contacts list
            function renderContacts() {
                const container = document.getElementById('contacts-list');
                const count = document.getElementById('contacts-count');

                count.textContent = `(${filteredContacts.length})`;

                if (filteredContacts.length === 0) {
                    container.innerHTML = '<div class="empty-state"><p>No contacts found</p></div>';
                    return;
                }

                container.innerHTML = filteredContacts.map(contact => {
                    const statusStyle = contact.overall_status_color
                        ? `background: ${contact.overall_status_color}20; color: ${contact.overall_status_color};`
                        : '';

                    const sourceLabel = contact.source === 'subassigned' ? 'Subassigned' : 'Assigned';

                    return `
                        <div class="contact-item ${parseInt(selectedContactId) === contact.ID ? 'selected' : ''}"
                             data-contact-id="${contact.ID}"
                             onclick="selectContact(${contact.ID})">
                            <div class="contact-name">
                                ${escapeHtml(contact.name)}
                                ${contact.overall_status ? `<span class="status-badge" style="${statusStyle}">${escapeHtml(contact.overall_status)}</span>` : ''}
                            </div>
                            <div class="contact-meta">
                                ${contact.seeker_path ? escapeHtml(contact.seeker_path) + ' • ' : ''}
                                ${contact.last_modified ? escapeHtml(contact.last_modified) : ''}
                                <span class="source-badge">${sourceLabel}</span>
                            </div>
                        </div>
                    `;
                }).join('');
            }

            // Filter contacts by search term
            function filterContacts(searchTerm) {
                if (!searchTerm) {
                    filteredContacts = contacts;
                } else {
                    const term = searchTerm.toLowerCase();
                    filteredContacts = contacts.filter(c =>
                        c.name.toLowerCase().includes(term) ||
                        (c.overall_status && c.overall_status.toLowerCase().includes(term)) ||
                        (c.seeker_path && c.seeker_path.toLowerCase().includes(term))
                    );
                }
                renderContacts();
            }

            // Select contact and show details
            async function selectContact(contactId) {
                selectedContactId = contactId;

                // Highlight selected contact
                document.querySelectorAll('.contact-item').forEach(el => {
                    el.classList.toggle('selected', parseInt(el.dataset.contactId) === contactId);
                });

                // Show details panel on mobile
                if (isMobile()) {
                    document.getElementById('details-panel').classList.add('mobile-visible');
                    document.getElementById('contacts-panel').classList.add('mobile-hidden');
                }

                // Show loading
                const detailsContainer = document.getElementById('contact-details');
                detailsContainer.innerHTML = '<div class="loading"><div class="loading-spinner" style="margin: 0 auto;"></div></div>';

                try {
                    const response = await fetch(
                        `${myContactsApp.root}${myContactsApp.parts.root}/v1/${myContactsApp.parts.type}/contact`,
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': myContactsApp.nonce
                            },
                            body: JSON.stringify({
                                contact_id: contactId,
                                parts: myContactsApp.parts
                            })
                        }
                    );
                    const contact = await response.json();

                    if (contact.code) {
                        detailsContainer.innerHTML = `<div class="empty-state"><p>Error: ${escapeHtml(contact.message || 'Unknown error')}</p></div>`;
                        return;
                    }

                    renderContactDetails(contact);

                    // Update mobile header
                    document.getElementById('mobile-contact-name').textContent = contact.name;
                } catch (error) {
                    console.error('Error loading contact details:', error);
                    detailsContainer.innerHTML = '<div class="empty-state"><p>Error loading details</p></div>';
                }
            }

            // Render contact details
            function renderContactDetails(contact) {
                const container = document.getElementById('contact-details');

                // Render tiles with their fields
                const tilesHtml = contact.tiles && contact.tiles.length > 0 ?
                    contact.tiles.map(tile => `
                        <div class="detail-tile">
                            <div class="tile-header">${escapeHtml(tile.label)}</div>
                            ${tile.fields.map(field => `
                                <div class="detail-section">
                                    <div class="detail-label">${renderFieldIcon(field)}${escapeHtml(field.label)}</div>
                                    <div class="detail-value ${!field.value ? 'empty-value' : ''}">${field.value ? escapeHtml(field.value) : '-'}</div>
                                </div>
                            `).join('')}
                        </div>
                    `).join('') :
                    '<p class="detail-empty">No contact information available</p>';

                // Render record info
                const recordInfoHtml = `
                    <div class="detail-tile">
                        <div class="tile-header">Record Info</div>
                        ${contact.created ? `
                            <div class="detail-section">
                                <div class="detail-label">Created</div>
                                <div class="detail-value">${escapeHtml(contact.created)}</div>
                            </div>
                        ` : ''}
                        ${contact.last_modified ? `
                            <div class="detail-section">
                                <div class="detail-label">Last Modified</div>
                                <div class="detail-value">${escapeHtml(contact.last_modified)}</div>
                            </div>
                        ` : ''}
                    </div>
                `;

                // Render activity/comments timeline
                const activityHtml = contact.activity && contact.activity.length > 0 ?
                    contact.activity.map(item => `
                        <div class="activity-item type-${item.type}">
                            <span class="activity-author">${escapeHtml(item.author)}</span>
                            <span class="activity-date">${escapeHtml(item.date)}</span>
                            <span class="activity-type-badge ${item.type}">${item.type}</span>
                            <div class="activity-content">${formatActivityContent(item.content)}</div>
                        </div>
                    `).join('') :
                    '<p class="detail-empty">No activity yet</p>';

                container.innerHTML = `
                    <div class="details-grid">
                        <div class="details-column">
                            <h2 class="contact-name-header">${escapeHtml(contact.name)}</h2>
                            ${tilesHtml}
                            ${recordInfoHtml}
                        </div>

                        <div class="details-column">
                            <h3>Comments & Activity</h3>
                            <div class="comment-input-section">
                                <div class="comment-input-wrapper">
                                    <div class="mention-dropdown" id="mention-dropdown"></div>
                                    <textarea class="comment-textarea" id="comment-textarea" placeholder="Type your comment... Use @ to mention users"></textarea>
                                </div>
                                <div class="comment-actions">
                                    <button class="comment-submit-btn" id="comment-submit-btn" onclick="submitComment()">Add Comment</button>
                                </div>
                            </div>
                            <div class="activity-list">
                                ${activityHtml}
                            </div>
                        </div>
                    </div>
                `;

                // Initialize mention listeners
                initMentionListeners();
            }

            // Format activity content (mentions and links)
            function formatActivityContent(text) {
                if (!text) return '';

                let formatted = escapeHtml(text);

                // Format @mentions: @[Name](id) -> styled span
                formatted = formatted.replace(
                    /@\[([^\]]+)\]\((\d+)\)/g,
                    '<span class="mention-tag">@$1</span>'
                );

                // Format URLs to clickable links
                const urlRegex = /(https?:\/\/[^\s<]+)/g;
                formatted = formatted.replace(
                    urlRegex,
                    '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>'
                );

                return formatted;
            }

            // Comment submission
            async function submitComment() {
                const textarea = document.getElementById('comment-textarea');
                const submitBtn = document.getElementById('comment-submit-btn');
                const comment = textarea.value.trim();

                if (!comment || !selectedContactId) return;

                submitBtn.disabled = true;
                submitBtn.textContent = 'Posting...';

                try {
                    const response = await fetch(
                        `${myContactsApp.root}${myContactsApp.parts.root}/v1/${myContactsApp.parts.type}/comment`,
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': myContactsApp.nonce
                            },
                            body: JSON.stringify({
                                contact_id: selectedContactId,
                                comment: comment,
                                parts: myContactsApp.parts
                            })
                        }
                    );

                    const result = await response.json();

                    if (result.success || result.comment_id) {
                        textarea.value = '';
                        selectContact(selectedContactId);
                        showSuccessToast('Comment added successfully!');
                    } else {
                        alert('Failed to add comment: ' + (result.message || 'Unknown error'));
                    }
                } catch (error) {
                    console.error('Error posting comment:', error);
                    alert('Error posting comment');
                } finally {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Add Comment';
                }
            }

            // @mention functionality
            let mentionSearchTimeout = null;
            let mentionStartPos = -1;
            let mentionUsers = [];
            let mentionActiveIndex = 0;

            function initMentionListeners() {
                const commentTextarea = document.getElementById('comment-textarea');
                const mentionDropdown = document.getElementById('mention-dropdown');

                if (!commentTextarea || !mentionDropdown) return;

                commentTextarea.addEventListener('input', function(e) {
                    const text = this.value;
                    const cursorPos = this.selectionStart;

                    const textBeforeCursor = text.substring(0, cursorPos);
                    const lastAtIndex = textBeforeCursor.lastIndexOf('@');

                    if (lastAtIndex !== -1) {
                        const textAfterAt = textBeforeCursor.substring(lastAtIndex + 1);

                        if (!textAfterAt.includes(' ') && !textAfterAt.includes('\n')) {
                            mentionStartPos = lastAtIndex;

                            clearTimeout(mentionSearchTimeout);
                            mentionSearchTimeout = setTimeout(() => {
                                searchMentionUsers(textAfterAt);
                            }, 200);
                            return;
                        }
                    }

                    hideMentionDropdown();
                });

                commentTextarea.addEventListener('keydown', function(e) {
                    const dropdown = document.getElementById('mention-dropdown');
                    if (!dropdown || !dropdown.classList.contains('show')) return;

                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        mentionActiveIndex = Math.min(mentionActiveIndex + 1, mentionUsers.length - 1);
                        renderMentionDropdown();
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        mentionActiveIndex = Math.max(mentionActiveIndex - 1, 0);
                        renderMentionDropdown();
                    } else if (e.key === 'Enter' && mentionUsers.length > 0) {
                        e.preventDefault();
                        selectMention(mentionUsers[mentionActiveIndex]);
                    } else if (e.key === 'Escape') {
                        hideMentionDropdown();
                    }
                });
            }

            async function searchMentionUsers(search) {
                if (search.length < 1) {
                    hideMentionDropdown();
                    return;
                }

                try {
                    const response = await fetch(
                        `${myContactsApp.root}${myContactsApp.parts.root}/v1/${myContactsApp.parts.type}/users-mention`,
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': myContactsApp.nonce
                            },
                            body: JSON.stringify({
                                search: search,
                                parts: myContactsApp.parts
                            })
                        }
                    );

                    const data = await response.json();
                    mentionUsers = data.users || [];
                    mentionActiveIndex = 0;

                    if (mentionUsers.length > 0) {
                        renderMentionDropdown();
                        document.getElementById('mention-dropdown').classList.add('show');
                    } else {
                        hideMentionDropdown();
                    }
                } catch (error) {
                    console.error('Error searching users:', error);
                    hideMentionDropdown();
                }
            }

            function renderMentionDropdown() {
                const dropdown = document.getElementById('mention-dropdown');
                if (!dropdown) return;

                dropdown.innerHTML = mentionUsers.map((user, index) => `
                    <div class="mention-item ${index === mentionActiveIndex ? 'active' : ''}"
                         onclick="selectMention(mentionUsers[${index}])">
                        <span class="mention-name">${escapeHtml(user.display_name)}</span>
                    </div>
                `).join('');
            }

            function selectMention(user) {
                const textarea = document.getElementById('comment-textarea');
                const text = textarea.value;
                const cursorPos = textarea.selectionStart;

                const beforeMention = text.substring(0, mentionStartPos);
                const afterCursor = text.substring(cursorPos);

                const mentionText = `@[${user.display_name}](${user.ID}) `;
                textarea.value = beforeMention + mentionText + afterCursor;

                const newCursorPos = beforeMention.length + mentionText.length;
                textarea.setSelectionRange(newCursorPos, newCursorPos);
                textarea.focus();

                hideMentionDropdown();
            }

            function hideMentionDropdown() {
                const dropdown = document.getElementById('mention-dropdown');
                if (dropdown) {
                    dropdown.classList.remove('show');
                }
                mentionUsers = [];
                mentionStartPos = -1;
            }

            // Mobile functionality
            function isMobile() {
                return window.innerWidth <= 768;
            }

            function hideMobileDetails() {
                document.getElementById('details-panel').classList.remove('mobile-visible');
                document.getElementById('contacts-panel').classList.remove('mobile-hidden');
            }

            // Render field icon (image URL or font-icon class)
            function renderFieldIcon(field) {
                // Check for image icon first (URL)
                if (field.icon && !field.icon.includes('undefined')) {
                    return `<img class="field-icon" src="${escapeHtml(field.icon)}" alt="" width="12" height="12">`;
                }
                // Check for font icon (CSS class like mdi mdi-account)
                if (field.font_icon && !field.font_icon.includes('undefined')) {
                    return `<i class="${escapeHtml(field.font_icon)} field-icon"></i>`;
                }
                return '';
            }

            // Utility: escape HTML
            function escapeHtml(text) {
                if (!text) return '';
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            // Success toast
            function showSuccessToast(message = 'Success!') {
                const toast = document.getElementById('success-toast');
                toast.textContent = message;
                toast.classList.add('show');
                setTimeout(() => {
                    toast.classList.remove('show');
                }, 3000);
            }
        </script>
        <?php
    }
}

// Initialize the magic link
Disciple_Tools_Homescreen_Apps_My_Contacts_Magic_Link::instance();
