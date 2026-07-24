(() => {
  'use strict';

  const STAFF_AUTOCOMPLETE = 'section-jawish-staff';
  const CUSTOMER_AUTOCOMPLETE = 'section-jawish-customer';
  const PHONE_PATTERN = /^09\d{8}$/;
  const AUTOFILL_SETTLE_MS = 450;

  const fieldClass = 'w-full border border-gray-300 rounded-lg px-3 py-2 mt-1 text-gray-900 placeholder:text-gray-400 focus:border-primary focus:ring-primary';
  const labelClass = 'block text-sm font-medium text-gray-700';

  const afterAutofillSettles = (callback) => {
    window.requestAnimationFrame(() => {
      window.setTimeout(callback, AUTOFILL_SETTLE_MS);
    });
  };

  const bindReadonlyUntilInteraction = (input) => {
    if (!(input instanceof HTMLInputElement) || input.type === 'password') {
      return;
    }
    input.setAttribute('readonly', 'readonly');
    const unlock = () => {
      input.removeAttribute('readonly');
    };
    input.addEventListener('focus', unlock, { once: true });
    input.addEventListener('input', unlock, { once: true });
    input.addEventListener('change', unlock, { once: true });
  };

  const looksLikePhone = (value) => {
    const digits = String(value || '').replace(/\D+/g, '');
    return PHONE_PATTERN.test(digits);
  };

  const looksLikeStaffUsername = (value) => {
    const text = String(value || '').trim();
    if (!text || looksLikePhone(text)) {
      return false;
    }

    return /^[a-z0-9._-]+$/i.test(text);
  };

  const mountCustomerLogin = () => {
    const form = document.getElementById('login-form-customer');
    const mount = form?.querySelector('[data-customer-login-mount]');
    if (!(form instanceof HTMLFormElement) || !(mount instanceof HTMLElement) || mount.dataset.ready === '1') {
      return;
    }
    mount.dataset.ready = '1';

    document.querySelector('[data-customer-login-placeholder]')?.remove();

    mount.className = 'space-y-4';
    mount.innerHTML = `
      <label class="${labelClass}">
        رقم الهاتف
        <input
          id="customer_login_phone"
          name="customer_phone"
          type="tel"
          inputmode="tel"
          autocomplete="${CUSTOMER_AUTOCOMPLETE} username"
          dir="ltr"
          data-phone-input
          data-login-phone
          class="${fieldClass} text-left"
          required
          placeholder="09xxxxxxxx"
        >
      </label>
      <label class="${labelClass}">
        كلمة المرور
        <input
          id="customer_login_password"
          name="customer_password"
          type="password"
          autocomplete="${CUSTOMER_AUTOCOMPLETE} current-password"
          class="${fieldClass}"
          required
          placeholder="••••••••"
        >
      </label>
      <button type="submit" class="w-full bg-primary text-white rounded-lg py-2.5 font-semibold hover:brightness-110 transition">
        دخول
      </button>
    `;

    mount.querySelectorAll('input').forEach((input) => {
      bindReadonlyUntilInteraction(input);
    });

    if (typeof window.portalPhoneInputInit === 'function') {
      window.portalPhoneInputInit(mount);
    }

    afterAutofillSettles(() => {
      const phone = mount.querySelector('#customer_login_phone');
      const password = mount.querySelector('#customer_login_password');
      if (!(phone instanceof HTMLInputElement) || !(password instanceof HTMLInputElement)) {
        return;
      }
      if (phone.value && !looksLikePhone(phone.value)) {
        phone.value = '';
        password.value = '';
      }
    });
  };

  const guardStaffLogin = () => {
    const form = document.getElementById('login-form-staff');
    if (!(form instanceof HTMLFormElement) || form.dataset.ready === '1') {
      return;
    }
    form.dataset.ready = '1';

    const username = form.querySelector('#staff_login_username, [name="staff_user_name"]');
    const password = form.querySelector('#staff_login_password, [name="staff_password"]');
    if (username instanceof HTMLInputElement) {
      bindReadonlyUntilInteraction(username);
    }

    afterAutofillSettles(() => {
      if (!(username instanceof HTMLInputElement)) {
        return;
      }
      if (username.value && !looksLikeStaffUsername(username.value)) {
        username.value = '';
        if (password instanceof HTMLInputElement) {
          password.value = '';
        }
      }
    });
  };

  const boot = () => {
    if (document.getElementById('login-form-customer')) {
      mountCustomerLogin();
    }
    if (document.getElementById('login-form-staff')) {
      guardStaffLogin();
    }
  };

  boot();
  document.addEventListener('DOMContentLoaded', boot);
})();
