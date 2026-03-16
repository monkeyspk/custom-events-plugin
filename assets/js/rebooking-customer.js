(function($) {
    const settings = window.customerRebookingData || {};
    const root = $('#customer-rebooking-root');

    if (!root.length || !settings.orderId || !settings.token) {
        return;
    }

    const loadingEl = root.find('.customer-rebooking-loading');
    const contentEl = root.find('.customer-rebooking-content');
    const messageEl = root.find('.customer-rebooking-message');
    const maxSlotsDefault = settings.maxSlots || 8;
    const loadMoreStep = settings.loadMoreStep || 8;
    let slotOptions = [];
    let visibleSlots = maxSlotsDefault;
    let currentOrderData = null;
    let recommendedSlotId = null;

    function setLoading(isLoading, text) {
        if (isLoading) {
            loadingEl.text(text || settings.i18n.loading).show();
            contentEl.hide();
        } else {
            loadingEl.hide();
        }
    }

    function showMessage(type, text, extraHtml) {
        messageEl
            .removeClass('error success')
            .addClass(type)
            .html(text + (extraHtml || ''))
            .show();
    }

    function buildOptions(options) {
        let html = '';
        if (!options || !options.length) {
            html += `<div class="slot-row disabled">${settings.i18n.noSlots || ''}</div>`;
            return html;
        }

        options.forEach(function(option) {
            const disabled = option.is_current || !option.is_available;
            const badges = [];
            if (!option.is_available) {
                badges.push(`<span class="badge badge-full">${settings.i18n.fullLabel}</span>`);
            }
            if (!disabled && option.id === recommendedSlotId) {
                badges.push(`<span class="badge badge-recommended">${settings.i18n.recommendedLabel}</span>`);
            }

            html += `
                <label class="slot-row ${disabled ? 'disabled' : ''} ${option.id === recommendedSlotId ? 'highlight' : ''}">
                    <input type="radio" name="new_product_id" value="${option.id}" ${disabled ? 'disabled' : ''}>
                    <span class="slot-label">${option.label}</span>
                    <span class="slot-badges">${badges.join('')}</span>
                </label>`;
        });

        return html;
    }

    function render(order) {
        if (!order) {
            return;
        }

        const visibleOptions = slotOptions.slice(0, visibleSlots);
        const hasMore = slotOptions.length > visibleSlots;

        const participantsRow = order.participants
            ? `<p class="participants-row"><strong>${order.participants_count}</strong> · ${order.participants}</p>`
            : '';
        const markup = `
            <div class="customer-rebooking-card">
                <div class="hero">
                    <p class="hero-eyebrow">${settings.i18n.heroEyebrow}</p>
                    <h1>${settings.i18n.heroTitle}</h1>
                    <p class="hero-subline">${settings.i18n.heroSubline}</p>
                    <p class="hero-note">${settings.i18n.heroNote}</p>
                </div>
                <div class="current-booking primary-card">
                    <h2>${settings.i18n.currentBooking}</h2>
                    <p class="booking-title">${order.event_title}</p>
                    <p class="booking-meta">${order.event_date} · ${order.event_time || ''}</p>
                    <p class="booking-location">${order.event_venue || ''}</p>
                    <div class="booking-participants">${participantsRow}</div>
                </div>
                <form class="customer-rebooking-form">
                    <label>${settings.i18n.selectLabel}</label>
                    <div class="slot-list">
                        ${buildOptions(visibleOptions)}
                    </div>
                    <p class="customer-rebooking-help">${settings.i18n.selectSubtitle}</p>
                    ${hasMore ? `<button type="button" class="slot-more">${settings.i18n.loadMore}</button>` : ''}
                    <button type="submit" class="customer-rebooking-submit">${settings.i18n.submitLabel}</button>
                </form>
            </div>`;

        contentEl.html(markup).show();
        attachFormHandler();
    }

    function attachFormHandler() {
        const form = contentEl.find('form.customer-rebooking-form');
        form.off('click', '.slot-more');
        form.on('click', '.slot-more', function(e) {
            e.preventDefault();
            visibleSlots = Math.min(slotOptions.length, visibleSlots + loadMoreStep);
            render(currentOrderData);
        });

        form.off('submit');
        form.on('submit', function(event) {
            event.preventDefault();
            const value = form.find('input[name="new_product_id"]:checked').val();
            if (!value) {
                showMessage('error', settings.i18n.selectError);
                return;
            }
            processRebooking(value);
        });
    }

    function processRebooking(productId) {
        setLoading(true, settings.i18n.submitting);
        $.post(settings.ajaxUrl, {
            action: 'event_customer_rebook_order',
            order_id: settings.orderId,
            token: settings.token,
            new_product_id: productId
        })
            .done(function(response) {
                setLoading(false);
                if (!response || !response.success) {
                    const message = response && response.data ? response.data : settings.i18n.genericError;
                    showMessage('error', message);
                    return;
                }

                contentEl.hide();
                let extra = '';
                if (response.data.rebooking_url) {
                    extra = `<p><a class="customer-rebooking-link" href="${response.data.rebooking_url}">${settings.i18n.newLinkLabel}</a></p>`;
                }
                showMessage('success', response.data.message || settings.i18n.successMessage, extra);
            })
            .fail(function() {
                setLoading(false);
                showMessage('error', settings.i18n.genericError);
            });
    }

    function load() {
        setLoading(true, settings.i18n.loading);
        $.post(settings.ajaxUrl, {
            action: 'event_customer_rebooking_init',
            order_id: settings.orderId,
            token: settings.token
        })
            .done(function(response) {
                setLoading(false);
                if (!response || !response.success) {
                    const message = response && response.data ? response.data : settings.i18n.genericError;
                    showMessage('error', message);
                    return;
                }
                slotOptions = (response.data.options || []).filter(function(option) {
                    return !option.is_current;
                });
                const firstAvailable = slotOptions.find(function(option) {
                    return option.is_available;
                });
                recommendedSlotId = firstAvailable ? firstAvailable.id : null;
                currentOrderData = response.data.order;
                visibleSlots = Math.min(maxSlotsDefault, slotOptions.length || maxSlotsDefault);
                render(currentOrderData);
            })
            .fail(function() {
                setLoading(false);
                showMessage('error', settings.i18n.genericError);
            });
    }

    load();
})(jQuery);
