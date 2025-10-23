<?php
/**
 * Plugin Name: Luna License Manager (Clean)
 * Description: Manages Luna Licenses and VL Client Users - Clean version without conflicting REST API endpoints
 * Version:     1.1.0
 * Author:      Visible Light
 */

if (!defined('ABSPATH')) {
    exit;
}

final class VL_License_Manager {
    /**
     * Singleton instance.
     *
     * @var VL_License_Manager|null
     */
    private static $instance = null;

    /**
     * Bootstraps hooks.
     */
    private function __construct() {
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_filter('login_redirect', array($this, 'filter_login_redirect'), 10, 3);
        add_action('wp_logout', array($this, 'handle_logout_redirect'));
        add_action('init', array($this, 'maybe_bootstrap_console_session'));
        add_action('template_redirect', array($this, 'protect_console')); // Runs on front-end
        add_action('template_redirect', array($this, 'redirect_authenticated_clients'));
        add_action('login_init', array($this, 'maybe_redirect_logged_in_client_from_wp_login'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('init', array($this, 'maybe_create_missing_licenses'));
        add_action('rest_api_init', array($this, 'add_cors_headers'));
    }

    private static function console_base_paths() {
        return array(
            '/ai-constellation-dashboard/',
            '/ai-constellation-console/',
        );
    }

    private static function console_primary_path() {
        $paths = self::console_base_paths();

        return isset($paths[0]) ? $paths[0] : '/ai-constellation-dashboard/';
    }

    private static function url_targets_console($url) {
        if (empty($url) || !is_string($url)) {
            return false;
        }

        $parsed = wp_parse_url($url);
        $path   = '';
        if (is_array($parsed) && isset($parsed['path'])) {
            $path = (string) $parsed['path'];
        } else {
            $path = (string) $url;
        }

        $needle_source = trim($path, '/');
        foreach (self::console_base_paths() as $base) {
            $needle = trim($base, '/');
            if ('' !== $needle && false !== stripos($needle_source, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function is_console_page_context() {
        if (function_exists('is_page')) {
            if (is_page(array('ai-constellation-dashboard', 'ai-constellation-console'))) {
                return true;
            }
        }

        if (empty($_SERVER['REQUEST_URI'])) {
            return false;
        }

        return self::url_targets_console((string) wp_unslash($_SERVER['REQUEST_URI']));
    }

    /**
     * Returns the singleton instance.
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Handles plugin activation.
     */
    public static function activate() {
        self::add_clients_role();
        self::seed_default_licenses();
        self::create_sample_data_streams();
    }

    /**
     * Handles plugin deactivation.
     */
    public static function deactivate() {
        remove_role('vl_client');
    }

    /**
     * Registers the VL Client role if it is missing.
     */
    private static function add_clients_role() {
        if (!get_role('vl_client')) {
            add_role(
                'vl_client',
                'VL Client',
                array(
                    'read'                   => true,
                    'vl_access_supercluster' => true,
                    'vl_view_own_data'       => true,
                )
            );
        }
    }

    /**
     * Seeds default licenses when registry is empty.
     */
    private static function seed_default_licenses() {
        $licenses = self::lic_store_get();
        if (empty($licenses)) {
            $default_licenses = array(
                'VL-VYAK-9BPQ-NKCC' => array(
                    'client_name' => 'Commonwealth Health Services',
                    'status'      => 'active',
                    'created'     => current_time('mysql'),
                ),
                'VL-H2K3-ZFQK-DKDC' => array(
                    'client_name' => 'Site Assembly',
                    'status'      => 'active',
                    'created'     => current_time('mysql'),
                ),
                'VL-AWJJ-8J6S-GD6R' => array(
                    'client_name' => 'Visible Light',
                    'status'      => 'active',
                    'created'     => current_time('mysql'),
                ),
            );

            self::lic_store_set($default_licenses);
        }
    }

    /**
     * Helper accessor for license store.
     */
    private static function lic_store_get() {
        $store = get_option('vl_licenses_registry', array());
        if (!is_array($store)) {
            return array();
        }

        $cleaned    = array();
        $did_update = false;

        foreach ($store as $license_key => $license_data) {
            if (self::is_legacy_license_key($license_key)) {
                $did_update = true;
                continue;
            }

            if (!is_array($license_data)) {
                $license_data = array();
                $did_update   = true;
            }

            if (!isset($license_data['key']) || $license_data['key'] !== $license_key) {
                $license_data['key'] = $license_key;
                $did_update          = true;
            }

            if (isset($license_data['contact_email'])) {
                $clean_email = sanitize_email($license_data['contact_email']);
                if ($license_data['contact_email'] !== $clean_email) {
                    $license_data['contact_email'] = $clean_email;
                    $did_update                     = true;
                }
            }

            $cleaned[$license_key] = $license_data;
        }

        if ($did_update) {
            update_option('vl_licenses_registry', $cleaned);
        }

        return $cleaned;
    }

    private static function lic_store_set($list) {
        if (!is_array($list)) {
            $list = array();
        }

        $cleaned = array();

        foreach ($list as $license_key => $license_data) {
            if (self::is_legacy_license_key($license_key)) {
                continue;
            }

            if (!is_array($license_data)) {
                $license_data = array();
            }

            if (!isset($license_data['key']) || $license_data['key'] !== $license_key) {
                $license_data['key'] = $license_key;
            }

            if (isset($license_data['contact_email'])) {
                $license_data['contact_email'] = sanitize_email($license_data['contact_email']);
            }

            $cleaned[$license_key] = $license_data;
        }

        update_option('vl_licenses_registry', $cleaned);
    }

    private static function conn_store_get() {
        $store = get_option('vl_connections_registry', array());
        return is_array($store) ? $store : array();
    }

    private static function conn_store_set($list) {
        update_option('vl_connections_registry', is_array($list) ? $list : array());
    }

    private static function lic_generate_key() {
        $alph = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $chunk = function() use($alph){ $s=''; for($i=0;$i<4;$i++) $s .= $alph[random_int(0, strlen($alph)-1)]; return $s; };
        return 'VL-' . $chunk() . '-' . $chunk() . '-' . $chunk();
    }

    private static function lic_create_with_key($client, $site, $key, $active = false, $email = '') {
        if (self::is_legacy_license_key($key)) {
            error_log('[VL Licenses] Attempt to create legacy lic_ license discarded.');
            $key = self::lic_generate_key();
        }

        $email = sanitize_email($email);

        $license = array(
            'client_name' => $client,
            'site'        => $site,
            'key'         => $key,
            'status'      => $active ? 'active' : 'inactive',
            'created'     => current_time('mysql'),
            'last_seen'   => null,
            'contact_email' => $email,
        );

        $store       = self::lic_store_get();
        $store[$key] = $license;
        self::lic_store_set($store);

        return $license;
    }

    private static function lic_create($client, $site, $email = '') {
        $key = self::lic_generate_key();
        return self::lic_create_with_key($client, $site, $key, false, $email);
    }

    private static function lic_lookup_by_key($key) {
        if (self::is_legacy_license_key($key)) {
            return null;
        }

        $store = self::lic_store_get();

        if (isset($store[$key])) {
            return $store[$key];
        }

        foreach ($store as $license_data) {
            if (isset($license_data['key']) && $license_data['key'] === $key) {
                return $license_data;
            }
        }

        return null;
    }

    private static function is_legacy_license_key($key) {
        if (!is_string($key)) {
            return false;
        }

        return 0 === stripos($key, 'lic_');
    }

    private static function lic_redact($key) {
        if (empty($key)) {
            return '';
        }

        return substr($key, 0, 8) . '...' . substr($key, -4);
    }

    private static function lic_dashboard_segment($license_key) {
        $sanitized = preg_replace('/[^A-Za-z0-9\-]/', '-', $license_key);
        $sanitized = trim($sanitized, '-');
        return strtolower($sanitized);
    }

    private static function lic_dashboard_url($license, $fallback_key = '') {
        $key = isset($license['key']) ? $license['key'] : $fallback_key;
        if (empty($key)) {
            return 'https://supercluster.visiblelight.ai/';
        }

        return add_query_arg(
            'license',
            $key,
            'https://supercluster.visiblelight.ai/'
        );
    }

    /**
     * Extracts the license key from the current request in a tolerant way.
     *
     * @return array{license:string,source:string}
     */
    private static function lic_extract_request_license() {
        $result = array(
            'license' => '',
            'source'  => 'none',
        );

        if (isset($_GET['license'])) {
            $value = sanitize_text_field(wp_unslash($_GET['license']));
            if (!empty($value)) {
                $result['license'] = $value;
                $result['source']  = 'license';

                return $result;
            }
        }

        if (isset($_GET['lic'])) {
            $value = sanitize_text_field(wp_unslash($_GET['lic']));
            if (!empty($value)) {
                $result['license'] = $value;
                $result['source']  = 'lic';

                return $result;
            }
        }

        if (isset($_SERVER['REQUEST_URI'])) {
            $request_uri = wp_unslash($_SERVER['REQUEST_URI']);
            if (is_string($request_uri)) {
                if (preg_match('~/ai-constellation-(?:console|dashboard)/(?:[^/?#]+/)?lic=([^/?#&]+)~i', $request_uri, $matches)) {
                    $result['license'] = sanitize_text_field($matches[1]);
                    $result['source']  = 'path';

                    return $result;
                }

                if (preg_match('~/supercluster-constellation/(?:[^/?#]+/)?license=([^/?#&]+)~i', $request_uri, $matches)) {
                    $result['license'] = sanitize_text_field($matches[1]);
                    $result['source']  = 'path';

                    return $result;
                }
            }
        }

        return $result;
    }

    private static function lic_extract_license_from_url($url) {
        if (empty($url)) {
            return '';
        }

        $parts = wp_parse_url($url);

        if (isset($parts['query'])) {
            parse_str($parts['query'], $query_vars);
            if (isset($query_vars['license']) && !empty($query_vars['license'])) {
                return sanitize_text_field((string) $query_vars['license']);
            }

            if (isset($query_vars['lic']) && !empty($query_vars['lic'])) {
                return sanitize_text_field((string) $query_vars['lic']);
            }
        }

        if (isset($parts['path'])) {
            $path = (string) $parts['path'];
            if (preg_match('~/lic=([^/?#&]+)~i', $path, $matches)) {
                return sanitize_text_field($matches[1]);
            }

            if (preg_match('~/license=([^/?#&]+)~i', $path, $matches)) {
                return sanitize_text_field($matches[1]);
            }
        }

        return '';
    }

    /**
     * Determines if the current front-end request targets the console route.
     */
    private static function is_console_request() {
        if (!empty($_GET['license']) || !empty($_GET['lic'])) {
            return true;
        }

        if (empty($_SERVER['REQUEST_URI'])) {
            return false;
        }

        $path = wp_parse_url(wp_unslash((string) $_SERVER['REQUEST_URI']), PHP_URL_PATH);
        if (!is_string($path)) {
            return false;
        }

        $trimmed = trim($path, '/');
        foreach (self::console_base_paths() as $base_path) {
            $needle = trim($base_path, '/');
            if ('' !== $needle && false !== stripos($trimmed, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Finds a VL client user that owns a license key.
     *
     * @param string $license_key
     *
     * @return WP_User|null
     */
    private static function lic_find_user_by_license($license_key) {
        if (empty($license_key)) {
            return null;
        }

        $query = new WP_User_Query(
            array(
                'number'     => 1,
                'role__in'   => array('vl_client'),
                'meta_query' => array(
                    'relation' => 'OR',
                    array(
                        'key'   => 'vl_license_key',
                        'value' => $license_key,
                    ),
                    array(
                        'key'   => 'license_key',
                        'value' => $license_key,
                    ),
                ),
            )
        );

        $users = $query->get_results();

        if (empty($users)) {
            return null;
        }

        $user = $users[0];

        return ($user instanceof WP_User) ? $user : null;
    }

    /**
     * Ensures a WordPress user exists for the provided VL client details.
     *
     * @param string $client_name Display name for the client account.
     * @param string $email       Email address for the client account.
     * @param string $license_key License key assigned to the client.
     * @param string $site        Optional site descriptor stored with the user.
     * @param string $password    Optional password to assign when creating a new user.
     *
     * @return array|WP_Error Array with keys `user` (WP_User) and `created` (bool) on success, WP_Error otherwise.
     */
    private static function ensure_client_user($client_name, $email, $license_key, $site = '', $password = '') {
        $email = sanitize_email($email);

        if (empty($email) || !is_email($email)) {
            return new WP_Error('invalid_email', 'A valid email address is required for VL clients.');
        }

        $site = sanitize_text_field($site);

        $existing_id = email_exists($email);
        if ($existing_id) {
            $user = get_user_by('id', $existing_id);
            if (!$user instanceof WP_User) {
                return new WP_Error('existing_user_missing', sprintf('Unable to load the existing WordPress user for %s.', $email));
            }

            $user->add_role('vl_client');
            update_user_meta($user->ID, 'vl_license_key', $license_key);
            update_user_meta($user->ID, 'license_key', $license_key);

            if (!empty($site)) {
                update_user_meta($user->ID, 'vl_client_site', $site);
            }

            if (!empty($client_name) && $user->display_name !== $client_name) {
                wp_update_user(
                    array(
                        'ID'           => $user->ID,
                        'display_name' => $client_name,
                    )
                );
            }

            // Store the VL license key in wp_activation_key column
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'users',
                array('user_activation_key' => $license_key),
                array('ID' => $user->ID),
                array('%s'),
                array('%d')
            );

            return array(
                'user'    => get_user_by('id', $user->ID),
                'created' => false,
            );
        }

        $username_base = '';

        if (!empty($client_name)) {
            $preferred_username = strtolower(preg_replace('/\s+/', '', $client_name));
            $username_base      = sanitize_user($preferred_username, true);
        }

        if (empty($username_base)) {
            $username_base = sanitize_user(current(explode('@', $email)), true);
        }

        if (empty($username_base)) {
            $username_base = sanitize_user(strtolower(str_replace(' ', '-', $client_name)), true);
        }
        if (empty($username_base)) {
            $username_base = 'vlclient';
        }

        $username = $username_base;
        $suffix   = 1;
        while (username_exists($username)) {
            $username = $username_base . $suffix;
            $suffix++;
        }

        $password = (string) $password;
        if ('' === trim($password)) {
            $password = wp_generate_password(20, true, true);
        }
        $user_id  = wp_create_user($username, $password, $email);

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        wp_update_user(
            array(
                'ID'           => $user_id,
                'display_name' => $client_name,
                'first_name'   => $client_name,
            )
        );

        $user = new WP_User($user_id);
        $user->set_role('vl_client');

        update_user_meta($user_id, 'vl_license_key', $license_key);
        update_user_meta($user_id, 'license_key', $license_key);

        if (!empty($site)) {
            update_user_meta($user_id, 'vl_client_site', $site);
        }

        // Store the VL license key in wp_activation_key column
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'users',
            array('user_activation_key' => $license_key),
            array('ID' => $user_id),
            array('%s'),
            array('%d')
        );

        return array(
            'user'    => get_user_by('id', $user_id),
            'created' => true,
        );
    }

    private static function status_pill_from_row($row) {
        // Check for both old and new status field names
        $status = 'unknown';
        if (isset($row['status'])) {
            $status = $row['status'];
        } elseif (isset($row['active'])) {
            $status = $row['active'] ? 'active' : 'inactive';
        }
        
        $class  = ('active' === $status) ? 'vl-status-active' : 'vl-status-inactive';

        return '<span class="vl-status-pill ' . esc_attr($class) . '">' . esc_html(ucfirst($status)) . '</span>';
    }

    /**
     * Admin menu registration.
     */
    public function register_admin_menu() {
        add_menu_page(
            'VL Clients',
            'VL Clients',
            'manage_options',
            'vl-clients',
            array($this, 'render_licenses_screen'),
            'dashicons-admin-users',
            30
        );

        add_submenu_page(
            'vl-clients',
            'VL Hub Profile',
            'VL Hub Profile',
            'manage_options',
            'vl-hub-profile',
            array($this, 'render_hub_profile_screen')
        );
    }

    /**
     * Renders the license admin screen.
     */
    public function render_licenses_screen() {
        $licenses    = self::lic_store_get();
        $connections = self::conn_store_get(); // Reserved for future use.
        $messages    = array(
            'success' => array(),
            'error'   => array(),
        );
    
        // Handle client edit screen
        if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['license_key'])) {
            $license_key = sanitize_text_field(wp_unslash($_GET['license_key']));
            $license = isset($licenses[$license_key]) ? $licenses[$license_key] : null;
            
            if ($license) {
                $this->render_client_edit_screen($license_key, $license, $messages);
            return;
            } else {
                $messages['error'][] = 'License not found.';
            }
        }
    
        if (isset($_POST['action'])) {
            $action = sanitize_text_field(wp_unslash($_POST['action']));
    
            if ('create_license' === $action) {
                check_admin_referer('vl_create_license');
    
                $client   = sanitize_text_field(wp_unslash($_POST['client_name']));
                $site     = sanitize_text_field(wp_unslash($_POST['site']));
                $email    = isset($_POST['client_email']) ? sanitize_email(wp_unslash($_POST['client_email'])) : '';
                $password = isset($_POST['client_password']) ? trim(wp_unslash($_POST['client_password'])) : '';
    
                if (!$client || !$site || !$email || '' === $password) {
                    $messages['error'][] = 'Client name, site, email address, and password are all required.';
                } elseif (!is_email($email)) {
                    $messages['error'][] = 'Please provide a valid email address for the client.';
                } else {
                    $license = self::lic_create($client, $site, $email);
                    $ensure  = self::ensure_client_user($client, $email, $license['key'], $site, $password);
    
                    if (is_wp_error($ensure)) {
                        $messages['error'][] = $ensure->get_error_message();
    
                        $store = self::lic_store_get();
                        if (isset($store[$license['key']])) {
                            unset($store[$license['key']]);
                            self::lic_store_set($store);
                        }
                    } else {
                        $user     = $ensure['user'];
                        $username = ($user instanceof WP_User) ? $user->user_login : '';

                        if ($ensure['created']) {
                            $messages['success'][] = $username
                                ? sprintf('License created and new VL Client user %s provisioned.', $username)
                                : 'License created and new VL Client user provisioned.';
                        } else {
                            $messages['success'][] = $username
                                ? sprintf('License created and linked to existing user %s.', $username)
                                : 'License created and linked to existing user account.';
                        }
                    }

                    $licenses = self::lic_store_get();
                }
            }

            if ('sync_client_user' === $action) {
                check_admin_referer('vl_sync_client_user');

                $license_key = sanitize_text_field(wp_unslash($_POST['license_key'] ?? ''));

                if (empty($license_key)) {
                    $messages['error'][] = 'Unable to sync client: missing license key.';
                } else {
                    $license = self::lic_lookup_by_key($license_key);

                    if (!$license) {
                        $messages['error'][] = 'Unable to sync client: license not found.';
                    } else {
                        $client_name = isset($license['client_name']) ? $license['client_name'] : '';
                        $email       = isset($license['contact_email']) ? $license['contact_email'] : '';
                        $site        = isset($license['site']) ? $license['site'] : '';

                        if (empty($email)) {
                            $client_label = $client_name ? sanitize_text_field($client_name) : 'client';
                            $messages['error'][] = sprintf('Unable to sync %s: no email address stored with the license.', $client_label);
                        } else {
                            $ensure = self::ensure_client_user($client_name, $email, $license_key, $site);

                            if (is_wp_error($ensure)) {
                                $messages['error'][] = $ensure->get_error_message();
                            } else {
                                $user     = $ensure['user'];
                                $username = ($user instanceof WP_User) ? $user->user_login : '';

                                if ($ensure['created']) {
                                    $messages['success'][] = $username
                                        ? sprintf('Created new VL Client user %s and linked the license.', $username)
                                        : 'Created new VL Client user and linked the license.';
                                } else {
                                    $messages['success'][] = $username
                                        ? sprintf('Updated existing user %s and synced their VL Client access.', $username)
                                        : 'Updated the existing VL Client user and synced access.';
                                }
                            }
                        }
                    }
                }

                $licenses = self::lic_store_get();
            }

            if ('delete_license' === $action) {
                check_admin_referer('vl_delete_license');

                $key = sanitize_text_field(wp_unslash($_POST['license_key']));
                if ($key) {
                    $store = self::lic_store_get();
                    if (isset($store[$key])) {
                        $linked_user = self::lic_find_user_by_license($key);
                        unset($store[$key]);
                        self::lic_store_set($store);
                        $messages['success'][] = 'License deleted successfully.';
                        if ($linked_user instanceof WP_User) {
                            if (wp_delete_user($linked_user->ID)) {
                                $messages['success'][] = sprintf('Deleted WordPress user %s linked to the license.', $linked_user->user_login);
                            } else {
                                $messages['error'][] = sprintf('License removed but unable to delete linked user %s. Please remove them manually from Users.', $linked_user->user_login);
                            }
                        }
                        $licenses = self::lic_store_get();
                    }
                }
            }
        }
        ?>
        <div class="wrap">
            <h1>Luna Licenses</h1>
            
<?php foreach ($messages['success'] as $notice) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
<?php endforeach; ?>
<?php foreach ($messages['error'] as $notice) : ?>
        <div class="notice notice-error"><p><?php echo esc_html($notice); ?></p></div>
<?php endforeach; ?>

            <div class="vl-admin-grid">
                <div class="vl-admin-card">
                    <h2>Create New License</h2>
                    <form method="post">
                        <?php wp_nonce_field('vl_create_license'); ?>
                        <input type="hidden" name="action" value="create_license">
                        <table class="form-table">
                            <tr>
                                <th scope="row">Client Name</th>
                                <td><input type="text" name="client_name" required class="regular-text"></td>
        </tr>
        <tr>
                                <th scope="row">Site</th>
                                <td><input type="text" name="site" required class="regular-text"></td>
        </tr>
        <tr>
                                <th scope="row">Client Email</th>
                                <td><input type="email" name="client_email" required class="regular-text"></td>
        </tr>
        <tr>
                                <th scope="row">Password</th>
                                <td><input type="password" name="client_password" required class="regular-text" autocomplete="new-password"></td>
        </tr>
      </table>
                        <?php submit_button('Create License'); ?>
    </form>
                </div>

                <div class="vl-admin-card">
                    <h2>Existing Licenses</h2>
                    <table class="wp-list-table widefat fixed striped">
      <thead>
        <tr>
          <th>Client</th>
                            <th>Email</th>
                            <th>Site</th>
          <th>Key</th>
          <th>Status</th>
          <th>Created</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
                        <?php foreach ($licenses as $license_key => $license) : ?>
                            <tr>
                                <td><?php echo esc_html(isset($license['client_name']) ? $license['client_name'] : (isset($license['client']) ? $license['client'] : 'Unknown')); ?></td>
                                <td><?php echo esc_html(isset($license['contact_email']) ? $license['contact_email'] : ''); ?></td>
                                <td><?php echo esc_html(isset($license['site']) ? $license['site'] : ''); ?></td>
                                <td class="vl-license-key-cell">
                                    <code><?php echo esc_html(self::lic_redact($license_key)); ?></code>
                                    <button
                                        type="button"
                                        class="button button-small vl-copy-license"
                                        data-license="<?php echo esc_attr($license_key); ?>"
                                        aria-label="Copy license key <?php echo esc_attr($license_key); ?>"
                                    >Copy</button>
                                </td>
                                <td><?php echo wp_kses_post(self::status_pill_from_row($license)); ?></td>
                                <td><?php echo esc_html(isset($license['created']) ? $license['created'] : ''); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(self::lic_dashboard_url($license, $license_key)); ?>" class="button button-small">View Dashboard</a>
                                    <a href="<?php echo esc_url(add_query_arg(array('page' => 'vl-clients', 'license_key' => $license_key, 'action' => 'edit'), admin_url('admin.php'))); ?>" class="button button-small">Edit Client</a>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field('vl_sync_client_user'); ?>
                                        <input type="hidden" name="action" value="sync_client_user">
                                        <input type="hidden" name="license_key" value="<?php echo esc_attr($license_key); ?>">
                                        <input type="submit" class="button button-small" value="Sync User">
                                    </form>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field('vl_delete_license'); ?>
                                        <input type="hidden" name="action" value="delete_license">
                                        <input type="hidden" name="license_key" value="<?php echo esc_attr($license_key); ?>">
                                        <input type="submit" class="button button-small" value="Delete" onclick="return confirm('Are you sure?');">
              </form>
            </td>
          </tr>
                        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
            </div>
        </div>

        <style>
            .vl-admin-grid {
                display: grid;
                grid-template-columns: 1fr 2fr;
                gap: 20px;
                margin-top: 20px;
            }

            .vl-admin-card {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 20px;
            }

            .vl-status-pill {
                padding: 4px 8px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
            }

            .vl-status-active {
                background: #d4edda;
                color: #155724;
            }

            .vl-status-inactive {
                background: #f8d7da;
                color: #721c24;
            }

            .vl-license-key-cell {
                display: flex;
                align-items: center;
                gap: 8px;
                flex-wrap: wrap;
            }

            .vl-license-key-cell code {
                margin-right: 0;
            }

            .vl-copy-license.vl-copy-success {
                border-color: #28a745;
                color: #155724;
                box-shadow: 0 0 0 1px rgba(40,167,69,0.4);
            }
        </style>
            <script>
        (function(){
            const notify = (btn, message) => {
                const original = btn.dataset.originalText || btn.textContent;
                if (!btn.dataset.originalText) {
                    btn.dataset.originalText = original;
                }
                btn.textContent = message;
                btn.classList.add('vl-copy-success');
                setTimeout(() => {
                    btn.textContent = btn.dataset.originalText;
                    btn.classList.remove('vl-copy-success');
                }, 2000);
            };

            const copyFallback = (text, btn) => {
                const textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.style.position = 'fixed';
                textarea.style.top = '-1000px';
                document.body.appendChild(textarea);
                textarea.focus();
                textarea.select();

                try {
                    const succeeded = document.execCommand('copy');
                    document.body.removeChild(textarea);
                    if (succeeded) {
                        notify(btn, 'Copied!');
                    } else {
                        notify(btn, 'Copy failed');
                    }
                } catch (err) {
                    document.body.removeChild(textarea);
                    notify(btn, 'Copy failed');
                }
            };

            document.addEventListener('click', function(event){
                    const btn = event.target.closest('.vl-copy-license');
                    if (!btn) {
                        return;
                    }
    
                    const key = btn.getAttribute('data-license') || '';
                    if (!key) {
                        notify(btn, 'Copy failed');
                        return;
                    }
    
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(key)
                            .then(() => notify(btn, 'Copied!'))
                            .catch(() => copyFallback(key, btn));
                    } else {
                        copyFallback(key, btn);
                    }
                });
            })();
            </script>
        <?php
    }

    /**
     * Handles login redirect behaviour for clients vs admins.
     */
    public function filter_login_redirect($redirect_to, $requested_redirect_to, $user) {
        if (!($user instanceof WP_User)) {
            return $redirect_to;
        }

        $roles = (array) $user->roles;
        error_log('[VL Login] User: ' . $user->user_login . ', Roles: ' . implode(',', $roles));

        $license_key = get_user_meta($user->ID, 'vl_license_key', true);
        if (!$license_key) {
            $license_key = get_user_meta($user->ID, 'license_key', true);
        }

        if (!in_array('vl_client', $roles, true) && !empty($license_key)) {
            $user->add_role('vl_client');
            $roles[] = 'vl_client';
            error_log('[VL Login] Added vl_client role based on stored license for user: ' . $user->user_login);
        }

        if (!in_array('vl_client', $roles, true)) {
            error_log('[VL Login] Not a VL client, honouring requested redirect.');

            return !empty($requested_redirect_to) ? $requested_redirect_to : $redirect_to;
        }

        if (!empty($license_key) && self::is_legacy_license_key($license_key)) {
            delete_user_meta($user->ID, 'vl_license_key');
            delete_user_meta($user->ID, 'license_key');
            error_log('[VL Login] Removed legacy lic_ key for user: ' . $user->user_login);
            $license_key = '';
        }

        if (!$license_key && !empty($requested_redirect_to)) {
            $guessed = self::lic_extract_license_from_url($requested_redirect_to);
            if (!empty($guessed) && !self::is_legacy_license_key($guessed)) {
                $license_key = $guessed;
                update_user_meta($user->ID, 'vl_license_key', $license_key);
                error_log('[VL Login] Stored license from requested redirect: ' . $license_key);
            }
        }

        if (!$license_key && isset($_REQUEST['license'])) {
            $param_license = sanitize_text_field(wp_unslash((string) $_REQUEST['license']));
            if (!empty($param_license) && !self::is_legacy_license_key($param_license)) {
                $license_key = $param_license;
                update_user_meta($user->ID, 'vl_license_key', $license_key);
                error_log('[VL Login] Stored license from request parameter: ' . $license_key);
            }
        }

        $license = $license_key ? self::lic_lookup_by_key($license_key) : null;
        if ($license_key && (!$license || (isset($license['status']) && 'active' !== $license['status']))) {
            error_log('[VL Login] License key present but inactive or missing in registry: ' . $license_key);
        }

        if (!empty($requested_redirect_to) && self::url_targets_console($requested_redirect_to)) {
            $requested_license = self::lic_extract_license_from_url($requested_redirect_to);
            if (empty($license_key) && !empty($requested_license) && !self::is_legacy_license_key($requested_license)) {
                $license_key = $requested_license;
                update_user_meta($user->ID, 'vl_license_key', $license_key);
                error_log('[VL Login] Captured license from requested console redirect: ' . $license_key);
            }

            if (!empty($requested_redirect_to)) {
                error_log('[VL Login] Honouring requested console redirect: ' . $requested_redirect_to);

                return $requested_redirect_to;
            }
        }

        if (!empty($license_key)) {
            $url = self::lic_dashboard_url($license ?: array('key' => $license_key), $license_key);
            error_log('[VL Login] Redirecting VL client with license ' . $license_key . ' to ' . $url);

            return $url;
        }

        $fallback = 'https://supercluster.visiblelight.ai/';
        error_log('[VL Login] No license associated; using fallback console URL: ' . $fallback);

        return $fallback;
    }

    /**
     * Forces VL clients to the login screen when logging out.
     */
    public function handle_logout_redirect() {
        wp_safe_redirect('https://supercluster.visiblelight.ai/');
        exit;
    }

    /**
     * Ensures VL clients arriving with a license token are logged in before
     * other template_redirect callbacks (like forced login plugins) run.
     */
    public function maybe_bootstrap_console_session() {
        if (is_admin() || is_user_logged_in()) {
            return;
        }

        if (!self::is_console_request()) {
            return;
        }

        $license_info = self::lic_extract_request_license();
        $license      = $license_info['license'];

        if (empty($license) || self::is_legacy_license_key($license)) {
            return;
        }

        $record = self::lic_lookup_by_key($license);
        if (!$record || (isset($record['status']) && 'active' !== $record['status'])) {
            return;
        }

        $user = self::lic_find_user_by_license($license);
        if (!($user instanceof WP_User) || !in_array('vl_client', (array) $user->roles, true)) {
            return;
        }

        if ('license' !== $license_info['source']) {
            $_GET['license'] = $license;
        }

        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);
        do_action('wp_login', $user->user_login, $user);
    }

    /**
     * Protects the console from unauthenticated access.
     */
    public function protect_console() {
        if (!self::is_console_page_context()) {
            return;
        }

        $license_info = self::lic_extract_request_license();
        $license      = $license_info['license'];

        if (empty($license) || self::is_legacy_license_key($license)) {
            wp_safe_redirect('https://supercluster.visiblelight.ai/');
            exit;
        }

        if ('license' !== $license_info['source']) {
            // Normalise access for downstream consumers that expect ?license=.
            $_GET['license'] = $license;
        }

        if (is_user_logged_in()) {
            return;
        }

        $record = self::lic_lookup_by_key($license);
        if ($record && (!isset($record['status']) || 'active' === $record['status'])) {
            $user = self::lic_find_user_by_license($license);
            if ($user instanceof WP_User && in_array('vl_client', (array) $user->roles, true)) {
                wp_set_current_user($user->ID);
                wp_set_auth_cookie($user->ID, true);
                /**
                 * Allow other plugins hooked into the standard login process to react to the automatic sign on.
                 */
                do_action('wp_login', $user->user_login, $user);

                return;
            }
        }

        wp_safe_redirect(home_url('/supercluster-login/'));
        exit;
    }

    /**
     * Redirects authenticated clients away from the login screen.
     */
    public function redirect_authenticated_clients() {
        // Only redirect from the login page, not from the console page
        if (is_page('supercluster-login') && is_user_logged_in()) {
            $user = wp_get_current_user();

            if ($user instanceof WP_User && in_array('vl_client', (array) $user->roles, true)) {
                $license_key = get_user_meta($user->ID, 'vl_license_key', true);
                if (self::is_legacy_license_key($license_key)) {
                    delete_user_meta($user->ID, 'vl_license_key');
                    delete_user_meta($user->ID, 'license_key');
                    $license_key = '';
                }
                $license     = $license_key ? self::lic_lookup_by_key($license_key) : null;
                $url         = $license ? self::lic_dashboard_url($license, $license_key) : home_url(self::console_primary_path());

                wp_safe_redirect($url);
                exit;
            }
        }

        // Don't redirect if user is already on the console page
        if (self::is_console_page_context() && is_user_logged_in()) {
            return; // Stay on the console page
        }
    }

    /**
     * Redirect VL Clients away from the core wp-login.php screen when already signed in.
     */
    public function maybe_redirect_logged_in_client_from_wp_login() {
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash((string) $_REQUEST['action'])) : 'login';
        if (in_array($action, array('logout', 'lostpassword', 'retrievepassword', 'rp', 'resetpass', 'register', 'confirmaction'), true)) {
            return;
        }

        if (!is_user_logged_in()) {
            return;
        }

        $user = wp_get_current_user();
        if (!($user instanceof WP_User) || !in_array('vl_client', (array) $user->roles, true)) {
            return;
        }

        $license_key = get_user_meta($user->ID, 'vl_license_key', true);
        if (self::is_legacy_license_key($license_key)) {
            delete_user_meta($user->ID, 'vl_license_key');
            delete_user_meta($user->ID, 'license_key');
            $license_key = '';
        }

        $license = $license_key ? self::lic_lookup_by_key($license_key) : null;
        $url     = $license ? self::lic_dashboard_url($license, $license_key) : 'https://supercluster.visiblelight.ai/';

        wp_safe_redirect($url);
        exit;
    }

    /**
     * Add CORS headers for subdomain access.
     */
    public function add_cors_headers() {
        // Allow requests from the supercluster subdomain
        header('Access-Control-Allow-Origin: https://supercluster.visiblelight.ai');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Allow-Credentials: true');
        
        // Handle preflight OPTIONS requests
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }

    /**
     * REST API registration.
     */
    public function register_rest_routes() {
        register_rest_route(
            'vl-license/v1',
            '/activate',
            array(
                'methods'             => 'POST',
                'permission_callback' => '__return_true',
                'callback'            => array($this, 'rest_activate_license'),
            )
        );

        register_rest_route(
            'vl-license/v1',
            '/heartbeat',
            array(
                'methods'             => 'POST',
                'permission_callback' => '__return_true',
                'callback'            => array($this, 'rest_license_heartbeat'),
            )
        );

        register_rest_route(
            'vl-hub/v1',
            '/session',
            array(
                'methods'             => 'GET',
                'permission_callback' => '__return_true',
                'callback'            => array($this, 'rest_session_info'),
            )
        );

        register_rest_route(
            'vl-hub/v1',
            '/clients',
            array(
                'methods'             => 'GET',
                'permission_callback' => array($this, 'rest_require_manage_clients'),
                'callback'            => array($this, 'rest_clients_list'),
            )
        );

        register_rest_route(
            'vl-hub/v1',
            '/category-health',
            array(
                'methods'             => 'GET',
                'permission_callback' => '__return_true',
                'callback'            => array($this, 'rest_category_health'),
            )
        );

        register_rest_route(
            'vl-hub/v1',
            '/constellation',
            array(
                'methods'             => 'GET',
                'permission_callback' => '__return_true',
                'callback'            => array($this, 'rest_constellation_data'),
            )
        );

        register_rest_route(
            'vl-hub/v1',
            '/data-streams',
            array(
                'methods'             => 'GET',
                'permission_callback' => '__return_true',
                'callback'            => array($this, 'rest_data_streams'),
            )
        );

        register_rest_route(
            'vl-hub/v1',
            '/sync-client-data',
            array(
                'methods'             => 'POST',
                'permission_callback' => '__return_true',
                'callback'            => array($this, 'rest_sync_client_data'),
            )
        );

        register_rest_route(
            'vl-hub/v1',
            '/complete-cloud-connection',
            array(
                'methods'             => 'POST',
                'permission_callback' => '__return_true',
                'callback'            => array($this, 'rest_complete_cloud_connection'),
            )
        );
    }

    /**
     * REST handler: activate license.
     */
    public function rest_activate_license($request) {
        $license = trim((string) $request->get_param('license'));
        $site    = esc_url_raw((string) $request->get_param('site_url'));
        $name    = sanitize_text_field((string) $request->get_param('site_name'));
        $wpv     = sanitize_text_field((string) $request->get_param('wp_version'));
        $pv      = sanitize_text_field((string) $request->get_param('plugin_version'));

        if (!$license || !$site) {
            $response = rest_ensure_response(array('ok' => false, 'error' => 'missing_params'));
            if (is_object($response) && method_exists($response, 'set_status')) {
                $response->set_status(400);
            }

            return $response;
        }

        $store    = self::lic_store_get();
        $found_id = null;

      foreach ($store as $id => $row) {
            if (isset($row['key']) && $row['key'] === $license) {
                $found_id = $id;
          break;
        }
      }
      
        if (!$found_id) {
            $response = rest_ensure_response(array('ok' => false, 'error' => 'license_not_found'));
            if (is_object($response) && method_exists($response, 'set_status')) {
                $response->set_status(404);
            }

            return $response;
        }

        $store[$found_id]['last_seen']      = current_time('mysql');
        $store[$found_id]['site']           = $site;
        $store[$found_id]['site_name']      = $name;
        $store[$found_id]['wp_version']     = $wpv;
        $store[$found_id]['plugin_version'] = $pv;
        $store[$found_id]['status']         = 'active';

        self::lic_store_set($store);

        return rest_ensure_response(array('ok' => true, 'license' => $found_id));
    }

    /**
     * REST handler: heartbeat ping.
     */
    public function rest_license_heartbeat($request) {
        $license = trim((string) $request->get_param('license'));
      if (!$license) {
            $response = rest_ensure_response(array('ok' => false, 'error' => 'missing_license'));
            if (is_object($response) && method_exists($response, 'set_status')) {
                $response->set_status(400);
            }

            return $response;
        }

        $store    = self::lic_store_get();
        $found_id = null;

      foreach ($store as $id => $row) {
            if (isset($row['key']) && $row['key'] === $license) {
                $found_id = $id;
          break;
        }
      }
      
        if (!$found_id) {
            $response = rest_ensure_response(array('ok' => false, 'error' => 'license_not_found'));
            if (is_object($response) && method_exists($response, 'set_status')) {
                $response->set_status(404);
            }

            return $response;
        }

        $store[$found_id]['last_seen'] = current_time('mysql');
        $store[$found_id]['status']    = 'active';
        self::lic_store_set($store);

        return rest_ensure_response(array('ok' => true));
    }

    /**
     * REST permission helper: requires a privileged user to manage clients.
     */
    public function rest_require_manage_clients() {
        return is_user_logged_in() && current_user_can('list_users');
    }

    /**
     * REST handler: return session information for the current viewer.
     */
    public function rest_session_info($request) {
        if (!is_user_logged_in()) {
            return rest_ensure_response(
                array(
                    'authenticated' => false,
                    'login_url'     => wp_login_url('https://supercluster.visiblelight.ai/'),
                )
            );
        }

        $user        = wp_get_current_user();
        $license_key = get_user_meta($user->ID, 'vl_license_key', true);
        $license     = $license_key ? self::lic_lookup_by_key($license_key) : null;

        $license_payload = null;
        if ($license) {
            $license_payload = array(
                'key'            => $license_key,
                'client_name'    => isset($license['client_name']) ? $license['client_name'] : '',
                'status'         => isset($license['status']) ? $license['status'] : '',
                'dashboard_url'  => self::lic_dashboard_url($license, $license_key),
                'last_seen'      => isset($license['last_seen']) ? $license['last_seen'] : null,
                'contact_email'  => isset($license['contact_email']) ? $license['contact_email'] : '',
            );
        }

        $permissions = array(
            'can_manage_clients' => current_user_can('list_users'),
        );

        // Get wp_activation_key from user table
        $wp_activation_key = '';
        if ($user->ID) {
            $wp_activation_key = get_user_meta($user->ID, 'user_activation_key', true);
            if (empty($wp_activation_key)) {
                // Fallback: get directly from database
                global $wpdb;
                $activation_key = $wpdb->get_var($wpdb->prepare(
                    "SELECT user_activation_key FROM {$wpdb->users} WHERE ID = %d",
                    $user->ID
                ));
                $wp_activation_key = $activation_key ?: '';
            }
        }

        $response = array(
            'authenticated' => true,
            'user'          => array(
                'id'           => $user->ID,
                'display_name' => $user->display_name,
                'roles'        => $user->roles,
            ),
            'permissions'   => $permissions,
            'dashboard_url' => $license_payload ? $license_payload['dashboard_url'] : 'https://supercluster.visiblelight.ai/',
            'license'       => $license_payload,
            'is_vl_client'  => in_array('vl_client', (array) $user->roles, true),
            'license_key'   => $license_key,
            'wp_activation_key' => $wp_activation_key,
        );

        return rest_ensure_response($response);
    }

    /**
     * REST handler: return the roster of licenses for privileged users.
     */
    public function rest_clients_list($request) {
        $store   = self::lic_store_get();
    $clients = array();
    
        foreach ($store as $key => $row) {
            $clients[] = array(
                'license_key'   => $key,
                'client_name'   => isset($row['client_name']) ? $row['client_name'] : '',
                'status'        => isset($row['status']) ? $row['status'] : '',
                'dashboard_url' => self::lic_dashboard_url($row, $key),
                'contact_email' => isset($row['contact_email']) ? $row['contact_email'] : '',
            );
        }

        return rest_ensure_response(array('clients' => $clients));
    }

    /**
     * REST handler: return constellation data for the Supercluster.
     */
    public function rest_constellation_data($request) {
        // Add CORS headers for this specific endpoint
        $this->add_cors_headers();
        
        $license = sanitize_text_field($request->get_param('license'));
        
        // Get all licenses
        $store = self::lic_store_get();
        $clients = array();
        
        foreach ($store as $key => $row) {
            $clients[] = array(
                'license_id' => $key,
                'license_key' => $key,
                'client' => isset($row['client_name']) ? $row['client_name'] : 'Unassigned Client',
                'site' => isset($row['site']) ? $row['site'] : '',
                'active' => isset($row['status']) && $row['status'] === 'active',
                'created' => isset($row['created']) ? $row['created'] : '',
                'last_seen' => isset($row['last_seen']) ? $row['last_seen'] : '',
                'categories' => self::get_constellation_categories($key)
            );
        }
        
        $response = array(
            'generated_at' => current_time('mysql'),
            'total_clients' => count($clients),
            'clients' => $clients
        );
        
        return rest_ensure_response($response);
    }

    /**
     * Get constellation categories for a license.
     */
    private static function get_constellation_categories($license_key) {
        $categories = array(
            array(
                'slug' => 'identity',
                'name' => 'Identity & Licensing',
                'color' => '#7ee787',
                'icon' => 'visiblelightailogoonly.svg',
                'nodes' => array(
                    array(
                        'id' => 'client',
                        'label' => 'Client',
                        'color' => '#7ee787',
                        'value' => 6,
                        'detail' => 'Unassigned'
                    ),
                    array(
                        'id' => 'site',
                        'label' => 'Primary Site',
                        'color' => '#7ee787',
                        'value' => 6,
                        'detail' => 'https://example.com'
                    ),
                    array(
                        'id' => 'status',
                        'label' => 'License Status',
                        'color' => '#7ee787',
                        'value' => 4,
                        'detail' => 'Inactive'
                    ),
                    array(
                        'id' => 'heartbeat',
                        'label' => 'Last Heartbeat',
                        'color' => '#7ee787',
                        'value' => 5,
                        'detail' => 'No activity recorded'
                    )
                )
            ),
            array(
                'slug' => 'infrastructure',
                'name' => 'Infrastructure & Platform',
                'color' => '#58a6ff',
                'icon' => 'arrows-rotate-reverse-regular-full.svg',
                'nodes' => array(
                    array(
                        'id' => 'https',
                        'label' => 'HTTPS',
                        'color' => '#58a6ff',
                        'value' => 4,
                        'detail' => 'Unknown'
                    )
                )
            ),
            array(
                'slug' => 'security',
                'name' => 'Security & Compliance',
                'color' => '#f85149',
                'icon' => 'eye-slash-light-full.svg',
                'nodes' => array(
                    array(
                        'id' => 'security_placeholder',
                        'label' => 'Security Signals',
                        'color' => '#f85149',
                        'value' => 3,
                        'detail' => 'No security data reported'
                    )
                )
            ),
            array(
                'slug' => 'content',
                'name' => 'Content Universe',
                'color' => '#f2cc60',
                'icon' => 'play-regular-full.svg',
                'nodes' => array(
                    array(
                        'id' => 'content_placeholder',
                        'label' => 'Content Footprint',
                        'color' => '#f2cc60',
                        'value' => 3,
                        'detail' => 'Content metrics not synced yet'
                    )
                )
            ),
            array(
                'slug' => 'plugins',
                'name' => 'Plugin Ecosystem',
                'color' => '#d2a8ff',
                'icon' => 'plus-solid-full.svg',
                'nodes' => array(
                    array(
                        'id' => 'plugins_placeholder',
                        'label' => 'Plugins',
                        'color' => '#d2a8ff',
                        'value' => 3,
                        'detail' => 'Plugins not reported'
                    )
                )
            ),
            array(
                'slug' => 'themes',
                'name' => 'Theme & Experience',
                'color' => '#8b949e',
                'icon' => 'visiblelightailogo.svg',
                'nodes' => array(
                    array(
                        'id' => 'themes_placeholder',
                        'label' => 'Themes',
                        'color' => '#8b949e',
                        'value' => 3,
                        'detail' => 'Theme data not synced'
                    )
                )
            ),
            array(
                'slug' => 'users',
                'name' => 'User Accounts & Roles',
                'color' => '#79c0ff',
                'icon' => 'eye-regular-full.svg',
                'nodes' => array(
                    array(
                        'id' => 'users_placeholder',
                        'label' => 'Users',
                        'color' => '#79c0ff',
                        'value' => 3,
                        'detail' => 'User roster not available'
                    )
                )
            ),
            array(
                'slug' => 'ai',
                'name' => 'AI Conversations',
                'color' => '#bc8cff',
                'icon' => 'visiblelightailogo.svg',
                'nodes' => array(
                    array(
                        'id' => 'conversations_placeholder',
                        'label' => 'AI Chats',
                        'color' => '#bc8cff',
                        'value' => 3,
                        'detail' => 'No conversations logged'
                    )
                )
            ),
            array(
                'slug' => 'sessions',
                'name' => 'Sessions & Engagement',
                'color' => '#56d364',
                'icon' => 'arrows-rotate-reverse-regular-full.svg',
                'nodes' => array(
                    array(
                        'id' => 'sessions_placeholder',
                        'label' => 'Sessions',
                        'color' => '#56d364',
                        'value' => 3,
                        'detail' => 'No session telemetry yet'
                    )
                )
            ),
            array(
                'slug' => 'integrations',
                'name' => 'Integrations & Signals',
                'color' => '#ffa657',
                'icon' => 'minus-solid-full.svg',
                'nodes' => array(
                    array(
                        'id' => 'integrations_placeholder',
                        'label' => 'Cloud Integrations',
                        'color' => '#ffa657',
                        'value' => 3,
                        'detail' => 'No connections synced'
                    )
                )
            )
        );
        
        return $categories;
    }

    /**
     * Creates missing licenses that are referenced in the frontend but don't exist in the store.
     */
    public function maybe_create_missing_licenses() {
        // Only run this once per day to avoid performance issues
        $last_check = get_option('vl_last_license_check', 0);
        if (time() - $last_check < 86400) { // 24 hours
            return;
        }
        
        update_option('vl_last_license_check', time());
        
        $store = self::lic_store_get();
        $missing_licenses = array(
            'VL-GC5K-YKBM-BM5F' => array(
                'client_name' => 'Commonwealth Health Services',
                'site' => 'https://commonwealthhealthservices.com',
                'contact_email' => 'admin@commonwealthhealthservices.com',
                'status' => 'active'
            ),
            'VL-VYAK-9BPQ-NKCC' => array(
                'client_name' => 'Commonwealth Health Services',
                'site' => 'https://commonwealthhealthservices.com',
                'contact_email' => 'admin@commonwealthhealthservices.com',
                'status' => 'active'
            ),
            'VL-H2K3-ZFQK-DKDC' => array(
                'client_name' => 'Site Assembly',
                'site' => 'https://siteassembly.com',
                'contact_email' => 'admin@siteassembly.com',
                'status' => 'active'
            ),
            'VL-AWJJ-8J6S-GD6R' => array(
                'client_name' => 'Visible Light',
                'site' => 'https://visiblelight.ai',
                'contact_email' => 'admin@visiblelight.ai',
                'status' => 'active'
            )
        );
        
        $updated = false;
        foreach ($missing_licenses as $license_key => $license_data) {
            if (!isset($store[$license_key])) {
                $license_data['key'] = $license_key;
                $license_data['created'] = current_time('mysql');
                $license_data['last_seen'] = null;
                $store[$license_key] = $license_data;
                $updated = true;
                error_log('[VL Licenses] Auto-created missing license: ' . $license_key);
            }
        }
        
        if ($updated) {
            self::lic_store_set($store);
        }
    }

    /**
     * Helper accessor for data streams store.
     */
    private static function data_streams_store_get() {
        $store = get_option('vl_data_streams', array());
        return is_array($store) ? $store : array();
    }

    private static function data_streams_store_set($list) {
        update_option('vl_data_streams', is_array($list) ? $list : array());
    }

    /**
     * REST handler: Get category health data.
     * 
     * This endpoint analyzes all data streams for a given license and category,
     * then returns an AI-powered health summary.
     */
    public function rest_category_health($request) {
        $license = sanitize_text_field($request->get_param('license'));
        $category = sanitize_text_field($request->get_param('category'));
        
        if (empty($license) || empty($category)) {
            return rest_ensure_response(array(
                'success' => false,
                'error' => 'License and category parameters are required'
            ));
        }
        
        // Verify license exists and is active
        $license_record = self::lic_lookup_by_key($license);
        if (!$license_record || (isset($license_record['status']) && $license_record['status'] !== 'active')) {
            return rest_ensure_response(array(
                'success' => false,
                'error' => 'Invalid or inactive license'
            ));
        }
        
        // Get all data streams for this license
        $all_streams = self::data_streams_store_get();
        $license_streams = isset($all_streams[$license]) ? $all_streams[$license] : array();
        
        // Filter streams by category
        $category_streams = array_filter($license_streams, function($stream) use ($category) {
            return isset($stream['categories']) && in_array($category, $stream['categories']);
        });
        
        // If no streams found, try to fetch real-time data from client
        if (empty($category_streams)) {
            $real_time_data = self::fetch_client_data($license, $category);
            if ($real_time_data) {
                $category_streams = $real_time_data;
            }
        }
        
        // Generate health summary based on actual data
        $health_summary = self::generate_category_health_analysis($category, $category_streams, $license_record);
        
        return rest_ensure_response(array(
            'success' => true,
            'category' => $category,
            'health_summary' => $health_summary['summary'],
            'metrics' => $health_summary['metrics'],
            'stream_count' => count($category_streams),
            'data_source' => empty($category_streams) ? 'no_data' : 'active_streams'
        ));
    }

    /**
     * REST handler: Get all data streams for a license.
     */
    public function rest_data_streams($request) {
        $license = sanitize_text_field($request->get_param('license'));
        
        if (empty($license)) {
            return rest_ensure_response(array(
                'success' => false,
                'error' => 'License parameter is required'
            ));
        }
        
        // Verify license exists and is active
        $license_record = self::lic_lookup_by_key($license);
        if (!$license_record || (isset($license_record['status']) && $license_record['status'] !== 'active')) {
            return rest_ensure_response(array(
                'success' => false,
                'error' => 'Invalid or inactive license'
            ));
        }
        
        $all_streams = self::data_streams_store_get();
        $license_streams = isset($all_streams[$license]) ? $all_streams[$license] : array();
        
        return rest_ensure_response(array(
            'success' => true,
            'streams' => $license_streams,
            'count' => count($license_streams)
        ));
    }

    /**
     * REST handler: Sync data from client website.
     */
    public function rest_sync_client_data($request) {
        $license = sanitize_text_field($request->get_param('license'));
        $category = sanitize_text_field($request->get_param('category'));
        $data = $request->get_json_params();
        
        if (empty($license)) {
            return rest_ensure_response(array(
                'success' => false,
                'error' => 'License parameter is required'
            ));
        }
        
        // Verify license exists and is active
        $license_record = self::lic_lookup_by_key($license);
        if (!$license_record || (isset($license_record['status']) && $license_record['status'] !== 'active')) {
            return rest_ensure_response(array(
                'success' => false,
                'error' => 'Invalid or inactive license'
            ));
        }
        
        // Process and store the incoming data
        $result = self::process_client_data($license, $category, $data);
        
        return rest_ensure_response(array(
            'success' => $result['success'],
            'message' => $result['message'],
            'streams_updated' => $result['streams_updated']
        ));
    }

    /**
     * REST handler: Complete cloud connection.
     */
    public function rest_complete_cloud_connection($request) {
        $data = $request->get_json_params();
        
        $service_name = sanitize_text_field($data['service_name'] ?? '');
        $token = sanitize_text_field($data['token'] ?? '');
        $api_key = sanitize_text_field($data['api_key'] ?? '');
        $account_id = sanitize_text_field($data['account_id'] ?? '');
        $notes = sanitize_textarea_field($data['notes'] ?? '');
        
        if (empty($service_name) || empty($token)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'Service name and token are required'
            ));
        }
        
        // Verify token
        $token_data = get_option('vl_client_link_token_' . $token, null);
        if (!$token_data || $token_data['expires'] < time()) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'Invalid or expired token'
            ));
        }
        
        // Verify token matches service
        if ($token_data['service_name'] !== $service_name) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'Token does not match service'
            ));
        }
        
        $license_key = $token_data['license_key'];
        
        // Store connection details
        $connection_data = array(
            'service_name' => $service_name,
            'subcategory' => $token_data['subcategory'],
            'api_key' => $api_key,
            'account_id' => $account_id,
            'notes' => $notes,
            'status' => 'connected',
            'connected_at' => current_time('mysql'),
            'connected_by' => 'client'
        );
        
        // Get existing data streams
        $all_streams = self::data_streams_store_get();
        $license_streams = isset($all_streams[$license_key]) ? $all_streams[$license_key] : array();
        
        // Add new connection
        $license_streams[] = $connection_data;
        $all_streams[$license_key] = $license_streams;
        
        // Save updated streams
        self::data_streams_store_set($all_streams);
        
        // Clean up token
        delete_option('vl_client_link_token_' . $token);
        
        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Cloud connection completed successfully',
            'service' => $service_name
        ));
    }

    /**
     * Generates AI-powered health analysis for a category based on real data stream metrics.
     * 
     * @param string $category The category key (e.g., 'infrastructure', 'content')
     * @param array $streams Array of data streams in this category
     * @param array $license_record The license record
     * @return array Health analysis with summary and metrics
     */
    private static function generate_category_health_analysis($category, $streams, $license_record) {
        $stream_count = count($streams);
        $active_count = 0;
        $error_count = 0;
        $warning_count = 0;
        $total_health_score = 0;
        
        // Analyze each stream
        foreach ($streams as $stream) {
            if (isset($stream['status']) && $stream['status'] === 'active') {
                $active_count++;
            }
            
            if (isset($stream['health_score'])) {
                $total_health_score += floatval($stream['health_score']);
            }
            
            if (isset($stream['error_count'])) {
                $error_count += intval($stream['error_count']);
            }
            
            if (isset($stream['warning_count'])) {
                $warning_count += intval($stream['warning_count']);
            }
        }
        
        $avg_health_score = $stream_count > 0 ? ($total_health_score / $stream_count) : 0;
        $uptime_percentage = $stream_count > 0 ? ($active_count / $stream_count) * 100 : 0;
        
        // Generate context-aware summary based on category and metrics
        $summaries = array(
            'infrastructure' => self::generate_infrastructure_summary($avg_health_score, $uptime_percentage, $error_count, $stream_count),
            'content' => self::generate_content_summary($avg_health_score, $stream_count),
            'search' => self::generate_search_summary($avg_health_score, $stream_count),
            'analytics' => self::generate_analytics_summary($avg_health_score, $stream_count),
            'marketing' => self::generate_marketing_summary($avg_health_score, $stream_count),
            'ecommerce' => self::generate_ecommerce_summary($avg_health_score, $stream_count),
            'security' => self::generate_security_summary($avg_health_score, $error_count, $warning_count),
            'cloudops' => self::generate_cloudops_summary($avg_health_score, $uptime_percentage),
            'identity' => self::generate_identity_summary($avg_health_score, $error_count),
            'competitive' => self::generate_competitive_summary($avg_health_score, $stream_count)
        );
        
        $summary = isset($summaries[$category]) ? $summaries[$category] : 
            'System health data is being analyzed. ' . $stream_count . ' data streams are currently monitored in this category.';
        
        return array(
            'summary' => $summary,
            'metrics' => array(
                'stream_count' => $stream_count,
                'active_count' => $active_count,
                'health_score' => round($avg_health_score, 1),
                'uptime_percentage' => round($uptime_percentage, 1),
                'error_count' => $error_count,
                'warning_count' => $warning_count
            )
        );
    }

    // Category-specific summary generators
    private static function generate_infrastructure_summary($health, $uptime, $errors, $count) {
        if ($errors > 0) {
            return "Infrastructure monitoring detected {$errors} issues across {$count} data streams. System health is at " . round($health, 1) . "% with " . round($uptime, 1) . "% uptime. Immediate attention recommended.";
        }
        return "Infrastructure health is optimal with " . round($uptime, 1) . "% uptime across {$count} monitored systems. All infrastructure streams are running smoothly with no critical issues detected.";
    }

    private static function generate_content_summary($health, $count) {
        if ($health >= 80) {
            return "Content management systems are performing excellently across {$count} data streams. SEO optimization and content delivery are operating at peak efficiency.";
        }
        return "Content systems are operational across {$count} streams with a health score of " . round($health, 1) . "%. Some optimization opportunities detected.";
    }

    private static function generate_search_summary($health, $count) {
        if ($health >= 85) {
            return "Search engine visibility is strong with {$count} active monitoring streams. Rankings are stable with positive trends across key metrics.";
        }
        return "Search performance is being monitored across {$count} data streams. Health score: " . round($health, 1) . "%. Focus on keyword optimization recommended.";
    }

    private static function generate_analytics_summary($health, $count) {
        if ($health >= 80) {
            return "Analytics data streams ({$count} total) show positive engagement trends. Data collection and reporting systems are functioning optimally.";
        }
        return "Analytics monitoring active across {$count} streams with " . round($health, 1) . "% health. Some data collection issues may require attention.";
    }

    private static function generate_marketing_summary($health, $count) {
        if ($health >= 85) {
            return "Marketing campaigns are delivering strong results across {$count} monitored channels. All marketing automation systems are performing well.";
        }
        return "Marketing operations monitored across {$count} data streams. Current health: " . round($health, 1) . "%. Campaign performance may need optimization.";
    }

    private static function generate_ecommerce_summary($health, $count) {
        if ($health >= 80) {
            return "E-commerce performance is excellent across {$count} transaction and inventory streams. Payment processing and order management systems are stable.";
        }
        return "E-commerce systems monitored across {$count} streams. Health score: " . round($health, 1) . "%. Review recommended for checkout and inventory processes.";
    }

    private static function generate_security_summary($health, $errors, $warnings) {
        if ($errors > 0 || $warnings > 5) {
            return "Security monitoring has detected {$errors} critical issues and {$warnings} warnings. Immediate security review recommended to maintain system integrity.";
        }
        if ($health >= 95) {
            return "Security posture is robust with no vulnerabilities detected. All security systems are up to date and properly configured. Continuous monitoring active.";
        }
        return "Security systems are operational with " . round($health, 1) . "% health. {$warnings} minor warnings detected. Regular security audits recommended.";
    }

    private static function generate_cloudops_summary($health, $uptime) {
        if ($health >= 85 && $uptime >= 99) {
            return "Cloud infrastructure is running efficiently with " . round($uptime, 1) . "% uptime. Resource utilization is optimal and auto-scaling is functioning correctly.";
        }
        return "Cloud operations health: " . round($health, 1) . "% with " . round($uptime, 1) . "% uptime. Some cloud resource optimization opportunities available.";
    }

    private static function generate_identity_summary($health, $errors) {
        if ($errors > 0) {
            return "User authentication systems have {$errors} reported issues. Identity and access management requires immediate attention.";
        }
        if ($health >= 95) {
            return "User authentication systems are secure and reliable. Single sign-on integration is working seamlessly across all identity providers.";
        }
        return "Identity management health: " . round($health, 1) . "%. Authentication systems are operational but may benefit from security hardening.";
    }

    private static function generate_competitive_summary($health, $count) {
        if ($health >= 80) {
            return "Competitive analysis across {$count} monitoring streams shows strong market positioning. Key performance metrics are trending positively across all tracked channels.";
        }
        return "Competitive intelligence gathered from {$count} data streams. Health: " . round($health, 1) . "%. Market position analysis suggests areas for strategic improvement.";
    }

    /**
     * Helper functions for managing data streams and category assignments
     */

    /**
     * Adds or updates a data stream for a specific license.
     * 
     * @param string $license_key The license key
     * @param string $stream_id Unique identifier for the data stream
     * @param array $stream_data Stream configuration including categories, health metrics, etc.
     * @return bool Success status
     */
    public static function add_data_stream($license_key, $stream_id, $stream_data) {
        if (empty($license_key) || empty($stream_id) || !is_array($stream_data)) {
            return false;
        }

        $all_streams = self::data_streams_store_get();
        
        // Initialize license streams if not exists
        if (!isset($all_streams[$license_key])) {
            $all_streams[$license_key] = array();
        }

        // Ensure required fields
        $stream_data['id'] = $stream_id;
        $stream_data['license_key'] = $license_key;
        $stream_data['created'] = current_time('mysql');
        $stream_data['last_updated'] = current_time('mysql');

        // Set default values if not provided
        if (!isset($stream_data['status'])) {
            $stream_data['status'] = 'active';
        }
        if (!isset($stream_data['health_score'])) {
            $stream_data['health_score'] = 100.0;
        }
        if (!isset($stream_data['categories'])) {
            $stream_data['categories'] = array();
        }
        if (!isset($stream_data['error_count'])) {
            $stream_data['error_count'] = 0;
        }
        if (!isset($stream_data['warning_count'])) {
            $stream_data['warning_count'] = 0;
        }

        $all_streams[$license_key][$stream_id] = $stream_data;
        
        return self::data_streams_store_set($all_streams);
    }

    /**
     * Updates health metrics for a specific data stream.
     * 
     * @param string $license_key The license key
     * @param string $stream_id The stream ID
     * @param array $metrics Health metrics to update
     * @return bool Success status
     */
    public static function update_stream_health($license_key, $stream_id, $metrics) {
        $all_streams = self::data_streams_store_get();
        
        if (!isset($all_streams[$license_key][$stream_id])) {
            return false;
        }

        // Update allowed metrics
        $allowed_metrics = array('health_score', 'error_count', 'warning_count', 'status', 'last_updated');
        
        foreach ($allowed_metrics as $metric) {
            if (isset($metrics[$metric])) {
                $all_streams[$license_key][$stream_id][$metric] = $metrics[$metric];
            }
        }
        
        $all_streams[$license_key][$stream_id]['last_updated'] = current_time('mysql');
        
        return self::data_streams_store_set($all_streams);
    }

    /**
     * Assigns a data stream to one or more categories.
     * 
     * @param string $license_key The license key
     * @param string $stream_id The stream ID
     * @param array $categories Array of category keys
     * @return bool Success status
     */
    public static function assign_stream_categories($license_key, $stream_id, $categories) {
        $all_streams = self::data_streams_store_get();
        
        if (!isset($all_streams[$license_key][$stream_id])) {
            return false;
        }

        // Validate categories against known categories
        $valid_categories = array(
            'infrastructure', 'content', 'search', 'analytics', 
            'marketing', 'ecommerce', 'security', 'cloudops', 
            'identity', 'competitive'
        );
        
        $filtered_categories = array_intersect($categories, $valid_categories);
        
        $all_streams[$license_key][$stream_id]['categories'] = array_values($filtered_categories);
        $all_streams[$license_key][$stream_id]['last_updated'] = current_time('mysql');
        
        return self::data_streams_store_set($all_streams);
    }

    /**
     * Gets all data streams for a specific license and category.
     * 
     * @param string $license_key The license key
     * @param string $category Optional category filter
     * @return array Array of data streams
     */
    public static function get_license_streams($license_key, $category = null) {
        $all_streams = self::data_streams_store_get();
        
        if (!isset($all_streams[$license_key])) {
            return array();
        }

        $streams = $all_streams[$license_key];
        
        if ($category) {
            $streams = array_filter($streams, function($stream) use ($category) {
                return isset($stream['categories']) && in_array($category, $stream['categories']);
            });
        }
        
        return $streams;
    }

    /**
     * Fetches real-time data from client websites based on license and category.
     * 
     * @param string $license_key The license key
     * @param string $category The data category
     * @return array|false Array of data streams or false on failure
     */
    private static function fetch_client_data($license_key, $category) {
        $license_record = self::lic_lookup_by_key($license_key);
        if (!$license_record || !isset($license_record['site'])) {
            return false;
        }
        
        $client_site = $license_record['site'];
        $endpoints = self::get_category_endpoints($category);
        
        $streams = array();
        
        foreach ($endpoints as $endpoint) {
            $url = rtrim($client_site, '/') . '/wp-json/' . $endpoint;
            
            $response = wp_remote_get($url, array(
                'headers' => array(
                    'X-Luna-License' => $license_key,
                    'User-Agent' => 'VL-Hub/1.0'
                ),
                'timeout' => 10,
                'sslverify' => false
            ));
            
            if (is_wp_error($response)) {
                continue;
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if ($data && isset($data['items'])) {
                $stream_id = $category . '_' . str_replace('/', '_', $endpoint);
                $streams[$stream_id] = array(
                    'name' => ucfirst($category) . ' Data Stream',
                    'description' => 'Real-time data from ' . $client_site,
                    'categories' => array($category),
                    'health_score' => 85.0,
                    'error_count' => 0,
                    'warning_count' => 0,
                    'status' => 'active',
                    'last_updated' => current_time('mysql'),
                    'data_count' => count($data['items']),
                    'source_url' => $client_site
                );
            }
        }
        
        return $streams;
    }

    /**
     * Gets the appropriate API endpoints for each category.
     * 
     * @param string $category The data category
     * @return array Array of endpoint paths
     */
    private static function get_category_endpoints($category) {
        $endpoint_map = array(
            'infrastructure' => array('luna_widget/v1/system/site'),
            'content' => array('luna_widget/v1/content/posts', 'luna_widget/v1/content/pages'),
            'identity' => array('luna_widget/v1/users'),
            'security' => array('luna_widget/v1/plugins', 'luna_widget/v1/themes'),
            'analytics' => array('luna_widget/v1/chat/history'),
            'search' => array(), // No direct endpoints yet
            'marketing' => array(), // No direct endpoints yet
            'ecommerce' => array(), // No direct endpoints yet
            'cloudops' => array('luna_widget/v1/system/site'),
            'competitive' => array() // No direct endpoints yet
        );
        
        return isset($endpoint_map[$category]) ? $endpoint_map[$category] : array();
    }

    /**
     * Processes incoming data from client websites and creates/updates data streams.
     * 
     * @param string $license_key The license key
     * @param string $category The data category
     * @param array $data The incoming data
     * @return array Processing result
     */
    private static function process_client_data($license_key, $category, $data) {
        $streams_updated = 0;
        $all_streams = self::data_streams_store_get();
        
        if (!isset($all_streams[$license_key])) {
            $all_streams[$license_key] = array();
        }
        
        // Process different types of data based on category
        switch ($category) {
            case 'infrastructure':
                if (isset($data['system_info'])) {
                    $stream_id = 'system_health_' . time();
                    $all_streams[$license_key][$stream_id] = array(
                        'name' => 'System Health Monitor',
                        'description' => 'Real-time system performance metrics',
                        'categories' => array('infrastructure', 'cloudops'),
                        'health_score' => self::calculate_system_health($data['system_info']),
                        'error_count' => 0,
                        'warning_count' => 0,
                        'status' => 'active',
                        'last_updated' => current_time('mysql'),
                        'data' => $data['system_info']
                    );
                    $streams_updated++;
                }
                break;
                
            case 'content':
                if (isset($data['posts']) || isset($data['pages'])) {
                    $content_count = 0;
                    if (isset($data['posts'])) $content_count += count($data['posts']);
                    if (isset($data['pages'])) $content_count += count($data['pages']);
                    
                    $stream_id = 'content_management_' . time();
                    $all_streams[$license_key][$stream_id] = array(
                        'name' => 'Content Management System',
                        'description' => 'Content creation and management metrics',
                        'categories' => array('content'),
                        'health_score' => 90.0,
                        'error_count' => 0,
                        'warning_count' => 0,
                        'status' => 'active',
                        'last_updated' => current_time('mysql'),
                        'content_count' => $content_count
                    );
                    $streams_updated++;
                }
                break;
                
            case 'analytics':
                if (isset($data['chat_history'])) {
                    $stream_id = 'chat_analytics_' . time();
                    $all_streams[$license_key][$stream_id] = array(
                        'name' => 'Chat Analytics',
                        'description' => 'User interaction and engagement metrics',
                        'categories' => array('analytics', 'identity'),
                        'health_score' => 85.0,
                        'error_count' => 0,
                        'warning_count' => 0,
                        'status' => 'active',
                        'last_updated' => current_time('mysql'),
                        'interaction_count' => count($data['chat_history'])
                    );
                    $streams_updated++;
                }
                break;
                
            case 'security':
                if (isset($data['plugins']) || isset($data['themes'])) {
                    $stream_id = 'security_monitor_' . time();
                    $all_streams[$license_key][$stream_id] = array(
                        'name' => 'Security Monitor',
                        'description' => 'Plugin and theme security status',
                        'categories' => array('security', 'infrastructure'),
                        'health_score' => self::calculate_security_health($data),
                        'error_count' => 0,
                        'warning_count' => 0,
                        'status' => 'active',
                        'last_updated' => current_time('mysql'),
                        'plugin_count' => isset($data['plugins']) ? count($data['plugins']) : 0,
                        'theme_count' => isset($data['themes']) ? count($data['themes']) : 0
                    );
                    $streams_updated++;
                }
                break;
        }
        
        // Save updated streams
        if ($streams_updated > 0) {
            self::data_streams_store_set($all_streams);
        }
        
        return array(
            'success' => true,
            'message' => "Processed {$streams_updated} data streams for {$category}",
            'streams_updated' => $streams_updated
        );
    }

    /**
     * Calculates system health score based on system information.
     */
    private static function calculate_system_health($system_info) {
        $score = 100.0;
        
        // Check memory usage
        if (isset($system_info['memory_usage'])) {
            $memory_percent = floatval($system_info['memory_usage']);
            if ($memory_percent > 90) $score -= 20;
            elseif ($memory_percent > 80) $score -= 10;
        }
        
        // Check PHP version
        if (isset($system_info['php_version'])) {
            $php_version = $system_info['php_version'];
            if (version_compare($php_version, '8.0', '<')) $score -= 15;
            elseif (version_compare($php_version, '7.4', '<')) $score -= 25;
        }
        
        return max(0, min(100, $score));
    }

    /**
     * Calculates security health score based on plugins and themes.
     */
    private static function calculate_security_health($data) {
        $score = 100.0;
        $outdated_count = 0;
        
        if (isset($data['plugins'])) {
            foreach ($data['plugins'] as $plugin) {
                if (isset($plugin['update_available']) && $plugin['update_available']) {
                    $outdated_count++;
                }
            }
        }
        
        if (isset($data['themes'])) {
            foreach ($data['themes'] as $theme) {
                if (isset($theme['update_available']) && $theme['update_available']) {
                    $outdated_count++;
                }
            }
        }
        
        // Deduct points for outdated items
        $score -= ($outdated_count * 5);
        
        return max(0, min(100, $score));
    }

    /**
     * Renders a data source tab with streams, health analysis, and metrics.
     * 
     * @param string $data_source The data source key
     * @param string $description The data source description
     * @param array $client_streams All client streams
     * @param array $license The license record
     * @return string HTML content for the tab
     */
    public static function render_data_source_tab($data_source, $description, $client_streams, $license) {
        // Filter streams for this data source
        $source_streams = array_filter($client_streams, function($stream) use ($data_source) {
            return isset($stream['categories']) && in_array($data_source, $stream['categories']);
        });
        
        // Generate health analysis
        $health_summary = self::generate_category_health_analysis($data_source, $source_streams, $license);
        
        $html = '<div class="vl-data-source-tab">';
        $html .= '<div class="vl-data-source-header" style="background: #f9f9f9; padding: 20px; border-radius: 5px; margin-bottom: 20px;">';
        $html .= '<h3 style="margin: 0 0 10px 0; color: #0073aa;">' . ucfirst($data_source) . '</h3>';
        $html .= '<p style="margin: 0; color: #666; font-size: 14px;">' . esc_html($description) . '</p>';
        $html .= '</div>';
        
        // Health Summary
        $html .= '<div class="vl-health-summary" style="background: white; padding: 20px; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 20px;">';
        $html .= '<h4 style="margin-top: 0; color: #0073aa;">Health Summary</h4>';
        $html .= '<p>' . esc_html($health_summary['summary']) . '</p>';
        $html .= '<div class="vl-health-metrics" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-top: 15px;">';
        $html .= '<div class="vl-metric" style="text-align: center; padding: 10px; background: #f0f0f0; border-radius: 3px;">';
        $html .= '<div style="font-size: 1.5em; font-weight: bold; color: #0073aa;">' . $health_summary['metrics']['stream_count'] . '</div>';
        $html .= '<small>Data Streams</small>';
        $html .= '</div>';
        $html .= '<div class="vl-metric" style="text-align: center; padding: 10px; background: #f0f0f0; border-radius: 3px;">';
        $html .= '<div style="font-size: 1.5em; font-weight: bold; color: ' . ($health_summary['metrics']['health_score'] >= 80 ? '#00a32a' : ($health_summary['metrics']['health_score'] >= 60 ? '#dba617' : '#d63638')) . ';">' . $health_summary['metrics']['health_score'] . '%</div>';
        $html .= '<small>Health Score</small>';
        $html .= '</div>';
        $html .= '<div class="vl-metric" style="text-align: center; padding: 10px; background: #f0f0f0; border-radius: 3px;">';
        $html .= '<div style="font-size: 1.5em; font-weight: bold; color: #00a32a;">' . $health_summary['metrics']['uptime_percentage'] . '%</div>';
        $html .= '<small>Uptime</small>';
        $html .= '</div>';
        $html .= '<div class="vl-metric" style="text-align: center; padding: 10px; background: #f0f0f0; border-radius: 3px;">';
        $html .= '<div style="font-size: 1.5em; font-weight: bold; color: ' . ($health_summary['metrics']['error_count'] > 0 ? '#d63638' : '#00a32a') . ';">' . $health_summary['metrics']['error_count'] . '</div>';
        $html .= '<small>Errors</small>';
        $html .= '</div>';
        
        // Add Interactions metric
        $interactions_count = self::get_interactions_count($license);
        $html .= '<div class="vl-metric vl-interactions-metric" style="text-align: center; padding: 10px; background: #f0f0f0; border-radius: 3px; cursor: pointer;" onclick="showChatTranscript(\'' . esc_js($license['key']) . '\')">';
        $html .= '<div style="font-size: 1.5em; font-weight: bold; color: #0073aa;">' . $interactions_count . '</div>';
        $html .= '<small>Interactions</small>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        
        // Add GA4 Integration for Analytics tab
        if ($data_source === 'analytics') {
            $html .= self::render_ga4_integration($license);
        }
        
        // Data Streams Table
        if (!empty($source_streams)) {
            $html .= '<div class="vl-streams-table" style="background: white; border: 1px solid #ddd; border-radius: 5px; overflow: hidden;">';
            $html .= '<h4 style="margin: 0; padding: 15px; background: #f9f9f9; border-bottom: 1px solid #ddd;">Data Streams</h4>';
            $html .= '<table class="wp-list-table widefat fixed striped" style="margin: 0;">';
            $html .= '<thead>';
            $html .= '<tr>';
            $html .= '<th style="padding: 10px;">Stream Name</th>';
            $html .= '<th style="padding: 10px;">Description</th>';
            $html .= '<th style="padding: 10px;">Health</th>';
            $html .= '<th style="padding: 10px;">Status</th>';
            $html .= '<th style="padding: 10px;">Last Updated</th>';
            $html .= '</tr>';
            $html .= '</thead>';
            $html .= '<tbody>';
            
            foreach ($source_streams as $stream_id => $stream) {
                $html .= '<tr>';
                $html .= '<td style="padding: 10px;"><strong>' . esc_html($stream['name']) . '</strong></td>';
                $html .= '<td style="padding: 10px;">' . esc_html($stream['description']) . '</td>';
                $html .= '<td style="padding: 10px;">';
                $html .= '<span style="color: ' . ($stream['health_score'] >= 80 ? '#00a32a' : ($stream['health_score'] >= 60 ? '#dba617' : '#d63638')) . '; font-weight: bold;">';
                $html .= round($stream['health_score'], 1) . '%';
                $html .= '</span>';
                $html .= '</td>';
                $html .= '<td style="padding: 10px;">';
                $html .= '<span class="vl-status-pill vl-status-' . esc_attr($stream['status']) . '" style="background: ' . ($stream['status'] === 'active' ? '#00a32a' : '#d63638') . '; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px;">';
                $html .= esc_html(ucfirst($stream['status']));
                $html .= '</span>';
                $html .= '</td>';
                $html .= '<td style="padding: 10px;">' . esc_html(isset($stream['last_updated']) ? $stream['last_updated'] : 'Unknown') . '</td>';
                $html .= '</tr>';
            }
            
            $html .= '</tbody>';
            $html .= '</table>';
            $html .= '</div>';
        } else {
            $html .= '<div class="vl-no-streams" style="background: white; padding: 40px; text-align: center; border: 1px solid #ddd; border-radius: 5px;">';
            $html .= '<h4 style="color: #666; margin-bottom: 10px;">No Data Streams Found</h4>';
            $html .= '<p style="color: #999; margin: 0;">No data streams are currently assigned to the ' . $data_source . ' category.</p>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        
        return $html;
    }

    /**
     * Renders GA4 integration section for Analytics tab.
     * 
     * @param array $license The license record
     * @return string HTML content for GA4 integration
     */
    public static function render_ga4_integration($license) {
        $license_key = $license['key'] ?? '';
        $ga4_settings = get_option('vl_ga4_settings_' . $license_key, array());
        
        $html = '<div class="vl-ga4-integration" style="background: white; padding: 20px; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 20px;">';
        $html .= '<h4 style="margin-top: 0; color: #0073aa;">Google Analytics 4 Integration</h4>';
        
        // Handle GA4 settings save
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_ga4_settings']) && check_admin_referer('vl_ga4_nonce')) {
            $ga4_settings = array(
                'ga4_property_id' => sanitize_text_field($_POST['ga4_property_id']),
                'ga4_measurement_id' => sanitize_text_field($_POST['ga4_measurement_id']),
                'ga4_api_key' => sanitize_text_field($_POST['ga4_api_key']),
                'ga4_enabled' => isset($_POST['ga4_enabled']) ? true : false,
                'ga4_credentials' => sanitize_text_field($_POST['ga4_credentials'] ?? ''),
                'last_updated' => current_time('mysql')
            );
            update_option('vl_ga4_settings_' . $license_key, $ga4_settings);
            
            // Test GA4 authentication
            $auth_result = self::test_ga4_authentication($ga4_settings);
            if ($auth_result['success']) {
                $html .= '<div class="notice notice-success"><p>GA4 settings saved and authentication successful!</p></div>';
            } else {
                $html .= '<div class="notice notice-error"><p>GA4 settings saved but authentication failed: ' . esc_html($auth_result['error']) . '</p></div>';
            }
        }
        
        $html .= '<form method="post">';
        $html .= wp_nonce_field('vl_ga4_nonce', '_wpnonce', true, false);
        $html .= '<table class="form-table">';
        $html .= '<tr><th scope="row">Enable GA4 Integration</th>';
        $html .= '<td><label><input type="checkbox" name="ga4_enabled" value="1" ' . checked(isset($ga4_settings['ga4_enabled']) ? $ga4_settings['ga4_enabled'] : false, true, false) . '> Enable Google Analytics 4 integration</label></td></tr>';
        $html .= '<tr><th scope="row">GA4 Property ID</th>';
        $html .= '<td><input type="text" name="ga4_property_id" value="' . esc_attr(isset($ga4_settings['ga4_property_id']) ? $ga4_settings['ga4_property_id'] : '') . '" class="regular-text" placeholder="123456789"></td></tr>';
        $html .= '<tr><th scope="row">Measurement ID</th>';
        $html .= '<td><input type="text" name="ga4_measurement_id" value="' . esc_attr(isset($ga4_settings['ga4_measurement_id']) ? $ga4_settings['ga4_measurement_id'] : '') . '" class="regular-text" placeholder="G-XXXXXXXXXX"></td></tr>';
        $html .= '<tr><th scope="row">API Key</th>';
        $html .= '<td><input type="password" name="ga4_api_key" value="' . esc_attr(isset($ga4_settings['ga4_api_key']) ? $ga4_settings['ga4_api_key'] : '') . '" class="regular-text" placeholder="Your GA4 API Key"></td></tr>';
        $html .= '<tr><th scope="row">Service Account JSON</th>';
        $html .= '<td><textarea name="ga4_credentials" class="large-text" rows="4" placeholder="Paste your GA4 Service Account JSON credentials here">' . esc_textarea(isset($ga4_settings['ga4_credentials']) ? $ga4_settings['ga4_credentials'] : '') . '</textarea></td></tr>';
        $html .= '</table>';
        $html .= '<p class="submit"><input type="submit" name="save_ga4_settings" class="button-primary" value="Save GA4 Settings"></p>';
        $html .= '</form>';
        
        // Show authentication status
        if (!empty($ga4_settings['ga4_enabled'])) {
            $auth_status = self::get_ga4_auth_status($ga4_settings);
            $html .= '<div class="vl-ga4-status" style="margin-top: 15px; padding: 10px; background: ' . ($auth_status['authenticated'] ? '#d4edda' : '#f8d7da') . '; border: 1px solid ' . ($auth_status['authenticated'] ? '#c3e6cb' : '#f5c6cb') . '; border-radius: 4px;">';
            $html .= '<strong>Authentication Status:</strong> ' . ($auth_status['authenticated'] ? 'Connected' : 'Not Connected');
            if (!$auth_status['authenticated'] && !empty($auth_status['error'])) {
                $html .= '<br><small>Error: ' . esc_html($auth_status['error']) . '</small>';
            }
            $html .= '</div>';
        }
        
        $html .= '</div>';
        return $html;
    }

    /**
     * Tests GA4 authentication with provided credentials.
     * 
     * @param array $ga4_settings GA4 configuration
     * @return array Authentication result
     */
    public static function test_ga4_authentication($ga4_settings) {
        if (empty($ga4_settings['ga4_property_id']) || empty($ga4_settings['ga4_api_key'])) {
            return array('success' => false, 'error' => 'Missing required GA4 credentials');
        }
        
        // Test GA4 API connection
        $url = 'https://analyticsdata.googleapis.com/v1beta/properties/' . $ga4_settings['ga4_property_id'] . ':runReport';
        $headers = array(
            'Authorization' => 'Bearer ' . $ga4_settings['ga4_api_key'],
            'Content-Type' => 'application/json'
        );
        
        $body = json_encode(array(
            'dateRanges' => array(
                array('startDate' => '7daysAgo', 'endDate' => 'today')
            ),
            'dimensions' => array(array('name' => 'date')),
            'metrics' => array(array('name' => 'sessions'))
        ));
        
        $response = wp_remote_post($url, array(
            'headers' => $headers,
            'body' => $body,
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code === 200) {
            return array('success' => true, 'message' => 'GA4 authentication successful');
        } else {
            $body = wp_remote_retrieve_body($response);
            $error_data = json_decode($body, true);
            $error_message = isset($error_data['error']['message']) ? $error_data['error']['message'] : 'Unknown error';
            return array('success' => false, 'error' => $error_message);
        }
    }

    /**
     * Gets GA4 authentication status.
     * 
     * @param array $ga4_settings GA4 configuration
     * @return array Authentication status
     */
    public static function get_ga4_auth_status($ga4_settings) {
        if (empty($ga4_settings['ga4_enabled'])) {
            return array('authenticated' => false, 'error' => 'GA4 integration disabled');
        }
        
        $auth_result = self::test_ga4_authentication($ga4_settings);
        return array(
            'authenticated' => $auth_result['success'],
            'error' => $auth_result['success'] ? '' : $auth_result['error']
        );
    }

    /**
     * Gets the total number of chat interactions for a license.
     * 
     * @param array $license The license record
     * @return int Number of interactions
     */
    public static function get_interactions_count($license) {
        $license_key = $license['key'] ?? '';
        if (empty($license_key)) return 0;
        
        // Get interactions count from stored data
        $interactions_data = get_option('vl_interactions_' . $license_key, array());
        return isset($interactions_data['total_interactions']) ? (int)$interactions_data['total_interactions'] : 0;
    }

    /**
     * Gets chat transcript for a specific license.
     * 
     * @param string $license_key The license key
     * @return array Chat transcript data
     */
    public static function get_chat_transcript($license_key) {
        if (empty($license_key)) return array();
        
        // Get chat transcript from stored data
        $transcript_data = get_option('vl_chat_transcript_' . $license_key, array());
        return $transcript_data;
    }

    /**
     * Renders chat transcript modal.
     * 
     * @param string $license_key The license key
     * @return string HTML content for chat transcript modal
     */
    public static function render_chat_transcript_modal($license_key) {
        $transcript = self::get_chat_transcript($license_key);
        
        $html = '<div id="chat-transcript-modal" class="vl-modal" style="display: none; position: fixed; z-index: 100000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">';
        $html .= '<div class="vl-modal-content" style="background-color: #fff; margin: 5% auto; padding: 20px; border-radius: 8px; width: 80%; max-width: 800px; max-height: 80vh; overflow-y: auto;">';
        $html .= '<div class="vl-modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #ddd;">';
        $html .= '<h3 style="margin: 0;">Chat Transcript - License: ' . esc_html($license_key) . '</h3>';
        $html .= '<span class="vl-modal-close" style="font-size: 24px; font-weight: bold; cursor: pointer; color: #666;">&times;</span>';
        $html .= '</div>';
        $html .= '<div class="vl-modal-body" id="chat-transcript-content">';
        
        if (empty($transcript)) {
            $html .= '<p>No chat transcript available for this license.</p>';
        } else {
            $html .= '<div class="vl-chat-transcript" style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 15px; background: #f9f9f9;">';
            foreach ($transcript as $entry) {
                $html .= '<div class="vl-chat-entry" style="margin-bottom: 15px; padding: 10px; border-radius: 5px; background: ' . ($entry['type'] === 'user' ? '#e3f2fd' : '#f5f5f5') . ';">';
                $html .= '<div style="font-weight: bold; color: #333; margin-bottom: 5px;">';
                $html .= ($entry['type'] === 'user' ? '👤 User' : '🤖 Luna') . ' - ' . esc_html($entry['timestamp']);
                $html .= '</div>';
                $html .= '<div style="color: #555;">' . esc_html($entry['message']) . '</div>';
                $html .= '</div>';
            }
            $html .= '</div>';
        }
        
        $html .= '</div>';
        $html .= '<div class="vl-modal-footer" style="margin-top: 20px; text-align: right;">';
        $html .= '<button type="button" class="button" onclick="closeChatTranscript()">Close</button>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }

    /**
     * Renders CloudOps & Infrastructure cloud connections.
     * 
     * @param string $license_key The license key
     * @param array $license The license record
     * @return string HTML content for cloud connections
     */
    public static function render_cloudops_connections($license_key, $license) {
        $html = '<div class="vl-cloud-connections" style="margin-top: 20px;">';
        $html .= '<h4>Cloud Connections</h4>';
        $html .= '<p>Connect to cloud services for infrastructure monitoring and management.</p>';
        
        $connections = array(
            array(
                'name' => 'Microsoft Azure',
                'subcategory' => 'Servers & Hosting',
                'icon' => '🔷',
                'description' => 'Azure cloud services, virtual machines, and hosting',
                'status' => 'disconnected'
            ),
            array(
                'name' => 'Cloudflare',
                'subcategory' => 'CDNs & Firewalls',
                'icon' => '☁️',
                'description' => 'CDN, DDoS protection, and web security',
                'status' => 'disconnected'
            ),
            array(
                'name' => 'Liquid Web',
                'subcategory' => 'Servers & Hosting',
                'icon' => '🌊',
                'description' => 'Managed hosting and server infrastructure',
                'status' => 'disconnected'
            ),
            array(
                'name' => 'Google Cloud',
                'subcategory' => 'Cloud Services',
                'icon' => '☁️',
                'description' => 'Google Cloud Platform services and infrastructure',
                'status' => 'disconnected'
            ),
            array(
                'name' => 'AWS',
                'subcategory' => 'Cloud Services',
                'icon' => '🟠',
                'description' => 'Amazon Web Services cloud infrastructure',
                'status' => 'disconnected'
            ),
            array(
                'name' => 'Lighthouse Insights',
                'subcategory' => 'Performance Analytics',
                'icon' => '🏗️',
                'description' => 'Performance monitoring and optimization',
                'status' => 'disconnected'
            ),
            array(
                'name' => 'GoDaddy',
                'subcategory' => 'Domains & DNS',
                'icon' => '🌐',
                'description' => 'Domain management and DNS services',
                'status' => 'disconnected'
            )
        );
        
        $html .= '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">';
        
        foreach ($connections as $connection) {
            $status_color = $connection['status'] === 'connected' ? '#00a32a' : '#d63638';
            $status_text = $connection['status'] === 'connected' ? 'Connected' : 'Not Connected';
            
            $html .= '<div style="background: white; border: 1px solid #ddd; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
            $html .= '<div style="display: flex; align-items: center; margin-bottom: 15px;">';
            $html .= '<span style="font-size: 24px; margin-right: 10px;">' . $connection['icon'] . '</span>';
            $html .= '<div>';
            $html .= '<h5 style="margin: 0; font-size: 16px; color: #333;">' . esc_html($connection['name']) . '</h5>';
            $html .= '<small style="color: #666;">' . esc_html($connection['subcategory']) . '</small>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '<p style="color: #666; font-size: 14px; margin: 10px 0;">' . esc_html($connection['description']) . '</p>';
            $html .= '<div style="display: flex; justify-content: space-between; align-items: center;">';
            $html .= '<span style="color: ' . $status_color . '; font-weight: bold; font-size: 12px;">' . $status_text . '</span>';
            $html .= '<div style="display: flex; gap: 5px;">';
            $html .= '<button type="button" class="button button-primary" style="font-size: 12px; padding: 5px 10px;">Connect</button>';
            $html .= '<button type="button" class="button button-secondary" style="font-size: 12px; padding: 5px 10px;" onclick="sendClientLink(\'' . esc_js($connection['name']) . '\', \'' . esc_js($connection['subcategory']) . '\')">Send Link to Client</button>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        $html .= '</div>';
        return $html;
    }

    /**
     * Renders Security cloud connections.
     * 
     * @param string $license_key The license key
     * @param array $license The license record
     * @return string HTML content for security connections
     */
    public static function render_security_connections($license_key, $license) {
        $html = '<div class="vl-security-connections" style="margin-top: 20px;">';
        $html .= '<h4>Security Connections</h4>';
        $html .= '<p>Connect to security services for threat detection and protection.</p>';
        
        $connections = array(
            array(
                'name' => 'Cloudflare',
                'subcategory' => 'CDNs & Firewalls',
                'icon' => '☁️',
                'description' => 'DDoS protection, WAF, and security features',
                'status' => 'disconnected'
            ),
            array(
                'name' => 'SSL/TLS Status',
                'subcategory' => 'Certificate Management',
                'icon' => '🔒',
                'description' => 'SSL certificate monitoring and expiry alerts',
                'status' => 'disconnected'
            )
        );
        
        $html .= '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">';
        
        foreach ($connections as $connection) {
            $status_color = $connection['status'] === 'connected' ? '#00a32a' : '#d63638';
            $status_text = $connection['status'] === 'connected' ? 'Connected' : 'Not Connected';
            
            $html .= '<div style="background: white; border: 1px solid #ddd; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
            $html .= '<div style="display: flex; align-items: center; margin-bottom: 15px;">';
            $html .= '<span style="font-size: 24px; margin-right: 10px;">' . $connection['icon'] . '</span>';
            $html .= '<div>';
            $html .= '<h5 style="margin: 0; font-size: 16px; color: #333;">' . esc_html($connection['name']) . '</h5>';
            $html .= '<small style="color: #666;">' . esc_html($connection['subcategory']) . '</small>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '<p style="color: #666; font-size: 14px; margin: 10px 0;">' . esc_html($connection['description']) . '</p>';
            $html .= '<div style="display: flex; justify-content: space-between; align-items: center;">';
            $html .= '<span style="color: ' . $status_color . '; font-weight: bold; font-size: 12px;">' . $status_text . '</span>';
            $html .= '<div style="display: flex; gap: 5px;">';
            $html .= '<button type="button" class="button button-primary" style="font-size: 12px; padding: 5px 10px;">Connect</button>';
            $html .= '<button type="button" class="button button-secondary" style="font-size: 12px; padding: 5px 10px;" onclick="sendClientLink(\'' . esc_js($connection['name']) . '\', \'' . esc_js($connection['subcategory']) . '\')">Send Link to Client</button>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        $html .= '</div>';
        return $html;
    }

    /**
     * Renders Analytics cloud connections.
     * 
     * @param string $license_key The license key
     * @param array $license The license record
     * @return string HTML content for analytics connections
     */
    public static function render_analytics_connections($license_key, $license) {
        $html = '<div class="vl-analytics-connections" style="margin-top: 20px;">';
        $html .= '<h4>Analytics Connections</h4>';
        $html .= '<p>Connect to analytics services for comprehensive data collection.</p>';
        
        $connections = array(
            array(
                'name' => 'Google Analytics 4',
                'subcategory' => 'Site Analytics',
                'icon' => '📊',
                'description' => 'Website traffic and user behavior analytics',
                'status' => 'disconnected'
            ),
            array(
                'name' => 'Lighthouse Insights',
                'subcategory' => 'Performance Analytics',
                'icon' => '🏗️',
                'description' => 'Core Web Vitals and performance monitoring',
                'status' => 'disconnected'
            )
        );
        
        $html .= '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">';
        
        foreach ($connections as $connection) {
            $status_color = $connection['status'] === 'connected' ? '#00a32a' : '#d63638';
            $status_text = $connection['status'] === 'connected' ? 'Connected' : 'Not Connected';
            
            $html .= '<div style="background: white; border: 1px solid #ddd; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
            $html .= '<div style="display: flex; align-items: center; margin-bottom: 15px;">';
            $html .= '<span style="font-size: 24px; margin-right: 10px;">' . $connection['icon'] . '</span>';
            $html .= '<div>';
            $html .= '<h5 style="margin: 0; font-size: 16px; color: #333;">' . esc_html($connection['name']) . '</h5>';
            $html .= '<small style="color: #666;">' . esc_html($connection['subcategory']) . '</small>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '<p style="color: #666; font-size: 14px; margin: 10px 0;">' . esc_html($connection['description']) . '</p>';
            $html .= '<div style="display: flex; justify-content: space-between; align-items: center;">';
            $html .= '<span style="color: ' . $status_color . '; font-weight: bold; font-size: 12px;">' . $status_text . '</span>';
            $html .= '<div style="display: flex; gap: 5px;">';
            $html .= '<button type="button" class="button button-primary" style="font-size: 12px; padding: 5px 10px;">Connect</button>';
            $html .= '<button type="button" class="button button-secondary" style="font-size: 12px; padding: 5px 10px;" onclick="sendClientLink(\'' . esc_js($connection['name']) . '\', \'' . esc_js($connection['subcategory']) . '\')">Send Link to Client</button>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        $html .= '</div>';
        return $html;
    }

    /**
     * Renders Marketing cloud connections.
     * 
     * @param string $license_key The license key
     * @param array $license The license record
     * @return string HTML content for marketing connections
     */
    public static function render_marketing_connections($license_key, $license) {
        $html = '<div class="vl-marketing-connections" style="margin-top: 20px;">';
        $html .= '<h4>Marketing Connections</h4>';
        $html .= '<p>Connect to advertising platforms for campaign management and analytics.</p>';
        
        $connections = array(
            array(
                'name' => 'Google Ads',
                'subcategory' => 'PPC Ads',
                'icon' => '🎯',
                'description' => 'Google advertising campaigns and performance',
                'status' => 'disconnected'
            ),
            array(
                'name' => 'LinkedIn Ads',
                'subcategory' => 'Social Ads',
                'icon' => '💼',
                'description' => 'LinkedIn advertising and professional targeting',
                'status' => 'disconnected'
            ),
            array(
                'name' => 'Meta Ads',
                'subcategory' => 'Social Ads',
                'icon' => '📱',
                'description' => 'Facebook and Instagram advertising campaigns',
                'status' => 'disconnected'
            )
        );
        
        $html .= '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">';
        
        foreach ($connections as $connection) {
            $status_color = $connection['status'] === 'connected' ? '#00a32a' : '#d63638';
            $status_text = $connection['status'] === 'connected' ? 'Connected' : 'Not Connected';
            
            $html .= '<div style="background: white; border: 1px solid #ddd; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
            $html .= '<div style="display: flex; align-items: center; margin-bottom: 15px;">';
            $html .= '<span style="font-size: 24px; margin-right: 10px;">' . $connection['icon'] . '</span>';
            $html .= '<div>';
            $html .= '<h5 style="margin: 0; font-size: 16px; color: #333;">' . esc_html($connection['name']) . '</h5>';
            $html .= '<small style="color: #666;">' . esc_html($connection['subcategory']) . '</small>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '<p style="color: #666; font-size: 14px; margin: 10px 0;">' . esc_html($connection['description']) . '</p>';
            $html .= '<div style="display: flex; justify-content: space-between; align-items: center;">';
            $html .= '<span style="color: ' . $status_color . '; font-weight: bold; font-size: 12px;">' . $status_text . '</span>';
            $html .= '<div style="display: flex; gap: 5px;">';
            $html .= '<button type="button" class="button button-primary" style="font-size: 12px; padding: 5px 10px;">Connect</button>';
            $html .= '<button type="button" class="button button-secondary" style="font-size: 12px; padding: 5px 10px;" onclick="sendClientLink(\'' . esc_js($connection['name']) . '\', \'' . esc_js($connection['subcategory']) . '\')">Send Link to Client</button>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        $html .= '</div>';
        return $html;
    }

    /**
     * Renders Search cloud connections.
     * 
     * @param string $license_key The license key
     * @param array $license The license record
     * @return string HTML content for search connections
     */
    public static function render_search_connections($license_key, $license) {
        $html = '<div class="vl-search-connections" style="margin-top: 20px;">';
        $html .= '<h4>Search Connections</h4>';
        $html .= '<p>Connect to search platforms for SEO monitoring and optimization.</p>';
        
        $connections = array(
            array(
                'name' => 'Google Search Console',
                'subcategory' => 'SEO Analytics',
                'icon' => '🔍',
                'description' => 'Search performance, indexing status, and SEO insights',
                'status' => 'disconnected'
            )
        );
        
        $html .= '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">';
        
        foreach ($connections as $connection) {
            $status_color = $connection['status'] === 'connected' ? '#00a32a' : '#d63638';
            $status_text = $connection['status'] === 'connected' ? 'Connected' : 'Not Connected';
            
            $html .= '<div style="background: white; border: 1px solid #ddd; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
            $html .= '<div style="display: flex; align-items: center; margin-bottom: 15px;">';
            $html .= '<span style="font-size: 24px; margin-right: 10px;">' . $connection['icon'] . '</span>';
            $html .= '<div>';
            $html .= '<h5 style="margin: 0; font-size: 16px; color: #333;">' . esc_html($connection['name']) . '</h5>';
            $html .= '<small style="color: #666;">' . esc_html($connection['subcategory']) . '</small>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '<p style="color: #666; font-size: 14px; margin: 10px 0;">' . esc_html($connection['description']) . '</p>';
            $html .= '<div style="display: flex; justify-content: space-between; align-items: center;">';
            $html .= '<span style="color: ' . $status_color . '; font-weight: bold; font-size: 12px;">' . $status_text . '</span>';
            $html .= '<div style="display: flex; gap: 5px;">';
            $html .= '<button type="button" class="button button-primary" style="font-size: 12px; padding: 5px 10px;">Connect</button>';
            $html .= '<button type="button" class="button button-secondary" style="font-size: 12px; padding: 5px 10px;" onclick="sendClientLink(\'' . esc_js($connection['name']) . '\', \'' . esc_js($connection['subcategory']) . '\')">Send Link to Client</button>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        $html .= '</div>';
        return $html;
    }

    /**
     * Renders WordPress data overview tab.
     * 
     * @param string $license_key The license key
     * @param array $license The license record
     * @return string HTML content for WordPress data tab
     */
    public static function render_wordpress_data_tab($license_key, $license) {
        $html = '<div class="vl-wordpress-data-overview">';
        $html .= '<h3>WordPress Site Overview</h3>';
        $html .= '<p>Comprehensive data collection from the client WordPress site.</p>';
        
        // Get WordPress core status
        $core_status = self::fetch_client_wp_data($license_key, 'wp-core-status');
        if ($core_status) {
            $html .= '<div class="vl-wp-core-status" style="background: white; padding: 20px; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 20px;">';
            $html .= '<h4>WordPress Core Status</h4>';
            $html .= '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">';
            $html .= '<div><strong>Version:</strong> ' . esc_html($core_status['version']) . '</div>';
            $html .= '<div><strong>Update Available:</strong> ' . ($core_status['update_available'] ? 'Yes' : 'No') . '</div>';
            $html .= '<div><strong>PHP Version:</strong> ' . esc_html($core_status['php_version']) . '</div>';
            $html .= '<div><strong>MySQL Version:</strong> ' . esc_html($core_status['mysql_version']) . '</div>';
            $html .= '<div><strong>Memory Limit:</strong> ' . esc_html($core_status['memory_limit']) . '</div>';
            $html .= '<div><strong>Multisite:</strong> ' . ($core_status['is_multisite'] ? 'Yes' : 'No') . '</div>';
            $html .= '</div>';
            $html .= '</div>';
        }
        
        // Get comments count
        $comments_count = self::fetch_client_wp_data($license_key, 'comments-count');
        if ($comments_count) {
            $html .= '<div class="vl-comments-overview" style="background: white; padding: 20px; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 20px;">';
            $html .= '<h4>Comments Overview</h4>';
            $html .= '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">';
            $html .= '<div><strong>Total:</strong> ' . $comments_count['total'] . '</div>';
            $html .= '<div><strong>Approved:</strong> ' . $comments_count['approved'] . '</div>';
            $html .= '<div><strong>Pending:</strong> ' . $comments_count['pending'] . '</div>';
            $html .= '<div><strong>Spam:</strong> ' . $comments_count['spam'] . '</div>';
            $html .= '<div><strong>Trash:</strong> ' . $comments_count['trash'] . '</div>';
            $html .= '</div>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        return $html;
    }

    /**
     * Renders posts tab with SEO scores and detailed information.
     * 
     * @param string $license_key The license key
     * @param array $license The license record
     * @return string HTML content for posts tab
     */
    public static function render_posts_tab($license_key, $license) {
        $html = '<div class="vl-posts-overview">';
        $html .= '<h3>Posts Overview</h3>';
        $html .= '<p>All published posts with SEO scores, categories, and author information.</p>';
        
        $posts_data = self::fetch_client_wp_data($license_key, 'posts');
        if ($posts_data && isset($posts_data['items'])) {
            $html .= '<div class="vl-posts-table" style="background: white; border: 1px solid #ddd; border-radius: 5px; overflow: hidden;">';
            $html .= '<table class="wp-list-table widefat fixed striped">';
            $html .= '<thead><tr><th>Title</th><th>Author</th><th>Categories</th><th>SEO Score</th><th>Date</th><th>Comments</th></tr></thead>';
            $html .= '<tbody>';
            
            foreach ($posts_data['items'] as $post) {
                $seo_score = isset($post['seo_score']) ? $post['seo_score'] : 0;
                $seo_color = $seo_score >= 80 ? '#00a32a' : ($seo_score >= 60 ? '#dba617' : '#d63638');
                
                $html .= '<tr>';
                $html .= '<td><strong>' . esc_html($post['title']) . '</strong><br><small>' . esc_html($post['slug']) . '</small></td>';
                $html .= '<td>' . esc_html($post['author']['display_name']) . '<br><small>' . esc_html($post['author']['email']) . '</small></td>';
                $html .= '<td>' . implode(', ', array_map('esc_html', $post['categories'])) . '</td>';
                $html .= '<td><span style="color: ' . $seo_color . '; font-weight: bold;">' . $seo_score . '%</span></td>';
                $html .= '<td>' . esc_html(date('M j, Y', strtotime($post['date']))) . '</td>';
                $html .= '<td>' . $post['comment_count'] . '</td>';
                $html .= '</tr>';
            }
            
            $html .= '</tbody></table>';
            $html .= '<div style="padding: 15px; background: #f9f9f9; border-top: 1px solid #ddd;">';
            $html .= '<strong>Total Posts:</strong> ' . $posts_data['total'] . ' | ';
            $html .= '<strong>Page:</strong> ' . $posts_data['page'] . ' of ' . ceil($posts_data['total'] / $posts_data['per_page']);
            $html .= '</div>';
            $html .= '</div>';
        } else {
            $html .= '<p>No posts data available.</p>';
        }
        
        $html .= '</div>';
        return $html;
    }

    /**
     * Renders pages tab with SEO scores and detailed information.
     * 
     * @param string $license_key The license key
     * @param array $license The license record
     * @return string HTML content for pages tab
     */
    public static function render_pages_tab($license_key, $license) {
        $html = '<div class="vl-pages-overview">';
        $html .= '<h3>Pages Overview</h3>';
        $html .= '<p>All pages with SEO scores, status, and author information.</p>';
        
        $pages_data = self::fetch_client_wp_data($license_key, 'pages');
        if ($pages_data && isset($pages_data['items'])) {
            $html .= '<div class="vl-pages-table" style="background: white; border: 1px solid #ddd; border-radius: 5px; overflow: hidden;">';
            $html .= '<table class="wp-list-table widefat fixed striped">';
            $html .= '<thead><tr><th>Title</th><th>Author</th><th>Status</th><th>SEO Score</th><th>Date</th><th>Comments</th></tr></thead>';
            $html .= '<tbody>';
            
            foreach ($pages_data['items'] as $page) {
                $seo_score = isset($page['seo_score']) ? $page['seo_score'] : 0;
                $seo_color = $seo_score >= 80 ? '#00a32a' : ($seo_score >= 60 ? '#dba617' : '#d63638');
                $status_color = $page['status'] === 'publish' ? '#00a32a' : '#dba617';
                
                $html .= '<tr>';
                $html .= '<td><strong>' . esc_html($page['title']) . '</strong><br><small>' . esc_html($page['slug']) . '</small></td>';
                $html .= '<td>' . esc_html($page['author']['display_name']) . '<br><small>' . esc_html($page['author']['email']) . '</small></td>';
                $html .= '<td><span style="color: ' . $status_color . '; font-weight: bold;">' . ucfirst($page['status']) . '</span></td>';
                $html .= '<td><span style="color: ' . $seo_color . '; font-weight: bold;">' . $seo_score . '%</span></td>';
                $html .= '<td>' . esc_html(date('M j, Y', strtotime($page['date']))) . '</td>';
                $html .= '<td>' . $page['comment_count'] . '</td>';
                $html .= '</tr>';
            }
            
            $html .= '</tbody></table>';
            $html .= '<div style="padding: 15px; background: #f9f9f9; border-top: 1px solid #ddd;">';
            $html .= '<strong>Total Pages:</strong> ' . $pages_data['total'] . ' | ';
            $html .= '<strong>Page:</strong> ' . $pages_data['page'] . ' of ' . ceil($pages_data['total'] / $pages_data['per_page']);
            $html .= '</div>';
            $html .= '</div>';
        } else {
            $html .= '<p>No pages data available.</p>';
        }
        
        $html .= '</div>';
        return $html;
    }

    /**
     * Renders users tab with detailed user information.
     * 
     * @param string $license_key The license key
     * @param array $license The license record
     * @return string HTML content for users tab
     */
    public static function render_users_tab($license_key, $license) {
        $html = '<div class="vl-users-overview">';
        $html .= '<h3>Users Overview</h3>';
        $html .= '<p>All registered users with their roles and activity information.</p>';
        
        $users_data = self::fetch_client_wp_data($license_key, 'users');
        if ($users_data && isset($users_data['items'])) {
            $html .= '<div class="vl-users-table" style="background: white; border: 1px solid #ddd; border-radius: 5px; overflow: hidden;">';
            $html .= '<table class="wp-list-table widefat fixed striped">';
            $html .= '<thead><tr><th>Username</th><th>Display Name</th><th>Email</th><th>Roles</th><th>Post Count</th><th>Registered</th></tr></thead>';
            $html .= '<tbody>';
            
            foreach ($users_data['items'] as $user) {
                $html .= '<tr>';
                $html .= '<td><strong>' . esc_html($user['username']) . '</strong><br><small>ID: ' . $user['id'] . '</small></td>';
                $html .= '<td>' . esc_html($user['name']) . '</td>';
                $html .= '<td>' . esc_html($user['email']) . '</td>';
                $html .= '<td>' . implode(', ', array_map('esc_html', $user['roles'])) . '</td>';
                $html .= '<td>' . $user['post_count'] . '</td>';
                $html .= '<td>' . esc_html(date('M j, Y', strtotime($user['registered']))) . '</td>';
                $html .= '</tr>';
            }
            
            $html .= '</tbody></table>';
            $html .= '<div style="padding: 15px; background: #f9f9f9; border-top: 1px solid #ddd;">';
            $html .= '<strong>Total Users:</strong> ' . $users_data['total'] . ' | ';
            $html .= '<strong>Page:</strong> ' . $users_data['page'] . ' of ' . ceil($users_data['total'] / $users_data['per_page']);
            $html .= '</div>';
            $html .= '</div>';
        } else {
            $html .= '<p>No users data available.</p>';
        }
        
        $html .= '</div>';
        return $html;
    }

    /**
     * Renders plugins tab with update status information.
     * 
     * @param string $license_key The license key
     * @param array $license The license record
     * @return string HTML content for plugins tab
     */
    public static function render_plugins_tab($license_key, $license) {
        $html = '<div class="vl-plugins-overview">';
        $html .= '<h3>Plugins Overview</h3>';
        $html .= '<p>All installed plugins with their status and update availability.</p>';
        
        $plugins_data = self::fetch_client_wp_data($license_key, 'plugins');
        if ($plugins_data && isset($plugins_data['items'])) {
            $active_count = 0;
            $update_count = 0;
            
            $html .= '<div class="vl-plugins-table" style="background: white; border: 1px solid #ddd; border-radius: 5px; overflow: hidden;">';
            $html .= '<table class="wp-list-table widefat fixed striped">';
            $html .= '<thead><tr><th>Plugin Name</th><th>Version</th><th>Status</th><th>Update Available</th><th>New Version</th></tr></thead>';
            $html .= '<tbody>';
            
            foreach ($plugins_data['items'] as $plugin) {
                if ($plugin['active']) $active_count++;
                if ($plugin['update_available']) $update_count++;
                
                $status_color = $plugin['active'] ? '#00a32a' : '#666';
                $update_color = $plugin['update_available'] ? '#d63638' : '#00a32a';
                
                $html .= '<tr>';
                $html .= '<td><strong>' . esc_html($plugin['name']) . '</strong><br><small>' . esc_html($plugin['slug']) . '</small></td>';
                $html .= '<td>' . esc_html($plugin['version']) . '</td>';
                $html .= '<td><span style="color: ' . $status_color . '; font-weight: bold;">' . ($plugin['active'] ? 'Active' : 'Inactive') . '</span></td>';
                $html .= '<td><span style="color: ' . $update_color . '; font-weight: bold;">' . ($plugin['update_available'] ? 'Yes' : 'No') . '</span></td>';
                $html .= '<td>' . ($plugin['update_available'] ? esc_html($plugin['new_version']) : '-') . '</td>';
                $html .= '</tr>';
            }
            
            $html .= '</tbody></table>';
            $html .= '<div style="padding: 15px; background: #f9f9f9; border-top: 1px solid #ddd;">';
            $html .= '<strong>Total Plugins:</strong> ' . count($plugins_data['items']) . ' | ';
            $html .= '<strong>Active:</strong> ' . $active_count . ' | ';
            $html .= '<strong>Updates Available:</strong> ' . $update_count;
            $html .= '</div>';
            $html .= '</div>';
        } else {
            $html .= '<p>No plugins data available.</p>';
        }
        
        $html .= '</div>';
        return $html;
    }

    /**
     * Renders themes tab with update status information.
     * 
     * @param string $license_key The license key
     * @param array $license The license record
     * @return string HTML content for themes tab
     */
    public static function render_themes_tab($license_key, $license) {
        $html = '<div class="vl-themes-overview">';
        $html .= '<h3>Themes Overview</h3>';
        $html .= '<p>All installed themes with their status and update availability.</p>';
        
        $themes_data = self::fetch_client_wp_data($license_key, 'themes');
        if ($themes_data && isset($themes_data['items'])) {
            $active_count = 0;
            $update_count = 0;
            
            $html .= '<div class="vl-themes-table" style="background: white; border: 1px solid #ddd; border-radius: 5px; overflow: hidden;">';
            $html .= '<table class="wp-list-table widefat fixed striped">';
            $html .= '<thead><tr><th>Theme Name</th><th>Version</th><th>Status</th><th>Update Available</th><th>New Version</th></tr></thead>';
            $html .= '<tbody>';
            
            foreach ($themes_data['items'] as $theme) {
                if ($theme['is_active']) $active_count++;
                if ($theme['update_available']) $update_count++;
                
                $status_color = $theme['is_active'] ? '#00a32a' : '#666';
                $update_color = $theme['update_available'] ? '#d63638' : '#00a32a';
                
                $html .= '<tr>';
                $html .= '<td><strong>' . esc_html($theme['name']) . '</strong><br><small>' . esc_html($theme['stylesheet']) . '</small></td>';
                $html .= '<td>' . esc_html($theme['version']) . '</td>';
                $html .= '<td><span style="color: ' . $status_color . '; font-weight: bold;">' . ($theme['is_active'] ? 'Active' : 'Inactive') . '</span></td>';
                $html .= '<td><span style="color: ' . $update_color . '; font-weight: bold;">' . ($theme['update_available'] ? 'Yes' : 'No') . '</span></td>';
                $html .= '<td>' . ($theme['update_available'] ? esc_html($theme['new_version']) : '-') . '</td>';
                $html .= '</tr>';
            }
            
            $html .= '</tbody></table>';
            $html .= '<div style="padding: 15px; background: #f9f9f9; border-top: 1px solid #ddd;">';
            $html .= '<strong>Total Themes:</strong> ' . count($themes_data['items']) . ' | ';
            $html .= '<strong>Active:</strong> ' . $active_count . ' | ';
            $html .= '<strong>Updates Available:</strong> ' . $update_count;
            $html .= '</div>';
            $html .= '</div>';
        } else {
            $html .= '<p>No themes data available.</p>';
        }
        
        $html .= '</div>';
        return $html;
    }

    /**
     * Renders comments tab with comment statistics.
     * 
     * @param string $license_key The license key
     * @param array $license The license record
     * @return string HTML content for comments tab
     */
    public static function render_comments_tab($license_key, $license) {
        $html = '<div class="vl-comments-overview">';
        $html .= '<h3>Comments Overview</h3>';
        $html .= '<p>Comment statistics and recent comments from the client site.</p>';
        
        // Get comments count
        $comments_count = self::fetch_client_wp_data($license_key, 'comments-count');
        if ($comments_count) {
            $html .= '<div class="vl-comments-stats" style="background: white; padding: 20px; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 20px;">';
            $html .= '<h4>Comment Statistics</h4>';
            $html .= '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">';
            $html .= '<div style="text-align: center; padding: 15px; background: #f0f0f0; border-radius: 5px;">';
            $html .= '<div style="font-size: 2em; font-weight: bold; color: #0073aa;">' . $comments_count['total'] . '</div>';
            $html .= '<div>Total Comments</div>';
            $html .= '</div>';
            $html .= '<div style="text-align: center; padding: 15px; background: #f0f0f0; border-radius: 5px;">';
            $html .= '<div style="font-size: 2em; font-weight: bold; color: #00a32a;">' . $comments_count['approved'] . '</div>';
            $html .= '<div>Approved</div>';
            $html .= '</div>';
            $html .= '<div style="text-align: center; padding: 15px; background: #f0f0f0; border-radius: 5px;">';
            $html .= '<div style="font-size: 2em; font-weight: bold; color: #dba617;">' . $comments_count['pending'] . '</div>';
            $html .= '<div>Pending</div>';
            $html .= '</div>';
            $html .= '<div style="text-align: center; padding: 15px; background: #f0f0f0; border-radius: 5px;">';
            $html .= '<div style="font-size: 2em; font-weight: bold; color: #d63638;">' . $comments_count['spam'] . '</div>';
            $html .= '<div>Spam</div>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';
        }
        
        // Get recent comments
        $comments_data = self::fetch_client_wp_data($license_key, 'comments');
        if ($comments_data && isset($comments_data['items'])) {
            $html .= '<div class="vl-recent-comments" style="background: white; border: 1px solid #ddd; border-radius: 5px; overflow: hidden;">';
            $html .= '<h4 style="margin: 0; padding: 15px; background: #f9f9f9; border-bottom: 1px solid #ddd;">Recent Comments</h4>';
            $html .= '<div style="max-height: 400px; overflow-y: auto;">';
            
            foreach (array_slice($comments_data['items'], 0, 20) as $comment) {
                $html .= '<div style="padding: 15px; border-bottom: 1px solid #eee;">';
                $html .= '<div style="font-weight: bold; color: #333;">' . esc_html($comment['author']) . '</div>';
                $html .= '<div style="color: #666; font-size: 0.9em; margin: 5px 0;">' . esc_html($comment['content']) . '</div>';
                $html .= '<div style="font-size: 0.8em; color: #999;">';
                $html .= 'Post ID: ' . $comment['post_id'] . ' | ';
                $html .= 'Date: ' . esc_html($comment['date']) . ' | ';
                $html .= 'Status: ' . ($comment['approved'] ? 'Approved' : 'Pending');
                $html .= '</div>';
                $html .= '</div>';
            }
            
            $html .= '</div>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        return $html;
    }

    /**
     * Fetches WordPress data from client site.
     * 
     * @param string $license_key The license key
     * @param string $endpoint The API endpoint to call
     * @return array|false The response data or false on failure
     */
    private static function fetch_client_wp_data($license_key, $endpoint) {
        $license = self::lic_lookup_by_key($license_key);
        if (!$license || empty($license['site'])) {
            return false;
        }
        
        $client_url = rtrim($license['site'], '/');
        $api_url = $client_url . '/wp-json/luna_widget/v1/' . $endpoint;
        
        $response = wp_remote_get($api_url, array(
            'timeout' => 10,
            'headers' => array(
                'X-Luna-License' => $license_key
            )
        ));
        
        if (is_wp_error($response)) {
            error_log('[VL Hub] Failed to fetch ' . $endpoint . ' from ' . $client_url . ': ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return $data;
    }

    /**
     * Creates sample data streams for testing and demonstration.
     * This function can be called to populate the system with example data.
     */
    public static function create_sample_data_streams() {
        $sample_licenses = array('VL-GC5K-YKBM-BM5F', 'VL-VYAK-9BPQ-NKCC', 'VL-H2K3-ZFQK-DKDC', 'VL-AWJJ-8J6S-GD6R');
        
        foreach ($sample_licenses as $license) {
            // Infrastructure streams
            self::add_data_stream($license, 'server_monitoring', array(
                'name' => 'Server Health Monitoring',
                'description' => 'Real-time server performance and health metrics',
                'categories' => array('infrastructure', 'cloudops'),
                'health_score' => 95.5,
                'error_count' => 0,
                'warning_count' => 2
            ));
            
            self::add_data_stream($license, 'database_performance', array(
                'name' => 'Database Performance',
                'description' => 'Database query performance and connection monitoring',
                'categories' => array('infrastructure'),
                'health_score' => 88.2,
                'error_count' => 1,
                'warning_count' => 3
            ));

            // Content streams
            self::add_data_stream($license, 'cms_health', array(
                'name' => 'CMS Health Check',
                'description' => 'Content management system status and performance',
                'categories' => array('content'),
                'health_score' => 92.8,
                'error_count' => 0,
                'warning_count' => 1
            ));

            // Search streams
            self::add_data_stream($license, 'seo_rankings', array(
                'name' => 'SEO Rankings Monitor',
                'description' => 'Search engine ranking tracking and analysis',
                'categories' => array('search'),
                'health_score' => 85.3,
                'error_count' => 0,
                'warning_count' => 4
            ));

            // Analytics streams
            self::add_data_stream($license, 'google_analytics', array(
                'name' => 'Google Analytics',
                'description' => 'Website traffic and user behavior analytics',
                'categories' => array('analytics'),
                'health_score' => 97.1,
                'error_count' => 0,
                'warning_count' => 0
            ));

            // Marketing streams
            self::add_data_stream($license, 'email_campaigns', array(
                'name' => 'Email Campaign Performance',
                'description' => 'Email marketing campaign metrics and deliverability',
                'categories' => array('marketing'),
                'health_score' => 89.7,
                'error_count' => 0,
                'warning_count' => 2
            ));

            // E-commerce streams
            self::add_data_stream($license, 'payment_processing', array(
                'name' => 'Payment Processing',
                'description' => 'Payment gateway health and transaction monitoring',
                'categories' => array('ecommerce'),
                'health_score' => 99.2,
                'error_count' => 0,
                'warning_count' => 0
            ));

            // Security streams
            self::add_data_stream($license, 'security_scanner', array(
                'name' => 'Security Vulnerability Scanner',
                'description' => 'Automated security scanning and threat detection',
                'categories' => array('security'),
                'health_score' => 96.8,
                'error_count' => 0,
                'warning_count' => 1
            ));

            // Identity streams
            self::add_data_stream($license, 'user_authentication', array(
                'name' => 'User Authentication System',
                'description' => 'Login system and user session monitoring',
                'categories' => array('identity'),
                'health_score' => 94.5,
                'error_count' => 0,
                'warning_count' => 1
            ));

            // Competitive streams
            self::add_data_stream($license, 'competitor_analysis', array(
                'name' => 'Competitor Analysis',
                'description' => 'Competitive intelligence and market positioning',
                'categories' => array('competitive'),
                'health_score' => 87.3,
                'error_count' => 0,
                'warning_count' => 3
            ));
        }
    }

    /**
     * Renders the client edit screen with data stream management.
     */
    public function render_client_edit_screen($license_key, $license, $messages) {
        $client_name = isset($license['client_name']) ? $license['client_name'] : 'Unknown Client';
        $client_email = isset($license['contact_email']) ? $license['contact_email'] : '';
        $client_site = isset($license['site']) ? $license['site'] : '';
        
        // Get data streams for this license
        $data_streams = self::get_license_streams($license_key);
        
        // Handle form submissions
        if (isset($_POST['action'])) {
            $action = sanitize_text_field(wp_unslash($_POST['action']));
            
            if ('update_client' === $action) {
                check_admin_referer('vl_update_client');
                
                $new_name = sanitize_text_field(wp_unslash($_POST['client_name']));
                $new_email = sanitize_email(wp_unslash($_POST['client_email']));
                $new_site = sanitize_text_field(wp_unslash($_POST['client_site']));
                
                $store = self::lic_store_get();
                if (isset($store[$license_key])) {
                    $store[$license_key]['client_name'] = $new_name;
                    $store[$license_key]['contact_email'] = $new_email;
                    $store[$license_key]['site'] = $new_site;
                    $store[$license_key]['last_updated'] = current_time('mysql');
                    
                    self::lic_store_set($store);
                    $messages['success'][] = 'Client information updated successfully.';
                    
                    // Update license data for display
                    $license = $store[$license_key];
                }
            }
            
            if ('add_data_stream' === $action) {
                check_admin_referer('vl_add_data_stream');
                
                $stream_id = sanitize_text_field(wp_unslash($_POST['stream_id']));
                $stream_name = sanitize_text_field(wp_unslash($_POST['stream_name']));
                $stream_description = sanitize_text_field(wp_unslash($_POST['stream_description']));
                $stream_categories = isset($_POST['stream_categories']) ? array_map('sanitize_text_field', wp_unslash($_POST['stream_categories'])) : array();
                $health_score = floatval($_POST['health_score']);
                
                if ($stream_id && $stream_name) {
                    $result = self::add_data_stream($license_key, $stream_id, array(
                        'name' => $stream_name,
                        'description' => $stream_description,
                        'categories' => $stream_categories,
                        'health_score' => $health_score
                    ));
                    
                    if ($result) {
                        $messages['success'][] = 'Data stream added successfully.';
                        $data_streams = self::get_license_streams($license_key); // Refresh data
                    } else {
                        $messages['error'][] = 'Failed to add data stream. Please check that the Stream ID is unique and try again.';
                        error_log('[VL Data Streams] Failed to add stream: ' . $stream_id . ' for license: ' . $license_key);
                    }
                } else {
                    $messages['error'][] = 'Stream ID and name are required.';
                }
            }
            
            if ('update_stream_health' === $action) {
                check_admin_referer('vl_update_stream_health');
                
                $stream_id = sanitize_text_field(wp_unslash($_POST['stream_id']));
                $health_score = floatval($_POST['health_score']);
                $error_count = intval($_POST['error_count']);
                $warning_count = intval($_POST['warning_count']);
                $status = sanitize_text_field(wp_unslash($_POST['status']));
                
                $result = self::update_stream_health($license_key, $stream_id, array(
                    'health_score' => $health_score,
                    'error_count' => $error_count,
                    'warning_count' => $warning_count,
                    'status' => $status
                ));
                
                if ($result) {
                    $messages['success'][] = 'Stream health updated successfully.';
                    $data_streams = self::get_license_streams($license_key); // Refresh data
                } else {
                    $messages['error'][] = 'Failed to update stream health.';
                }
            }
        }
        
        ?>
        <div class="wrap">
            <h1>Edit Client: <?php echo esc_html($client_name); ?></h1>
            
            <?php if (!empty($messages['success'])) : ?>
                <div class="notice notice-success">
                    <?php foreach ($messages['success'] as $message) : ?>
                        <p><?php echo esc_html($message); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($messages['error'])) : ?>
                <div class="notice notice-error">
                    <?php foreach ($messages['error'] as $message) : ?>
                        <p><?php echo esc_html($message); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <div class="vl-admin-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
                
                <!-- Client Information -->
                <div class="postbox">
                    <h2 class="hndle">Client Information</h2>
                    <div class="inside">
                        <form method="post">
                            <?php wp_nonce_field('vl_update_client'); ?>
                            <input type="hidden" name="action" value="update_client">
                            
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><label for="client_name">Client Name</label></th>
                                    <td><input type="text" id="client_name" name="client_name" value="<?php echo esc_attr($client_name); ?>" class="regular-text" /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="client_email">Email</label></th>
                                    <td><input type="email" id="client_email" name="client_email" value="<?php echo esc_attr($client_email); ?>" class="regular-text" /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="client_site">Website</label></th>
                                    <td><input type="url" id="client_site" name="client_site" value="<?php echo esc_attr($client_site); ?>" class="regular-text" /></td>
                                </tr>
                                <tr>
                                    <th scope="row">License Key</th>
                                    <td><code><?php echo esc_html($license_key); ?></code></td>
                                </tr>
                                <tr>
                                    <th scope="row">Status</th>
                                    <td><?php echo wp_kses_post(self::status_pill_from_row($license)); ?></td>
                                </tr>
                            </table>
                            
                            <p class="submit">
                                <input type="submit" class="button-primary" value="Update Client Information" />
                                <a href="<?php echo esc_url(admin_url('admin.php?page=vl-clients')); ?>" class="button">Back to Clients</a>
                            </p>
                        </form>
                    </div>
                </div>
                
                <!-- Data Streams Management -->
                <div class="postbox">
                    <h2 class="hndle">Data Streams Management</h2>
                    <div class="inside">
                        <h3>Add New Data Stream</h3>
                        <form method="post" style="margin-bottom: 20px;">
                            <?php wp_nonce_field('vl_add_data_stream'); ?>
                            <input type="hidden" name="action" value="add_data_stream">
                            
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><label for="stream_id">Stream ID</label></th>
                                    <td><input type="text" id="stream_id" name="stream_id" class="regular-text" placeholder="e.g., server_monitoring" required /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="stream_name">Stream Name</label></th>
                                    <td><input type="text" id="stream_name" name="stream_name" class="regular-text" placeholder="e.g., Server Health Monitoring" required /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="stream_description">Description</label></th>
                                    <td><textarea id="stream_description" name="stream_description" class="large-text" rows="3" placeholder="What does this stream monitor?"></textarea></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="stream_categories">Categories</label></th>
                                    <td>
                                        <?php
                                        $categories = array(
                                            'infrastructure' => 'Infrastructure',
                                            'content' => 'Content',
                                            'search' => 'Search',
                                            'analytics' => 'Analytics',
                                            'marketing' => 'Marketing',
                                            'ecommerce' => 'E-commerce',
                                            'security' => 'Security',
                                            'cloudops' => 'CloudOps',
                                            'identity' => 'Identity',
                                            'competitive' => 'Competitive'
                                        );
                                        foreach ($categories as $key => $label) : ?>
                                            <label style="display: block; margin-bottom: 5px;">
                                                <input type="checkbox" name="stream_categories[]" value="<?php echo esc_attr($key); ?>" />
                                                <?php echo esc_html($label); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="health_score">Initial Health Score</label></th>
                                    <td><input type="number" id="health_score" name="health_score" min="0" max="100" step="0.1" value="100" class="small-text" /></td>
                                </tr>
                            </table>
                            
                            <p class="submit">
                                <input type="submit" class="button-primary" value="Add Data Stream" />
                            </p>
                        </form>
                        
                        <h3>Existing Data Streams (<?php echo count($data_streams); ?>)</h3>
                        <?php if (empty($data_streams)) : ?>
                            <p>No data streams found. Add one above to get started.</p>
                        <?php else : ?>
                            <table class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Categories</th>
                                        <th>Health</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($data_streams as $stream_id => $stream) : ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo esc_html($stream['name']); ?></strong><br>
                                                <small><?php echo esc_html($stream['description']); ?></small>
                                            </td>
                                            <td>
                                                <?php if (!empty($stream['categories'])) : ?>
                                                    <?php foreach ($stream['categories'] as $category) : ?>
                                                        <span class="vl-category-tag" style="background: #f0f0f0; padding: 2px 6px; border-radius: 3px; font-size: 11px; margin-right: 3px;"><?php echo esc_html(ucfirst($category)); ?></span>
                                                    <?php endforeach; ?>
                                                <?php else : ?>
                                                    <em>No categories assigned</em>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?php echo esc_html(round($stream['health_score'], 1)); ?>%</strong><br>
                                                <small>Errors: <?php echo intval($stream['error_count']); ?> | Warnings: <?php echo intval($stream['warning_count']); ?></small>
                                            </td>
                                            <td>
                                                <span class="vl-status-pill vl-status-<?php echo esc_attr($stream['status']); ?>" style="background: <?php echo $stream['status'] === 'active' ? '#00a32a' : '#d63638'; ?>; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px;">
                                                    <?php echo esc_html(ucfirst($stream['status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button type="button" class="button button-small vl-edit-stream" data-stream-id="<?php echo esc_attr($stream_id); ?>">Edit</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
            .vl-category-tag {
                display: inline-block;
                background: #f0f0f0;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 11px;
                margin-right: 3px;
                margin-bottom: 2px;
            }
            .vl-status-pill {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: bold;
            }
            .vl-status-active {
                background: #00a32a;
                color: white;
            }
            .vl-status-inactive {
                background: #d63638;
                color: white;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            $('.vl-edit-stream').on('click', function() {
                var streamId = $(this).data('stream-id');
                // TODO: Implement inline editing or modal for stream editing
                alert('Stream editing functionality will be implemented here. Stream ID: ' + streamId);
            });
        });
        </script>
        <?php
    }

    /**
     * Renders the VL Hub profile screen with client data tabs.
     */
    public function render_hub_profile_screen() {
        $licenses = self::lic_store_get();
        $selected_license = isset($_GET['license_key']) ? sanitize_text_field(wp_unslash($_GET['license_key'])) : '';
        
        // Get all data streams across all licenses for overview
        $all_streams = self::data_streams_store_get();
        $total_streams = 0;
        $active_streams = 0;
        $total_errors = 0;
        $total_warnings = 0;
        $avg_health = 0;
        $health_count = 0;
        
        foreach ($all_streams as $license_key => $streams) {
            foreach ($streams as $stream) {
                $total_streams++;
                if (isset($stream['status']) && $stream['status'] === 'active') {
                    $active_streams++;
                }
                if (isset($stream['health_score'])) {
                    $avg_health += floatval($stream['health_score']);
                    $health_count++;
                }
                if (isset($stream['error_count'])) {
                    $total_errors += intval($stream['error_count']);
                }
                if (isset($stream['warning_count'])) {
                    $total_warnings += intval($stream['warning_count']);
                }
            }
        }
        
        $avg_health = $health_count > 0 ? round($avg_health / $health_count, 1) : 0;
        ?>
        <div class="wrap">
            <h1>VL Hub Profile - Data Overview</h1>
            
            <div class="vl-hub-overview" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
                <div class="postbox">
                    <h3>Total Data Streams</h3>
                    <div style="font-size: 2em; font-weight: bold; color: #0073aa;"><?php echo $total_streams; ?></div>
                </div>
                <div class="postbox">
                    <h3>Active Streams</h3>
                    <div style="font-size: 2em; font-weight: bold; color: #00a32a;"><?php echo $active_streams; ?></div>
                </div>
                <div class="postbox">
                    <h3>Average Health</h3>
                    <div style="font-size: 2em; font-weight: bold; color: <?php echo $avg_health >= 80 ? '#00a32a' : ($avg_health >= 60 ? '#dba617' : '#d63638'); ?>;"><?php echo $avg_health; ?>%</div>
                </div>
                <div class="postbox">
                    <h3>Total Errors</h3>
                    <div style="font-size: 2em; font-weight: bold; color: <?php echo $total_errors > 0 ? '#d63638' : '#00a32a'; ?>;"><?php echo $total_errors; ?></div>
                </div>
                <div class="postbox">
                    <h3>Total Warnings</h3>
                    <div style="font-size: 2em; font-weight: bold; color: <?php echo $total_warnings > 0 ? '#dba617' : '#00a32a'; ?>;"><?php echo $total_warnings; ?></div>
                </div>
            </div>
            
            <div class="vl-hub-tabs" style="margin-top: 20px;">
                <h2>Client Data Tabs</h2>
                
                <!-- License Selection -->
                <div style="margin-bottom: 20px;">
                    <label for="license-selector">Select Client License:</label>
                    <select id="license-selector" style="min-width: 300px;">
                        <option value="">-- Select a client license --</option>
                        <?php foreach ($licenses as $license_key => $license) : ?>
                            <option value="<?php echo esc_attr($license_key); ?>" <?php selected($selected_license, $license_key); ?>>
                                <?php echo esc_html(isset($license['client_name']) ? $license['client_name'] : 'Unknown Client'); ?> (<?php echo esc_html($license_key); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <?php if ($selected_license && isset($licenses[$selected_license])) : ?>
                    <?php
                    $license = $licenses[$selected_license];
                    $client_streams = self::get_license_streams($selected_license);
                    $client_name = isset($license['client_name']) ? $license['client_name'] : 'Unknown Client';
                    ?>
                    
                    <div class="vl-client-profile">
                        <h3>Client: <?php echo esc_html($client_name); ?></h3>
                        <p><strong>License:</strong> <?php echo esc_html($selected_license); ?></p>
                        <p><strong>Website:</strong> <?php echo esc_html(isset($license['site']) ? $license['site'] : 'Not specified'); ?></p>
                        <p><strong>Email:</strong> <?php echo esc_html(isset($license['contact_email']) ? $license['contact_email'] : 'Not specified'); ?></p>
                        
                        <div class="vl-client-tabs" style="margin-top: 20px;">
                            <div class="tab-buttons" style="border-bottom: 1px solid #ddd; margin-bottom: 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 5px;">
                                <button class="tab-button active" data-tab="infrastructure">CloudOps & Infrastructure</button>
                                <button class="tab-button" data-tab="content">Content</button>
                                <button class="tab-button" data-tab="search">Search</button>
                                <button class="tab-button" data-tab="analytics">Analytics</button>
                                <button class="tab-button" data-tab="marketing">Marketing</button>
                                <button class="tab-button" data-tab="ecommerce">E-commerce</button>
                                <button class="tab-button" data-tab="security">Security</button>
                                <button class="tab-button" data-tab="cloudops">CloudOps</button>
                                <button class="tab-button" data-tab="identity">Identity</button>
                                <button class="tab-button" data-tab="competitive">Competitive</button>
                                <button class="tab-button" data-tab="wordpress-data">WordPress Data</button>
                                <button class="tab-button" data-tab="posts">Posts</button>
                                <button class="tab-button" data-tab="pages">Pages</button>
                                <button class="tab-button" data-tab="users">Users</button>
                                <button class="tab-button" data-tab="plugins">Plugins</button>
                                <button class="tab-button" data-tab="themes">Themes</button>
                                <button class="tab-button" data-tab="comments">Comments</button>
                            </div>
                            
                            <!-- CloudOps & Infrastructure Tab -->
                            <div class="tab-content active" id="tab-infrastructure">
                                <?php echo self::render_data_source_tab('infrastructure', 'Server uptime, error detection, system health', $client_streams, $license); ?>
                                <?php echo self::render_cloudops_connections($selected_license, $license); ?>
                            </div>
                            
                            <!-- Content Tab -->
                            <div class="tab-content" id="tab-content">
                                <?php echo self::render_data_source_tab('content', 'CMS performance, SEO optimization, content delivery', $client_streams, $license); ?>
                            </div>
                            
                            <!-- Search Tab -->
                            <div class="tab-content" id="tab-search">
                                <?php echo self::render_data_source_tab('search', 'Ranking stability, keyword performance, visibility', $client_streams, $license); ?>
                                <?php echo self::render_search_connections($selected_license, $license); ?>
                            </div>
                            
                            <!-- Analytics Tab -->
                            <div class="tab-content" id="tab-analytics">
                                <?php echo self::render_data_source_tab('analytics', 'Data collection, engagement trends, reporting', $client_streams, $license); ?>
                                <?php echo self::render_analytics_connections($selected_license, $license); ?>
                            </div>
                            
                            <!-- Marketing Tab -->
                            <div class="tab-content" id="tab-marketing">
                                <?php echo self::render_data_source_tab('marketing', 'Campaign performance, ROI, automation health', $client_streams, $license); ?>
                                <?php echo self::render_marketing_connections($selected_license, $license); ?>
                            </div>
                            
                            <!-- E-commerce Tab -->
                            <div class="tab-content" id="tab-ecommerce">
                                <?php echo self::render_data_source_tab('ecommerce', 'Transaction processing, inventory, conversion rates', $client_streams, $license); ?>
                            </div>
                            
                            <!-- Security Tab -->
                            <div class="tab-content" id="tab-security">
                                <?php echo self::render_data_source_tab('security', 'Vulnerability scanning, threat detection, compliance', $client_streams, $license); ?>
                                <?php echo self::render_security_connections($selected_license, $license); ?>
                            </div>
                            
                            <!-- CloudOps Tab -->
                            <div class="tab-content" id="tab-cloudops">
                                <?php echo self::render_data_source_tab('cloudops', 'Resource utilization, auto-scaling, uptime', $client_streams, $license); ?>
                            </div>
                            
                            <!-- Identity Tab -->
                            <div class="tab-content" id="tab-identity">
                                <?php echo self::render_data_source_tab('identity', 'Authentication systems, SSO, user management', $client_streams, $license); ?>
                            </div>
                            
                            <!-- Competitive Tab -->
                            <div class="tab-content" id="tab-competitive">
                                <?php echo self::render_data_source_tab('competitive', 'Market positioning, competitor analysis, trends', $client_streams, $license); ?>
                            </div>
                            
                            <!-- WordPress Data Tab -->
                            <div class="tab-content" id="tab-wordpress-data">
                                <?php echo self::render_wordpress_data_tab($selected_license, $license); ?>
                            </div>
                            
                            <!-- Posts Tab -->
                            <div class="tab-content" id="tab-posts">
                                <?php echo self::render_posts_tab($selected_license, $license); ?>
                            </div>
                            
                            <!-- Pages Tab -->
                            <div class="tab-content" id="tab-pages">
                                <?php echo self::render_pages_tab($selected_license, $license); ?>
                            </div>
                            
                            <!-- Users Tab -->
                            <div class="tab-content" id="tab-users">
                                <?php echo self::render_users_tab($selected_license, $license); ?>
                            </div>
                            
                            <!-- Plugins Tab -->
                            <div class="tab-content" id="tab-plugins">
                                <?php echo self::render_plugins_tab($selected_license, $license); ?>
                            </div>
                            
                            <!-- Themes Tab -->
                            <div class="tab-content" id="tab-themes">
                                <?php echo self::render_themes_tab($selected_license, $license); ?>
                            </div>
                            
                            <!-- Comments Tab -->
                            <div class="tab-content" id="tab-comments">
                                <?php echo self::render_comments_tab($selected_license, $license); ?>
                            </div>
                        </div>
                    </div>
                <?php else : ?>
                    <p>Please select a client license to view their data.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
            .vl-category-tag {
                display: inline-block;
                background: #f0f0f0;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 11px;
                margin-right: 3px;
                margin-bottom: 2px;
            }
            .vl-status-pill {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: bold;
            }
            .vl-status-active {
                background: #00a32a;
                color: white;
            }
            .vl-status-inactive {
                background: #d63638;
                color: white;
            }
            .tab-button {
                background: none;
                border: none;
                padding: 10px 20px;
                cursor: pointer;
                border-bottom: 2px solid transparent;
            }
            .tab-button.active {
                border-bottom-color: #0073aa;
                background: #f0f0f0;
            }
            .tab-content {
                display: none;
            }
            .tab-content.active {
                display: block;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // License selector
            $('#license-selector').on('change', function() {
                var licenseKey = $(this).val();
                if (licenseKey) {
                    window.location.href = '<?php echo admin_url('admin.php?page=vl-hub-profile'); ?>&license_key=' + encodeURIComponent(licenseKey);
                }
            });
            
            // Tab switching with auto-scroll
            $('.tab-button').on('click', function() {
                var tab = $(this).data('tab');
                $('.tab-button').removeClass('active');
                $('.tab-content').removeClass('active');
                $(this).addClass('active');
                $('#tab-' + tab).addClass('active');
                
                // Auto-scroll to the tab content
                var tabContent = $('#tab-' + tab);
                if (tabContent.length) {
                    $('html, body').animate({
                        scrollTop: tabContent.offset().top - 100
                    }, 500);
                }
            });
        });
        
        // Send client link functionality
        function sendClientLink(serviceName, subcategory) {
            if (confirm('Send a secure link to the client to complete the ' + serviceName + ' connection?')) {
                // Get current license key from URL
                const urlParams = new URLSearchParams(window.location.search);
                const licenseKey = urlParams.get('license_key');
                
                if (!licenseKey) {
                    alert('License key not found. Please refresh the page and try again.');
                    return;
                }
                
                // Show loading state
                const button = event.target;
                const originalText = button.textContent;
                button.textContent = 'Sending...';
                button.disabled = true;
                
                // Send AJAX request to create and send the link
                jQuery.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'vl_send_client_link',
                        license_key: licenseKey,
                        service_name: serviceName,
                        subcategory: subcategory,
                        nonce: '<?php echo wp_create_nonce('vl_send_client_link_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Secure link sent to client email successfully!');
                        } else {
                            alert('Error sending link: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('Error sending link. Please try again.');
                    },
                    complete: function() {
                        button.textContent = originalText;
                        button.disabled = false;
                    }
                });
            }
        }

        // Chat transcript functionality
        function showChatTranscript(licenseKey) {
            // Create modal if it doesn't exist
            if (!document.getElementById('chat-transcript-modal')) {
                var modal = document.createElement('div');
                modal.innerHTML = '<?php echo addslashes(self::render_chat_transcript_modal($selected_license)); ?>';
                document.body.appendChild(modal.firstElementChild);
            }
            
            // Show modal
            document.getElementById('chat-transcript-modal').style.display = 'block';
            
            // Load transcript data
            loadChatTranscript(licenseKey);
        }
        
        function closeChatTranscript() {
            document.getElementById('chat-transcript-modal').style.display = 'none';
        }
        
        function loadChatTranscript(licenseKey) {
            // Make AJAX request to get chat transcript
            jQuery.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'vl_get_chat_transcript',
                    license_key: licenseKey,
                    nonce: '<?php echo wp_create_nonce('vl_chat_transcript_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        var content = document.getElementById('chat-transcript-content');
                        if (response.data.transcript && response.data.transcript.length > 0) {
                            var html = '<div class="vl-chat-transcript" style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 15px; background: #f9f9f9;">';
                            response.data.transcript.forEach(function(entry) {
                                html += '<div class="vl-chat-entry" style="margin-bottom: 15px; padding: 10px; border-radius: 5px; background: ' + (entry.type === 'user' ? '#e3f2fd' : '#f5f5f5') + ';">';
                                html += '<div style="font-weight: bold; color: #333; margin-bottom: 5px;">';
                                html += (entry.type === 'user' ? '👤 User' : '🤖 Luna') + ' - ' + entry.timestamp;
                                html += '</div>';
                                html += '<div style="color: #555;">' + entry.message + '</div>';
                                html += '</div>';
                            });
                            html += '</div>';
                            content.innerHTML = html;
                        } else {
                            content.innerHTML = '<p>No chat transcript available for this license.</p>';
                        }
                    } else {
                        document.getElementById('chat-transcript-content').innerHTML = '<p>Error loading chat transcript: ' + response.data + '</p>';
                    }
                },
                error: function() {
                    document.getElementById('chat-transcript-content').innerHTML = '<p>Error loading chat transcript. Please try again.</p>';
                }
            });
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            var modal = document.getElementById('chat-transcript-modal');
            if (event.target === modal) {
                closeChatTranscript();
            }
        }
        </script>
        <?php
    }
}

// AJAX handler for chat transcript
add_action('wp_ajax_vl_get_chat_transcript', function() {
    check_ajax_referer('vl_chat_transcript_nonce', 'nonce');
    
    $license_key = sanitize_text_field($_POST['license_key'] ?? '');
    if (empty($license_key)) {
        wp_send_json_error('License key required');
        return;
    }
    
    $transcript = VL_License_Manager::get_chat_transcript($license_key);
    wp_send_json_success(array('transcript' => $transcript));
});

// AJAX handler for sending client links
add_action('wp_ajax_vl_send_client_link', function() {
    check_ajax_referer('vl_send_client_link_nonce', 'nonce');
    
    $license_key = sanitize_text_field($_POST['license_key'] ?? '');
    $service_name = sanitize_text_field($_POST['service_name'] ?? '');
    $subcategory = sanitize_text_field($_POST['subcategory'] ?? '');
    
    if (empty($license_key) || empty($service_name)) {
        wp_send_json_error('License key and service name required');
        return;
    }
    
    // Get license information
    $license = VL_License_Manager::lic_lookup_by_key($license_key);
    if (!$license) {
        wp_send_json_error('License not found');
        return;
    }
    
    // Get client email
    $client_email = $license['contact_email'] ?? '';
    if (empty($client_email)) {
        wp_send_json_error('Client email not found for this license');
        return;
    }
    
    // Generate secure token
    $token = wp_generate_password(32, false);
    $expires = time() + (24 * 60 * 60); // 24 hours
    
    // Store token in database
    $token_data = array(
        'license_key' => $license_key,
        'service_name' => $service_name,
        'subcategory' => $subcategory,
        'expires' => $expires,
        'created' => time()
    );
    
    update_option('vl_client_link_token_' . $token, $token_data, false);
    
    // Generate secure link
    $secure_link = 'https://supercluster.visiblelight.ai/?license=' . $license_key . '&cloud_connection=' . urlencode($service_name) . '&token=' . $token;
    
    // Send email
    $subject = 'Complete Your ' . $service_name . ' Connection - Visible Light AI';
    $message = "
    <html>
    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
            <h2 style='color: #2B6AFF;'>Complete Your Cloud Connection</h2>
            <p>Hello,</p>
            <p>You have been invited to complete your <strong>" . esc_html($service_name) . "</strong> connection for your Visible Light AI Constellation.</p>
            <p><strong>Service:</strong> " . esc_html($service_name) . "<br>
            <strong>Category:</strong> " . esc_html($subcategory) . "</p>
            <p>Click the button below to securely complete your connection:</p>
            <div style='text-align: center; margin: 30px 0;'>
                <a href='" . esc_url($secure_link) . "' style='background: #2B6AFF; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;'>Complete Connection</a>
            </div>
            <p><strong>This link will expire in 24 hours.</strong></p>
            <p>If you have any questions, please contact your Visible Light AI administrator.</p>
            <hr style='margin: 30px 0; border: none; border-top: 1px solid #eee;'>
            <p style='font-size: 12px; color: #666;'>This is an automated message from Visible Light AI. Please do not reply to this email.</p>
        </div>
    </body>
    </html>
    ";
    
    $headers = array('Content-Type: text/html; charset=UTF-8');
    $sent = wp_mail($client_email, $subject, $message, $headers);
    
    if ($sent) {
        wp_send_json_success('Link sent successfully to ' . $client_email);
    } else {
        wp_send_json_error('Failed to send email');
    }
});

// Bootstrap the plugin once WordPress loads plugins.
add_action('plugins_loaded', array('VL_License_Manager', 'instance'));

register_activation_hook(__FILE__, array('VL_License_Manager', 'activate'));
register_deactivation_hook(__FILE__, array('VL_License_Manager', 'deactivate'));