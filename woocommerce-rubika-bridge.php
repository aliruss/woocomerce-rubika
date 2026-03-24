<?php
/**
 * Plugin Name: WooCommerce Rubika Bridge
 * Description: Lightweight WooCommerce to Rubika publisher with queue, scheduling, and per-product controls.
 * Version: 1.0.0
 * Author: Codex
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WCRB_Plugin')) {
    class WCRB_Plugin {
        const OPTION_KEY = 'wcrb_settings';
        const LAST_SENT_OPTION = 'wcrb_last_sent_at';
        const CRON_HOOK = 'wcrb_process_queue_event';
        const TABLE_SUFFIX = 'wcrb_queue';

        public function __construct() {
            register_activation_hook(__FILE__, array($this, 'activate'));
            register_deactivation_hook(__FILE__, array($this, 'deactivate'));

            add_action('admin_menu', array($this, 'register_admin_menu'));
            add_action('admin_init', array($this, 'register_settings'));

            add_action('admin_post_wcrb_enqueue_all', array($this, 'handle_enqueue_all'));
            add_action('admin_post_wcrb_enqueue_single', array($this, 'handle_enqueue_single'));

            add_action('admin_bar_menu', array($this, 'admin_bar_publish_button'), 100);
            add_action('admin_notices', array($this, 'admin_notice'));

            add_action(self::CRON_HOOK, array($this, 'process_queue'));
            add_filter('cron_schedules', array($this, 'register_cron_schedules'));
        }

        public function activate() {
            $this->maybe_create_table();

            $defaults = $this->default_settings();
            $current = get_option(self::OPTION_KEY, array());
            update_option(self::OPTION_KEY, wp_parse_args($current, $defaults));

            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time() + 60, 'wcrb_every_minute', self::CRON_HOOK);
            }
        }

        public function deactivate() {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }

        public function register_cron_schedules($schedules) {
            if (!isset($schedules['wcrb_every_minute'])) {
                $schedules['wcrb_every_minute'] = array(
                    'interval' => 60,
                    'display'  => __('Every Minute (WCRB)', 'wcrb'),
                );
            }
            return $schedules;
        }

        private function default_settings() {
            return array(
                'bot_token' => 'JAIHJ0LIWGEOQKKWPBQFQKBEFSUAFZQIDYBFOTKDPUEQNSYTCAWPXPJEISIACNAP',
                'channel' => '@behdashtik_site',
                'website_url' => home_url('/'),
                'template' => "🛍️ {title}\n\n{short_description}\n\n💰 {price}",
                'image_count' => 1,
                'excluded_images' => '',
                'interval_minutes' => 15,
                'send_window_start' => '09:00',
                'send_window_end' => '22:00',
                'disable_notification' => 0,
            );
        }

        private function get_settings() {
            return wp_parse_args(get_option(self::OPTION_KEY, array()), $this->default_settings());
        }

        private function maybe_create_table() {
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_SUFFIX;
            $charset_collate = $wpdb->get_charset_collate();

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $sql = "CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                product_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                error_message TEXT NULL,
                scheduled_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                sent_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY status_scheduled (status, scheduled_at),
                KEY product_id (product_id)
            ) {$charset_collate};";

            dbDelta($sql);
        }

        public function register_admin_menu() {
            add_submenu_page(
                'woocommerce',
                __('Rubika Bridge', 'wcrb'),
                __('Rubika Bridge', 'wcrb'),
                'manage_woocommerce',
                'wcrb-settings',
                array($this, 'render_settings_page')
            );
        }

        public function register_settings() {
            register_setting('wcrb_settings_group', self::OPTION_KEY, array($this, 'sanitize_settings'));
        }

        public function sanitize_settings($input) {
            $sanitized = $this->default_settings();
            $sanitized['bot_token'] = sanitize_text_field($input['bot_token'] ?? '');
            $sanitized['channel'] = sanitize_text_field($input['channel'] ?? '');
            $sanitized['website_url'] = esc_url_raw($input['website_url'] ?? home_url('/'));
            $sanitized['template'] = wp_kses_post($input['template'] ?? '');
            $sanitized['image_count'] = max(0, absint($input['image_count'] ?? 1));
            $sanitized['excluded_images'] = sanitize_text_field($input['excluded_images'] ?? '');
            $sanitized['interval_minutes'] = max(1, absint($input['interval_minutes'] ?? 15));
            $sanitized['send_window_start'] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $input['send_window_start'] ?? '') ? $input['send_window_start'] : '09:00';
            $sanitized['send_window_end'] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $input['send_window_end'] ?? '') ? $input['send_window_end'] : '22:00';
            $sanitized['disable_notification'] = !empty($input['disable_notification']) ? 1 : 0;
            return $sanitized;
        }

        public function render_settings_page() {
            if (!current_user_can('manage_woocommerce')) {
                return;
            }

            $settings = $this->get_settings();
            list($synced, $unsynced) = $this->product_sync_counts();
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('WooCommerce Rubika Bridge', 'wcrb'); ?></h1>
                <p><?php echo esc_html(sprintf(__('Synced products: %d | Unsynced products: %d', 'wcrb'), $synced, $unsynced)); ?></p>

                <form method="post" action="options.php">
                    <?php settings_fields('wcrb_settings_group'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="wcrb_bot_token"><?php esc_html_e('Bot Token', 'wcrb'); ?></label></th>
                            <td><input type="text" id="wcrb_bot_token" name="<?php echo esc_attr(self::OPTION_KEY); ?>[bot_token]" class="regular-text" value="<?php echo esc_attr($settings['bot_token']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcrb_channel"><?php esc_html_e('Rubika Channel', 'wcrb'); ?></label></th>
                            <td><input type="text" id="wcrb_channel" name="<?php echo esc_attr(self::OPTION_KEY); ?>[channel]" class="regular-text" value="<?php echo esc_attr($settings['channel']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcrb_website_url"><?php esc_html_e('Website URL', 'wcrb'); ?></label></th>
                            <td><input type="url" id="wcrb_website_url" name="<?php echo esc_attr(self::OPTION_KEY); ?>[website_url]" class="regular-text" value="<?php echo esc_attr($settings['website_url']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcrb_template"><?php esc_html_e('Message Template (supports {title}, {short_description}, {price}, {url})', 'wcrb'); ?></label></th>
                            <td><textarea id="wcrb_template" name="<?php echo esc_attr(self::OPTION_KEY); ?>[template]" rows="8" class="large-text code"><?php echo esc_textarea($settings['template']); ?></textarea></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcrb_image_count"><?php esc_html_e('Number of images per product', 'wcrb'); ?></label></th>
                            <td><input type="number" min="0" id="wcrb_image_count" name="<?php echo esc_attr(self::OPTION_KEY); ?>[image_count]" value="<?php echo esc_attr($settings['image_count']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcrb_excluded_images"><?php esc_html_e('Excluded image attachment IDs (comma separated)', 'wcrb'); ?></label></th>
                            <td><input type="text" id="wcrb_excluded_images" name="<?php echo esc_attr(self::OPTION_KEY); ?>[excluded_images]" class="regular-text" value="<?php echo esc_attr($settings['excluded_images']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcrb_interval_minutes"><?php esc_html_e('Publish interval (minutes)', 'wcrb'); ?></label></th>
                            <td><input type="number" min="1" id="wcrb_interval_minutes" name="<?php echo esc_attr(self::OPTION_KEY); ?>[interval_minutes]" value="<?php echo esc_attr($settings['interval_minutes']); ?>">
                                <p class="description"><?php esc_html_e('Recommended: 10-20 minutes for medium stores.', 'wcrb'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Daily publish window', 'wcrb'); ?></th>
                            <td>
                                <input type="time" name="<?php echo esc_attr(self::OPTION_KEY); ?>[send_window_start]" value="<?php echo esc_attr($settings['send_window_start']); ?>"> -
                                <input type="time" name="<?php echo esc_attr(self::OPTION_KEY); ?>[send_window_end]" value="<?php echo esc_attr($settings['send_window_end']); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcrb_disable_notification"><?php esc_html_e('Disable Rubika notification', 'wcrb'); ?></label></th>
                            <td><label><input type="checkbox" id="wcrb_disable_notification" name="<?php echo esc_attr(self::OPTION_KEY); ?>[disable_notification]" value="1" <?php checked((int) $settings['disable_notification'], 1); ?>> <?php esc_html_e('Send silently', 'wcrb'); ?></label></td>
                        </tr>
                    </table>
                    <?php submit_button(); ?>
                </form>

                <hr>
                <h2><?php esc_html_e('Bulk queue', 'wcrb'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('wcrb_enqueue_all'); ?>
                    <input type="hidden" name="action" value="wcrb_enqueue_all">
                    <?php submit_button(__('Queue all products by category order', 'wcrb'), 'secondary', 'submit', false); ?>
                </form>
            </div>
            <?php
        }

        private function product_sync_counts() {
            $query = new WP_Query(array(
                'post_type' => 'product',
                'post_status' => 'publish',
                'fields' => 'ids',
                'nopaging' => true,
            ));

            $synced = 0;
            $total = count($query->posts);
            foreach ($query->posts as $product_id) {
                if (get_post_meta($product_id, '_wcrb_last_sent_at', true)) {
                    $synced++;
                }
            }

            return array($synced, max(0, $total - $synced));
        }

        public function handle_enqueue_all() {
            if (!current_user_can('manage_woocommerce') || !check_admin_referer('wcrb_enqueue_all')) {
                wp_die(esc_html__('Not allowed.', 'wcrb'));
            }

            $products = wc_get_products(array(
                'status' => 'publish',
                'limit' => -1,
                'orderby' => 'title',
                'order' => 'ASC',
                'return' => 'objects',
            ));

            usort($products, function($a, $b) {
                $cats_a = wp_get_post_terms($a->get_id(), 'product_cat', array('fields' => 'names'));
                $cats_b = wp_get_post_terms($b->get_id(), 'product_cat', array('fields' => 'names'));
                $cat_a = !empty($cats_a) ? implode(',', $cats_a) : 'zzzz';
                $cat_b = !empty($cats_b) ? implode(',', $cats_b) : 'zzzz';
                return strcmp($cat_a . $a->get_name(), $cat_b . $b->get_name());
            });

            $count = 0;
            foreach ($products as $product) {
                if ($this->enqueue_product($product->get_id())) {
                    $count++;
                }
            }

            wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'wcrb_notice' => 'bulk', 'queued' => $count), admin_url('admin.php')));
            exit;
        }

        public function handle_enqueue_single() {
            if (!current_user_can('edit_products') || !check_admin_referer('wcrb_enqueue_single')) {
                wp_die(esc_html__('Not allowed.', 'wcrb'));
            }

            $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
            if ($product_id) {
                $this->enqueue_product($product_id);
            }

            wp_safe_redirect(add_query_arg(array('post' => $product_id, 'action' => 'edit', 'wcrb_notice' => 'single'), admin_url('post.php')));
            exit;
        }

        private function enqueue_product($product_id) {
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_SUFFIX;

            $already = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE product_id = %d AND status IN ('pending','processing') LIMIT 1",
                $product_id
            ));

            if ($already) {
                return false;
            }

            $result = $wpdb->insert(
                $table,
                array(
                    'product_id' => $product_id,
                    'status' => 'pending',
                    'attempts' => 0,
                    'scheduled_at' => current_time('mysql', 1),
                    'created_at' => current_time('mysql', 1),
                ),
                array('%d', '%s', '%d', '%s', '%s')
            );

            return (bool) $result;
        }

        public function process_queue() {
            if (!$this->is_in_send_window()) {
                return;
            }

            $settings = $this->get_settings();
            $last_sent = (int) get_option(self::LAST_SENT_OPTION, 0);
            $min_gap = max(1, absint($settings['interval_minutes'])) * 60;
            if ($last_sent > 0 && (time() - $last_sent) < $min_gap) {
                return;
            }

            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_SUFFIX;
            $item = $wpdb->get_row(
                "SELECT * FROM {$table} WHERE status = 'pending' AND scheduled_at <= UTC_TIMESTAMP() ORDER BY id ASC LIMIT 1"
            );

            if (!$item) {
                return;
            }

            $wpdb->update($table, array('status' => 'processing'), array('id' => $item->id), array('%s'), array('%d'));

            $sent = $this->send_product_to_rubika((int) $item->product_id);
            if ($sent['success']) {
                $wpdb->update(
                    $table,
                    array('status' => 'sent', 'sent_at' => current_time('mysql', 1), 'error_message' => null),
                    array('id' => $item->id),
                    array('%s', '%s', '%s'),
                    array('%d')
                );
                update_option(self::LAST_SENT_OPTION, time(), false);
                update_post_meta((int) $item->product_id, '_wcrb_last_sent_at', current_time('mysql'));
            } else {
                $attempts = (int) $item->attempts + 1;
                $status = $attempts >= 5 ? 'failed' : 'pending';
                $wpdb->update(
                    $table,
                    array(
                        'status' => $status,
                        'attempts' => $attempts,
                        'error_message' => sanitize_text_field($sent['message']),
                        'scheduled_at' => gmdate('Y-m-d H:i:s', time() + 600),
                    ),
                    array('id' => $item->id),
                    array('%s', '%d', '%s', '%s'),
                    array('%d')
                );
            }
        }

        private function is_in_send_window() {
            $settings = $this->get_settings();
            $start = $settings['send_window_start'];
            $end = $settings['send_window_end'];

            $now = current_time('H:i');
            if ($start <= $end) {
                return ($now >= $start && $now <= $end);
            }

            return ($now >= $start || $now <= $end);
        }

        private function send_product_to_rubika($product_id) {
            $settings = $this->get_settings();
            $product = wc_get_product($product_id);
            if (!$product) {
                return array('success' => false, 'message' => 'Invalid product');
            }

            $text = $this->render_template($product, $settings);
            $images = $this->collect_images($product, (int) $settings['image_count'], $settings['excluded_images']);

            foreach ($images as $attachment_id) {
                $upload = $this->upload_image_to_rubika($attachment_id, $settings['bot_token']);
                if (!$upload['success']) {
                    return $upload;
                }

                $file_payload = array(
                    'chat_id' => $settings['channel'],
                    'file_id' => $upload['file_id'],
                    'text' => $text,
                    'disable_notification' => (bool) $settings['disable_notification'],
                    'inline_keypad' => $this->build_buy_keypad($product),
                );

                $result = $this->rubika_api_request($settings['bot_token'], 'sendFile', $file_payload);
                if (!$result['success']) {
                    return $result;
                }

                $text = '';
            }

            if (empty($images)) {
                $payload = array(
                    'chat_id' => $settings['channel'],
                    'text' => $text,
                    'disable_notification' => (bool) $settings['disable_notification'],
                    'inline_keypad' => $this->build_buy_keypad($product),
                );
                return $this->rubika_api_request($settings['bot_token'], 'sendMessage', $payload);
            }

            return array('success' => true, 'message' => 'Sent');
        }

        private function render_template($product, $settings) {
            $price = $this->format_product_price($product);

            $replacements = array(
                '{title}' => $product->get_name(),
                '{short_description}' => wp_strip_all_tags($product->get_short_description()),
                '{price}' => $price,
                '{url}' => get_permalink($product->get_id()),
            );

            return strtr($settings['template'], $replacements);
        }

        private function format_product_price($product) {
            if ($product->is_type('variable')) {
                $min_regular = $product->get_variation_regular_price('min', true);
                $min_sale = $product->get_variation_sale_price('min', true);

                if (!empty($min_sale) && $min_sale > 0 && $min_sale < $min_regular) {
                    return sprintf('🔥 %s (به‌جای ~%s~)', wc_price($min_sale), wc_price($min_regular));
                }

                return sprintf('💸 %s', wc_price($product->get_variation_price('min', true)));
            }

            $regular = $product->get_regular_price();
            $sale = $product->get_sale_price();

            if (!empty($sale) && (float) $sale > 0 && (float) $regular > (float) $sale) {
                return sprintf('🔥 %s (به‌جای ~%s~)', wc_price($sale), wc_price($regular));
            }

            return sprintf('💸 %s', wc_price($product->get_price()));
        }

        private function collect_images($product, $limit, $excluded_images_csv) {
            $excluded = array_filter(array_map('absint', explode(',', (string) $excluded_images_csv)));
            $ids = array();

            $main_image = $product->get_image_id();
            if ($main_image) {
                $ids[] = $main_image;
            }

            $gallery = $product->get_gallery_image_ids();
            if (!empty($gallery)) {
                $ids = array_merge($ids, $gallery);
            }

            $ids = array_values(array_unique(array_filter($ids)));
            $ids = array_values(array_diff($ids, $excluded));

            if ($limit > 0) {
                $ids = array_slice($ids, 0, $limit);
            }

            return $ids;
        }

        private function upload_image_to_rubika($attachment_id, $token) {
            $path = get_attached_file($attachment_id);
            if (!$path || !file_exists($path)) {
                return array('success' => false, 'message' => 'Image file missing');
            }

            $request = $this->rubika_api_request($token, 'requestSendFile', array('type' => 'Image'));
            if (!$request['success'] || empty($request['data']['upload_url'])) {
                return array('success' => false, 'message' => 'Could not get upload URL');
            }

            $upload_url = $request['data']['upload_url'];
            $response = wp_remote_post($upload_url, array(
                'timeout' => 60,
                'body' => array(
                    'file' => curl_file_create($path),
                ),
            ));

            if (is_wp_error($response)) {
                return array('success' => false, 'message' => $response->get_error_message());
            }

            $json = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($json) || empty($json['file_id'])) {
                return array('success' => false, 'message' => 'No file_id in upload response');
            }

            return array('success' => true, 'file_id' => $json['file_id']);
        }

        private function build_buy_keypad($product) {
            return array(
                'rows' => array(
                    array(
                        'buttons' => array(
                            array(
                                'id' => 'buy_' . $product->get_id(),
                                'type' => 'Simple',
                                'button_text' => '🛒 خرید محصول',
                                'url' => get_permalink($product->get_id()),
                            ),
                        ),
                    ),
                ),
            );
        }

        private function rubika_api_request($token, $method, $payload) {
            $url = sprintf('https://botapi.rubika.ir/v3/%s/%s', rawurlencode($token), $method);
            $response = wp_remote_post($url, array(
                'timeout' => 45,
                'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
                'body' => wp_json_encode($payload),
            ));

            if (is_wp_error($response)) {
                return array('success' => false, 'message' => $response->get_error_message());
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if ($status_code < 200 || $status_code >= 300) {
                return array('success' => false, 'message' => 'HTTP ' . $status_code);
            }

            if (!is_array($body)) {
                return array('success' => false, 'message' => 'Invalid JSON response');
            }

            return array('success' => true, 'data' => $body, 'message' => 'OK');
        }

        public function admin_bar_publish_button($wp_admin_bar) {
            if (!current_user_can('edit_products') || !is_admin()) {
                return;
            }

            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if (!$screen || $screen->base !== 'post' || $screen->post_type !== 'product') {
                return;
            }

            $product_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
            if (!$product_id) {
                return;
            }

            $url = wp_nonce_url(
                add_query_arg(
                    array('action' => 'wcrb_enqueue_single', 'product_id' => $product_id),
                    admin_url('admin-post.php')
                ),
                'wcrb_enqueue_single'
            );

            $wp_admin_bar->add_node(array(
                'id' => 'wcrb_publish_product',
                'title' => __('Queue for Rubika', 'wcrb'),
                'href' => $url,
                'meta' => array('class' => 'wcrb-publish-product'),
            ));
        }

        public function admin_notice() {
            if (empty($_GET['wcrb_notice'])) {
                return;
            }

            if ($_GET['wcrb_notice'] === 'single') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Product was queued for Rubika.', 'wcrb') . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'bulk') {
                $queued = isset($_GET['queued']) ? absint($_GET['queued']) : 0;
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('Queued %d products for Rubika.', 'wcrb'), $queued)) . '</p></div>';
            }
        }
    }

    new WCRB_Plugin();
}
