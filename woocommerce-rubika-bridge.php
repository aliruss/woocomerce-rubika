<?php
/**
 * Plugin Name: WooCommerce Rubika Bridge
 * Description: Lightweight WooCommerce social publisher for Rubika and Telegram relay with queue, scheduling, and per-product controls.
 * Version: 1.2.0
 * Author: Codex
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WCRB_Plugin')) {
    class WCRB_Plugin {
        const VERSION = '1.2.0';
        const VERSION_OPTION = 'wcrb_plugin_version';
        const OPTION_KEY = 'wcrb_settings';
        const LAST_SENT_OPTION = 'wcrb_last_sent_at';
        const LAST_RUNNER_PING_OPTION = 'wcrb_last_runner_ping';
        const LOG_OPTION = 'wcrb_logs';
        const CRON_HOOK = 'wcrb_process_queue_event';
        const TABLE_SUFFIX = 'wcrb_queue';

        public function __construct() {
            register_activation_hook(__FILE__, array($this, 'activate'));
            register_deactivation_hook(__FILE__, array($this, 'deactivate'));

            add_action('admin_menu', array($this, 'register_admin_menu'));
            add_action('admin_init', array($this, 'register_settings'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
            add_action('add_meta_boxes', array($this, 'register_product_social_meta_box'));
            add_action('save_post_product', array($this, 'save_product_social_meta'), 10, 2);
            add_action('init', array($this, 'bootstrap_queue_runner'));

            add_action('admin_post_wcrb_enqueue_all', array($this, 'handle_enqueue_all'));
            add_action('admin_post_wcrb_enqueue_single', array($this, 'handle_enqueue_single'));
            add_action('admin_post_wcrb_clear_queue', array($this, 'handle_clear_queue'));
            add_action('admin_post_wcrb_clear_logs', array($this, 'handle_clear_logs'));
            add_action('admin_post_wcrb_run_queue', array($this, 'handle_run_queue_now'));
            add_action('admin_post_wcrb_clear_database', array($this, 'handle_clear_database'));
            add_action('admin_post_wcrb_send_test_message', array($this, 'handle_send_test_message'));
            add_action('admin_post_wcrb_reset_sync_records', array($this, 'handle_reset_sync_records'));
            add_action('admin_post_wcrb_send_now_single', array($this, 'handle_send_now_single'));
            add_action('admin_post_wcrb_test_telegram_relay', array($this, 'handle_test_telegram_relay'));
            add_action('transition_post_status', array($this, 'enqueue_newly_published_product'), 10, 3);

            add_action('admin_bar_menu', array($this, 'admin_bar_publish_button'), 100);
            add_action('admin_notices', array($this, 'admin_notice'));

            add_action(self::CRON_HOOK, array($this, 'process_queue'));
            add_filter('cron_schedules', array($this, 'register_cron_schedules'));
        }

        public function activate() {
            $this->maybe_create_table();
            $this->maybe_run_migrations();

            $defaults = $this->default_settings();
            $current = get_option(self::OPTION_KEY, array());
            update_option(self::OPTION_KEY, wp_parse_args($current, $defaults));

            $this->ensure_cron_event_scheduled();

            update_option(self::VERSION_OPTION, self::VERSION, false);

            $this->add_log('info', 'Plugin activated.', array('version' => self::VERSION));
        }

        public function deactivate() {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            $this->add_log('info', 'Plugin deactivated.');
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

        public function bootstrap_queue_runner() {
            $this->maybe_run_migrations();
            $this->ensure_cron_event_scheduled();
            $this->recover_stale_processing_items();
            $this->maybe_process_queue_on_request();
        }

        private function ensure_cron_event_scheduled() {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time() + 60, 'wcrb_every_minute', self::CRON_HOOK);
            }
        }

        private function recover_stale_processing_items() {
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_SUFFIX;

            $wpdb->query(
                "UPDATE {$table}
                SET status = 'pending', scheduled_at = UTC_TIMESTAMP(), error_message = 'Recovered from stale processing state'
                WHERE status = 'processing' AND created_at < (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)"
            );
        }

        private function maybe_process_queue_on_request() {
            if (wp_doing_cron()) {
                return;
            }

            $last_ping = (int) get_option(self::LAST_RUNNER_PING_OPTION, 0);
            if ($last_ping > 0 && (time() - $last_ping) < 45) {
                return;
            }

            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_SUFFIX;
            $has_pending = (int) $wpdb->get_var("SELECT COUNT(1) FROM {$table} WHERE status = 'pending' AND scheduled_at <= UTC_TIMESTAMP()");
            if ($has_pending < 1) {
                return;
            }

            update_option(self::LAST_RUNNER_PING_OPTION, time(), false);
            $this->process_queue(false);
        }

        private function default_settings() {
            return array(
                'bot_token' => 'JAIHJ0LIWGEOQKKWPBQFQKBEFSUAFZQIDYBFOTKDPUEQNSYTCAWPXPJEISIACNAP',
                'channel' => '@behdashtik_site',
                'website_url' => home_url('/'),
                'template' => "🛍️ {title}\n\n{short_description}\n\n💰 {price}\n🔗 {url}",
                'image_count' => 1,
                'excluded_images' => '',
                'interval_minutes' => 15,
                'send_window_start' => '00:00',
                'send_window_end' => '23:59',
                'disable_notification' => 0,
                'enable_logs' => 1,
                'enable_plugin' => 1,
                'rubika_enabled' => 1,
                'telegram_enabled' => 0,
                'telegram_relay_url' => '',
                'telegram_relay_api_key' => '',
                'telegram_hmac_secret' => '',
                'telegram_image_count' => 2,
                'telegram_template' => "🛍️ {title}

{social_text}

💰 {price}
🔗 {url}",
                'telegram_parse_mode' => 'HTML',
                'telegram_send_as_album' => 1,
            );
        }

        private function get_settings() {
            return wp_parse_args(get_option(self::OPTION_KEY, array()), $this->default_settings());
        }

        private function maybe_run_migrations() {
            $installed_version = get_option(self::VERSION_OPTION, '0.0.0');
            if (version_compare($installed_version, self::VERSION, '>=')) {
                return;
            }

            $this->maybe_create_table();
            $this->migrate_queue_network_columns();
            update_option(self::VERSION_OPTION, self::VERSION, false);
        }

        private function migrate_queue_network_columns() {
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_SUFFIX;
            $wpdb->query("UPDATE {$table} SET network = 'rubika' WHERE network IS NULL OR network = ''");
        }

        private function maybe_create_table() {
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_SUFFIX;
            $charset_collate = $wpdb->get_charset_collate();

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $sql = "CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                product_id BIGINT UNSIGNED NOT NULL,
                network VARCHAR(20) NOT NULL DEFAULT 'rubika',
                payload_hash VARCHAR(64) NULL,
                request_id VARCHAR(80) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                error_message TEXT NULL,
                last_response TEXT NULL,
                scheduled_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                sent_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY status_scheduled (status, scheduled_at),
                KEY network_status (network, status),
                KEY payload_network (payload_hash, network),
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

        public function enqueue_admin_assets($hook) {
            if ($hook !== 'woocommerce_page_wcrb-settings') {
                return;
            }

            wp_enqueue_media();
            wp_register_script('wcrb-admin', '', array('jquery'), self::VERSION, true);
            wp_enqueue_script('wcrb-admin');
            wp_add_inline_script(
                'wcrb-admin',
                "jQuery(function($){
                    var frame;
                    function renderPreviews(ids){
                        var wrap = $('#wcrb-excluded-preview');
                        wrap.empty();
                        if(!ids.length){return;}
                        ids.forEach(function(id){
                            wp.media.attachment(id).fetch().then(function(){
                                var att = wp.media.attachment(id).toJSON();
                                var src = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : (att.icon || '');
                                if(src){wrap.append('<span style=\"display:inline-block;margin:0 8px 8px 0;text-align:center\"><img src=\"'+src+'\" style=\"width:60px;height:60px;object-fit:cover;display:block;border:1px solid #ddd\"><small>#'+id+'</small></span>');}
                            });
                        });
                    }

                    $('#wcrb_pick_images').on('click', function(e){
                        e.preventDefault();
                        var field = $('#wcrb_excluded_images');
                        var selectedIds = field.val() ? field.val().split(',').map(function(v){ return parseInt(v,10); }).filter(Boolean) : [];

                        if(frame){ frame.open(); return; }
                        frame = wp.media({
                            title: 'انتخاب تصاویر مستثنی',
                            button: { text: 'انتخاب تصاویر' },
                            library: { type: 'image' },
                            multiple: true
                        });
                        frame.on('open', function(){
                            var selection = frame.state().get('selection');
                            selectedIds.forEach(function(id){
                                var attachment = wp.media.attachment(id);
                                attachment.fetch();
                                selection.add(attachment ? [attachment] : []);
                            });
                        });
                        frame.on('select', function(){
                            var ids = frame.state().get('selection').map(function(att){ return att.id; });
                            field.val(ids.join(','));
                            renderPreviews(ids);
                        });
                        frame.open();
                    });

                    $('#wcrb_clear_images').on('click', function(e){
                        e.preventDefault();
                        $('#wcrb_excluded_images').val('');
                        $('#wcrb-excluded-preview').empty();
                    });

                    var initial = $('#wcrb_excluded_images').val() ? $('#wcrb_excluded_images').val().split(',').map(function(v){ return parseInt(v,10); }).filter(Boolean) : [];
                    renderPreviews(initial);
                });"
            );
        }

        public function sanitize_settings($input) {
            $sanitized = $this->default_settings();
            $sanitized['bot_token'] = sanitize_text_field($input['bot_token'] ?? '');
            $sanitized['channel'] = sanitize_text_field($input['channel'] ?? '');
            $sanitized['website_url'] = esc_url_raw($input['website_url'] ?? home_url('/'));
            $sanitized['template'] = wp_kses_post($input['template'] ?? '');
            $sanitized['image_count'] = max(0, absint($input['image_count'] ?? 1));
            $sanitized['excluded_images'] = implode(',', array_filter(array_map('absint', explode(',', (string) ($input['excluded_images'] ?? '')))));
            $sanitized['interval_minutes'] = max(1, absint($input['interval_minutes'] ?? 15));
            $sanitized['send_window_start'] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $input['send_window_start'] ?? '') ? $input['send_window_start'] : '00:00';
            $sanitized['send_window_end'] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $input['send_window_end'] ?? '') ? $input['send_window_end'] : '23:59';
            $sanitized['disable_notification'] = !empty($input['disable_notification']) ? 1 : 0;
            $sanitized['enable_logs'] = !empty($input['enable_logs']) ? 1 : 0;
            $sanitized['enable_plugin'] = !empty($input['enable_plugin']) ? 1 : 0;
            $sanitized['rubika_enabled'] = !empty($input['rubika_enabled']) ? 1 : 0;
            $sanitized['telegram_enabled'] = !empty($input['telegram_enabled']) ? 1 : 0;
            $sanitized['telegram_relay_url'] = esc_url_raw($input['telegram_relay_url'] ?? '');
            $current = get_option(self::OPTION_KEY, array());
            $api_key_input = sanitize_text_field($input['telegram_relay_api_key'] ?? '');
            $hmac_input = sanitize_text_field($input['telegram_hmac_secret'] ?? '');
            $sanitized['telegram_relay_api_key'] = $api_key_input !== '' ? $api_key_input : ($current['telegram_relay_api_key'] ?? '');
            $sanitized['telegram_hmac_secret'] = $hmac_input !== '' ? $hmac_input : ($current['telegram_hmac_secret'] ?? '');
            $sanitized['telegram_image_count'] = max(0, absint($input['telegram_image_count'] ?? 2));
            $sanitized['telegram_template'] = wp_kses_post($input['telegram_template'] ?? $sanitized['telegram_template']);
            $parse_mode = strtoupper(sanitize_text_field($input['telegram_parse_mode'] ?? 'HTML'));
            $sanitized['telegram_parse_mode'] = in_array($parse_mode, array('HTML', 'MARKDOWN', 'NONE'), true) ? $parse_mode : 'HTML';
            $sanitized['telegram_send_as_album'] = !empty($input['telegram_send_as_album']) ? 1 : 0;
            return $sanitized;
        }

        public function render_settings_page() {
            if (!current_user_can('manage_woocommerce')) {
                return;
            }

            $settings = $this->get_settings();
            list($synced, $unsynced) = $this->product_sync_counts();
            $queue_stats = $this->queue_stats();
            $network_queue_stats = $this->queue_network_stats();
            $logs = $this->get_logs();
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('WooCommerce Rubika Bridge', 'wcrb'); ?></h1>
                <p><?php echo esc_html(sprintf(__('Synced products: %d | Unsynced products: %d', 'wcrb'), $synced, $unsynced)); ?></p>
                <p>
                    <?php echo esc_html(sprintf(__('Queue — Pending: %d | Processing: %d | Sent: %d | Failed: %d', 'wcrb'), $queue_stats['pending'], $queue_stats['processing'], $queue_stats['sent'], $queue_stats['failed'])); ?>
                </p>
                <p>
                    <?php foreach ($network_queue_stats as $network => $stats) : ?>
                        <span style="display:inline-block;margin-right:12px"><?php echo esc_html(sprintf('%s — P:%d | S:%d | F:%d', ucfirst($network), $stats['pending'], $stats['sent'], $stats['failed'])); ?></span>
                    <?php endforeach; ?>
                </p>

                <form method="post" action="options.php">
                    <?php settings_fields('wcrb_settings_group'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="wcrb_enable_plugin"><?php esc_html_e('Enable Rubika publishing', 'wcrb'); ?></label></th>
                            <td><label><input type="checkbox" id="wcrb_enable_plugin" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_plugin]" value="1" <?php checked((int) $settings['enable_plugin'], 1); ?>> <?php esc_html_e('Enable plugin send/queue actions', 'wcrb'); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcrb_rubika_enabled"><?php esc_html_e('Enable Rubika', 'wcrb'); ?></label></th>
                            <td><label><input type="checkbox" id="wcrb_rubika_enabled" name="<?php echo esc_attr(self::OPTION_KEY); ?>[rubika_enabled]" value="1" <?php checked((int) $settings['rubika_enabled'], 1); ?>> <?php esc_html_e('Publish to Rubika', 'wcrb'); ?></label></td>
                        </tr>
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
                            <th scope="row"><label for="wcrb_excluded_images"><?php esc_html_e('Excluded images', 'wcrb'); ?></label></th>
                            <td>
                                <input type="hidden" id="wcrb_excluded_images" name="<?php echo esc_attr(self::OPTION_KEY); ?>[excluded_images]" value="<?php echo esc_attr($settings['excluded_images']); ?>">
                                <p>
                                    <button type="button" class="button" id="wcrb_pick_images"><?php esc_html_e('Select from Media Library', 'wcrb'); ?></button>
                                    <button type="button" class="button-link-delete" id="wcrb_clear_images"><?php esc_html_e('Clear selection', 'wcrb'); ?></button>
                                </p>
                                <div id="wcrb-excluded-preview"></div>
                            </td>
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
                        <tr>
                            <th scope="row"><label for="wcrb_enable_logs"><?php esc_html_e('Enable logging', 'wcrb'); ?></label></th>
                            <td><label><input type="checkbox" id="wcrb_enable_logs" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_logs]" value="1" <?php checked((int) $settings['enable_logs'], 1); ?>> <?php esc_html_e('Store plugin logs', 'wcrb'); ?></label></td>
                        </tr>
                        <tr><th colspan="2"><h2><?php esc_html_e('Telegram Relay', 'wcrb'); ?></h2></th></tr>
                        <tr>
                            <th scope="row"><label for="wcrb_telegram_enabled"><?php esc_html_e('Enable Telegram', 'wcrb'); ?></label></th>
                            <td><label><input type="checkbox" id="wcrb_telegram_enabled" name="<?php echo esc_attr(self::OPTION_KEY); ?>[telegram_enabled]" value="1" <?php checked((int) $settings['telegram_enabled'], 1); ?>> <?php esc_html_e('Publish to Telegram through external relay', 'wcrb'); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcrb_telegram_relay_url"><?php esc_html_e('Telegram Relay URL', 'wcrb'); ?></label></th>
                            <td><input type="url" id="wcrb_telegram_relay_url" name="<?php echo esc_attr(self::OPTION_KEY); ?>[telegram_relay_url]" class="regular-text" value="<?php echo esc_attr($settings['telegram_relay_url']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcrb_telegram_relay_api_key"><?php esc_html_e('Relay API Key', 'wcrb'); ?></label></th>
                            <td><input type="password" autocomplete="new-password" id="wcrb_telegram_relay_api_key" name="<?php echo esc_attr(self::OPTION_KEY); ?>[telegram_relay_api_key]" class="regular-text" value="" placeholder="<?php echo empty($settings['telegram_relay_api_key']) ? esc_attr__('Not set', 'wcrb') : esc_attr__('Saved - leave blank to keep', 'wcrb'); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcrb_telegram_hmac_secret"><?php esc_html_e('Optional HMAC Secret', 'wcrb'); ?></label></th>
                            <td><input type="password" autocomplete="new-password" id="wcrb_telegram_hmac_secret" name="<?php echo esc_attr(self::OPTION_KEY); ?>[telegram_hmac_secret]" class="regular-text" value="" placeholder="<?php echo empty($settings['telegram_hmac_secret']) ? esc_attr__('Not set', 'wcrb') : esc_attr__('Saved - leave blank to keep', 'wcrb'); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcrb_telegram_image_count"><?php esc_html_e('Telegram image count', 'wcrb'); ?></label></th>
                            <td><input type="number" min="0" id="wcrb_telegram_image_count" name="<?php echo esc_attr(self::OPTION_KEY); ?>[telegram_image_count]" value="<?php echo esc_attr($settings['telegram_image_count']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcrb_telegram_template"><?php esc_html_e('Telegram message template', 'wcrb'); ?></label></th>
                            <td><textarea id="wcrb_telegram_template" name="<?php echo esc_attr(self::OPTION_KEY); ?>[telegram_template]" rows="6" class="large-text code"><?php echo esc_textarea($settings['telegram_template']); ?></textarea></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcrb_telegram_parse_mode"><?php esc_html_e('Telegram parse mode', 'wcrb'); ?></label></th>
                            <td><select id="wcrb_telegram_parse_mode" name="<?php echo esc_attr(self::OPTION_KEY); ?>[telegram_parse_mode]">
                                <option value="HTML" <?php selected($settings['telegram_parse_mode'], 'HTML'); ?>>HTML</option>
                                <option value="MARKDOWN" <?php selected($settings['telegram_parse_mode'], 'MARKDOWN'); ?>>Markdown</option>
                                <option value="NONE" <?php selected($settings['telegram_parse_mode'], 'NONE'); ?>>None</option>
                            </select></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcrb_telegram_send_as_album"><?php esc_html_e('Send Telegram images as album', 'wcrb'); ?></label></th>
                            <td><label><input type="checkbox" id="wcrb_telegram_send_as_album" name="<?php echo esc_attr(self::OPTION_KEY); ?>[telegram_send_as_album]" value="1" <?php checked((int) $settings['telegram_send_as_album'], 1); ?>> <?php esc_html_e('Relay may send multiple images as an album', 'wcrb'); ?></label></td>
                        </tr>
                    </table>
                    <?php submit_button(); ?>
                </form>

                <hr>
                <h2><?php esc_html_e('Queue actions', 'wcrb'); ?></h2>
                <p>
                    <form style="display:inline-block;margin-right:8px" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('wcrb_enqueue_all'); ?>
                        <input type="hidden" name="action" value="wcrb_enqueue_all">
                        <?php submit_button(__('Queue all published products', 'wcrb'), 'secondary', 'submit', false); ?>
                    </form>

                    <form style="display:inline-block;margin-right:8px" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('wcrb_run_queue'); ?>
                        <input type="hidden" name="action" value="wcrb_run_queue">
                        <?php submit_button(__('Run queue now', 'wcrb'), 'secondary', 'submit', false); ?>
                    </form>

                    <form style="display:inline-block" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Are you sure you want to clear the queue?', 'wcrb')); ?>');">
                        <?php wp_nonce_field('wcrb_clear_queue'); ?>
                        <input type="hidden" name="action" value="wcrb_clear_queue">
                        <?php submit_button(__('Clear queue', 'wcrb'), 'delete', 'submit', false); ?>
                    </form>
                </p>
                <p>
                    <form style="display:inline-block;margin-right:8px" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('wcrb_send_test_message'); ?>
                        <input type="hidden" name="action" value="wcrb_send_test_message">
                        <?php submit_button(__('Send Hello test message', 'wcrb'), 'secondary', 'submit', false); ?>
                    </form>

                    <form style="display:inline-block;margin-right:8px" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('wcrb_test_telegram_relay'); ?>
                        <input type="hidden" name="action" value="wcrb_test_telegram_relay">
                        <?php submit_button(__('Test Telegram relay', 'wcrb'), 'secondary', 'submit', false); ?>
                    </form>

                    <form style="display:inline-block" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('This will clear queue table, plugin logs/options, and sync markers. Continue?', 'wcrb')); ?>');">
                        <?php wp_nonce_field('wcrb_clear_database'); ?>
                        <input type="hidden" name="action" value="wcrb_clear_database">
                        <?php submit_button(__('Clear plugin database', 'wcrb'), 'delete', 'submit', false); ?>
                    </form>
                    <form style="display:inline-block;margin-left:8px" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Reset synced/unsynced product records?', 'wcrb')); ?>');">
                        <?php wp_nonce_field('wcrb_reset_sync_records'); ?>
                        <input type="hidden" name="action" value="wcrb_reset_sync_records">
                        <?php submit_button(__('Reset synced/unsynced records', 'wcrb'), 'secondary', 'submit', false); ?>
                    </form>
                </p>

                <hr>
                <h2><?php esc_html_e('Logs', 'wcrb'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:8px">
                    <?php wp_nonce_field('wcrb_clear_logs'); ?>
                    <input type="hidden" name="action" value="wcrb_clear_logs">
                    <?php submit_button(__('Clear logs', 'wcrb'), 'secondary', 'submit', false); ?>
                </form>
                <textarea readonly rows="14" class="large-text code"><?php echo esc_textarea(implode("\n", $logs)); ?></textarea>
            </div>
            <?php
        }

        public function register_product_social_meta_box() {
            add_meta_box(
                'wcrb_product_social_texts',
                __('Social publishing text', 'wcrb'),
                array($this, 'render_product_social_meta_box'),
                'product',
                'normal',
                'default'
            );
        }

        public function render_product_social_meta_box($post) {
            wp_nonce_field('wcrb_save_product_social_meta', 'wcrb_product_social_nonce');
            $general = get_post_meta($post->ID, '_wcrb_social_text', true);
            $rubika = get_post_meta($post->ID, '_wcrb_rubika_text', true);
            $telegram = get_post_meta($post->ID, '_wcrb_telegram_text', true);
            ?>
            <p><label for="wcrb_social_text"><strong><?php esc_html_e('General social media custom text', 'wcrb'); ?></strong></label></p>
            <textarea id="wcrb_social_text" name="wcrb_social_text" rows="4" class="widefat"><?php echo esc_textarea($general); ?></textarea>
            <p><label for="wcrb_rubika_text"><strong><?php esc_html_e('Rubika custom text', 'wcrb'); ?></strong></label></p>
            <textarea id="wcrb_rubika_text" name="wcrb_rubika_text" rows="4" class="widefat"><?php echo esc_textarea($rubika); ?></textarea>
            <p><label for="wcrb_telegram_text"><strong><?php esc_html_e('Telegram custom text', 'wcrb'); ?></strong></label></p>
            <textarea id="wcrb_telegram_text" name="wcrb_telegram_text" rows="4" class="widefat"><?php echo esc_textarea($telegram); ?></textarea>
            <?php
        }

        public function save_product_social_meta($post_id, $post) {
            if (!isset($_POST['wcrb_product_social_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wcrb_product_social_nonce'])), 'wcrb_save_product_social_meta')) {
                return;
            }
            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                return;
            }
            if (!current_user_can('edit_post', $post_id)) {
                return;
            }

            $fields = array(
                'wcrb_social_text' => '_wcrb_social_text',
                'wcrb_rubika_text' => '_wcrb_rubika_text',
                'wcrb_telegram_text' => '_wcrb_telegram_text',
            );
            foreach ($fields as $field => $meta_key) {
                $value = isset($_POST[$field]) ? sanitize_textarea_field(wp_unslash($_POST[$field])) : '';
                if ($value === '') {
                    delete_post_meta($post_id, $meta_key);
                } else {
                    update_post_meta($post_id, $meta_key, $value);
                }
            }
        }

        private function queue_stats() {
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_SUFFIX;
            $rows = $wpdb->get_results("SELECT status, COUNT(*) AS cnt FROM {$table} GROUP BY status", ARRAY_A);
            $stats = array('pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0);
            foreach ($rows as $row) {
                $status = $row['status'];
                if (isset($stats[$status])) {
                    $stats[$status] = (int) $row['cnt'];
                }
            }
            return $stats;
        }

        private function queue_network_stats() {
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_SUFFIX;
            $rows = $wpdb->get_results("SELECT network, status, COUNT(*) AS cnt FROM {$table} GROUP BY network, status", ARRAY_A);
            $stats = array(
                'rubika' => array('pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0),
                'telegram' => array('pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0),
            );
            foreach ($rows as $row) {
                $network = $this->normalize_network($row['network'] ?? 'rubika');
                $status = $row['status'];
                if (isset($stats[$network][$status])) {
                    $stats[$network][$status] = (int) $row['cnt'];
                }
            }
            return $stats;
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
                if (get_post_meta($product_id, '_wcrb_last_sent_at', true) || get_post_meta($product_id, '_wcrb_rubika_last_sent_at', true)) {
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
                foreach ($this->get_enabled_networks() as $network) {
                    if ($this->enqueue_product($product->get_id(), $network)) {
                        $count++;
                    }
                }
            }

            $this->add_log('info', 'Bulk enqueue completed.', array('queued' => $count));
            wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'wcrb_notice' => 'bulk', 'queued' => $count), admin_url('admin.php')));
            exit;
        }

        public function handle_enqueue_single() {
            if (!current_user_can('edit_products') || !check_admin_referer('wcrb_enqueue_single')) {
                wp_die(esc_html__('Not allowed.', 'wcrb'));
            }

            $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
            if (!$this->is_plugin_enabled()) {
                wp_safe_redirect(add_query_arg(array('wcrb_notice' => 'plugin_disabled'), wp_get_referer() ?: admin_url()));
                exit;
            }

            if ($product_id) {
                foreach ($this->get_enabled_networks() as $network) {
                    $this->enqueue_product($product_id, $network);
                }
                $this->add_log('info', 'Single product queued.', array('product_id' => $product_id, 'network' => 'all_enabled'));
                $this->process_queue(true);
            }

            $redirect_to = wp_get_referer() ? wp_get_referer() : admin_url('post.php?post=' . $product_id . '&action=edit');
            wp_safe_redirect(add_query_arg(array('wcrb_notice' => 'single'), $redirect_to));
            exit;
        }

        public function handle_reset_sync_records() {
            if (!current_user_can('manage_woocommerce') || !check_admin_referer('wcrb_reset_sync_records')) {
                wp_die(esc_html__('Not allowed.', 'wcrb'));
            }

            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_wcrb_last_sent_at','_wcrb_rubika_last_sent_at','_wcrb_rubika_last_payload_hash','_wcrb_telegram_last_sent_at','_wcrb_telegram_last_payload_hash')");
            $this->add_log('warning', 'Synced/unsynced records reset by admin.');

            wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'wcrb_notice' => 'reset_sync'), admin_url('admin.php')));
            exit;
        }

        public function handle_clear_queue() {
            if (!current_user_can('manage_woocommerce') || !check_admin_referer('wcrb_clear_queue')) {
                wp_die(esc_html__('Not allowed.', 'wcrb'));
            }

            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_SUFFIX;
            $wpdb->query("TRUNCATE TABLE {$table}");
            $this->add_log('warning', 'Queue cleared by admin.');

            wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'wcrb_notice' => 'clear_queue'), admin_url('admin.php')));
            exit;
        }

        public function handle_clear_logs() {
            if (!current_user_can('manage_woocommerce') || !check_admin_referer('wcrb_clear_logs')) {
                wp_die(esc_html__('Not allowed.', 'wcrb'));
            }

            update_option(self::LOG_OPTION, array(), false);
            wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'wcrb_notice' => 'clear_logs'), admin_url('admin.php')));
            exit;
        }

        public function handle_run_queue_now() {
            if (!current_user_can('manage_woocommerce') || !check_admin_referer('wcrb_run_queue')) {
                wp_die(esc_html__('Not allowed.', 'wcrb'));
            }

            $this->process_queue(true);
            wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'wcrb_notice' => 'run_queue'), admin_url('admin.php')));
            exit;
        }

        public function handle_clear_database() {
            if (!current_user_can('manage_woocommerce') || !check_admin_referer('wcrb_clear_database')) {
                wp_die(esc_html__('Not allowed.', 'wcrb'));
            }

            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_SUFFIX;
            $wpdb->query("TRUNCATE TABLE {$table}");

            delete_option(self::LAST_SENT_OPTION);
            delete_option(self::LAST_RUNNER_PING_OPTION);
            delete_option(self::LOG_OPTION);

            $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_wcrb_last_sent_at','_wcrb_rubika_last_sent_at','_wcrb_rubika_last_payload_hash','_wcrb_telegram_last_sent_at','_wcrb_telegram_last_payload_hash')");
            $this->add_log('warning', 'Plugin database data cleared by admin.');

            wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'wcrb_notice' => 'clear_database'), admin_url('admin.php')));
            exit;
        }

        public function handle_send_test_message() {
            if (!current_user_can('manage_woocommerce') || !check_admin_referer('wcrb_send_test_message')) {
                wp_die(esc_html__('Not allowed.', 'wcrb'));
            }

            $settings = $this->get_settings();
            $payload = array(
                'chat_id' => $settings['channel'],
                'text' => 'Hello from WooCommerce Rubika Bridge 👋',
                'disable_notification' => (bool) $settings['disable_notification'],
            );
            $result = $this->rubika_api_request($settings['bot_token'], 'sendMessage', $payload);
            if (!$result['success'] && strpos($result['message'], 'INVALID_INPUT') !== false) {
                $payload = array(
                    'chat_id' => $settings['channel'],
                    'text' => 'Hello from WooCommerce Rubika Bridge 👋',
                );
                $result = $this->rubika_api_request($settings['bot_token'], 'sendMessage', $payload);
            }

            if ($result['success']) {
                $this->add_log('info', 'Test message sent successfully.');
                wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'wcrb_notice' => 'test_ok'), admin_url('admin.php')));
                exit;
            }

            $this->add_log('error', 'Test message failed.', array('message' => $result['message']));
            wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'wcrb_notice' => 'test_fail'), admin_url('admin.php')));
            exit;
        }

        public function handle_test_telegram_relay() {
            if (!current_user_can('manage_woocommerce') || !check_admin_referer('wcrb_test_telegram_relay')) {
                wp_die(esc_html__('Not allowed.', 'wcrb'));
            }

            $settings = $this->get_settings();
            if (empty($settings['telegram_relay_url']) || empty($settings['telegram_relay_api_key'])) {
                $this->add_log('error', 'Telegram relay test failed.', array('network' => 'telegram', 'message' => 'Relay URL or API key is missing'));
                wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'wcrb_notice' => 'telegram_test_fail'), admin_url('admin.php')));
                exit;
            }

            $request_id = $this->build_request_id(0, 'telegram');
            $body = wp_json_encode(array('network' => 'telegram', 'request_id' => $request_id, 'action' => 'ping'));
            $headers = array(
                'Content-Type' => 'application/json; charset=utf-8',
                'X-Relay-Api-Key' => $settings['telegram_relay_api_key'],
                'X-Request-Id' => $request_id,
            );
            if (!empty($settings['telegram_hmac_secret'])) {
                $headers['X-Relay-Signature'] = hash_hmac('sha256', $body, $settings['telegram_hmac_secret']);
            }

            $response = wp_remote_post($settings['telegram_relay_url'], array('timeout' => 15, 'headers' => $headers, 'body' => $body));
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) < 200 || wp_remote_retrieve_response_code($response) >= 300) {
                $message = is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($response);
                $this->add_log('error', 'Telegram relay test failed.', array('network' => 'telegram', 'request_id' => $request_id, 'message' => $message));
                wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'wcrb_notice' => 'telegram_test_fail'), admin_url('admin.php')));
                exit;
            }

            $this->add_log('info', 'Telegram relay test succeeded.', array('network' => 'telegram', 'request_id' => $request_id));
            wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'wcrb_notice' => 'telegram_test_ok'), admin_url('admin.php')));
            exit;
        }

        public function handle_send_now_single() {
            if (!current_user_can('edit_products') || !check_admin_referer('wcrb_send_now_single')) {
                wp_die(esc_html__('Not allowed.', 'wcrb'));
            }

            if (!$this->is_plugin_enabled()) {
                wp_safe_redirect(add_query_arg(array('wcrb_notice' => 'plugin_disabled'), wp_get_referer() ?: admin_url()));
                exit;
            }

            $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
            $network = isset($_GET['network']) ? sanitize_key($_GET['network']) : 'rubika';
            if ($product_id) {
                $networks = $network === 'all' ? $this->get_enabled_networks() : array($network);
                $all_success = true;
                foreach ($networks as $current_network) {
                    $result = $this->send_product_to_network($product_id, $current_network, true);
                    if ($result['success']) {
                        $payload_hash = $this->build_payload_hash(wc_get_product($product_id), $current_network);
                        update_post_meta($product_id, $this->sent_meta_key($current_network), current_time('mysql'));
                        update_post_meta($product_id, $this->sent_hash_meta_key($current_network), $payload_hash);
                        if ($current_network === 'rubika') {
                            update_post_meta($product_id, '_wcrb_last_sent_at', current_time('mysql'));
                        }
                    } else {
                        $all_success = false;
                        $this->add_log('error', 'Direct send failed.', array('product_id' => $product_id, 'network' => $current_network, 'message' => $result['message']));
                    }
                }
                if ($all_success) {
                    wp_safe_redirect(add_query_arg(array('wcrb_notice' => 'direct_ok'), wp_get_referer() ?: admin_url()));
                    exit;
                }
            }

            wp_safe_redirect(add_query_arg(array('wcrb_notice' => 'direct_fail'), wp_get_referer() ?: admin_url()));
            exit;
        }

        public function enqueue_newly_published_product($new_status, $old_status, $post) {
            if (!$this->is_plugin_enabled()) {
                return;
            }

            if (!($post instanceof WP_Post) || $post->post_type !== 'product') {
                return;
            }

            if ($old_status === 'publish' || $new_status !== 'publish') {
                return;
            }

            foreach ($this->get_enabled_networks() as $network) {
                if ($this->enqueue_product((int) $post->ID, $network)) {
                    $this->add_log('info', 'Newly published product auto-queued.', array('product_id' => (int) $post->ID, 'network' => $network));
                }
            }
        }

        private function enqueue_product($product_id, $network = 'rubika', $force = false) {
            $network = $this->normalize_network($network);
            if (!$this->is_plugin_enabled() || !$this->is_network_enabled($network)) {
                return false;
            }

            $post = get_post($product_id);
            if (!$post || $post->post_type !== 'product' || $post->post_status !== 'publish') {
                return false;
            }

            $product = wc_get_product($product_id);
            if (!$product || !$product->is_in_stock()) {
                return false;
            }

            $payload_hash = $this->build_payload_hash($product, $network);
            if (!$force && $this->was_payload_sent($product_id, $network, $payload_hash)) {
                $this->add_log('info', 'Duplicate payload prevented from queueing.', array(
                    'product_id' => $product_id,
                    'network' => $network,
                    'payload_hash' => $payload_hash,
                ));
                return false;
            }

            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_SUFFIX;

            $already = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE product_id = %d AND network = %s AND status IN ('pending','processing') LIMIT 1",
                $product_id,
                $network
            ));

            if ($already) {
                return false;
            }

            $request_id = $this->build_request_id($product_id, $network);
            $result = $wpdb->insert(
                $table,
                array(
                    'product_id' => $product_id,
                    'network' => $network,
                    'payload_hash' => $payload_hash,
                    'request_id' => $request_id,
                    'status' => 'pending',
                    'attempts' => 0,
                    'scheduled_at' => current_time('mysql', 1),
                    'created_at' => current_time('mysql', 1),
                ),
                array('%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
            );

            return (bool) $result;
        }

        public function process_queue($force = false) {
            if (!$this->is_plugin_enabled()) {
                return;
            }

            if (!$force && !$this->is_in_send_window()) {
                $this->add_log('info', 'Queue paused: outside send window.');
                return;
            }

            $settings = $this->get_settings();
            $last_sent = (int) get_option(self::LAST_SENT_OPTION, 0);
            $min_gap = max(1, absint($settings['interval_minutes'])) * 60;
            if (!$force && $last_sent > 0 && (time() - $last_sent) < $min_gap) {
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

            $network = $this->normalize_network($item->network ?? 'rubika');
            $payload_hash = !empty($item->payload_hash) ? $item->payload_hash : $this->build_payload_hash(wc_get_product((int) $item->product_id), $network);
            $sent = $this->send_product_to_network((int) $item->product_id, $network, false, $payload_hash, $item->request_id ?? '');
            if ($sent['success']) {
                $wpdb->update(
                    $table,
                    array('status' => 'sent', 'sent_at' => current_time('mysql', 1), 'error_message' => null, 'last_response' => sanitize_text_field($sent['message'] ?? 'OK')),
                    array('id' => $item->id),
                    array('%s', '%s', '%s', '%s'),
                    array('%d')
                );
                update_option(self::LAST_SENT_OPTION, time(), false);
                update_post_meta((int) $item->product_id, $this->sent_meta_key($network), current_time('mysql'));
                update_post_meta((int) $item->product_id, $this->sent_hash_meta_key($network), $payload_hash);
                if ($network === 'rubika') {
                    update_post_meta((int) $item->product_id, '_wcrb_last_sent_at', current_time('mysql'));
                }
                $this->add_log('info', 'Product sent.', array('product_id' => (int) $item->product_id, 'network' => $network, 'queue_id' => (int) $item->id, 'payload_hash' => $payload_hash, 'request_id' => $item->request_id ?? '', 'result' => 'sent'));
            } else {
                $attempts = (int) $item->attempts + 1;
                $status = $attempts >= 5 ? 'failed' : 'pending';
                $wpdb->update(
                    $table,
                    array(
                        'status' => $status,
                        'attempts' => $attempts,
                        'error_message' => sanitize_text_field($sent['message']),
                        'last_response' => sanitize_text_field($sent['message']),
                        'scheduled_at' => gmdate('Y-m-d H:i:s', time() + 600),
                    ),
                    array('id' => $item->id),
                    array('%s', '%d', '%s', '%s', '%s'),
                    array('%d')
                );
                $this->add_log('error', 'Send failed.', array('product_id' => (int) $item->product_id, 'network' => $network, 'queue_id' => (int) $item->id, 'payload_hash' => $payload_hash, 'request_id' => $item->request_id ?? '', 'message' => $sent['message'], 'attempts' => $attempts));
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

        private function send_product_to_network($product_id, $network, $force = false, $payload_hash = '', $request_id = '') {
            $network = $this->normalize_network($network);
            if (!$this->is_network_enabled($network)) {
                return array('success' => false, 'message' => ucfirst($network) . ' is disabled');
            }

            $product = wc_get_product($product_id);
            if (!$product) {
                return array('success' => false, 'message' => 'Invalid product');
            }

            $payload_hash = $payload_hash ?: $this->build_payload_hash($product, $network);
            if (!$force && $this->was_payload_sent($product_id, $network, $payload_hash)) {
                $this->add_log('info', 'Duplicate payload prevented.', array(
                    'product_id' => $product_id,
                    'network' => $network,
                    'payload_hash' => $payload_hash,
                    'result' => 'duplicate_prevented',
                ));
                return array('success' => true, 'message' => 'Duplicate payload already sent');
            }

            if ($network === 'telegram') {
                return $this->send_product_to_telegram($product_id, $payload_hash, $request_id);
            }

            return $this->send_product_to_rubika($product_id);
        }

        private function send_product_to_rubika($product_id) {
            $settings = $this->get_settings();
            $product = wc_get_product($product_id);
            if (!$product || $product->get_status() !== 'publish' || !$product->is_in_stock()) {
                return array('success' => false, 'message' => 'Invalid, unpublished, or out-of-stock product');
            }

            $text = $this->render_network_template($product, 'rubika');
            $images = $this->collect_images($product, (int) $settings['image_count'], $settings['excluded_images']);

            if (empty($images)) {
                $this->add_log('info', 'No product image found; sending text-only Rubika message.', array('product_id' => $product_id));
                return $this->send_text_message($settings['bot_token'], array(
                    'chat_id' => $settings['channel'],
                    'text' => $text,
                    'disable_notification' => (bool) $settings['disable_notification'],
                    'inline_keypad' => $this->build_buy_keypad($product),
                ));
            }

            foreach ($images as $index => $attachment_id) {
                $upload = $this->upload_image_to_rubika($attachment_id, $settings['bot_token'], $product_id);
                if (!$upload['success']) {
                    $this->add_log('error', 'Image upload failed; product will remain queued for retry.', array(
                        'product_id' => $product_id,
                        'attachment_id' => $attachment_id,
                        'reason' => $upload['message'],
                    ));
                    return array('success' => false, 'message' => 'Image upload failed for attachment ' . $attachment_id . ': ' . $upload['message']);
                }

                $file_payload = array(
                    'chat_id' => $settings['channel'],
                    'file_id' => $upload['file_id'],
                    'text' => $index === 0 ? $text : '',
                    'disable_notification' => (bool) $settings['disable_notification'],
                    'inline_keypad' => $index === 0 ? $this->build_buy_keypad($product) : null,
                );

                $result = $this->send_image_message($settings['bot_token'], array_filter($file_payload, function($value) {
                    return $value !== null;
                }));
                if (!$result['success']) {
                    $this->add_log('error', 'Image send failed; product will remain queued for retry.', array(
                        'product_id' => $product_id,
                        'attachment_id' => $attachment_id,
                        'reason' => $result['message'],
                    ));
                    return array('success' => false, 'message' => 'Image send failed for attachment ' . $attachment_id . ': ' . $result['message']);
                }

                $this->add_log('info', 'Image sent to Rubika.', array(
                    'product_id' => $product_id,
                    'attachment_id' => $attachment_id,
                    'method' => $result['method'] ?? 'unknown',
                ));
            }

            return array('success' => true, 'message' => 'Sent');
        }

        private function send_image_message($token, $payload) {
            $methods = array('sendPhoto', 'sendImage', 'sendFile');
            $last_error = 'Image send failed';

            foreach ($methods as $method) {
                $result = $this->rubika_api_request($token, $method, $payload);
                if ($result['success']) {
                    $result['method'] = $method;
                    return $result;
                }
                if (strpos($result['message'], 'INVALID_INPUT') !== false) {
                    $stripped_payload = $payload;
                    unset($stripped_payload['inline_keypad'], $stripped_payload['disable_notification']);
                    $retry = $this->rubika_api_request($token, $method, $stripped_payload);
                    if ($retry['success']) {
                        $retry['method'] = $method;
                        return $retry;
                    }
                }
                $last_error = $method . ': ' . $result['message'];
            }

            return array('success' => false, 'message' => $last_error);
        }

        private function send_text_message($token, $payload) {
            $result = $this->rubika_api_request($token, 'sendMessage', $payload);
            if ($result['success']) {
                return $result;
            }

            if (strpos($result['message'], 'INVALID_INPUT') !== false) {
                $fallback_payload = array(
                    'chat_id' => $payload['chat_id'],
                    'text' => $payload['text'],
                );
                return $this->rubika_api_request($token, 'sendMessage', $fallback_payload);
            }

            return $result;
        }

        private function send_product_to_telegram($product_id, $payload_hash = '', $request_id = '') {
            $settings = $this->get_settings();
            if (empty($settings['telegram_relay_url']) || empty($settings['telegram_relay_api_key'])) {
                return array('success' => false, 'message' => 'Telegram relay URL or API key is missing');
            }

            $product = wc_get_product($product_id);
            if (!$product || $product->get_status() !== 'publish' || !$product->is_in_stock()) {
                return array('success' => false, 'message' => 'Invalid, unpublished, or out-of-stock product');
            }

            $request_id = $request_id ?: $this->build_request_id($product_id, 'telegram');
            $payload = $this->build_telegram_relay_payload($product, $request_id);
            $body = wp_json_encode($payload);
            if (!$body) {
                return array('success' => false, 'message' => 'Could not encode Telegram relay payload');
            }

            $headers = array(
                'Content-Type' => 'application/json; charset=utf-8',
                'X-Relay-Api-Key' => $settings['telegram_relay_api_key'],
                'X-Request-Id' => $request_id,
            );
            if (!empty($settings['telegram_hmac_secret'])) {
                $headers['X-Relay-Signature'] = hash_hmac('sha256', $body, $settings['telegram_hmac_secret']);
            }

            $this->add_log('info', 'Telegram relay request started.', array(
                'product_id' => $product_id,
                'network' => 'telegram',
                'payload_hash' => $payload_hash,
                'request_id' => $request_id,
            ));

            $response = wp_remote_post($settings['telegram_relay_url'], array(
                'timeout' => 30,
                'headers' => $headers,
                'body' => $body,
            ));

            if (is_wp_error($response)) {
                return array('success' => false, 'message' => $response->get_error_message());
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $raw_body = wp_remote_retrieve_body($response);
            if ($status_code < 200 || $status_code >= 300) {
                return array('success' => false, 'message' => 'Relay HTTP ' . $status_code . ': ' . mb_substr(wp_strip_all_tags($raw_body), 0, 200));
            }

            $decoded = json_decode($raw_body, true);
            if (is_array($decoded) && isset($decoded['success']) && !$decoded['success']) {
                $message = !empty($decoded['message']) ? sanitize_text_field($decoded['message']) : 'Telegram relay returned success=false';
                return array('success' => false, 'message' => $message);
            }

            $this->add_log('info', 'Telegram relay request completed.', array(
                'product_id' => $product_id,
                'network' => 'telegram',
                'payload_hash' => $payload_hash,
                'request_id' => $request_id,
                'result' => 'success',
            ));

            return array('success' => true, 'message' => 'Telegram relay accepted payload');
        }

        private function build_telegram_relay_payload($product, $request_id) {
            $settings = $this->get_settings();
            $image_ids = $this->collect_images($product, (int) $settings['telegram_image_count'], $settings['excluded_images']);
            $images = array();
            foreach ($image_ids as $image_id) {
                $url = wp_get_attachment_url($image_id);
                if (!$url) {
                    continue;
                }
                $images[] = array(
                    'id' => (int) $image_id,
                    'url' => esc_url_raw($url),
                    'mime' => get_post_mime_type($image_id) ?: 'image/jpeg',
                );
            }

            return array(
                'network' => 'telegram',
                'request_id' => $request_id,
                'product' => array(
                    'id' => $product->get_id(),
                    'title' => $product->get_name(),
                    'url' => get_permalink($product->get_id()),
                    'price' => $this->plain_product_price($product),
                    'short_description' => wp_strip_all_tags($product->get_short_description()),
                    'social_text' => $this->select_social_text($product, 'telegram'),
                    'caption' => $this->render_network_template($product, 'telegram'),
                    'images' => $images,
                ),
                'options' => array(
                    'image_count' => (int) $settings['telegram_image_count'],
                    'parse_mode' => $settings['telegram_parse_mode'],
                    'send_as_album' => !empty($settings['telegram_send_as_album']),
                ),
            );
        }

        private function render_network_template($product, $network) {
            $settings = $this->get_settings();
            $network = $this->normalize_network($network);
            $template = $network === 'telegram' ? $settings['telegram_template'] : $settings['template'];
            $social_text = $this->select_social_text($product, $network);
            $replacements = array(
                '{title}' => $product->get_name(),
                '{short_description}' => $social_text,
                '{social_text}' => $social_text,
                '{price}' => $this->format_product_price($product),
                '{url}' => get_permalink($product->get_id()),
            );
            return strtr($template, $replacements);
        }

        private function select_social_text($product, $network) {
            $product_id = $product->get_id();
            $network_text_key = $network === 'telegram' ? '_wcrb_telegram_text' : '_wcrb_rubika_text';
            $network_text = trim((string) get_post_meta($product_id, $network_text_key, true));
            if ($network_text !== '') {
                return $network_text;
            }

            $general = trim((string) get_post_meta($product_id, '_wcrb_social_text', true));
            if ($general !== '') {
                return $general;
            }

            $short_description = trim(wp_strip_all_tags($product->get_short_description()));
            if ($short_description !== '') {
                return $short_description;
            }

            return $product->get_name() . "\n" . $this->plain_product_price($product) . "\n" . get_permalink($product_id);
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
                    return sprintf('🔥 %s (به‌جای ~%s~)', $this->format_toman($min_sale), $this->format_toman($min_regular));
                }

                return sprintf('💸 %s', $this->format_toman($product->get_variation_price('min', true)));
            }

            $regular = $product->get_regular_price();
            $sale = $product->get_sale_price();

            if (!empty($sale) && (float) $sale > 0 && (float) $regular > (float) $sale) {
                return sprintf('🔥 %s (به‌جای ~%s~)', $this->format_toman($sale), $this->format_toman($regular));
            }

            return sprintf('💸 %s', $this->format_toman($product->get_price()));
        }

        private function format_toman($amount) {
            $numeric = is_numeric($amount) ? (float) $amount : 0;
            return number_format_i18n($numeric) . ' تومان';
        }

        private function plain_product_price($product) {
            if ($product->is_type('variable')) {
                return $this->format_toman($product->get_variation_price('min', true));
            }
            return $this->format_toman($product->get_price());
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

        private function upload_image_to_rubika($attachment_id, $token, $product_id = 0) {
            $original_path = get_attached_file($attachment_id);
            if (!$original_path || !file_exists($original_path)) {
                return array('success' => false, 'message' => 'Image file missing');
            }

            $prepared = $this->prepare_image_for_rubika_upload($original_path, $attachment_id, $product_id);
            if (!$prepared['success']) {
                $this->cleanup_prepared_image($prepared, $attachment_id, $product_id);
                return array('success' => false, 'message' => $prepared['message']);
            }

            $path = $prepared['path'];
            $this->add_log('info', 'Rubika image upload started.', array(
                'product_id' => $product_id,
                'attachment_id' => $attachment_id,
                'file' => basename($path),
                'size' => filesize($path),
            ));

            $request = $this->rubika_api_request($token, 'requestSendFile', array('type' => 'Image'));
            if (!$request['success']) {
                $request = $this->rubika_api_request($token, 'requestSendFile', array('type' => 'File'));
            }
            if (!$request['success']) {
                $this->cleanup_prepared_image($prepared, $attachment_id, $product_id);
                $this->add_log('error', 'Rubika upload URL request failed.', array(
                    'product_id' => $product_id,
                    'attachment_id' => $attachment_id,
                    'reason' => $request['message'],
                ));
                return $request;
            }

            $upload_url = '';
            if (!empty($request['data']['upload_url'])) {
                $upload_url = $request['data']['upload_url'];
            } elseif (!empty($request['data']['data']['upload_url'])) {
                $upload_url = $request['data']['data']['upload_url'];
            }

            if (empty($upload_url)) {
                $this->cleanup_prepared_image($prepared, $attachment_id, $product_id);
                return array('success' => false, 'message' => 'Could not get upload URL');
            }

            $raw_upload_body = '';
            if (function_exists('curl_init') && class_exists('CURLFile')) {
                $curl = curl_init();
                curl_setopt_array($curl, array(
                    CURLOPT_URL => $upload_url,
                    CURLOPT_POST => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 60,
                    CURLOPT_POSTFIELDS => array(
                        'file' => new CURLFile($path, 'image/jpeg', basename($path)),
                    ),
                ));
                $curl_response = curl_exec($curl);
                if ($curl_response !== false) {
                    $raw_upload_body = (string) $curl_response;
                }
                curl_close($curl);
            }

            if ($raw_upload_body === '') {
                $file_part = function_exists('curl_file_create') ? curl_file_create($path, 'image/jpeg', basename($path)) : '@' . $path;
                $response = wp_remote_post($upload_url, array(
                    'timeout' => 60,
                    'body' => array(
                        'file' => $file_part,
                    ),
                ));

                if (is_wp_error($response)) {
                    $this->cleanup_prepared_image($prepared, $attachment_id, $product_id);
                    $this->add_log('error', 'Rubika multipart image upload failed.', array(
                        'product_id' => $product_id,
                        'attachment_id' => $attachment_id,
                        'reason' => $response->get_error_message(),
                    ));
                    return array('success' => false, 'message' => $response->get_error_message());
                }

                $raw_upload_body = wp_remote_retrieve_body($response);
            }

            $json = json_decode($raw_upload_body, true);
            $file_id = $this->extract_file_id_from_upload_response($json);

            if (empty($file_id)) {
                $fallback_response = wp_remote_post($upload_url, array(
                    'timeout' => 60,
                    'headers' => array(
                        'Content-Type' => 'image/jpeg',
                    ),
                    'body' => file_get_contents($path),
                ));

                if (!is_wp_error($fallback_response)) {
                    $fallback_raw = wp_remote_retrieve_body($fallback_response);
                    $fallback_json = json_decode($fallback_raw, true);
                    $file_id = $this->extract_file_id_from_upload_response($fallback_json);
                    if (empty($file_id)) {
                        $raw_upload_body = $fallback_raw;
                    }
                }
            }

            if (empty($file_id)) {
                $body_for_log = is_string($raw_upload_body) ? mb_substr($raw_upload_body, 0, 400) : '';
                $this->cleanup_prepared_image($prepared, $attachment_id, $product_id);
                $this->add_log('error', 'Rubika image upload returned no file_id.', array(
                    'product_id' => $product_id,
                    'attachment_id' => $attachment_id,
                    'response' => $body_for_log,
                ));
                return array('success' => false, 'message' => 'No file_id in upload response: ' . $body_for_log);
            }

            $this->cleanup_prepared_image($prepared, $attachment_id, $product_id);
            $this->add_log('info', 'Rubika image upload completed.', array(
                'product_id' => $product_id,
                'attachment_id' => $attachment_id,
            ));

            return array('success' => true, 'file_id' => $file_id);
        }

        private function prepare_image_for_rubika_upload($path, $attachment_id = 0, $product_id = 0) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $supported_without_conversion = array('jpg', 'jpeg');
            $needs_conversion = !in_array($extension, $supported_without_conversion, true);

            if (!$needs_conversion) {
                $validation = $this->wait_for_valid_image_file($path, true);
                if (!$validation['success']) {
                    $this->add_log('error', 'Original image validation failed.', array(
                        'product_id' => $product_id,
                        'attachment_id' => $attachment_id,
                        'file' => basename($path),
                        'reason' => $validation['message'],
                    ));
                    return array('success' => false, 'message' => $validation['message'], 'path' => $path, 'temporary' => false, 'generated_files' => array());
                }

                $this->add_log('info', 'Original JPG image validated for Rubika upload.', array(
                    'product_id' => $product_id,
                    'attachment_id' => $attachment_id,
                    'file' => basename($path),
                ));
                return array('success' => true, 'path' => $path, 'temporary' => false, 'generated_files' => array(), 'message' => 'Original image ready');
            }

            $this->add_log('info', 'Image conversion started for Rubika upload.', array(
                'product_id' => $product_id,
                'attachment_id' => $attachment_id,
                'source' => basename($path),
                'extension' => $extension,
            ));

            $generated_files = array();
            $final_jpg = trailingslashit(get_temp_dir()) . 'wcrb-rubika-' . wp_generate_uuid4() . '.jpg';
            $generated_files[] = $final_jpg;
            $converted = false;

            if (function_exists('wp_get_image_editor')) {
                $editor = wp_get_image_editor($path);
                if (!is_wp_error($editor)) {
                    $saved = $editor->save($final_jpg, 'image/jpeg');
                    $converted = !is_wp_error($saved) && !empty($saved['path']) && file_exists($saved['path']);
                    if ($converted && $saved['path'] !== $final_jpg) {
                        $final_jpg = $saved['path'];
                        $generated_files[] = $final_jpg;
                    }
                }
            }

            if (!$converted && function_exists('imagejpeg')) {
                $image_resource = false;
                if ($extension === 'webp' && function_exists('imagecreatefromwebp')) {
                    $image_resource = @imagecreatefromwebp($path);
                } elseif ($extension === 'avif' && function_exists('imagecreatefromavif')) {
                    $image_resource = @imagecreatefromavif($path);
                } else {
                    $raw_contents = @file_get_contents($path);
                    if ($raw_contents !== false && function_exists('imagecreatefromstring')) {
                        $image_resource = @imagecreatefromstring($raw_contents);
                    }
                }

                if ($image_resource) {
                    $converted = @imagejpeg($image_resource, $final_jpg, 90);
                    imagedestroy($image_resource);
                }
            }

            if (!$converted) {
                $prepared = array('path' => $final_jpg, 'temporary' => true, 'generated_files' => $generated_files);
                $this->cleanup_prepared_image($prepared, $attachment_id, $product_id);
                $this->add_log('error', 'Image conversion failed for Rubika upload.', array(
                    'product_id' => $product_id,
                    'attachment_id' => $attachment_id,
                    'source' => basename($path),
                ));
                return array('success' => false, 'message' => 'Image conversion failed', 'path' => $final_jpg, 'temporary' => true, 'generated_files' => $generated_files);
            }

            clearstatcache(true, $final_jpg);
            $validation = $this->wait_for_valid_image_file($final_jpg, true);
            if (!$validation['success']) {
                $prepared = array('path' => $final_jpg, 'temporary' => true, 'generated_files' => $generated_files);
                $this->cleanup_prepared_image($prepared, $attachment_id, $product_id);
                $this->add_log('error', 'Converted JPG validation failed.', array(
                    'product_id' => $product_id,
                    'attachment_id' => $attachment_id,
                    'file' => basename($final_jpg),
                    'reason' => $validation['message'],
                ));
                return array('success' => false, 'message' => $validation['message'], 'path' => $final_jpg, 'temporary' => true, 'generated_files' => $generated_files);
            }

            $this->add_log('info', 'Image conversion completed and validated for Rubika upload.', array(
                'product_id' => $product_id,
                'attachment_id' => $attachment_id,
                'source' => basename($path),
                'file' => basename($final_jpg),
                'size' => filesize($final_jpg),
            ));

            return array('success' => true, 'path' => $final_jpg, 'temporary' => true, 'generated_files' => $generated_files, 'message' => 'Converted image ready');
        }

        private function wait_for_valid_image_file($path, $require_jpeg = true) {
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                clearstatcache(true, $path);
                if (file_exists($path) && is_readable($path) && filesize($path) > 0) {
                    $image_info = @getimagesize($path);
                    if (is_array($image_info) && !empty($image_info['mime'])) {
                        if (!$require_jpeg || $image_info['mime'] === 'image/jpeg') {
                            return array('success' => true, 'message' => 'Image file is valid');
                        }
                        return array('success' => false, 'message' => 'Validated image is not JPEG: ' . $image_info['mime']);
                    }
                }
                usleep(250000);
            }

            return array('success' => false, 'message' => 'Image file was not ready or valid after retry checks');
        }

        private function cleanup_prepared_image($prepared, $attachment_id = 0, $product_id = 0) {
            if (empty($prepared['temporary'])) {
                return;
            }

            $files = array();
            if (!empty($prepared['generated_files']) && is_array($prepared['generated_files'])) {
                $files = $prepared['generated_files'];
            } elseif (!empty($prepared['path'])) {
                $files[] = $prepared['path'];
            }

            foreach (array_unique(array_filter($files)) as $file) {
                if (file_exists($file)) {
                    $deleted = @unlink($file);
                    $this->add_log($deleted ? 'info' : 'warning', 'Temporary Rubika image cleanup ' . ($deleted ? 'completed.' : 'failed.'), array(
                        'product_id' => $product_id,
                        'attachment_id' => $attachment_id,
                        'file' => basename($file),
                    ));
                }
            }
        }

        private function extract_file_id_from_upload_response($json) {
            if (!is_array($json)) {
                return '';
            }

            $possible_keys = array('file_id', 'fileId', 'id');
            foreach ($possible_keys as $key) {
                if (!empty($json[$key]) && is_scalar($json[$key])) {
                    return (string) $json[$key];
                }
            }

            foreach ($json as $value) {
                if (is_array($value)) {
                    $nested = $this->extract_file_id_from_upload_response($value);
                    if (!empty($nested)) {
                        return $nested;
                    }
                }
            }

            return '';
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
                            ),
                        ),
                    ),
                ),
            );
        }

        private function rubika_api_request($token, $method, $payload) {
            if (empty($token)) {
                return array('success' => false, 'message' => 'Bot token is empty');
            }

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
            $raw_body = wp_remote_retrieve_body($response);
            $body = json_decode($raw_body, true);

            if ($status_code < 200 || $status_code >= 300) {
                return array('success' => false, 'message' => 'HTTP ' . $status_code . ': ' . wp_strip_all_tags($raw_body));
            }

            if (!is_array($body)) {
                return array('success' => false, 'message' => 'Invalid JSON response');
            }

            if (isset($body['ok']) && !$body['ok']) {
                $error_text = !empty($body['description']) ? $body['description'] : 'Rubika API returned ok=false';
                return array('success' => false, 'message' => $error_text, 'data' => $body);
            }

            if (isset($body['status'])) {
                $normalized = strtoupper((string) $body['status']);
                $allowed = array('OK', 'SUCCESS');
                if (!in_array($normalized, $allowed, true)) {
                    $error_text = !empty($body['description']) ? $body['description'] : ('Rubika status: ' . $body['status']);
                    return array('success' => false, 'message' => $error_text, 'data' => $body);
                }
            }

            return array('success' => true, 'data' => $body, 'message' => 'OK');
        }

        public function admin_bar_publish_button($wp_admin_bar) {
            if (!current_user_can('edit_products')) {
                return;
            }

            $product_id = 0;
            if (is_admin()) {
                $screen = function_exists('get_current_screen') ? get_current_screen() : null;
                if ($screen && $screen->base === 'post' && $screen->post_type === 'product') {
                    $product_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
                }
            } elseif (function_exists('is_product') && is_product()) {
                $product_id = get_queried_object_id();
            }

            if (!$product_id) {
                return;
            }

            $post = get_post($product_id);
            if (!$post || $post->post_status !== 'publish') {
                return;
            }

            $wp_admin_bar->add_node(array(
                'id' => 'wcrb_social_menu',
                'title' => __('شبکه اجتماعی', 'wcrb'),
                'href' => false,
            ));

            $actions = array(
                'rubika' => __('ارسال به روبیکا', 'wcrb'),
                'telegram' => __('ارسال به تلگرام', 'wcrb'),
                'all' => __('ارسال به همه شبکه‌های فعال', 'wcrb'),
            );

            foreach ($actions as $network => $title) {
                if ($network !== 'all' && !$this->is_network_enabled($network)) {
                    continue;
                }
                $url = wp_nonce_url(
                    add_query_arg(
                        array('action' => 'wcrb_send_now_single', 'product_id' => $product_id, 'network' => $network),
                        admin_url('admin-post.php')
                    ),
                    'wcrb_send_now_single'
                );
                $wp_admin_bar->add_node(array(
                    'id' => 'wcrb_publish_product_' . $network,
                    'parent' => 'wcrb_social_menu',
                    'title' => $title,
                    'href' => $url,
                    'meta' => array('class' => 'wcrb-publish-product'),
                ));
            }

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

            if ($_GET['wcrb_notice'] === 'clear_queue') {
                echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Queue has been cleared.', 'wcrb') . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'clear_logs') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Logs cleared.', 'wcrb') . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'run_queue') {
                echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__('Queue runner executed once.', 'wcrb') . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'clear_database') {
                echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Plugin database data has been cleared.', 'wcrb') . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'test_ok') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Test message sent successfully.', 'wcrb') . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'test_fail') {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Test message failed. Check logs for details.', 'wcrb') . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'reset_sync') {
                echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Synced/unsynced product records were reset.', 'wcrb') . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'direct_ok') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Product sent directly to Rubika.', 'wcrb') . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'direct_fail') {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Direct send failed. Check logs.', 'wcrb') . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'plugin_disabled') {
                echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Social publishing is disabled from plugin settings.', 'wcrb') . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'telegram_test_ok') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Telegram relay test succeeded.', 'wcrb') . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'telegram_test_fail') {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Telegram relay test failed. Check logs.', 'wcrb') . '</p></div>';
            }
        }

        private function normalize_network($network) {
            $network = sanitize_key($network);
            return in_array($network, array('rubika', 'telegram'), true) ? $network : 'rubika';
        }

        private function get_enabled_networks() {
            $networks = array();
            if ($this->is_network_enabled('rubika')) {
                $networks[] = 'rubika';
            }
            if ($this->is_network_enabled('telegram')) {
                $networks[] = 'telegram';
            }
            return $networks;
        }

        private function is_network_enabled($network) {
            if (!$this->is_plugin_enabled()) {
                return false;
            }
            $settings = $this->get_settings();
            $network = $this->normalize_network($network);
            if ($network === 'telegram') {
                return !empty($settings['telegram_enabled']);
            }
            return !empty($settings['rubika_enabled']);
        }

        private function sent_meta_key($network) {
            return '_wcrb_' . $this->normalize_network($network) . '_last_sent_at';
        }

        private function sent_hash_meta_key($network) {
            return '_wcrb_' . $this->normalize_network($network) . '_last_payload_hash';
        }

        private function was_payload_sent($product_id, $network, $payload_hash) {
            if (empty($payload_hash)) {
                return false;
            }
            return hash_equals((string) get_post_meta($product_id, $this->sent_hash_meta_key($network), true), (string) $payload_hash);
        }

        private function build_request_id($product_id, $network) {
            return $this->normalize_network($network) . '-' . absint($product_id) . '-' . wp_generate_uuid4();
        }

        private function build_payload_hash($product, $network) {
            if (!$product) {
                return '';
            }
            $settings = $this->get_settings();
            $network = $this->normalize_network($network);
            $image_count = $network === 'telegram' ? (int) $settings['telegram_image_count'] : (int) $settings['image_count'];
            $image_ids = $this->collect_images($product, $image_count, $settings['excluded_images']);
            $image_urls = array();
            foreach ($image_ids as $image_id) {
                $image_urls[] = wp_get_attachment_url($image_id);
            }
            $payload = array(
                'product_id' => $product->get_id(),
                'network' => $network,
                'text' => $this->render_network_template($product, $network),
                'url' => get_permalink($product->get_id()),
                'price' => $this->plain_product_price($product),
                'images' => $image_ids,
                'image_urls' => $image_urls,
                'settings' => array(
                    'image_count' => $image_count,
                    'template' => $network === 'telegram' ? $settings['telegram_template'] : $settings['template'],
                    'parse_mode' => $network === 'telegram' ? $settings['telegram_parse_mode'] : '',
                    'destination' => $network === 'telegram' ? $settings['telegram_relay_url'] : $settings['channel'],
                ),
            );
            return hash('sha256', wp_json_encode($payload));
        }

        private function add_log($level, $message, $context = array()) {
            if (!$this->is_logging_enabled()) {
                return;
            }

            $logs = get_option(self::LOG_OPTION, array());
            if (!is_array($logs)) {
                $logs = array();
            }

            $line = sprintf(
                '[%s] [%s] %s',
                current_time('mysql'),
                strtoupper(sanitize_key($level)),
                sanitize_text_field($message)
            );

            if (!empty($context)) {
                $encoded = wp_json_encode($context);
                if ($encoded) {
                    $line .= ' | ' . $encoded;
                }
            }

            $logs[] = $line;
            if (count($logs) > 300) {
                $logs = array_slice($logs, -300);
            }
            update_option(self::LOG_OPTION, $logs, false);
        }

        private function is_logging_enabled() {
            $settings = $this->get_settings();
            return !empty($settings['enable_logs']);
        }

        private function is_plugin_enabled() {
            $settings = $this->get_settings();
            return !empty($settings['enable_plugin']);
        }

        private function get_logs() {
            $logs = get_option(self::LOG_OPTION, array());
            if (!is_array($logs)) {
                return array();
            }
            return array_reverse($logs);
        }
    }

    new WCRB_Plugin();
}
