(function () {
    'use strict';

    var form = document.querySelector('[data-admin-login-form]');
    if (!form) return;

    var username = document.getElementById('username');
    var password = document.getElementById('password');
    var captchaToken = document.getElementById('captchaToken');
    var submitButton = document.querySelector('[data-login-submit]');
    var submitText = document.querySelector('[data-login-button-text]');
    var notice = document.querySelector('[data-login-notice]');
    var noticeText = document.querySelector('[data-login-notice-text]');
    var passwordToggle = document.querySelector('[data-password-toggle]');
    var capsLockHint = document.querySelector('[data-caps-lock]');
    var captchaModalElement = document.getElementById('captchaModal');
    var captcha = null;
    var submitting = false;

    function showNotice(message, isError) {
        if (!notice || !noticeText) return;
        notice.hidden = false;
        notice.classList.toggle('is-info', !isError);
        noticeText.textContent = message;
    }

    function hideNotice() {
        if (notice) notice.hidden = true;
    }

    function setSubmitting(value) {
        submitting = Boolean(value);
        if (!submitButton) return;
        submitButton.disabled = submitting;
        submitButton.setAttribute('aria-busy', submitting ? 'true' : 'false');
        if (submitText) submitText.textContent = submitting ? '正在进入控制台…' : '验证并登录';
    }

    function validateForm() {
        hideNotice();
        if (!username.value.trim()) {
            username.focus();
            showNotice('请输入用户名或邮箱', true);
            return false;
        }
        if (!password.value) {
            password.focus();
            showNotice('请输入账户密码', true);
            return false;
        }
        return true;
    }

    function submitAfterCaptcha(token) {
        captchaToken.value = token;
        setSubmitting(true);
        var modal = window.bootstrap ? window.bootstrap.Modal.getInstance(captchaModalElement) : null;
        if (modal) modal.hide();
        window.setTimeout(function () { form.submit(); }, 180);
    }

    function openCaptcha() {
        if (submitting || !validateForm()) return;

        if (!window.bootstrap || typeof BehaviorAuth === 'undefined') {
            showNotice('安全验证组件暂时不可用，请刷新页面后重试', true);
            return;
        }

        var modal = window.bootstrap.Modal.getOrCreateInstance(captchaModalElement);
        modal.show();

        try {
            if (!captcha) {
                captcha = new BehaviorAuth('captcha-container', '/vendor/public/captcha/AuthApi.php');
                captcha.onSuccess = function (bizToken) {
                    if (!bizToken) {
                        showNotice('安全验证未完成，请重试', true);
                        return;
                    }
                    submitAfterCaptcha(bizToken);
                };
                captcha.onFail = function () {
                    captchaToken.value = '';
                    showNotice('安全验证未通过，请再试一次', true);
                };
            } else if (typeof captcha.reset === 'function') {
                captcha.reset();
            }
        } catch (error) {
            modal.hide();
            showNotice('安全验证初始化失败，请刷新页面后重试', true);
        }
    }

    if (submitButton) submitButton.addEventListener('click', openCaptcha);

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        openCaptcha();
    });

    if (passwordToggle) {
        passwordToggle.addEventListener('click', function () {
            var isVisible = password.type === 'text';
            password.type = isVisible ? 'password' : 'text';
            passwordToggle.setAttribute('aria-pressed', isVisible ? 'false' : 'true');
            passwordToggle.setAttribute('aria-label', isVisible ? '显示密码' : '隐藏密码');
            var icon = passwordToggle.querySelector('i');
            if (icon) icon.className = isVisible ? 'bi bi-eye' : 'bi bi-eye-slash';
            password.focus({ preventScroll: true });
        });
    }

    if (password && capsLockHint) {
        ['keydown', 'keyup'].forEach(function (eventName) {
            password.addEventListener(eventName, function (event) {
                capsLockHint.hidden = !event.getModifierState || !event.getModifierState('CapsLock');
            });
        });
        password.addEventListener('blur', function () { capsLockHint.hidden = true; });
    }

    if (captchaModalElement) {
        captchaModalElement.addEventListener('hidden.bs.modal', function () {
            if (!submitting) captchaToken.value = '';
        });
    }
}());
