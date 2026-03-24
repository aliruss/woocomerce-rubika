<?php
/**
 * Plugin Name: WooCommerce Rubika Bridge
 * Description: Lightweight WooCommerce to Rubika publisher with queue, scheduling, and per-product controls.
 * Version: 1.1.0
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
            add_action('init', array($this, 'bootstrap_queue_runner'));

            add_action('admin_post_wcrb_enqueue_all', array($this, 'handle_enqueue_all'));
            add_action('admin_post_wcrb_enqueue_single', array($this, 'handle_enqueue_single'));
            add_action('admin_post_wcrb_clear_queue', array($this, 'handle_clear_queue'));
            add_action('admin_post_wcrb_clear_logs', array($this, 'handle_clear_logs'));
            add_action('admin_post_wcrb_run_queue', array($this, 'handle_run_queue_now'));
            add_action('admin_post_wcrb_clear_database', array($this, 'handle_clear_database'));
            add_action('admin_post_wcrb_send_test_message', array($this, 'handle_send_test_message'));
            add_action('admin_post_wcrb_reset_sync_records', array($this, 'handle_reset_sync_records'));

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

            $this->ensure_cron_event_scheduled();

            $this->add_log('info', 'Plugin activated.');
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

        public function enqueue_admin_assets($hook) {
            if ($hook !== 'woocommerce_page_wcrb-settings') {
                return;
            }

            wp_enqueue_media();
            wp_enqueue_script('jquery');
            wp_add_inline_script(
                'jquery',
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
            return $sanitized;
        }

        public function render_settings_page() {
            if (!current_user_can('manage_woocommerce')) {
                return;
            }

            $settings = $this->get_settings();
            list($synced, $unsynced) = $this->product_sync_counts();
            $queue_stats = $this->queue_stats();
            $logs = $this->get_logs();
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('WooCommerce Rubika Bridge', 'wcrb'); ?></h1>
                <p><?php echo esc_html(sprintf(__('Synced products: %d | Unsynced products: %d', 'wcrb'), $synced, $unsynced)); ?></p>
                <p>
                    <?php echo esc_html(sprintf(__('Queue — Pending: %d | Processing: %d | Sent: %d | Failed: %d', 'wcrb'), $queue_stats['pending'], $queue_stats['processing'], $queue_stats['sent'], $queue_stats['failed'])); ?>
                </p>

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

            $this->add_log('info', 'Bulk enqueue completed.', array('queued' => $count));
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
                $this->add_log('info', 'Single product queued.', array('product_id' => $product_id));
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
            $wpdb->delete($wpdb->postmeta, array('meta_key' => '_wcrb_last_sent_at'), array('%s'));
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

            $wpdb->delete($wpdb->postmeta, array('meta_key' => '_wcrb_last_sent_at'), array('%s'));
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

        private function enqueue_product($product_id) {
            $post = get_post($product_id);
            if (!$post || $post->post_type !== 'product' || $post->post_status !== 'publish') {
                return false;
            }

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

        public function process_queue($force = false) {
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
                $this->add_log('info', 'Product sent to Rubika.', array('product_id' => (int) $item->product_id));
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
                $this->add_log('error', 'Send failed.', array('product_id' => (int) $item->product_id, 'message' => $sent['message'], 'attempts' => $attempts));
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
            if (!$product || $product->get_status() !== 'publish') {
                return array('success' => false, 'message' => 'Invalid or unpublished product');
            }

            $text = $this->render_template($product, $settings);
            $images = $this->collect_images($product, (int) $settings['image_count'], $settings['excluded_images']);

            $image_send_failed = false;
            $message_sent = false;
            foreach ($images as $index => $attachment_id) {
                $upload = $this->upload_image_to_rubika($attachment_id, $settings['bot_token']);
                if (!$upload['success']) {
                    $image_send_failed = true;
                    $this->add_log('warning', 'Image upload failed, fallback to text-only message.', array(
                        'product_id' => $product_id,
                        'attachment_id' => $attachment_id,
                        'reason' => $upload['message'],
                    ));
                    break;
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
                    $image_send_failed = true;
                    $this->add_log('warning', 'Image send failed, fallback to text-only message.', array(
                        'product_id' => $product_id,
                        'attachment_id' => $attachment_id,
                        'reason' => $result['message'],
                    ));
                    break;
                }

                if ($index === 0) {
                    $message_sent = true;
                }
            }

            if (empty($images) || ($image_send_failed && !$message_sent)) {
                return $this->send_text_message($settings['bot_token'], array(
                    'chat_id' => $settings['channel'],
                    'text' => $text,
                    'disable_notification' => (bool) $settings['disable_notification'],
                    'inline_keypad' => $this->build_buy_keypad($product),
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
                    return $result;
                }
                if (strpos($result['message'], 'INVALID_INPUT') !== false) {
                    $stripped_payload = $payload;
                    unset($stripped_payload['inline_keypad'], $stripped_payload['disable_notification']);
                    $retry = $this->rubika_api_request($token, $method, $stripped_payload);
                    if ($retry['success']) {
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
            $original_path = get_attached_file($attachment_id);
            if (!$original_path || !file_exists($original_path)) {
                return array('success' => false, 'message' => 'Image file missing');
            }

            $prepared = $this->prepare_image_for_rubika_upload($original_path);
            $path = $prepared['path'];
            $cleanup_temp_file = !empty($prepared['temporary']);
            if ($cleanup_temp_file) {
                $this->add_log('info', 'Converted WEBP to JPG for Rubika upload.', array('source' => basename($original_path)));
            }

            $request = $this->rubika_api_request($token, 'requestSendFile', array('type' => 'Image'));
            if (!$request['success']) {
                $request = $this->rubika_api_request($token, 'requestSendFile', array('type' => 'File'));
            }
            if (!$request['success']) {
                if ($cleanup_temp_file && file_exists($path)) {
                    @unlink($path);
                }
                return $request;
            }

            $upload_url = '';
            if (!empty($request['data']['upload_url'])) {
                $upload_url = $request['data']['upload_url'];
            } elseif (!empty($request['data']['data']['upload_url'])) {
                $upload_url = $request['data']['data']['upload_url'];
            }

            if (empty($upload_url)) {
                if ($cleanup_temp_file && file_exists($path)) {
                    @unlink($path);
                }
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
                        'file' => new CURLFile($path),
                    ),
                ));
                $curl_response = curl_exec($curl);
                if ($curl_response !== false) {
                    $raw_upload_body = (string) $curl_response;
                }
                curl_close($curl);
            }

            if ($raw_upload_body === '') {
                $file_part = function_exists('curl_file_create') ? curl_file_create($path) : '@' . $path;
                $response = wp_remote_post($upload_url, array(
                    'timeout' => 60,
                    'body' => array(
                        'file' => $file_part,
                    ),
                ));

                if (is_wp_error($response)) {
                    if ($cleanup_temp_file && file_exists($path)) {
                        @unlink($path);
                    }
                    return array('success' => false, 'message' => $response->get_error_message());
                }

                $raw_upload_body = wp_remote_retrieve_body($response);
            }

            $json = json_decode($raw_upload_body, true);
            $file_id = $this->extract_file_id_from_upload_response($json);

            if (empty($file_id)) {
                // Fallback: some upload endpoints expect raw binary body.
                $fallback_response = wp_remote_post($upload_url, array(
                    'timeout' => 60,
                    'headers' => array(
                        'Content-Type' => wp_check_filetype($path)['type'] ?: 'application/octet-stream',
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
                if ($cleanup_temp_file && file_exists($path)) {
                    @unlink($path);
                }
                return array('success' => false, 'message' => 'No file_id in upload response: ' . $body_for_log);
            }

            if ($cleanup_temp_file && file_exists($path)) {
                @unlink($path);
            }

            return array('success' => true, 'file_id' => $file_id);
        }

        private function prepare_image_for_rubika_upload($path) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($extension !== 'webp') {
                return array('path' => $path, 'temporary' => false);
            }

            if (!function_exists('imagecreatefromwebp') || !function_exists('imagejpeg')) {
                return array('path' => $path, 'temporary' => false);
            }

            $image_resource = @imagecreatefromwebp($path);
            if (!$image_resource) {
                return array('path' => $path, 'temporary' => false);
            }

            $temp_jpg = wp_tempnam('wcrb-webp-convert');
            if (!$temp_jpg) {
                imagedestroy($image_resource);
                return array('path' => $path, 'temporary' => false);
            }

            $result = @imagejpeg($image_resource, $temp_jpg, 90);
            imagedestroy($image_resource);
            if (!$result || !file_exists($temp_jpg)) {
                @unlink($temp_jpg);
                return array('path' => $path, 'temporary' => false);
            }

            return array('path' => $temp_jpg, 'temporary' => true);
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

            $url = wp_nonce_url(
                add_query_arg(
                    array('action' => 'wcrb_enqueue_single', 'product_id' => $product_id),
                    admin_url('admin-post.php')
                ),
                'wcrb_enqueue_single'
            );

            $wp_admin_bar->add_node(array(
                'id' => 'wcrb_publish_product',
                'title' => __('ارسال به صف روبیکا', 'wcrb'),
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
        }

        private function add_log($level, $message, $context = array()) {
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
