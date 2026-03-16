/**
 * Admin JavaScript für Event Umbuchungen
 */
jQuery(document).ready(function($) {
    // Variablen für die Paginierung
    let currentPage = 1;
    let totalPages = 1;
    let currentOrderId = null;
    let currentOrderData = null;
    let prefillProductId = null;

    // Event-Handler für die Suche
    $('#search-orders-btn').on('click', function() {
        searchOrders(1);
    });

    $('#customer-search').on('keypress', function(e) {
        if (e.which === 13) {
            searchOrders(1);
        }
    });

    // Funktion zum Suchen von Bestellungen
    function searchOrders(page) {
        const searchTerm = $('#customer-search').val();
        currentPage = page;

        // Ladeanimation anzeigen
        $('#rebooking-orders-body').html('<tr><td colspan="6">Suche läuft...</td></tr>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'search_event_orders',
                search: searchTerm,
                page: page,
                _wpnonce: wpApiSettings.nonce // Stellen Sie sicher, dass der Nonce korrekt übergeben wird
            },
            success: function(response) {
                if (response.success) {
                    // Tabelle mit Ergebnissen füllen
                    const orders = response.data.orders;
                    let html = '';

                    if (orders.length === 0) {
                        html = '<tr><td colspan="6">Keine Bestellungen gefunden.</td></tr>';
                    } else {
                        orders.forEach(function(order) {
                            html += '<tr>';
                            html += '<td>#' + order.id + '</td>';
                            html += '<td>' + order.customer + '</td>';
                            html += '<td>' + order.event_title + '</td>';
                            html += '<td>' + order.event_date + '</td>';
                            html += '<td>' + order.participants_count + ' <div class="participants-list">' + order.participants + '</div></td>';
                            html += '<td><a class="rebook-action" data-order=\'' + JSON.stringify(order) + '\'>Umbuchen</a></td>';
                            html += '</tr>';
                        });
                    }

                    $('#rebooking-orders-body').html(html);

                    // Paginierung aktualisieren
                    totalPages = response.data.total_pages;
                    updatePagination();

                    // Event-Handler für Umbuchungs-Links
                    $('.rebook-action').on('click', function() {
                        const orderData = $(this).data('order');
                        openRebookingModal(orderData);
                    });
                } else {
                    $('#rebooking-orders-body').html('<tr><td colspan="6">Fehler beim Suchen: ' + response.data + '</td></tr>');
                    // Fehler in der Konsole protokollieren
                    console.error('Fehler bei der Suchanfrage:', response);
                }
            },
            error: function(xhr, status, error) {
                // Detaillierte Fehlerinformationen anzeigen und protokollieren
                const errorDetails = xhr.responseText ? xhr.responseText : 'Keine Fehlerdetails verfügbar';
                console.error('AJAX-Fehler bei der Suche:', {
                    status: status,
                    error: error,
                    xhr: xhr,
                    response: errorDetails
                });

                $('#rebooking-orders-body').html('<tr><td colspan="6">Fehler bei der Anfrage: ' + status + ' - ' + error + '<br>Details: ' + errorDetails + '</td></tr>');
            }
        });
    }

    // Paginierung aktualisieren
    function updatePagination() {
        let paginationHtml = '<div class="tablenav-pages">';

        if (totalPages > 1) {
            paginationHtml += '<span class="displaying-num">' + totalPages + ' Seiten</span>';
            paginationHtml += '<span class="pagination-links">';

            // Zurück-Button
            if (currentPage > 1) {
                paginationHtml += '<a class="prev-page button" data-page="' + (currentPage - 1) + '"><span class="screen-reader-text">Vorherige Seite</span><span aria-hidden="true">‹</span></a>';
            } else {
                paginationHtml += '<span class="prev-page button disabled"><span class="screen-reader-text">Vorherige Seite</span><span aria-hidden="true">‹</span></span>';
            }

            // Aktuelle Seite / Gesamt
            paginationHtml += '<span class="paging-input"><input class="current-page" type="text" value="' + currentPage + '" size="1"> von <span class="total-pages">' + totalPages + '</span></span>';

            // Weiter-Button
            if (currentPage < totalPages) {
                paginationHtml += '<a class="next-page button" data-page="' + (currentPage + 1) + '"><span class="screen-reader-text">Nächste Seite</span><span aria-hidden="true">›</span></a>';
            } else {
                paginationHtml += '<span class="next-page button disabled"><span class="screen-reader-text">Nächste Seite</span><span aria-hidden="true">›</span></span>';
            }

            paginationHtml += '</span>';
        }

        paginationHtml += '</div>';

        $('#rebooking-orders-pagination').html(paginationHtml);

        // Event-Handler für Pagination
        $('.pagination-links .button:not(.disabled)').on('click', function() {
            const page = parseInt($(this).data('page'));
            searchOrders(page);
        });

        $('.current-page').on('keypress', function(e) {
            if (e.which === 13) {
                const page = parseInt($(this).val());
                if (page > 0 && page <= totalPages) {
                    searchOrders(page);
                }
            }
        });
    }

    // Modal für Umbuchung öffnen
    function openRebookingModal(orderData) {
        currentOrderId = orderData.id;
        currentOrderData = orderData;
        prefillProductId = orderData.event_product_id || null;

        // Aktuelle Bestelldaten anzeigen
        $('#current-order-id').text('#' + orderData.id);
        $('#current-customer').text(orderData.customer);
        $('#current-event').text(orderData.event_title);
        $('#current-date').text(orderData.event_date);

        $('#rebooking-action').val('rebook');
        $('#new-event-date').html('<option value="">' + rebookingAdminL10n.pleaseSelectEventFirst + '</option>');
        $('#new-event-select').val('');

        // Events laden
        loadAvailableEvents();

        // Modal anzeigen
        $('#rebooking-modal').show();
    }

    // Verfügbare Events laden
    function loadAvailableEvents() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'get_available_events',
                _wpnonce: wpApiSettings.nonce
            },
            success: function(response) {
                if (response.success) {
                    const events = response.data;
                    let html = '<option value="">' + rebookingAdminL10n.pleaseSelectEvent + '</option>';

                    events.forEach(function(event) {
                        html += '<option value="' + event.id + '">' + event.title + '</option>';
                    });

                    $('#new-event-select').html(html);

                    if ($('#rebooking-action').val() === 'rebook' && currentOrderData && currentOrderData.event_id) {
                        $('#new-event-select').val(String(currentOrderData.event_id)).trigger('change');
                    } else {
                        $('#new-event-select').val('');
                        $('#new-event-date').html('<option value="">' + rebookingAdminL10n.pleaseSelectEventFirst + '</option>');
                    }
                } else {
                    console.error('Fehler beim Laden der Events:', response);
                    alert('Fehler beim Laden der Events: ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                const errorDetails = xhr.responseText ? xhr.responseText : 'Keine Fehlerdetails verfügbar';
                console.error('AJAX-Fehler beim Laden der Events:', {
                    status: status,
                    error: error,
                    xhr: xhr,
                    response: errorDetails
                });
                alert('Fehler bei der Anfrage: ' + status + ' - ' + error + '\nDetails: ' + errorDetails);
            }
        });
    }

    // Event-Handler für die Event-Auswahl
    $('#new-event-select').on('change', function() {
        const eventId = $(this).val();

        if (eventId) {
            // Verfügbare Termine für das Event laden
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'get_available_events',
                    event_id: eventId,
                    _wpnonce: wpApiSettings.nonce
                },
                success: function(response) {
                    if (response.success) {
                        const dates = response.data;
                        let html = '<option value="">' + rebookingAdminL10n.pleaseSelectDate + '</option>';

                        if (dates.products && dates.products.length > 0) {
                            dates.products.forEach(function(product) {
                                html += '<option value="' + product.id + '">' + product.date + ' (' + product.availability + ' verfügbar)</option>';
                            });
                        } else {
                            html = '<option value="">' + rebookingAdminL10n.noDatesAvailable + '</option>';
                        }

                        $('#new-event-date').html(html);

                        if ($('#rebooking-action').val() === 'rebook' && prefillProductId) {
                            const prefillValue = String(prefillProductId);
                            if ($('#new-event-date option[value="' + prefillValue + '"]').length) {
                                $('#new-event-date').val(prefillValue);
                            }
                            prefillProductId = null;
                        } else {
                            $('#new-event-date').val('');
                        }
                    } else {
                        console.error('Fehler beim Laden der Termine:', response);
                        alert('Fehler beim Laden der Termine: ' + response.data);
                    }
                },
                error: function(xhr, status, error) {
                    const errorDetails = xhr.responseText ? xhr.responseText : 'Keine Fehlerdetails verfügbar';
                    console.error('AJAX-Fehler beim Laden der Termine:', {
                        status: status,
                        error: error,
                        xhr: xhr,
                        response: errorDetails
                    });
                    alert('Fehler bei der Anfrage: ' + status + ' - ' + error + '\nDetails: ' + errorDetails);
                }
            });
        } else {
            // Event zurücksetzen
            $('#new-event-date').html('<option value="">' + rebookingAdminL10n.pleaseSelectEventFirst + '</option>');
        }
    });

    // Event-Handler für Modal schließen
    $('.rebooking-modal-close, .rebooking-modal-cancel').on('click', function() {
        $('#rebooking-modal').hide();
    });

    // Event-Handler für Umbuchung durchführen
    // Im Event-Handler für Umbuchung durchführen
    $('#process-rebooking-btn').on('click', function() {
        const newEventId = $('#new-event-select').val();
        const newProductId = $('#new-event-date').val();
        const deleteOldOrder = $('#delete-old-order').is(':checked');
        const transferMode = $('#rebooking-action').val() || 'rebook';

        if (!newEventId || !newProductId) {
            alert('Bitte wählen Sie ein Event und einen Termin aus.');
            return;
        }

        // Bestätigung anfordern
        if (!confirm('Wollen Sie die Umbuchung wirklich durchführen?')) {
            return;
        }

        // Ladeindikator anzeigen
        $('#process-rebooking-btn').prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin-right:5px;"></span> Umbuchung läuft...');
        $('.rebooking-modal-cancel').prop('disabled', true);

        // Umbuchung durchführen
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'rebook_order',
                old_order_id: currentOrderId,
                new_product_id: newProductId,
                delete_old_order: deleteOldOrder,
                transfer_mode: transferMode,
                _wpnonce: wpApiSettings.nonce
            },
            success: function(response) {
                // Ladeindikator entfernen
                $('#process-rebooking-btn').prop('disabled', false).html('Umbuchung durchführen');
                $('.rebooking-modal-cancel').prop('disabled', false);

                if (response.success) {
                    alert('Umbuchung erfolgreich durchgeführt. Neue Bestellnummer: #' + response.data.new_order_id);
                    $('#rebooking-modal').hide();
                    searchOrders(currentPage);
                } else {
                    console.error('Fehler bei der Umbuchung:', response);
                    alert('Fehler bei der Umbuchung: ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                // Ladeindikator entfernen
                $('#process-rebooking-btn').prop('disabled', false).html('Umbuchung durchführen');
                $('.rebooking-modal-cancel').prop('disabled', false);

                const errorDetails = xhr.responseText ? xhr.responseText : 'Keine Fehlerdetails verfügbar';
                console.error('AJAX-Fehler bei der Umbuchung:', {
                    status: status,
                    error: error,
                    xhr: xhr,
                    response: errorDetails
                });
                alert('Fehler bei der Anfrage: ' + status + ' - ' + error + '\nDetails: ' + errorDetails);
            }
        });
    });

    $('#rebooking-action').on('change', function() {
        if ($(this).val() === 'rebook') {
            prefillProductId = currentOrderData ? currentOrderData.event_product_id : null;
            if (currentOrderData && currentOrderData.event_id) {
                $('#new-event-select').val(String(currentOrderData.event_id)).trigger('change');
            }
        } else {
            prefillProductId = null;
        }
    });
});
