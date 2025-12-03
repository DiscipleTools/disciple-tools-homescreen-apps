<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
} // Exit if accessed directly.

/**
 * Class Disciple_Tools_Homescreen_Apps_Dispatcher_Magic_Link
 *
 * A standalone magic link for dispatchers to assign unassigned contacts to users.
 * Features a three-panel layout with drag-and-drop assignment.
 */
class Disciple_Tools_Homescreen_Apps_Dispatcher_Magic_Link extends DT_Magic_Url_Base {

    public $page_title = 'Dispatcher';
    public $page_description = 'Assign unassigned contacts to users.';
    public $root = 'homescreen_apps';
    public $type = 'dispatcher';
    public $post_type = 'user';
    private $meta_key = '';

    private static $_instance = null;

    public $meta = [];
    public $translatable = [ 'query', 'user' ];

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
            'contacts_only'     => false,
            'supports_create'   => false,
            'icon'              => 'mdi mdi-account-switch',
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

        // Require user to be logged in
        if ( ! is_user_logged_in() ) {
            wp_redirect( dt_login_url( 'login', '?redirect_to=' . rawurlencode( site_url( dt_get_url_path() ) ) ) );
            exit;
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
     * Note: Using POST for all data fetching to avoid caching issues with WordPress hosting services
     */
    public function add_endpoints() {
        $namespace = $this->root . '/v1';

        $permission_callback = function () {
            return is_user_logged_in();
        };

        register_rest_route(
            $namespace, '/' . $this->type . '/contacts', [
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'get_unassigned_contacts' ],
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
            $namespace, '/' . $this->type . '/users', [
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'get_users_with_workload' ],
                    'permission_callback' => $permission_callback,
                ],
            ]
        );

        register_rest_route(
            $namespace, '/' . $this->type . '/assign', [
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'assign_contact_to_user' ],
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
    }

    /**
     * Get unassigned contacts
     */
    public function get_unassigned_contacts( WP_REST_Request $request ) {
        $contacts = DT_Posts::list_posts( 'contacts', [
            'overall_status' => [ 'unassigned' ],
            'sort'           => '-post_date',
            'limit'          => 100,
        ], true );

        $result = [];
        if ( ! is_wp_error( $contacts ) && isset( $contacts['posts'] ) ) {
            foreach ( $contacts['posts'] as $contact ) {
                $location = '';
                if ( ! empty( $contact['location_grid'] ) ) {
                    $location = $contact['location_grid'][0]['label'] ?? '';
                }

                $age_days = 0;
                if ( ! empty( $contact['post_date']['timestamp'] ) ) {
                    $age_days = max( 0, floor( ( time() - $contact['post_date']['timestamp'] ) / 86400 ) );
                }

                $result[] = [
                    'ID'       => $contact['ID'],
                    'name'     => $contact['name'] ?? 'Unknown',
                    'location' => $location,
                    'age_days' => $age_days,
                    'source'   => $contact['sources'][0]['label'] ?? '',
                ];
            }
        }

        return [
            'contacts' => $result,
            'total'    => count( $result ),
        ];
    }

    /**
     * Get contact details
     */
    public function get_contact_details( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        $params = dt_recursive_sanitize_array( $params );
        $contact_id = intval( $params['contact_id'] ?? 0 );

        $contact = DT_Posts::get_post( 'contacts', $contact_id, true, true );
        if ( is_wp_error( $contact ) ) {
            return new WP_Error( 'contact_not_found', 'Contact not found', [ 'status' => 404 ] );
        }

        $field_settings = DT_Posts::get_post_field_settings( 'contacts' );
        $tile_settings = DT_Posts::get_post_tiles( 'contacts' );
        $comments = DT_Posts::get_post_comments( 'contacts', $contact_id, true, 'all', [ 'number' => 50 ] );

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
            if ( empty( $field_setting['tile'] ) ) {
                continue;
            }

            $tile_key = $field_setting['tile'];

            // Skip if tile doesn't exist in tile settings
            if ( ! isset( $tile_settings[ $tile_key ] ) ) {
                continue;
            }

            // Skip if no data for this field
            if ( ! isset( $contact[ $field_key ] ) || empty( $contact[ $field_key ] ) ) {
                continue;
            }

            $value = $contact[ $field_key ];
            $formatted_value = $this->format_field_value( $value, $field_setting );

            // Skip if formatted value is empty
            if ( $formatted_value === '' || $formatted_value === null || ( is_array( $formatted_value ) && empty( $formatted_value ) ) ) {
                continue;
            }

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
                'key'   => $field_key,
                'label' => $field_setting['name'] ?? $field_key,
                'value' => $formatted_value,
                'type'  => $field_setting['type'] ?? 'text',
                'order' => is_numeric( $field_order ) ? intval( $field_order ) : 100,
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

        $age_days = 0;
        if ( ! empty( $contact['post_date']['timestamp'] ) ) {
            $age_days = max( 0, floor( ( time() - $contact['post_date']['timestamp'] ) / 86400 ) );
        }

        // Extract location grid IDs for matching
        $location_ids = [];
        if ( ! empty( $contact['location_grid'] ) ) {
            foreach ( $contact['location_grid'] as $loc ) {
                if ( isset( $loc['id'] ) ) {
                    $location_ids[] = $loc['id'];
                }
            }
        }

        // Extract language keys for matching
        $language_keys = [];
        if ( ! empty( $contact['languages'] ) ) {
            foreach ( $contact['languages'] as $lang ) {
                if ( is_string( $lang ) ) {
                    $language_keys[] = $lang;
                } elseif ( isset( $lang['key'] ) ) {
                    $language_keys[] = $lang['key'];
                }
            }
        }

        return [
            'ID'           => $contact['ID'],
            'name'         => $contact['name'] ?? 'Unknown',
            'tiles'        => $tiles,
            'age_days'     => $age_days,
            'created'      => $contact['post_date']['formatted'] ?? '',
            'comments'     => $comments['comments'] ?? [],
            'location_ids' => $location_ids,
            'languages'    => $language_keys,
        ];
    }

    /**
     * Format field value based on field type
     */
    private function format_field_value( $value, $field_setting ) {
        $type = $field_setting['type'] ?? 'text';

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
                            // Look up label from field options
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
            case 'location_grid_meta':
                if ( is_array( $value ) ) {
                    $locations = [];
                    foreach ( $value as $item ) {
                        if ( isset( $item['label'] ) ) {
                            $locations[] = $item['label'];
                        }
                    }
                    return implode( ', ', $locations );
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
     * Get users with workload data
     */
    public function get_users_with_workload( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        $params = dt_recursive_sanitize_array( $params );

        // Get contact location and language data for matching
        $contact_location_ids = $params['contact_location_ids'] ?? [];
        $contact_languages = $params['contact_languages'] ?? [];

        // Use DT_User_Management if available, otherwise query directly
        if ( class_exists( 'DT_User_Management' ) ) {
            $users_data = DT_User_Management::get_users( false );
        } else {
            $users_data = $this->get_users_fallback();
        }

        // Get location proximity data if contact has locations
        $location_data = [];
        if ( ! empty( $contact_location_ids ) ) {
            $location_data = $this->get_location_proximity_data( $contact_location_ids );
        }

        $result = [];
        foreach ( $users_data as $user_id => $user ) {
            // Only include multipliers
            $roles = maybe_unserialize( $user['roles'] ?? '' );
            if ( ! is_array( $roles ) || ! in_array( 'multiplier', $roles ) ) {
                continue;
            }

            // Get user status
            $user_status = $user['user_status'] ?? '';
            $workload_status = $user['workload_status'] ?? '';

            // Get locations
            $locations = [];
            $user_location = Disciple_Tools_Users::get_user_location( $user_id );
            if ( ! empty( $user_location ) && isset( $user_location['location_grid'] ) ) {
                foreach ( $user_location['location_grid'] as $loc ) {
                    $locations[] = $loc['label'] ?? '';
                }
            }

            // Get user languages
            $user_languages = get_user_option( 'user_languages', $user_id );
            if ( ! is_array( $user_languages ) ) {
                $user_languages = [];
            }

            // Check for language match
            $language_match = false;
            if ( ! empty( $contact_languages ) && ! empty( $user_languages ) ) {
                $language_match = count( array_intersect( $contact_languages, $user_languages ) ) > 0;
            }

            // Check for location match
            $location_match = false;
            $location_level = null;
            $best_location_match = '';
            if ( isset( $location_data[ $user_id ] ) ) {
                $location_match = true;
                $location_level = $location_data[ $user_id ]['level'];
                $best_location_match = $location_data[ $user_id ]['match_name'];
            }

            // Calculate match score (lower is better)
            // Location exact match = 0, parent = 1, etc. No match = 100
            // Language match subtracts 50 from score
            $match_score = 100;
            if ( $location_match ) {
                $match_score = $location_level;
            }
            if ( $language_match ) {
                $match_score -= 50;
            }

            $result[] = [
                'ID'                  => intval( $user_id ),
                'display_name'        => $user['display_name'] ?? 'Unknown',
                'user_status'         => $user_status,
                'workload_status'     => $workload_status,
                'locations'           => $locations,
                'active_contacts'     => intval( $user['number_active'] ?? 0 ),
                'assigned_contacts'   => intval( $user['number_assigned_to'] ?? 0 ),
                'pending_contacts'    => intval( $user['number_new_assigned'] ?? 0 ),
                'language_match'      => $language_match,
                'location_match'      => $location_match,
                'location_level'      => $location_level,
                'best_location_match' => $best_location_match,
                'match_score'         => $match_score,
            ];
        }

        // Sort by match score first (lower is better), then by active contacts
        usort( $result, function( $a, $b ) {
            if ( $a['match_score'] !== $b['match_score'] ) {
                return $a['match_score'] - $b['match_score'];
            }
            return $a['active_contacts'] - $b['active_contacts'];
        });

        return [
            'users' => $result,
            'total' => count( $result ),
        ];
    }

    /**
     * Calculate location proximity data for users based on contact locations
     * Adapted from DT_Contacts_Access::get_location_data()
     */
    private function get_location_proximity_data( $location_ids ) {
        global $wpdb;
        $location_data = [];

        foreach ( $location_ids as $grid_id ) {
            $location = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $wpdb->dt_location_grid WHERE grid_id = %s",
                esc_sql( $grid_id )
            ), ARRAY_A );

            if ( ! $location ) {
                continue;
            }

            $levels = [];

            if ( $grid_id === '1' ) {
                $match_location_ids = '( 1 )';
            } else {
                $match_location_ids = '( ';
                for ( $i = 0; $i <= ( (int) $location['level'] ); $i++ ) {
                    $admin_key = 'admin' . $i . '_grid_id';
                    if ( isset( $location[ $admin_key ] ) ) {
                        $levels[ $location[ $admin_key ] ] = [ 'level' => $i ];
                        $match_location_ids .= $location[ $admin_key ] . ', ';
                    }
                }
                $match_location_ids .= ')';
            }

            $match_location_ids = str_replace( ', )', ' )', $match_location_ids );

            // Get location names
            //phpcs:disable
            $location_names = $wpdb->get_results( "
                SELECT alt_name, grid_id
                FROM $wpdb->dt_location_grid
                WHERE grid_id IN $match_location_ids
            ", ARRAY_A );

            // Get users with matching locations
            $users_in_location = $wpdb->get_results( $wpdb->prepare( "
                SELECT user_id, meta_value as grid_id
                FROM $wpdb->usermeta um
                WHERE um.meta_key = %s
                AND um.meta_value IN $match_location_ids
            ", $wpdb->prefix . 'location_grid' ), ARRAY_A );
            //phpcs:enable

            foreach ( $location_names as $l ) {
                if ( isset( $levels[ $l['grid_id'] ] ) ) {
                    $levels[ $l['grid_id'] ]['name'] = $l['alt_name'];
                }
            }

            // Calculate proximity level for each user
            // 0 = exact match, 1 = parent match, etc.
            foreach ( $users_in_location as $l ) {
                $level = (int) $location['level'] - $levels[ $l['grid_id'] ]['level'];
                if ( ! isset( $location_data[ $l['user_id'] ] ) || $location_data[ $l['user_id'] ]['level'] > $level ) {
                    $location_data[ $l['user_id'] ] = [
                        'level'      => $level,
                        'match_name' => $levels[ $l['grid_id'] ]['name'] ?? '',
                    ];
                }
            }
        }

        return $location_data;
    }

    /**
     * Fallback method to get users if DT_User_Management is not available
     */
    private function get_users_fallback() {
        global $wpdb;
        $users = [];

        $users_query = $wpdb->get_results( $wpdb->prepare( "
            SELECT users.ID,
                users.display_name,
                um.meta_value as roles
            FROM $wpdb->users as users
            INNER JOIN $wpdb->usermeta as um on ( um.user_id = users.ID AND um.meta_key = %s )
            GROUP by users.ID, um.meta_value
        ", $wpdb->prefix . 'capabilities' ), ARRAY_A );

        foreach ( $users_query as $user ) {
            $users[ $user['ID'] ] = $user;
            $users[ $user['ID'] ]['number_active'] = 0;
            $users[ $user['ID'] ]['number_assigned_to'] = 0;
            $users[ $user['ID'] ]['number_new_assigned'] = 0;
        }

        return $users;
    }

    /**
     * Assign contact to user
     */
    public function assign_contact_to_user( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        $params = dt_recursive_sanitize_array( $params );

        $contact_id = intval( $params['contact_id'] ?? 0 );
        $user_id = intval( $params['user_id'] ?? 0 );

        if ( ! $contact_id || ! $user_id ) {
            return new WP_Error( 'missing_params', 'Contact ID and User ID are required', [ 'status' => 400 ] );
        }

        $result = DT_Posts::update_post( 'contacts', $contact_id, [
            'assigned_to'    => $user_id,
            'overall_status' => 'assigned',
        ], true, true );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return [
            'success'    => true,
            'contact_id' => $contact_id,
            'user_id'    => $user_id,
            'message'    => 'Contact assigned successfully',
        ];
    }

    /**
     * Add a comment to a contact
     */
    public function add_comment( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        $params = dt_recursive_sanitize_array( $params );

        $contact_id = intval( $params['contact_id'] ?? 0 );
        $comment = $params['comment'] ?? '';

        if ( ! $contact_id || empty( $comment ) ) {
            return new WP_Error( 'missing_params', 'Contact ID and comment are required', [ 'status' => 400 ] );
        }

        $result = DT_Posts::add_post_comment( 'contacts', $contact_id, $comment, 'comment', [], true, true );

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

            .dispatcher-container {
                display: grid;
                grid-template-columns: 320px 280px 1fr;
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

            /* Contact List */
            .contact-item {
                padding: 12px;
                border: 1px solid var(--border-color);
                border-radius: 6px;
                margin-bottom: 8px;
                cursor: grab;
                background: var(--card-bg);
                transition: all 0.2s ease;
            }

            .contact-item:hover {
                border-color: var(--primary-color);
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            }

            .contact-item.dragging {
                opacity: 0.5;
                cursor: grabbing;
            }

            .contact-item.selected {
                border-color: var(--primary-color);
                background: #e3f2fd;
            }

            .contact-name {
                font-weight: 600;
                margin-bottom: 4px;
            }

            .contact-meta {
                font-size: 12px;
                color: #666;
            }

            .contact-age {
                display: inline-block;
                padding: 2px 6px;
                border-radius: 10px;
                font-size: 11px;
                margin-left: 8px;
            }

            .contact-age.fresh { background: #e8f5e9; color: #2e7d32; }
            .contact-age.moderate { background: #fff3e0; color: #ef6c00; }
            .contact-age.old { background: #ffebee; color: #c62828; }

            /* Contact Details - Two Column Layout */
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

            .contact-name-header {
                font-size: 22px;
                font-weight: 700;
                color: var(--primary-color);
                margin: 0 0 20px 0;
                padding-bottom: 12px;
                border-bottom: 2px solid var(--primary-color);
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
            }

            .detail-value {
                font-size: 14px;
            }

            .detail-empty {
                color: #999;
                font-style: italic;
            }

            .comment-list {
                margin-top: 0;
            }

            .comment-item {
                padding: 12px;
                background: var(--bg-color);
                border-radius: 6px;
                margin-bottom: 8px;
            }

            .comment-author {
                font-weight: 600;
                font-size: 13px;
            }

            .comment-date {
                font-size: 11px;
                color: #666;
                margin-left: 8px;
            }

            .comment-content {
                margin-top: 6px;
                font-size: 14px;
                white-space: pre-wrap;
            }

            .comment-content a {
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

            @media (max-width: 1200px) {
                .details-grid {
                    grid-template-columns: 1fr;
                }
            }

            /* User List */
            .user-card {
                padding: 12px;
                border: 2px dashed var(--border-color);
                border-radius: 6px;
                margin-bottom: 8px;
                transition: all 0.2s ease;
            }

            .user-card.drag-over {
                border-color: var(--primary-color);
                background: #e3f2fd;
            }

            .user-name {
                font-weight: 600;
                margin-bottom: 8px;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .status-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 10px;
                font-size: 11px;
                font-weight: 500;
            }

            .status-badge.away { background: #fff3e0; color: #ef6c00; }
            .status-badge.inconsistent { background: #fce4ec; color: #c2185b; }
            .status-badge.inactive { background: #eeeeee; color: #616161; }

            .workload-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 10px;
                font-size: 11px;
                font-weight: 500;
            }

            .workload-badge.green { background: #e8f5e9; color: #2e7d32; }
            .workload-badge.yellow { background: #fff3e0; color: #ef6c00; }
            .workload-badge.red { background: #ffebee; color: #c62828; }

            .match-badges {
                display: flex;
                gap: 6px;
                margin-top: 6px;
                flex-wrap: wrap;
            }

            .match-badge {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                padding: 3px 8px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 500;
            }

            .match-badge.location {
                background: #e3f2fd;
                color: #1565c0;
            }

            .match-badge.location.exact {
                background: #c8e6c9;
                color: #2e7d32;
            }

            .match-badge.language {
                background: #f3e5f5;
                color: #7b1fa2;
            }

            .user-stats {
                display: flex;
                gap: 16px;
                font-size: 12px;
                color: #666;
                margin-top: 8px;
            }

            .user-locations {
                font-size: 12px;
                color: #666;
                margin-top: 4px;
            }

            .user-actions {
                margin-top: 8px;
            }

            .assign-btn {
                background: var(--primary-color);
                color: white;
                border: none;
                padding: 6px 12px;
                border-radius: 4px;
                font-size: 12px;
                cursor: pointer;
                transition: background 0.2s ease;
            }

            .assign-btn:hover {
                background: #2d5a7b;
            }

            .assign-btn:disabled {
                background: #ccc;
                cursor: not-allowed;
            }

            /* Search input */
            #user-search {
                display: block;
                width: 100%;
                margin-top: 8px;
                padding: 8px 12px;
                border: 1px solid var(--border-color);
                border-radius: 4px;
                font-size: 14px;
            }

            #user-search:focus {
                outline: none;
                border-color: var(--primary-color);
            }

            /* Open in D.T. button */
            .open-dt-btn {
                float: right;
                background: var(--primary-color);
                color: white;
                padding: 4px 10px;
                border-radius: 4px;
                font-size: 12px;
                font-weight: normal;
                text-decoration: none;
                transition: background 0.2s ease;
            }

            .open-dt-btn:hover {
                background: #2d5a7b;
                color: white;
            }

            /* Comment input */
            .comment-input-inline {
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

            .mention-item .mention-name {
                font-weight: 500;
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
                .dispatcher-container {
                    grid-template-columns: 1fr;
                    height: auto;
                }

                .panel {
                    max-height: 400px;
                }
            }

            /* Mobile Layout */
            @media (max-width: 768px) {
                .dispatcher-container {
                    grid-template-columns: 1fr;
                    height: 100vh;
                    padding: 0;
                    gap: 0;
                }

                /* Hide contacts list on mobile */
                #contacts-panel {
                    display: none;
                }

                /* Hide users panel by default on mobile */
                #users-panel {
                    display: none;
                    position: fixed;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    z-index: 1000;
                    max-height: none;
                    border-radius: 0;
                }

                #users-panel.mobile-visible {
                    display: flex;
                }

                /* Details panel full screen on mobile */
                #details-panel {
                    max-height: none;
                    height: 100vh;
                    border-radius: 0;
                }

                .panel-header {
                    position: sticky;
                    top: 0;
                    z-index: 10;
                }

                /* Mobile navigation */
                .mobile-nav {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                }

                .mobile-nav-btn {
                    background: var(--primary-color);
                    color: white;
                    border: none;
                    padding: 6px 12px;
                    border-radius: 4px;
                    font-size: 12px;
                    cursor: pointer;
                }

                .mobile-nav-btn:disabled {
                    background: #ccc;
                    cursor: not-allowed;
                }

                .mobile-nav-counter {
                    font-size: 12px;
                    color: #666;
                    font-weight: normal;
                }

                .mobile-assign-btn {
                    background: var(--success-color);
                    color: white;
                    border: none;
                    padding: 6px 14px;
                    border-radius: 4px;
                    font-size: 13px;
                    font-weight: 500;
                    cursor: pointer;
                    margin-left: auto;
                }

                .mobile-close-btn {
                    background: #666;
                    color: white;
                    border: none;
                    padding: 8px 16px;
                    border-radius: 4px;
                    font-size: 14px;
                    cursor: pointer;
                    margin-left: auto;
                }

                #users-panel .panel-header {
                    display: flex;
                    flex-wrap: wrap;
                    align-items: center;
                    gap: 8px;
                }

                #users-panel .panel-header #user-search {
                    width: 100%;
                    order: 3;
                }

                #details-panel .panel-header {
                    display: flex;
                    flex-wrap: wrap;
                    align-items: center;
                    gap: 8px;
                }

                #details-panel .open-dt-btn {
                    order: 2;
                    margin-left: 0;
                    padding: 6px 10px;
                    font-size: 11px;
                }

                .details-grid {
                    grid-template-columns: 1fr;
                }
            }

            /* Show mobile elements only on mobile */
            .mobile-only {
                display: none;
            }

            @media (max-width: 768px) {
                .mobile-only {
                    display: flex;
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
        <div class="dispatcher-container">
            <!-- Left Panel: Users List -->
            <div class="panel" id="users-panel">
                <div class="panel-header">
                    <span>Multipliers <span id="users-count">(0)</span></span>
                    <button class="mobile-close-btn mobile-only" onclick="hideMobileUsersList()">Close</button>
                    <input type="text" id="user-search" placeholder="Search multipliers..." oninput="filterUsers(this.value)">
                </div>
                <div class="panel-content" id="users-list">
                    <div class="empty-state">
                        <div class="empty-state-icon">👉</div>
                        <p>Select a contact to see matching multipliers</p>
                    </div>
                </div>
            </div>

            <!-- Center Panel: Contacts List -->
            <div class="panel" id="contacts-panel">
                <div class="panel-header">
                    Dispatch Needed <span id="contacts-count">(0)</span>
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
                    <span class="desktop-only">Contact Details</span>
                    <a href="#" id="open-in-dt-btn" class="open-dt-btn" target="_blank" style="display: none;">Open in D.T.</a>

                    <!-- Mobile navigation -->
                    <div class="mobile-nav mobile-only">
                        <button class="mobile-nav-btn" id="mobile-prev-btn" onclick="navigateContact(-1)" disabled>&larr; Prev</button>
                        <span class="mobile-nav-counter" id="mobile-counter">0 / 0</span>
                        <button class="mobile-nav-btn" id="mobile-next-btn" onclick="navigateContact(1)">Next &rarr;</button>
                        <button class="mobile-assign-btn" id="mobile-assign-btn" onclick="showMobileUsersList()">Assign</button>
                    </div>
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
            Contact assigned successfully!
        </div>

        <?php
    }

    /**
     * Footer JavaScript
     */
    public function footer_javascript() {
        ?>
        <script>
            const dispatcherApp = {
                root: '<?php echo esc_url_raw( rest_url() ); ?>',
                nonce: '<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>',
                parts: <?php echo json_encode( $this->parts ); ?>,
                site_url: '<?php echo esc_url( site_url() ); ?>'
            };

            let selectedContactId = null;
            let selectedContactLocationIds = [];
            let selectedContactLanguages = [];
            let contacts = [];
            let users = [];

            // Initialize
            document.addEventListener('DOMContentLoaded', function() {
                loadContacts();
            });

            // Load unassigned contacts
            async function loadContacts() {
                try {
                    const response = await fetch(
                        `${dispatcherApp.root}${dispatcherApp.parts.root}/v1/${dispatcherApp.parts.type}/contacts`,
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': dispatcherApp.nonce
                            },
                            body: JSON.stringify({
                                parts: dispatcherApp.parts
                            })
                        }
                    );
                    const data = await response.json();
                    contacts = data.contacts || [];
                    renderContacts();
                } catch (error) {
                    console.error('Error loading contacts:', error);
                    document.getElementById('contacts-list').innerHTML =
                        '<div class="empty-state"><p>Error loading contacts</p></div>';
                }
            }

            // Load users with workload (pass contact data for matching)
            async function loadUsers(contactLocationIds = [], contactLanguages = []) {
                // Show loading spinner
                document.getElementById('users-list').innerHTML =
                    '<div class="loading"><div class="loading-spinner" style="margin: 0 auto;"></div><p>Finding matching multipliers...</p></div>';

                try {
                    const response = await fetch(
                        `${dispatcherApp.root}${dispatcherApp.parts.root}/v1/${dispatcherApp.parts.type}/users`,
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': dispatcherApp.nonce
                            },
                            body: JSON.stringify({
                                parts: dispatcherApp.parts,
                                contact_location_ids: contactLocationIds,
                                contact_languages: contactLanguages
                            })
                        }
                    );
                    const data = await response.json();
                    users = data.users || [];
                    const searchTerm = document.getElementById('user-search').value;
                    renderUsers(searchTerm);
                } catch (error) {
                    console.error('Error loading users:', error);
                    document.getElementById('users-list').innerHTML =
                        '<div class="empty-state"><p>Error loading users</p></div>';
                }
            }

            // Render contacts list
            function renderContacts() {
                const container = document.getElementById('contacts-list');
                const count = document.getElementById('contacts-count');

                count.textContent = `(${contacts.length})`;

                if (contacts.length === 0) {
                    container.innerHTML = '<div class="empty-state"><p>No contacts need dispatch</p></div>';
                    // Update mobile nav for empty state
                    if (isMobile()) {
                        document.getElementById('mobile-counter').textContent = '0 / 0';
                        document.getElementById('mobile-prev-btn').disabled = true;
                        document.getElementById('mobile-next-btn').disabled = true;
                    }
                    return;
                }

                // Auto-select first contact on mobile
                autoSelectFirstContactOnMobile();

                container.innerHTML = contacts.map(contact => {
                    const ageClass = contact.age_days <= 1 ? 'fresh' :
                                    contact.age_days <= 7 ? 'moderate' : 'old';
                    const ageText = contact.age_days === 0 ? 'Today' :
                                   contact.age_days === 1 ? '1 day' :
                                   `${contact.age_days} days`;

                    return `
                        <div class="contact-item" draggable="true"
                             data-contact-id="${contact.ID}"
                             onclick="selectContact(${contact.ID})"
                             ondragstart="handleDragStart(event, ${contact.ID})"
                             ondragend="handleDragEnd(event)">
                            <div class="contact-name">
                                ${escapeHtml(contact.name)}
                                <span class="contact-age ${ageClass}">${ageText}</span>
                            </div>
                            <div class="contact-meta">
                                ${contact.location ? escapeHtml(contact.location) : 'No location'}
                                ${contact.source ? ` • ${escapeHtml(contact.source)}` : ''}
                            </div>
                        </div>
                    `;
                }).join('');
            }

            // Render users list
            function renderUsers(searchTerm = '') {
                const container = document.getElementById('users-list');
                const count = document.getElementById('users-count');

                // Filter users by search term
                const filteredUsers = searchTerm
                    ? users.filter(u => {
                        const term = searchTerm.toLowerCase();
                        const nameMatch = u.display_name.toLowerCase().includes(term);
                        const locationMatch = u.locations.some(l => l.toLowerCase().includes(term));
                        return nameMatch || locationMatch;
                    })
                    : users;

                count.textContent = `(${filteredUsers.length})`;

                if (filteredUsers.length === 0) {
                    container.innerHTML = searchTerm
                        ? '<div class="empty-state"><p>No multipliers match your search</p></div>'
                        : '<div class="empty-state"><p>No multipliers found</p></div>';
                    return;
                }

                container.innerHTML = filteredUsers.map(user => {
                    // Only show user status if not active and not empty
                    const userStatusClass = user.user_status === 'away' ? 'away' :
                                           user.user_status === 'inconsistent' ? 'inconsistent' :
                                           user.user_status === 'inactive' ? 'inactive' : '';

                    const userStatusText = user.user_status === 'away' ? 'Away' :
                                          user.user_status === 'inconsistent' ? 'Inconsistent' :
                                          user.user_status === 'inactive' ? 'Inactive' : '';

                    const workloadClass = user.workload_status === 'too_many' ? 'red' :
                                          user.workload_status === 'existing' ? 'yellow' :
                                          user.workload_status === 'active' ? 'green' : '';

                    const workloadText = user.workload_status === 'too_many' ? 'Too Many' :
                                        user.workload_status === 'existing' ? 'Existing Only' :
                                        user.workload_status === 'active' ? 'Accepting' : '';

                    // Build match badges HTML
                    let matchBadgesHtml = '';
                    if (user.location_match || user.language_match) {
                        let badges = [];
                        if (user.location_match) {
                            const isExact = user.location_level === 0;
                            const locationText = user.best_location_match
                                ? (isExact ? 'Exact: ' : 'Near: ') + escapeHtml(user.best_location_match)
                                : (isExact ? 'Exact match' : 'Location match');
                            badges.push(`<span class="match-badge location ${isExact ? 'exact' : ''}">📍 ${locationText}</span>`);
                        }
                        if (user.language_match) {
                            badges.push(`<span class="match-badge language">🗣️ Language match</span>`);
                        }
                        matchBadgesHtml = `<div class="match-badges">${badges.join('')}</div>`;
                    }

                    return `
                        <div class="user-card"
                             data-user-id="${user.ID}"
                             ondragover="handleDragOver(event)"
                             ondragleave="handleDragLeave(event)"
                             ondrop="handleDrop(event, ${user.ID})">
                            <div class="user-name">
                                ${escapeHtml(user.display_name)}
                                ${userStatusText ? `<span class="status-badge ${userStatusClass}">${userStatusText}</span>` : ''}
                                ${workloadText ? `<span class="workload-badge ${workloadClass}">${workloadText}</span>` : ''}
                            </div>
                            ${matchBadgesHtml}
                            <div class="user-stats">
                                <span>Active: ${user.active_contacts}</span>
                                <span>Assigned: ${user.assigned_contacts}</span>
                                <span>Pending: ${user.pending_contacts}</span>
                            </div>
                            ${user.locations.length > 0 ? `
                                <div class="user-locations">
                                    📍 ${user.locations.map(l => escapeHtml(l)).join(', ')}
                                </div>
                            ` : ''}
                            <div class="user-actions">
                                <button class="assign-btn" onclick="event.stopPropagation(); assignSelectedContact(${user.ID})" ${!selectedContactId ? 'disabled' : ''}>
                                    Assign Contact
                                </button>
                            </div>
                        </div>
                    `;
                }).join('');
            }

            // Filter users by search term
            function filterUsers(searchTerm) {
                renderUsers(searchTerm);
            }

            // Assign the currently selected contact to a user
            function assignSelectedContact(userId) {
                if (!selectedContactId) {
                    alert('Please select a contact first');
                    return;
                }
                assignContact(selectedContactId, userId);
            }

            // Select contact and show details
            async function selectContact(contactId) {
                selectedContactId = contactId;

                // Update mobile navigation
                if (isMobile()) {
                    updateMobileNav();
                }

                // Highlight selected contact
                document.querySelectorAll('.contact-item').forEach(el => {
                    el.classList.toggle('selected', parseInt(el.dataset.contactId) === contactId);
                });

                // Show/update the "Open in D.T." button
                const openDtBtn = document.getElementById('open-in-dt-btn');
                openDtBtn.href = `${dispatcherApp.site_url}/contacts/${contactId}`;
                openDtBtn.style.display = 'inline-block';

                // Show loading
                const detailsContainer = document.getElementById('contact-details');
                detailsContainer.innerHTML = '<div class="loading"><div class="loading-spinner" style="margin: 0 auto;"></div></div>';

                try {
                    const response = await fetch(
                        `${dispatcherApp.root}${dispatcherApp.parts.root}/v1/${dispatcherApp.parts.type}/contact`,
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': dispatcherApp.nonce
                            },
                            body: JSON.stringify({
                                contact_id: contactId,
                                parts: dispatcherApp.parts
                            })
                        }
                    );
                    const contact = await response.json();
                    renderContactDetails(contact);

                    // Store contact's location and language data for user matching
                    selectedContactLocationIds = contact.location_ids || [];
                    selectedContactLanguages = contact.languages || [];

                    // Reload users with contact data to calculate matches
                    loadUsers(selectedContactLocationIds, selectedContactLanguages);
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
                                    <div class="detail-label">${escapeHtml(field.label)}</div>
                                    <div class="detail-value">${escapeHtml(field.value)}</div>
                                </div>
                            `).join('')}
                        </div>
                    `).join('') :
                    '<p class="detail-empty">No contact information available</p>';

                // Add created date at the end
                const createdHtml = contact.created ? `
                    <div class="detail-tile">
                        <div class="tile-header">Record Info</div>
                        <div class="detail-section">
                            <div class="detail-label">Created</div>
                            <div class="detail-value">${escapeHtml(contact.created)} (${contact.age_days} days ago)</div>
                        </div>
                    </div>
                ` : '';

                const commentsHtml = contact.comments && contact.comments.length > 0 ?
                    contact.comments.map(c => `
                        <div class="comment-item">
                            <span class="comment-author">${escapeHtml(c.comment_author)}</span>
                            <span class="comment-date">${escapeHtml(c.comment_date)}</span>
                            <div class="comment-content">${formatComment(c.comment_content)}</div>
                        </div>
                    `).join('') :
                    '<p class="detail-empty">No comments or activity yet</p>';

                container.innerHTML = `
                    <div class="details-grid">
                        <div class="details-column">
                            <h2 class="contact-name-header">${escapeHtml(contact.name)}</h2>
                            ${tilesHtml}
                            ${createdHtml}
                        </div>

                        <div class="details-column">
                            <h3>Comments & Activity</h3>
                            <div class="comment-input-inline">
                                <div class="comment-input-wrapper">
                                    <div class="mention-dropdown" id="mention-dropdown"></div>
                                    <textarea class="comment-textarea" id="comment-textarea" placeholder="Type your comment... Use @ to mention users"></textarea>
                                </div>
                                <div class="comment-actions">
                                    <button class="comment-submit-btn" id="comment-submit-btn" onclick="submitComment()">Add Comment</button>
                                </div>
                            </div>
                            <div class="comment-list">
                                ${commentsHtml}
                            </div>
                        </div>
                    </div>
                `;

                // Initialize mention listeners after rendering
                initMentionListeners();
            }

            // Drag and drop handlers
            function handleDragStart(event, contactId) {
                event.dataTransfer.setData('text/plain', contactId);
                event.target.classList.add('dragging');
                selectedContactId = contactId;
            }

            function handleDragEnd(event) {
                event.target.classList.remove('dragging');
            }

            function handleDragOver(event) {
                event.preventDefault();
                event.currentTarget.classList.add('drag-over');
            }

            function handleDragLeave(event) {
                event.currentTarget.classList.remove('drag-over');
            }

            async function handleDrop(event, userId) {
                event.preventDefault();
                event.currentTarget.classList.remove('drag-over');

                const contactId = parseInt(event.dataTransfer.getData('text/plain')) || selectedContactId;
                if (!contactId) return;

                await assignContact(contactId, userId);
            }

            // Assign contact to user
            async function assignContact(contactId, userId) {
                contactId = parseInt(contactId);
                userId = parseInt(userId);

                try {
                    const response = await fetch(
                        `${dispatcherApp.root}${dispatcherApp.parts.root}/v1/${dispatcherApp.parts.type}/assign`,
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': dispatcherApp.nonce
                            },
                            body: JSON.stringify({
                                contact_id: contactId,
                                user_id: userId,
                                parts: dispatcherApp.parts
                            })
                        }
                    );

                    const result = await response.json();

                    // Check for success - either explicit success flag or successful post update (has ID)
                    if (result.success || (result.ID && !result.code)) {
                        // Get current index before removing for mobile navigation
                        const currentIndex = getCurrentContactIndex();

                        // Remove contact from list
                        contacts = contacts.filter(c => parseInt(c.ID) !== contactId);

                        // Handle mobile: hide users list and navigate to next contact
                        if (isMobile()) {
                            hideMobileUsersList();

                            if (contacts.length > 0) {
                                // Navigate to next contact (or previous if we were at the end)
                                const newIndex = Math.min(currentIndex, contacts.length - 1);
                                selectContact(contacts[newIndex].ID);
                            } else {
                                // No more contacts
                                selectedContactId = null;
                                document.getElementById('contact-details').innerHTML =
                                    '<div class="empty-state"><div class="empty-state-icon">🎉</div><p>All contacts have been dispatched!</p></div>';
                                updateMobileNav();
                            }
                            renderContacts();
                        } else {
                            // Desktop behavior
                            renderContacts();

                            // Clear details and users list if this was the selected contact
                            if (parseInt(selectedContactId) === contactId) {
                                document.getElementById('contact-details').innerHTML =
                                    '<div class="empty-state"><div class="empty-state-icon">&#128100;</div><p>Select a contact to view details</p></div>';
                                document.getElementById('open-in-dt-btn').style.display = 'none';
                                document.getElementById('users-list').innerHTML =
                                    '<div class="empty-state"><div class="empty-state-icon">👉</div><p>Select a contact to see matching multipliers</p></div>';
                                document.getElementById('users-count').textContent = '(0)';
                                selectedContactId = null;
                                selectedContactLocationIds = [];
                                selectedContactLanguages = [];
                                users = [];
                            }
                        }

                        // Update user stats
                        const user = users.find(u => parseInt(u.ID) === userId);
                        if (user) {
                            user.active_contacts++;
                            user.pending_contacts++;
                        }
                        // Re-render users with current search term
                        const searchTerm = document.getElementById('user-search').value;
                        renderUsers(searchTerm);

                        // Show success toast
                        showSuccessToast();
                    } else {
                        alert('Failed to assign contact: ' + (result.message || result.data?.message || 'Unknown error'));
                    }
                } catch (error) {
                    console.error('Error assigning contact:', error);
                    alert('Error assigning contact');
                }
            }

            // Utility: escape HTML
            function escapeHtml(text) {
                if (!text) return '';
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            // Format comment content (mentions and links)
            function formatComment(text) {
                if (!text) return '';

                // First escape HTML
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
                        `${dispatcherApp.root}${dispatcherApp.parts.root}/v1/${dispatcherApp.parts.type}/comment`,
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': dispatcherApp.nonce
                            },
                            body: JSON.stringify({
                                contact_id: selectedContactId,
                                comment: comment,
                                parts: dispatcherApp.parts
                            })
                        }
                    );

                    const result = await response.json();

                    if (result.success || result.comment_id) {
                        textarea.value = '';
                        // Reload contact details to show new comment
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

                    // Find @ symbol before cursor
                    const textBeforeCursor = text.substring(0, cursorPos);
                    const lastAtIndex = textBeforeCursor.lastIndexOf('@');

                    if (lastAtIndex !== -1) {
                        const textAfterAt = textBeforeCursor.substring(lastAtIndex + 1);

                        // Check if there's a space between @ and cursor (means mention is complete)
                        if (!textAfterAt.includes(' ') && !textAfterAt.includes('\n')) {
                            mentionStartPos = lastAtIndex;

                            // Debounce the search
                            clearTimeout(mentionSearchTimeout);
                            mentionSearchTimeout = setTimeout(() => {
                                searchMentionUsers(textAfterAt);
                            }, 200);
                            return;
                        }
                    }

                    // Hide dropdown if no active mention
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
                        `${dispatcherApp.root}${dispatcherApp.parts.root}/v1/${dispatcherApp.parts.type}/users-mention`,
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': dispatcherApp.nonce
                            },
                            body: JSON.stringify({
                                search: search,
                                parts: dispatcherApp.parts
                            })
                        }
                    );

                    const data = await response.json();
                    mentionUsers = data.users || [];
                    mentionActiveIndex = 0;

                    if (mentionUsers.length > 0) {
                        renderMentionDropdown();
                        const dropdown = document.getElementById('mention-dropdown');
                        if (dropdown) dropdown.classList.add('show');
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

                // Replace @search with @[Name](user_id)
                const beforeMention = text.substring(0, mentionStartPos);
                const afterCursor = text.substring(cursorPos);

                const mentionText = `@[${user.display_name}](${user.ID}) `;
                textarea.value = beforeMention + mentionText + afterCursor;

                // Set cursor after mention
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

            // Update success toast to accept custom message
            function showSuccessToast(message = 'Contact assigned successfully!') {
                const toast = document.getElementById('success-toast');
                toast.textContent = message;
                toast.classList.add('show');
                setTimeout(() => {
                    toast.classList.remove('show');
                }, 3000);
            }

            // Mobile functionality
            function isMobile() {
                return window.innerWidth <= 768;
            }

            function getCurrentContactIndex() {
                if (!selectedContactId) return -1;
                return contacts.findIndex(c => parseInt(c.ID) === parseInt(selectedContactId));
            }

            function updateMobileNav() {
                const currentIndex = getCurrentContactIndex();
                const total = contacts.length;

                document.getElementById('mobile-counter').textContent = `${currentIndex + 1} / ${total}`;
                document.getElementById('mobile-prev-btn').disabled = currentIndex <= 0;
                document.getElementById('mobile-next-btn').disabled = currentIndex >= total - 1;
            }

            function navigateContact(direction) {
                const currentIndex = getCurrentContactIndex();
                const newIndex = currentIndex + direction;

                if (newIndex >= 0 && newIndex < contacts.length) {
                    selectContact(contacts[newIndex].ID);
                }
            }

            function showMobileUsersList() {
                document.getElementById('users-panel').classList.add('mobile-visible');
            }

            function hideMobileUsersList() {
                document.getElementById('users-panel').classList.remove('mobile-visible');
            }

            // Auto-select first contact on mobile after loading
            function autoSelectFirstContactOnMobile() {
                if (isMobile() && contacts.length > 0 && !selectedContactId) {
                    selectContact(contacts[0].ID);
                }
            }
        </script>
        <?php
    }
}

// Initialize the magic link
Disciple_Tools_Homescreen_Apps_Dispatcher_Magic_Link::instance();
