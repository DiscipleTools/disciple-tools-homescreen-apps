<?php
if ( !defined( 'ABSPATH' ) ) { exit; } // Exit if accessed directly.

/**
 * Class Disciple_Tools_Homescreen_Apps_Create_Contact
 */
class Disciple_Tools_Homescreen_Apps_Create_Contact extends DT_Magic_Url_Base {

    public $page_title = 'Create Contacts';
    public $page_description = 'Create a new contact';
    public $root = 'homescreen_apps';
    public $type = 'create_contact';
    public $post_type = 'contacts';
    private $meta_key = '';
    public $show_bulk_send = false;
    public $show_app_tile = false;
    protected $post_field_settings = null;

    private static $_instance = null;
    public $meta = [];

    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function __construct() {

        /**
         * Specify metadata structure for magic link processing
         */
        $this->meta = [
            'app_type'      => 'magic_link',
            'post_type'     => $this->post_type,
            'contacts_only' => false,
            'fields'        => [
                [
                    'id'    => 'name',
                    'label' => 'Name'
                ]
            ],
            'icon'           => 'mdi mdi-account-plus',
            'show_in_home_apps' => true
        ];

        $this->meta_key = $this->root . '_' . $this->type . '_magic_key';
        parent::__construct();

        // Get field settings for contacts
        $this->post_field_settings = DT_Posts::get_post_field_settings( $this->post_type, false );

        /**
         * Add to user apps list and register endpoints
         */
        add_filter( 'dt_settings_apps_list', [ $this, 'dt_settings_apps_list' ], 10, 1 );
        add_action( 'rest_api_init', [ $this, 'add_endpoints' ] );

        /**
         * Check if this is the correct URL
         */
        $url = dt_get_url_path();
        if ( strpos( $url, $this->root . '/' . $this->type ) === false ) {
            return;
        }

        /**
         * Check if magic link parts are valid
         */
        if ( !$this->check_parts_match() ){
            return;
        }

        // Require user login
        if ( ! is_user_logged_in() ) {
            wp_redirect( wp_login_url( $_SERVER['REQUEST_URI'] ) );
            exit;
        }

        // Load the page
        add_action( 'dt_blank_body', [ $this, 'body' ] );
        add_filter( 'dt_magic_url_base_allowed_css', [ $this, 'dt_magic_url_base_allowed_css' ], 10, 1 );
        add_filter( 'dt_magic_url_base_allowed_js', [ $this, 'dt_magic_url_base_allowed_js' ], 10, 1 );
        add_action( 'wp_enqueue_scripts', [ $this, 'wp_enqueue_scripts' ], 100 );
    }

    public function wp_enqueue_scripts() {
        // Support Geolocation APIs
        if ( DT_Mapbox_API::get_key() ) {
            DT_Mapbox_API::load_mapbox_header_scripts();
            DT_Mapbox_API::load_mapbox_search_widget();
        }

        // Support Typeahead APIs
        $path     = '/dt-core/dependencies/typeahead/dist/';
        $path_js  = $path . 'jquery.typeahead.min.js';
        $path_css = $path . 'jquery.typeahead.min.css';

        $dtwc_version = '0.7.4';

        wp_enqueue_script( 'jquery-typeahead', get_template_directory_uri() . $path_js, [ 'jquery' ], filemtime( get_template_directory() . $path_js ) );
        wp_enqueue_style( 'jquery-typeahead-css', get_template_directory_uri() . $path_css, [], filemtime( get_template_directory() . $path_css ) );
        wp_enqueue_style( 'material-font-icons-css', 'https://cdn.jsdelivr.net/npm/@mdi/font@6.6.96/css/materialdesignicons.min.css', [], '6.6.96' );

        wp_enqueue_style( 'dt-web-components-css', "https://cdn.jsdelivr.net/npm/@disciple.tools/web-components@$dtwc_version/styles/light.css", [], $dtwc_version );

        wp_enqueue_script( 'dt-web-components-js', "https://cdn.jsdelivr.net/npm/@disciple.tools/web-components@$dtwc_version/dist/index.js", $dtwc_version );
    }

    public function dt_magic_url_base_allowed_js( $allowed_js ) {
        $allowed_js = [];
        $allowed_js[] = 'jquery';
        $allowed_js[] = 'mapbox-gl';
        $allowed_js[] = 'mapbox-cookie';
        $allowed_js[] = 'mapbox-search-widget';
        $allowed_js[] = 'google-search-widget';
        $allowed_js[] = 'jquery-typeahead';
        $allowed_js[] = 'dt-web-components-js';
        return $allowed_js;
    }

    public function dt_magic_url_base_allowed_css( $allowed_css ) {
        $allowed_css[] = 'mapbox-gl-css';
        $allowed_css[] = 'jquery-typeahead-css';
        $allowed_css[] = 'material-font-icons-css';
        $allowed_css[] = 'dt-web-components-css';
        return $allowed_css;
    }

    /**
     * Add to user apps list
     */
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

    /**
     * Custom styles
     */
    public function header_style(){
        ?>
        <style>
            body {
                background-color: white;
                padding: 1em;
            }

            .create-form {
                max-width: 600px;
                margin: 0 auto;
                padding: 2em;
            }

            .form-field {
                margin-bottom: 1.5em;
            }

            .form-field label {
                display: block;
                margin-bottom: 0.5em;
                font-weight: bold;
            }

            .form-field input,
            .form-field textarea,
            .form-field select {
                width: 100%;
                padding: 0.5em;
                border: 1px solid #ddd;
                border-radius: 4px;
            }

            .form-field textarea {
                height: 100px;
                resize: vertical;
            }

            .button {
                background-color: #0073aa;
                color: white;
                padding: 0.75em 1.5em;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-size: 1em;
            }

            .button:hover {
                background-color: #005a87;
            }

            .button:disabled {
                background-color: #ccc;
                cursor: not-allowed;
            }

            .loading-spinner {
                display: none;
                width: 16px;
                height: 16px;
                border: 2px solid #f3f3f3;
                border-top: 2px solid #3498db;
                border-radius: 50%;
                animation: spin 1s linear infinite;
                margin-left: 10px;
            }

            .loading-spinner.active {
                display: inline-block;
            }

            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }

            .alert-notice {
                display: none;
                border: 2px solid #4caf50;
                background-color: rgba(142,195,81,0.2);
                border-radius: 5px;
                padding: 1em;
                margin: 1em 0;
            }

            .alert-notice.error {
                border-color: #f44336;
                background-color: rgba(244,67,54,0.2);
            }

            .checkbox-group {
                display: flex;
                flex-direction: column;
                gap: 0.5em;
            }

            .checkbox-label {
                display: flex;
                align-items: center;
                font-weight: normal;
                margin-bottom: 0;
            }

            .checkbox-label input[type="checkbox"] {
                width: auto;
                margin-right: 0.5em;
            }
        </style>
        <?php
    }

    /**
     * Footer JavaScript
     */
    public function footer_javascript(){
        ?>
        <script>
            let jsObject = [<?php echo json_encode([
                'root' => esc_url_raw( rest_url() ),
                'nonce' => wp_create_nonce( 'wp_rest' ),
                'parts' => $this->parts,
                'post' => [
                    'ID' => 0,
                    'post_type' => $this->post_type
                ],
                'field_settings' => $this->post_field_settings,
                'translations' => [
                    'create_success' => __( 'Contact created successfully!', 'disciple-tools-homescreen-apps' ),
                    'name_required' => __( 'Contact name is required', 'disciple-tools-homescreen-apps' ),
                    'error_occurred' => __( 'An error occurred while creating the contact', 'disciple-tools-homescreen-apps' ),
                    'regions_of_focus' => __( 'Regions of Focus', 'disciple_tools' ),
                    'all_locations'    => __( 'All Locations', 'disciple_tools' ),
                ],
                'mapbox' => [
                    'map_key'        => DT_Mapbox_API::get_key(),
                    'google_map_key' => Disciple_Tools_Google_Geocode_API::get_key(),
                    'translations'   => [
                        'search_location' => __( 'Search Location', 'disciple_tools' ),
                        'delete_location' => __( 'Delete Location', 'disciple_tools' ),
                        'use'             => __( 'Use', 'disciple_tools' ),
                        'open_modal'      => __( 'Open Modal', 'disciple_tools' )
                    ]
                ]
            ]) ?>][0]

            // Add missing window functions that DT fields expect
            window.lodash = {
                escape: function(string) {
                    var map = {
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#x27;',
                        "/": '&#x2F;',
                    };
                    var reg = /[&<>"'/]/ig;
                    return string.replace(reg, function(match){
                        return map[match];
                    });
                }
            };

            window.SHAREDFUNCTIONS = {
                formatDate: function(timestamp) {
                    return new Date(timestamp * 1000).toLocaleDateString();
                },
                formatComment: function(comment) {
                    return comment; // Simple implementation
                }
            };

            // Activate field controls
            window.activate_field_controls = () => {
                jQuery('.form-field[data-template-type="dt"]').each(function (idx, fieldDiv) {
                    let field_id = jQuery(fieldDiv).data('field-id');
                    let field_type = jQuery(fieldDiv).data('field-type');
                    let field_template_type = jQuery(fieldDiv).data('template-type');

                    if (field_template_type && field_template_type === 'dt') {
                        switch (field_type) {
                            case 'multi_select':
                                // Handle Selections
                                jQuery(fieldDiv).find('.dt_multi_select').on("click", function (evt) {
                                    let multi_select = jQuery(evt.currentTarget);
                                    if (multi_select.hasClass('empty-select-button')) {
                                        multi_select.removeClass('empty-select-button');
                                        multi_select.addClass('selected-select-button');
                                    } else {
                                        multi_select.removeClass('selected-select-button');
                                        multi_select.addClass('empty-select-button');
                                    }
                                });
                                break;

                            case 'communication_channel':
                                // Add
                                jQuery(fieldDiv).find('button.add-button').on('click', evt => {
                                    let field = jQuery(evt.currentTarget).data('list-class');
                                    let list = jQuery(fieldDiv).find(`#edit-${field}`);

                                    list.append(`
                                        <div class="input-group">
                                            <input type="text" data-field="${window.lodash.escape(field)}" class="dt-communication-channel input-group-field" dir="auto" />
                                            <div class="input-group-button">
                                                <button class="button alert input-height delete-button-style channel-delete-button delete-button new-${window.lodash.escape(field)}" data-key="new" data-field="${window.lodash.escape(field)}">&times;</button>
                                            </div>
                                        </div>`);
                                });

                                // Remove
                                jQuery(fieldDiv).on('click', '.channel-delete-button', evt => {
                                    jQuery(evt.currentTarget).parent().parent().remove();
                                });
                                break;
                        }
                    }
                });
            };

            jQuery(document).ready(function() {
                // Activate field controls on page load
                window.activate_field_controls();

                jQuery('#create-contact-btn').on("click", function () {
                    const alertNotice = jQuery('#alert-notice');
                    const spinner = jQuery('.loading-spinner');
                    const submitBtn = jQuery('#create-contact-btn');

                    // Hide previous alerts
                    alertNotice.hide().removeClass('error');

                    // Build payload using the same approach as single-record.php
                    let payload = {
                        'action': 'create_contact',
                        'parts': jsObject.parts,
                        'post_type': jsObject.parts.post_type || 'contacts',
                        'fields': {
                            'dt': []
                        }
                    };

                    // Iterate over form fields, capturing values from DT web components
                    jQuery('.form-field[data-template-type="dt"]').each(function (idx, fieldDiv) {
                        let field_id = jQuery(fieldDiv).data('field-id');
                        let field_type = jQuery(fieldDiv).data('field-type');
                        let field_template_type = jQuery(fieldDiv).data('template-type');

                        if (field_template_type === 'dt') {
                            // Find the DT web component in this field div
                            let dtComponent = jQuery(fieldDiv).find('[id="' + field_id + '"]');
                            if (dtComponent.length === 0) {
                                return;
                            }

                            let rawValue = dtComponent.attr('value');

                            if (!rawValue) {
                                return;
                            }

                            switch (field_type) {
                                case 'text':
                                case 'textarea':
                                    // For dt-text, value is a simple string
                                    if (rawValue && rawValue.trim() !== '') {
                                        payload['fields']['dt'].push({
                                            id: field_id,
                                            dt_type: field_type,
                                            template_type: field_template_type,
                                            value: rawValue.trim()
                                        });
                                    }
                                    break;

                                case 'key_select':
                                    // For dt-single-select, value is the selected key
                                    if (rawValue && rawValue.trim() !== '') {
                                        payload['fields']['dt'].push({
                                            id: field_id,
                                            dt_type: field_type,
                                            template_type: field_template_type,
                                            value: rawValue.trim()
                                        });
                                    }
                                    break;

                                case 'communication_channel':
                                    // For dt-multi-text, value is JSON array of objects
                                    try {
                                        let parsedValues = JSON.parse(rawValue);
                                        if (Array.isArray(parsedValues) && parsedValues.length > 0) {
                                            let values = [];
                                            parsedValues.forEach(function(item) {
                                                if (item.value && item.value.trim() !== '') {
                                                    values.push({
                                                        'value': item.value.trim()
                                                    });
                                                }
                                            });
                                            if (values.length > 0) {
                                                payload['fields']['dt'].push({
                                                    id: field_id,
                                                    dt_type: field_type,
                                                    template_type: field_template_type,
                                                    value: values
                                                });
                                            }
                                        }
                                    } catch (e) {
                                        // Silently handle parsing errors
                                    }
                                    break;

                                case 'multi_select':
                                    // For dt-multi-select-button-group, value is JSON array of selected keys
                                    try {
                                        let parsedValues = JSON.parse(rawValue);
                                        if (Array.isArray(parsedValues) && parsedValues.length > 0) {
                                            let options = [];
                                            parsedValues.forEach(function(selectedKey) {
                                                if (selectedKey && selectedKey.trim() !== '') {
                                                    options.push({
                                                        'value': selectedKey.trim(),
                                                        'delete': false
                                                    });
                                                }
                                            });
                                            if (options.length > 0) {
                                                payload['fields']['dt'].push({
                                                    id: field_id,
                                                    dt_type: field_type,
                                                    template_type: field_template_type,
                                                    value: options
                                                });
                                            }
                                        }
                                    } catch (e) {
                                        // Silently handle parsing errors
                                    }
                                    break;

                                default:
                                    // Handle other field types as needed
                                    break;
                            }
                        }
                    });



                    // Validate required fields (name is required)
                    let hasName = false;
                    payload['fields']['dt'].forEach(function(field) {
                        if (field.id === 'name' && field.value) {
                            hasName = true;
                        }
                    });

                    if (!hasName) {
                        showAlert(jsObject.translations.name_required, true);
                        return;
                    }

                    // Disable button and show spinner
                    submitBtn.prop('disabled', true);
                    spinner.addClass('active');

                    // Submit request
                    jQuery.ajax({
                        type: "POST",
                        data: JSON.stringify(payload),
                        contentType: "application/json; charset=utf-8",
                        dataType: "json",
                        url: jsObject.root + jsObject.parts.root + '/v1/' + jsObject.parts.type,
                        beforeSend: function (xhr) {
                            xhr.setRequestHeader('X-WP-Nonce', jsObject.nonce)
                        }
                    }).done(function (data) {
                        if (data.success) {
                            let successMessage = jsObject.translations.create_success;
                            if (data.contact_id) {
                                const contactUrl = window.location.origin + '/contacts/' + data.contact_id;
                                successMessage += ' <a href="' + contactUrl + '" target="_blank">View Contact</a>';
                            }
                            showAlert(successMessage, false);
                            // Clear form by resetting DT web component values
                            jQuery('.form-field dt-text').attr('value', '');
                            jQuery('.form-field dt-multi-text').attr('value', '[]');
                            jQuery('.form-field dt-single-select').attr('value', '');
                            jQuery('.form-field dt-multi-select-button-group').attr('value', '[]');
                        } else {
                            showAlert(data.message || jsObject.translations.error_occurred, true);
                        }
                    }).fail(function (e) {
                        const errorMsg = e.responseJSON?.message || jsObject.translations.error_occurred;
                        showAlert(errorMsg, true);
                    }).always(function() {
                        // Re-enable button and hide spinner
                        submitBtn.prop('disabled', false);
                        spinner.removeClass('active');
                        document.documentElement.scrollTop = 0;
                    });
                });

                function showAlert(message, isError) {
                    const alertNotice = jQuery('#alert-notice');
                    alertNotice.find('#alert-content').html(message);
                    if (isError) {
                        alertNotice.addClass('error');
                    }
                    alertNotice.fadeIn('slow');
                }
            });
        </script>
        <?php
        return true;
    }

    /**
     * Page body
     */
    public function body(){
        ?>
        <div id="wrapper">
            <div class="grid-x">
                <div class="cell center">
                    <h2 id="title"><?php echo esc_html( $this->page_title ); ?></h2>
                </div>
            </div>
            <hr>
            
            <div id="content">
                <div id="alert-notice" class="alert-notice">
                    <div id="alert-content"></div>
                </div>

                <div class="cell center">
                    <p><?php echo esc_html( $this->page_description ); ?></p>
                </div>

                <div class="create-form">
                    <?php
                    // Fields to display in the create form
                    $form_fields = [
                        'name' => ['required' => true],
                        'contact_phone' => ['required' => false],
                        'contact_email' => ['required' => false],
                        'seeker_path' => ['required' => false],
                        'milestones' => ['required' => false]
                    ];
                    
                    // Create empty post for field rendering (like template-new-post.php)
                    $empty_post = [
                        'post_type' => $this->post_type
                    ];

                    // Revert back to dt translations like in single-record.php
                    $this->hard_switch_to_default_dt_text_domain();
                    
                    foreach ( $form_fields as $field_id => $field_config ) {
                        if ( isset( $this->post_field_settings[$field_id] ) ) {
                            $field_type = $this->post_field_settings[$field_id]['type'];
                            ?>
                            <div class="form-field" data-field-id="<?php echo esc_attr( $field_id ); ?>" data-field-type="<?php echo esc_attr( $field_type ); ?>" data-template-type="dt">
                                <?php
                                // Capture rendered field html
                                $this->post_field_settings[$field_id]['custom_display'] = false;
                                $this->post_field_settings[$field_id]['readonly'] = false;
                                
                                // Set required flag for DT to handle
                                if ( $field_config['required'] ) {
                                    $this->post_field_settings[$field_id]['required'] = true;
                                }
                                
                                // Check if function exists
                                if ( function_exists( 'render_field_for_display' ) ) {
                                    // Use the same approach as template-new-post.php with field options
                                    $field_options = [
                                        'connection' => [
                                            'allow_add' => false,
                                        ]
                                    ];
                                    render_field_for_display( $field_id, $this->post_field_settings, $empty_post, null, null, null, $field_options );
                                } else {
                                    echo '<p>Error: render_field_for_display function not found</p>';
                                }
                                ?>
                            </div>
                            <?php
                        }
                    }
                    ?>

                    <button id="create-contact-btn" class="button" style="min-width: 100%;">
                        Create Contact
                        <span class="loading-spinner"></span>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Register REST Endpoints
     */
    public function add_endpoints() {
        $namespace = $this->root . '/v1';
        register_rest_route(
            $namespace, '/'.$this->type, [
                [
                    'methods'  => 'POST',
                    'callback' => [ $this, 'create_contact' ],
                    'permission_callback' => function( WP_REST_Request $request ){
                        $magic = new DT_Magic_URL( $this->root );
                        return $magic->verify_rest_endpoint_permissions_on_post( $request );
                    },
                ],
            ]
        );
    }

    /**
     * Create contact endpoint
     */
    public function create_contact( WP_REST_Request $request ) {
        $params = $request->get_params();
        
        if ( !isset( $params['fields'] ) || !isset( $params['fields']['dt'] ) ) {
            return new WP_Error( __METHOD__, 'Missing field data', [ 'status' => 400 ] );
        }

        // Set up user context if not logged in
        if ( !is_user_logged_in() ){
            wp_set_current_user( 0 );
            $current_user = wp_get_current_user();
            $current_user->add_cap( 'magic_link' );
            $current_user->display_name = 'Homescreen App User';
        }

        // Prepare contact fields for DT_Posts::create_post
        $updates = [
            'type' => 'access'
        ];
        
        // Process DT field values using the same approach as single-record.php
        foreach ( $params['fields']['dt'] ?? [] as $field ) {
            switch ( $field['dt_type'] ) {
                case 'text':
                case 'textarea':
                case 'key_select':
                    if ( !empty( $field['value'] ) ) {
                        $updates[$field['id']] = sanitize_text_field( $field['value'] );
                    }
                    break;
                    
                case 'communication_channel':
                    if ( !empty( $field['value'] ) && is_array( $field['value'] ) ) {
                        $updates[$field['id']] = [];
                        foreach ( $field['value'] as $value ) {
                            if ( !empty( $value['value'] ) ) {
                                $updates[$field['id']][] = [
                                    'value' => sanitize_text_field( $value['value'] )
                                ];
                            }
                        }
                    }
                    break;
                    
                case 'multi_select':
                    if ( !empty( $field['value'] ) && is_array( $field['value'] ) ) {
                        $options = [];
                        foreach ( $field['value'] as $option ) {
                            if ( !empty( $option['value'] ) && !$option['delete'] ) {
                                $options[] = [
                                    'value' => sanitize_text_field( $option['value'] )
                                ];
                            }
                        }
                        if ( !empty( $options ) ) {
                            $updates[$field['id']] = [ 'values' => $options ];
                        }
                    }
                    break;
                    
                default:
                    // Handle other field types as needed
                    break;
            }
        }
        
        // Validate that we have a name
        if ( empty( $updates['name'] ) ) {
            return [
                'success' => false,
                'message' => 'Contact name is required'
            ];
        }
        
        // Create the contact
        $result = DT_Posts::create_post( 'contacts', $updates, true, false );
        
        if ( is_wp_error( $result ) ) {
            return [
                'success' => false,
                'message' => $result->get_error_message()
            ];
        }
        
        return [
            'success' => true,
            'contact_id' => $result['ID'],
            'message' => 'Contact created successfully!'
        ];
    }
}

Disciple_Tools_Homescreen_Apps_Create_Contact::instance();
