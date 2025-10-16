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

    /**
     * Returns inline <style> tag for front‑end, printed only once per request.
     * This guarantees styling even if theme (e.g., Divi) suppresses our enqueued CSS.
     */
    private function inline_css_tag(){
        if ($this->inline_printed) return '';
        $this->inline_printed = true;
        $css =
        /* base layout */
        '.hsp-root{font-size:16px;line-height:1.5}' .
        '.hsp-root .hsp-week{display:grid;gap:24px;--hsp-gap:24px}' .
        '.hsp-root .hsp-collapsed{margin:0 0 16px}' .
        '.hsp-root .hsp-toggle{display:inline-block;padding:10px 16px;border:1px solid #ef6c00;border-radius:10px;background:#ef6c00;color:#fff;font-weight:700;letter-spacing:.2px;cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,.06)}' .
        '.hsp-root .hsp-toggle:hover{background:#e05f00;border-color:#e05f00}' .
        '.hsp-root .hsp-toggle:focus{outline:2px solid #ffd7a6;outline-offset:2px}' .
        '.hsp-root .hsp-toggle[aria-expanded="true"]{background:#444;border-color:#444}' .
        '.hsp-root .hsp-hidden{display:none}' .
        // --- week nav styles ---
        '.hsp-root .hsp-week__nav{display:flex;gap:12px;align-items:center;justify-content:center;margin:0 0 16px}' .
        '.hsp-root .hsp-week__nav .hsp-nav__btn, .hsp-root .hsp-week__nav .hsp-nav__btn[type=button]{display:inline-block;padding:6px 10px;border:1px solid #ddd;border-radius:6px;background:#fff;text-decoration:none;color:#333;cursor:pointer}' .
        '.hsp-root .hsp-week__nav .hsp-nav__btn:hover, .hsp-root .hsp-week__nav .hsp-nav__btn[type=button]:hover{background:#fafafa}' .
        '.hsp-root .hsp-week__nav .hsp-nav__label{font-weight:600}' .
        '.hsp-root .hsp-week__nav .hsp-nav__date{padding:6px 8px;border:1px solid #ddd;border-radius:6px}' .
        '@media (min-width:960px){.hsp-root .hsp-week{grid-template-columns:1fr 1fr;justify-items:stretch}}' .
        '@media (min-width:960px){.hsp-root .hsp-week > .hsp-day:last-child:nth-child(odd){grid-column:1/-1;justify-self:center;width:calc((100% - var(--hsp-gap))/2)}}' .
        /* day card */
        '.hsp-root .hsp-day{border:1px solid #e8e8e8;border-radius:12px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.03);overflow:hidden}' .
        '.hsp-root .hsp-day__heading{margin:0;padding:12px 16px;border-bottom:1px solid #f0f0f0;font-weight:700;letter-spacing:.2px;background:#fafafa}' .
        '.hsp-root .hsp-badge{display:inline-block;margin-left:8px;padding:2px 8px;border-radius:999px;background:#eef5ff;color:#1f3a68;font-size:.85em;font-weight:600}' .
        '.hsp-root .hsp-body{padding:0 16px 12px}' .
        /* common grid for rows */
        '.hsp-root .hsp-grid{display:grid;grid-template-columns:1fr auto;grid-template-areas:"title price" "sides price";align-items:baseline;gap:2px 8px;padding:8px 0;border-bottom:1px dashed #e6e6e6}' .
        '.hsp-root .hsp-grid:last-child{border-bottom:0}' .
        '.hsp-root .hsp-title{grid-area:title;font-weight:600}' .
        '.hsp-root .hsp-sides{grid-area:sides;display:block;color:#7a7a7a;font-size:.9em;margin:2px 0 0}' .
        '.hsp-root .hsp-price{grid-area:price;justify-self:end;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;color:#111}' .
        /* soup as full grid row */
        '.hsp-root .hsp-soup{margin:0}' .
        /* meals list */
        '.hsp-root .hsp-mains{list-style:none;margin:0;padding:0}' .
        '.hsp-root .hsp-item{list-style:none;padding:0}' .
        '.hsp-root .hsp-static{margin:28px 0 0;padding:18px 20px;border:1px solid #f3d4b2;border-radius:12px;background:#fff7ed;box-shadow:0 1px 3px rgba(0,0,0,.04)}' .
        '.hsp-root .hsp-static__title{margin:0 0 10px;font-size:1.05em;letter-spacing:.08em;text-transform:uppercase;color:#b45309;font-weight:700}' .
        '.hsp-root .hsp-static__list{margin:0;padding-left:20px;color:#4b5563;font-size:.97em}' .
        '.hsp-root .hsp-static__list li{margin:4px 0}' ;
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
                          . '.hsp-root .hsp-title{font-weight:500}'
                          . '.hsp-root .hsp-sides{color:#777}'
                          . '.hsp-root .hsp-price{margin-left:1rem;white-space:nowrap;font-variant-numeric:tabular-nums}';
                \wp_add_inline_style('hospoda-frontend', $override);
                $found = true;
                break;
            }
        }
        if (!$found) {
            $fallback = '.hsp-week{display:grid;gap:2rem}.hsp-mains{list-style:none;margin:0;padding:0;display:grid;gap:1rem}.hsp-item{display:grid;gap:1rem;grid-template-columns:1fr auto;align-items:start}.hsp-price{white-space:nowrap;font-variant-numeric:tabular-nums}.hsp-sides{color:#7a7a7a}.hsp-static{margin:24px 0 0;padding:18px 20px;border:1px solid #f3d4b2;border-radius:12px;background:#fff7ed}.hsp-static__title{margin:0 0 8px;text-transform:uppercase;letter-spacing:.08em;font-weight:700;color:#b45309}.hsp-static__list{margin:0;padding-left:20px}.hsp-static__list li{margin:4px 0}';
            \wp_register_style('hospoda-frontend', false, [], VERSION);
            \wp_enqueue_style('hospoda-frontend');
            \wp_add_inline_style('hospoda-frontend', $fallback);
            $override = '.hsp-root .hsp-mains{list-style:none!important;margin:0!important;padding:0!important}'
                      . '.hsp-root .hsp-item{display:grid!important;grid-template-columns:1fr auto!important;align-items:start!important}'
                      . '.hsp-root .hsp-title{font-weight:500}'
                      . '.hsp-root .hsp-sides{color:#777}'
                      . '.hsp-root .hsp-price{margin-left:1rem;white-space:nowrap;font-variant-numeric:tabular-nums}';
            \wp_add_inline_style('hospoda-frontend', $override);
        }
    }

    public function frontend_inline_probe(){
        // Vytiskneme drobný korektivní CSS s vysokou prioritou tak, aby přebil Divi
        echo "\n<style id=\"hospoda-frontend-probe\">\n".
             ".hsp-week ul.hsp-mains{list-style:none!important;margin:0!important;padding:0!important}\n".
             ".hsp-week .hsp-item{display:grid!important;grid-template-columns:1fr auto!important;align-items:start!important}\n".
             ".hsp-week .hsp-title{font-weight:600}\n".
             ".hsp-week .hsp-sides{color:#7a7a7a;font-size:.9em;display:block}\n".
             ".hsp-week .hsp-price{margin-left:1rem;white-space:nowrap;font-variant-numeric:tabular-nums;text-align:right}\n".
             ".hsp-root .hsp-static{margin-top:24px;padding:18px 20px;border:1px solid #f3d4b2;border-radius:12px;background:#fff7ed}\n".
             ".hsp-root .hsp-static__title{margin:0 0 8px;text-transform:uppercase;letter-spacing:.08em;font-weight:700;color:#b45309}\n".
             ".hsp-root .hsp-static__list{margin:0;padding-left:20px}\n".
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

        add_submenu_page(
            'hospoda-week',
            'Přílohy',
            'Přílohy',
            'manage_categories',
            'edit-tags.php?taxonomy=' . TAX_SIDE . '&post_type=' . CPT_MEAL
        );

        add_submenu_page(
            'hospoda-week',
            'Alergeny',
            'Alergeny',
            'manage_categories',
            'edit-tags.php?taxonomy=' . TAX_ALLERGEN . '&post_type=' . CPT_MEAL
        );

        add_submenu_page(
            'hospoda-week',
            'Nastavení exportu',
            'Nastavení exportu',
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
.hs-week-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;margin-top:10px}
.hs-week-day{border:1px solid #d6d6d6;padding:20px;background:#fff;border-radius:12px;box-shadow:0 2px 4px rgba(15,23,42,.04)}
.hs-week-day legend{margin:-20px -20px 16px;padding:18px 20px;border-bottom:1px solid #d3d9e5;border-radius:12px 12px 0 0;background:linear-gradient(135deg,#eef4ff,#f8fbff);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;box-shadow:inset 0 -1px 0 rgba(15,23,42,.06)}
.hs-week-day .hs-week-day__name{font-size:22px;letter-spacing:.05em;text-transform:uppercase;color:#0f172a;font-weight:800}
.hs-week-day legend .hs-week-day__date{font-size:14px;font-weight:600;color:#334155;text-transform:none;letter-spacing:0}
.hs-week-section{margin-bottom:20px}
.hs-week-section:last-of-type{margin-bottom:0}
.hs-week-section-title{margin:0 0 10px;font-size:14px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:#6b7280}
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
            $css2 = '.ui-autocomplete{z-index:100000 !important; background:#fff; border:1px solid #ccd0d4; box-shadow:0 2px 6px rgba(0,0,0,.1)} .ui-autocomplete .ui-menu-item-wrapper{padding:6px 10px} .ui-state-active{background:#f0f6ff}';
            wp_add_inline_style('hospoda-admin',$css2);
            wp_enqueue_script('jquery-ui-autocomplete');
            $js = <<<'JS'
        (function($){
          function attachAutocomplete($ctx){
            $ctx.find('.meal-autocomplete').each(function(){
              var $input = $(this);
              if ($input.data('ui-autocomplete')) return;
              $input.autocomplete({
                source: function(req,res){
                  $.get(ajaxurl, {_ajax_nonce:HOSPOS.nonce, action:'hospoda_meal_search', term:req.term}, function(data){ res(data); });
                },
                minLength: 2,
                select: function(e,ui){
                  $input.val(ui.item.label);
                  var $row = $input.closest('.row');
                  $row.find('.meal-id').val(ui.item.id);
                  // doplnit cenu
                  if (ui.item.price !== undefined) { $row.find('.price').val(ui.item.price); }
                  // doplnit přílohy
                  if (ui.item.sides && ui.item.sides.length){
                    $row.find('.sides input[type=checkbox]').prop('checked', false);
                    ui.item.sides.forEach(function(tid){
                      $row.find('.sides input[type=checkbox][value="'+tid+'"]').prop('checked', true);
                    });
                  }
                  return false;
                }
              });
            });
          }
          $(document).on('click','#add-row', function(){
            var idx = $('#mains .row.main').length;
            var $clone = $('#mains .row.main').first().clone();
            $clone.find('input').each(function(){
              var $i = $(this);
              if ($i.hasClass('meal-autocomplete')) { $i.val('').attr('name','mains['+idx+'][title]'); }
              else if ($i.hasClass('meal-id')) { $i.val('').attr('name','mains['+idx+'][id]'); }
              else if ($i.hasClass('price')) { $i.val('').attr('name','mains['+idx+'][price]'); }
            });
            $clone.find('.sides input[type=checkbox]').each(function(){
              $(this).prop('checked', false).attr('name','mains['+idx+'][sides][]');
            });
            $('#mains').append($clone);
            attachAutocomplete($clone);
          });
          $(document).on('click','.remove-row', function(){
            var $container = $(this).closest('.hs-mains');
            var $rows = $container.find('.row.main');
            if ($rows.length > 1) {
              $(this).closest('.row.main').remove();
            }
          });
          // Weekly editor: add row (build fresh row to avoid name collisions)
          $(document).on('click','.add-row-week', function(e){ e.preventDefault();
            var idx = $(this).data('week-index'); if (typeof idx === 'undefined'){ idx = $(this).closest('fieldset').data('week-index'); }
            var $wrap = $(this).closest('fieldset').find('.hs-mains');
            if (!$wrap.length){ $wrap = $(this).closest('.hs-week-day').find('.hs-mains'); }
            if (!$wrap.length){ return; }
            var $rows = $wrap.find('.row.main');
            var count = $rows.length; // index noveho radku

            // Vezmeme HTML příloh z prvního řádku dne (pokud existuje)
            var sidesHtml = '';
            if ($rows.length) {
              sidesHtml = $rows.first().find('.sides').html() || '';
            }

            var tmpl = ''+
              '<div class="row main">\n'+
              '  <input class="meal-autocomplete" name="week[mains]['+idx+']['+count+'][title]" type="text" placeholder="Název jídla…" value="">\n'+
              '  <input class="meal-id" type="hidden" name="week[mains]['+idx+']['+count+'][id]" value="">\n'+
              '  <input class="price" type="text" name="week[mains]['+idx+']['+count+'][price]" placeholder="Cena (Kč)" value="">\n'+
              '  <div class="sides">'+ sidesHtml +'</div>\n'+
              '  <button type="button" class="button link-button remove-row" data-week-index="'+idx+'">Odstranit</button>\n'+
              '</div>';

            var $row = $(tmpl);
            if (!$row.find('.sides').length){ $row.append('<div class="sides"></div>'); }
            // Reset a správná jména pro checkboxy příloh
            $row.find('.sides input[type=checkbox]').each(function(){
              $(this).prop('checked', false).attr('name','week[mains]['+idx+']['+count+'][sides][]');
            });

            $wrap.append($row);
            attachAutocomplete($row);
          });
          // Weekly editor: change week start
          $(document).on('change','#hs-week-start',function(){
            var d=$(this).val(); if(!d) return;
            var url=new URL(window.location.href);
            url.searchParams.set('week',d);
            window.location.href=url.toString();
          });
          $(function(){ attachAutocomplete($(document)); });
        })(jQuery);
JS;
            wp_localize_script('jquery-ui-autocomplete','HOSPOS',['nonce'=>wp_create_nonce('hospoda_meal_search')]);
            wp_add_inline_script('jquery-ui-autocomplete',$js);
        }

        if ($is_branding_page) {
            $css3 = '.hs-branding{margin:20px 0;padding:20px;border:1px solid #d0d0d0;border-radius:6px;background:#fff;max-width:960px}.hs-branding h2{margin-top:0}.hs-branding__logo{display:flex;align-items:flex-start;gap:12px;margin-bottom:12px}.hs-branding__preview{width:160px;min-height:120px;border:1px dashed #ccd0d4;border-radius:4px;display:flex;align-items:center;justify-content:center;background:#fafafa;overflow:hidden}.hs-branding__preview img{max-width:100%;height:auto;display:block}.hs-branding__preview span{color:#777;font-style:italic}.hs-branding textarea{max-width:100%}.hs-branding .description{margin-top:4px;color:#555}.hs-branding__controls{display:flex;flex-direction:column;gap:8px}.hs-branding__static{margin-top:24px;padding-top:16px;border-top:1px solid #d8d8d8}';
            wp_add_inline_style('hospoda-admin', $css3);
        }
    }

    private function get_pdf_branding_defaults(): array {
        return [
            'logo_id'     => 0,
            'top_text'    => "HOSPODA POD KOSTELEM\nJarošov nad Nežárkou\nDenní nabídka\nK hlavnímu jídlu polévka za 20 Kč · Kola 0,3 l k menu za 15 Kč\nVaříme PO–PÁ od 10:30 do 14:00. Objednávky přijímáme den předem do 16:00 na telefonu hospody nebo osobně u obsluhy.",
            'bottom_text' => "Seznam alergenů je k nahlédnutí u obsluhy. Pro více informací se ptejte personálu.\nV nabídce mohou nastat drobné změny podle dostupnosti surovin. Děkujeme za pochopení.",
            'static_menu' => '',
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

        if (isset($stored['static_menu'])) {
            $output['static_menu'] = $this->sanitize_multiline_text((string)$stored['static_menu']);
        }

        return $output;
    }

    private function get_static_menu_lines(): array {
        if (is_array($this->static_menu_cache)) {
            return $this->static_menu_cache;
        }

        $branding = $this->get_pdf_branding_settings();
        $raw = (string)($branding['static_menu'] ?? '');
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $lines = array_map('trim', explode("\n", $raw));
        $lines = array_values(array_filter($lines, static function ($line) {
            return $line !== '';
        }));

        $this->static_menu_cache = $lines;

        return $lines;
    }

    private function get_menu_preferences(): array {
        if (is_array($this->menu_preferences_cache)) {
            return $this->menu_preferences_cache;
        }

        $stored = get_option('hsp_menu_preferences', []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $defaults = ['soup_price_mode' => 'included'];
        $prefs = wp_parse_args($stored, $defaults);
        $prefs['soup_price_mode'] = $this->normalize_soup_price_mode($prefs['soup_price_mode'] ?? '');

        $this->menu_preferences_cache = $prefs;

        return $prefs;
    }

    private function normalize_soup_price_mode(string $value): string {
        return in_array($value, ['included', 'separate'], true) ? $value : 'included';
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
        $result = ['price'=>'','sides'=>[],'allergens'=>[]];
        $since = date('Y-m-d', strtotime('-180 days'));
        $days = get_posts([
            'post_type'=>CPT_DAY,
            'posts_per_page'=>300,
            'meta_query'=>[
                ['key'=>'menu_date','value'=>$since,'compare'=>'>=']
            ],
            'orderby'=>'meta_value','meta_key'=>'menu_date','order'=>'DESC'
        ]);
        foreach($days as $p){
            $mains = get_post_meta($p->ID,'mains',true);
            if (is_array($mains)){
                foreach($mains as $row){
                    $match = false;
                    if ($meal_id && !empty($row['id']) && intval($row['id']) === intval($meal_id)) $match = true;
                    elseif ($title !== '' && !empty($row['title']) && strcasecmp($row['title'],$title)===0) $match = true;
                    if ($match){
                        $result['price'] = $row['price'] ?? '';
                        $result['sides'] = array_map('intval', $row['sides'] ?? []);
                        $result['allergens'] = array_map('intval', $row['allergens'] ?? []);
                        return $result; // první (nejnovější)
                    }
                }
            }
            $soup = get_post_meta($p->ID,'soup',true);
            if (!empty($soup)){
                $match = false;
                if ($meal_id && !empty($soup['id']) && intval($soup['id']) === intval($meal_id)) $match = true;
                elseif ($title !== '' && !empty($soup['title']) && strcasecmp($soup['title'],$title)===0) $match = true;
                if ($match){
                    $result['price'] = $soup['price'] ?? '';
                    $result['sides'] = []; // polévky obvykle bez příloh
                    $result['allergens'] = array_map('intval', $soup['allergens'] ?? []);
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
        $sides = get_terms(['taxonomy'=>TAX_SIDE,'hide_empty'=>false]);
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
            'id'=>$soup_id,
            'title'=>$this->meal_title_by_id($soup_id,sanitize_text_field($soup_in['title']??'')),
            'price'=>sanitize_text_field($soup_in['price']??''),
        ];
        update_post_meta($post_id,'soup',$soup);
        $mains=[];
        foreach($_POST['mains']??[] as $row){
            $id=intval($row['id']??0);
            if (!$id && !empty($row['title'])){ $id = $this->ensure_meal_exists($row['title']); }
            $mains[]=[
                'id'=>$id,
                'title'=>$this->meal_title_by_id($id,sanitize_text_field($row['title']??'')),
                'price'=>sanitize_text_field($row['price']??''),
                'sides'=>array_map('intval',$row['sides']??[])
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
    private function render_week_day_block($index,$label,$date,$sides,$data){
        ?>
        <fieldset class="hs-week-day" data-week-index="<?php echo esc_attr($index); ?>">
          <legend>
            <span class="hs-week-day__name"><?php echo esc_html($label); ?></span>
            <span class="hs-week-day__date"><?php echo esc_html($this->format_admin_date($date)); ?></span>
          </legend>

          <div class="hs-week-section hs-week-section--soup">
            <h3 class="hs-week-section-title">Polévka</h3>
            <div class="row soup">
              <input class="meal-autocomplete" name="week[soup][<?php echo esc_attr($index); ?>][title]" type="text" placeholder="Polévka – začněte psát…" value="<?php echo esc_attr($data['soup']['title'] ?? ''); ?>">
              <input class="meal-id" type="hidden" name="week[soup][<?php echo esc_attr($index); ?>][id]" value="<?php echo esc_attr($data['soup']['id'] ?? ''); ?>">
              <input class="price" type="text" name="week[soup][<?php echo esc_attr($index); ?>][price]" placeholder="Cena (Kč)" value="<?php echo esc_attr($data['soup']['price'] ?? ''); ?>">
            </div>
          </div>

          <div class="hs-week-section hs-week-section--mains">
            <h3 class="hs-week-section-title">Hlavní jídla</h3>
            <div id="mains-<?php echo esc_attr($index); ?>" class="hs-mains">
              <?php
              if (!empty($data['mains'])) {
                  foreach ($data['mains'] as $i=>$row) {
                      $row_sides = $row['sides'] ?? [];
                      ?>
                      <div class="row main">
                        <input class="meal-autocomplete" name="week[mains][<?php echo esc_attr($index); ?>][<?php echo esc_attr($i); ?>][title]" type="text" placeholder="Název jídla…" value="<?php echo esc_attr($row['title'] ?? ''); ?>">
                        <input class="meal-id" type="hidden" name="week[mains][<?php echo esc_attr($index); ?>][<?php echo esc_attr($i); ?>][id]" value="<?php echo esc_attr($row['id'] ?? ''); ?>">
                        <input class="price" type="text" name="week[mains][<?php echo esc_attr($index); ?>][<?php echo esc_attr($i); ?>][price]" placeholder="Cena (Kč)" value="<?php echo esc_attr($row['price'] ?? ''); ?>">
                        <div class="sides">
                          <?php foreach ($sides as $side): $term_id = is_object($side)?$side->term_id:(isset($side['term_id'])?$side['term_id']:''); $term_name = is_object($side)?$side->name:(isset($side['name'])?$side['name']:''); ?>
                            <label><input type="checkbox" name="week[mains][<?php echo esc_attr($index); ?>][<?php echo esc_attr($i); ?>][sides][]" value="<?php echo esc_attr($term_id); ?>" <?php checked(in_array($term_id, $row_sides)); ?>> <?php echo esc_html($term_name); ?></label>
                          <?php endforeach; ?>
                        </div>
                        <button type="button" class="button link-button remove-row" data-week-index="<?php echo esc_attr($index); ?>">Odstranit</button>
                      </div>
                      <?php
                  }
              } else {
                  // prázdná výchozí řádka s korektními názvy polí
                  ?>
                  <div class="row main">
                    <input class="meal-autocomplete" name="week[mains][<?php echo esc_attr($index); ?>][0][title]" type="text" placeholder="Název jídla…" value="">
                    <input class="meal-id" type="hidden" name="week[mains][<?php echo esc_attr($index); ?>][0][id]" value="">
                    <input class="price" type="text" name="week[mains][<?php echo esc_attr($index); ?>][0][price]" placeholder="Cena (Kč)" value="">
                    <div class="sides">
                      <?php foreach ($sides as $side): $term_id = is_object($side)?$side->term_id:(isset($side['term_id'])?$side['term_id']:''); $term_name = is_object($side)?$side->name:(isset($side['name'])?$side['name']:''); ?>
                        <label><input type="checkbox" name="week[mains][<?php echo esc_attr($index); ?>][0][sides][]" value="<?php echo esc_attr($term_id); ?>"> <?php echo esc_html($term_name); ?></label>
                      <?php endforeach; ?>
                    </div>
                    <button type="button" class="button link-button remove-row" data-week-index="<?php echo esc_attr($index); ?>">Odstranit</button>
                  </div>
                  <?php
              }
              ?>
            </div>
            <p class="hs-week-add"><button type="button" class="button add-row-week" data-week-index="<?php echo esc_attr($index); ?>">Přidat jídlo</button></p>
          </div>
        </fieldset>
        <?php
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
        $sides_terms = get_terms(['taxonomy'=>TAX_SIDE,'hide_empty'=>false]);
        if (\is_wp_error($sides_terms)) {
            $sides_terms = [];
        }
        $sides_map = [];
        foreach ($sides_terms as $side) {
            $term_id = is_object($side) ? (int)$side->term_id : (int)($side['term_id'] ?? 0);
            $term_name = is_object($side) ? $side->name : ($side['name'] ?? '');
            if ($term_id) {
                $sides_map[$term_id] = $term_name;
            }
        }

        $days_data=[];
        for($i=0;$i<5;$i++){
            $posts=get_posts(['post_type'=>CPT_DAY,'posts_per_page'=>1,'meta_key'=>'menu_date','meta_value'=>$dates[$i]]);
            if($posts){
                $id=$posts[0]->ID;
                $days_data[$i]=[
                    'soup'=>get_post_meta($id,'soup',true),
                    'mains'=>get_post_meta($id,'mains',true)
                ];
            } else {
                $days_data[$i]=['soup'=>['id'=>'','title'=>'','price'=>''],'mains'=>[]];
            }
        }

        return [
            'monday'      => $monday,
            'labels'      => $labels,
            'dates'       => $dates,
            'days'        => $days_data,
            'sides_terms' => $sides_terms,
            'sides_map'   => $sides_map,
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
        $day_count = count($dates);
        ?>
        <div class="wrap">
          <h1>Týdenní menu</h1>
          <?php if (isset($_GET['saved'])) : ?>
            <div class="notice notice-success is-dismissible"><p>Týdenní menu bylo uloženo.</p></div>
          <?php endif; ?>
          <p>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=hospoda-week-branding')); ?>">Nastavení PDF exportu</a>
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
              <?php for($i=0;$i<$day_count;$i++){ $this->render_week_day_block($i,$labels[$i] ?? '',$dates[$i] ?? '',$sides,$days_data[$i] ?? []); } ?>
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
        $static_menu = (string)($branding['static_menu'] ?? '');
        ?>
        <div class="wrap">
          <h1>Nastavení PDF exportu</h1>
          <p class="description">Nastavení použité při generování týdenního jídelního lístku do PDF.</p>
          <?php if (isset($_GET['branding_saved'])) : ?>
            <div class="notice notice-success is-dismissible"><p>Nastavení PDF exportu bylo uloženo.</p></div>
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
              <p>
                <label for="hs-branding-static"><strong>Stálá nabídka</strong></label><br>
                <textarea name="branding_static" id="hs-branding-static" rows="4" class="large-text code"><?php echo esc_textarea($static_menu); ?></textarea>
                <span class="description">Každý řádek se zobrazí jako položka stálé nabídky v PDF i na webu pod aktuálním menu.</span>
              </p>
            </div>
            <fieldset class="hs-branding__soup">
              <legend><strong>Zobrazení ceny polévky</strong></legend>
              <label><input type="radio" name="soup_price_mode" value="included" <?php checked('included', $soup_mode); ?>> Polévka je v ceně menu (nezobrazovat cenu zvlášť)</label><br>
              <label><input type="radio" name="soup_price_mode" value="separate" <?php checked('separate', $soup_mode); ?>> Polévka se účtuje zvlášť (zobrazit cenu samostatně)</label>
              <p class="description">Nastavení ovlivní veřejné zobrazení jídelníčku i export do PDF.</p>
            </fieldset>
            <p>
              <button type="submit" class="button button-primary">Uložit nastavení PDF</button>
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
        $week = $_POST['week'] ?? [];

        // Uložení 5 pracovních dní (Po–Pá)
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
                'id'    => $soup_id,
                'title' => $this->meal_title_by_id($soup_id, sanitize_text_field($soup_in['title'] ?? '')),
                'price' => sanitize_text_field($soup_in['price'] ?? ''),
            ];
            update_post_meta($post_id, 'soup', $soup);

            // Hlavní jídla
            $mains = [];
            $mains_in = $week['mains'][$i] ?? [];
            foreach ($mains_in as $row) {
                $id = intval($row['id'] ?? 0);
                if (!$id && !empty($row['title'])){ $id = $this->ensure_meal_exists($row['title']); }
                $mains[] = [
                    'id'    => $id,
                    'title' => $this->meal_title_by_id($id, sanitize_text_field($row['title'] ?? '')),
                    'price' => sanitize_text_field($row['price'] ?? ''),
                    'sides' => array_map('intval', $row['sides'] ?? []),
                ];
            }
            update_post_meta($post_id, 'mains', $mains);
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
        $static = isset($_POST['branding_static']) ? sanitize_textarea_field(wp_unslash($_POST['branding_static'])) : '';

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
            'logo_id'     => max(0, $new_logo_id),
            'top_text'    => $this->sanitize_multiline_text($top),
            'bottom_text' => $this->sanitize_multiline_text($bottom),
            'static_menu' => $this->sanitize_multiline_text($static),
        ];

        update_option('hsp_pdf_branding', $data, false);
        $this->static_menu_cache = null;

        $preferences = [
            'soup_price_mode' => $this->normalize_soup_price_mode(isset($_POST['soup_price_mode']) ? sanitize_text_field(wp_unslash($_POST['soup_price_mode'])) : ''),
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

        require_once __DIR__ . '/includes/class-simple-pdf.php';
        require_once __DIR__ . '/includes/class-week-pdf-exporter.php';

        $exporter = new Week_Pdf_Exporter();
        $exportOptions = [
            'show_soup_price' => $this->should_show_soup_price(),
            'static_menu'     => $this->get_static_menu_lines(),
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
            echo '<ul>';
            foreach($mains as $row){
                $line = esc_html($row['title'] ?? '');
                if (!empty($row['sides'])){
                    $names = array_map(function($tid){ $t = get_term($tid); return $t ? $t->name : ''; }, $row['sides']);
                    $line .= ' (' . esc_html(implode(', ', array_filter($names))) . ')';
                }
                if (!empty($row['price'])) $line .= ' — '.esc_html($row['price']).' Kč';
                echo '<li>'.$line.'</li>';
            }
            echo '</ul>';
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
            echo '<ul class="hsp-mains">';
            foreach($mains as $row){
                $title = esc_html($row['title'] ?? '');
                $price = !empty($row['price']) ? '<span class="hsp-price">'.esc_html($row['price']).' Kč</span>' : '';
                $sidesText = '';
                if (!empty($row['sides'])){
                    $names = array_map(function($tid){ $t = get_term($tid); return $t ? $t->name : ''; }, $row['sides']);
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
        echo '</div>'; // .hsp-body
        echo '</div>';
        return ob_get_clean();
    }

    private function render_static_menu_block(): string {
        $items = $this->get_static_menu_lines();
        if (empty($items)) {
            return '';
        }

        ob_start();
        echo '<div class="hsp-static">';
        echo '<h4 class="hsp-static__title">Stálá nabídka</h4>';
        echo '<ul class="hsp-static__list">';
        foreach ($items as $line) {
            echo '<li>' . esc_html($line) . '</li>';
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