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
        add_options_page(
            'SEO AUTOPTIMISER',
            'SEO AUTOPTIMISER',
            'manage_options',
            'seo-autoptimiser',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting('seo_autoptimiser_group', $this->option_name, [$this, 'sanitize_settings']);

        add_settings_section(
            'seo_autoptimiser_main',
            'OpenAI + Automation Settings',
            function () {
                echo '<p>Connect your OpenAI API key and configure automatic SEO optimization behavior.</p>';
            },
            'seo-autoptimiser'
        );

        $fields = [
            'openai_api_key' => 'OpenAI API Key',
            'openai_model' => 'Model (example: gpt-4.1-mini)',
            'auto_apply' => 'Auto-apply optimized content',
            'optimise_on_save' => 'Optimize when post is saved',
            'optimise_published_only' => 'Only optimize published posts',
            'max_posts_per_run' => 'Posts to process per hourly run',
            'target_language' => 'Target language (example: English)',
        ];

        foreach ($fields as $field => $label) {
            add_settings_field(
                $field,
                esc_html($label),
                [$this, 'render_field'],
                'seo-autoptimiser',
                'seo_autoptimiser_main',
                ['field' => $field]
            );
        }
    }

    public function sanitize_settings($input) {
        $defaults = $this->get_default_settings();
        $sanitized = wp_parse_args((array) $input, $defaults);
        $sanitized['openai_api_key'] = sanitize_text_field($sanitized['openai_api_key']);
        $sanitized['openai_model'] = sanitize_text_field($sanitized['openai_model']);
        $sanitized['target_language'] = sanitize_text_field($sanitized['target_language']);
        $sanitized['auto_apply'] = !empty($sanitized['auto_apply']) ? 1 : 0;
        $sanitized['optimise_on_save'] = !empty($sanitized['optimise_on_save']) ? 1 : 0;
        $sanitized['optimise_published_only'] = !empty($sanitized['optimise_published_only']) ? 1 : 0;
        $sanitized['max_posts_per_run'] = max(1, min(20, intval($sanitized['max_posts_per_run'])));
        return $sanitized;
    }

    private function get_default_settings() {
        return [
            'openai_api_key' => '',
            'openai_model' => 'gpt-4.1-mini',
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
        $value = isset($settings[$field]) ? $settings[$field] : '';

        if (in_array($field, ['auto_apply', 'optimise_on_save', 'optimise_published_only'], true)) {
            echo '<label><input type="checkbox" name="' . esc_attr($this->option_name . '[' . $field . ']') . '" value="1" ' . checked(1, intval($value), false) . '> Enabled</label>';
            return;
        }

        if ($field === 'max_posts_per_run') {
            echo '<input type="number" min="1" max="20" name="' . esc_attr($this->option_name . '[' . $field . ']') . '" value="' . esc_attr($value) . '">';
            return;
        }

        $type = ($field === 'openai_api_key') ? 'password' : 'text';
        $placeholder = ($field === 'openai_api_key') ? 'sk-...' : '';

        echo '<input type="' . esc_attr($type) . '" class="regular-text" name="' . esc_attr($this->option_name . '[' . $field . ']') . '" value="' . esc_attr($value) . '" placeholder="' . esc_attr($placeholder) . '">';
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>SEO AUTOPTIMISER</h1>
            <p>
                This plugin can analyze and optimize your content with OpenAI. There is no official unlimited free OpenAI API key.
                You must add your own API key and manage usage/billing in your OpenAI account.
            </p>
            <form method="post" action="options.php">
                <?php
                settings_fields('seo_autoptimiser_group');
                do_settings_sections('seo-autoptimiser');
                submit_button('Save Settings');
                ?>
            </form>
        </div>
        <?php
    }

    public function register_meta_box() {
        add_meta_box(
            'seo_autoptimiser_meta_box',
            'SEO AUTOPTIMISER',
            [$this, 'render_meta_box'],
            ['post', 'page'],
            'side',
            'high'
        );
    }

    public function render_meta_box($post) {
        wp_nonce_field('seo_autoptimiser_meta_box_nonce', 'seo_autoptimiser_meta_box_nonce');
        echo '<p>Analyze content and generate SEO-optimized version.</p>';
        echo '<button type="button" class="button button-primary" id="seo-autoptimiser-analyse" data-post-id="' . esc_attr($post->ID) . '">Run SEO Optimization</button>';
        echo '<div id="seo-autoptimiser-result" style="margin-top:10px;font-size:12px;"></div>';
    }

    public function enqueue_assets($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        wp_enqueue_script(
            'seo-autoptimiser-admin',
            SEO_AUTOPTIMISER_URL . 'includes/seo-autoptimiser-admin.js',
            ['jquery'],
            SEO_AUTOPTIMISER_VERSION,
            true
        );

        wp_localize_script('seo-autoptimiser-admin', 'seoAutoptimiser', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('seo_autoptimiser_ajax_nonce'),
        ]);
    }

    public function ajax_analyse_post() {
        check_ajax_referer('seo_autoptimiser_ajax_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $post_id = isset($_POST['postId']) ? intval($_POST['postId']) : 0;
        $post = get_post($post_id);

        if (!$post || !in_array($post->post_type, ['post', 'page'], true)) {
            wp_send_json_error(['message' => 'Invalid post']);
        }

        $result = $this->optimise_post_content($post);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    public function maybe_auto_optimise_on_save($post_id, $post, $update) {
        if (!$update || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        $settings = $this->get_settings();

        if (empty($settings['optimise_on_save'])) {
            return;
        }

        if (!empty($settings['optimise_published_only']) && $post->post_status !== 'publish') {
            return;
        }

        $this->optimise_post_content($post);
    }

    public function run_scheduled_optimisation() {
        $settings = $this->get_settings();

        $args = [
            'post_type' => ['post', 'page'],
            'post_status' => !empty($settings['optimise_published_only']) ? 'publish' : ['publish', 'draft', 'pending'],
            'posts_per_page' => intval($settings['max_posts_per_run']),
            'orderby' => 'modified',
            'order' => 'DESC',
        ];

        $posts = get_posts($args);

        foreach ($posts as $post) {
            $this->optimise_post_content($post);
        }
    }

    private function optimise_post_content($post) {
        $settings = $this->get_settings();

        if (empty($settings['openai_api_key'])) {
            return new WP_Error('missing_api_key', 'Please add OpenAI API key in SEO AUTOPTIMISER settings.');
        }

        $content = wp_strip_all_tags($post->post_content);
        if (strlen(trim($content)) < 50) {
            return new WP_Error('content_too_short', 'Content is too short to optimize.');
        }

        $prompt = $this->build_prompt($post->post_title, $content, $settings['target_language']);
        $response = $this->call_openai_api($prompt, $settings);

        if (is_wp_error($response)) {
            return $response;
        }

        $optimized_title = !empty($response['optimized_title']) ? $response['optimized_title'] : $post->post_title;
        $optimized_content = !empty($response['optimized_content_html']) ? $response['optimized_content_html'] : $post->post_content;
        $meta_description = !empty($response['meta_description']) ? $response['meta_description'] : '';
        $keywords = !empty($response['keywords']) && is_array($response['keywords']) ? $response['keywords'] : [];

        if (!empty($settings['auto_apply'])) {
            remove_action('save_post', [$this, 'maybe_auto_optimise_on_save'], 20);
            wp_update_post([
                'ID' => $post->ID,
                'post_title' => wp_kses_post($optimized_title),
                'post_content' => wp_kses_post($optimized_content),
            ]);
            add_action('save_post', [$this, 'maybe_auto_optimise_on_save'], 20, 3);

            if (!empty($meta_description)) {
                update_post_meta($post->ID, '_yoast_wpseo_metadesc', sanitize_text_field($meta_description));
                update_post_meta($post->ID, '_rank_math_description', sanitize_text_field($meta_description));
            }

            if (!empty($keywords)) {
                $keyword_str = implode(', ', array_map('sanitize_text_field', $keywords));
                update_post_meta($post->ID, '_yoast_wpseo_focuskw', sanitize_text_field($keywords[0]));
                update_post_meta($post->ID, '_rank_math_focus_keyword', sanitize_text_field($keyword_str));
            }
        }

        return [
            'optimized_title' => $optimized_title,
            'meta_description' => $meta_description,
            'keywords' => $keywords,
            'applied' => !empty($settings['auto_apply']),
        ];
    }

    private function build_prompt($title, $content, $language) {
        return "You are an expert SEO strategist for WordPress Elementor websites.\n"
            . "Analyze and optimize this content.\n"
            . "Output valid JSON only with keys: optimized_title, optimized_content_html, meta_description, keywords.\n"
            . "Rules:\n"
            . "- Improve readability, structure (H2/H3), and search intent coverage.\n"
            . "- Extract primary and secondary keywords from existing content and likely user intent.\n"
            . "- Keep factual claims conservative and avoid keyword stuffing.\n"
            . "- Meta description max 155 chars.\n"
            . "- Content language: {$language}.\n"
            . "TITLE: {$title}\n"
            . "CONTENT:\n{$content}";
    }

    private function call_openai_api($prompt, $settings) {
        $body = [
            'model' => $settings['openai_model'],
            'input' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => $prompt],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'seo_output',
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'optimized_title' => ['type' => 'string'],
                            'optimized_content_html' => ['type' => 'string'],
                            'meta_description' => ['type' => 'string'],
                            'keywords' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                        'required' => ['optimized_title', 'optimized_content_html', 'meta_description', 'keywords'],
                        'additionalProperties' => false,
                    ],
                    'strict' => true,
                ],
            ],
        ];

        $request = wp_remote_post('https://api.openai.com/v1/responses', [
            'headers' => [
                'Authorization' => 'Bearer ' . $settings['openai_api_key'],
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
            'timeout' => 60,
        ]);

        if (is_wp_error($request)) {
            return $request;
        }

        $status = wp_remote_retrieve_response_code($request);
        $payload = json_decode(wp_remote_retrieve_body($request), true);

        if ($status < 200 || $status >= 300) {
            $message = isset($payload['error']['message']) ? $payload['error']['message'] : 'OpenAI API request failed.';
            return new WP_Error('openai_error', $message);
        }

        $json_text = $payload['output'][0]['content'][0]['text'] ?? '';
        $data = json_decode($json_text, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return new WP_Error('invalid_json', 'Could not parse AI response JSON.');
        }

        return $data;
    }
}
