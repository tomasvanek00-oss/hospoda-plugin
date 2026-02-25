<?php

if (!defined('ABSPATH')) {
    exit;
}

trait Hospoda_Orders_Core_Trait {
    private function get_order_settings_defaults(): array {
        return [
            'enabled' => 0,
            'require_login' => 1,
            'custom_login_url' => '',
            'mode' => 'both',
            'cutoff_type' => 'same_day_time',
            'cutoff_value' => '09:30',
            'cutoff_days_before_monday' => 5,
            'delivery_mode' => 'both',
            'delivery_fee_type' => 'fixed',
            'delivery_fee_value' => '0',
            'delivery_zones' => "",
            'payment_mode' => 'reservation',
            'notification_email' => get_option('admin_email'),
            'customer_email_template' => 'Děkujeme za objednávku #{order_number}.',
            'ops_email_template' => 'Nová objednávka #{order_number} na datum {menu_date}.',
            'max_orders_per_day' => 0,
            'max_item_qty' => 0,
            'gdpr_text' => 'Souhlasím se zpracováním osobních údajů pro vyřízení objednávky.',
            'gdpr_link' => '',
            'retention_days' => 90,
        ];
    }

    private function get_order_settings(): array {
        $stored = get_option('hsp_order_settings', []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return $this->sanitize_order_settings(wp_parse_args($stored, $this->get_order_settings_defaults()));
    }

    private function sanitize_order_settings(array $input): array {
        $defaults = $this->get_order_settings_defaults();
        $data = wp_parse_args($input, $defaults);
        $data['enabled'] = !empty($data['enabled']) ? 1 : 0;
        $data['require_login'] = !empty($data['require_login']) ? 1 : 0;
        $data['custom_login_url'] = esc_url_raw((string)($data['custom_login_url'] ?? ''));
        $data['mode'] = in_array($data['mode'], ['day', 'week', 'both'], true) ? $data['mode'] : 'both';
        $data['cutoff_type'] = in_array($data['cutoff_type'], ['same_day_time', 'day_before_time', 'hours_before', 'week_days_before_monday_time'], true) ? $data['cutoff_type'] : 'same_day_time';
        $data['cutoff_value'] = sanitize_text_field((string)$data['cutoff_value']);
        $data['cutoff_days_before_monday'] = max(0, min(14, (int)$data['cutoff_days_before_monday']));
        $data['delivery_mode'] = in_array($data['delivery_mode'], ['delivery', 'pickup', 'both'], true) ? $data['delivery_mode'] : 'both';
        $data['delivery_fee_type'] = in_array($data['delivery_fee_type'], ['fixed', 'zone', 'free_from'], true) ? $data['delivery_fee_type'] : 'fixed';
        $data['delivery_fee_value'] = sanitize_text_field((string)$data['delivery_fee_value']);
        $data['delivery_zones'] = sanitize_textarea_field((string)$data['delivery_zones']);
        $data['payment_mode'] = in_array($data['payment_mode'], ['reservation', 'cash', 'qr', 'future'], true) ? $data['payment_mode'] : 'reservation';
        $data['notification_email'] = sanitize_email((string)$data['notification_email']);
        $data['customer_email_template'] = sanitize_textarea_field((string)$data['customer_email_template']);
        $data['ops_email_template'] = sanitize_textarea_field((string)$data['ops_email_template']);
        $data['max_orders_per_day'] = max(0, (int)$data['max_orders_per_day']);
        $data['max_item_qty'] = max(0, (int)$data['max_item_qty']);
        $data['gdpr_text'] = sanitize_textarea_field((string)$data['gdpr_text']);
        $data['gdpr_link'] = esc_url_raw((string)$data['gdpr_link']);
        $data['retention_days'] = max(7, (int)$data['retention_days']);
        return $data;
    }

    private function get_order_login_url(string $redirect_url = ''): string {
        $settings = $this->get_order_settings();
        $custom = trim((string)($settings['custom_login_url'] ?? ''));
        $target_redirect = $redirect_url !== '' ? add_query_arg('hsp_after_login', '1', $redirect_url) : '';

        if ($custom !== '') {
            if ($target_redirect !== '') {
                return add_query_arg('redirect_to', $target_redirect, $custom);
            }
            return $custom;
        }

        $auto_login_url = $this->ensure_order_login_page($target_redirect);
        if ($auto_login_url !== '') {
            return $auto_login_url;
        }

        return wp_login_url($target_redirect !== '' ? $target_redirect : home_url('/'));
    }

    private function ensure_order_login_page(string $redirect_url = ''): string {
        $stored_id = (int) get_option('hsp_order_login_page_id', 0);
        if ($stored_id > 0) {
            $post = get_post($stored_id);
            if ($post instanceof \WP_Post && $post->post_status === 'publish') {
                $url = get_permalink($stored_id);
                if (is_string($url) && $url !== '') {
                    return $redirect_url !== '' ? add_query_arg('redirect_to', $redirect_url, $url) : $url;
                }
            }
        }

        $existing = get_posts([
            'post_type' => 'page',
            'posts_per_page' => 1,
            'post_status' => 'publish',
            'meta_key' => '_hsp_order_login_page',
            'meta_value' => '1',
            'fields' => 'ids',
        ]);

        if (!empty($existing[0])) {
            $existing_id = (int) $existing[0];
            update_option('hsp_order_login_page_id', $existing_id, false);
            $url = get_permalink($existing_id);
            if (is_string($url) && $url !== '') {
                return $redirect_url !== '' ? add_query_arg('redirect_to', $redirect_url, $url) : $url;
            }
        }

        $new_id = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Přihlášení pro objednávky',
            'post_name' => 'prihlaseni-objednavky',
            'post_content' => '[hsp_order_login]',
        ], true);

        if (is_wp_error($new_id) || !$new_id) {
            return '';
        }

        update_post_meta((int)$new_id, '_hsp_order_login_page', '1');
        update_option('hsp_order_login_page_id', (int)$new_id, false);

        $url = get_permalink((int)$new_id);
        if (!is_string($url) || $url === '') {
            return '';
        }

        return $redirect_url !== '' ? add_query_arg('redirect_to', $redirect_url, $url) : $url;
    }

    private function get_order_register_url(string $redirect_url = ''): string {
        $url = $this->ensure_order_register_page($redirect_url);
        if ($url !== '') {
            return $url;
        }
        return wp_registration_url();
    }

    private function ensure_order_register_page(string $redirect_url = ''): string {
        $stored_id = (int) get_option('hsp_order_register_page_id', 0);
        if ($stored_id > 0) {
            $post = get_post($stored_id);
            if ($post instanceof \WP_Post && $post->post_status === 'publish') {
                $url = get_permalink($stored_id);
                if (is_string($url) && $url !== '') {
                    return $redirect_url !== '' ? add_query_arg('redirect_to', $redirect_url, $url) : $url;
                }
            }
        }

        $existing = get_posts([
            'post_type' => 'page',
            'posts_per_page' => 1,
            'post_status' => 'publish',
            'meta_key' => '_hsp_order_register_page',
            'meta_value' => '1',
            'fields' => 'ids',
        ]);

        if (!empty($existing[0])) {
            $existing_id = (int) $existing[0];
            update_option('hsp_order_register_page_id', $existing_id, false);
            $url = get_permalink($existing_id);
            if (is_string($url) && $url !== '') {
                return $redirect_url !== '' ? add_query_arg('redirect_to', $redirect_url, $url) : $url;
            }
        }

        $new_id = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Registrace pro objednávky',
            'post_name' => 'registrace-objednavky',
            'post_content' => '[hsp_order_register]',
        ], true);

        if (is_wp_error($new_id) || !$new_id) {
            return '';
        }

        update_post_meta((int)$new_id, '_hsp_order_register_page', '1');
        update_option('hsp_order_register_page_id', (int)$new_id, false);

        $url = get_permalink((int)$new_id);
        if (!is_string($url) || $url === '') {
            return '';
        }

        return $redirect_url !== '' ? add_query_arg('redirect_to', $redirect_url, $url) : $url;
    }

    private function is_ordering_enabled(): bool {
        $settings = $this->get_order_settings();
        return !empty($settings['enabled']);
    }

    private function get_order_statuses(): array {
        return [
            'hsp_new' => 'Nová',
            'hsp_confirmed' => 'Potvrzená',
            'hsp_in_kitchen' => 'V kuchyni',
            'hsp_out_for_delivery' => 'Na rozvozu',
            'hsp_done' => 'Hotovo',
            'hsp_cancelled' => 'Zrušeno',
        ];
    }

    private function parse_zone_fees(string $zones): array {
        $result = [];
        foreach (preg_split('/
|
|
/', $zones) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }
            [$name, $fee] = array_map('trim', explode(':', $line, 2));
            if ($name === '') {
                continue;
            }
            $result[sanitize_title($name)] = [
                'label' => $name,
                'fee' => (float)str_replace(',', '.', $fee),
            ];
        }
        return $result;
    }

    private function compute_delivery_fee(array $settings, string $delivery_type, string $address): float {
        if ($delivery_type !== 'delivery') {
            return 0.0;
        }

        $type = $settings['delivery_fee_type'] ?? 'fixed';
        $value = (float)str_replace(',', '.', (string)($settings['delivery_fee_value'] ?? '0'));
        if ($type === 'fixed') {
            return max(0, $value);
        }

        if ($type === 'zone') {
            $zones = $this->parse_zone_fees((string)($settings['delivery_zones'] ?? ''));
            $address_lower = mb_strtolower($address);
            foreach ($zones as $zone) {
                if (mb_strpos($address_lower, mb_strtolower($zone['label'])) !== false) {
                    return max(0, (float)$zone['fee']);
                }
            }
            return max(0, $value);
        }

        if ($type === 'free_from') {
            return max(0, $value);
        }

        return 0.0;
    }

    private function get_next_workdays(int $count = 5): array {
        $dates = [];
        $cursor = new \DateTimeImmutable('today', wp_timezone());
        while (count($dates) < $count) {
            $n = (int)$cursor->format('N');
            if ($n >= 1 && $n <= 5) {
                $dates[] = $cursor->format('Y-m-d');
            }
            $cursor = $cursor->modify('+1 day');
        }
        return $dates;
    }

    private function get_next_mondays(int $count = 4): array {
        $dates = [];
        $cursor = new \DateTimeImmutable('today', wp_timezone());
        while ((int)$cursor->format('N') !== 1) {
            $cursor = $cursor->modify('+1 day');
        }
        for ($i = 0; $i < $count; $i++) {
            $dates[] = $cursor->modify('+' . $i . ' week')->format('Y-m-d');
        }
        return $dates;
    }

    private function can_order_for_date(string $menu_date, array $settings): bool {
        $target = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $menu_date . ' 00:00', wp_timezone());
        if (!$target) {
            return false;
        }

        $now = new \DateTimeImmutable('now', wp_timezone());
        $cutoff_type = $settings['cutoff_type'] ?? 'same_day_time';
        $cutoff_value = (string)($settings['cutoff_value'] ?? '09:30');

        if ($cutoff_type === 'same_day_time') {
            $cutoff = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $menu_date . ' ' . $cutoff_value, wp_timezone());
            return $cutoff ? $now <= $cutoff : true;
        }

        if ($cutoff_type === 'day_before_time') {
            $base = $target->modify('-1 day');
            $cutoff = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $base->format('Y-m-d') . ' ' . $cutoff_value, wp_timezone());
            return $cutoff ? $now <= $cutoff : true;
        }

        if ($cutoff_type === 'week_days_before_monday_time') {
            $dow = (int)$target->format('N');
            $monday = $target->modify('-' . ($dow - 1) . ' days');
            $days_before = max(0, (int)($settings['cutoff_days_before_monday'] ?? 5));
            $deadline_day = $monday->modify('-' . $days_before . ' days')->format('Y-m-d');
            $cutoff = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $deadline_day . ' ' . $cutoff_value, wp_timezone());
            return $cutoff ? $now <= $cutoff : true;
        }

        $hours = max(0, (int)$cutoff_value);
        $cutoff = $target->modify('-' . $hours . ' hours');
        return $now <= $cutoff;
    }

}
