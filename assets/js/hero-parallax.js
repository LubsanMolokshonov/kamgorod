/**
 * Hero Parallax Effect
 * Плавное движение изображений при движении мыши
 */
(function() {
    'use strict';

    const CONFIG = {
        maxMovement: 30,      // Максимальное смещение в пикселях
        smoothing: 0.15,      // Плавность (0-1)
        minWidth: 768,        // Минимальная ширина для активации
        minFPS: 30           // Минимальный FPS
    };

    let isEnabled = false;
    let rafId = null;
    let heroContainer = null;
    let parallaxElements = [];
    let mousePos = { x: 0.5, y: 0.5 };
    let targetPos = { x: 0.5, y: 0.5 };
    let currentPos = { x: 0.5, y: 0.5 };
    let containerBounds = null;

    function init() {
        // Проверка поддержки
        if (!window.requestAnimationFrame) return;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        heroContainer = document.getElementById('heroImages');
        if (!heroContainer) return;

        const elements = heroContainer.querySelectorAll('[data-parallax-speed]');
        parallaxElements = Array.from(elements).map(el => ({
            element: el,
            speed: parseFloat(el.getAttribute('data-parallax-speed')) || 0.5
        }));

        checkScreenSize();
        window.addEventListener('mousemove', handleMouseMove, { passive: true });
        window.addEventListener('resize', debounce(handleResize, 200));
    }

    function checkScreenSize() {
        const shouldEnable = window.innerWidth >= CONFIG.minWidth;
        if (shouldEnable && !isEnabled) enable();
        else if (!shouldEnable && isEnabled) disable();
    }

    function enable() {
        isEnabled = true;
        updateContainerBounds();
        heroContainer.classList.add('parallax-active');
        if (!rafId) rafId = requestAnimationFrame(animate);
    }

    function disable() {
        isEnabled = false;
        heroContainer.classList.remove('parallax-active');
        if (rafId) {
            cancelAnimationFrame(rafId);
            rafId = null;
        }
        resetPositions();
    }

    function updateContainerBounds() {
        if (heroContainer) containerBounds = heroContainer.getBoundingClientRect();
    }

    function handleMouseMove(e) {
        if (!isEnabled || !containerBounds) return;

        // Нормализация координат (0-1)
        mousePos.x = (e.clientX - containerBounds.left) / containerBounds.width;
        mousePos.y = (e.clientY - containerBounds.top) / containerBounds.height;

        mousePos.x = Math.max(0, Math.min(1, mousePos.x));
        mousePos.y = Math.max(0, Math.min(1, mousePos.y));

        // Центрирование (-0.5 до 0.5)
        targetPos.x = mousePos.x - 0.5;
        targetPos.y = mousePos.y - 0.5;
    }

    function animate() {
        if (!isEnabled) return;

        // Плавная интерполяция (easing)
        currentPos.x += (targetPos.x - currentPos.x) * CONFIG.smoothing;
        currentPos.y += (targetPos.y - currentPos.y) * CONFIG.smoothing;

        // Применение трансформаций
        parallaxElements.forEach(({ element, speed }) => {
            const moveX = currentPos.x * CONFIG.maxMovement * speed;
            const moveY = currentPos.y * CONFIG.maxMovement * speed;
            element.style.transform = `translate3d(${moveX}px, ${moveY}px, 0)`;
        });

        rafId = requestAnimationFrame(animate);
    }

    function resetPositions() {
        parallaxElements.forEach(({ element }) => {
            element.style.transform = 'translate3d(0, 0, 0)';
        });
        currentPos = { x: 0.5, y: 0.5 };
        targetPos = { x: 0.5, y: 0.5 };
        mousePos = { x: 0.5, y: 0.5 };
    }

    function handleResize() {
        checkScreenSize();
        if (isEnabled) updateContainerBounds();
    }

    function debounce(func, wait) {
        let timeout;
        return function(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func(...args), wait);
        };
    }

    function cleanup() {
        disable();
        window.removeEventListener('mousemove', handleMouseMove);
        window.removeEventListener('resize', handleResize);
    }

    // Инициализация
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.addEventListener('beforeunload', cleanup);

    // Debug API
    window.HeroParallax = {
        enable, disable,
        isEnabled: () => isEnabled,
        getConfig: () => CONFIG
    };
})();
