<?php

if (!defined('ABSPATH')) {
    exit;
}

class SEO_Autoptimiser {
    private $option_name = 'seo_autoptimiser_settings';

    public function init() {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('add_meta_boxes', [$this, 'register_meta_box']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('elementor/editor/after_enqueue_scripts', [$this, 'enqueue_elementor_assets']);
        add_action('wp_ajax_seo_autoptimiser_analyse', [$this, 'ajax_analyse_post']);
        add_action('save_post', [$this, 'maybe_auto_optimise_on_save'], 20, 3);
        add_action('seo_autoptimiser_cron_optimise', [$this, 'run_scheduled_optimisation']);

        if (!wp_next_scheduled('seo_autoptimiser_cron_optimise')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'seo_autoptimiser_cron_optimise');
        }

        register_deactivation_hook(dirname(__DIR__) . '/seo-autoptimiser.php', [$this, 'on_deactivation']);
    }

    public function on_deactivation() {
        wp_clear_scheduled_hook('seo_autoptimiser_cron_optimise');
    }

    public function register_admin_menu() {
        add_options_page('SEO AUTOPTIMISER', 'SEO AUTOPTIMISER', 'manage_options', 'seo-autoptimiser', [$this, 'render_settings_page']);
    }

    public function register_settings() {
        register_setting('seo_autoptimiser_group', $this->option_name, [$this, 'sanitize_settings']);

        add_settings_section('seo_autoptimiser_main', 'AI + SEO Rule Settings', function () {
            echo '<p>Use a free-tier AI provider key (Gemini) and define your SEO rules/company context.</p>';
        }, 'seo-autoptimiser');

        $fields = [
            'ai_provider' => 'AI Provider',
            'gemini_api_key' => 'Gemini API Key (free tier available)',
            'gemini_model' => 'Gemini Model (example: gemini-1.5-flash)',
            'seo_rules' => 'SEO Rules (one per line)',
            'company_information' => 'Company Information / Brand Voice',
            'auto_apply' => 'Auto-apply optimized content',
            'optimise_on_save' => 'Optimize when post is saved',
            'optimise_published_only' => 'Only optimize published posts',
            'max_posts_per_run' => 'Posts to process per hourly run',
            'target_language' => 'Target language',
        ];

        foreach ($fields as $field => $label) {
            add_settings_field($field, esc_html($label), [$this, 'render_field'], 'seo-autoptimiser', 'seo_autoptimiser_main', ['field' => $field]);
        }
    }

    public function sanitize_settings($input) {
        $defaults = $this->get_default_settings();
        $sanitized = wp_parse_args((array) $input, $defaults);
        $sanitized['ai_provider'] = in_array($sanitized['ai_provider'], ['gemini'], true) ? $sanitized['ai_provider'] : 'gemini';
        $sanitized['gemini_api_key'] = sanitize_text_field($sanitized['gemini_api_key']);
        $sanitized['gemini_model'] = sanitize_text_field($sanitized['gemini_model']);
        $sanitized['target_language'] = sanitize_text_field($sanitized['target_language']);
        $sanitized['seo_rules'] = sanitize_textarea_field($sanitized['seo_rules']);
        $sanitized['company_information'] = sanitize_textarea_field($sanitized['company_information']);
        $sanitized['auto_apply'] = !empty($sanitized['auto_apply']) ? 1 : 0;
        $sanitized['optimise_on_save'] = !empty($sanitized['optimise_on_save']) ? 1 : 0;
        $sanitized['optimise_published_only'] = !empty($sanitized['optimise_published_only']) ? 1 : 0;
        $sanitized['max_posts_per_run'] = max(1, min(20, intval($sanitized['max_posts_per_run'])));
        return $sanitized;
    }

    private function get_default_settings() {
        return [
            'ai_provider' => 'gemini',
            'gemini_api_key' => '',
            'gemini_model' => 'gemini-1.5-flash',
            'seo_rules' => "Use one H1 only\nUse clear H2/H3 hierarchy\nAvoid keyword stuffing",
            'company_information' => '',
            'auto_apply' => 0,
            'optimise_on_save' => 0,
            'optimise_published_only' => 1,
            'max_posts_per_run' => 3,
            'target_language' => 'English',
        ];
    }

    private function get_settings() {
        return wp_parse_args(get_option($this->option_name, []), $this->get_default_settings());
    }

    public function render_field($args) {
        $field = $args['field'];
        $settings = $this->get_settings();
        $value = $settings[$field] ?? '';

        if ($field === 'ai_provider') {
            echo '<select name="' . esc_attr($this->option_name . '[' . $field . ']') . '"><option value="gemini" ' . selected($value, 'gemini', false) . '>Gemini</option></select>';
            return;
        }

        if (in_array($field, ['auto_apply', 'optimise_on_save', 'optimise_published_only'], true)) {
            echo '<label><input type="checkbox" name="' . esc_attr($this->option_name . '[' . $field . ']') . '" value="1" ' . checked(1, intval($value), false) . '> Enabled</label>';
            return;
        }

        if ($field === 'max_posts_per_run') {
            echo '<input type="number" min="1" max="20" name="' . esc_attr($this->option_name . '[' . $field . ']') . '" value="' . esc_attr($value) . '">';
            return;
        }

        if (in_array($field, ['seo_rules', 'company_information'], true)) {
            echo '<textarea class="large-text" rows="6" name="' . esc_attr($this->option_name . '[' . $field . ']') . '">' . esc_textarea($value) . '</textarea>';
            return;
        }

        $type = ($field === 'gemini_api_key') ? 'password' : 'text';
        echo '<input type="' . esc_attr($type) . '" class="regular-text" name="' . esc_attr($this->option_name . '[' . $field . ']') . '" value="' . esc_attr($value) . '">';
    }

    public function render_settings_page() { ?>
        <div class="wrap"><h1>SEO AUTOPTIMISER</h1><p>Configured for Gemini API (free tier available from Google AI Studio).</p>
        <form method="post" action="options.php"><?php settings_fields('seo_autoptimiser_group'); do_settings_sections('seo-autoptimiser'); submit_button('Save Settings'); ?></form></div>
    <?php }

    public function register_meta_box() {
        add_meta_box('seo_autoptimiser_meta_box', 'SEO AUTOPTIMISER', [$this, 'render_meta_box'], ['post', 'page'], 'side', 'high');
    }

    public function render_meta_box($post) {
        wp_nonce_field('seo_autoptimiser_meta_box_nonce', 'seo_autoptimiser_meta_box_nonce');
        echo '<p>Analyze and get SEO suggestions (incl. Elementor suggestion mode).</p>';
        echo '<button type="button" class="button button-primary" id="seo-autoptimiser-analyse" data-post-id="' . esc_attr($post->ID) . '">Run SEO Optimization</button>';
        echo '<div id="seo-autoptimiser-result" style="margin-top:10px;font-size:12px;"></div>';
    }

    public function enqueue_assets($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;
        wp_enqueue_script('seo-autoptimiser-admin', SEO_AUTOPTIMISER_URL . 'includes/seo-autoptimiser-admin.js', ['jquery'], SEO_AUTOPTIMISER_VERSION, true);
        wp_localize_script('seo-autoptimiser-admin', 'seoAutoptimiser', ['ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('seo_autoptimiser_ajax_nonce'), 'isElementor' => false]);
    }

    public function enqueue_elementor_assets() {
        wp_enqueue_script('seo-autoptimiser-admin', SEO_AUTOPTIMISER_URL . 'includes/seo-autoptimiser-admin.js', ['jquery'], SEO_AUTOPTIMISER_VERSION, true);
        $post_id = isset($_GET['post']) ? intval($_GET['post']) : 0;
        wp_localize_script('seo-autoptimiser-admin', 'seoAutoptimiser', ['ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('seo_autoptimiser_ajax_nonce'), 'isElementor' => true, 'postId' => $post_id]);
    }

    public function ajax_analyse_post() {
        check_ajax_referer('seo_autoptimiser_ajax_nonce', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error(['message' => 'Unauthorized']);
        $post_id = isset($_POST['postId']) ? intval($_POST['postId']) : 0;
        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, ['post', 'page'], true)) wp_send_json_error(['message' => 'Invalid post']);

        $result = $this->optimise_post_content($post, true);
        if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()]);
        wp_send_json_success($result);
    }

    public function maybe_auto_optimise_on_save($post_id, $post, $update) {
        if (!$update || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        $settings = $this->get_settings();
        if (empty($settings['optimise_on_save'])) return;
        if (!empty($settings['optimise_published_only']) && $post->post_status !== 'publish') return;
        $this->optimise_post_content($post, false);
    }

    public function run_scheduled_optimisation() {
        $settings = $this->get_settings();
        $posts = get_posts(['post_type' => ['post', 'page'], 'post_status' => !empty($settings['optimise_published_only']) ? 'publish' : ['publish', 'draft', 'pending'], 'posts_per_page' => intval($settings['max_posts_per_run']), 'orderby' => 'modified', 'order' => 'DESC']);
        foreach ($posts as $post) $this->optimise_post_content($post, false);
    }

    private function get_elementor_text_nodes($post_id) {
        $raw = get_post_meta($post_id, '_elementor_data', true);
        $nodes = [];
        if (!$raw) return $nodes;
        $data = json_decode($raw, true);
        if (!is_array($data)) return $nodes;
        $walker = function ($items) use (&$walker, &$nodes) {
            foreach ((array)$items as $item) {
                if (!empty($item['settings']) && is_array($item['settings'])) {
                    foreach ($item['settings'] as $k => $v) {
                        if (is_string($v) && strlen(trim(wp_strip_all_tags($v))) > 20) $nodes[] = ['field' => $k, 'text' => wp_strip_all_tags($v)];
                    }
                }
                if (!empty($item['elements'])) $walker($item['elements']);
            }
        };
        $walker($data);
        return array_slice($nodes, 0, 25);
    }

    private function optimise_post_content($post, $manual_mode = false) {
        $settings = $this->get_settings();
        if (empty($settings['gemini_api_key'])) return new WP_Error('missing_api_key', 'Please add Gemini API key in settings.');

        $content = wp_strip_all_tags($post->post_content);
        $elementor_nodes = $this->get_elementor_text_nodes($post->ID);
        $context = "POST CONTENT:\n{$content}\n\nELEMENTOR TEXT NODES:\n" . wp_json_encode($elementor_nodes);

        if (strlen(trim($content)) < 30 && empty($elementor_nodes)) return new WP_Error('content_too_short', 'Content too short to optimize.');

        $prompt = $this->build_prompt($post->post_title, $context, $settings);
        $response = $this->call_gemini_api($prompt, $settings);
        if (is_wp_error($response)) return $response;

        $optimized_title = $response['optimized_title'] ?? $post->post_title;
        $optimized_content = $response['optimized_content_html'] ?? $post->post_content;
        $meta_description = $response['meta_description'] ?? '';
        $keywords = (isset($response['keywords']) && is_array($response['keywords'])) ? $response['keywords'] : [];
        $changes = (isset($response['text_changes']) && is_array($response['text_changes'])) ? $response['text_changes'] : [];

        if (!empty($settings['auto_apply']) && !$manual_mode) {
            remove_action('save_post', [$this, 'maybe_auto_optimise_on_save'], 20);
            wp_update_post(['ID' => $post->ID, 'post_title' => wp_kses_post($optimized_title), 'post_content' => wp_kses_post($optimized_content)]);
            add_action('save_post', [$this, 'maybe_auto_optimise_on_save'], 20, 3);
            if (!empty($meta_description)) {
                update_post_meta($post->ID, '_yoast_wpseo_metadesc', sanitize_text_field($meta_description));
                update_post_meta($post->ID, '_rank_math_description', sanitize_text_field($meta_description));
            }
        }

        return ['optimized_title' => $optimized_title, 'optimized_content_html' => $optimized_content, 'meta_description' => $meta_description, 'keywords' => $keywords, 'text_changes' => $changes, 'applied' => (!empty($settings['auto_apply']) && !$manual_mode)];
    }

    private function build_prompt($title, $content, $settings) {
        return "You are an expert SEO content editor for WordPress and Elementor. Return JSON only.\n"
        . "Required keys: optimized_title, optimized_content_html, meta_description, keywords, text_changes.\n"
        . "text_changes is array of objects: {original_text, suggested_text, reason}.\n"
        . "SEO RULES:\n{$settings['seo_rules']}\n"
        . "COMPANY INFORMATION:\n{$settings['company_information']}\n"
        . "Target language: {$settings['target_language']}\n"
        . "Use concise, factual, search-intent-focused rewrites and mention where text should be improved.\n"
        . "TITLE: {$title}\nCONTENT:\n{$content}";
    }

    private function call_gemini_api($prompt, $settings) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($settings['gemini_model']) . ':generateContent?key=' . rawurlencode($settings['gemini_api_key']);
        $schema = [
            'type' => 'OBJECT',
            'properties' => [
                'optimized_title' => ['type' => 'STRING'],
                'optimized_content_html' => ['type' => 'STRING'],
                'meta_description' => ['type' => 'STRING'],
                'keywords' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                'text_changes' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['original_text' => ['type' => 'STRING'], 'suggested_text' => ['type' => 'STRING'], 'reason' => ['type' => 'STRING']]]],
            ],
        ];
        $body = ['contents' => [['parts' => [['text' => $prompt]]]], 'generationConfig' => ['responseMimeType' => 'application/json', 'responseSchema' => $schema]];
        $request = wp_remote_post($url, ['headers' => ['Content-Type' => 'application/json'], 'body' => wp_json_encode($body), 'timeout' => 60]);
        if (is_wp_error($request)) return $request;
        $status = wp_remote_retrieve_response_code($request);
        $payload = json_decode(wp_remote_retrieve_body($request), true);
        if ($status < 200 || $status >= 300) return new WP_Error('gemini_error', 'Gemini API request failed.');
        $json_text = $payload['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $data = json_decode($json_text, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) return new WP_Error('invalid_json', 'Could not parse AI response JSON.');
        return $data;
    }
}
