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

  function initPaletteHandlers($root){
    if (!$root.data('hsColorPaletteReady')) {
      $root.on('click', '.hs-color-swatch', function(event){
        event.preventDefault();
        var $swatch = $(this);
        var color = normaliseColor($swatch.data('color'));
        var $input = $swatch.closest('.hs-branding__field').find('.hs-color-field');
        if (!$input.length || !color) {
          return;
        }
        if (typeof $input.wpColorPicker === 'function') {
          try {
            $input.wpColorPicker('color', color);
          } catch (err) {
            $input.val(color).trigger('change');
          }
        } else {
          $input.val(color).trigger('change');
        }
        updatePaletteState($input, color);
      });

      $root.on('input change', '.hs-color-field', function(){
        updatePaletteState($(this), $(this).val());
      });
      $root.data('hsColorPaletteReady', true);
    }
  }

  function hydratePickers($root){
    $root.find('.hs-color-field').each(function(){
      var $input = $(this);
      if ($input.data('wpColorPickerInit')) {
        updatePaletteState($input, $input.val());
        return;
      }

      $input.wpColorPicker({
        change: function(event, ui){
          var value = ui && ui.color ? ui.color.toString() : '';
          updatePaletteState($(event.target), value);
        },
        clear: function(event){
          updatePaletteState($(event.target), '');
        }
      });
      $input.data('wpColorPickerInit', true);
      updatePaletteState($input, $input.val());
    });
  }

  function bootstrap(){
    var $root = $('.hs-branding');
    if (!$root.length) {
      return true;
    }
    if (typeof $.fn.wpColorPicker !== 'function') {
      return false;
    }

    initPaletteHandlers($root);
    hydratePickers($root);
    return true;
  }

  function initWhenReady(){
    if (bootstrap()) {
      return;
    }

    var retries = 0;
    var timer = window.setInterval(function(){
      retries++;
      if (bootstrap() || retries > 40) {
        window.clearInterval(timer);
      }
    }, 150);
  }

  if (document.readyState === 'loading') {
    $(initWhenReady);
  } else {
    initWhenReady();
  }
})(jQuery);
