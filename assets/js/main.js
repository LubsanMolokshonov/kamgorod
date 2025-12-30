/**
 * Main JavaScript
 * Global functionality for the site
 */

$(document).ready(function() {
    // Mobile menu toggle
    $('#hamburger').on('click', function() {
        $('#mainNav').toggleClass('active');
        $(this).toggleClass('active');
    });

    // Close mobile menu when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.header-container').length) {
            $('#mainNav').removeClass('active');
            $('#hamburger').removeClass('active');
        }
    });

    // Smooth scroll for anchor links
    $('a[href^="#"]').on('click', function(e) {
        e.preventDefault();
        var target = $(this.hash);
        if (target.length) {
            $('html, body').animate({
                scrollTop: target.offset().top - 80
            }, 500);
        }
    });

    // Category filter
    $('.filter-btn').on('click', function() {
        var category = $(this).data('category');

        // Update active state
        $('.filter-btn').removeClass('active');
        $(this).addClass('active');

        // Filter competitions
        if (category === 'all') {
            $('.competition-card').fadeIn(300);
        } else {
            $('.competition-card').hide();
            $('.competition-card[data-category="' + category + '"]').fadeIn(300);
        }
    });

    // Add animation on scroll
    function animateOnScroll() {
        $('.competition-card').each(function() {
            var elementTop = $(this).offset().top;
            var viewportBottom = $(window).scrollTop() + $(window).height();

            if (elementTop < viewportBottom - 50) {
                $(this).addClass('animated');
            }
        });
    }

    $(window).on('scroll', animateOnScroll);
    animateOnScroll(); // Initial check

    // FAQ toggle functionality
    $('.faq-item').on('click', function() {
        $(this).toggleClass('active');
    });
});

/**
 * Open regulations modal for a competition
 * @param {string} competitionId - ID of the competition
 * @param {string} competitionTitle - Title of the competition
 */
function openRegulationsModal(competitionId, competitionTitle) {
    const modal = document.getElementById('regulationsModal');
    const modalTitle = document.getElementById('regulationsModalTitle');
    const modalBody = document.getElementById('regulationsModalBody');

    // Update modal title
    modalTitle.textContent = 'Положение о конкурсе: ' + competitionTitle;

    // Show loading state
    modalBody.innerHTML = '<p style="text-align: center; color: var(--text-medium);">Загрузка...</p>';

    // Show modal
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';

    // Load regulations content
    fetch('/ajax/get-regulations.php?competition_id=' + competitionId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                modalBody.innerHTML = data.content;
            } else {
                modalBody.innerHTML = '<p style="color: #EE3F58;">Ошибка загрузки положения конкурса. Попробуйте позже.</p>';
            }
        })
        .catch(error => {
            console.error('Error loading regulations:', error);
            modalBody.innerHTML = '<p style="color: #EE3F58;">Ошибка загрузки положения конкурса. Попробуйте позже.</p>';
        });
}

/**
 * Close regulations modal
 */
function closeRegulationsModal() {
    const modal = document.getElementById('regulationsModal');
    modal.style.display = 'none';
    document.body.style.overflow = '';
}

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeRegulationsModal();
    }
});
