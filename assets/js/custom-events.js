(function($) {
    'use strict';

    function initializeAccordion() {
      $('.event-summary').off('click').on('click', function(e) {
          var $event = $(this).closest('.parkourone-event');
  console.log("Event geklickt:", $event, "Sold out?", $event.hasClass('sold-out'));
          // Bei ausverkauftem Event
          if ($event.hasClass('sold-out')) {
              e.preventDefault();
              e.stopPropagation();
              return;
          }

          e.preventDefault();
          e.stopPropagation();

          var $dropdown = $event.find('.event-details-dropdown');
          $('.parkourone-event').not($event).removeClass('active').find('.event-details-dropdown').slideUp();
          $event.toggleClass('active');
          $dropdown.slideToggle({
              duration: 300,
              start: function() {
                  if ($dropdown.is(':visible')) {
                      initializeParticipantFields($dropdown);
                  }
              },
              complete: function() {
                  $dropdown.css('display', '');
              }
          });
      });
    }

    function updatePrice($container) {
        var productPrice = parseFloat($container.data('product-price'));
        var $form = $container.closest('form');
        var $priceDisplay = $form.find('.price-amount');
        var visibleFields = $container.find('.participant-fields:not(.hidden)').length;
        var totalPrice = productPrice * visibleFields;
        $priceDisplay.text(totalPrice.toFixed(2) + ' ' + customEventsParams.currencySymbol);
    }

    function initializeParticipantFields($context) {
        $context = $context || $(document);

        $context.find('.add-participant').off('click').on('click', function(e) {
            e.preventDefault();
            var $container = $(this).siblings('.participant-container');
            var $hiddenField = $container.find('.participant-fields.hidden').first();
            if ($hiddenField.length) {
                $hiddenField.removeClass('hidden').find('input').prop('required', true);
                updatePrice($container);
                updateParticipantNumbers($container);
            }
            if ($container.find('.participant-fields.hidden').length === 0) {
                $(this).hide();
            }
        });

        $context.find('.participant-container').off('click', '.remove-participant-wrapper').on('click', '.remove-participant-wrapper', function(e) {
            e.preventDefault();
            var $wrapper = $(this);
            var $container = $wrapper.closest('.participant-container');
            var $fields = $wrapper.closest('.participant-fields');
            $fields.addClass('hidden').find('input').prop('required', false).val('');
            $container.siblings('.add-participant').show();
            updatePrice($container);
            updateParticipantNumbers($container);
        });
    }

    function updateParticipantNumbers($container) {
        $container.find('.participant-fields:not(.hidden)').each(function(index) {
            var $removeWrapper = $(this).find('.remove-participant-wrapper');
            if ($removeWrapper.length) {
                $removeWrapper.find('.remove-participant-text').text('Teilnehmer ' + (index + 1) + ' entfernen?');
            }
        });
    }

    function initializeAdvancedFilter() {
        $('.parkourone-filter').on('change', function() {
            updateEventList();
        });
    }

    function updateEventList() {
        var ageFilter = $('#age-filter').val();
        var locationFilter = $('#location-filter').val();
        var weekdayFilter = $('#weekday-filter').val();

        $.ajax({
            url: customEventsParams.ajaxurl,
            type: 'POST',
            data: {
                action: 'filter_events',
                age: ageFilter,
                location: locationFilter,
                weekday: weekdayFilter,
                nonce: customEventsParams.nonce
            },
            success: function(response) {
                $('#parkourone-event-list').html(response.data);
                initializeAccordion();
                initializeParticipantFields();
            },
            error: function(xhr, status, error) {
                console.error('Error fetching filtered events:', error);
            }
        });
    }

    $(document).ready(function() {
        initializeAccordion();
        initializeParticipantFields();
        initializeAdvancedFilter();

        $(document).on('ajaxComplete', function() {
            initializeAccordion();
            initializeParticipantFields();
        });
    });
})(jQuery);
