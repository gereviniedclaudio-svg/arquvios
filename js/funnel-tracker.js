/**
 * Funnel tracker — beacon de etapas para /analytics/track.php
 * Uso: <script src="/js/funnel-tracker.js" data-funnel-step="inscricao"></script>
 * Ou: FunnelTracker.advance('inscricao')
 */
(function (window, document) {
  'use strict';

  var STORAGE_VID = 'funnel_visitor_id';
  var STORAGE_UTM = 'utm_params';
  var ENDPOINT = '/analytics/track.php';
  var UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'src', 'sck'];
  // currentScript só existe durante a execução síncrona do script
  var STEP_FROM_SCRIPT = document.currentScript
    ? document.currentScript.getAttribute('data-funnel-step')
    : null;

  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'v-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
  }

  function getVisitorId() {
    try {
      var id = localStorage.getItem(STORAGE_VID);
      if (id && id.length >= 8) return id;
      id = uuid();
      localStorage.setItem(STORAGE_VID, id);
      return id;
    } catch (e) {
      return uuid();
    }
  }

  function captureUtms() {
    var out = {};
    try {
      var params = new URLSearchParams(window.location.search || '');
      UTM_KEYS.forEach(function (k) {
        var v = params.get(k);
        if (v) {
          out[k] = v;
          try { localStorage.setItem(k, v); } catch (e) {}
        }
      });
      var stored = {};
      try { stored = JSON.parse(localStorage.getItem(STORAGE_UTM) || '{}'); } catch (e) {}
      UTM_KEYS.forEach(function (k) {
        if (!out[k]) {
          var ls = localStorage.getItem(k) || stored[k];
          if (ls) out[k] = ls;
        }
      });
      var merged = Object.assign({}, stored, out);
      localStorage.setItem(STORAGE_UTM, JSON.stringify(merged));
    } catch (e) {}
    return out;
  }

  function currentStep() {
    if (STEP_FROM_SCRIPT) return STEP_FROM_SCRIPT;
    var el = document.querySelector('script[data-funnel-step]');
    if (el) return el.getAttribute('data-funnel-step');
    var bodyEl = document.querySelector('[data-funnel-step]');
    if (bodyEl) return bodyEl.getAttribute('data-funnel-step');
    return null;
  }

  function send(step, eventName) {
    if (!step || !eventName) return;
    var utms = captureUtms();
    var payload = {
      visitor_id: getVisitorId(),
      step: step,
      event: eventName,
      path: (window.location.pathname || '') + (window.location.search || ''),
      referrer: document.referrer || '',
      utm_source: utms.utm_source || utms.src || '',
      utm_medium: utms.utm_medium || '',
      utm_campaign: utms.utm_campaign || '',
      utm_content: utms.utm_content || '',
      utm_term: utms.utm_term || ''
    };

    var body = JSON.stringify(payload);
    try {
      if (navigator.sendBeacon) {
        var blob = new Blob([body], { type: 'application/json' });
        if (navigator.sendBeacon(ENDPOINT, blob)) return;
      }
    } catch (e) {}

    try {
      fetch(ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: body,
        keepalive: true,
        credentials: 'omit'
      }).catch(function () {});
    } catch (e) {}
  }

  var FunnelTracker = {
    view: function (step) {
      send(step || currentStep(), 'view');
    },
    advance: function (step) {
      send(step || currentStep(), 'advance');
    },
    getVisitorId: getVisitorId
  };

  window.FunnelTracker = FunnelTracker;

  function boot() {
    var step = currentStep();
    if (step) FunnelTracker.view(step);

    document.addEventListener('click', function (e) {
      var t = e.target && e.target.closest ? e.target.closest('[data-funnel-advance]') : null;
      if (!t) return;
      var advanceStep = t.getAttribute('data-funnel-advance') || step;
      FunnelTracker.advance(advanceStep);
    }, true);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(window, document);
