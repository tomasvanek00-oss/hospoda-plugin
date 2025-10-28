(function($){
  'use strict';

  function normaliseColor(value){
    return (value || '').toString().trim().toLowerCase();
  }

  function updatePaletteState($input, color){
    if (!$input || !$input.length) {
      return;
    }
    var normalised = normaliseColor(typeof color !== 'undefined' ? color : $input.val());
    $input.closest('.hs-branding__field').find('.hs-color-swatch').each(function(){
      var $swatch = $(this);
      var swatchColor = normaliseColor($swatch.data('color'));
      $swatch.toggleClass('is-active', swatchColor !== '' && swatchColor === normalised);
    });
  }

  function initColorPickers(){
    var $root = $('.hs-branding');
    if (!$root.length || typeof $.fn.wpColorPicker !== 'function') {
      return;
    }

    $root.find('.hs-color-field').each(function(){
      var $input = $(this);
      $input.wpColorPicker({
        change: function(event, ui){
          var value = ui && ui.color ? ui.color.toString() : '';
          updatePaletteState($(event.target), value);
        },
        clear: function(event){
          updatePaletteState($(event.target), '');
        }
      });
      updatePaletteState($input, $input.val());
    });

    $root.on('click', '.hs-color-swatch', function(event){
      event.preventDefault();
      var $swatch = $(this);
      var color = normaliseColor($swatch.data('color'));
      var $input = $swatch.closest('.hs-branding__field').find('.hs-color-field');
      if (!$input.length || !color) {
        return;
      }
      if (typeof $input.wpColorPicker === 'function') {
        $input.wpColorPicker('color', color);
      }
      $input.val(color).trigger('change');
      updatePaletteState($input, color);
    });

    $root.on('input change', '.hs-color-field', function(){
      updatePaletteState($(this), $(this).val());
    });
  }

  if (document.readyState === 'loading') {
    $(initColorPickers);
  } else {
    initColorPickers();
  }
})(jQuery);
