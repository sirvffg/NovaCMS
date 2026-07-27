(function () {
    'use strict';

    const root = document.documentElement;

    function currentTheme() {
        return root.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
    }

    function syncThemeButtons() {
        const dark = currentTheme() === 'dark';
        document.querySelectorAll('[data-account-theme-toggle]').forEach((button) => {
            const icon = button.querySelector('i');
            button.setAttribute('aria-label', dark ? '切换到浅色模式' : '切换到深色模式');
            button.setAttribute('title', dark ? '切换到浅色模式' : '切换到深色模式');
            if (icon) icon.className = dark ? 'bi bi-sun' : 'bi bi-moon-stars';
        });
    }

    function toggleTheme() {
        const next = currentTheme() === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-bs-theme', next);
        try {
            localStorage.setItem('theme', next);
        } catch (error) {
            // The theme still applies for the current page when storage is unavailable.
        }
        syncThemeButtons();
    }

    function setupPasswordToggles() {
        document.querySelectorAll('[data-password-toggle]').forEach((button) => {
            const target = document.getElementById(button.dataset.passwordToggle || '');
            if (!target) return;

            button.addEventListener('click', () => {
                const reveal = target.type === 'password';
                target.type = reveal ? 'text' : 'password';
                button.setAttribute('aria-pressed', reveal ? 'true' : 'false');
                button.setAttribute('aria-label', reveal ? '隐藏密码' : '显示密码');
                const icon = button.querySelector('i');
                if (icon) icon.className = reveal ? 'bi bi-eye-slash' : 'bi bi-eye';
                target.focus({ preventScroll: true });
            });
        });
    }

    function setupCapsLockHints() {
        document.querySelectorAll('[data-caps-lock-hint]').forEach((input) => {
            const hint = document.getElementById(input.dataset.capsLockHint || '');
            if (!hint) return;

            const update = (event) => {
                const active = Boolean(event.getModifierState && event.getModifierState('CapsLock'));
                hint.hidden = !active;
            };

            input.addEventListener('keydown', update);
            input.addEventListener('keyup', update);
            input.addEventListener('blur', () => { hint.hidden = true; });
        });
    }

    function passwordStrength(value) {
        if (!value) return 0;
        let score = 0;
        if (value.length >= 6) score += 1;
        if (value.length >= 10) score += 1;
        if (/[a-zA-Z]/.test(value) && /\d/.test(value)) score += 1;
        if (/[^a-zA-Z0-9]/.test(value)) score += 1;
        return Math.min(score, 4);
    }

    function setupStrengthMeters() {
        document.querySelectorAll('[data-password-strength]').forEach((input) => {
            const meter = document.getElementById(input.dataset.passwordStrength || '');
            if (!meter) return;
            const update = () => meter.setAttribute('data-level', String(passwordStrength(input.value)));
            input.addEventListener('input', update);
            update();
        });
    }

    function setupSectionNavigation() {
        const links = Array.from(document.querySelectorAll('.account-section-nav a[href^="#"]'));
        if (!links.length) return;

        const targets = links
            .map((link) => document.querySelector(link.getAttribute('href')))
            .filter(Boolean);

        links.forEach((link) => {
            link.addEventListener('click', () => {
                links.forEach((item) => item.classList.remove('active'));
                link.classList.add('active');
            });
        });

        if (!('IntersectionObserver' in window) || !targets.length) return;

        const observer = new IntersectionObserver((entries) => {
            const visible = entries
                .filter((entry) => entry.isIntersecting)
                .sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];
            if (!visible) return;
            links.forEach((link) => {
                link.classList.toggle('active', link.getAttribute('href') === '#' + visible.target.id);
            });
        }, { rootMargin: '-22% 0px -62% 0px', threshold: [0.01, 0.2, 0.5] });

        targets.forEach((target) => observer.observe(target));
    }

    window.showAccountToast = function (message, type) {
        document.querySelectorAll('.account-toast').forEach((toast) => toast.remove());

        const normalizedType = ['success', 'danger', 'info'].includes(type) ? type : 'info';
        const icons = {
            success: 'bi-check-circle-fill',
            danger: 'bi-exclamation-circle-fill',
            info: 'bi-info-circle-fill'
        };
        const toast = document.createElement('div');
        toast.className = 'account-toast ' + normalizedType;
        toast.setAttribute('role', normalizedType === 'danger' ? 'alert' : 'status');
        toast.setAttribute('aria-live', normalizedType === 'danger' ? 'assertive' : 'polite');

        const icon = document.createElement('i');
        icon.className = 'bi ' + icons[normalizedType];
        icon.setAttribute('aria-hidden', 'true');

        const text = document.createElement('span');
        text.textContent = String(message || '');

        toast.append(icon, text);
        document.body.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('show'));

        window.setTimeout(() => {
            toast.classList.remove('show');
            window.setTimeout(() => toast.remove(), 240);
        }, 3200);
    };

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-account-theme-toggle]').forEach((button) => {
            button.addEventListener('click', toggleTheme);
        });
        syncThemeButtons();
        setupPasswordToggles();
        setupCapsLockHints();
        setupStrengthMeters();
        setupSectionNavigation();
    });
})();
