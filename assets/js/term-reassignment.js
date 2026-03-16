/**
 * JavaScript für Event-Kategorie Term Reassignment
 * Fängt Delete-Klicks auf edit-tags.php ab und zeigt ein Modal
 * zur Umkategorisierung der zugeordneten Events.
 */
jQuery(document).ready(function($) {
    let currentTermId = null;
    let pendingDeleteUrl = null;
    let pendingBulkForm = null;
    let skipBulkIntercept = false;

    // Modal-HTML ins DOM einfügen
    const modalHtml =
        '<div id="term-reassignment-modal" style="display:none;">' +
            '<div class="term-reassignment-backdrop"></div>' +
            '<div class="term-reassignment-content">' +
                '<div class="term-reassignment-header">' +
                    '<h2>' + termReassignmentL10n.modalTitle + '</h2>' +
                    '<span class="term-reassignment-close">&times;</span>' +
                '</div>' +
                '<div class="term-reassignment-body">' +
                    '<div id="term-reassignment-loading">' +
                        '<span class="spinner is-active"></span> ' + termReassignmentL10n.loading +
                    '</div>' +
                    '<div id="term-reassignment-info" style="display:none;">' +
                        '<p id="term-event-count-text"></p>' +
                        '<div id="term-reassignment-select-wrapper" class="form-field" style="display:none;">' +
                            '<label>' + termReassignmentL10n.reassignTo + '</label>' +
                            '<select id="term-reassignment-target"></select>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="term-reassignment-footer">' +
                    '<button class="button term-reassignment-cancel">' + termReassignmentL10n.cancel + '</button>' +
                    '<button class="button button-primary" id="term-reassignment-confirm" disabled>' + termReassignmentL10n.confirmDelete + '</button>' +
                '</div>' +
            '</div>' +
        '</div>';
    $('body').append(modalHtml);

    // --- Einzelne Delete-Links abfangen ---
    $(document).on('click', 'a.delete-tag', function(e) {
        e.preventDefault();
        e.stopImmediatePropagation();

        var deleteUrl = $(this).attr('href');
        var termId = getTermIdFromUrl(deleteUrl);

        if (!termId) {
            window.location.href = deleteUrl;
            return;
        }

        currentTermId = parseInt(termId);
        pendingDeleteUrl = deleteUrl;
        pendingBulkForm = null;
        openModal(currentTermId);
    });

    // --- Bulk-Delete für genau 1 Term abfangen ---
    $(document).on('click', '#doaction, #doaction2', function(e) {
        if (skipBulkIntercept) {
            skipBulkIntercept = false;
            return true;
        }

        var $btn = $(this);
        var actionName = ($btn.attr('id') === 'doaction') ? 'action' : 'action2';
        var selectedAction = $('select[name="' + actionName + '"]').val();

        if (selectedAction !== 'delete') {
            return true;
        }

        var checkedIds = $('input[name="delete_tags[]"]:checked').map(function() {
            return parseInt($(this).val());
        }).get();

        if (checkedIds.length !== 1) {
            return true; // 0 oder mehrere: normaler WP-Flow
        }

        e.preventDefault();

        currentTermId = checkedIds[0];
        pendingDeleteUrl = null;
        pendingBulkForm = $btn.closest('form');
        openModal(currentTermId);
    });

    // --- Hilfsfunktionen ---

    function getTermIdFromUrl(url) {
        var match = url.match(/tag_ID=(\d+)/);
        return match ? match[1] : null;
    }

    function openModal(termId) {
        $('#term-reassignment-loading').show();
        $('#term-reassignment-info').hide();
        $('#term-reassignment-select-wrapper').hide();
        $('#term-reassignment-confirm').prop('disabled', true).html(termReassignmentL10n.confirmDelete);
        $('#term-reassignment-modal').show();

        // Event-Anzahl abrufen
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'get_term_event_count',
                term_id: termId,
                _wpnonce: wpApiSettings.nonce
            },
            success: function(response) {
                if (!response.success) {
                    alert(termReassignmentL10n.error + ': ' + response.data);
                    closeModal();
                    return;
                }

                var count = response.data.count;
                var termName = response.data.term_name;

                $('#term-reassignment-loading').hide();
                $('#term-reassignment-info').show();

                if (count === 0) {
                    $('#term-event-count-text').html(
                        '<strong>"' + escapeHtml(termName) + '"</strong>: ' + termReassignmentL10n.noEvents
                    );
                    $('#term-reassignment-select-wrapper').hide();
                    $('#term-reassignment-confirm').prop('disabled', false);
                } else {
                    $('#term-event-count-text').html(
                        '<strong>"' + escapeHtml(termName) + '"</strong>: ' +
                        termReassignmentL10n.eventsAssigned.replace('%d', count)
                    );
                    loadSiblingTerms(termId);
                }
            },
            error: function() {
                alert(termReassignmentL10n.error);
                closeModal();
            }
        });
    }

    function loadSiblingTerms(termId) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'get_sibling_terms',
                term_id: termId,
                _wpnonce: wpApiSettings.nonce
            },
            success: function(response) {
                if (!response.success) {
                    alert(termReassignmentL10n.error + ': ' + response.data);
                    closeModal();
                    return;
                }

                var siblings = response.data.siblings;
                var others = response.data.others;

                var html = '<option value="0">' + termReassignmentL10n.noReassignment + '</option>';

                if (siblings.length > 0) {
                    html += '<optgroup label="' + termReassignmentL10n.sameLevelGroup + '">';
                    for (var i = 0; i < siblings.length; i++) {
                        html += '<option value="' + siblings[i].id + '">' +
                            escapeHtml(siblings[i].name) + ' (' + siblings[i].count + ')' +
                            '</option>';
                    }
                    html += '</optgroup>';
                }

                if (others.length > 0) {
                    html += '<optgroup label="' + termReassignmentL10n.otherGroup + '">';
                    for (var j = 0; j < others.length; j++) {
                        html += '<option value="' + others[j].id + '">' +
                            escapeHtml(others[j].name) + ' (' + others[j].count + ')' +
                            '</option>';
                    }
                    html += '</optgroup>';
                }

                $('#term-reassignment-target').html(html);
                $('#term-reassignment-select-wrapper').show();
                $('#term-reassignment-confirm').prop('disabled', false);
            },
            error: function() {
                alert(termReassignmentL10n.error);
                closeModal();
            }
        });
    }

    function closeModal() {
        $('#term-reassignment-modal').hide();
        currentTermId = null;
        pendingDeleteUrl = null;
        pendingBulkForm = null;
    }

    // Modal schließen
    $(document).on('click', '.term-reassignment-close, .term-reassignment-cancel', function() {
        closeModal();
    });

    // Backdrop-Klick schließt Modal
    $(document).on('click', '.term-reassignment-backdrop', function() {
        closeModal();
    });

    // Confirm-Button
    $(document).on('click', '#term-reassignment-confirm', function() {
        var $btn = $(this);
        var targetTermId = parseInt($('#term-reassignment-target').val()) || 0;

        // Keine Events oder "keine Umkategorisierung" → direkt löschen
        if (!$('#term-reassignment-select-wrapper').is(':visible') || targetTermId === 0) {
            proceedWithDelete();
            return;
        }

        // Events umkategorisieren
        $btn.prop('disabled', true).html(
            '<span class="spinner is-active" style="float:none;margin-right:5px;"></span> ' +
            termReassignmentL10n.reassigning
        );
        $('.term-reassignment-cancel').prop('disabled', true);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'reassign_term_events',
                term_id: currentTermId,
                target_term_id: targetTermId,
                _wpnonce: wpApiSettings.nonce
            },
            success: function(response) {
                if (response.success) {
                    proceedWithDelete();
                } else {
                    alert(termReassignmentL10n.error + ': ' + response.data);
                    $btn.prop('disabled', false).html(termReassignmentL10n.confirmDelete);
                    $('.term-reassignment-cancel').prop('disabled', false);
                }
            },
            error: function() {
                alert(termReassignmentL10n.error);
                $btn.prop('disabled', false).html(termReassignmentL10n.confirmDelete);
                $('.term-reassignment-cancel').prop('disabled', false);
            }
        });
    });

    function proceedWithDelete() {
        if (pendingDeleteUrl) {
            window.location.href = pendingDeleteUrl;
        } else if (pendingBulkForm && currentTermId) {
            // Für Bulk-Delete: den individuellen Delete-Link des Terms verwenden
            var $deleteLink = $('#tag-' + currentTermId + ' a.delete-tag');
            if ($deleteLink.length) {
                window.location.href = $deleteLink.attr('href');
            } else {
                // Fallback: Formular normal absenden
                skipBulkIntercept = true;
                pendingBulkForm.find('#doaction').trigger('click');
            }
        }
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }
});
