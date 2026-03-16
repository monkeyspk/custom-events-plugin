
(function($) {
    'use strict';

    function showSkeletons(container, count = 3) {
        const skeleton = `
            <div class="event-skeleton">
                <div class="event-skeleton-date skeleton"></div>
                <div class="event-skeleton-title skeleton"></div>
                <div class="event-skeleton-meta">
                    <div class="event-skeleton-meta-item skeleton"></div>
                    <div class="event-skeleton-meta-item skeleton"></div>
                    <div class="event-skeleton-meta-item skeleton"></div>
                </div>
            </div>
        `;

        let skeletons = '';
        for (let i = 0; i < count; i++) {
            skeletons += skeleton;
        }
        
        container.html(skeletons);
    }

    function hideSkeletons(container) {
        container.find('.event-skeleton').remove();
    }

    // Make globally available
    window.eventSkeletons = {
        show: showSkeletons,
        hide: hideSkeletons
    };

})(jQuery);
