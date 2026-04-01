<?php

namespace HospodaPlugin;

if (!defined('ABSPATH')) {
    exit;
}

trait Hospoda_Theme_Settings_Trait {
    private function get_frontend_theme_defaults(): array {
        return [
            'week' => [
                'card_bg'            => '#ffffff',
                'card_border'        => '#e8e8e8',
                'heading_bg'         => '#fafafa',
                'heading_text'       => '#0f172a',
                'body_text'          => '#111111',
                'sides_text'         => '#7a7a7a',
                'price_text'         => '#111111',
                'badge_bg'           => '#eef5ff',
                'badge_text'         => '#1f3a68',
                'group_bg'           => '#fff8ed',
                'group_border'       => '#f3d4b2',
                'group_title'        => '#b45309',
                'group_price'        => '#ef6c00',
                'bullet_color'       => '#ef6c00',
                'group_bullet_color' => '#b45309',
                'toggle_bg'          => '#ef6c00',
                'toggle_text'        => '#ffffff',
                'toggle_border'      => '#ef6c00',
                'toggle_active_bg'   => '#444444',
                'toggle_active_text' => '#ffffff',
                'toggle_focus'       => '#ffd7a6',
            ],
            'static' => [
                'background'   => '#fff7ed',
                'border'       => '#f3d4b2',
                'title'        => '#b45309',
                'text'         => '#4b5563',
                'price'        => '#111111',
                'bullet_color' => '#b45309',
            ],
            'typography' => [
                'base_size'     => 16,
                'title_weight'  => '600',
                'week_bullet'   => 'none',
                'group_bullet'  => 'none',
                'static_bullet' => 'none',
            ],
            'variant' => 'classic',
        ];
    }

    private function get_frontend_theme_settings(): array {
        if (is_array($this->frontend_theme_cache)) {
            return $this->frontend_theme_cache;
        }

        $stored = get_option('hsp_frontend_theme', []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $settings = $this->sanitize_frontend_theme_settings($stored);
        $this->frontend_theme_cache = $settings;

        return $settings;
    }

    /**
     * @param mixed $input
     */
    private function sanitize_frontend_theme_settings($input): array {
        $defaults = $this->get_frontend_theme_defaults();
        $output = $defaults;

        if (is_array($input)) {
            if (isset($input['week']) && is_array($input['week'])) {
                foreach ($defaults['week'] as $key => $fallback) {
                    $value = $input['week'][$key] ?? $fallback;
                    $output['week'][$key] = $this->sanitize_theme_color($value, $fallback);
                }
            }
            if (isset($input['static']) && is_array($input['static'])) {
                foreach ($defaults['static'] as $key => $fallback) {
                    $value = $input['static'][$key] ?? $fallback;
                    $output['static'][$key] = $this->sanitize_theme_color($value, $fallback);
                }
            }
            if (isset($input['typography']) && is_array($input['typography'])) {
                $typo = $input['typography'];
                $size = isset($typo['base_size']) ? intval($typo['base_size']) : $defaults['typography']['base_size'];
                $output['typography']['base_size'] = max(12, min(24, $size));

                $output['typography']['title_weight'] = $this->normalize_title_weight($typo['title_weight'] ?? $defaults['typography']['title_weight']);
                $output['typography']['week_bullet'] = $this->normalize_bullet_style($typo['week_bullet'] ?? $defaults['typography']['week_bullet']);
                $output['typography']['group_bullet'] = $this->normalize_bullet_style($typo['group_bullet'] ?? $defaults['typography']['group_bullet']);
                $output['typography']['static_bullet'] = $this->normalize_bullet_style($typo['static_bullet'] ?? $defaults['typography']['static_bullet']);
            }
            $output['variant'] = $this->normalize_theme_variant($input['variant'] ?? $defaults['variant']);
        }

        return $output;
    }

    private function sanitize_theme_color($value, string $fallback): string {
        $value = is_string($value) ? trim($value) : '';
        $sanitized = $value !== '' ? \sanitize_hex_color($value) : '';
        if (!$sanitized) {
            return $fallback;
        }
        return $sanitized;
    }

    private function normalize_bullet_style(string $value): string {
        $value = strtolower(\sanitize_key($value));
        $allowed = ['none', 'disc', 'dash', 'square', 'arrow'];
        return in_array($value, $allowed, true) ? $value : 'none';
    }

    private function normalize_title_weight(string $value): string {
        $value = trim($value);
        $allowed = ['400', '500', '600', '700'];
        return in_array($value, $allowed, true) ? $value : '600';
    }


    private function normalize_theme_variant(string $value): string {
        $value = strtolower(\sanitize_key($value));
        $allowed = ['classic', 'modern', 'minimal', 'czech'];
        return in_array($value, $allowed, true) ? $value : 'classic';
    }

    private function get_bullet_symbol(string $style): string {
        switch ($style) {
            case 'disc':
                return '\\2022';
            case 'dash':
                return '\\2013';
            case 'square':
                return '\\25AA';
            case 'arrow':
                return '\\203A';
            default:
                return '';
        }
    }

}
