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
const CPT_ORDER  = 'hsp_order';   // objednávky
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
        add_action('admin_post_hsp_order_export_delivery_csv', [$this,'handle_order_export_delivery_csv']);
        add_action('admin_post_hsp_order_export_kitchen_csv', [$this,'handle_order_export_kitchen_csv']);
        add_action('admin_post_hsp_order_update_status', [$this,'handle_order_status_update']);
        add_shortcode('poledni_menu', [$this,'shortcode_menu']);
        add_shortcode('hsp_order_form', [$this,'shortcode_order_form']);
        add_shortcode('hsp_my_orders', [$this,'shortcode_my_orders']);
        add_action('add_meta_boxes', [$this,'add_day_metabox']);
        add_filter('manage_'.CPT_DAY.'_posts_columns', [$this,'day_columns']);
        add_action('manage_'.CPT_DAY.'_posts_custom_column', [$this,'day_columns_content'], 10, 2);
        add_action('wp_enqueue_scripts', [$this,'frontend_assets'], 9999);
        add_action('wp_head', [$this,'frontend_inline_probe'], 1000);
        add_action('wp_print_styles', [$this,'frontend_assets'], 9999);
        add_action('wp_ajax_hsp_get_week', [$this,'ajax_get_week']);
        add_action('wp_ajax_nopriv_hsp_get_week', [$this,'ajax_get_week']);
        add_action('wp_ajax_hsp_submit_order', [$this,'ajax_submit_order']);
        add_action('wp_ajax_nopriv_hsp_submit_order', [$this,'ajax_submit_order']);
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

        register_post_type(CPT_ORDER, [
            'labels' => [
                'name' => 'Objednávky',
                'singular_name' => 'Objednávka',
                'menu_name' => 'Objednávky',
            ],
            'public' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'supports' => ['title'],
        ]);

        register_post_status('hsp_new', ['label' => 'Nová', 'public' => false, 'show_in_admin_all_list' => true, 'show_in_admin_status_list' => true, 'label_count' => _n_noop('Nová <span class="count">(%s)</span>', 'Nová <span class="count">(%s)</span>')]);
        register_post_status('hsp_confirmed', ['label' => 'Potvrzená', 'public' => false, 'show_in_admin_all_list' => true, 'show_in_admin_status_list' => true, 'label_count' => _n_noop('Potvrzená <span class="count">(%s)</span>', 'Potvrzená <span class="count">(%s)</span>')]);
        register_post_status('hsp_in_kitchen', ['label' => 'V kuchyni', 'public' => false, 'show_in_admin_all_list' => true, 'show_in_admin_status_list' => true, 'label_count' => _n_noop('V kuchyni <span class="count">(%s)</span>', 'V kuchyni <span class="count">(%s)</span>')]);
        register_post_status('hsp_out_for_delivery', ['label' => 'Na rozvozu', 'public' => false, 'show_in_admin_all_list' => true, 'show_in_admin_status_list' => true, 'label_count' => _n_noop('Na rozvozu <span class="count">(%s)</span>', 'Na rozvozu <span class="count">(%s)</span>')]);
        register_post_status('hsp_done', ['label' => 'Hotovo', 'public' => false, 'show_in_admin_all_list' => true, 'show_in_admin_status_list' => true, 'label_count' => _n_noop('Hotovo <span class="count">(%s)</span>', 'Hotovo <span class="count">(%s)</span>')]);
        register_post_status('hsp_cancelled', ['label' => 'Zrušeno', 'public' => false, 'show_in_admin_all_list' => true, 'show_in_admin_status_list' => true, 'label_count' => _n_noop('Zrušeno <span class="count">(%s)</span>', 'Zrušeno <span class="count">(%s)</span>')]);
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


        if ($this->is_ordering_enabled()) {
            add_submenu_page(
                'hospoda-week',
                'Objednávky',
                'Objednávky',
                'edit_posts',
                'hospoda-orders',
                [$this,'render_orders_admin_page']
            );
        }
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
        $is_branding_page = (strpos((string) $hook, 'hospoda-week-branding') !== false);
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
            $price_placeholder = $this->get_price_placeholder_label();
            $menu_price_hint = $this->get_menu_price_hint();
            wp_localize_script('jquery-ui-autocomplete', 'HOSPOS', [
                'nonce'            => wp_create_nonce('hospoda_meal_search'),
                'currency'         => $this->get_currency_label(),
                'pricePlaceholder' => $price_placeholder,
                'menuPriceHint'    => $menu_price_hint,
            ]);
            $js = <<<'JS'
        (function($){
          function escapeRegExp(str){
            return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
          }

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
            var pricePlaceholder = (window.HOSPOS && window.HOSPOS.pricePlaceholder) ? window.HOSPOS.pricePlaceholder : 'Cena';
            var tmpl = ''+
              '<div class="row main">\n'+
              '  <input class="meal-autocomplete" name="mains['+idx+'][title]" type="text" placeholder="Název jídla…" value="">\n'+
              '  <input class="meal-id" type="hidden" name="mains['+idx+'][id]" value="">\n'+
              '  <input class="meal-allergens" type="hidden" name="mains['+idx+'][allergens]" value="">\n'+
              '  <input class="price" type="text" name="mains['+idx+'][price]" placeholder="'+pricePlaceholder+'" value="">\n'+
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

          function formatMenuPriceLabel(value){
            var raw = $.trim(value || '');
            if (!raw){
              return '';
            }
            var lower = raw.toLowerCase();
            var currency = '';
            if (window.HOSPOS && typeof window.HOSPOS.currency === 'string'){
              currency = $.trim(window.HOSPOS.currency);
            }
            if (currency){
              try {
                var matcher = new RegExp(escapeRegExp(currency), 'i');
                if (matcher.test(raw)){
                  return raw;
                }
              } catch (err) {
                // ignore invalid patterns and fall back to default checks
              }
            }
            if (/[€$£]/.test(raw) || lower.indexOf('kč') !== -1 || lower.indexOf('czk') !== -1 || lower.indexOf('eur') !== -1 || lower.indexOf('usd') !== -1){
              return raw;
            }
            if (!/\d/.test(raw)){
              return raw;
            }
            if (!currency){
              return raw;
            }
            return raw.replace(/\s+$/,'') + ' ' + currency;
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
            var displayPrice = formatMenuPriceLabel(price);
            if (displayPrice){
              if (!$priceEl.length){
                $priceEl = $('<span class="hs-week-menu-group__price"></span>').appendTo($header);
              }
              $priceEl.text(displayPrice);
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
            var pricePlaceholder = (window.HOSPOS && window.HOSPOS.pricePlaceholder) ? window.HOSPOS.pricePlaceholder : 'Cena';
            var priceField = includePrice ? '  <input class="price" type="text" name="week[mains]['+idx+']['+rowKey+'][price]" placeholder="'+pricePlaceholder+'" value="">\n' : '';
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
            var pricePlaceholder = (window.HOSPOS && window.HOSPOS.pricePlaceholder) ? window.HOSPOS.pricePlaceholder : 'Cena';
            var tmpl = ''+
              '<div class="row main" data-static-index="'+idx+'">\n'+
              '  <input class="meal-autocomplete" name="static_menu['+idx+'][title]" type="text" placeholder="Název jídla…" value="">\n'+
              '  <input class="meal-id" type="hidden" name="static_menu['+idx+'][id]" value="">\n'+
              '  <input class="meal-allergens" type="hidden" name="static_menu['+idx+'][allergens]" value="">\n'+
              '  <input class="price" type="text" name="static_menu['+idx+'][price]" placeholder="'+pricePlaceholder+'" value="">\n'+
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
            var priceHint = (window.HOSPOS && window.HOSPOS.menuPriceHint) ? window.HOSPOS.menuPriceHint : 'Např. 139';
            var configHtml = ''+
              '<div class="hs-week-day-group-config" data-group-key="'+key+'" data-index="'+next+'">\n'+
              '  <input type="hidden" name="week[menu_groups]['+dayIndex+']['+next+'][key]" value="'+key+'">\n'+
              '  <label>Název menu\n    <input type="text" class="regular-text js-day-group-label" name="week[menu_groups]['+dayIndex+']['+next+'][label]" value="" placeholder="'+labelPlaceholder+'">\n  </label>\n'+
              '  <label>Cena / popisek\n    <input type="text" class="regular-text js-day-group-price" name="week[menu_groups]['+dayIndex+']['+next+'][price]" value="" placeholder="'+priceHint+'">\n  </label>\n'+
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
            var priceHint = (window.HOSPOS && window.HOSPOS.menuPriceHint) ? window.HOSPOS.menuPriceHint : 'Např. 139';
            var tmpl = ''+
              '<div class="hs-menu-group" data-index="'+next+'">\n'+
              '  <input type="hidden" name="menu_groups['+next+'][key]" value="'+key+'">\n'+
              '  <label>Název menu\n    <input type="text" name="menu_groups['+next+'][label]" value="">\n  </label>\n'+
              '  <label>Cena / popisek\n    <input type="text" name="menu_groups['+next+'][price]" value="" placeholder="'+priceHint+'">\n  </label>\n'+
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

            $branding_js = plugin_dir_path(__FILE__) . 'assets/js/branding.js';
            $branding_ver = file_exists($branding_js) ? filemtime($branding_js) : VERSION;
            wp_register_script(
                'hospoda-branding',
                plugins_url('assets/js/branding.js', __FILE__),
                ['jquery', 'wp-color-picker'],
                $branding_ver,
                true
            );
            wp_enqueue_script('hospoda-branding');
            $css3 = '.hs-branding{margin:20px 0;padding:20px;border:1px solid #d0d0d0;border-radius:6px;background:#fff;max-width:960px}.hs-branding h2{margin-top:0}.hs-branding__logo{display:flex;align-items:flex-start;gap:12px;margin-bottom:12px}.hs-branding__preview{width:160px;min-height:120px;border:1px dashed #ccd0d4;border-radius:4px;display:flex;align-items:center;justify-content:center;background:#fafafa;overflow:hidden}.hs-branding__preview img{max-width:100%;height:auto;display:block}.hs-branding__preview span{color:#777;font-style:italic}.hs-branding textarea{max-width:100%}.hs-branding .description{margin-top:4px;color:#555}.hs-branding__controls{display:flex;flex-direction:column;gap:8px}.hs-branding__static{margin-top:24px;padding-top:16px;border-top:1px solid #d8d8d8}.hs-branding__static h2{margin:0 0 6px;font-size:18px}.hs-branding__menu-groups{margin-top:20px;padding:16px;border:1px solid #d9dde8;border-radius:8px;background:#f8fafc}.hs-branding__menu-groups.is-hidden{display:none}.hs-branding__currency{margin-top:24px;padding:16px;border:1px solid #d9dde8;border-radius:8px;background:#f8fafc}.hs-branding__currency label{font-weight:600;color:#334155}.hs-menu-groups{display:flex;flex-direction:column;gap:12px;margin-top:12px}.hs-menu-group{display:flex;flex-wrap:wrap;gap:12px;padding:12px;border:1px solid #e5e7eb;border-radius:6px;background:#fff}.hs-menu-group label{display:flex;flex-direction:column;flex:1 1 220px;font-weight:600;font-size:13px;color:#334155}.hs-menu-group label input[type=text]{margin-top:4px}.hs-menu-group-remove{margin-left:auto}.hs-menu-groups__actions{margin-top:10px}.hs-static-menu{display:flex;flex-direction:column;gap:14px;margin-top:12px}.hs-static-menu .row{display:flex;flex-wrap:wrap;gap:12px;padding:14px;border:1px solid #e5e7eb;border-radius:8px;background:#fafafa}.hs-static-menu .row input.meal-autocomplete{flex:1 1 260px;min-width:220px}.hs-static-menu .row input.price{width:110px}.hs-static-menu .sides{display:flex;flex-wrap:wrap;gap:8px}.hs-static-menu .sides label{margin:0;padding:4px 10px;border:1px solid #d5d7db;border-radius:4px;background:#fff;font-size:13px}.hs-static-menu .remove-row{margin-left:auto}.hs-static-actions{margin-top:12px}';
            $css3 .= '.hs-branding__theme{margin-top:24px;padding-top:20px;border-top:1px solid #d8d8d8;display:flex;flex-direction:column;gap:20px}.hs-branding__theme-grid{display:grid;gap:18px}.hs-branding__theme-group{border:1px solid #e2e8f0;border-radius:8px;padding:16px 18px;background:#f9fafb;display:flex;flex-direction:column;gap:12px}.hs-branding__theme-group h3{margin:0;font-size:16px;color:#0f172a}.hs-branding__field{display:flex;flex-direction:column;gap:4px}.hs-branding__field label{font-weight:600;font-size:13px;color:#334155}.hs-branding__field input[type=text]{max-width:170px}.hs-branding__field input[type=number]{max-width:120px}.hs-branding__field select{max-width:200px}.hs-branding__field .description{margin:0;font-size:12px;color:#64748b}.hs-color-palette{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}.hs-color-swatch{--hs-swatch-color:#000;width:34px;height:34px;padding:0;border-radius:4px;border:1px solid #cbd5e1;background:var(--hs-swatch-color);box-shadow:inset 0 0 0 1px rgba(255,255,255,.6);cursor:pointer;position:relative}.hs-color-swatch:hover{box-shadow:0 0 0 2px rgba(37,99,235,.4)}.hs-color-swatch.is-active{box-shadow:0 0 0 3px rgba(37,99,235,.8)}.hs-color-swatch:focus{outline:2px solid #2563eb;outline-offset:2px}';
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
            'soup_price_mode'      => 'included',
            'sides_mode'           => 'taxonomy',
            'pricing_mode'         => 'per_item',
            'menu_groups'          => [],
            'currency_label'       => 'Kč',
            'day_switch_hour'      => 12,
            'weekend_rollover_day' => 6,
        ];
        $prefs = wp_parse_args($stored, $defaults);
        $prefs['soup_price_mode'] = $this->normalize_soup_price_mode($prefs['soup_price_mode'] ?? '');
        $prefs['sides_mode'] = $this->normalize_sides_mode((string)($prefs['sides_mode'] ?? ''));
        $prefs['pricing_mode'] = $this->normalize_pricing_mode((string)($prefs['pricing_mode'] ?? ''));
        $prefs['menu_groups'] = $this->sanitize_menu_groups($prefs['menu_groups'] ?? []);
        $prefs['currency_label'] = $this->sanitize_currency_label($prefs['currency_label'] ?? '');
        $prefs['day_switch_hour'] = $this->sanitize_day_switch_hour($prefs['day_switch_hour'] ?? 12);
        $prefs['weekend_rollover_day'] = $this->sanitize_weekend_rollover_day($prefs['weekend_rollover_day'] ?? 6);

        $this->menu_preferences_cache = $prefs;

        return $prefs;
    }

    private function normalize_soup_price_mode(string $value): string {
        return in_array($value, ['included', 'separate'], true) ? $value : 'included';
    }


    private function get_order_settings_defaults(): array {
        return [
            'enabled' => 0,
            'require_login' => 1,
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

    private function sanitize_currency_label($value): string {
        if (!is_string($value)) {
            return 'Kč';
        }

        $clean = sanitize_text_field($value);
        $clean = preg_replace('/\s+/u', ' ', trim($clean));
        if ($clean === null || $clean === '') {
            return 'Kč';
        }

        return $clean;
    }

    private function sanitize_day_switch_hour($value): int {
        if (is_string($value) && $value !== '') {
            $value = trim($value);
        }

        $hour = is_numeric($value) ? (int) $value : 12;
        if ($hour < 0) {
            $hour = 0;
        }
        if ($hour > 23) {
            $hour = 23;
        }

        return $hour;
    }

    private function sanitize_weekend_rollover_day($value): int {
        if (is_string($value) && $value !== '') {
            $value = trim($value);
        }

        $day = is_numeric($value) ? (int) $value : 6;
        if ($day < 1) {
            $day = 1;
        }
        if ($day > 7) {
            $day = 7;
        }

        return $day;
    }

    private function get_currency_label(): string {
        $prefs = $this->get_menu_preferences();
        $label = isset($prefs['currency_label']) ? (string)$prefs['currency_label'] : '';
        $label = trim($label);
        if ($label === '') {
            $label = 'Kč';
        }
        return $label;
    }

    private function get_day_switch_hour(): int {
        $prefs = $this->get_menu_preferences();
        $hour = isset($prefs['day_switch_hour']) ? (int) $prefs['day_switch_hour'] : 12;
        if ($hour < 0 || $hour > 23) {
            $hour = 12;
        }

        return $hour;
    }

    private function get_weekend_rollover_day(): int {
        $prefs = $this->get_menu_preferences();
        $day = isset($prefs['weekend_rollover_day']) ? (int) $prefs['weekend_rollover_day'] : 6;
        if ($day < 1 || $day > 7) {
            $day = 6;
        }

        return $day;
    }

    private function get_effective_today_datetime(): \DateTimeImmutable {
        $timezone = wp_timezone();
        if (!$timezone instanceof \DateTimeZone) {
            $timezone = new \DateTimeZone('UTC');
        }

        $now = new \DateTimeImmutable('now', $timezone);
        $hour = $this->get_day_switch_hour();
        $after_cutoff = $this->is_after_day_switch_hour($now, $hour);
        $current_dow = (int) $now->format('N');

        $effective = $now;
        if ($after_cutoff) {
            $effective = $effective->modify('+1 day');
        }

        $weekend_day = $this->get_weekend_rollover_day();
        if ($this->should_roll_to_next_monday($effective, $weekend_day, $after_cutoff, $current_dow)) {
            $effective = $this->move_to_next_monday($effective);
        }

        return $effective;
    }

    private function is_after_day_switch_hour(\DateTimeImmutable $moment, int $hour): bool {
        if ($hour < 0 || $hour > 23) {
            return false;
        }

        $cutoff = $moment->setTime($hour, 0, 0);

        return $moment >= $cutoff;
    }

    private function should_roll_to_next_monday(\DateTimeImmutable $candidate, int $weekend_day, bool $after_cutoff, int $current_dow): bool {
        if ($weekend_day < 1 || $weekend_day > 7) {
            $weekend_day = 6;
        }

        $dow = (int) $candidate->format('N');
        if ($dow > $weekend_day) {
            return true;
        }

        if ($dow === $weekend_day) {
            if ($weekend_day === 6) {
                return true;
            }

            if ($current_dow === $weekend_day) {
                return $after_cutoff;
            }

            return false;
        }

        return false;
    }

    private function move_to_next_monday(\DateTimeImmutable $candidate): \DateTimeImmutable {
        $dow = (int) $candidate->format('N');
        if ($dow === 1) {
            return $candidate;
        }

        $days_to_add = 8 - $dow;
        return $candidate->modify('+' . $days_to_add . ' days');
    }

    private function price_contains_currency(string $price, string $currency): bool {
        if ($currency !== '') {
            $pattern = '/' . preg_quote($currency, '/') . '/iu';
            if (preg_match($pattern, $price)) {
                return true;
            }
        }

        return (bool) preg_match('/kč|czk|€|eur|usd|\$|£/iu', $price);
    }

    private function format_price_for_display(string $price): string {
        $price = trim($price);
        if ($price === '') {
            return '';
        }

        $currency = $this->get_currency_label();
        if ($currency === '' || !$this->contains_digit($price)) {
            return $price;
        }

        if ($this->price_contains_currency($price, $currency)) {
            return $price;
        }

        return rtrim($price) . ' ' . $currency;
    }

    private function get_price_placeholder_label(): string {
        $currency = $this->get_currency_label();
        return $currency !== '' ? sprintf('Cena (%s)', $currency) : 'Cena';
    }

    private function get_menu_price_hint(): string {
        $currency = $this->get_currency_label();
        return $currency !== '' ? sprintf('Např. 139 %s', $currency) : 'Např. 139';
    }

    private function contains_digit(string $value): bool {
        return (bool) preg_match('/\d/u', $value);
    }

    private function format_menu_group_price_display(string $price): string {
        return $this->format_price_for_display($price);
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
        $price_placeholder = $this->get_price_placeholder_label();
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
              <input class="price" type="text" name="soup[price]" placeholder="<?php echo esc_attr($price_placeholder); ?>" value="<?php echo esc_attr($data['soup']['price'] ?? ''); ?>">
            </div>
            <div id="mains" class="hs-mains">
              <?php
              if (!empty($data['mains'])) {
                  foreach ($data['mains'] as $i=>$row) $this->render_main_row($sides,$row,$i,$price_placeholder);
              } else {
                  $this->render_main_row($sides,[] ,0,$price_placeholder);
              }
              ?>
            </div>
            <p><button type="button" class="button" id="add-row">Přidat jídlo</button></p>
            <p><button class="button button-primary">Uložit menu</button></p>
          </form>
        </div>
        <?php
    }

    private function render_main_row($sides,$row,$i,$price_placeholder) {
        ?>
        <div class="row main">
          <input class="meal-autocomplete" name="mains[<?php echo esc_attr($i); ?>][title]" type="text" placeholder="Název jídla…" value="<?php echo esc_attr($row['title'] ?? ''); ?>">
          <input class="meal-id" type="hidden" name="mains[<?php echo esc_attr($i); ?>][id]" value="<?php echo esc_attr($row['id'] ?? ''); ?>">
          <input class="meal-allergens" type="hidden" name="mains[<?php echo esc_attr($i); ?>][allergens]" value="<?php echo esc_attr($this->format_allergens_field($row['allergens'] ?? [])); ?>">
          <input class="price" type="text" name="mains[<?php echo esc_attr($i); ?>][price]" placeholder="<?php echo esc_attr($price_placeholder); ?>" value="<?php echo esc_attr($row['price'] ?? ''); ?>">
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
        $soup_title_raw = sanitize_text_field($soup_in['title'] ?? '');
        $soup_price = sanitize_text_field($soup_in['price'] ?? '');
        $soup_allergens = $this->sanitize_allergen_list($soup_in['allergens'] ?? []);
        [$soup_id, $soup_title] = $this->resolve_meal_variant($soup_id, $soup_title_raw, $soup_price, $soup_allergens, [], false);
        $soup = [
            'id'        => $soup_id,
            'title'     => $soup_title,
            'price'     => $soup_price,
            'allergens' => $soup_allergens,
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
            $title_raw = sanitize_text_field($row['title'] ?? '');
            $price = sanitize_text_field($row['price'] ?? '');
            $allergens = $this->sanitize_allergen_list($row['allergens'] ?? []);
            $sides_list = array_values(array_unique(array_map('intval',$row['sides']??[])));
            $group_key = '';
            if ($use_groups) {
                $candidate = isset($row['menu_group']) ? sanitize_key($row['menu_group']) : '';
                if ($candidate !== '' && in_array($candidate, $group_keys, true)) {
                    $group_key = $candidate;
                } else {
                    $group_key = $default_group_key;
                }
            }

            [$id, $resolved_title] = $this->resolve_meal_variant($id, $title_raw, $price, $allergens, $sides_list, $this->should_manage_sides());

            $mains[]=[
                'id'=>$id,
                'title'=>$resolved_title,
                'price'=>$price,
                'sides'=>$sides_list,
                'allergens'=>$allergens,
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

    /**
     * Pokud uživatel upraví název převzatého jídla, založí novou položku v knihovně
     * a uloží ji s původní i novou verzí.
     */
    private function resolve_meal_variant(int $id, string $input_title, string $price, array $allergens, array $sides, bool $use_sides): array {
        $input_title = trim($input_title);
        $canonical = $this->meal_title_by_id($id, '');

        // Pokud je název změněný oproti knihovně, vytvoř nový záznam a ten ulož do týdne.
        if ($canonical !== '' && $input_title !== '' && $canonical !== $input_title) {
            $cloned_id = $this->clone_meal_variant($id, $input_title, $price, $allergens, $sides, $use_sides);
            if ($cloned_id > 0) {
                return [$cloned_id, $input_title];
            }
        }

        if (!$id && $input_title !== '') {
            $new_id = $this->ensure_meal_exists($input_title);
            return [$new_id, $input_title];
        }

        if ($canonical !== '') {
            return [$id, $canonical];
        }

        return [$id, $input_title];
    }

    private function clone_meal_variant(int $source_id, string $title, string $price, array $allergens, array $sides, bool $use_sides): int {
        $title = trim($title);
        if ($title === '') {
            return 0;
        }

        $new_id = (int) wp_insert_post([
            'post_type'   => CPT_MEAL,
            'post_status' => 'publish',
            'post_title'  => $title,
        ]);

        if ($new_id <= 0) {
            return 0;
        }

        $resolved_price = $price !== '' ? $price : sanitize_text_field((string) get_post_meta($source_id, 'price', true));
        if ($resolved_price !== '') {
            update_post_meta($new_id, 'price', $resolved_price);
        }

        $resolved_allergens = !empty($allergens) ? $allergens : $this->get_meal_term_ids($source_id, TAX_ALLERGEN);
        if (!empty($resolved_allergens)) {
            wp_set_object_terms($new_id, array_map('intval', $resolved_allergens), TAX_ALLERGEN, false);
        }

        if ($use_sides) {
            $resolved_sides = !empty($sides) ? $sides : $this->get_meal_term_ids($source_id, TAX_SIDE);
            if (!empty($resolved_sides)) {
                wp_set_object_terms($new_id, array_map('intval', $resolved_sides), TAX_SIDE, false);
            }
        }

        return $new_id;
    }

    // ---------- Týdenní admin stránka ----------
    private function render_week_day_block($index,$label,$date,$sides,$data,$use_sides,$pricing_mode,$default_menu_groups){
        $soup = is_array($data['soup'] ?? null) ? $data['soup'] : [];
        $mains_raw = is_array($data['mains'] ?? null) ? $data['mains'] : [];
        $price_placeholder = $this->get_price_placeholder_label();
        $menu_price_hint = $this->get_menu_price_hint();

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
              <input class="price" type="text" name="week[soup][<?php echo esc_attr($index); ?>][price]" placeholder="<?php echo esc_attr($price_placeholder); ?>" value="<?php echo esc_attr($soup['price'] ?? ''); ?>">
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
                            <input type="text" class="regular-text js-day-group-price" name="week[menu_groups][<?php echo esc_attr($index); ?>][<?php echo esc_attr($i); ?>][price]" value="<?php echo esc_attr($group_price); ?>" placeholder="<?php echo esc_attr($menu_price_hint); ?>">
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
                  $price_display = $this->format_menu_group_price_display($price_text);

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
                      <?php if ($price_display !== '') : ?><span class="hs-week-menu-group__price"><?php echo esc_html($price_display); ?></span><?php endif; ?>
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
                          <input class="price" type="text" name="week[mains][<?php echo esc_attr($index); ?>][<?php echo esc_attr($i); ?>][price]" placeholder="<?php echo esc_attr($price_placeholder); ?>" value="<?php echo esc_attr($row['price'] ?? ''); ?>">
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
                      <input class="price" type="text" name="week[mains][<?php echo esc_attr($index); ?>][0][price]" placeholder="<?php echo esc_attr($price_placeholder); ?>" value="">
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
        $order_settings = $this->get_order_settings();
        $soup_mode = $preferences['soup_price_mode'] ?? 'included';
        $sides_mode = $preferences['sides_mode'] ?? 'taxonomy';
        $pricing_mode = $preferences['pricing_mode'] ?? 'per_item';
        $currency_label = $this->get_currency_label();
        $day_switch_hour = isset($preferences['day_switch_hour']) ? (int) $preferences['day_switch_hour'] : $this->get_day_switch_hour();
        $weekend_rollover_day = isset($preferences['weekend_rollover_day']) ? (int) $preferences['weekend_rollover_day'] : $this->get_weekend_rollover_day();
        $price_placeholder = $this->get_price_placeholder_label();
        $menu_price_hint = $this->get_menu_price_hint();
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
        $frontend_theme = $this->get_frontend_theme_settings();
        $theme_defaults = $this->get_frontend_theme_defaults();
        $week_theme = $frontend_theme['week'];
        $static_theme = $frontend_theme['static'];
        $typo_theme = $frontend_theme['typography'];
        $color_palette = [
            '#0f172a' => 'Tmavě modrá',
            '#1d4ed8' => 'Královská modrá',
            '#0ea5e9' => 'Azurová',
            '#22c55e' => 'Zelená',
            '#f97316' => 'Oranžová',
            '#ef6c00' => 'Tmavě oranžová',
            '#facc15' => 'Zlatá',
            '#f1f5f9' => 'Světle šedá',
            '#1f2937' => 'Břidlicová',
            '#ffffff' => 'Bílá',
        ];
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
        $weekend_day_options = [
            5 => 'Pátek',
            6 => 'Sobota',
            7 => 'Neděle',
            4 => 'Čtvrtek',
            3 => 'Středa',
            2 => 'Úterý',
            1 => 'Pondělí',
        ];
        $base_size_value = isset($typo_theme['base_size']) ? (int)$typo_theme['base_size'] : 16;
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
                      <input class="price" type="text" name="static_menu[<?php echo esc_attr($index); ?>][price]" placeholder="<?php echo esc_attr($price_placeholder); ?>" value="<?php echo esc_attr($item_price); ?>">
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
          <fieldset class="hs-branding__currency">
            <legend><strong>Značení měny</strong></legend>
            <label for="hs-currency-label">Text měny</label><br>
            <input type="text" id="hs-currency-label" name="currency_label" value="<?php echo esc_attr($currency_label); ?>" class="regular-text">
            <p class="description">Například „Kč“, „CZK“ nebo „EUR“. Tento text se automaticky přidá k cenám, pokud již neobsahují měnu.</p>
          </fieldset>
          <fieldset class="hs-branding__schedule">
            <legend><strong>Přepínání dnů</strong></legend>
            <label for="hs-day-switch-hour">Hodina přepnutí na další den</label><br>
            <input type="number" id="hs-day-switch-hour" name="day_switch_hour" min="0" max="23" value="<?php echo esc_attr($day_switch_hour); ?>" class="small-text"> <span class="description">Po dosažení této hodiny se výpis jídel automaticky přepne na následující den.</span>
            <p class="description">Například zadáním hodnoty <strong>15</strong> se v 15:00 začne zobrazovat jídelníček na další den.</p>
            <label for="hs-weekend-rollover"><strong>Začátek víkendu</strong></label><br>
            <select id="hs-weekend-rollover" name="weekend_rollover_day">
              <?php foreach ($weekend_day_options as $value => $label_day) : ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected((int) $value, (int) $weekend_rollover_day); ?>><?php echo esc_html($label_day); ?></option>
              <?php endforeach; ?>
            </select>
            <p class="description">Zvolený den se po dosažení uvedené hodiny (a všechny následující dny) automaticky přepne na nadcházející pondělí.</p>
          </fieldset>
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
                        <input type="text" name="menu_groups[<?php echo esc_attr($i); ?>][price]" value="<?php echo esc_attr($group_price); ?>" placeholder="<?php echo esc_attr($menu_price_hint); ?>">
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
                      <?php if (!empty($color_palette)) : ?>
                        <div class="hs-color-palette" role="group" aria-label="Rychlý výběr barev">
                          <?php foreach ($color_palette as $hex => $label) : ?>
                            <button type="button" class="hs-color-swatch" data-color="<?php echo esc_attr(strtolower($hex)); ?>" title="<?php echo esc_attr($label); ?>" style="--hs-swatch-color: <?php echo esc_attr($hex); ?>;">
                              <span class="screen-reader-text"><?php echo esc_html($label); ?></span>
                            </button>
                          <?php endforeach; ?>
                        </div>
                      <?php endif; ?>
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
                      <?php if (!empty($color_palette)) : ?>
                        <div class="hs-color-palette" role="group" aria-label="Rychlý výběr barev">
                          <?php foreach ($color_palette as $hex => $label) : ?>
                            <button type="button" class="hs-color-swatch" data-color="<?php echo esc_attr(strtolower($hex)); ?>" title="<?php echo esc_attr($label); ?>" style="--hs-swatch-color: <?php echo esc_attr($hex); ?>;">
                              <span class="screen-reader-text"><?php echo esc_html($label); ?></span>
                            </button>
                          <?php endforeach; ?>
                        </div>
                      <?php endif; ?>
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
          <fieldset class="hs-branding__orders">
            <legend><strong>Objednávkový systém</strong></legend>
            <p><input type="hidden" name="order_settings[enabled]" value="0"><label><input type="checkbox" name="order_settings[enabled]" value="1" <?php checked(!empty($order_settings['enabled'])); ?>> Povolit modul objednávek</label></p>
            <div class="hs-order-settings-extra" style="<?php echo empty($order_settings['enabled']) ? 'display:none' : ''; ?>">
              <p><input type="hidden" name="order_settings[require_login]" value="0"><label><input type="checkbox" name="order_settings[require_login]" value="1" <?php checked(!empty($order_settings['require_login'])); ?>> Povolit objednávky pouze pro registrované/přihlášené uživatele</label></p>
              <p><label>Režim objednávek
                <select name="order_settings[mode]">
                  <option value="day" <?php selected($order_settings['mode'] ?? 'both', 'day'); ?>>Denní</option>
                  <option value="week" <?php selected($order_settings['mode'] ?? 'both', 'week'); ?>>Týdenní</option>
                  <option value="both" <?php selected($order_settings['mode'] ?? 'both', 'both'); ?>>Obojí</option>
                </select>
              </label></p>
              <p><label>Uzávěrka
                <select name="order_settings[cutoff_type]">
                  <option value="same_day_time" <?php selected($order_settings['cutoff_type'] ?? '', 'same_day_time'); ?>>Tentýž den do času</option>
                  <option value="day_before_time" <?php selected($order_settings['cutoff_type'] ?? '', 'day_before_time'); ?>>Den předem do času</option>
                  <option value="hours_before" <?php selected($order_settings['cutoff_type'] ?? '', 'hours_before'); ?>>Počet hodin předem</option>
                  <option value="week_days_before_monday_time" <?php selected($order_settings['cutoff_type'] ?? '', 'week_days_before_monday_time'); ?>>Týdenní: X dní před pondělím do času</option>
                </select>
                <input type="text" name="order_settings[cutoff_value]" value="<?php echo esc_attr($order_settings['cutoff_value'] ?? '09:30'); ?>" placeholder="09:30 / 12">
                <input type="number" name="order_settings[cutoff_days_before_monday]" value="<?php echo esc_attr((string)($order_settings['cutoff_days_before_monday'] ?? 5)); ?>" min="0" max="14" style="width:80px">
              </label></p>
              <p class="description">Pro týdenní objednávky nastavte např. <strong>5 dní</strong> + čas <strong>20:00</strong>, což odpovídá středě před pondělním týdnem.</p>
              <p><label>Doručení
                <select name="order_settings[delivery_mode]">
                  <option value="delivery" <?php selected($order_settings['delivery_mode'] ?? '', 'delivery'); ?>>Rozvoz</option>
                  <option value="pickup" <?php selected($order_settings['delivery_mode'] ?? '', 'pickup'); ?>>Osobní odběr</option>
                  <option value="both" <?php selected($order_settings['delivery_mode'] ?? '', 'both'); ?>>Obojí</option>
                </select>
              </label></p>
              <p><label>Cena rozvozu
                <select name="order_settings[delivery_fee_type]">
                  <option value="fixed" <?php selected($order_settings['delivery_fee_type'] ?? '', 'fixed'); ?>>Fixní</option>
                  <option value="zone" <?php selected($order_settings['delivery_fee_type'] ?? '', 'zone'); ?>>Podle zóny</option>
                  <option value="free_from" <?php selected($order_settings['delivery_fee_type'] ?? '', 'free_from'); ?>>Zdarma od částky</option>
                </select>
                <input type="text" name="order_settings[delivery_fee_value]" value="<?php echo esc_attr($order_settings['delivery_fee_value'] ?? '0'); ?>" placeholder="0">
              </label></p>
              <p><label>Zóny (např. Jarošov:20)
                <textarea name="order_settings[delivery_zones]" rows="3" class="large-text"><?php echo esc_textarea($order_settings['delivery_zones'] ?? ''); ?></textarea>
              </label></p>
              <p><label>Platby
                <select name="order_settings[payment_mode]">
                  <option value="reservation" <?php selected($order_settings['payment_mode'] ?? '', 'reservation'); ?>>Bez platby (rezervace)</option>
                  <option value="cash" <?php selected($order_settings['payment_mode'] ?? '', 'cash'); ?>>Hotově</option>
                  <option value="qr" <?php selected($order_settings['payment_mode'] ?? '', 'qr'); ?>>QR manuálně</option>
                  <option value="future" <?php selected($order_settings['payment_mode'] ?? '', 'future'); ?>>Online brána (future)</option>
                </select>
              </label></p>
              <p><label>Email provozovny <input type="email" class="regular-text" name="order_settings[notification_email]" value="<?php echo esc_attr($order_settings['notification_email'] ?? ''); ?>"></label></p>
              <p><label>Šablona emailu zákazníkovi<textarea name="order_settings[customer_email_template]" rows="2" class="large-text"><?php echo esc_textarea($order_settings['customer_email_template'] ?? ''); ?></textarea></label></p>
              <p><label>Šablona emailu provozu<textarea name="order_settings[ops_email_template]" rows="2" class="large-text"><?php echo esc_textarea($order_settings['ops_email_template'] ?? ''); ?></textarea></label></p>
              <p><label>Max objednávek / den <input type="number" name="order_settings[max_orders_per_day]" value="<?php echo esc_attr((string)($order_settings['max_orders_per_day'] ?? 0)); ?>" min="0"></label></p>
              <p><label>Max porcí na položku <input type="number" name="order_settings[max_item_qty]" value="<?php echo esc_attr((string)($order_settings['max_item_qty'] ?? 0)); ?>" min="0"></label></p>
              <p><label>GDPR text<textarea name="order_settings[gdpr_text]" rows="2" class="large-text"><?php echo esc_textarea($order_settings['gdpr_text'] ?? ''); ?></textarea></label></p>
              <p><label>GDPR odkaz <input type="url" class="large-text" name="order_settings[gdpr_link]" value="<?php echo esc_attr($order_settings['gdpr_link'] ?? ''); ?>"></label></p>
              <p><label>Uchování objednávek (dní) <input type="number" name="order_settings[retention_days]" value="<?php echo esc_attr((string)($order_settings['retention_days'] ?? 90)); ?>" min="7"></label></p>
            </div>
          </fieldset>
          <script>document.addEventListener('DOMContentLoaded',function(){var cb=document.querySelector('input[name="order_settings[enabled]"]');var box=document.querySelector('.hs-order-settings-extra');if(!cb||!box)return;cb.addEventListener('change',function(){box.style.display=cb.checked?'':'none';});});</script>
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
            $soup_title_raw = sanitize_text_field($soup_in['title'] ?? '');
            $soup_price = sanitize_text_field($soup_in['price'] ?? '');
            $soup_allergens = $this->sanitize_allergen_list($soup_in['allergens'] ?? []);
            [$soup_id, $soup_title] = $this->resolve_meal_variant($soup_id, $soup_title_raw, $soup_price, $soup_allergens, [], false);
            $soup = [
                'id'        => $soup_id,
                'title'     => $soup_title,
                'price'     => $soup_price,
                'allergens' => $soup_allergens,
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
                $title_raw = sanitize_text_field($row['title'] ?? '');
                $price = sanitize_text_field($row['price'] ?? '');
                $allergens = $this->sanitize_allergen_list($row['allergens'] ?? []);
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

                [$id, $resolved_title] = $this->resolve_meal_variant($id, $title_raw, $price, $allergens, $sides_list, $use_sides);

                $mains[] = [
                    'id'    => $id,
                    'title' => $resolved_title,
                    'price' => $price,
                    'sides' => $sides_list,
                    'allergens' => $allergens,
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
            'currency_label'  => $this->sanitize_currency_label(isset($_POST['currency_label']) ? wp_unslash($_POST['currency_label']) : ''),
            'day_switch_hour' => $this->sanitize_day_switch_hour(isset($_POST['day_switch_hour']) ? wp_unslash($_POST['day_switch_hour']) : 12),
            'weekend_rollover_day' => $this->sanitize_weekend_rollover_day(isset($_POST['weekend_rollover_day']) ? wp_unslash($_POST['weekend_rollover_day']) : 6),
        ];
        update_option('hsp_menu_preferences', $preferences, false);
        $this->menu_preferences_cache = null;

        if (isset($_POST['order_settings']) && is_array($_POST['order_settings'])) {
            $order_input = wp_unslash($_POST['order_settings']);
            $order_settings = $this->sanitize_order_settings($order_input);
            update_option('hsp_order_settings', $order_settings, false);
            $this->cleanup_old_orders($order_settings);
        }

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
            'currency_label'  => $this->get_currency_label(),
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
                $price_display = $this->format_price_for_display((string)$soup['price']);
                if ($price_display !== '') {
                    $line .= ' — ' . esc_html($price_display);
                }
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
                    $price_raw = isset($group['price']) ? (string)$group['price'] : '';
                    $price_label = $this->format_menu_group_price_display($price_raw);
                    echo '<h5 style="margin:.5em 0 0;">' . esc_html($label) . ($price_label !== '' ? ' — ' . esc_html($price_label) : '') . '</h5>';
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
                        $price_display = $this->format_price_for_display((string)$row['price']);
                        if ($price_display !== '') {
                            $line .= ' — ' . esc_html($price_display);
                        }
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
            if ($show_soup_price && !empty($soup['price'])) {
                $price_display = $this->format_price_for_display((string)$soup['price']);
                if ($price_display !== '') {
                    echo '<span class="hsp-price">'.esc_html($price_display).'</span>';
                }
            }
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
                    $price_raw = isset($group['price']) ? (string)$group['price'] : '';
                    $price_label = $this->format_menu_group_price_display($price_raw);
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
                    $price_display = $this->format_price_for_display((string)($row['price'] ?? ''));
                    $price = $price_display !== '' ? '<span class="hsp-price">'.esc_html($price_display).'</span>' : '';
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
            $price_display = $this->format_price_for_display($price);
            $price_html = $price_display !== '' ? '<span class="hsp-price">' . esc_html($price_display) . '</span>' : '';

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


    private function get_day_menu_payload(string $date): array {
        $posts = get_posts([
            'post_type' => CPT_DAY,
            'posts_per_page' => 1,
            'meta_key' => 'menu_date',
            'meta_value' => $date,
        ]);
        if (!$posts) {
            return [];
        }
        $post_id = (int)$posts[0]->ID;
        $soup = get_post_meta($post_id, 'soup', true);
        $mains = get_post_meta($post_id, 'mains', true);
        $items = [];
        if (!empty($soup['title'])) {
            $items[] = [
                'type' => 'soup',
                'meal_id' => (int)($soup['id'] ?? 0),
                'title' => (string)$soup['title'],
                'price' => (string)($soup['price'] ?? ''),
                'sides' => [],
                'allergens' => is_array($soup['allergens'] ?? null) ? array_values(array_map('intval', $soup['allergens'])) : [],
            ];
        }

        if (is_array($mains)) {
            foreach ($mains as $main) {
                if (!is_array($main) || empty($main['title'])) {
                    continue;
                }
                $items[] = [
                    'type' => 'main',
                    'meal_id' => (int)($main['id'] ?? 0),
                    'title' => (string)$main['title'],
                    'price' => (string)($main['price'] ?? ''),
                    'sides' => is_array($main['sides'] ?? null) ? array_values(array_map('intval', $main['sides'])) : [],
                    'allergens' => is_array($main['allergens'] ?? null) ? array_values(array_map('intval', $main['allergens'])) : [],
                ];
            }
        }
        return $items;
    }

    private function cleanup_old_orders(array $settings): void {
        $retention = max(7, (int)($settings['retention_days'] ?? 90));
        $before = (new \DateTimeImmutable('now', wp_timezone()))->modify('-' . $retention . ' days')->format('Y-m-d H:i:s');
        $old = get_posts([
            'post_type' => CPT_ORDER,
            'post_status' => 'any',
            'posts_per_page' => 200,
            'date_query' => [['before' => $before]],
            'fields' => 'ids',
        ]);
        foreach ($old as $id) {
            wp_trash_post((int)$id);
        }
    }

    private function order_rate_limit_key(): string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return 'hsp_order_rate_' . md5((string)$ip);
    }

    private function order_rate_limited(): bool {
        $key = $this->order_rate_limit_key();
        $count = (int)get_transient($key);
        if ($count >= 20) {
            return true;
        }
        set_transient($key, $count + 1, MINUTE_IN_SECONDS * 10);
        return false;
    }

    private function create_order(array $payload): array {
        $settings = $this->get_order_settings();

        if (!empty($settings['require_login']) && !is_user_logged_in()) {
            return ['error' => 'Objednávky jsou dostupné pouze přihlášeným uživatelům.'];
        }

        $week_order = !empty($payload['week_order']);
        $selected_by_date = [];
        $week_start = '';

        if ($week_order) {
            $week_start = sanitize_text_field((string)($payload['week_start'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $week_start)) {
                return ['error' => 'Neplatný začátek týdne.'];
            }

            $days = isset($payload['days']) && is_array($payload['days']) ? $payload['days'] : [];
            foreach ($days as $day) {
                if (!is_array($day)) {
                    continue;
                }
                $menu_date = sanitize_text_field((string)($day['menu_date'] ?? ''));
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $menu_date)) {
                    continue;
                }
                $selected = isset($day['items']) && is_array($day['items']) ? $day['items'] : [];
                if (!empty($selected)) {
                    $selected_by_date[$menu_date] = $selected;
                }
            }

            if (empty($selected_by_date)) {
                return ['error' => 'Vyberte alespoň jednu položku v týdnu.'];
            }
        } else {
            $menu_date = sanitize_text_field((string)($payload['menu_date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $menu_date)) {
                return ['error' => 'Neplatné datum menu.'];
            }
            $selected = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
            $selected_by_date[$menu_date] = $selected;
            $week_start = $menu_date;
        }

        $items = [];
        $subtotal = 0.0;
        $max_item_qty = max(0, (int)($settings['max_item_qty'] ?? 0));

        foreach ($selected_by_date as $menu_date => $selected) {
            if (!$this->can_order_for_date($menu_date, $settings)) {
                return ['error' => 'Objednávky pro den ' . $menu_date . ' jsou uzavřeny.'];
            }

            $day_items = $this->get_day_menu_payload($menu_date);
            if (empty($day_items)) {
                continue;
            }

            foreach ($selected as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $title = sanitize_text_field((string)($row['title'] ?? ''));
                $qty = max(1, (int)($row['quantity'] ?? 1));
                if ($max_item_qty > 0) {
                    $qty = min($max_item_qty, $qty);
                }
                $found = null;
                foreach ($day_items as $item) {
                    if ($item['title'] === $title) {
                        $found = $item;
                        break;
                    }
                }
                if (!$found) {
                    continue;
                }
                $price_num = (float)preg_replace('/[^0-9.,-]/', '', str_replace(',', '.', (string)($found['price'] ?? '0')));
                $line_total = max(0, $price_num) * $qty;
                $subtotal += $line_total;
                $items[] = [
                    'menu_date' => $menu_date,
                    'type' => $found['type'],
                    'meal_id' => (int)$found['meal_id'],
                    'title' => $found['title'],
                    'quantity' => $qty,
                    'price' => (string)($found['price'] ?? ''),
                    'sides' => $found['sides'] ?? [],
                    'allergens' => $found['allergens'] ?? [],
                    'note' => sanitize_text_field((string)($row['note'] ?? '')),
                ];
            }
        }

        if (empty($items)) {
            return ['error' => 'Objednávka neobsahuje žádné položky.'];
        }

        $delivery_type = sanitize_key((string)($payload['delivery_type'] ?? 'pickup'));
        if (!in_array($delivery_type, ['delivery', 'pickup'], true)) {
            $delivery_type = 'pickup';
        }
        $address = sanitize_text_field((string)($payload['delivery_address'] ?? ''));
        if ($delivery_type === 'delivery' && $address === '') {
            return ['error' => 'Pro rozvoz je potřeba vyplnit adresu.'];
        }

        $customer_name = sanitize_text_field((string)($payload['customer_name'] ?? ''));
        $customer_phone = sanitize_text_field((string)($payload['customer_phone'] ?? ''));
        if ($customer_phone === '') {
            return ['error' => 'Telefon je povinný.'];
        }

        $gdpr_accepted = !empty($payload['gdpr_accepted']);
        if (!$gdpr_accepted) {
            return ['error' => 'Pro odeslání je nutný souhlas GDPR.'];
        }

        $delivery_fee = $this->compute_delivery_fee($settings, $delivery_type, $address);
        if (($settings['delivery_fee_type'] ?? '') === 'free_from') {
            $limit = (float)str_replace(',', '.', (string)($settings['delivery_fee_value'] ?? '0'));
            $delivery_fee = $subtotal >= $limit ? 0.0 : $delivery_fee;
        }
        $total = $subtotal + $delivery_fee;

        $seq = (int)get_option('hsp_order_sequence', 1000) + 1;
        update_option('hsp_order_sequence', $seq, false);
        $order_number = 'HSP-' . $seq;

        $post_id = wp_insert_post([
            'post_type' => CPT_ORDER,
            'post_status' => 'hsp_new',
            'post_title' => $order_number,
        ], true);
        if (is_wp_error($post_id)) {
            return ['error' => 'Objednávku se nepodařilo uložit.'];
        }

        $meta = [
            'order_number' => $order_number,
            'order_date_created' => current_time('mysql'),
            'menu_date' => $week_start,
            'is_week_order' => $week_order ? 1 : 0,
            'time_window' => sanitize_text_field((string)($payload['time_window'] ?? '')),
            'customer_name' => $customer_name,
            'customer_phone' => $customer_phone,
            'customer_email' => sanitize_email((string)($payload['customer_email'] ?? '')),
            'delivery_type' => $delivery_type,
            'delivery_address' => $address,
            'note' => sanitize_textarea_field((string)($payload['note'] ?? '')),
            'items' => $items,
            'totals' => ['subtotal' => round($subtotal, 2), 'delivery_fee' => round($delivery_fee, 2), 'total' => round($total, 2)],
            'consents' => [
                'gdpr_accepted' => true,
                'gdpr_text_version' => (string)($settings['gdpr_text'] ?? ''),
            ],
            'status_history' => [[
                'status' => 'hsp_new',
                'time' => current_time('mysql'),
                'by' => get_current_user_id(),
            ]],
        ];

        foreach ($meta as $k => $v) {
            update_post_meta($post_id, $k, $v);
        }

        if (is_user_logged_in()) {
            $uid = get_current_user_id();
            if ($customer_name !== '') {
                update_user_meta($uid, 'hsp_customer_name', $customer_name);
            }
            if ($customer_phone !== '') {
                update_user_meta($uid, 'hsp_customer_phone', $customer_phone);
            }
            if ($address !== '') {
                update_user_meta($uid, 'hsp_delivery_address', $address);
            }
        }

        $ops_email = (string)($settings['notification_email'] ?? '');
        $customer_email = (string)$meta['customer_email'];
        $repl = ['{order_number}' => $order_number, '{menu_date}' => $week_start, '#{order_number}' => $order_number];
        if (is_email($ops_email)) {
            wp_mail($ops_email, 'Nová objednávka ' . $order_number, strtr((string)($settings['ops_email_template'] ?? ''), $repl));
        }
        if (is_email($customer_email)) {
            wp_mail($customer_email, 'Potvrzení objednávky ' . $order_number, strtr((string)($settings['customer_email_template'] ?? ''), $repl));
        }

        return ['post_id' => $post_id, 'order_number' => $order_number, 'total' => $total];
    }

    public function ajax_submit_order() {
        $settings = $this->get_order_settings();
        if (empty($settings['enabled'])) {
            wp_send_json_error(['message' => 'Objednávkový systém je vypnutý.'], 403);
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field((string)$_POST['nonce']), 'hsp_order_submit')) {
            wp_send_json_error(['message' => 'Neplatný bezpečnostní token.'], 403);
        }
        if ($this->order_rate_limited()) {
            wp_send_json_error(['message' => 'Příliš mnoho požadavků, zkuste to za chvíli.'], 429);
        }
        $payload_raw = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : '';
        $payload = json_decode((string)$payload_raw, true);
        if (!is_array($payload)) {
            wp_send_json_error(['message' => 'Neplatná data objednávky.'], 400);
        }
        $result = $this->create_order($payload);
        if (!empty($result['error'])) {
            wp_send_json_error(['message' => $result['error']], 400);
        }
        wp_send_json_success(['order_number' => $result['order_number']]);
    }

    public function shortcode_order_form($atts = []): string {
        $settings = $this->get_order_settings();
        if (empty($settings['enabled'])) {
            return '<p><em>Objednávkový systém je aktuálně vypnutý.</em></p>';
        }

        if (!empty($settings['require_login']) && !is_user_logged_in()) {
            return '<div class="hsp-order-locked"><p><strong>Objednávky jsou dostupné jen pro přihlášené zákazníky.</strong></p><p><a class="button button-primary" href="' . esc_url(wp_login_url(get_permalink() ?: home_url('/'))) . '">Přihlásit se</a></p></div>';
        }

        $user = wp_get_current_user();
        $default_name = is_user_logged_in() ? (string)get_user_meta($user->ID, 'hsp_customer_name', true) : '';
        if ($default_name === '' && is_user_logged_in()) {
            $default_name = $user->display_name;
        }
        $default_phone = is_user_logged_in() ? (string)get_user_meta($user->ID, 'hsp_customer_phone', true) : '';
        $default_address = is_user_logged_in() ? (string)get_user_meta($user->ID, 'hsp_delivery_address', true) : '';
        $default_email = is_user_logged_in() ? (string)$user->user_email : '';

        $a = shortcode_atts(['mode' => 'week'], $atts, 'hsp_order_form');
        $week_mode = ($a['mode'] !== 'day');

        $weeks = $this->get_next_mondays(4);
        $menu_map = [];
        foreach ($weeks as $monday) {
            $menu_map[$monday] = [];
            $monday_ts = strtotime($monday);
            for ($i = 0; $i < 5; $i++) {
                $date = wp_date('Y-m-d', strtotime('+' . $i . ' days', $monday_ts));
                $menu_map[$monday][$date] = [
                    'open' => $this->can_order_for_date($date, $settings),
                    'items' => $this->get_day_menu_payload($date),
                ];
            }
        }

        ob_start();
        echo '<div class="hsp-order-form hsp-order-form--card" id="hsp-order-form">';
        echo '<h3>Objednávka jídel</h3>';
        echo '<p class="hsp-order-help">Vyberte jídla na celý týden a odešlete jednu souhrnnou objednávku.</p>';
        echo '<label class="hsp-order-label">Týden od pondělí <select id="hsp-order-week" class="hsp-order-select">';
        foreach ($weeks as $monday) {
            echo '<option value="' . esc_attr($monday) . '">' . esc_html(wp_date('j. n. Y', strtotime($monday))) . '</option>';
        }
        echo '</select></label>';

        echo '<div id="hsp-order-days" class="hsp-order-days"></div>';
        echo '<h4>Kontaktní údaje</h4>';
        echo '<div class="hsp-order-grid">';
        echo '<input type="text" id="hsp-order-name" placeholder="Jméno" value="' . esc_attr($default_name) . '" />';
        echo '<input type="tel" id="hsp-order-phone" placeholder="Telefon" value="' . esc_attr($default_phone) . '" />';
        echo '<input type="email" id="hsp-order-email" placeholder="Email (volitelně)" value="' . esc_attr($default_email) . '" />';
        echo '</div>';
        echo '<p><label><input type="radio" name="hsp-order-delivery" value="pickup" checked> Osobní odběr</label> <label><input type="radio" name="hsp-order-delivery" value="delivery"> Rozvoz</label></p>';
        echo '<input type="text" id="hsp-order-address" placeholder="Adresa rozvozu" value="' . esc_attr($default_address) . '" />';
        echo '<textarea id="hsp-order-note" placeholder="Poznámka"></textarea>';
        echo '<p><label><input type="checkbox" id="hsp-order-gdpr"> ' . esc_html((string)($settings['gdpr_text'] ?? 'Souhlasím se zpracováním osobních údajů.')) . '</label></p>';
        echo '<button type="button" class="button button-primary" id="hsp-order-submit">Odeslat objednávku na týden</button>';
        echo '<p id="hsp-order-msg"></p>';
        echo '</div>';

        $style = '.hsp-order-form--card{max-width:980px;background:#fff;border:1px solid #d9e2ec;border-radius:12px;padding:18px 20px;box-shadow:0 2px 6px rgba(0,0,0,.05)}'
               . '.hsp-order-days{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:14px 0}'
               . '.hsp-order-day{border:1px solid #d8e3f2;border-radius:10px;padding:10px;background:#f8fbff}'
               . '.hsp-order-day h5{margin:0 0 8px;font-size:14px;color:#0f172a}'
               . '.hsp-order-items{display:grid;gap:6px}'
               . '.hsp-order-item{display:flex;gap:8px;align-items:center;justify-content:space-between}'
               . '.hsp-order-item input[type=number]{width:62px}'
               . '.hsp-order-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}'
               . '.hsp-order-help{color:#475569;margin:.25rem 0 .75rem}'
               . '.hsp-order-day--closed{opacity:.55}';
        wp_register_style('hsp-order-inline', false, [], VERSION);
        wp_enqueue_style('hsp-order-inline');
        wp_add_inline_style('hsp-order-inline', $style);

        wp_enqueue_script('jquery');
        $config_js = 'window.HSP_ORDER=' . wp_json_encode([
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hsp_order_submit'),
            'weeks' => $menu_map,
            'weekMode' => $week_mode ? 1 : 0,
        ]) . ';';
        $script_js = <<<'JS'
(function(){
  if(!window.jQuery||!window.HSP_ORDER){return;}
  var $=window.jQuery;
  var weeks=HSP_ORDER.weeks||{};
  function esc(v){return String(v||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
  function render(){
    var weekStart=$("#hsp-order-week").val();
    var days=weeks[weekStart]||{};
    var html='';
    Object.keys(days).forEach(function(date){
      var day=days[date]||{};
      var open=!!day.open;
      var items=day.items||[];
      html+="<div class='hsp-order-day"+(open?'':' hsp-order-day--closed')+"' data-date='"+date+"'><h5>"+date+(open?'':' • uzavřeno')+"</h5>";
      if(!items.length){ html+="<p><em>Bez menu</em></p></div>"; return; }
      html+="<div class='hsp-order-items'>";
      items.forEach(function(it,idx){
        var dis=open?'':' disabled';
        html+="<label class='hsp-order-item'><span><input type='checkbox' class='hsp-order-item-check' data-date='"+date+"' data-title='"+esc(it.title)+"'"+dis+"> "+esc(it.title)+" <small>("+esc(it.price||'bez ceny')+")</small></span><input type='number' min='1' value='1' class='hsp-order-qty' data-date='"+date+"' data-index='"+idx+"'"+dis+"></label>";
      });
      html+='</div></div>';
    });
    $("#hsp-order-days").html(html);
  }
  $(document).on('change','#hsp-order-week',render);
  render();

  $(document).on('click','#hsp-order-submit',function(){
    var weekStart=$("#hsp-order-week").val();
    var dayMap={};
    $('.hsp-order-item-check:checked').each(function(){
      var date=$(this).data('date');
      var qtyInput=$(this).closest('.hsp-order-item').find('.hsp-order-qty');
      var qty=parseInt(qtyInput.val(),10)||1;
      if(!dayMap[date]){ dayMap[date]=[]; }
      dayMap[date].push({title:$(this).data('title'),quantity:qty});
    });
    var days=[];
    Object.keys(dayMap).forEach(function(date){ days.push({menu_date:date,items:dayMap[date]}); });

    var payload={
      week_order:1,
      week_start:weekStart,
      days:days,
      customer_name:$("#hsp-order-name").val(),
      customer_phone:$("#hsp-order-phone").val(),
      customer_email:$("#hsp-order-email").val(),
      delivery_type:$("input[name='hsp-order-delivery']:checked").val(),
      delivery_address:$("#hsp-order-address").val(),
      note:$("#hsp-order-note").val(),
      gdpr_accepted:$("#hsp-order-gdpr").is(':checked')
    };

    $.post(HSP_ORDER.ajax,{action:'hsp_submit_order',nonce:HSP_ORDER.nonce,payload:JSON.stringify(payload)})
      .done(function(res){
        if(res&&res.success){
          $('#hsp-order-msg').text('Objednávka přijata: '+res.data.order_number);
        }else{
          $('#hsp-order-msg').text((res&&res.data&&res.data.message)?res.data.message:'Objednávku se nepodařilo odeslat.');
        }
      })
      .fail(function(xhr){
        var m='Objednávku se nepodařilo odeslat.';
        if(xhr&&xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message){m=xhr.responseJSON.data.message;}
        $('#hsp-order-msg').text(m);
      });
  });
})();
JS;
        wp_add_inline_script('jquery', $config_js . $script_js, 'after');

        return ob_get_clean();
    }

    public function shortcode_my_orders(): string {
        $phone = isset($_GET['hsp_phone']) ? sanitize_text_field(wp_unslash($_GET['hsp_phone'])) : '';
        $html = '<div class="hsp-my-orders">';
        $html .= '<form method="get"><label>Telefon <input type="text" name="hsp_phone" value="' . esc_attr($phone) . '"></label> <button class="button">Zobrazit</button></form>';
        if ($phone !== '') {
            $orders = get_posts([
                'post_type' => CPT_ORDER,
                'posts_per_page' => 20,
                'post_status' => 'any',
                'meta_key' => 'customer_phone',
                'meta_value' => $phone,
            ]);
            if ($orders) {
                $html .= '<ul>';
                foreach ($orders as $order) {
                    $menu_date = (string)get_post_meta($order->ID, 'menu_date', true);
                    $total = get_post_meta($order->ID, 'totals', true);
                    $total_price = is_array($total) ? (string)($total['total'] ?? '') : '';
                    $html .= '<li><strong>' . esc_html($order->post_title) . '</strong> – ' . esc_html($menu_date) . ' – ' . esc_html($this->format_price_for_display($total_price)) . '</li>';
                }
                $html .= '</ul>';
            } else {
                $html .= '<p><em>Nebyly nalezeny žádné objednávky.</em></p>';
            }
        }
        $html .= '</div>';
        return $html;
    }

    public function render_orders_admin_page() {
        if (!current_user_can('edit_posts')) {
            wp_die();
        }

        $selected_date = isset($_GET['menu_date']) ? sanitize_text_field(wp_unslash($_GET['menu_date'])) : '';
        $meta_query = [];
        if ($selected_date !== '') {
            $meta_query[] = ['key' => 'menu_date', 'value' => $selected_date];
        }
        $orders = get_posts([
            'post_type' => CPT_ORDER,
            'posts_per_page' => 200,
            'post_status' => 'any',
            'meta_query' => $meta_query,
        ]);

        echo '<div class="wrap"><h1>Objednávky</h1>';
        echo '<p><a class="button" href="' . esc_url(admin_url('admin-post.php?action=hsp_order_export_delivery_csv')) . '">Export rozvoz CSV</a> <a class="button" href="' . esc_url(admin_url('admin-post.php?action=hsp_order_export_kitchen_csv')) . '">Export kuchyň CSV</a></p>';
        echo '<table class="widefat striped"><thead><tr><th>Číslo</th><th>Datum menu</th><th>Jméno</th><th>Telefon</th><th>Typ</th><th>Cena</th><th>Stav</th><th>Vytvořeno</th></tr></thead><tbody>';
        foreach ($orders as $order) {
            $menu_date = (string)get_post_meta($order->ID, 'menu_date', true);
            $name = (string)get_post_meta($order->ID, 'customer_name', true);
            $phone = (string)get_post_meta($order->ID, 'customer_phone', true);
            $dtype = (string)get_post_meta($order->ID, 'delivery_type', true);
            $totals = get_post_meta($order->ID, 'totals', true);
            $total_price = is_array($totals) ? (string)($totals['total'] ?? '') : '';
            $status = $order->post_status;
            echo '<tr>';
            echo '<td><a href="' . esc_url(admin_url('admin.php?page=hospoda-orders&order_id=' . $order->ID)) . '">' . esc_html($order->post_title) . '</a></td>';
            echo '<td>' . esc_html($menu_date) . '</td><td>' . esc_html($name) . '</td><td>' . esc_html($phone) . '</td><td>' . esc_html($dtype) . '</td><td>' . esc_html($this->format_price_for_display($total_price)) . '</td><td>' . esc_html($this->get_order_statuses()[$status] ?? $status) . '</td><td>' . esc_html($order->post_date) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        $order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
        if ($order_id > 0) {
            $order_post = get_post($order_id);
            if ($order_post && $order_post->post_type === CPT_ORDER) {
                $items = get_post_meta($order_id, 'items', true);
                $address = (string)get_post_meta($order_id, 'delivery_address', true);
                $note = (string)get_post_meta($order_id, 'note', true);
                echo '<hr><h2>Detail objednávky ' . esc_html($order_post->post_title) . '</h2>';
                echo '<p><strong>Adresa:</strong> ' . esc_html($address) . '</p><p><strong>Poznámka:</strong> ' . esc_html($note) . '</p>';
                if (is_array($items) && !empty($items)) {
                    echo '<ul>';
                    foreach ($items as $item) {
                        echo '<li>' . esc_html((string)($item['title'] ?? '')) . ' × ' . esc_html((string)($item['quantity'] ?? 1)) . '</li>';
                    }
                    echo '</ul>';
                }
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field('hsp_order_update_status_' . $order_id);
                echo '<input type="hidden" name="action" value="hsp_order_update_status"><input type="hidden" name="order_id" value="' . esc_attr((string)$order_id) . '">';
                echo '<select name="new_status">';
                foreach ($this->get_order_statuses() as $k => $label) {
                    echo '<option value="' . esc_attr($k) . '"' . selected($order_post->post_status, $k, false) . '>' . esc_html($label) . '</option>';
                }
                echo '</select> <button class="button button-primary">Uložit stav</button></form>';
            }
        }

        echo '</div>';
    }

    public function handle_order_status_update() {
        if (!current_user_can('edit_posts')) {
            wp_die();
        }
        $order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
        check_admin_referer('hsp_order_update_status_' . $order_id);
        $new_status = sanitize_key((string)($_POST['new_status'] ?? ''));
        $allowed = array_keys($this->get_order_statuses());
        if ($order_id > 0 && in_array($new_status, $allowed, true)) {
            wp_update_post(['ID' => $order_id, 'post_status' => $new_status]);
            $history = get_post_meta($order_id, 'status_history', true);
            if (!is_array($history)) {
                $history = [];
            }
            $history[] = ['status' => $new_status, 'time' => current_time('mysql'), 'by' => get_current_user_id()];
            update_post_meta($order_id, 'status_history', $history);
        }
        wp_redirect(admin_url('admin.php?page=hospoda-orders&order_id=' . $order_id));
        exit;
    }

    public function handle_order_export_delivery_csv() {
        if (!current_user_can('edit_posts')) {
            wp_die();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=rozvoz.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Objednávka', 'Datum menu', 'Jméno', 'Telefon', 'Adresa', 'Čas', 'Položky']);
        $orders = get_posts(['post_type' => CPT_ORDER, 'posts_per_page' => 500, 'post_status' => 'any']);
        foreach ($orders as $order) {
            $items = get_post_meta($order->ID, 'items', true);
            $item_label = [];
            if (is_array($items)) {
                foreach ($items as $i) {
                    $item_label[] = (string)($i['title'] ?? '') . ' x' . (int)($i['quantity'] ?? 1);
                }
            }
            fputcsv($out, [
                $order->post_title,
                get_post_meta($order->ID, 'menu_date', true),
                get_post_meta($order->ID, 'customer_name', true),
                get_post_meta($order->ID, 'customer_phone', true),
                get_post_meta($order->ID, 'delivery_address', true),
                get_post_meta($order->ID, 'time_window', true),
                implode('; ', $item_label),
            ]);
        }
        fclose($out);
        exit;
    }

    public function handle_order_export_kitchen_csv() {
        if (!current_user_can('edit_posts')) {
            wp_die();
        }
        $aggregate = [];
        $orders = get_posts(['post_type' => CPT_ORDER, 'posts_per_page' => 500, 'post_status' => 'any']);
        foreach ($orders as $order) {
            $menu_date = (string)get_post_meta($order->ID, 'menu_date', true);
            $items = get_post_meta($order->ID, 'items', true);
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                $title = (string)($item['title'] ?? '');
                $qty = (int)($item['quantity'] ?? 1);
                if ($title === '') {
                    continue;
                }
                if (!isset($aggregate[$menu_date])) {
                    $aggregate[$menu_date] = [];
                }
                if (!isset($aggregate[$menu_date][$title])) {
                    $aggregate[$menu_date][$title] = 0;
                }
                $aggregate[$menu_date][$title] += $qty;
            }
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=kuchyn.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Datum menu', 'Položka', 'Počet porcí']);
        foreach ($aggregate as $date => $rows) {
            foreach ($rows as $title => $qty) {
                fputcsv($out, [$date, $title, $qty]);
            }
        }
        fclose($out);
        exit;
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
            $manual_week_start = false;
            $start = $a['week_start'];
            if ($start !== '') {
                $manual_week_start = true;
            }

            $effective_today = null;
            if (!$manual_week_start) {
                $effective_today = $this->get_effective_today_datetime();
                $start = $effective_today->format('Y-m-d');
            }

            $ts = strtotime($start);
            if ($ts === false) {
                $ts = current_time('timestamp');
            }
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
            if (!$manual_week_start && $effective_today instanceof \DateTimeImmutable) {
                $today = $effective_today->format('Y-m-d');
            } else {
                $today = wp_date('Y-m-d');
            }
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
