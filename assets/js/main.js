(function () {
  'use strict';

  var PIXEL_ID = '1014610764961858';
  var CONSENT_KEY = 'fonil_consent';
  var ATTR_KEY = 'fonil_attr';
  var ATTR_FIELDS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid'];
  var openedAt = Date.now();

  window.dataLayer = window.dataLayer || [];

  /* ---------- Consent + Meta Pixel (LGPD: nothing loads before opt-in) ---------- */
  function loadPixel() {
    if (window.fbq) return;
    !function (f, b, e, v, n, t, s) {
      if (f.fbq) return; n = f.fbq = function () { n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments); };
      if (!f._fbq) f._fbq = n; n.push = n; n.loaded = !0; n.version = '2.0'; n.queue = [];
      t = b.createElement(e); t.async = !0; t.src = v; s = b.getElementsByTagName(e)[0]; s.parentNode.insertBefore(t, s);
    }(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');
    window.fbq('init', PIXEL_ID);
    window.fbq('track', 'PageView');
  }

  function getConsent() {
    try { return localStorage.getItem(CONSENT_KEY); } catch (e) { return null; }
  }

  function setConsent(value) {
    try { localStorage.setItem(CONSENT_KEY, value); } catch (e) { /* storage blocked */ }
  }

  function initConsent() {
    var banner = document.getElementById('cookie-banner');
    var consent = getConsent();
    if (consent === 'granted') loadPixel();
    if (consent || !banner) return;
    banner.hidden = false;
    document.getElementById('cookie-accept').addEventListener('click', function () {
      setConsent('granted'); banner.hidden = true; loadPixel();
    });
    document.getElementById('cookie-reject').addEventListener('click', function () {
      setConsent('denied'); banner.hidden = true;
    });
  }

  /* ---------- Attribution: keep first-touch params for the session ---------- */
  function captureAttribution() {
    var q = new URLSearchParams(location.search);
    var stored = {};
    try { stored = JSON.parse(sessionStorage.getItem(ATTR_KEY) || '{}'); } catch (e) { stored = {}; }
    var hasNew = ATTR_FIELDS.some(function (k) { return q.get(k); });
    if (hasNew) {
      stored = {};
      ATTR_FIELDS.forEach(function (k) { stored[k] = (q.get(k) || '').slice(0, 255); });
      stored.pagina = location.href.slice(0, 500);
      try { sessionStorage.setItem(ATTR_KEY, JSON.stringify(stored)); } catch (e) { /* ignore */ }
    }
    if (!stored.pagina) stored.pagina = location.href.slice(0, 500);
    return stored;
  }

  /* ---------- Validation ---------- */
  var RULES = {
    nome: function (v) { return /^\S{2,}(\s+\S+)+$/.test(v.trim()) ? '' : 'Informe seu nome e sobrenome.'; },
    whatsapp: function (v) {
      var d = v.replace(/\D/g, '');
      return (d.length === 10 || d.length === 11) && d[0] !== '0' ? '' : 'Informe o WhatsApp com DDD (10 ou 11 dígitos).';
    },
    email: function (v) { return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v.trim()) ? '' : 'Informe um e-mail válido.'; },
    empresa: function (v) { return v.trim().length >= 2 ? '' : 'Informe o nome da empresa.'; },
    faturamento: function (v) { return v ? '' : 'Selecione uma faixa de faturamento.'; },
    cargo: function (v) { return v ? '' : 'Selecione seu cargo na empresa.'; }
  };

  function setFieldError(form, name, msg) {
    var el = form.elements[name];
    var err = document.getElementById('e-' + name);
    el.setAttribute('aria-invalid', msg ? 'true' : 'false');
    if (err) err.textContent = msg;
  }

  function validateForm(form) {
    var firstInvalid = null;
    Object.keys(RULES).forEach(function (name) {
      var msg = RULES[name](form.elements[name].value || '');
      setFieldError(form, name, msg);
      if (msg && !firstInvalid) firstInvalid = form.elements[name];
    });
    return firstInvalid;
  }

  function maskPhone(v) {
    var d = v.replace(/\D/g, '').slice(0, 11);
    if (!d) return '';
    if (d.length <= 2) return '(' + d;
    var ddd = d.slice(0, 2), r = d.slice(2);
    if (d.length <= 10) return '(' + ddd + ') ' + r.slice(0, 4) + (r.length > 4 ? '-' + r.slice(4) : '');
    return '(' + ddd + ') ' + r.slice(0, 5) + '-' + r.slice(5);
  }

  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16);
    });
  }

  /* ---------- Form ---------- */
  function initForm(attribution) {
    var form = document.getElementById('lead-form');
    if (!form) return;
    var btn = document.getElementById('btn-submit');
    var formError = document.getElementById('form-error');
    var done = document.getElementById('lead-done');
    var eventId = uuid();
    var sending = false;

    var phone = form.elements.whatsapp;
    phone.addEventListener('input', function () { phone.value = maskPhone(phone.value); });

    Object.keys(RULES).forEach(function (name) {
      var el = form.elements[name];
      var evt = el.tagName === 'SELECT' ? 'change' : 'blur';
      el.addEventListener(evt, function () {
        if (form.dataset.tried) setFieldError(form, name, RULES[name](el.value || ''));
      });
    });

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (sending) return;
      form.dataset.tried = '1';
      formError.textContent = '';

      var invalid = validateForm(form);
      if (invalid) { invalid.focus(); return; }

      var payload = {
        nome: form.elements.nome.value.trim(),
        whatsapp: form.elements.whatsapp.value.replace(/\D/g, ''),
        email: form.elements.email.value.trim().toLowerCase(),
        empresa: form.elements.empresa.value.trim(),
        faturamento: form.elements.faturamento.value,
        cargo: form.elements.cargo.value,
        website: form.elements.website.value,
        elapsed_ms: Date.now() - openedAt,
        event_id: eventId
      };
      ATTR_FIELDS.concat('pagina').forEach(function (k) { payload[k] = attribution[k] || ''; });

      sending = true;
      btn.disabled = true;
      btn.setAttribute('aria-busy', 'true');

      fetch('/api/lead.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
        credentials: 'same-origin'
      })
        .then(function (res) { return res.json().catch(function () { return {}; }).then(function (data) { return { res: res, data: data }; }); })
        .then(function (r) {
          if (!r.res.ok || !r.data || r.data.ok !== true) {
            if (r.data && r.data.errors) {
              Object.keys(r.data.errors).forEach(function (k) { if (form.elements[k]) setFieldError(form, k, r.data.errors[k]); });
            }
            throw new Error((r.data && r.data.message) || 'Não foi possível enviar agora. Tente novamente em instantes.');
          }
          onSuccess(payload, r.data.crm_sent === true);
        })
        .catch(function (err) {
          formError.textContent = err && err.message && err.message !== 'Failed to fetch'
            ? err.message : 'Falha de conexão. Verifique sua internet e tente novamente.';
          sending = false;
          btn.disabled = false;
          btn.removeAttribute('aria-busy');
        });
    });

    function onSuccess(p, crmSent) {
      form.hidden = true;
      form.style.display = 'none';
      done.hidden = false;
      done.focus();
      // Anti-spam responses may acknowledge the request without sending a lead.
      if (!crmSent) return;
      window.dataLayer.push({ event: 'generate_lead', faturamento: p.faturamento, cargo: p.cargo, event_id: p.event_id });
      if (typeof window.fbq === 'function') window.fbq('track', 'Lead', { content_name: 'diagnostico_comercial' }, { eventID: p.event_id });
    }
  }

  /* ---------- CTA clicks (analytics only, never a conversion) ---------- */
  function initCtaTracking() {
    document.querySelectorAll('[data-cta]').forEach(function (a) {
      a.addEventListener('click', function () {
        window.dataLayer.push({ event: 'cta_click', cta_location: a.getAttribute('data-cta') });
      });
    });
  }

  initConsent();
  initForm(captureAttribution());
  initCtaTracking();
})();
