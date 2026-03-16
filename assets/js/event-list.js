(function($) {
    'use strict';

    let currentPage = 1;
    let isLoading = false;
    let currentOffer = '';
    let currentAge = '';
    let currentLocation = '';
    let currentWeekday = '';
    let currentKlasse = '';

    // EVENT LOADING & RENDERING
    function loadEvents(loadMore = false) {
      console.log("🔍 loadEvents() wurde aufgerufen! Filter:", { offer: currentOffer, age: currentAge, location: currentLocation, weekday: currentWeekday });
        if (isLoading) return;
        isLoading = true;

        const $container = $('#parkourone-event-list');
        const $loadMoreBtn = $('#load-more-events');

        if (!loadMore) {
            window.eventSkeletons.show($container);
        } else {
            $loadMoreBtn.addClass('loading').text('Lade...');
        }

        const url = new URL(eventListData.apiUrl);
        url.searchParams.append('page', currentPage);
        if (currentOffer) url.searchParams.append('offer', currentOffer);
        if (currentAge) url.searchParams.append('age', currentAge);
        if (currentLocation) url.searchParams.append('location', currentLocation);
        if (currentWeekday) url.searchParams.append('weekday', currentWeekday);
        if (currentKlasse) url.searchParams.append('klasse', currentKlasse)

        console.log("API Request URL:", url.toString());

        $.get(url.toString())
            .done(function(response) {
                const events = response.events;
                if (!loadMore) {
                    $container.empty();
                }
                if (events && events.length > 0) {
                    events.forEach(function(event) {
                        $container.append(renderEvent(event));
                    });
                    initializeAccordion();
                    initializeParticipantFields();

                    if (response.has_more) {
                        if (!$loadMoreBtn.length) {
                            $container.after('<button id="load-more-events" class="button">Weitere Events laden</button>');
                        } else {
                            $loadMoreBtn.removeClass('loading').text('Weitere Events laden');
                        }
                    } else {
                        $loadMoreBtn.remove();
                    }


                    if (currentKlasse && response.events && response.events.length === 1) {
                                    setTimeout(function() {
                                        $('.parkourone-event .event-summary').trigger('click');
                                    }, 300);
                                }

                } else {
                    if (!loadMore) {
                        $container.html('<p>Zu dieser Filter-Kombination gibt es derzeit leider kein Angebot. Bitte passe deine Auswahl an.</p>');
                    }
                    $loadMoreBtn.remove();
                }

                        })


            .fail(function(error) {
                console.error('Error loading events:', error);
                if (!loadMore) {
                    $container.html('<p>Fehler beim Laden der Events.</p>');
                }
            })
            .always(function() {
                isLoading = false;
                if (!loadMore) {
                    window.eventSkeletons.hide($container);
                }
            });
    }

    function renderEvent(event) {
        const [day, month, year] = event.date.split('-');
        const date = new Date(year, month - 1, day);
        return `
            <div class="parkourone-event ${event.stock <= 0 ? 'sold-out' : ''}" data-event-id="${event.product_id}" data-event-date="${event.date}" data-event-permalink="${event.permalink || ''}">
                <div class="event-summary">
                    <div class="event-date">
                        <span class="event-day">${date.getDate()}</span>
                        <span class="event-month">${date.toLocaleString('de-DE', {month: 'long'})}</span>
                    </div>
                    <div class="event-details">
                        <div class="event-info">
                            <h3 class="event-title">${event.title}</h3>
                            <div class="event-meta">
                                <span class="event-time">${event.start_time} - ${event.end_time}</span>
                                <span class="event-venue">${event.venue}</span>
                                <span class="event-headcoach">${event.headcoach}</span>
                                <div class="event-price-availability">
                                <span class="event-price">${event.price} ${eventListData.currencySymbol}</span>
                                    <span class="event-capacity">${event.stock > 0 ? event.stock + ' verfügbar' : 'Ausgebucht'}</span>
                                </div>
                                <span class="book-now-link">Jetzt buchen</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="event-details-dropdown">
                    ${event.dropdown_info ? `<div class="event-dropdown-info">${event.dropdown_info}</div>` : ''}
                    <p>${event.description}</p>
                    ${event.stock > 0 ? renderBookingForm(event) : ''}
                </div>
            </div>
        `;
    }

    function renderBookingForm(event) {
        let form = `
            <form class="cart">
                <input type="hidden" name="event_id" value="${event.product_id}">
                <div class="participant-container" data-product-id="${event.product_id}" data-product-price="${event.price}">`;

        for (let i = 0; i < event.stock; i++) {
            const visibility_class = i === 0 ? '' : 'hidden';
            form += `
                <div class="participant-fields ${visibility_class}">
                    <input type="text" name="event_participant_name[]" placeholder="Name" ${i === 0 ? 'required' : ''}>
                    <input type="text" name="event_participant_vorname[]" placeholder="Vorname" ${i === 0 ? 'required' : ''}>
                    <div class="date-input-container" data-placeholder="Geburtsdatum">
      <input type="text" name="event_participant_geburtsdatum[]" class="date-input" ${i === 0 ? 'required' : ''}>
  </div>

                </div>`;
        }

        form += `
                </div>
                <div class="participant-buttons">
                    ${event.stock > 1 ? `
                        <button type="button" class="add-participant">Teilnehmer_in hinzufügen</button>
                        <button type="button" class="remove-participant" style="display: none;">Teilnehmer_in entfernen</button>
                    ` : ''}
                </div>
                <button type="submit" name="add-to-cart" value="${event.product_id}" class="single_add_to_cart_button button alt">Jetzt buchen</button>
            </form>`;

        return form;
    }

    function initializeAccordion() {
        $('.event-summary').off('click').on('click', function(e) {
            var $event = $(this).closest('.parkourone-event');
            if ($event.hasClass('sold-out')) {
                e.preventDefault();
                e.stopPropagation();
                var $capacitySpan = $event.find('.event-capacity');
                $capacitySpan.addClass('flash');
                setTimeout(function() {
                    $capacitySpan.removeClass('flash');
                }, 1000);
                return;
            }
            e.preventDefault();
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

    function initializeParticipantFields($context) {
        $context = $context || $(document);
        $context.find('.add-participant').off('click').on('click', function(e) {
            e.preventDefault();
            var $container = $(this).closest('form').find('.participant-container');
            var $hiddenField = $container.find('.participant-fields.hidden').first();
            if ($hiddenField.length) {
                $hiddenField.removeClass('hidden').find('input').prop('required', true);
                updatePrice($container);
                $(this).siblings('.remove-participant').show();
                if ($container.find('.participant-fields.hidden').length === 0) {
                    $(this).hide();
                }
            }
        });

        $context.find('.remove-participant').off('click').on('click', function(e) {
            e.preventDefault();
            var $container = $(this).closest('form').find('.participant-container');
            var $lastVisibleField = $container.find('.participant-fields:not(.hidden)').last();
            if ($lastVisibleField.length && !$lastVisibleField.is(':first-child')) {
                $lastVisibleField.addClass('hidden').find('input').prop('required', false).val('');
                updatePrice($container);
                $(this).siblings('.add-participant').show();
                if ($container.find('.participant-fields:not(.hidden)').length === 1) {
                    $(this).hide();
                }
            }
        });

        $context.find('.participant-fields input').on('focus', function() {
            $(this).closest('.participant-fields').addClass('focus');
        }).on('blur', function() {
            $(this).closest('.participant-fields').removeClass('focus');
        });

        if (/Mobi|Android/i.test(navigator.userAgent)) {
            // Mobile: Input direkt als "date"
            $context.find('.date-input').attr('type', 'date');
        } else {
            // Desktop: Beim Fokus zum "date"-Typ wechseln und Klassen setzen für den Pseudo-Platzhalter
            $context.find('.date-input')
                .on('focus', function() {
                    $(this).attr('type', 'date');
                    $(this).closest('.date-input-container').addClass('focused');
                })
                .on('blur', function() {
                    if (!this.value) {
                        $(this).attr('type', 'text');
                    }
                    $(this).closest('.date-input-container').removeClass('focused');
                })
                .on('input', function() {
                    if ($(this).val()) {
                        $(this).closest('.date-input-container').addClass('filled');
                    } else {
                        $(this).closest('.date-input-container').removeClass('filled');
                    }
                });
        }

    }

    function updatePrice($container) {
        var productPrice = parseFloat($container.data('product-price'));
        var $form = $container.closest('form');
        var $priceDisplay = $form.find('.price-amount');
        var visibleFields = $container.find('.participant-fields:not(.hidden)').length;
        var totalPrice = productPrice * visibleFields;
        $priceDisplay.text(totalPrice.toFixed(2) + ' ' + eventListData.currencySymbol);
    }

    function initializeAdvancedFilter() {
        $('.parkourone-filter').on('change', function() {
            currentPage = 1;
            currentOffer = $('#offer-filter').val();
            currentAge = $('#age-filter').val();
            currentLocation = $('#location-filter').val();
            currentWeekday = $('#weekday-filter').val();
            loadEvents();
        });
    }

    $(document).on('click', '#load-more-events', function() {
        if (!isLoading) {
            currentPage++;
            loadEvents(true);
        }
    });

    $(document).ready(function() {
        function getQueryParam(param) {
            const urlParams = new URLSearchParams(window.location.search);
            return urlParams.get(param) ? urlParams.get(param).toLowerCase() : '';
        }

        currentOffer = getQueryParam('offer');
        currentAge = getQueryParam('age');
        currentLocation = getQueryParam('location');
        currentWeekday = getQueryParam('weekday');
        currentKlasse = getQueryParam('klasse');

        $('#offer-filter').val(currentOffer);
        $('#age-filter').val(currentAge);
        $('#location-filter').val(currentLocation);
        $('#weekday-filter').val(currentWeekday);

        console.log("🔍 loadEvents() wird ausgelöst. Filter:", {
        offer: currentOffer,
        age: currentAge,
        location: currentLocation,
        weekday: currentWeekday,
        klasse: currentKlasse // Klasse-Parameter in Log ausgeben
    });

        if (!currentOffer && !currentAge && !currentLocation && !currentWeekday) {
            console.log("ℹ️ Keine Filter vorhanden, lade alle Events.");
        }

        loadEvents();

        initializeAdvancedFilter();
    });

})(jQuery);
