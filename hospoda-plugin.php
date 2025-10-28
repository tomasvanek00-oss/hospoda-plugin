<?php
/**
 * Plugin Name: Hospoda – Jídelní lístek a polední menu (modifikace)
 * Description: Upravená verze, která zobrazuje pouze týdenní menu a skrývá editaci denního menu.
 * Version: 1.0.1
 * Author: Media crew s.r.o. (úpravy OpenAI)
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

namespace HospodaPlugin;

if (!defined('ABSPATH')) exit;

// Konstanty
const VERSION    = '1.0.1';
const CPT_MEAL   = 'meal';        // knihovna jídel
const CPT_DAY    = 'daily_menu';  // polední menu podle data
const TAX_SIDE   = 'meal_side';   // přílohy
const TAX_ALLERGEN = 'meal_allergen';   // alergeny 1–14

class Hospoda_Plugin {
    private $inline_printed = false;
    private $menu_preferences_cache = null;
    private $static_menu_cache = null;
    private $sides_cache = null;
    private $meal_terms_cache = [];
    private $frontend_theme_cache = null;

    /**
     * Returns inline <style> tag for front‑end, printed only once per request.
     * This guarantees styling even if theme (e.g., Divi) suppresses our enqueued CSS.
     */
    private function inline_css_tag(){
        if ($this->inline_printed) return '';
        $this->inline_printed = true;
        $theme = $this->get_frontend_theme_settings();
        $week = $theme['week'];
        $static = $theme['static'];
        $typo = $theme['typography'];

        $base_size = isset($typo['base_size']) ? (int) $typo['base_size'] : 16;
        if ($base_size < 12) {
            $base_size = 12;
        }
        $title_weight = $this->normalize_title_weight((string)($typo['title_weight'] ?? '600'));

        $week_bullet_symbol = $this->get_bullet_symbol($typo['week_bullet'] ?? 'none');
        $week_bullet_display = $week_bullet_symbol !== '' ? 'inline-block' : 'none';
        $week_bullet_offset = $week_bullet_symbol !== '' ? '1.6em' : '0';

        $group_bullet_symbol = $this->get_bullet_symbol($typo['group_bullet'] ?? 'none');
        $group_bullet_display = $group_bullet_symbol !== '' ? 'inline-block' : 'none';
        $group_bullet_offset = $group_bullet_symbol !== '' ? '1.5em' : '0';

        $static_bullet_symbol = $this->get_bullet_symbol($typo['static_bullet'] ?? 'none');
        $static_bullet_display = $static_bullet_symbol !== '' ? 'inline-block' : 'none';
        $static_bullet_offset = $static_bullet_symbol !== '' ? '1.5em' : '0';

        $css = '';
        $css .= '.hsp-root{font-size:'.$base_size.'px;line-height:1.5;color:'.$week['body_text'].';';
        $css .= '--hsp-week-bullet:"'.$week_bullet_symbol.'";--hsp-week-bullet-display:'.$week_bullet_display.';--hsp-week-bullet-offset:'.$week_bullet_offset.';--hsp-week-bullet-color:'.$week['bullet_color'].';';
        $css .= '--hsp-group-bullet:"'.$group_bullet_symbol.'";--hsp-group-bullet-display:'.$group_bullet_display.';--hsp-group-bullet-offset:'.$group_bullet_offset.';--hsp-group-bullet-color:'.$week['group_bullet_color'].';';
        $css .= '--hsp-static-bullet:"'.$static_bullet_symbol.'";--hsp-static-bullet-display:'.$static_bullet_display.';--hsp-static-bullet-offset:'.$static_bullet_offset.';--hsp-static-bullet-color:'.$static['bullet_color'].';}';
        $css .= '.hsp-root .hsp-week{display:grid;gap:24px;--hsp-gap:24px}';
        $css .= '.hsp-root .hsp-collapsed{margin:0 0 16px}';
        $css .= '.hsp-root .hsp-toggle{display:inline-block;padding:10px 16px;border:1px solid '.$week['group_price'].';border-radius:10px;background:'.$week['group_price'].';color:#fff;font-weight:700;letter-spacing:.2px;cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,.06)}';
        $css .= '.hsp-root .hsp-toggle:hover{background:'.$week['group_price'].';border-color:'.$week['group_price'].';opacity:.92}';
        $css .= '.hsp-root .hsp-toggle:focus{outline:2px solid rgba(255,215,166,.8);outline-offset:2px}';
        $css .= '.hsp-root .hsp-toggle[aria-expanded="true"]{background:#444;border-color:#444}';
        $css .= '.hsp-root .hsp-hidden{display:none}';
        $css .= '.hsp-root .hsp-week__nav{display:flex;gap:12px;align-items:center;justify-content:center;margin:0 0 16px}';
        $css .= '.hsp-root .hsp-week__nav .hsp-nav__btn, .hsp-root .hsp-week__nav .hsp-nav__btn[type=button]{display:inline-block;padding:6px 10px;border:1px solid #ddd;border-radius:6px;background:#fff;text-decoration:none;color:'.$week['body_text'].';cursor:pointer}';
        $css .= '.hsp-root .hsp-week__nav .hsp-nav__btn:hover, .hsp-root .hsp-week__nav .hsp-nav__btn[type=button]:hover{background:#fafafa}';
        $css .= '.hsp-root .hsp-week__nav .hsp-nav__label{font-weight:600}';
        $css .= '.hsp-root .hsp-week__nav .hsp-nav__date{padding:6px 8px;border:1px solid #ddd;border-radius:6px}';
        $css .= '@media (min-width:960px){.hsp-root .hsp-week{grid-template-columns:1fr 1fr;justify-items:stretch}}';
        $css .= '@media (min-width:960px){.hsp-root .hsp-week > .hsp-day:last-child:nth-child(odd){grid-column:1/-1;justify-self:center;width:calc((100% - var(--hsp-gap))/2)}}';
        $css .= '.hsp-root .hsp-day{border:1px solid '.$week['card_border'].';border-radius:12px;background:'.$week['card_bg'].';box-shadow:0 1px 2px rgba(0,0,0,.03);overflow:hidden}';
        $css .= '.hsp-root .hsp-day__heading{margin:0;padding:12px 16px;border-bottom:1px solid '.$week['card_border'].';font-weight:700;letter-spacing:.2px;background:'.$week['heading_bg'].';color:'.$week['heading_text'].'}';
        $css .= '.hsp-root .hsp-badge{display:inline-block;margin-left:8px;padding:2px 8px;border-radius:999px;background:'.$week['badge_bg'].';color:'.$week['badge_text'].';font-size:.85em;font-weight:600}';
        $css .= '.hsp-root .hsp-body{padding:0 16px 12px;color:'.$week['body_text'].'}';
        $css .= '.hsp-root .hsp-grid{display:grid;grid-template-columns:1fr auto;grid-template-areas:"title price" "sides price";align-items:baseline;gap:2px 8px;padding:8px 0;border-bottom:1px dashed '.$week['card_border'].'}';
        $css .= '.hsp-root .hsp-grid:last-child{border-bottom:0}';
        $css .= '.hsp-root .hsp-day .hsp-item{list-style:none;padding:0;position:relative;padding-left:var(--hsp-week-bullet-offset)}';
        $css .= '.hsp-root .hsp-day .hsp-item::before{content:var(--hsp-week-bullet);display:var(--hsp-week-bullet-display);position:absolute;left:0;top:1.1em;transform:translateY(-50%);color:var(--hsp-week-bullet-color);font-weight:700;font-size:.9em;line-height:1}';
        $css .= '.hsp-root .hsp-title{grid-area:title;font-weight:'.$title_weight.';color:'.$week['body_text'].'}';
        $css .= '.hsp-root .hsp-sides{grid-area:sides;display:block;color:'.$week['sides_text'].';font-size:.9em;margin:2px 0 0}';
        $css .= '.hsp-root .hsp-price{grid-area:price;justify-self:end;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;color:'.$week['price_text'].'}';
        $css .= '.hsp-root .hsp-menu-groups{display:grid;gap:18px;margin:24px 0 0}';
        $css .= '.hsp-root .hsp-menu-group{border:1px solid '.$week['group_border'].';border-radius:12px;padding:16px 18px;background:'.$week['group_bg'].';box-shadow:0 1px 2px rgba(0,0,0,.04)}';
        $css .= '.hsp-root .hsp-menu-group__title{display:flex;justify-content:space-between;align-items:baseline;font-size:1.05em;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:'.$week['group_title'].';margin:0 0 6px}';
        $css .= '.hsp-root .hsp-menu-group__price{margin-left:12px;font-weight:700;color:'.$week['group_price'].';font-size:.95em}';
        $css .= '.hsp-root .hsp-menu-group__list{list-style:none;margin:0;padding:0;display:grid;gap:6px;color:'.$week['body_text'].'}';
        $css .= '.hsp-root .hsp-menu-group__item{display:flex;flex-direction:column;gap:2px;position:relative;padding-left:var(--hsp-group-bullet-offset)}';
        $css .= '.hsp-root .hsp-menu-group__item::before{content:var(--hsp-group-bullet);display:var(--hsp-group-bullet-display);position:absolute;left:0;top:.7em;transform:translateY(-50%);color:var(--hsp-group-bullet-color);font-weight:700;font-size:.85em;line-height:1}';
        $css .= '.hsp-root .hsp-soup{margin:0}';
        $css .= '.hsp-root .hsp-mains{list-style:none;margin:0;padding:0}';
        $css .= '.hsp-root .hsp-static{margin:28px 0 0;padding:18px 20px;border:1px solid '.$static['border'].';border-radius:12px;background:'.$static['background'].';box-shadow:0 1px 3px rgba(0,0,0,.04);color:'.$static['text'].'}';
        $css .= '.hsp-root .hsp-static__title{margin:0 0 10px;font-size:1.05em;letter-spacing:.08em;text-transform:uppercase;color:'.$static['title'].';font-weight:700}';
        $css .= '.hsp-root .hsp-static__list{list-style:none;margin:0;padding:0;color:'.$static['text'].';font-size:.97em;display:grid;gap:8px}';
        $css .= '.hsp-root .hsp-static__list li{margin:0}';
        $css .= '.hsp-root .hsp-static .hsp-item{position:relative;padding-left:var(--hsp-static-bullet-offset)}';
        $css .= '.hsp-root .hsp-static .hsp-item::before{content:var(--hsp-static-bullet);display:var(--hsp-static-bullet-display);position:absolute;left:0;top:.95em;transform:translateY(-50%);color:var(--hsp-static-bullet-color);font-weight:700;font-size:.85em;line-height:1}';
        $css .= '.hsp-root .hsp-static .hsp-price{color:'.$static['price'].'}';
        return "\n<style id=\"hospoda-frontend-inline\">$css</style>\n";
    }

    public function __construct() {
        add_action('init', [$this,'register_cpt_tax']);
        register_activation_hook(__FILE__, [$this,'activate']);
        // Instead of two separate menus, register only one for weekly menu
        add_action('admin_menu', [$this,'admin_menu_page']);
        add_action('admin_menu', [$this,'adjust_admin_submenus'], 100);
        add_action('admin_enqueue_scripts', [$this,'admin_assets']);
        add_action('wp_ajax_hospoda_meal_search', [$this,'ajax_meal_search']);
        add_action('admin_post_hospoda_save_day', [$this,'handle_save_day']);
        add_action('admin_post_hospoda_save_week', [$this,'handle_save_week']);
        add_action('admin_post_hospoda_save_branding', [$this,'handle_save_branding']);
        add_action('admin_post_hospoda_export_week_pdf', [$this,'handle_export_week_pdf']);
        add_shortcode('poledni_menu', [$this,'shortcode_menu']);
        add_action('add_meta_boxes', [$this,'add_day_metabox']);
        add_filter('manage_'.CPT_DAY.'_posts_columns', [$this,'day_columns']);
        add_action('manage_'.CPT_DAY.'_posts_custom_column', [$this,'day_columns_content'], 10, 2);
        add_action('wp_enqueue_scripts', [$this,'frontend_assets'], 9999);
        add_action('wp_head', [$this,'frontend_inline_probe'], 1000);
        add_action('wp_print_styles', [$this,'frontend_assets'], 9999);
        add_action('wp_ajax_hsp_get_week', [$this,'ajax_get_week']);
        add_action('wp_ajax_nopriv_hsp_get_week', [$this,'ajax_get_week']);
    }

    /**
     * Enqueue frontend CSS
     */
    public function frontend_assets(){
        $theme = $this->get_frontend_theme_settings();
        $week = $theme['week'];
        $typo = $theme['typography'];
        $title_weight = $this->normalize_title_weight((string)($typo['title_weight'] ?? '600'));
        $week_sides = $week['sides_text'];
        $week_price = $week['price_text'];
        $week_body = $week['body_text'];

        $candidates = [
            'assets/css/style.css', // preferred
            'assets/style.css',     // alternative
            'style.css',            // fallback (plugin root)
        ];
        $found = false;
        foreach ($candidates as $rel) {
            $file = \plugin_dir_path(__FILE__) . $rel;
            if (file_exists($file)) {
                $ver = filemtime($file);
                \wp_enqueue_style('hospoda-frontend', \plugins_url($rel, __FILE__), [], $ver);
                // High-specificity safeguards so theme styles (e.g., Divi) don't override our layout
                $override = '.hsp-root .hsp-mains{list-style:none!important;margin:0!important;padding:0!important}'
                          . '.hsp-root .hsp-item{display:grid!important;grid-template-columns:1fr auto!important;align-items:start!important}'
                          . '.hsp-root .hsp-title{font-weight:'.$title_weight.';color:'.$week_body.'}'
                          . '.hsp-root .hsp-sides{color:'.$week_sides.'}'
                          . '.hsp-root .hsp-price{margin-left:1rem;white-space:nowrap;font-variant-numeric:tabular-nums;color:'.$week_price.'}';
                \wp_add_inline_style('hospoda-frontend', $override);
                $found = true;
                break;
            }
        }
        if (!$found) {
            $fallback = '.hsp-week{display:grid;gap:2rem}.hsp-mains{list-style:none;margin:0;padding:0;display:grid;gap:1rem}.hsp-item{display:grid;gap:1rem;grid-template-columns:1fr auto;align-items:start}.hsp-price{white-space:nowrap;font-variant-numeric:tabular-nums;color:'.$week_price.'}.hsp-sides{color:'.$week_sides.'}.hsp-menu-groups{display:grid;gap:18px;margin:24px 0 0}.hsp-menu-group{border:1px solid '.$week['group_border'].';border-radius:12px;padding:16px 18px;background:'.$week['group_bg'].'}.hsp-menu-group__title{display:flex;justify-content:space-between;align-items:baseline;font-size:1.05em;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:'.$week['group_title'].';margin:0 0 6px}.hsp-menu-group__price{margin-left:12px;font-weight:700;color:'.$week['group_price'].';font-size:.95em}.hsp-menu-group__list{list-style:none;margin:0;padding:0;display:grid;gap:6px}.hsp-menu-group__item{display:flex;flex-direction:column;gap:2px}.hsp-static{margin:24px 0 0;padding:18px 20px;border:1px solid '.$theme['static']['border'].';border-radius:12px;background:'.$theme['static']['background'].'}.hsp-static__title{margin:0 0 8px;text-transform:uppercase;letter-spacing:.08em;font-weight:700;color:'.$theme['static']['title'].'}.hsp-static__list{list-style:none;margin:0;padding:0;display:grid;gap:8px;color:'.$theme['static']['text'].'}.hsp-static__list li{margin:0}';
            \wp_register_style('hospoda-frontend', false, [], VERSION);
            \wp_enqueue_style('hospoda-frontend');
            \wp_add_inline_style('hospoda-frontend', $fallback);
            $override = '.hsp-root .hsp-mains{list-style:none!important;margin:0!important;padding:0!important}'
                      . '.hsp-root .hsp-item{display:grid!important;grid-template-columns:1fr auto!important;align-items:start!important}'
                      . '.hsp-root .hsp-title{font-weight:'.$title_weight.';color:'.$week_body.'}'
                      . '.hsp-root .hsp-sides{color:'.$week_sides.'}'
                      . '.hsp-root .hsp-price{margin-left:1rem;white-space:nowrap;font-variant-numeric:tabular-nums;color:'.$week_price.'}';
            \wp_add_inline_style('hospoda-frontend', $override);
        }
    }

    public function frontend_inline_probe(){
        // Vytiskneme drobný korektivní CSS s vysokou prioritou tak, aby přebil Divi
        $theme = $this->get_frontend_theme_settings();
        $week = $theme['week'];
        $static = $theme['static'];
        $typo = $theme['typography'];
        $title_weight = $this->normalize_title_weight((string)($typo['title_weight'] ?? '600'));

        echo "\n<style id=\"hospoda-frontend-probe\">\n".
             ".hsp-week ul.hsp-mains{list-style:none!important;margin:0!important;padding:0!important}\n".
             ".hsp-week .hsp-item{display:grid!important;grid-template-columns:1fr auto!important;align-items:start!important}\n".
             ".hsp-week .hsp-title{font-weight:{$title_weight};color:{$week['body_text']}}\n".
             ".hsp-week .hsp-sides{color:{$week['sides_text']};font-size:.9em;display:block}\n".
             ".hsp-week .hsp-price{margin-left:1rem;white-space:nowrap;font-variant-numeric:tabular-nums;text-align:right;color:{$week['price_text']}}\n".
             ".hsp-root .hsp-static{margin-top:24px;padding:18px 20px;border:1px solid {$static['border']};border-radius:12px;background:{$static['background']}}\n".
             ".hsp-root .hsp-static__title{margin:0 0 8px;text-transform:uppercase;letter-spacing:.08em;font-weight:700;color:{$static['title']}}\n".
             ".hsp-root .hsp-static__list{list-style:none;margin:0;padding:0;display:grid;gap:8px;color:{$static['text']}}\n".
             "</style>\n";
    }

    public function activate() {
        $this->register_cpt_tax();
        flush_rewrite_rules();
        $defaults = ['Brambor','Vařený brambor','Hranolky','Rýže','Bramborová kaše','Knedlík','Šťouchané','Salát'];
        foreach ($defaults as $s) {
            if (!term_exists($s, TAX_SIDE)) wp_insert_term($s, TAX_SIDE);
        }
        // default allergens 1–14
        $allergen_terms = ['1','2','3','4','5','6','7','8','9','10','11','12','13','14'];
        foreach ($allergen_terms as $a) {
            if (!term_exists($a, TAX_ALLERGEN)) wp_insert_term($a, TAX_ALLERGEN);
        }
    }

    public function register_cpt_tax() {
        register_post_type(CPT_MEAL, [
            'labels'=>['name'=>'Jídla','singular_name'=>'Jídlo','menu_name'=>'Jídelní lístek'],
            'public'=>false,
            'show_ui'=>true,
            'show_in_menu'=>false,
            'show_in_rest'=>true,
            'supports'=>['title','editor','thumbnail']
        ]);
        register_taxonomy(TAX_SIDE, [CPT_MEAL], [
            'labels'=>['name'=>'Přílohy','singular_name'=>'Příloha'],
            'hierarchical'=>true,'show_ui'=>true,'show_admin_column'=>true,'show_in_rest'=>true
        ]);
        register_taxonomy(TAX_ALLERGEN, [CPT_MEAL], [
            'labels'=>['name'=>'Alergeny','singular_name'=>'Alergen'],
            'hierarchical'=>true,'show_ui'=>true,'show_admin_column'=>true,'show_in_rest'=>true
        ]);
        // Hide the CPT_DAY UI entirely from the admin menu; we keep the data structure but no UI
        register_post_type(CPT_DAY, [
            'labels'=>['name'=>'Denní menu','singular_name'=>'Menu dne','menu_name'=>'Denní menu (archiv)'],
            'public'=>false,'show_ui'=>false,'show_in_menu'=>false,'supports'=>['title']
        ]);
    }

    /**
     * Register the weekly menu as the only admin page for this plugin.
     */
    public function admin_menu_page() {
        $use_sides = $this->should_manage_sides();
        add_menu_page(
            'Týdenní menu',            // page title
            'Hospoda',                 // menu title
            'edit_posts',              // capability
            'hospoda-week',            // menu slug (points directly to weekly editor)
            [$this,'render_week_admin_page'], // callback
            'dashicons-list-view',    // icon
            6                         // position
        );

        add_submenu_page(
            'hospoda-week',
            'Archiv jídel',
            'Archiv jídel',
            'edit_posts',
            'edit.php?post_type=' . CPT_MEAL
        );

        if ($use_sides) {
            add_submenu_page(
                'hospoda-week',
                'Přílohy',
                'Přílohy',
                'manage_categories',
                'edit-tags.php?taxonomy=' . TAX_SIDE . '&post_type=' . CPT_MEAL
            );
        }

        add_submenu_page(
            'hospoda-week',
            'Alergeny',
            'Alergeny',
            'manage_categories',
            'edit-tags.php?taxonomy=' . TAX_ALLERGEN . '&post_type=' . CPT_MEAL
        );

        add_submenu_page(
            'hospoda-week',
            'Nastavení',
            'Nastavení',
            'edit_posts',
            'hospoda-week-branding',
            [$this,'render_branding_admin_page']
        );
    }

    public function adjust_admin_submenus() {
        global $submenu;
        if (isset($submenu['hospoda-week'][0])) {
            $submenu['hospoda-week'][0][0] = 'Týdenní menu';
        }
    }

    /**
     * Load admin CSS and JS
     */
    public function admin_assets($hook) {
        $is_week_page = ($hook === 'toplevel_page_hospoda-week');
        $is_branding_page = ($hook === 'hospoda-week_page_hospoda-week-branding');
        $needs_autocomplete = (strpos((string)$hook, 'hospoda-week') !== false);

        if (!wp_style_is('hospoda-admin', 'registered')) {
            wp_register_style('hospoda-admin', false, [], VERSION);
        }
        wp_enqueue_style('hospoda-admin');

        if ($is_week_page) {
            $css = <<<'CSS'
.hs-form.hs-week-form{max-width:1200px}
.hs-week-header{display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;margin:20px 0}
.hs-week-header label{display:block;font-weight:600;margin-bottom:4px}
.hs-week-header input[type=date]{min-width:200px}
.hs-week-header .description{margin:0;color:#4b5563;max-width:480px}
.hs-week-groups{margin:20px 0 10px;padding:16px 18px;border:1px solid #d9dde8;border-radius:12px;background:#f8fafc}
.hs-week-groups legend{font-size:16px;font-weight:700;margin:0 0 8px;color:#0f172a}
.hs-week-groups .description{margin:0 0 14px;color:#4b5563}
.hs-week-menu-groups{display:flex;flex-direction:column;gap:12px}
.hs-week-menu-group-config{display:flex;flex-wrap:wrap;gap:12px;padding:12px;border:1px solid #dbe3f4;border-radius:8px;background:#fff}
.hs-week-menu-group-config label{display:flex;flex-direction:column;flex:1 1 220px;font-weight:600;font-size:13px;color:#334155}
.hs-week-menu-group-config label input{margin-top:4px}
.hs-week-menu-group-config .hs-week-menu-group-remove{margin-left:auto}
.hs-week-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;margin-top:10px}
.hs-week-day{border:1px solid #d6d6d6;padding:20px;background:#fff;border-radius:12px;box-shadow:0 2px 4px rgba(15,23,42,.04)}
.hs-week-day legend{margin:-20px -20px 16px;padding:18px 20px;border-bottom:1px solid #d3d9e5;border-radius:12px 12px 0 0;background:linear-gradient(135deg,#eef4ff,#f8fbff);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;box-shadow:inset 0 -1px 0 rgba(15,23,42,.06)}
.hs-week-day .hs-week-day__name{font-size:22px;letter-spacing:.05em;text-transform:uppercase;color:#0f172a;font-weight:800}
.hs-week-day legend .hs-week-day__date{font-size:14px;font-weight:600;color:#334155;text-transform:none;letter-spacing:0}
.hs-week-section{margin-bottom:20px}
.hs-week-section:last-of-type{margin-bottom:0}
.hs-week-section-title{margin:0 0 10px;font-size:14px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:#6b7280}
.hs-week-menu-group{margin-bottom:18px;padding:16px;border:1px solid #d9e4ff;border-radius:10px;background:#f7faff;box-shadow:inset 0 1px 0 rgba(15,23,42,.04)}
.hs-week-menu-group:last-of-type{margin-bottom:0}
.hs-week-day-groups{margin:12px 0 16px;padding:12px 14px;border:1px solid #dbe3f4;border-radius:10px;background:#f8fafc}
.hs-week-day-groups>.button{margin-bottom:8px}
.hs-week-day-groups .description{margin:0 0 10px;color:#475569;font-size:13px}
.hs-week-day-groups-panel{margin-top:6px}
.hs-week-day-groups-list{display:flex;flex-direction:column;gap:12px}
.hs-week-day-group-config{display:flex;flex-wrap:wrap;gap:12px;padding:12px;border:1px solid #dbe3f4;border-radius:8px;background:#fff}
.hs-week-day-group-config label{display:flex;flex-direction:column;flex:1 1 220px;font-weight:600;font-size:13px;color:#334155}
.hs-week-day-group-config label input{margin-top:4px}
.hs-week-day-group-config .hs-week-day-group-remove{margin-left:auto}
.hs-week-menu-group__header{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px}
.hs-week-menu-group__title{font-size:16px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#0f172a}
.hs-week-menu-group__price{font-weight:700;color:#ef6c00;font-size:14px}
.hs-week-menu-group .hs-week-add{margin-top:10px}
.hs-week-day .row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:14px}
.hs-week-day .row:last-child{margin-bottom:0}
.hs-week-day .row input.meal-autocomplete{flex:1 1 240px;min-width:220px}
.hs-week-day .row input.price{width:110px}
.hs-week-day .sides{display:flex;flex-wrap:wrap;gap:8px}
.hs-week-day .sides label{margin:0;padding:4px 10px;border:1px solid #d5d7db;border-radius:4px;background:#f8fafc;font-size:13px}
.hs-week-day .remove-row{margin-left:auto}
.hs-week-add{margin:12px 0 0}
.hs-week-actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:28px}
CSS;
            wp_add_inline_style('hospoda-admin', $css);
        }

        if ($needs_autocomplete) {
            $css2 = '.ui-autocomplete{z-index:100000 !important;background:#fff;border:1px solid #ccd0d4;box-shadow:0 2px 6px rgba(0,0,0,.1)}.ui-autocomplete .ui-menu-item-wrapper{padding:6px 10px}.ui-state-active{background:#f0f6ff}';
            wp_add_inline_style('hospoda-admin', $css2);
            wp_enqueue_script('jquery-ui-autocomplete');
            wp_localize_script('jquery-ui-autocomplete', 'HOSPOS', ['nonce' => wp_create_nonce('hospoda_meal_search')]);
            $js = <<<'JS'
        (function($){
          function attachAutocomplete($ctx){
            $ctx.find('.meal-autocomplete').each(function(){
              var $input = $(this);
              if ($input.data('ui-autocomplete')) return;
              $input.autocomplete({
                source: function(req,res){
                  $.get(ajaxurl, {_ajax_nonce:HOSPOS.nonce, action:'hospoda_meal_search', term:req.term}, function(data){
                    if (Array.isArray(data)) { res(data); }
                    else { res([]); }
                  });
                },
                minLength: 2,
                select: function(e,ui){
                  if (!ui || !ui.item) { return false; }
                  $input.val(ui.item.label);
                  var $row = $input.closest('.row');
                  $row.find('.meal-id').val(ui.item.id || '');
                  if (ui.item.price !== undefined && ui.item.price !== null) {
                    $row.find('.price').val(ui.item.price);
                  }
                  var allergenList = Array.isArray(ui.item.allergens) ? ui.item.allergens : [];
                  $row.find('.meal-allergens').val(allergenList.length ? allergenList.join(',') : '');
                  if ($row.find('.sides').length){
                    $row.find('.sides input[type=checkbox]').prop('checked', false);
                    if (Array.isArray(ui.item.sides)){
                      ui.item.sides.forEach(function(tid){
                        $row.find('.sides input[type=checkbox][value="'+tid+'"]').prop('checked', true);
                      });
                    }
                  }
                  return false;
                }
              });
              $input.on('input', function(){
                if (!$input.val()){
                  var $row = $input.closest('.row');
                  $row.find('.meal-id').val('');
                  $row.find('.meal-allergens').val('');
                }
              });
            });
          }

          $(document).on('click','#add-row', function(e){
            e.preventDefault();
            var $wrap = $('#mains');
            if (!$wrap.length) return;
            var idx = $wrap.find('.row.main').length;
            var $template = $wrap.find('.row.main').first();
            var sidesHtml = $template.length ? ($template.find('.sides').html() || '') : '';
            var tmpl = ''+
              '<div class="row main">\n'+
              '  <input class="meal-autocomplete" name="mains['+idx+'][title]" type="text" placeholder="Název jídla…" value="">\n'+
              '  <input class="meal-id" type="hidden" name="mains['+idx+'][id]" value="">\n'+
              '  <input class="meal-allergens" type="hidden" name="mains['+idx+'][allergens]" value="">\n'+
              '  <input class="price" type="text" name="mains['+idx+'][price]" placeholder="Cena (Kč)" value="">\n'+
              '  <div class="sides">'+sidesHtml+'</div>\n'+
              '  <button type="button" class="button link-button remove-row">Odstranit</button>\n'+
              '</div>';
            var $row = $(tmpl);
            $row.find('.sides input[type=checkbox]').each(function(){
              $(this).prop('checked', false).attr('name','mains['+idx+'][sides][]');
            });
            $wrap.append($row);
            attachAutocomplete($row);
          });

          function getDayFieldset(index){
            return $('.hs-week-day[data-week-index="'+index+'"]').first();
          }

          function syncDayGroupDisplay(dayIndex, key){
            var $day = getDayFieldset(dayIndex);
            if (!$day.length){ return; }
            var $config = $day.find('.hs-week-day-group-config[data-group-key="'+key+'"]').first();
            var label = '';
            var price = '';
            if ($config.length){
              label = $.trim($config.find('.js-day-group-label').val() || '');
              price = $.trim($config.find('.js-day-group-price').val() || '');
            }
            var fallback = String(key).replace(/_/g,' ').toUpperCase();
            if (!label){ label = fallback; }
            var $group = $day.find('.hs-week-menu-group[data-group-key="'+key+'"]').first();
            if (!$group.length){ return; }
            $group.find('.hs-week-menu-group__title').text(label);
            var $header = $group.find('.hs-week-menu-group__header');
            var $priceEl = $group.find('.hs-week-menu-group__price');
            if (price){
              if (!$priceEl.length){
                $priceEl = $('<span class="hs-week-menu-group__price"></span>').appendTo($header);
              }
              $priceEl.text(price);
            } else {
              $priceEl.remove();
            }
          }

          function appendGroupToDay(dayIndex, group){
            var key = String(group.key || '');
            if (!key){
              key = 'menu_' + Date.now();
            }
            var label = group.label || '';
            var price = group.price || '';
            var $day = getDayFieldset(dayIndex);
            if (!$day.length){ return key; }
            var $tpl = $day.find('.hs-week-group-template[data-week-index="'+dayIndex+'"]').first();
            if (!$tpl.length){ return key; }
            var tpl = $tpl.html();
            if (!tpl){ return key; }
            var html = tpl.replace(/__GROUP_KEY__/g, key)
                          .replace(/__GROUP_LABEL__/g, label || '')
                          .replace(/__GROUP_PRICE__/g, price || '');
            var $block = $(html);
            if (!price){
              $block.find('.hs-week-menu-group__price').remove();
            }
            var $section = $day.find('.hs-week-section--mains');
            var $templateNode = $section.find('.hs-week-group-template').first();
            if ($templateNode.length){
              $templateNode.before($block);
            } else {
              $section.append($block);
            }
            attachAutocomplete($block);
            syncDayGroupDisplay(dayIndex, key);
            return key;
          }

          $(document).on('click','.add-row-week', function(e){
            e.preventDefault();
            var idx = $(this).data('week-index');
            if (typeof idx === 'undefined'){
              idx = $(this).closest('fieldset').data('week-index');
            }
            var groupKey = $(this).data('groupKey') || '';
            var $wrap;
            if (groupKey){
              var $group = $(this).closest('.hs-week-menu-group');
              $wrap = $group.find('.hs-mains').first();
              if (!$wrap.length){
                $wrap = $(this).closest('.hs-week-day').find('.hs-mains[data-group-key="'+groupKey+'"]').first();
              }
            } else {
              $wrap = $(this).closest('fieldset').find('.hs-mains').first();
            }
            if (!$wrap || !$wrap.length){ return; }
            var includePrice = String($wrap.data('includePrice')) !== '0';
            var hasSides = String($wrap.data('hasSides')) === '1';
            var nextSub = parseInt($wrap.data('nextSubindex'), 10);
            if (isNaN(nextSub)) {
              nextSub = $wrap.find('.row.main').length;
            }
            var rowKey;
            if (groupKey){
              rowKey = groupKey + '_' + nextSub;
              $wrap.data('nextSubindex', nextSub + 1);
            } else {
              rowKey = $wrap.find('.row.main').length;
            }
            var $rows = $wrap.find('.row.main');
            var sidesHtml = '';
            if ($rows.length && $rows.first().find('.sides').length){
              sidesHtml = $rows.first().find('.sides').html() || '';
            }
            var priceField = includePrice ? '  <input class="price" type="text" name="week[mains]['+idx+']['+rowKey+'][price]" placeholder="Cena (Kč)" value="">\n' : '';
            var groupInput = groupKey ? '  <input class="menu-group-key" type="hidden" name="week[mains]['+idx+']['+rowKey+'][menu_group]" value="'+groupKey+'">\n' : '';
            var sidesSection = '';
            if (hasSides && sidesHtml){
              sidesSection = '  <div class="sides">'+sidesHtml+'</div>\n';
            }
            var attrs = groupKey ? ' data-group-key="'+groupKey+'" data-subindex="'+rowKey+'"' : ' data-index="'+rowKey+'"';
            var tmpl = ''+
              '<div class="row main"'+attrs+'>\n'+
              '  <input class="meal-autocomplete" name="week[mains]['+idx+']['+rowKey+'][title]" type="text" placeholder="Název jídla…" value="">\n'+
              '  <input class="meal-id" type="hidden" name="week[mains]['+idx+']['+rowKey+'][id]" value="">\n'+
              '  <input class="meal-allergens" type="hidden" name="week[mains]['+idx+']['+rowKey+'][allergens]" value="">\n'+
              groupInput+
              priceField+
              sidesSection+
              '  <button type="button" class="button link-button remove-row" data-week-index="'+idx+'"'+(groupKey?' data-group-key="'+groupKey+'"':'')+'>Odstranit</button>\n'+
              '</div>';
            var $row = $(tmpl);
            $row.find('.sides input[type=checkbox]').each(function(){
              $(this).prop('checked', false).attr('name','week[mains]['+idx+']['+rowKey+'][sides][]');
            });
            $wrap.append($row);
            attachAutocomplete($row);
          });

          $(document).on('click','#hs-static-add', function(e){
            e.preventDefault();
            var $wrap = $('#hs-static-menu');
            if (!$wrap.length){ return; }
            var idx = $wrap.find('.row.main').length;
            var $rows = $wrap.find('.row.main');
            var hasSides = String($wrap.data('hasSides')) === '1';
            var sidesHtml = '';
            if (hasSides && $rows.length && $rows.first().find('.sides').length){
              sidesHtml = $rows.first().find('.sides').html() || '';
            }
            var sidesSection = hasSides && sidesHtml ? '  <div class="sides">'+sidesHtml+'</div>\n' : '';
            var tmpl = ''+
              '<div class="row main" data-static-index="'+idx+'">\n'+
              '  <input class="meal-autocomplete" name="static_menu['+idx+'][title]" type="text" placeholder="Název jídla…" value="">\n'+
              '  <input class="meal-id" type="hidden" name="static_menu['+idx+'][id]" value="">\n'+
              '  <input class="meal-allergens" type="hidden" name="static_menu['+idx+'][allergens]" value="">\n'+
              '  <input class="price" type="text" name="static_menu['+idx+'][price]" placeholder="Cena (Kč)" value="">\n'+
              sidesSection+
              '  <button type="button" class="button link-button remove-row" data-static-index="'+idx+'">Odstranit</button>\n'+
              '</div>';
            var $row = $(tmpl);
            $row.find('.sides input[type=checkbox]').each(function(){
              $(this).prop('checked', false).attr('name','static_menu['+idx+'][sides][]');
            });
            $wrap.append($row);
            attachAutocomplete($row);
          });

          $(document).on('click','.remove-row', function(){
            var $container = $(this).closest('.hs-mains');
            var $rows = $container.find('.row.main');
            if ($rows.length > 1) {
              $(this).closest('.row.main').remove();
            }
          });

          $(document).on('click','.hs-week-day-groups-toggle', function(e){
            e.preventDefault();
            var $btn = $(this);
            var dayIndex = $btn.data('dayIndex');
            var $day = getDayFieldset(dayIndex);
            if (!$day.length){ return; }
            var $panel = $day.find('.hs-week-day-groups-panel').first();
            var expanded = $btn.attr('aria-expanded') === 'true';
            if (expanded){
              $btn.attr('aria-expanded','false');
              $panel.attr('hidden','hidden');
            } else {
              $btn.attr('aria-expanded','true');
              $panel.removeAttr('hidden');
            }
          });

          $(document).on('click','.hs-week-day-groups-add', function(e){
            e.preventDefault();
            var dayIndex = $(this).data('dayIndex');
            var $day = getDayFieldset(dayIndex);
            if (!$day.length){ return; }
            var $list = $day.find('.hs-week-day-groups-list');
            if (!$list.length){ return; }
            var next = parseInt($list.data('nextIndex'), 10);
            if (isNaN(next)) {
              next = $list.find('.hs-week-day-group-config').length;
            }
            var key = 'menu_' + Date.now();
            var labelPlaceholder = 'MENU ' + (next + 1);
            var configHtml = ''+
              '<div class="hs-week-day-group-config" data-group-key="'+key+'" data-index="'+next+'">\n'+
              '  <input type="hidden" name="week[menu_groups]['+dayIndex+']['+next+'][key]" value="'+key+'">\n'+
              '  <label>Název menu\n    <input type="text" class="regular-text js-day-group-label" name="week[menu_groups]['+dayIndex+']['+next+'][label]" value="" placeholder="'+labelPlaceholder+'">\n  </label>\n'+
              '  <label>Cena / popisek\n    <input type="text" class="regular-text js-day-group-price" name="week[menu_groups]['+dayIndex+']['+next+'][price]" value="" placeholder="Např. 139 Kč">\n  </label>\n'+
              '  <button type="button" class="button link-button hs-week-day-group-remove">Odstranit</button>\n'+
              '</div>';
            var $config = $(configHtml);
            $list.append($config);
            $list.data('nextIndex', next + 1);
            appendGroupToDay(dayIndex, {key:key,label:'',price:''});
            $config.find('.js-day-group-label').focus();
          });

          $(document).on('click','#hs-menu-groups-add', function(e){
            e.preventDefault();
            var $wrap = $('#hs-menu-groups');
            if (!$wrap.length){ return; }
            var next = parseInt($wrap.data('nextIndex'), 10);
            if (isNaN(next)) {
              next = $wrap.find('.hs-menu-group').length;
            }
            var key = 'menu_' + Date.now();
            var tmpl = ''+
              '<div class="hs-menu-group" data-index="'+next+'">\n'+
              '  <input type="hidden" name="menu_groups['+next+'][key]" value="'+key+'">\n'+
              '  <label>Název menu\n    <input type="text" name="menu_groups['+next+'][label]" value="">\n  </label>\n'+
              '  <label>Cena / popisek\n    <input type="text" name="menu_groups['+next+'][price]" value="">\n  </label>\n'+
              '  <button type="button" class="button link-button hs-menu-group-remove">Odstranit</button>\n'+
              '</div>';
            var $row = $(tmpl);
            $wrap.append($row);
            $wrap.data('nextIndex', next + 1);
          });

          $(document).on('click','.hs-menu-group-remove', function(e){
            e.preventDefault();
            var $wrap = $('#hs-menu-groups');
            var $rows = $wrap.find('.hs-menu-group');
            if ($rows.length <= 1){ return; }
            $(this).closest('.hs-menu-group').remove();
          });

          $(document).on('click','.hs-week-day-group-remove', function(e){
            e.preventDefault();
            var $config = $(this).closest('.hs-week-day-group-config');
            if (!$config.length){ return; }
            var $day = $config.closest('.hs-week-day');
            var $list = $config.closest('.hs-week-day-groups-list');
            if ($list.find('.hs-week-day-group-config').length <= 1){ return; }
            var key = String($config.data('groupKey') || '');
            $config.remove();
            if (key && $day.length){
              $day.find('.hs-week-menu-group[data-group-key="'+key+'"]').remove();
            }
          });

          $(document).on('input change','.js-day-group-label, .js-day-group-price', function(){
            var $config = $(this).closest('.hs-week-day-group-config');
            var $day = $config.closest('.hs-week-day');
            var dayIndex = $day.data('weekIndex');
            var key = String($config.data('groupKey') || '');
            if (typeof dayIndex === 'undefined' || !key){ return; }
            syncDayGroupDisplay(dayIndex, key);
          });

          function toggleMenuGroupFields(){
            var mode = $('input[name="pricing_mode"]:checked').val();
            $('.hs-branding__menu-groups').toggleClass('is-hidden', mode !== 'menu_groups');
          }

          $(document).on('change','input[name="pricing_mode"]', toggleMenuGroupFields);

          $(document).on('change','#hs-week-start', function(){
            var d = $(this).val();
            if(!d) return;
            var url = new URL(window.location.href);
            url.searchParams.set('week', d);
            window.location.href = url.toString();
          });

          $(function(){
            attachAutocomplete($(document));
            toggleMenuGroupFields();
            $('.hs-week-day').each(function(){
              var $day = $(this);
              var dayIndex = $day.data('weekIndex');
              if (typeof dayIndex === 'undefined'){ return; }
              $day.find('.hs-week-day-group-config').each(function(){
                var key = String($(this).data('groupKey') || '');
                if (key){ syncDayGroupDisplay(dayIndex, key); }
              });
            });
          });
        })(jQuery);
JS;
            wp_add_inline_script('jquery-ui-autocomplete', $js);
        }

        if ($is_branding_page) {
            \wp_enqueue_style('wp-color-picker');
            \wp_enqueue_script('wp-color-picker');
            \wp_add_inline_script('wp-color-picker', 'jQuery(function($){$(".hs-color-field").wpColorPicker();});');
            $css3 = '.hs-branding{margin:20px 0;padding:20px;border:1px solid #d0d0d0;border-radius:6px;background:#fff;max-width:960px}.hs-branding h2{margin-top:0}.hs-branding__logo{display:flex;align-items:flex-start;gap:12px;margin-bottom:12px}.hs-branding__preview{width:160px;min-height:120px;border:1px dashed #ccd0d4;border-radius:4px;display:flex;align-items:center;justify-content:center;background:#fafafa;overflow:hidden}.hs-branding__preview img{max-width:100%;height:auto;display:block}.hs-branding__preview span{color:#777;font-style:italic}.hs-branding textarea{max-width:100%}.hs-branding .description{margin-top:4px;color:#555}.hs-branding__controls{display:flex;flex-direction:column;gap:8px}.hs-branding__static{margin-top:24px;padding-top:16px;border-top:1px solid #d8d8d8}.hs-branding__static h2{margin:0 0 6px;font-size:18px}.hs-branding__menu-groups{margin-top:20px;padding:16px;border:1px solid #d9dde8;border-radius:8px;background:#f8fafc}.hs-branding__menu-groups.is-hidden{display:none}.hs-menu-groups{display:flex;flex-direction:column;gap:12px;margin-top:12px}.hs-menu-group{display:flex;flex-wrap:wrap;gap:12px;padding:12px;border:1px solid #e5e7eb;border-radius:6px;background:#fff}.hs-menu-group label{display:flex;flex-direction:column;flex:1 1 220px;font-weight:600;font-size:13px;color:#334155}.hs-menu-group label input[type=text]{margin-top:4px}.hs-menu-group-remove{margin-left:auto}.hs-menu-groups__actions{margin-top:10px}.hs-static-menu{display:flex;flex-direction:column;gap:14px;margin-top:12px}.hs-static-menu .row{display:flex;flex-wrap:wrap;gap:12px;padding:14px;border:1px solid #e5e7eb;border-radius:8px;background:#fafafa}.hs-static-menu .row input.meal-autocomplete{flex:1 1 260px;min-width:220px}.hs-static-menu .row input.price{width:110px}.hs-static-menu .sides{display:flex;flex-wrap:wrap;gap:8px}.hs-static-menu .sides label{margin:0;padding:4px 10px;border:1px solid #d5d7db;border-radius:4px;background:#fff;font-size:13px}.hs-static-menu .remove-row{margin-left:auto}.hs-static-actions{margin-top:12px}';
            $css3 .= '.hs-branding__theme{margin-top:24px;padding-top:20px;border-top:1px solid #d8d8d8;display:flex;flex-direction:column;gap:20px}.hs-branding__theme-grid{display:grid;gap:18px}.hs-branding__theme-group{border:1px solid #e2e8f0;border-radius:8px;padding:16px 18px;background:#f9fafb;display:flex;flex-direction:column;gap:12px}.hs-branding__theme-group h3{margin:0;font-size:16px;color:#0f172a}.hs-branding__field{display:flex;flex-direction:column;gap:4px}.hs-branding__field label{font-weight:600;font-size:13px;color:#334155}.hs-branding__field input[type=text]{max-width:170px}.hs-branding__field input[type=number]{max-width:120px}.hs-branding__field select{max-width:200px}.hs-branding__field .description{margin:0;font-size:12px;color:#64748b}';
            $css3 .= '@media(min-width:768px){.hs-branding__theme-grid{grid-template-columns:repeat(auto-fit,minmax(260px,1fr))}}';
            wp_add_inline_style('hospoda-admin', $css3);
        }
    }

    /**
     * @return array{terms:array<int,\WP_Term|array>,map:array<int,string>}
     */
    private function get_sides_data(): array {
        if (is_array($this->sides_cache)) {
            return $this->sides_cache;
        }

        if (!$this->should_manage_sides()) {
            $data = ['terms' => [], 'map' => []];
            $this->sides_cache = $data;
            return $data;
        }

        $terms = get_terms(['taxonomy' => TAX_SIDE, 'hide_empty' => false]);
        if (\is_wp_error($terms)) {
            $terms = [];
        }

        $map = [];
        foreach ($terms as $term) {
            $term_id = is_object($term) ? (int)$term->term_id : (int)($term['term_id'] ?? 0);
            $term_name = is_object($term) ? (string)$term->name : (string)($term['name'] ?? '');
            if ($term_id && $term_name !== '') {
                $map[$term_id] = $term_name;
            }
        }

        $this->sides_cache = ['terms' => $terms, 'map' => $map];

        return $this->sides_cache;
    }

    private function get_pdf_branding_defaults(): array {
        return [
            'logo_id'     => 0,
            'top_text'    => "HOSPODA POD KOSTELEM\nJarošov nad Nežárkou\nDenní nabídka\nK hlavnímu jídlu polévka za 20 Kč · Kola 0,3 l k menu za 15 Kč\nVaříme PO–PÁ od 10:30 do 14:00. Objednávky přijímáme den předem do 16:00 na telefonu hospody nebo osobně u obsluhy.",
            'bottom_text' => "Seznam alergenů je k nahlédnutí u obsluhy. Pro více informací se ptejte personálu.\nV nabídce mohou nastat drobné změny podle dostupnosti surovin. Děkujeme za pochopení.",
            'static_menu_items' => [],
        ];
    }

    public function get_pdf_branding_settings(): array {
        $stored = get_option('hsp_pdf_branding', []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $defaults = $this->get_pdf_branding_defaults();
        $output = $defaults;

        $output['_top_custom'] = array_key_exists('top_text', $stored);
        $output['_bottom_custom'] = array_key_exists('bottom_text', $stored);

        if (isset($stored['logo_id'])) {
            $output['logo_id'] = (int)$stored['logo_id'];
        }
        if ($output['_top_custom']) {
            $output['top_text'] = $this->sanitize_multiline_text((string)$stored['top_text']);
        }
        if ($output['_bottom_custom']) {
            $output['bottom_text'] = $this->sanitize_multiline_text((string)$stored['bottom_text']);
        }

        $output['static_menu_items'] = [];
        if (isset($stored['static_menu_items'])) {
            $output['static_menu_items'] = $this->sanitize_static_menu_items($stored['static_menu_items']);
        } elseif (isset($stored['static_menu'])) {
            $output['static_menu_items'] = $this->convert_legacy_static_menu((string)$stored['static_menu']);
        }

        return $output;
    }

    private function get_static_menu_items(): array {
        if (is_array($this->static_menu_cache)) {
            return $this->static_menu_cache;
        }

        $branding = $this->get_pdf_branding_settings();
        $items = $branding['static_menu_items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }

        $items = $this->sanitize_static_menu_items($items);

        $this->static_menu_cache = $items;

        return $items;
    }

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

    private function get_menu_preferences(): array {
        if (is_array($this->menu_preferences_cache)) {
            return $this->menu_preferences_cache;
        }

        $stored = get_option('hsp_menu_preferences', []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $defaults = [
            'soup_price_mode' => 'included',
            'sides_mode'      => 'taxonomy',
            'pricing_mode'    => 'per_item',
            'menu_groups'     => [],
        ];
        $prefs = wp_parse_args($stored, $defaults);
        $prefs['soup_price_mode'] = $this->normalize_soup_price_mode($prefs['soup_price_mode'] ?? '');
        $prefs['sides_mode'] = $this->normalize_sides_mode((string)($prefs['sides_mode'] ?? ''));
        $prefs['pricing_mode'] = $this->normalize_pricing_mode((string)($prefs['pricing_mode'] ?? ''));
        $prefs['menu_groups'] = $this->sanitize_menu_groups($prefs['menu_groups'] ?? []);

        $this->menu_preferences_cache = $prefs;

        return $prefs;
    }

    private function normalize_soup_price_mode(string $value): string {
        return in_array($value, ['included', 'separate'], true) ? $value : 'included';
    }

    private function normalize_sides_mode(string $value): string {
        return in_array($value, ['taxonomy', 'disabled'], true) ? $value : 'taxonomy';
    }

    private function normalize_pricing_mode(string $value): string {
        return in_array($value, ['per_item', 'menu_groups'], true) ? $value : 'per_item';
    }

    private function should_show_soup_price(): bool {
        $prefs = $this->get_menu_preferences();
        return ($prefs['soup_price_mode'] ?? 'included') === 'separate';
    }

    private function sanitize_multiline_text(string $value): string {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $lines = array_map('trim', explode("\n", $value));
        $lines = array_filter($lines, static function ($line) {
            return $line !== '';
        });
        return implode("\n", $lines);
    }

    /**
     * @param mixed $value
     * @return array<int,array{id:int,title:string,price:string,sides:array<int,int>,allergens:array<int,int>}>
     */
    private function sanitize_static_menu_items($value): array {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = isset($row['id']) ? intval($row['id']) : 0;
            $title = isset($row['title']) ? sanitize_text_field($row['title']) : '';
            $price = isset($row['price']) ? sanitize_text_field($row['price']) : '';

            $sides = [];
            if (!empty($row['sides']) && is_array($row['sides'])) {
                foreach ($row['sides'] as $side) {
                    $side_id = (int)$side;
                    if ($side_id > 0) {
                        $sides[] = $side_id;
                    }
                }
            }
            $sides = array_values(array_unique($sides));

            $allergens = $this->sanitize_allergen_list($row['allergens'] ?? []);

            if ($title === '' && $id <= 0) {
                continue;
            }

            $item = [
                'id' => $id,
                'title' => $title,
                'price' => $price,
                'sides' => $sides,
                'allergens' => $allergens,
            ];

            $items[] = $this->apply_meal_defaults_to_item($item);
        }

        return array_values($items);
    }

    /**
     * @param array<int,mixed> $rows
     * @return array<int,array{id:int,title:string,price:string,sides:array<int,int>,allergens:array<int,int>}>
     */
    private function prepare_static_menu_submission(array $rows): array {
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $title_raw = isset($row['title']) ? sanitize_text_field(wp_unslash($row['title'])) : '';
            $id = isset($row['id']) ? intval($row['id']) : 0;
            if ($id <= 0 && $title_raw !== '') {
                $id = $this->ensure_meal_exists($title_raw);
            }
            $title = $this->meal_title_by_id($id, $title_raw);

            if ($title === '' && $id <= 0) {
                continue;
            }

            $price = isset($row['price']) ? sanitize_text_field(wp_unslash($row['price'])) : '';

            $sides = [];
            if (isset($row['sides']) && is_array($row['sides'])) {
                foreach ($row['sides'] as $side) {
                    $side_id = (int)$side;
                    if ($side_id > 0) {
                        $sides[] = $side_id;
                    }
                }
            }
            $sides = array_values(array_unique($sides));

            $allergens = $this->sanitize_allergen_list($row['allergens'] ?? []);

            $item = [
                'id'        => $id,
                'title'     => $title,
                'price'     => $price,
                'sides'     => $sides,
                'allergens' => $allergens,
            ];

            $items[] = $this->apply_meal_defaults_to_item($item);
        }

        return array_values($items);
    }

    /**
     * @param mixed $rows
     * @return array<int,array{key:string,label:string,price:string}>
     */
    private function prepare_menu_groups_submission($rows): array {
        if (!is_array($rows)) {
            return [];
        }

        $prepared = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $prepared[] = [
                'key'   => isset($row['key']) ? sanitize_key(wp_unslash($row['key'])) : '',
                'label' => isset($row['label']) ? sanitize_text_field(wp_unslash($row['label'])) : '',
                'price' => isset($row['price']) ? sanitize_text_field(wp_unslash($row['price'])) : '',
            ];
        }

        return $this->sanitize_menu_groups($prepared);
    }

    private function apply_meal_defaults_to_item(array $item): array {
        $id = isset($item['id']) ? (int)$item['id'] : 0;
        $title = isset($item['title']) ? (string)$item['title'] : '';
        $use_sides = $this->should_manage_sides();

        if ($id > 0) {
            $resolvedTitle = $this->meal_title_by_id($id, $title);
            if ($resolvedTitle !== '') {
                $item['title'] = $resolvedTitle;
            }

            if ($use_sides && empty($item['sides'])) {
                $item['sides'] = $this->get_meal_term_ids($id, TAX_SIDE);
            }

            if (empty($item['allergens'])) {
                $item['allergens'] = $this->get_meal_term_ids($id, TAX_ALLERGEN);
            }

            if (empty($item['price'])) {
                $meta_price = get_post_meta($id, 'price', true);
                if (is_string($meta_price) && $meta_price !== '') {
                    $item['price'] = sanitize_text_field($meta_price);
                }
            }
        }

        $item['sides'] = array_values(array_unique(array_map('intval', $item['sides'] ?? [])));
        $item['allergens'] = array_values(array_unique(array_map('intval', $item['allergens'] ?? [])));

        return $item;
    }

    private function convert_legacy_static_menu(string $value): array {
        $value = $this->sanitize_multiline_text($value);
        if ($value === '') {
            return [];
        }

        $lines = explode("\n", $value);
        $items = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $items[] = [
                'id' => 0,
                'title' => $line,
                'price' => '',
                'sides' => [],
                'allergens' => [],
            ];
        }

        return $items;
    }

    private function get_meal_term_ids(int $meal_id, string $taxonomy): array {
        if ($meal_id <= 0) {
            return [];
        }

        $cache_key = $taxonomy . ':' . $meal_id;
        if (isset($this->meal_terms_cache[$cache_key])) {
            return $this->meal_terms_cache[$cache_key];
        }

        $terms = wp_get_object_terms($meal_id, $taxonomy, ['fields' => 'ids']);
        if (is_wp_error($terms) || !is_array($terms)) {
            $this->meal_terms_cache[$cache_key] = [];
            return [];
        }

        $ids = array_values(array_unique(array_map('intval', $terms)));
        $this->meal_terms_cache[$cache_key] = $ids;

        return $ids;
    }

    /**
     * @param mixed $value
     * @return array<int,array{key:string,label:string,price:string}>
     */
    private function sanitize_menu_groups($value): array {
        if (!is_array($value)) {
            return [];
        }

        $groups = [];
        $used = [];
        $index = 1;
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = isset($row['label']) ? sanitize_text_field($row['label']) : '';
            if ($label === '') {
                continue;
            }

            $price = isset($row['price']) ? sanitize_text_field($row['price']) : '';
            $key = isset($row['key']) ? sanitize_key($row['key']) : '';
            if ($key === '') {
                $key = sanitize_key(remove_accents($label));
            }
            if ($key === '') {
                $key = 'menu_' . $index;
            }

            $base = $key;
            $suffix = 2;
            while (in_array($key, $used, true)) {
                $key = $base . '_' . $suffix;
                $suffix++;
            }
            $used[] = $key;

            $groups[] = [
                'key'   => $key,
                'label' => $label,
                'price' => $price,
            ];

            $index++;
            if (count($groups) >= 12) {
                break;
            }
        }

        return array_values($groups);
    }

    private function resolve_monday_for_date(string $date): ?string {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        $ts = strtotime($date);
        if ($ts === false) {
            return null;
        }

        $dow = (int) wp_date('N', $ts);
        $offset = $dow > 1 ? $dow - 1 : 0;
        $monday_ts = $offset ? strtotime('-' . $offset . ' days', $ts) : $ts;
        if ($monday_ts === false) {
            $monday_ts = $ts;
        }

        return wp_date('Y-m-d', $monday_ts);
    }

    /**
     * @param mixed $value
     * @return array<int,int>
     */
    private function sanitize_allergen_list($value): array {
        if (is_string($value)) {
            $value = preg_split('/[,\s]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }

        $clean = [];
        foreach ($value as $item) {
            $code = (int)$item;
            if ($code > 0) {
                $clean[] = $code;
            }
        }

        $clean = array_values(array_unique($clean));
        sort($clean, SORT_NUMERIC);

        return $clean;
    }

    private function should_manage_sides(): bool {
        $prefs = $this->get_menu_preferences();
        return ($prefs['sides_mode'] ?? 'taxonomy') === 'taxonomy';
    }

    private function get_pricing_mode(): string {
        $prefs = $this->get_menu_preferences();
        return $prefs['pricing_mode'] ?? 'per_item';
    }

    private function should_use_menu_groups(): bool {
        return $this->get_pricing_mode() === 'menu_groups' && !empty($this->get_menu_groups_setting());
    }

    /**
     * @return array<int,array{key:string,label:string,price:string}>
     */
    private function get_menu_groups_setting(): array {
        $prefs = $this->get_menu_preferences();
        $groups = $prefs['menu_groups'] ?? [];
        return $this->sanitize_menu_groups($groups);
    }

    /**
     * @return array<int,array{key:string,label:string,price:string}>
     */
    private function get_day_menu_groups_meta(int $post_id): array {
        $raw = get_post_meta($post_id, 'menu_groups', true);
        return $this->sanitize_menu_groups($raw);
    }

    /**
     * @return array<int,array{key:string,label:string,price:string}>
     */
    private function resolve_day_menu_groups(int $post_id): array {
        if (!$this->should_use_menu_groups()) {
            return [];
        }

        $stored = $this->get_day_menu_groups_meta($post_id);
        if (!empty($stored)) {
            return $stored;
        }

        return $this->get_menu_groups_setting();
    }

    /**
     * @param array<int,mixed> $mains
     * @param array<int,array{key:string,label:string,price:string}> $groups
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function group_mains_by_menu(array $mains, array $groups): array {
        $keys = [];
        foreach ($groups as $group) {
            $key = isset($group['key']) ? (string)$group['key'] : '';
            if ($key !== '') {
                $keys[] = $key;
            }
        }
        $keys = array_values(array_unique($keys));
        if (empty($keys)) {
            return ['' => array_values(array_filter($mains, 'is_array'))];
        }

        $default = $keys[0];
        $buckets = [];
        foreach ($keys as $key) {
            $buckets[$key] = [];
        }

        foreach ($mains as $row) {
            if (!is_array($row)) {
                continue;
            }
            $groupKey = isset($row['menu_group']) ? sanitize_key((string)$row['menu_group']) : '';
            if ($groupKey === '' || !in_array($groupKey, $keys, true)) {
                $groupKey = $default;
            }
            $row['menu_group'] = $groupKey;
            $buckets[$groupKey][] = $row;
        }

        return $buckets;
    }

    /**
     * @param mixed $value
     */
    private function format_allergens_field($value): string {
        $list = $this->sanitize_allergen_list($value);
        return empty($list) ? '' : implode(',', $list);
    }

    private function resolve_branding_logo_path(int $attachment_id): string {
        if ($attachment_id <= 0) {
            return '';
        }
        $path = get_attached_file($attachment_id);
        if (!$path || !is_string($path)) {
            return '';
        }
        return file_exists($path) ? $path : '';
    }

    private function ensure_meal_exists($title){
        $title = trim((string)$title);
        if ($title==='') return 0;
        $exists = get_page_by_title($title, OBJECT, CPT_MEAL);
        if ($exists) return (int)$exists->ID;
        return (int) wp_insert_post([
            'post_type'=>CPT_MEAL,
            'post_status'=>'publish',
            'post_title'=>$title,
        ]);
    }

    /**
     * Najde poslední použitou cenu a přílohy pro dané jídlo (podle ID nebo názvu)
     * v uloženém denním menu (za posledních ~180 dní).
     */
    private function last_usage_data($meal_id = 0, $title = ''){
        $title = trim((string)$title);
        $result = ['price' => '', 'sides' => [], 'allergens' => []];

        if ($meal_id > 0) {
            $result['sides'] = $this->get_meal_term_ids($meal_id, TAX_SIDE);
            $result['allergens'] = $this->get_meal_term_ids($meal_id, TAX_ALLERGEN);

            $meta_price = get_post_meta($meal_id, 'price', true);
            if (is_string($meta_price) && $meta_price !== '') {
                $result['price'] = sanitize_text_field($meta_price);
            }
        }

        $since = date('Y-m-d', strtotime('-180 days'));
        $days = get_posts([
            'post_type'      => CPT_DAY,
            'posts_per_page' => 300,
            'meta_query'     => [
                ['key' => 'menu_date', 'value' => $since, 'compare' => '>='],
            ],
            'orderby'        => 'meta_value',
            'meta_key'       => 'menu_date',
            'order'          => 'DESC',
        ]);
        foreach ($days as $p) {
            $mains = get_post_meta($p->ID, 'mains', true);
            if (is_array($mains)) {
                foreach ($mains as $row) {
                    $match = false;
                    if ($meal_id && !empty($row['id']) && intval($row['id']) === intval($meal_id)) {
                        $match = true;
                    } elseif ($title !== '' && !empty($row['title']) && strcasecmp($row['title'], $title) === 0) {
                        $match = true;
                    }
                    if ($match) {
                        if (!empty($row['price'])) {
                            $result['price'] = sanitize_text_field($row['price']);
                        }
                        $row_sides = array_map('intval', $row['sides'] ?? []);
                        if (!empty($row_sides)) {
                            $result['sides'] = array_values(array_unique($row_sides));
                        }
                        $row_allergens = array_map('intval', $row['allergens'] ?? []);
                        if (!empty($row_allergens)) {
                            $result['allergens'] = array_values(array_unique($row_allergens));
                        }
                        return $result; // první (nejnovější)
                    }
                }
            }
            $soup = get_post_meta($p->ID, 'soup', true);
            if (!empty($soup)) {
                $match = false;
                if ($meal_id && !empty($soup['id']) && intval($soup['id']) === intval($meal_id)) {
                    $match = true;
                } elseif ($title !== '' && !empty($soup['title']) && strcasecmp($soup['title'], $title) === 0) {
                    $match = true;
                }
                if ($match) {
                    if (!empty($soup['price'])) {
                        $result['price'] = sanitize_text_field($soup['price']);
                    }
                    $result['sides'] = []; // polévky obvykle bez příloh
                    $row_allergens = array_map('intval', $soup['allergens'] ?? []);
                    if (!empty($row_allergens)) {
                        $result['allergens'] = array_values(array_unique($row_allergens));
                    }
                    return $result;
                }
            }
        }
        return $result;
    }

    public function ajax_meal_search() {
        check_ajax_referer('hospoda_meal_search','_ajax_nonce');
        $term = isset($_GET['term']) ? sanitize_text_field($_GET['term']) : '';
        $out=[]; $seen=[];
        // 1) Primárně knihovna jídel (CPT_MEAL)
        $q = new \WP_Query([
            'post_type'=>CPT_MEAL,
            's'=>$term,
            'posts_per_page'=>20,
            'orderby'=>'title',
            'order'=>'ASC',
        ]);
        while($q->have_posts()){ $q->the_post();
            $title = get_the_title();
            $key = mb_strtolower($title);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $extra = $this->last_usage_data(get_the_ID(), $title);
            if (!$this->should_manage_sides()) {
                $extra['sides'] = [];
            }
            $out[]=[
                'label'=>$title,
                'value'=>$title,
                'id'=>get_the_ID(),
                'price'=>$extra['price'],
                'sides'=>$extra['sides'],
                'allergens'=>$extra['allergens'],
            ];
        }
        wp_reset_postdata();
        // 2) Doplň titulky z posledních 90 dní v denním menu (pokud je málo návrhů)
        if (count($out) < 20){
            $since = date('Y-m-d', strtotime('-90 days'));
            $days = get_posts([
                'post_type'=>CPT_DAY,
                'posts_per_page'=>200,
                'meta_query'=>[
                    ['key'=>'menu_date','value'=>$since,'compare'=>'>=']
                ],
                'orderby'=>'meta_value','meta_key'=>'menu_date','order'=>'DESC'
            ]);
            foreach($days as $p){
                $mains = get_post_meta($p->ID,'mains',true);
                if (is_array($mains)){
                    foreach($mains as $row){
                        $t = isset($row['title']) ? (string)$row['title'] : '';
                        if ($t!=='' && stripos($t,$term)!==false){
                            $key = mb_strtolower($t);
                            if (!isset($seen[$key])){
                                $extra = $this->last_usage_data(0,$t);
                                if (!$this->should_manage_sides()) {
                                    $extra['sides'] = [];
                                }
                                $out[] = [
                                    'label'=>$t,
                                    'value'=>$t,
                                    'id'=>0,
                                    'price'=>$extra['price'],
                                    'sides'=>$extra['sides'],
                                    'allergens'=>$extra['allergens'],
                                ];
                                $seen[$key]=true;
                                if (count($out) >= 20) break 2;
                            }
                        }
                    }
                }
                $soup = get_post_meta($p->ID,'soup',true);
                if (!empty($soup['title'])){
                    $t = (string)$soup['title'];
                    if ($t!=='' && stripos($t,$term)!==false){
                        $key = mb_strtolower($t);
                        if (!isset($seen[$key])){
                            $extra = $this->last_usage_data(0,$t);
                            if (!$this->should_manage_sides()) {
                                $extra['sides'] = [];
                            }
                            $out[] = [
                                'label'=>$t,
                                'value'=>$t,
                                'id'=>0,
                                'price'=>$extra['price'],
                                'sides'=>$extra['sides'],
                                'allergens'=>$extra['allergens'],
                            ];
                            $seen[$key]=true;
                            if (count($out) >= 20) break;
                        }
                    }
                }
            }
        }
        wp_send_json($out);
    }

    // ---------- Denní admin stránka ----------
    public function render_admin_page() {
        // Although daily editor is no longer used directly from menu, keep it accessible if needed.
        $today = date('Y-m-d');
        $existing = get_posts(['post_type'=>CPT_DAY,'posts_per_page'=>1,'meta_key'=>'menu_date','meta_value'=>$today]);
        $data = ['soup'=>['id'=>'','title'=>'','price'=>''],'mains'=>[]];
        if ($existing) {
            $id = $existing[0]->ID;
            $data['soup'] = get_post_meta($id,'soup',true);
            $data['mains'] = get_post_meta($id,'mains',true);
        }
        $sides_data = $this->get_sides_data();
        $sides = $sides_data['terms'];
        ?>
        <div class="wrap">
          <h1>Polední menu – dne <?php echo esc_html($today); ?></h1>
          <form class="hs-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('hospoda_save_day'); ?>
            <input type="hidden" name="action" value="hospoda_save_day">
            <input type="hidden" name="menu_date" value="<?php echo esc_attr($today); ?>">
            <div class="row soup">
             <input class="meal-autocomplete" name="soup[title]" type="text" placeholder="Polévka – začněte psát…" value="<?php echo esc_attr($data['soup']['title'] ?? ''); ?>">
              <input class="meal-id" type="hidden" name="soup[id]" value="<?php echo esc_attr($data['soup']['id'] ?? ''); ?>">
              <input class="meal-allergens" type="hidden" name="soup[allergens]" value="<?php echo esc_attr($this->format_allergens_field($data['soup']['allergens'] ?? [])); ?>">
              <input class="price" type="text" name="soup[price]" placeholder="Cena (Kč)" value="<?php echo esc_attr($data['soup']['price'] ?? ''); ?>">
            </div>
            <div id="mains" class="hs-mains">
              <?php
              if (!empty($data['mains'])) {
                  foreach ($data['mains'] as $i=>$row) $this->render_main_row($sides,$row,$i);
              } else {
                  $this->render_main_row($sides,[],0);
              }
              ?>
            </div>
            <p><button type="button" class="button" id="add-row">Přidat jídlo</button></p>
            <p><button class="button button-primary">Uložit menu</button></p>
          </form>
        </div>
        <?php
    }

    private function render_main_row($sides,$row,$i) {
        ?>
        <div class="row main">
          <input class="meal-autocomplete" name="mains[<?php echo esc_attr($i); ?>][title]" type="text" placeholder="Název jídla…" value="<?php echo esc_attr($row['title'] ?? ''); ?>">
          <input class="meal-id" type="hidden" name="mains[<?php echo esc_attr($i); ?>][id]" value="<?php echo esc_attr($row['id'] ?? ''); ?>">
          <input class="meal-allergens" type="hidden" name="mains[<?php echo esc_attr($i); ?>][allergens]" value="<?php echo esc_attr($this->format_allergens_field($row['allergens'] ?? [])); ?>">
          <input class="price" type="text" name="mains[<?php echo esc_attr($i); ?>][price]" placeholder="Cena (Kč)" value="<?php echo esc_attr($row['price'] ?? ''); ?>">
          <div class="sides">
            <?php foreach ($sides as $side): $term_id = is_object($side)?$side->term_id:$side['term_id']; $term_name = is_object($side)?$side->name:$side['name']; ?>
              <label><input type="checkbox" name="mains[<?php echo esc_attr($i); ?>][sides][]" value="<?php echo esc_attr($term_id); ?>" <?php checked(in_array($term_id, $row['sides'] ?? [])); ?>> <?php echo esc_html($term_name); ?></label>
            <?php endforeach; ?>
          </div>
          <button type="button" class="button link-button remove-row">Odstranit</button>
        </div>
        <?php
    }

    public function handle_save_day() {
        if(!current_user_can('edit_posts')) wp_die();
        check_admin_referer('hospoda_save_day');
        $date = sanitize_text_field($_POST['menu_date']);
        $existing = get_posts(['post_type'=>CPT_DAY,'posts_per_page'=>1,'meta_key'=>'menu_date','meta_value'=>$date]);
        if ($existing) $post_id = $existing[0]->ID;
        else $post_id = wp_insert_post(['post_type'=>CPT_DAY,'post_status'=>'publish','post_title'=>'Menu '.$date]);
        update_post_meta($post_id,'menu_date',$date);
        $soup_in = $_POST['soup'] ?? [];
        $soup_id = isset($soup_in['id']) ? intval($soup_in['id']) : 0;
        if (!$soup_id && !empty($soup_in['title'])){ $soup_id = $this->ensure_meal_exists($soup_in['title']); }
        $soup = [
            'id'        => $soup_id,
            'title'     => $this->meal_title_by_id($soup_id,sanitize_text_field($soup_in['title']??'')),
            'price'     => sanitize_text_field($soup_in['price']??''),
            'allergens' => $this->sanitize_allergen_list($soup_in['allergens'] ?? []),
        ];
        update_post_meta($post_id,'soup',$soup);
        $mains=[];
        $use_groups = $this->should_use_menu_groups();
        $group_settings = $this->get_menu_groups_setting();
        $group_keys = [];
        foreach ($group_settings as $group) {
            if (!is_array($group)) {
                continue;
            }
            $key = isset($group['key']) ? (string)$group['key'] : '';
            if ($key !== '') {
                $group_keys[] = sanitize_key($key);
            }
        }
        $group_keys = array_values(array_filter($group_keys, static function ($key) {
            return $key !== '';
        }));
        $default_group_key = $group_keys[0] ?? '';

        foreach($_POST['mains']??[] as $row){
            $id=intval($row['id']??0);
            if (!$id && !empty($row['title'])){ $id = $this->ensure_meal_exists($row['title']); }
            $group_key = '';
            if ($use_groups) {
                $candidate = isset($row['menu_group']) ? sanitize_key($row['menu_group']) : '';
                if ($candidate !== '' && in_array($candidate, $group_keys, true)) {
                    $group_key = $candidate;
                } else {
                    $group_key = $default_group_key;
                }
            }
            $mains[]=[
                'id'=>$id,
                'title'=>$this->meal_title_by_id($id,sanitize_text_field($row['title']??'')),
                'price'=>sanitize_text_field($row['price']??''),
                'sides'=>array_values(array_unique(array_map('intval',$row['sides']??[]))),
                'allergens'=>$this->sanitize_allergen_list($row['allergens'] ?? []),
                'menu_group'=>$group_key,
            ];
        }
        update_post_meta($post_id,'mains',$mains);
        wp_redirect(admin_url('admin.php?page=hospoda-week&saved=1')); exit;
    }

    private function meal_title_by_id($id,$fallback){
        if ($id) { $p=get_post($id); if($p) return $p->post_title; }
        return $fallback;
    }

    // ---------- Týdenní admin stránka ----------
    private function render_week_day_block($index,$label,$date,$sides,$data,$use_sides,$pricing_mode,$default_menu_groups){
        $soup = is_array($data['soup'] ?? null) ? $data['soup'] : [];
        $mains_raw = is_array($data['mains'] ?? null) ? $data['mains'] : [];

        $default_groups = $this->sanitize_menu_groups($default_menu_groups);
        if (empty($default_groups)) {
            $default_groups = [
                ['key' => 'menu_1', 'label' => 'MENU 1', 'price' => ''],
            ];
        }

        $active_groups = [];
        $config_groups = [];
        if ($pricing_mode === 'menu_groups') {
            $meta_groups = is_array($data['menu_groups_meta'] ?? null) ? $this->sanitize_menu_groups($data['menu_groups_meta']) : [];
            $day_groups = is_array($data['menu_groups'] ?? null) ? $this->sanitize_menu_groups($data['menu_groups']) : [];
            if (empty($day_groups)) {
                $day_groups = $default_groups;
            }
            $active_groups = $day_groups;
            $config_groups = !empty($meta_groups) ? $meta_groups : $active_groups;
            if (empty($config_groups)) {
                $config_groups = $default_groups;
            }
        }

        $use_groups = ($pricing_mode === 'menu_groups' && !empty($active_groups));
        $group_rows = $use_groups ? $this->group_mains_by_menu($mains_raw, $active_groups) : [];
        ?>
        <fieldset class="hs-week-day" data-week-index="<?php echo esc_attr($index); ?>">
          <legend>
            <span class="hs-week-day__name"><?php echo esc_html($label); ?></span>
            <span class="hs-week-day__date"><?php echo esc_html($this->format_admin_date($date)); ?></span>
          </legend>

          <div class="hs-week-section hs-week-section--soup">
            <h3 class="hs-week-section-title">Polévka</h3>
            <div class="row soup">
              <input class="meal-autocomplete" name="week[soup][<?php echo esc_attr($index); ?>][title]" type="text" placeholder="Polévka – začněte psát…" value="<?php echo esc_attr($soup['title'] ?? ''); ?>">
              <input class="meal-id" type="hidden" name="week[soup][<?php echo esc_attr($index); ?>][id]" value="<?php echo esc_attr($soup['id'] ?? ''); ?>">
              <input class="meal-allergens" type="hidden" name="week[soup][<?php echo esc_attr($index); ?>][allergens]" value="<?php echo esc_attr($this->format_allergens_field($soup['allergens'] ?? [])); ?>">
              <input class="price" type="text" name="week[soup][<?php echo esc_attr($index); ?>][price]" placeholder="Cena (Kč)" value="<?php echo esc_attr($soup['price'] ?? ''); ?>">
            </div>
          </div>

          <div class="hs-week-section hs-week-section--mains">
            <h3 class="hs-week-section-title">Hlavní jídla</h3>
            <?php if ($use_groups) : ?>
              <div class="hs-week-day-groups">
                <button type="button" class="button button-secondary hs-week-day-groups-toggle" aria-expanded="false" data-day-index="<?php echo esc_attr($index); ?>">Upravit menu a ceny</button>
                <div class="hs-week-day-groups-panel" hidden data-day-index="<?php echo esc_attr($index); ?>">
                  <p class="description">Změny se uloží pouze pro tento den.</p>
                  <div class="hs-week-day-groups-list" data-next-index="<?php echo esc_attr(count($config_groups)); ?>">
                    <?php foreach ($config_groups as $i => $group) :
                        $group_key = isset($group['key']) ? sanitize_key($group['key']) : '';
                        if ($group_key === '') {
                            $group_key = 'menu_' . ($i + 1);
                        }
                        $group_label = isset($group['label']) ? (string)$group['label'] : '';
                        if ($group_label === '') {
                            $group_label = strtoupper($group_key);
                        }
                        $group_price = isset($group['price']) ? (string)$group['price'] : '';
                        ?>
                        <div class="hs-week-day-group-config" data-group-key="<?php echo esc_attr($group_key); ?>" data-index="<?php echo esc_attr($i); ?>">
                          <input type="hidden" name="week[menu_groups][<?php echo esc_attr($index); ?>][<?php echo esc_attr($i); ?>][key]" value="<?php echo esc_attr($group_key); ?>">
                          <label>
                            Název menu
                            <input type="text" class="regular-text js-day-group-label" name="week[menu_groups][<?php echo esc_attr($index); ?>][<?php echo esc_attr($i); ?>][label]" value="<?php echo esc_attr($group_label); ?>" placeholder="Např. MENU <?php echo esc_attr($i + 1); ?>">
                          </label>
                          <label>
                            Cena / popisek
                            <input type="text" class="regular-text js-day-group-price" name="week[menu_groups][<?php echo esc_attr($index); ?>][<?php echo esc_attr($i); ?>][price]" value="<?php echo esc_attr($group_price); ?>" placeholder="Např. 139 Kč">
                          </label>
                          <button type="button" class="button link-button hs-week-day-group-remove">Odstranit</button>
                        </div>
                    <?php endforeach; ?>
                  </div>
                  <p><button type="button" class="button hs-week-day-groups-add" data-day-index="<?php echo esc_attr($index); ?>">Přidat menu</button></p>
                </div>
              </div>
              <?php foreach ($active_groups as $group) :
                  $group_key = isset($group['key']) ? (string)$group['key'] : '';
                  if ($group_key === '') {
                      continue;
                  }
                  $label_text = isset($group['label']) ? (string)$group['label'] : '';
                  if ($label_text === '') {
                      $label_text = strtoupper($group_key);
                  }
                  $price_text = isset($group['price']) ? (string)$group['price'] : '';

                  $rows_for_group = $group_rows[$group_key] ?? [];
                  $output_rows = [];
                  $sub_index = 0;
                  foreach ($rows_for_group as $row) {
                      if (!is_array($row)) {
                          continue;
                      }
                      $row['menu_group'] = $group_key;
                      $row_key = $group_key . '_' . $sub_index;
                      $output_rows[] = ['key' => $row_key, 'row' => $row];
                      $sub_index++;
                  }
                  if (empty($output_rows)) {
                      $row_key = $group_key . '_0';
                      $output_rows[] = [
                          'key' => $row_key,
                          'row' => [
                              'id' => '',
                              'title' => '',
                              'price' => '',
                              'sides' => [],
                              'allergens' => [],
                              'menu_group' => $group_key,
                          ],
                      ];
                      $sub_index = 1;
                  }
                  $next_subindex = $sub_index;
                  ?>
                  <div class="hs-week-menu-group" data-group-key="<?php echo esc_attr($group_key); ?>">
                    <div class="hs-week-menu-group__header">
                      <span class="hs-week-menu-group__title"><?php echo esc_html($label_text); ?></span>
                      <?php if ($price_text !== '') : ?><span class="hs-week-menu-group__price"><?php echo esc_html($price_text); ?></span><?php endif; ?>
                    </div>
                    <div id="mains-<?php echo esc_attr($index . '-' . $group_key); ?>" class="hs-mains" data-include-price="0" data-has-sides="<?php echo $use_sides ? '1' : '0'; ?>" data-group-key="<?php echo esc_attr($group_key); ?>" data-next-subindex="<?php echo esc_attr($next_subindex); ?>">
                      <?php foreach ($output_rows as $row_meta) :
                          $row_key = $row_meta['key'];
                          $row = $row_meta['row'];
                          $row_sides = is_array($row['sides'] ?? null) ? array_map('intval', $row['sides']) : [];
                          $allergen_value = $this->format_allergens_field($row['allergens'] ?? []);
                          ?>
                          <div class="row main" data-group-key="<?php echo esc_attr($group_key); ?>" data-subindex="<?php echo esc_attr($row_key); ?>">
                            <input class="meal-autocomplete" name="week[mains][<?php echo esc_attr($index); ?>][<?php echo esc_attr($row_key); ?>][title]" type="text" placeholder="Název jídla…" value="<?php echo esc_attr($row['title'] ?? ''); ?>">
                            <input class="meal-id" type="hidden" name="week[mains][<?php echo esc_attr($index); ?>][<?php echo esc_attr($row_key); ?>][id]" value="<?php echo esc_attr($row['id'] ?? ''); ?>">
                            <input class="meal-allergens" type="hidden" name="week[mains][<?php echo esc_attr($index); ?>][<?php echo esc_attr($row_key); ?>][allergens]" value="<?php echo esc_attr($allergen_value); ?>">
                            <input class="menu-group-key" type="hidden" name="week[mains][<?php echo esc_attr($index); ?>][<?php echo esc_attr($row_key); ?>][menu_group]" value="<?php echo esc_attr($group_key); ?>">
                            <?php if ($use_sides) : ?>
                              <div class="sides">
                                <?php foreach ($sides as $side) :
                                    $term_id = is_object($side) ? $side->term_id : (isset($side['term_id']) ? $side['term_id'] : '');
                                    $term_name = is_object($side) ? $side->name : (isset($side['name']) ? $side['name'] : '');
                                    if (!$term_id) {
                                        continue;
                                    }
                                    ?>
                                    <label><input type="checkbox" name="week[mains][<?php echo esc_attr($index); ?>][<?php echo esc_attr($row_key); ?>][sides][]" value="<?php echo esc_attr($term_id); ?>" <?php checked(in_array((int)$term_id, $row_sides, true)); ?>> <?php echo esc_html($term_name); ?></label>
                                <?php endforeach; ?>
                              </div>
                            <?php else : ?>
                              <?php foreach ($row_sides as $side_id) : ?>
                                <input type="hidden" name="week[mains][<?php echo esc_attr($index); ?>][<?php echo esc_attr($row_key); ?>][sides][]" value="<?php echo esc_attr($side_id); ?>">
                              <?php endforeach; ?>
                            <?php endif; ?>
                            <button type="button" class="button link-button remove-row" data-week-index="<?php echo esc_attr($index); ?>" data-group-key="<?php echo esc_attr($group_key); ?>">Odstranit</button>
                          </div>
                      <?php endforeach; ?>
                    </div>
                  <p class="hs-week-add"><button type="button" class="button add-row-week" data-week-index="<?php echo esc_attr($index); ?>" data-group-key="<?php echo esc_attr($group_key); ?>">Přidat jídlo</button></p>
                </div>
            <?php endforeach; ?>
              <script type="text/template" class="hs-week-group-template" data-week-index="<?php echo esc_attr($index); ?>"><?php echo $this->build_week_group_template($index, $use_sides, $sides); ?></script>
            <?php else : ?>
              <div id="mains-<?php echo esc_attr($index); ?>" class="hs-mains" data-include-price="1" data-has-sides="<?php echo $use_sides ? '1' : '0'; ?>">
                <?php
                if (!empty($mains_raw)) {
                    foreach ($mains_raw as $i=>$row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $row_sides = is_array($row['sides'] ?? null) ? array_map('intval', $row['sides']) : [];
                        ?>
                        <div class="row main" data-index="<?php echo esc_attr($i); ?>">
                          <input class="meal-autocomplete" name="week[mains][<?php echo esc_attr($index); ?>][<?php echo esc_attr($i); ?>][title]" type="text" placeholder="Název jídla…" value="<?php echo esc_attr($row['title'] ?? ''); ?>">
                          <input class="meal-id" type="hidden" name="week[mains][<?php echo esc_attr($index); ?>][<?php echo esc_attr($i); ?>][id]" value="<?php echo esc_attr($row['id'] ?? ''); ?>">
                          <input class="meal-allergens" type="hidden" name="week[mains][<?php echo esc_attr($index); ?>][<?php echo esc_attr($i); ?>][allergens]" value="<?php echo esc_attr($this->format_allergens_field($row['allergens'] ?? [])); ?>">
                          <input class="price" type="text" name="week[mains][<?php echo esc_attr($index); ?>][<?php echo esc_attr($i); ?>][price]" placeholder="Cena (Kč)" value="<?php echo esc_attr($row['price'] ?? ''); ?>">
                          <?php if ($use_sides) : ?>
                            <div class="sides">
                              <?php foreach ($sides as $side): $term_id = is_object($side)?$side->term_id:(isset($side['term_id'])?$side['term_id']:''); $term_name = is_object($side)?$side->name:(isset($side['name'])?$side['name']:''); if (!$term_id) { continue; } ?>
                                <label><input type="checkbox" name="week[mains][<?php echo esc_attr($index); ?>][<?php echo esc_attr($i); ?>][sides][]" value="<?php echo esc_attr($term_id); ?>" <?php checked(in_array((int)$term_id, $row_sides, true)); ?>> <?php echo esc_html($term_name); ?></label>
                              <?php endforeach; ?>
                            </div>
                          <?php else : ?>
                            <?php foreach ($row_sides as $side_id) : ?>
                              <input type="hidden" name="week[mains][<?php echo esc_attr($index); ?>][<?php echo esc_attr($i); ?>][sides][]" value="<?php echo esc_attr($side_id); ?>">
                            <?php endforeach; ?>
                          <?php endif; ?>
                          <button type="button" class="button link-button remove-row" data-week-index="<?php echo esc_attr($index); ?>">Odstranit</button>
                        </div>
                        <?php
                    }
                } else {
                    ?>
                    <div class="row main" data-index="0">
                      <input class="meal-autocomplete" name="week[mains][<?php echo esc_attr($index); ?>][0][title]" type="text" placeholder="Název jídla…" value="">
                      <input class="meal-id" type="hidden" name="week[mains][<?php echo esc_attr($index); ?>][0][id]" value="">
                      <input class="meal-allergens" type="hidden" name="week[mains][<?php echo esc_attr($index); ?>][0][allergens]" value="">
                      <input class="price" type="text" name="week[mains][<?php echo esc_attr($index); ?>][0][price]" placeholder="Cena (Kč)" value="">
                      <?php if ($use_sides) : ?>
                        <div class="sides">
                          <?php foreach ($sides as $side): $term_id = is_object($side)?$side->term_id:(isset($side['term_id'])?$side['term_id']:''); $term_name = is_object($side)?$side->name:(isset($side['name'])?$side['name']:''); if (!$term_id) { continue; } ?>
                            <label><input type="checkbox" name="week[mains][<?php echo esc_attr($index); ?>][0][sides][]" value="<?php echo esc_attr($term_id); ?>"> <?php echo esc_html($term_name); ?></label>
                          <?php endforeach; ?>
                        </div>
                      <?php endif; ?>
                      <button type="button" class="button link-button remove-row" data-week-index="<?php echo esc_attr($index); ?>">Odstranit</button>
                    </div>
                    <?php
                }
                ?>
              </div>
              <p class="hs-week-add"><button type="button" class="button add-row-week" data-week-index="<?php echo esc_attr($index); ?>">Přidat jídlo</button></p>
            <?php endif; ?>
          </div>
        </fieldset>
        <?php
    }

    private function build_week_group_template(int $index, bool $use_sides, array $sides): string {
        $row_placeholder = '__GROUP_KEY___0';
        ob_start();
        ?>
<div class="hs-week-menu-group" data-group-key="__GROUP_KEY__">
  <div class="hs-week-menu-group__header">
    <span class="hs-week-menu-group__title">__GROUP_LABEL__</span>
    <span class="hs-week-menu-group__price">__GROUP_PRICE__</span>
  </div>
  <div id="mains-<?php echo esc_attr($index); ?>-__GROUP_KEY__" class="hs-mains" data-include-price="0" data-has-sides="<?php echo $use_sides ? '1' : '0'; ?>" data-group-key="__GROUP_KEY__" data-next-subindex="1">
    <div class="row main" data-group-key="__GROUP_KEY__" data-subindex="<?php echo esc_attr($row_placeholder); ?>">
      <input class="meal-autocomplete" name="week[mains][<?php echo esc_attr($index); ?>][<?php echo esc_attr($row_placeholder); ?>][title]" type="text" placeholder="Název jídla…" value="">
      <input class="meal-id" type="hidden" name="week[mains][<?php echo esc_attr($index); ?>][<?php echo esc_attr($row_placeholder); ?>][id]" value="">
      <input class="meal-allergens" type="hidden" name="week[mains][<?php echo esc_attr($index); ?>][<?php echo esc_attr($row_placeholder); ?>][allergens]" value="">
      <input class="menu-group-key" type="hidden" name="week[mains][<?php echo esc_attr($index); ?>][<?php echo esc_attr($row_placeholder); ?>][menu_group]" value="__GROUP_KEY__">
      <?php if ($use_sides) : ?>
        <div class="sides">
          <?php foreach ($sides as $side) :
              $term_id = is_object($side) ? $side->term_id : (isset($side['term_id']) ? $side['term_id'] : '');
              $term_name = is_object($side) ? $side->name : (isset($side['name']) ? $side['name'] : '');
              if (!$term_id) {
                  continue;
              }
              ?>
              <label><input type="checkbox" name="week[mains][<?php echo esc_attr($index); ?>][<?php echo esc_attr($row_placeholder); ?>][sides][]" value="<?php echo esc_attr($term_id); ?>"> <?php echo esc_html($term_name); ?></label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <button type="button" class="button link-button remove-row" data-week-index="<?php echo esc_attr($index); ?>" data-group-key="__GROUP_KEY__">Odstranit</button>
    </div>
  </div>
  <p class="hs-week-add"><button type="button" class="button add-row-week" data-week-index="<?php echo esc_attr($index); ?>" data-group-key="__GROUP_KEY__">Přidat jídlo</button></p>
</div>
        <?php
        return trim(ob_get_clean());
    }

    private function prepare_week_context($week_start){
        $week_start = $week_start ?: date('Y-m-d');
        $timestamp = strtotime($week_start);
        if ($timestamp === false) {
            $timestamp = \current_time('timestamp');
        }
        $dow = (int)date('N', $timestamp);
        $monday_ts = strtotime('-'.($dow-1).' days', $timestamp);
        if ($monday_ts === false) {
            $monday_ts = $timestamp;
        }
        $monday = date('Y-m-d', $monday_ts);
        $labels=['Pondělí','Úterý','Středa','Čtvrtek','Pátek'];
        $dates=[];
        for($i=0;$i<5;$i++){
            $day_ts = strtotime("+{$i} day", $monday_ts);
            $dates[$i] = $day_ts ? date('Y-m-d', $day_ts) : date('Y-m-d', $monday_ts);
        }
        $sides_data = $this->get_sides_data();
        $sides_terms = $sides_data['terms'];

        $frontend_theme = $this->get_frontend_theme_settings();
        $theme_defaults = $this->get_frontend_theme_defaults();
        $week_theme = $frontend_theme['week'];
        $static_theme = $frontend_theme['static'];
        $typo_theme = $frontend_theme['typography'];
        $week_color_fields = [
            'card_bg'            => ['label' => 'Pozadí karty dne'],
            'card_border'        => ['label' => 'Rámeček dne'],
            'heading_bg'         => ['label' => 'Pozadí záhlaví'],
            'heading_text'       => ['label' => 'Barva textu záhlaví'],
            'body_text'          => ['label' => 'Základní text jídel'],
            'sides_text'         => ['label' => 'Text příloh'],
            'price_text'         => ['label' => 'Barva ceny jídel'],
            'badge_bg'           => ['label' => 'Pozadí odznaku „Dnes“'],
            'badge_text'         => ['label' => 'Text odznaku „Dnes“'],
            'group_bg'           => ['label' => 'Pozadí menu skupin'],
            'group_border'       => ['label' => 'Rámeček menu skupin'],
            'group_title'        => ['label' => 'Nadpis menu skupiny'],
            'group_price'        => ['label' => 'Barva ceny menu'],
            'bullet_color'       => ['label' => 'Odrážky denních jídel', 'description' => 'Použije se, pokud jsou odrážky zapnuté.'],
            'group_bullet_color' => ['label' => 'Odrážky v menu skupinách'],
        ];
        $static_color_fields = [
            'background'   => ['label' => 'Pozadí bloku'],
            'border'       => ['label' => 'Rámeček bloku'],
            'title'        => ['label' => 'Nadpis bloku'],
            'text'         => ['label' => 'Text položek'],
            'price'        => ['label' => 'Barva ceny'],
            'bullet_color' => ['label' => 'Odrážky položek'],
        ];
        $bullet_options = [
            'none'   => 'Bez odrážek',
            'disc'   => 'Tečka',
            'dash'   => 'Pomlčka',
            'square' => 'Čtvereček',
            'arrow'  => 'Šipka',
        ];
        $weight_options = [
            '500' => 'Střední (500)',
            '600' => 'Polotučné (600)',
            '700' => 'Tučné (700)',
            '400' => 'Normální (400)',
        ];
        $base_size_value = isset($typo_theme['base_size']) ? (int)$typo_theme['base_size'] : 16;
        $sides_map = $sides_data['map'];

        $pricing_mode = $this->get_pricing_mode();
        $menu_groups_default = $this->get_menu_groups_setting();
        $use_sides = $this->should_manage_sides();

        $days_data=[];
        for($i=0;$i<5;$i++){
            $posts=get_posts(['post_type'=>CPT_DAY,'posts_per_page'=>1,'meta_key'=>'menu_date','meta_value'=>$dates[$i]]);
            if($posts){
                $id=$posts[0]->ID;
                $groups_meta = $this->should_use_menu_groups() ? $this->get_day_menu_groups_meta($id) : [];
                $active_groups = (!empty($groups_meta) ? $groups_meta : $menu_groups_default);
                $days_data[$i]=[
                    'soup'=>get_post_meta($id,'soup',true),
                    'mains'=>get_post_meta($id,'mains',true),
                    'menu_groups'=>$this->should_use_menu_groups() ? $active_groups : [],
                    'menu_groups_meta'=>$groups_meta,
                ];
            } else {
                $days_data[$i]=[
                    'soup'=>['id'=>'','title'=>'','price'=>''],
                    'mains'=>[],
                    'menu_groups'=>$this->should_use_menu_groups() ? $menu_groups_default : [],
                    'menu_groups_meta'=>[],
                ];
            }
        }

        return [
            'monday'      => $monday,
            'labels'      => $labels,
            'dates'       => $dates,
            'days'        => $days_data,
            'sides_terms' => $sides_terms,
            'sides_map'   => $sides_map,
            'pricing_mode'=> $pricing_mode,
            'menu_groups_default' => $menu_groups_default,
            'use_sides'   => $use_sides,
        ];
    }

    private function format_admin_date($date) {
        if (empty($date)) {
            return '';
        }
        $ts = strtotime($date);
        if ($ts === false) {
            return $date;
        }
        return date_i18n('j. n. Y', $ts);
    }

    public function render_week_admin_page() {
        $week_start = isset($_GET['week'])?sanitize_text_field($_GET['week']):date('Y-m-d');
        $week = $this->prepare_week_context($week_start);
        $labels = $week['labels'];
        $dates = $week['dates'];
        $days_data = $week['days'];
        $sides = $week['sides_terms'];
        $monday = $week['monday'];
        $pricing_mode = $week['pricing_mode'] ?? 'per_item';
        $default_menu_groups = is_array($week['menu_groups_default'] ?? null) ? $week['menu_groups_default'] : [];
        if (empty($default_menu_groups)) {
            $default_menu_groups = [
                ['key' => 'menu_1', 'label' => 'MENU 1', 'price' => ''],
            ];
        }
        $use_sides = !empty($week['use_sides']);
        $day_count = count($dates);
        ?>
        <div class="wrap">
          <h1>Týdenní menu</h1>
          <?php if (isset($_GET['saved'])) : ?>
            <div class="notice notice-success is-dismissible"><p>Týdenní menu bylo uloženo.</p></div>
          <?php endif; ?>
          <p>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=hospoda-week-branding')); ?>">Nastavení</a>
          </p>
          <form class="hs-form hs-week-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('hospoda_save_week'); ?>
            <input type="hidden" name="action" value="hospoda_save_week">
            <div class="hs-week-header">
              <div>
                <label for="hs-week-start">Týden od (pondělí):</label>
                <input id="hs-week-start" type="date" name="week_start" value="<?php echo esc_attr($monday); ?>">
              </div>
              <p class="description">Změnou data se načte zvolený týden (pondělí–pátek) bez uložení.</p>
            </div>
            <div class="hs-week-grid">
              <?php for($i=0;$i<$day_count;$i++){ $this->render_week_day_block($i,$labels[$i] ?? '',$dates[$i] ?? '',$sides,$days_data[$i] ?? [],$use_sides,$pricing_mode,$default_menu_groups); } ?>
            </div>
            <div class="hs-week-actions">
              <button class="button button-primary">Uložit celý týden</button>
              <button type="submit" form="hs-week-export" class="button">Exportovat PDF</button>
            </div>
          </form>
          <form id="hs-week-export" class="hs-export-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" target="_blank">
            <?php wp_nonce_field('hospoda_export_week_pdf','hospoda_export_week_pdf_nonce'); ?>
            <input type="hidden" name="action" value="hospoda_export_week_pdf">
            <input type="hidden" name="week_start" value="<?php echo esc_attr($monday); ?>">
          </form>
        </div>
        <?php
    }

    public function render_branding_admin_page() {
        $branding = $this->get_pdf_branding_settings();
        $logo_id = (int)($branding['logo_id'] ?? 0);
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'medium') : '';
        $preferences = $this->get_menu_preferences();
        $soup_mode = $preferences['soup_price_mode'] ?? 'included';
        $sides_mode = $preferences['sides_mode'] ?? 'taxonomy';
        $pricing_mode = $preferences['pricing_mode'] ?? 'per_item';
        $static_items = $branding['static_menu_items'] ?? [];
        if (!is_array($static_items) || empty($static_items)) {
            $static_items = [
                ['id' => 0, 'title' => '', 'price' => '', 'sides' => [], 'allergens' => []],
            ];
        }
        $menu_groups = is_array($preferences['menu_groups'] ?? null) ? $preferences['menu_groups'] : [];
        if (empty($menu_groups)) {
            $menu_groups = [
                ['key' => 'menu_1', 'label' => 'MENU 1', 'price' => ''],
                ['key' => 'menu_2', 'label' => 'MENU 2', 'price' => ''],
            ];
        }
        $use_sides = $this->should_manage_sides();
        $sides_data = $this->get_sides_data();
        $sides_terms = $sides_data['terms'];
        ?>
        <div class="wrap">
          <h1>Nastavení</h1>
          <p class="description">Upravte vzhled, stálou nabídku a další volby použité ve výpisech jídelníčku a při exportu do PDF.</p>
          <?php if (isset($_GET['branding_saved'])) : ?>
            <div class="notice notice-success is-dismissible"><p>Nastavení bylo uloženo.</p></div>
          <?php elseif (isset($_GET['branding_error'])) : ?>
            <div class="notice notice-error is-dismissible"><p>Nahrání loga se nezdařilo: <?php echo esc_html(rawurldecode(wp_unslash($_GET['branding_error']))); ?></p></div>
          <?php endif; ?>
          <form class="hs-branding" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('hospoda_save_branding'); ?>
            <input type="hidden" name="action" value="hospoda_save_branding">
            <div class="hs-branding__logo">
              <div class="hs-branding__preview">
                <?php if ($logo_url) : ?>
                  <img src="<?php echo esc_url($logo_url); ?>" alt="">
                <?php else : ?>
                  <span>Žádné logo</span>
                <?php endif; ?>
              </div>
              <div class="hs-branding__controls">
                <input type="hidden" name="branding_logo_id" value="<?php echo esc_attr($logo_id); ?>">
                <label for="hs-branding-logo-upload"><strong>Nové logo</strong></label>
                <input type="file" id="hs-branding-logo-upload" name="branding_logo_file" accept="image/png,image/jpeg,image/svg+xml">
                <?php if ($logo_id) : ?>
                  <label><input type="checkbox" name="branding_logo_remove" value="1"> Odebrat aktuální logo</label>
                <?php endif; ?>
                <p class="description">Nahrajte nové logo (PNG, JPG nebo SVG). Pokud ponecháte pole prázdné, zůstane uložené logo beze změny.</p>
              </div>
            </div>
            <p>
              <label for="hs-branding-top"><strong>Text v záhlaví</strong></label><br>
              <textarea name="branding_top" id="hs-branding-top" rows="5" class="large-text code"><?php echo esc_textarea($branding['top_text']); ?></textarea>
              <span class="description">Každý řádek se vykreslí jako samostatný řádek nad jídelníčkem.</span>
            </p>
            <p>
              <label for="hs-branding-bottom"><strong>Text v patičce</strong></label><br>
              <textarea name="branding_bottom" id="hs-branding-bottom" rows="4" class="large-text code"><?php echo esc_textarea($branding['bottom_text']); ?></textarea>
              <span class="description">Řádky se zobrazí pod seznamem jídel v patičce PDF.</span>
            </p>
            <div class="hs-branding__static">
              <h2>Stálá nabídka</h2>
              <p class="description">Vyberte položky z knihovny jídel. Budou zobrazeny pod týdenním menu na webu i v PDF exportu.</p>
              <div id="hs-static-menu" class="hs-static-menu hs-mains" data-has-sides="<?php echo $use_sides ? '1' : '0'; ?>">
                <?php foreach ($static_items as $index => $item) :
                    $item_id = (int)($item['id'] ?? 0);
                    $item_title = (string)($item['title'] ?? '');
                    $item_price = (string)($item['price'] ?? '');
                    $item_sides = is_array($item['sides'] ?? null) ? array_map('intval', $item['sides']) : [];
                    $item_allergens = is_array($item['allergens'] ?? null) ? array_map('intval', $item['allergens']) : [];
                    $allergen_value = $this->format_allergens_field($item_allergens);
                    ?>
                    <div class="row main" data-static-index="<?php echo esc_attr($index); ?>">
                      <input class="meal-autocomplete" name="static_menu[<?php echo esc_attr($index); ?>][title]" type="text" placeholder="Název jídla…" value="<?php echo esc_attr($item_title); ?>">
                      <input class="meal-id" type="hidden" name="static_menu[<?php echo esc_attr($index); ?>][id]" value="<?php echo esc_attr($item_id); ?>">
                      <input class="meal-allergens" type="hidden" name="static_menu[<?php echo esc_attr($index); ?>][allergens]" value="<?php echo esc_attr($allergen_value); ?>">
                      <input class="price" type="text" name="static_menu[<?php echo esc_attr($index); ?>][price]" placeholder="Cena (Kč)" value="<?php echo esc_attr($item_price); ?>">
                      <?php if ($use_sides) : ?>
                        <div class="sides">
                          <?php foreach ($sides_terms as $side) :
                              $term_id = is_object($side) ? $side->term_id : ($side['term_id'] ?? 0);
                              $term_name = is_object($side) ? $side->name : ($side['name'] ?? '');
                              if (!$term_id) {
                                  continue;
                              }
                              ?>
                              <label><input type="checkbox" name="static_menu[<?php echo esc_attr($index); ?>][sides][]" value="<?php echo esc_attr($term_id); ?>" <?php checked(in_array((int)$term_id, $item_sides, true)); ?>> <?php echo esc_html($term_name); ?></label>
                          <?php endforeach; ?>
                        </div>
                      <?php else : ?>
                        <?php foreach ($item_sides as $preserve_side) : ?>
                          <input type="hidden" name="static_menu[<?php echo esc_attr($index); ?>][sides][]" value="<?php echo esc_attr($preserve_side); ?>">
                        <?php endforeach; ?>
                      <?php endif; ?>
                      <button type="button" class="button link-button remove-row" data-static-index="<?php echo esc_attr($index); ?>">Odstranit</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="hs-static-actions"><button type="button" class="button" id="hs-static-add">Přidat položku</button></p>
          </div>
          <fieldset class="hs-branding__sides">
            <legend><strong>Práce s přílohami</strong></legend>
            <label><input type="radio" name="sides_mode" value="taxonomy" <?php checked('taxonomy', $sides_mode); ?>> Přílohy spravujeme zvlášť a vybíráme je z knihovny</label><br>
            <label><input type="radio" name="sides_mode" value="disabled" <?php checked('disabled', $sides_mode); ?>> Přílohy zapisujeme přímo do názvu jídla (bez samostatného výběru)</label>
            <p class="description">Volba ovlivní administraci i výstupy – při vypnutí se seznam příloh skryje a již uložené přílohy zůstanou pouze pro případný návrat k původnímu režimu.</p>
          </fieldset>
          <fieldset class="hs-branding__soup">
            <legend><strong>Zobrazení ceny polévky</strong></legend>
            <label><input type="radio" name="soup_price_mode" value="included" <?php checked('included', $soup_mode); ?>> Polévka je v ceně menu (nezobrazovat cenu zvlášť)</label><br>
            <label><input type="radio" name="soup_price_mode" value="separate" <?php checked('separate', $soup_mode); ?>> Polévka se účtuje zvlášť (zobrazit cenu samostatně)</label>
            <p class="description">Nastavení ovlivní veřejné zobrazení jídelníčku i export do PDF.</p>
          </fieldset>
          <fieldset class="hs-branding__pricing">
            <legend><strong>Zobrazení cen hlavních jídel</strong></legend>
            <label><input type="radio" name="pricing_mode" value="per_item" <?php checked('per_item', $pricing_mode); ?>> Každé jídlo má vlastní cenu (původní způsob)</label><br>
            <label><input type="radio" name="pricing_mode" value="menu_groups" <?php checked('menu_groups', $pricing_mode); ?>> Využít menu skupiny (MENU 1, MENU 2…) s cenou v nadpisu</label>
            <div class="hs-branding__menu-groups<?php echo $pricing_mode === 'menu_groups' ? '' : ' is-hidden'; ?>">
              <p class="description">Zadejte názvy menu a jejich ceny. V týdenním menu pak pro každý den vznikne samostatná sekce podle těchto řádků.</p>
              <div id="hs-menu-groups" class="hs-menu-groups" data-next-index="<?php echo esc_attr(count($menu_groups)); ?>">
                <?php foreach ($menu_groups as $i => $group) :
                    $group_key = isset($group['key']) ? sanitize_key($group['key']) : '';
                    if ($group_key === '') {
                        $group_key = 'menu_' . ($i + 1);
                    }
                    $group_label = isset($group['label']) ? (string)$group['label'] : '';
                    $group_price = isset($group['price']) ? (string)$group['price'] : '';
                    ?>
                    <div class="hs-menu-group" data-index="<?php echo esc_attr($i); ?>">
                      <input type="hidden" name="menu_groups[<?php echo esc_attr($i); ?>][key]" value="<?php echo esc_attr($group_key); ?>">
                      <label>Název menu
                        <input type="text" name="menu_groups[<?php echo esc_attr($i); ?>][label]" value="<?php echo esc_attr($group_label); ?>">
                      </label>
                      <label>Cena / popisek
                        <input type="text" name="menu_groups[<?php echo esc_attr($i); ?>][price]" value="<?php echo esc_attr($group_price); ?>">
                      </label>
                      <button type="button" class="button link-button hs-menu-group-remove">Odstranit</button>
                    </div>
                <?php endforeach; ?>
              </div>
              <p class="hs-menu-groups__actions"><button type="button" class="button" id="hs-menu-groups-add">Přidat menu</button></p>
            </div>
          </fieldset>
          <fieldset class="hs-branding__theme">
            <legend><strong>Vzhled webového menu</strong></legend>
            <p class="description">Nastavte barvy a styl prvků, které se zobrazují ve veřejném výpisu jídelního lístku.</p>
            <div class="hs-branding__theme-grid">
              <div class="hs-branding__theme-group">
                <h3>Týdenní nabídka</h3>
                <?php foreach ($week_color_fields as $key => $meta) :
                    $field_id = 'hs-theme-week-' . $key;
                    $value = isset($week_theme[$key]) ? $week_theme[$key] : ($theme_defaults['week'][$key] ?? '');
                    $default = $theme_defaults['week'][$key] ?? '';
                    ?>
                    <div class="hs-branding__field">
                      <label for="<?php echo esc_attr($field_id); ?>"><?php echo esc_html($meta['label']); ?></label>
                      <input type="text" class="hs-color-field" id="<?php echo esc_attr($field_id); ?>" name="frontend_theme[week][<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($value); ?>" data-default-color="<?php echo esc_attr($default); ?>">
                      <?php if (!empty($meta['description'])) : ?>
                        <span class="description"><?php echo esc_html($meta['description']); ?></span>
                      <?php endif; ?>
                    </div>
                <?php endforeach; ?>
              </div>
              <div class="hs-branding__theme-group">
                <h3>Stálá nabídka</h3>
                <?php foreach ($static_color_fields as $key => $meta) :
                    $field_id = 'hs-theme-static-' . $key;
                    $value = isset($static_theme[$key]) ? $static_theme[$key] : ($theme_defaults['static'][$key] ?? '');
                    $default = $theme_defaults['static'][$key] ?? '';
                    ?>
                    <div class="hs-branding__field">
                      <label for="<?php echo esc_attr($field_id); ?>"><?php echo esc_html($meta['label']); ?></label>
                      <input type="text" class="hs-color-field" id="<?php echo esc_attr($field_id); ?>" name="frontend_theme[static][<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($value); ?>" data-default-color="<?php echo esc_attr($default); ?>">
                      <?php if (!empty($meta['description'])) : ?>
                        <span class="description"><?php echo esc_html($meta['description']); ?></span>
                      <?php endif; ?>
                    </div>
                <?php endforeach; ?>
              </div>
              <div class="hs-branding__theme-group">
                <h3>Typografie a odrážky</h3>
                <div class="hs-branding__field">
                  <label for="hs-theme-base-size">Základní velikost písma</label>
                  <input type="number" id="hs-theme-base-size" name="frontend_theme[typography][base_size]" value="<?php echo esc_attr($base_size_value); ?>" min="12" max="24" step="1">
                  <span class="description">Velikost v pixelech pro celé zobrazení menu.</span>
                </div>
                <div class="hs-branding__field">
                  <label for="hs-theme-title-weight">Tloušťka názvů jídel</label>
                  <select id="hs-theme-title-weight" name="frontend_theme[typography][title_weight]">
                    <?php foreach ($weight_options as $value => $label) : ?>
                      <option value="<?php echo esc_attr($value); ?>" <?php selected($typo_theme['title_weight'], $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="hs-branding__field">
                  <label for="hs-theme-week-bullet">Odrážky u denního menu</label>
                  <select id="hs-theme-week-bullet" name="frontend_theme[typography][week_bullet]">
                    <?php foreach ($bullet_options as $value => $label) : ?>
                      <option value="<?php echo esc_attr($value); ?>" <?php selected($typo_theme['week_bullet'], $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="hs-branding__field">
                  <label for="hs-theme-group-bullet">Odrážky v menu skupinách</label>
                  <select id="hs-theme-group-bullet" name="frontend_theme[typography][group_bullet]">
                    <?php foreach ($bullet_options as $value => $label) : ?>
                      <option value="<?php echo esc_attr($value); ?>" <?php selected($typo_theme['group_bullet'], $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="hs-branding__field">
                  <label for="hs-theme-static-bullet">Odrážky ve stálé nabídce</label>
                  <select id="hs-theme-static-bullet" name="frontend_theme[typography][static_bullet]">
                    <?php foreach ($bullet_options as $value => $label) : ?>
                      <option value="<?php echo esc_attr($value); ?>" <?php selected($typo_theme['static_bullet'], $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>
          </fieldset>
          <p>
            <button type="submit" class="button button-primary">Uložit nastavení</button>
            <a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=hospoda-week')); ?>">Zpět na týdenní menu</a>
          </p>
          </form>
        </div>
        <?php
    }

    public function handle_save_week() {
        if(!current_user_can('edit_posts')) wp_die();
        check_admin_referer('hospoda_save_week');
        $week_start = sanitize_text_field($_POST['week_start'] ?? date('Y-m-d'));
        $ts = strtotime($week_start); $dow = (int)date('N', $ts); $monday = date('Y-m-d', strtotime('-'.($dow-1).' days', $ts));
        $week = isset($_POST['week']) ? wp_unslash($_POST['week']) : [];
        $week_groups_input = isset($week['menu_groups']) && is_array($week['menu_groups']) ? $week['menu_groups'] : [];
        $default_day_groups = $this->get_menu_groups_setting();

        // Uložení 5 pracovních dní (Po–Pá)
        $use_sides = $this->should_manage_sides();
        $use_groups = $this->should_use_menu_groups();

        for ($i = 0; $i < 5; $i++) {
            $date = date('Y-m-d', strtotime("+{$i} day", strtotime($monday)));

            // Najít / vytvořit záznam denního menu
            $existing = get_posts([
                'post_type'      => CPT_DAY,
                'posts_per_page' => 1,
                'meta_key'       => 'menu_date',
                'meta_value'     => $date,
            ]);

            if ($existing) {
                $post_id = $existing[0]->ID;
                wp_update_post(['ID' => $post_id, 'post_title' => 'Menu ' . $date]);
            } else {
                $post_id = wp_insert_post([
                    'post_type'   => CPT_DAY,
                    'post_status' => 'publish',
                    'post_title'  => 'Menu ' . $date,
                ]);
                add_post_meta($post_id, 'menu_date', $date, true);
            }

            // Polévka
            $soup_in = $week['soup'][$i] ?? [];
            $soup_id = isset($soup_in['id']) ? intval($soup_in['id']) : 0;
            if (!$soup_id && !empty($soup_in['title'])){ $soup_id = $this->ensure_meal_exists($soup_in['title']); }
            $soup = [
                'id'        => $soup_id,
                'title'     => $this->meal_title_by_id($soup_id, sanitize_text_field($soup_in['title'] ?? '')),
                'price'     => sanitize_text_field($soup_in['price'] ?? ''),
                'allergens' => $this->sanitize_allergen_list($soup_in['allergens'] ?? []),
            ];
            update_post_meta($post_id, 'soup', $soup);

            // Hlavní jídla
            $mains = [];
            $mains_in = $week['mains'][$i] ?? [];
            $day_group_input = isset($week_groups_input[$i]) ? $week_groups_input[$i] : [];
            $day_groups = $use_groups ? $this->prepare_menu_groups_submission($day_group_input) : [];
            $active_groups = $use_groups ? (!empty($day_groups) ? $day_groups : $default_day_groups) : [];
            $group_keys = [];
            if ($use_groups) {
                foreach ($active_groups as $group) {
                    if (!is_array($group)) {
                        continue;
                    }
                    $key = isset($group['key']) ? sanitize_key((string)$group['key']) : '';
                    if ($key !== '') {
                        $group_keys[] = $key;
                    }
                }
                $group_keys = array_values(array_filter($group_keys, static function ($key) {
                    return $key !== '';
                }));
            }
            $default_group_key = $group_keys[0] ?? '';

            foreach ($mains_in as $row) {
                $id = intval($row['id'] ?? 0);
                if (!$id && !empty($row['title'])){ $id = $this->ensure_meal_exists($row['title']); }
                $sides_list = array_values(array_unique(array_map('intval', $row['sides'] ?? [])));
                $group_key = '';
                if ($use_groups) {
                    $group_candidate = isset($row['menu_group']) ? sanitize_key($row['menu_group']) : '';
                    if ($group_candidate !== '' && in_array($group_candidate, $group_keys, true)) {
                        $group_key = $group_candidate;
                    } else {
                        $group_key = $default_group_key;
                    }
                }
                $mains[] = [
                    'id'    => $id,
                    'title' => $this->meal_title_by_id($id, sanitize_text_field($row['title'] ?? '')),
                    'price' => sanitize_text_field($row['price'] ?? ''),
                    'sides' => $sides_list,
                    'allergens' => $this->sanitize_allergen_list($row['allergens'] ?? []),
                    'menu_group' => $group_key,
                ];
            }
            update_post_meta($post_id, 'mains', $mains);
            if ($use_groups) {
                if (!empty($day_groups) && $day_groups !== $default_day_groups) {
                    update_post_meta($post_id, 'menu_groups', $day_groups);
                } else {
                    delete_post_meta($post_id, 'menu_groups');
                }
            } else {
                delete_post_meta($post_id, 'menu_groups');
            }
        }

        wp_redirect(admin_url('admin.php?page=hospoda-week&saved=1'));
        exit;
    }

    public function handle_save_branding() {
        if (!current_user_can('edit_posts')) {
            wp_die();
        }
        check_admin_referer('hospoda_save_branding');

        $current_logo_id = isset($_POST['branding_logo_id']) ? intval($_POST['branding_logo_id']) : 0;
        $remove_logo = !empty($_POST['branding_logo_remove']);
        $top = isset($_POST['branding_top']) ? sanitize_textarea_field(wp_unslash($_POST['branding_top'])) : '';
        $bottom = isset($_POST['branding_bottom']) ? sanitize_textarea_field(wp_unslash($_POST['branding_bottom'])) : '';
        $static_raw = isset($_POST['static_menu']) && is_array($_POST['static_menu']) ? wp_unslash($_POST['static_menu']) : [];
        $static_items = $this->prepare_static_menu_submission($static_raw);

        $new_logo_id = $current_logo_id;
        $file = $_FILES['branding_logo_file'] ?? null;
        if ($file && isset($file['error']) && $file['error'] !== UPLOAD_ERR_NO_FILE) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $upload_id = media_handle_upload('branding_logo_file', 0);
            if (is_wp_error($upload_id)) {
                $error_message = rawurlencode($upload_id->get_error_message());
                wp_redirect(admin_url('admin.php?page=hospoda-week-branding&branding_error=' . $error_message));
                exit;
            }
            $new_logo_id = (int) $upload_id;
        } elseif ($remove_logo) {
            $new_logo_id = 0;
        }

        $data = [
            'logo_id'           => max(0, $new_logo_id),
            'top_text'          => $this->sanitize_multiline_text($top),
            'bottom_text'       => $this->sanitize_multiline_text($bottom),
            'static_menu_items' => $static_items,
        ];

        update_option('hsp_pdf_branding', $data, false);
        $this->static_menu_cache = null;
        $this->meal_terms_cache = [];

        $theme_input = isset($_POST['frontend_theme']) && is_array($_POST['frontend_theme']) ? wp_unslash($_POST['frontend_theme']) : [];
        $theme_settings = $this->sanitize_frontend_theme_settings($theme_input);
        update_option('hsp_frontend_theme', $theme_settings, false);
        $this->frontend_theme_cache = null;

        $raw_groups = isset($_POST['menu_groups']) && is_array($_POST['menu_groups']) ? wp_unslash($_POST['menu_groups']) : [];
        $preferences = [
            'soup_price_mode' => $this->normalize_soup_price_mode(isset($_POST['soup_price_mode']) ? sanitize_text_field(wp_unslash($_POST['soup_price_mode'])) : ''),
            'sides_mode'      => $this->normalize_sides_mode(isset($_POST['sides_mode']) ? sanitize_text_field(wp_unslash($_POST['sides_mode'])) : ''),
            'pricing_mode'    => $this->normalize_pricing_mode(isset($_POST['pricing_mode']) ? sanitize_text_field(wp_unslash($_POST['pricing_mode'])) : ''),
            'menu_groups'     => $this->prepare_menu_groups_submission($raw_groups),
        ];
        update_option('hsp_menu_preferences', $preferences, false);
        $this->menu_preferences_cache = null;

        wp_redirect(admin_url('admin.php?page=hospoda-week-branding&branding_saved=1'));
        exit;
    }

    public function handle_export_week_pdf(){
        if(!current_user_can('edit_posts')) wp_die();
        check_admin_referer('hospoda_export_week_pdf','hospoda_export_week_pdf_nonce');
        $week_start = isset($_POST['week_start']) ? sanitize_text_field($_POST['week_start']) : date('Y-m-d');
        $week = $this->prepare_week_context($week_start);
        $branding = $this->get_pdf_branding_settings();
        $branding['logo_path'] = $this->resolve_branding_logo_path((int)($branding['logo_id'] ?? 0));
        $preferences = $this->get_menu_preferences();

        require_once __DIR__ . '/includes/class-simple-pdf.php';
        require_once __DIR__ . '/includes/class-week-pdf-exporter.php';

        $exporter = new Week_Pdf_Exporter();
        $exportOptions = [
            'show_soup_price' => $this->should_show_soup_price(),
            'static_menu'     => $this->get_static_menu_items(),
            'pricing_mode'    => $week['pricing_mode'] ?? ($preferences['pricing_mode'] ?? 'per_item'),
            'show_sides'      => $this->should_manage_sides(),
        ];
        $pdf = $exporter->build($week, $branding, $exportOptions);

        $monday_ts = strtotime($week['monday']);
        if ($monday_ts === false) {
            $monday_ts = \current_time('timestamp');
        }
        $filename = 'tydenni-menu-' . date('Ymd', $monday_ts) . '.pdf';

        if (!headers_sent()) {
            \nocache_headers();
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($pdf));
        }
        echo $pdf;
        exit;
    }

    /**
     * Metabox se souhrnem uloženého menu (jen pro čtení)
     */
    public function add_day_metabox(){
        add_meta_box(
            'hospoda_day_summary',
            'Souhrn poledního menu',
            [$this,'render_day_metabox'],
            CPT_DAY,
            'normal',
            'high'
        );
    }

    public function render_day_metabox($post){
        $soup  = get_post_meta($post->ID,'soup',true);
        $mains = get_post_meta($post->ID,'mains',true);
        $use_groups = $this->should_use_menu_groups();
        $menu_groups = $use_groups ? $this->resolve_day_menu_groups((int) $post->ID) : [];
        $sides_map = $this->should_manage_sides() ? $this->get_sides_data()['map'] : [];
        echo '<style>.hs-meta ul{margin-left:1em} .hs-meta li{margin:.25em 0}</style>';
        echo '<div class="hs-meta">';
        echo '<p><strong>Datum:</strong> ' . esc_html( get_post_meta($post->ID,'menu_date',true) ) . '</p>';
        echo '<h4>Polévka</h4>';
        if (!empty($soup['title'])) {
            $line = esc_html($soup['title']);
            if ($this->should_show_soup_price() && !empty($soup['price'])) {
                $line .= ' — ' . esc_html($soup['price']) . ' Kč';
            }
            echo '<p>' . $line . '</p>';
        } else {
            echo '<p><em>nenastaveno</em></p>';
        }
        echo '<h4>Hlavní jídla</h4>';
        if (!empty($mains) && is_array($mains)){
            if ($use_groups && !empty($menu_groups)) {
                $grouped_mains = $this->group_mains_by_menu($mains, $menu_groups);
                foreach ($menu_groups as $group) {
                    $group_key = isset($group['key']) ? sanitize_key($group['key']) : '';
                    if ($group_key === '') {
                        continue;
                    }
                    $items = $grouped_mains[$group_key] ?? [];
                    if (empty($items)) {
                        continue;
                    }
                    $label = isset($group['label']) ? (string)$group['label'] : '';
                    if ($label === '') {
                        $label = strtoupper($group_key);
                    }
                    $price = isset($group['price']) ? (string)$group['price'] : '';
                    echo '<h5 style="margin:.5em 0 0;">' . esc_html($label) . ($price !== '' ? ' — ' . esc_html($price) : '') . '</h5>';
                    echo '<ul>';
                    foreach ($items as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $line = esc_html($row['title'] ?? '');
                        if (!empty($row['sides']) && is_array($row['sides'])) {
                            $names = [];
                            foreach ($row['sides'] as $side_id) {
                                $side_id = (int)$side_id;
                                if ($side_id && isset($sides_map[$side_id])) {
                                    $names[] = $sides_map[$side_id];
                                }
                            }
                            $names = array_filter($names);
                            if (!empty($names)) {
                                $line .= ' (' . esc_html(implode(', ', $names)) . ')';
                            }
                        }
                        echo '<li>'.$line.'</li>';
                    }
                    echo '</ul>';
                }
            } else {
                echo '<ul>';
                foreach($mains as $row){
                    if (!is_array($row)) {
                        continue;
                    }
                    $line = esc_html($row['title'] ?? '');
                    if (!empty($row['sides'])){
                        $names = [];
                        foreach ($row['sides'] as $side_id) {
                            $side_id = (int)$side_id;
                            if ($side_id && isset($sides_map[$side_id])) {
                                $names[] = $sides_map[$side_id];
                            }
                        }
                        $names = array_filter($names);
                        if (!empty($names)) {
                            $line .= ' (' . esc_html(implode(', ', $names)) . ')';
                        }
                    }
                    if (!empty($row['price'])) {
                        $line .= ' — '.esc_html($row['price']).' Kč';
                    }
                    echo '<li>'.$line.'</li>';
                }
                echo '</ul>';
            }
        } else {
            echo '<p><em>žádná hlavní jídla</em></p>';
        }
        echo '<p style="margin-top:1em"><a class="button" href="'.esc_url( admin_url('admin.php?page=hospoda-week') ).'">Otevřít týdenní editor</a></p>';
        echo '</div>';
    }

    /**
     * Sloupce v přehledu CPT Denní menu (archiv)
     */
    public function day_columns($cols){
        $cols['menu_date'] = 'Datum';
        $cols['soup']      = 'Polévka';
        $cols['mains']     = 'Počet jídel';
        return $cols;
    }

    public function day_columns_content($column, $post_id){
        if ($column === 'menu_date'){
            echo esc_html( get_post_meta($post_id,'menu_date',true) );
        } elseif ($column === 'soup'){
            $soup = get_post_meta($post_id,'soup',true);
            echo esc_html( $soup['title'] ?? '' );
        } elseif ($column === 'mains'){
            $mains = get_post_meta($post_id,'mains',true);
            echo is_array($mains) ? count($mains) : 0;
        }
    }

    /**
     * Vygeneruje HTML pro jedno konkrétní datum (YYYY-mm-dd)
     */
    private function render_day_menu_html($date){
        $posts = get_posts([
            'post_type'=>CPT_DAY,
            'posts_per_page'=>1,
            'meta_key'=>'menu_date',
            'meta_value'=>$date,
        ]);
        if (!$posts) return '';
        $post_id = $posts[0]->ID;
        $soup  = get_post_meta($post_id,'soup',true);
        $mains = get_post_meta($post_id,'mains',true);
        $show_soup_price = $this->should_show_soup_price();
        $use_groups = $this->should_use_menu_groups();
        $menu_groups = $use_groups ? $this->resolve_day_menu_groups((int) $post_id) : [];
        $use_sides = $this->should_manage_sides();
        $sides_map = $use_sides ? $this->get_sides_data()['map'] : [];
        ob_start();
        echo '<div class="hsp-day" data-date="'.esc_attr($date).'">';
        $heading  = '<h4 class="hsp-day__heading">'.esc_html( wp_date('l', strtotime($date)) ).' • '.esc_html( wp_date('j. n. Y', strtotime($date)) );
        if ($date === wp_date('Y-m-d')){ $heading .= ' <span class="hsp-badge">Dnes</span>'; }
        $heading .= '</h4>';
        echo $heading;
        echo '<div class="hsp-body">';
        if (!empty($soup['title'])){
            echo '<div class="hsp-soup hsp-grid"'
               . '><span class="hsp-title"><strong>Polévka:</strong> '.esc_html($soup['title']).'</span>';
            if ($show_soup_price && !empty($soup['price'])) echo '<span class="hsp-price">'.esc_html($soup['price']).' Kč</span>';
            echo '</div>';
        }
        if (!empty($mains) && is_array($mains)){
            if ($use_groups && !empty($menu_groups)) {
                $grouped_mains = $this->group_mains_by_menu($mains, $menu_groups);
                echo '<div class="hsp-menu-groups">';
                foreach ($menu_groups as $group) {
                    $group_key = isset($group['key']) ? sanitize_key($group['key']) : '';
                    if ($group_key === '') {
                        continue;
                    }
                    $items = $grouped_mains[$group_key] ?? [];
                    if (empty($items)) {
                        continue;
                    }
                    $label = isset($group['label']) ? (string)$group['label'] : '';
                    if ($label === '') {
                        $label = strtoupper($group_key);
                    }
                    $price_label = isset($group['price']) ? (string)$group['price'] : '';
                    echo '<div class="hsp-menu-group">';
                    echo '<div class="hsp-menu-group__title">'.esc_html($label);
                    if ($price_label !== '') {
                        echo '<span class="hsp-menu-group__price">'.esc_html($price_label).'</span>';
                    }
                    echo '</div>';
                    echo '<ul class="hsp-menu-group__list">';
                    foreach ($items as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $title = esc_html($row['title'] ?? '');
                        $sidesText = '';
                        if ($use_sides && !empty($row['sides'])) {
                            $names = [];
                            foreach ($row['sides'] as $side_id) {
                                $side_id = (int)$side_id;
                                if ($side_id && isset($sides_map[$side_id])) {
                                    $names[] = $sides_map[$side_id];
                                }
                            }
                            $names = array_filter($names);
                            if (!empty($names)) {
                                $sidesText = '<small class="hsp-sides">('.esc_html(implode(', ', $names)).')</small>';
                            }
                        }
                        echo '<li class="hsp-menu-group__item"><span class="hsp-title">'.$title.'</span>'.$sidesText.'</li>';
                    }
                    echo '</ul>';
                    echo '</div>';
                }
                echo '</div>';
            } else {
                echo '<ul class="hsp-mains">';
                foreach($mains as $row){
                    if (!is_array($row)) {
                        continue;
                    }
                    $title = esc_html($row['title'] ?? '');
                    $price = !empty($row['price']) ? '<span class="hsp-price">'.esc_html($row['price']).' Kč</span>' : '';
                    $sidesText = '';
                    if ($use_sides && !empty($row['sides'])){
                        $names = [];
                        foreach ($row['sides'] as $side_id) {
                            $side_id = (int)$side_id;
                            if ($side_id && isset($sides_map[$side_id])) {
                                $names[] = $sides_map[$side_id];
                            }
                        }
                        $names = array_filter($names);
                        if (!empty($names)) $sidesText = '<small class="hsp-sides">('.esc_html(implode(', ',$names)).')</small>';
                    }
                    echo '<li class="hsp-item hsp-grid"'
                        . '><span class="hsp-title">'.$title.'</span>'
                        . $sidesText
                        . $price
                        . '</li>';
                }
                echo '</ul>';
            }
        }
        echo '</div>'; // .hsp-body
        echo '</div>';
        return ob_get_clean();
    }

    private function render_static_menu_block(): string {
        $items = $this->get_static_menu_items();
        if (empty($items)) {
            return '';
        }

        $sides_map = $this->get_sides_data()['map'];

        ob_start();
        echo '<div class="hsp-static">';
        echo '<h4 class="hsp-static__title">Stálá nabídka</h4>';
        echo '<ul class="hsp-static__list">';
        foreach ($items as $item) {
            $title = trim((string)($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $price = trim((string)($item['price'] ?? ''));
            $price_html = $price !== '' ? '<span class="hsp-price">' . esc_html($price) . ' Kč</span>' : '';

            $sides_html = '';
            if (!empty($item['sides']) && is_array($item['sides'])) {
                $names = [];
                foreach ($item['sides'] as $side_id) {
                    $side_id = (int)$side_id;
                    if ($side_id && isset($sides_map[$side_id])) {
                        $names[] = $sides_map[$side_id];
                    }
                }
                $names = array_values(array_filter($names, static function ($name) {
                    return $name !== '';
                }));
                if (!empty($names)) {
                    $sides_html = '<span class="hsp-sides">' . esc_html(implode(', ', $names)) . '</span>';
                }
            }

            echo '<li class="hsp-item hsp-grid">';
            echo '<span class="hsp-title">' . esc_html($title) . '</span>';
            if ($sides_html !== '') {
                echo $sides_html;
            }
            if ($price_html !== '') {
                echo $price_html;
            }
            echo '</li>';
        }
        echo '</ul>';
        echo '</div>';

        return ob_get_clean();
    }

    /**
     * Shortcode [poledni_menu] – den nebo celý týden
     * Použití:
     *  - [poledni_menu]                → dnešní den
     *  - [poledni_menu date="2025-08-26"] → konkrétní den
     *  - [poledni_menu week="1"]         → aktuální týden (Po–Pá)
     *  - [poledni_menu week="1" week_start="2025-08-25"] → týden od zadaného pondělí
     *  - [poledni_menu week="1" full="1"]   → zobrazit rovnou celý týden
     */
    public function shortcode_menu($atts = []){
        $a = shortcode_atts([
            'date'       => '',
            'week'       => '0',
            'week_start' => '',
            'heading'    => '',
            'full'       => '', // NEW: force full-week view when truthy (1,true,yes,ano)
        ], $atts, 'poledni_menu');

        // Pokud je požadován týden
        $is_week = in_array(strtolower($a['week']), ['1','true','yes','ano'], true);
        if ($is_week){
            // GET override: menu_week=YYYY-mm-dd (monday)
            $week_override = isset($_GET['menu_week']) ? sanitize_text_field($_GET['menu_week']) : '';
            if ($week_override && preg_match('/^\d{4}-\d{2}-\d{2}$/', $week_override)) {
                $a['week_start'] = $week_override;
            }
            $start = $a['week_start'] ?: date('Y-m-d');
            $ts = strtotime($start);
            $dow = (int) wp_date('N', $ts); // 1 = Mon
            $monday = wp_date('Y-m-d', strtotime('-'.($dow-1).' days', $ts));
            // expanded only if full=1 or view=full
            $attr_full = in_array(strtolower((string)$a['full']), ['1','true','yes','ano'], true);
            $get_full  = isset($_GET['full']) && in_array(strtolower((string)$_GET['full']), ['1','true','yes','ano'], true);
            $start_expanded = $attr_full || $get_full || (isset($_GET['view']) && $_GET['view'] === 'full');
            ob_start();
            echo $this->inline_css_tag();
            echo '<div id="hsp-menu"></div>';
            echo '<div class="hsp-root">';

            // COLLAPSED: zobrazíme jen jeden den (dnes, pokud spadá do zvoleného týdne; jinak pondělí)
            $today = wp_date('Y-m-d');
            $today_ts = strtotime($today);
            $week_start_ts = strtotime($monday);
            $week_end_ts = strtotime('+4 days', $week_start_ts);
            $collapsed_date = ($today_ts >= $week_start_ts && $today_ts <= $week_end_ts) ? $today : $monday;

            echo '<div class="hsp-collapsed'.($start_expanded?' hsp-hidden':'').'">';
            $collapsed_html = $this->render_day_menu_html($collapsed_date);
            if ($collapsed_html){
                echo $collapsed_html;
            } else {
                echo '<div class="hsp-day">';
                echo '<h4 class="hsp-day__heading">'.esc_html( wp_date('l', strtotime($collapsed_date)) ).' • '.esc_html( wp_date('j. n. Y', strtotime($collapsed_date)) ).'</h4>';
                echo '<div class="hsp-body"><p><em>Menu zatím není vyplněno.</em></p></div>';
                echo '</div>';
            }
            echo '</div>'; // .hsp-collapsed
            $btn_label = $start_expanded ? 'Skrýt celý týden' : 'Zobrazit celý týden';
            $btn_aria  = $start_expanded ? 'true' : 'false';
            echo '<script>(function(w){w.HSP_MENU={ajax:"'.esc_url( admin_url('admin-ajax.php') ).'", nonce:"'.esc_js( wp_create_nonce('hsp_menu') ).'", forceFull:"'.(($attr_full||$get_full)?'1':'0').'"};})(window);</script>';
            echo '<p style="text-align:center;margin:12px 0 16px"><button type="button" class="hsp-toggle" aria-expanded="'.$btn_aria.'" data-target="#hsp-week-full">'.$btn_label.'</button></p>';

            // FULL WEEK NAV + GRID (zatím skryto)
            $prev_monday = wp_date('Y-m-d', strtotime('-7 days', strtotime($monday)));
            $next_monday = wp_date('Y-m-d', strtotime('+7 days', strtotime($monday)));
            $q_prev = ['menu_week'=>$prev_monday];
            $q_next = ['menu_week'=>$next_monday];
            if ($attr_full || $get_full) { $q_prev['full']='1'; $q_next['full']='1'; }
            $prev_url = esc_url( add_query_arg($q_prev) . '#hsp-menu' );
            $next_url = esc_url( add_query_arg($q_next) . '#hsp-menu' );

            echo '<div id="hsp-week-full" class="'.($start_expanded?'':'hsp-hidden').'">';
            echo '<form class="hsp-week__nav" method="get" action="#hsp-menu">';
            foreach ($_GET as $k=>$v){ if ($k==='menu_week') continue; echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr($v).'">'; }
            if ($attr_full && !$get_full) { echo '<input type="hidden" name="full" value="1">'; }
            echo '<button type="button" class="hsp-nav__btn hsp-nav__prev" data-url="'.$prev_url.'" aria-label="Minulý týden">&laquo; Minulý</button>';
            echo '<label class="hsp-nav__label">Týden od: <input class="hsp-nav__date" type="date" name="menu_week" value="'.esc_attr($monday).'" onchange="this.form.submit()"></label>';
            echo '<button type="button" class="hsp-nav__btn hsp-nav__next" data-url="'.$next_url.'" aria-label="Další týden">Další &raquo;</button>';
            echo '</form>';

            echo '<div class="hsp-week">';
            if (!empty($a['heading'])){
                echo '<h3 class="hsp-week__title">'.esc_html($a['heading']).'</h3>';
            }
            for ($i = 0; $i < 5; $i++){
                $date_i = wp_date('Y-m-d', strtotime("+{$i} days", strtotime($monday)));
                $html_i = $this->render_day_menu_html($date_i);
                if ($html_i){ echo $html_i; }
            }
            echo '</div>';        // close .hsp-week
            echo '</div>';        // close #hsp-week-full
            $static_html = $this->render_static_menu_block();
            if ($static_html) {
                echo $static_html;
            }
            echo '</div>';        // close .hsp-root
            // Improved JS for toggle button
            echo '<script>(function(){var b=document.querySelector(".hsp-toggle");if(!b)return;var full=document.querySelector(b.getAttribute("data-target"));if(!full)return;var coll=document.querySelector(".hsp-collapsed");b.addEventListener("click",function(){var isHidden=full.classList.toggle("hsp-hidden");var expanded=!isHidden;if(coll){coll.classList.toggle("hsp-hidden", expanded);}b.setAttribute("aria-expanded", expanded?"true":"false");b.textContent=expanded?"Skrýt celý týden":"Zobrazit celý týden";if(expanded){setTimeout(function(){full.scrollIntoView({behavior:"smooth",block:"start"});},10);}});})();</script>';
            // On-load helper to scroll to anchor if landed with hash
            echo '<script>(function(){if(location.hash==="#hsp-menu"){var el=document.getElementById("hsp-menu");if(el){setTimeout(function(){el.scrollIntoView({behavior:"auto",block:"start"});},0);}}})();</script>';
            echo '<script>(function(){
var R=document.querySelector(".hsp-root"); if(!R||!window.HSP_MENU) return;
var full=document.getElementById("hsp-week-full"); if(!full) return;
var grid=full.querySelector(".hsp-week"); if(!grid) return;
var form=full.querySelector(".hsp-week__nav"); if(!form) return;
var dateEl=form.querySelector(".hsp-nav__date");
var prevBtn=form.querySelector(".hsp-nav__prev");
var nextBtn=form.querySelector(".hsp-nav__next");
function ymd(d){return d.toISOString().slice(0,10);} // YYYY-MM-DD
function addDays(dateStr, days){ var d=new Date(dateStr+"T12:00:00"); d.setDate(d.getDate()+days); return ymd(d); }
function setLoading(on){ grid.style.opacity= on? .5: 1; }
function updateURL(newMonday){ try{ var u=new URL(location.href); u.searchParams.set("menu_week", newMonday); if (window.HSP_MENU && HSP_MENU.forceFull==="1"){ u.searchParams.set("full","1"); } history.replaceState(null, "", u.toString()+"#hsp-menu"); }catch(e){} }
async function fetchWeek(weekDate){
  setLoading(true);
  try{
    var res = await fetch(HSP_MENU.ajax, { method:"POST", headers:{"Content-Type":"application/x-www-form-urlencoded"}, body: new URLSearchParams({ action:"hsp_get_week", menu_week: weekDate, nonce: HSP_MENU.nonce }) });
    var data = await res.json();
    if(!data || !data.success){ throw new Error((data&&data.data&&data.data.message)||"ERR"); }
    grid.innerHTML = data.data.html;
    if (dateEl) dateEl.value = data.data.monday; // normalize to monday
    updateURL(data.data.monday);
    // ensure expanded view is visible after update
    var coll=document.querySelector(".hsp-collapsed");
    if (coll) coll.classList.add("hsp-hidden");
    full.classList.remove("hsp-hidden");
  }catch(e){ console.error(e); }
  setLoading(false);
}
if (prevBtn){ prevBtn.addEventListener("click", function(e){ e.preventDefault(); if(!dateEl||!dateEl.value) return; fetchWeek( addDays(dateEl.value, -7) ); }); }
if (nextBtn){ nextBtn.addEventListener("click", function(e){ e.preventDefault(); if(!dateEl||!dateEl.value) return; fetchWeek( addDays(dateEl.value, +7) ); }); }
if (dateEl){ dateEl.addEventListener("change", function(e){ e.preventDefault(); if(!dateEl.value) return; fetchWeek(dateEl.value); }); }
})();</script>';
            return ob_get_clean();
        }

        // Jinak jeden den – dnes nebo zadané datum
        $date = $a['date'] ?: date('Y-m-d');
        $html = $this->render_day_menu_html($date);
        if ($html){
            $wrap = $this->inline_css_tag();
            $wrap .= '<div class="hsp-root"><div class="hsp-single">';
            if (!empty($a['heading'])) $wrap .= '<h3 class="hsp-single__title">'.esc_html($a['heading']).'</h3>';
            $wrap .= $html;
            $static_html = $this->render_static_menu_block();
            if ($static_html) {
                $wrap .= $static_html;
            }
            $wrap .= '</div></div>';
            return $wrap;
        }
        return '<p><em>Dnešní menu není nastaveno.</em></p>';
    }

    public function ajax_get_week(){
        // Security check
        if ( ! isset($_POST['nonce']) || ! wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'hsp_menu') ){
            wp_send_json_error(['message'=>'Invalid nonce'], 403);
        }
        $req = isset($_POST['menu_week']) ? sanitize_text_field($_POST['menu_week']) : '';
        if (!$req || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $req)){
            wp_send_json_error(['message'=>'Bad date']);
        }
        $ts  = strtotime($req);
        $dow = (int) wp_date('N', $ts); // 1=Mon..7=Sun
        $mon = wp_date('Y-m-d', strtotime('-'.($dow-1).' days', $ts));

        ob_start();
        for ($i = 0; $i < 5; $i++){
            $date_i = wp_date('Y-m-d', strtotime("+{$i} days", strtotime($mon)));
            $html_i = $this->render_day_menu_html($date_i);
            if ($html_i){ echo $html_i; }
        }
        $html = ob_get_clean();

        wp_send_json_success([
            'html'   => $html,
            'monday' => $mon,
        ]);
    }
}

new Hospoda_Plugin();