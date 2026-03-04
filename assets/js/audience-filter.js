/**
 * Unified Audience Filter
 * Scrolls active tab into view on mobile
 */
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.af-categories, .af-types, .af-specs').forEach(function(container) {
        var active = container.querySelector('.active');
        if (active) {
            var scrollLeft = active.offsetLeft - container.offsetLeft - 16;
            container.scrollTo({ left: scrollLeft, behavior: 'smooth' });
        }
    });
});
