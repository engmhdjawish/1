/**
 * Zero-touch PWA updater (page side). Pairs with public/sw.js and Portal\Support\PwaBuild.
 *
 * Every page carries <meta name="portal-build">. This script keeps the service worker registration on
 * /sw.js?b=<server build> and, once a newer worker has taken over, reloads the page at a safe moment so
 * no installed app keeps running an old build. Nothing asks the user to tap anything.
 *
 * Kept in plain ES2015 (no ?. / ??) so a parse error can never silently disable it on older Chrome builds.
 * The pure logic is exported through a guarded module.exports so it can be unit-tested in Node.
 */
(function (global, factory) {
  'use strict';

  var api = factory();
  if (typeof module === 'object' && module && module.exports) {
    module.exports = api;
  }
  if (global && global.document && global.navigator) {
    api.boot(global);
  }
})(typeof window !== 'undefined' ? window : null, function () {
  'use strict';

  var SW_SCOPE = '/';
  var VERSION_ENDPOINT = '/api/app-version.php';
  var BUILD_PATTERN = /^[A-Za-z0-9._-]{1,32}$/;

  var CHECK_JITTER_MAX_MS = 45000;
  var CHECK_INTERVAL_MS = 10 * 60 * 1000;
  var MIN_CHECK_GAP_MS = 20000;
  var FETCH_TIMEOUT_MS = 4000;
  var BACKOFF_BASE_MS = 30000;
  var BACKOFF_MAX_MS = 15 * 60 * 1000;
  var GET_BUILD_TIMEOUT_MS = 3000;
  var ESCAPE_AFTER_MS = 30000;
  var IDLE_MS = 5000;
  var SUBMIT_GRACE_MS = 20000;
  var RELOAD_RETRY_MS = 3000;
  var TOAST_REVEAL_MS = 3500;

  var RELOADED_KEY = 'portal-pwa-reloaded';
  var ESCAPED_KEY = 'portal-pwa-escaped';
  var JUST_UPDATED_KEY = 'portal-pwa-just-updated';
  var LAST_CHECK_KEY = 'portal-pwa-last-check';

  var QUIET_PATH_PREFIXES = [
    '/dashboard/',
    '/login.php',
    '/staff-login.php',
    '/customer-login.php',
    '/register.php',
    '/logout.php',
    '/store-cart.php',
    '/cart.php',
    '/checkout',
    '/payment',
    '/order-confirmation.php',
    '/store-order-confirmation.php'
  ];

  var TEXT_LIKE_TYPES = ['', 'text', 'search', 'tel', 'email', 'url', 'password'];
  var NON_TYPING_TYPES = ['button', 'submit', 'reset', 'image', 'checkbox', 'radio', 'range', 'color', 'hidden', 'file'];
  var MODAL_SELECTOR = 'body.store-cart-drawer-open, .store-cart-checkout:not([hidden]), dialog[open], [aria-modal="true"]:not([hidden]), body.pwa-modal-open';

  function noop() {}

  function isRealBuild(value) {
    return typeof value === 'string' && value !== 'legacy' && BUILD_PATTERN.test(value);
  }

  function buildSwUrl(build) {
    return '/sw.js?b=' + encodeURIComponent(build);
  }

  /** Build id carried by a registered worker URL; workers registered without ?b= are "legacy". */
  function parseBuildFromUrl(scriptUrl) {
    try {
      var parsed = new URL(String(scriptUrl), 'https://example.invalid/');
      var raw = parsed.searchParams.get('b');
      return raw && BUILD_PATTERN.test(raw) ? raw : 'legacy';
    } catch (error) {
      return 'legacy';
    }
  }

  function backoffDelay(failures, random) {
    var exponent = Math.max(0, (failures | 0) - 1);
    var base = Math.min(BACKOFF_MAX_MS, BACKOFF_BASE_MS * Math.pow(2, exponent));
    var jitter = 0.8 + 0.4 * (typeof random === 'number' ? random : 0.5);

    return Math.round(Math.min(BACKOFF_MAX_MS, base * jitter));
  }

  function isQuietPath(pathname) {
    var path = String(pathname || '');
    for (var i = 0; i < QUIET_PATH_PREFIXES.length; i++) {
      if (path.indexOf(QUIET_PATH_PREFIXES[i]) === 0) {
        return true;
      }
    }

    return false;
  }

  /**
   * Decides whether the page may be reloaded right now. Pure: every input is a primitive so tests can drive it.
   *  - quiet pages (login, checkout, dashboard) are never reloaded in place; the next navigation is already fresh
   *  - pending submits/uploads, open dialogs and unsaved typed text always win
   *  - hidden pages reload at once; visible pages must be idle (no focused field with content, no recent input)
   */
  function evaluateReloadSafety(snapshot) {
    var s = snapshot || {};
    var now = s.now || 0;
    if (isQuietPath(s.pathname)) {
      return { safe: false, reason: 'quiet-path', retry: false };
    }
    if ((s.pendingSubmitUntil || 0) > now) {
      return { safe: false, reason: 'pending-submit', retry: true };
    }
    if (s.hasFileSelected) {
      return { safe: false, reason: 'file-selected', retry: true };
    }
    if (s.modalOpen) {
      return { safe: false, reason: 'dialog-open', retry: true };
    }
    if (s.hasDirtyText) {
      return { safe: false, reason: 'unsaved-input', retry: true };
    }
    if (s.visibilityState === 'hidden') {
      return { safe: true, reason: 'hidden', retry: false };
    }
    if (s.focusedHasContent) {
      return { safe: false, reason: 'typing', retry: true };
    }
    if (now - (s.lastInteractionAt || 0) < IDLE_MS) {
      return { safe: false, reason: 'recent-interaction', retry: true };
    }

    return { safe: true, reason: 'idle', retry: false };
  }

  function lowerType(element) {
    return String((element && element.type) || '').toLowerCase();
  }

  /** Reads the few facts evaluateReloadSafety needs from the live document. */
  function inspectDocument(doc) {
    var facts = { focusedHasContent: false, hasDirtyText: false, hasFileSelected: false, modalOpen: false };
    try {
      var active = doc.activeElement;
      if (active && active !== doc.body && active !== doc.documentElement) {
        var tag = String(active.tagName || '').toLowerCase();
        if (tag === 'select') {
          facts.focusedHasContent = true;
        } else if (tag === 'textarea' || (tag === 'input' && NON_TYPING_TYPES.indexOf(lowerType(active)) === -1)) {
          facts.focusedHasContent = String(active.value || '').trim() !== '';
        } else if (active.isContentEditable) {
          facts.focusedHasContent = String(active.textContent || '').trim() !== '';
        }
      }

      var fields = doc.querySelectorAll('input, textarea');
      var limit = Math.min(fields.length, 300);
      for (var i = 0; i < limit; i++) {
        var field = fields[i];
        var type = lowerType(field);
        if (type === 'file') {
          if (field.files && field.files.length > 0) {
            facts.hasFileSelected = true;
          }
          continue;
        }
        var isTextarea = String(field.tagName || '').toLowerCase() === 'textarea';
        if (!isTextarea && TEXT_LIKE_TYPES.indexOf(type) === -1) {
          continue;
        }
        if (field.readOnly || field.disabled) {
          continue;
        }
        var value = String(field.value || '');
        if (value.trim() !== '' && value !== String(field.defaultValue || '')) {
          facts.hasDirtyText = true;
        }
      }

      facts.modalOpen = !!doc.querySelector(MODAL_SELECTOR);
    } catch (error) {
      /* an unreadable DOM must never throw out of the updater */
    }

    return facts;
  }

  function readPageBuild(doc) {
    try {
      var meta = doc.querySelector('meta[name="portal-build"]');
      var value = meta ? String(meta.getAttribute('content') || '').trim() : '';

      return BUILD_PATTERN.test(value) ? value : null;
    } catch (error) {
      return null;
    }
  }

  /**
   * verdict shown in the diagnostics panel:
   *  UP TO DATE: page, server and active worker agree
   *  UPDATE PENDING: a newer build exists and a worker for it is installing/waiting/just registered
   *  STALE: something disagrees and nothing is on its way
   */
  function decideVerdict(info) {
    var i = info || {};
    if (!i.serverBuild) {
      return 'UNKNOWN';
    }
    if (i.pageBuild === i.serverBuild && i.activeBuild === i.serverBuild) {
      return 'UP TO DATE';
    }
    if (i.installingBuild === i.serverBuild || i.waitingBuild === i.serverBuild || i.attemptBuild === i.serverBuild || i.pendingReloadTarget === i.serverBuild) {
      return 'UPDATE PENDING';
    }

    return 'STALE';
  }

  function createController(env) {
    var doc = env.document;
    var loc = env.location;
    var nav = env.navigator;
    var sw = nav && nav.serviceWorker ? nav.serviceWorker : null;
    var pageBuild = readPageBuild(doc);

    var state = {
      started: false,
      hadController: !!(sw && sw.controller),
      failures: 0,
      lastCheckAt: 0,
      serverBuild: null,
      inFlight: null,
      checkTimer: null,
      attempt: null,
      escalationTimer: null,
      pendingReload: null,
      reloadTimer: null,
      lastInteractionAt: 0,
      pendingSubmitUntil: 0,
      verifiedWorkerBuild: null,
      lastActivation: null,
      lastEscape: null,
      events: []
    };

    function now() {
      return env.now();
    }

    function remember(message) {
      state.events.push({ at: now(), message: String(message) });
      if (state.events.length > 25) {
        state.events.shift();
      }
    }

    function storageGet(store, key) {
      try {
        return store ? store.getItem(key) : null;
      } catch (error) {
        return null;
      }
    }

    function storageSet(store, key, value) {
      try {
        if (!store) {
          return false;
        }
        store.setItem(key, value);

        return true;
      } catch (error) {
        return false;
      }
    }

    function storageRemove(store, key) {
      try {
        if (store) {
          store.removeItem(key);
        }
      } catch (error) {
        /* ignore */
      }
    }

    function readList(key) {
      var raw = storageGet(env.sessionStorage, key);
      if (!raw) {
        return [];
      }
      try {
        var parsed = JSON.parse(raw);

        return Array.isArray(parsed) ? parsed : [];
      } catch (error) {
        return [];
      }
    }

    function listHas(key, value) {
      return readList(key).indexOf(value) !== -1;
    }

    function listAdd(key, value) {
      var list = readList(key);
      if (list.indexOf(value) === -1) {
        list.push(value);
      }

      return storageSet(env.sessionStorage, key, JSON.stringify(list.slice(-8)));
    }

    function persistCheck(ok, detail) {
      storageSet(env.localStorage, LAST_CHECK_KEY, JSON.stringify({
        at: now(),
        ok: !!ok,
        serverBuild: state.serverBuild,
        detail: String(detail || '')
      }));
    }

    function swUrlFor(build) {
      return buildSwUrl(build);
    }

    function registerBuild(build) {
      if (!sw || !isRealBuild(build)) {
        return Promise.resolve(null);
      }
      remember('register ' + swUrlFor(build));

      return sw.register(swUrlFor(build), { scope: SW_SCOPE, updateViaCache: 'none' }).then(function (registration) {
        try {
          var updated = registration && registration.update ? registration.update() : null;
          if (updated && updated.catch) {
            updated.catch(noop);
          }
        } catch (error) {
          /* update() is only a nudge */
        }

        return registration;
      });
    }

    function workerBuilds(registration) {
      function of(worker) {
        return worker ? parseBuildFromUrl(worker.scriptURL) : null;
      }

      return {
        active: of(registration && registration.active),
        waiting: of(registration && registration.waiting),
        installing: of(registration && registration.installing)
      };
    }

    function askWorkerBuild(worker, timeoutMs) {
      return new Promise(function (resolve) {
        if (!worker || !env.MessageChannel) {
          resolve(null);
          return;
        }
        var finished = false;
        var channel = new env.MessageChannel();
        var timer = null;
        function finish(value) {
          if (finished) {
            return;
          }
          finished = true;
          env.clearTimeout(timer);
          try {
            channel.port1.onmessage = null;
            if (channel.port1.close) {
              channel.port1.close();
            }
          } catch (error) {
            /* ignore */
          }
          resolve(value);
        }
        timer = env.setTimeout(function () {
          finish(null);
        }, timeoutMs || GET_BUILD_TIMEOUT_MS);
        channel.port1.onmessage = function (event) {
          var data = event && event.data;
          finish(data && data.type === 'PORTAL_SW_BUILD' && BUILD_PATTERN.test(String(data.build)) ? String(data.build) : null);
        };
        try {
          worker.postMessage({ type: 'GET_BUILD' }, [channel.port2]);
        } catch (error) {
          finish(null);
        }
      });
    }

    function fetchServerBuild() {
      return new Promise(function (resolve, reject) {
        var settled = false;
        var controller = env.AbortController ? new env.AbortController() : null;
        var timer = env.setTimeout(function () {
          if (settled) {
            return;
          }
          settled = true;
          try {
            if (controller) {
              controller.abort();
            }
          } catch (error) {
            /* ignore */
          }
          reject(new Error('timeout'));
        }, FETCH_TIMEOUT_MS);
        var init = { cache: 'no-store', credentials: 'omit', headers: { Accept: 'application/json' } };
        if (controller) {
          init.signal = controller.signal;
        }
        env.fetch(VERSION_ENDPOINT, init).then(function (response) {
          if (!response || !response.ok) {
            throw new Error('http ' + (response && response.status));
          }

          return response.json();
        }).then(function (data) {
          if (settled) {
            return;
          }
          settled = true;
          env.clearTimeout(timer);
          var build = data && data.build;
          if (typeof build !== 'string' || !BUILD_PATTERN.test(build)) {
            reject(new Error('bad build'));
            return;
          }
          resolve(build);
        }, function (error) {
          if (settled) {
            return;
          }
          settled = true;
          env.clearTimeout(timer);
          reject(error);
        });
      });
    }

    /* ---- reload gating ------------------------------------------------ */

    function snapshot() {
      var facts = inspectDocument(doc);

      return {
        pathname: loc.pathname,
        visibilityState: doc.visibilityState,
        now: now(),
        lastInteractionAt: state.lastInteractionAt,
        pendingSubmitUntil: state.pendingSubmitUntil,
        focusedHasContent: facts.focusedHasContent,
        hasDirtyText: facts.hasDirtyText,
        hasFileSelected: facts.hasFileSelected,
        modalOpen: facts.modalOpen
      };
    }

    function performReload(pending) {
      env.clearTimeout(state.reloadTimer);
      state.reloadTimer = null;
      state.pendingReload = null;
      var guardKey = pending.escape ? 'escape:' + pending.target : pending.target;
      if (listHas(RELOADED_KEY, guardKey)) {
        remember('reload skipped (loop guard) for ' + guardKey);

        return;
      }
      if (!listAdd(RELOADED_KEY, guardKey)) {
        remember('reload skipped (no sessionStorage to guard against loops)');

        return;
      }
      storageSet(env.sessionStorage, JUST_UPDATED_KEY, pending.target);
      remember('reload for ' + pending.target + ' (' + pending.reason + ')');
      try {
        var target = new URL(loc.href);
        target.hash = '';
        loc.replace(target.href);
      } catch (error) {
        loc.reload();
      }
    }

    function tryReload() {
      var pending = state.pendingReload;
      if (!pending) {
        return;
      }
      env.clearTimeout(state.reloadTimer);
      state.reloadTimer = null;
      var verdict = evaluateReloadSafety(snapshot());
      if (verdict.safe) {
        performReload(pending);

        return;
      }
      pending.waitingFor = verdict.reason;
      if (verdict.retry && doc.visibilityState !== 'hidden') {
        state.reloadTimer = env.setTimeout(tryReload, RELOAD_RETRY_MS);
      }
    }

    function requestReload(target, reason, options) {
      var opts = options || {};
      if (!isRealBuild(target)) {
        return false;
      }
      if (!opts.escape && target === pageBuild) {
        return false;
      }
      var guardKey = opts.escape ? 'escape:' + target : target;
      if (listHas(RELOADED_KEY, guardKey)) {
        remember('reload for ' + target + ' already done in this tab');

        return false;
      }
      if (state.pendingReload && state.pendingReload.target === target && !!state.pendingReload.escape === !!opts.escape) {
        return true;
      }
      state.pendingReload = { target: target, reason: reason, since: now(), escape: !!opts.escape, waitingFor: null };
      remember('reload queued for ' + target + ' (' + reason + ')');
      tryReload();

      return true;
    }

    /* ---- attempt tracking + escape hatch ------------------------------ */

    function clearAttempt() {
      state.attempt = null;
      env.clearTimeout(state.escalationTimer);
      state.escalationTimer = null;
    }

    function armEscalation(delayMs) {
      env.clearTimeout(state.escalationTimer);
      state.escalationTimer = env.setTimeout(escalate, delayMs);
    }

    function noteAttempt(build) {
      if (state.attempt && state.attempt.build === build) {
        return;
      }
      state.attempt = { build: build, at: now(), nudged: false };
      armEscalation(ESCAPE_AFTER_MS + 250);
    }

    function escapeHatch(target, reason) {
      if (!sw || !isRealBuild(target)) {
        return Promise.resolve(false);
      }
      if (listHas(ESCAPED_KEY, target)) {
        remember('escape hatch already used for ' + target);

        return Promise.resolve(false);
      }
      if (!listAdd(ESCAPED_KEY, target)) {
        return Promise.resolve(false);
      }
      state.lastEscape = { at: now(), reason: reason, target: target };
      remember('escape hatch: ' + reason);
      var steps = [];
      steps.push(
        sw.getRegistrations().then(function (registrations) {
          return Promise.all(registrations.map(function (registration) {
            return registration.unregister();
          }));
        }).catch(noop)
      );
      if (env.caches && env.caches.keys) {
        steps.push(
          env.caches.keys().then(function (names) {
            return Promise.all(names.filter(function (name) {
              return name.indexOf('jawish-') === 0;
            }).map(function (name) {
              return env.caches.delete(name);
            }));
          }).catch(noop)
        );
      }

      return Promise.all(steps).then(function () {
        clearAttempt();
        state.verifiedWorkerBuild = null;
        requestReload(target, 'escape-hatch', { escape: true });

        return true;
      });
    }

    function escalate() {
      state.escalationTimer = null;
      var attempt = state.attempt;
      if (!attempt) {
        return;
      }
      if (nav.onLine === false) {
        armEscalation(ESCAPE_AFTER_MS);

        return;
      }
      fetchServerBuild().then(function (fresh) {
        state.serverBuild = fresh;
        if (fresh !== attempt.build) {
          clearAttempt();

          return reconcile(fresh);
        }

        return sw.getRegistration(SW_SCOPE).then(function (registration) {
          if (!registration) {
            clearAttempt();

            return registerBuild(fresh);
          }
          var builds = workerBuilds(registration);
          if (builds.active === fresh) {
            return askWorkerBuild(sw.controller || registration.active, GET_BUILD_TIMEOUT_MS).then(function (answered) {
              if (answered === fresh) {
                clearAttempt();
                state.verifiedWorkerBuild = fresh;
                pageIsStale(fresh);

                return null;
              }

              return escapeHatch(fresh, 'worker-did-not-confirm-build');
            });
          }
          if (builds.waiting === fresh && registration.waiting && !attempt.nudged) {
            attempt.nudged = true;
            try {
              registration.waiting.postMessage({ type: 'SKIP_WAITING' });
            } catch (error) {
              /* ignore */
            }
            armEscalation(5000);

            return null;
          }

          return escapeHatch(fresh, 'update-did-not-activate');
        });
      }).catch(function () {
        armEscalation(ESCAPE_AFTER_MS * 2);
      });
    }

    /* ---- version check ------------------------------------------------ */

    function registerAttempt(build) {
      noteAttempt(build);

      return registerBuild(build).catch(function (error) {
        remember('register failed: ' + (error && error.message ? error.message : error));
      });
    }

    function pageIsStale(serverBuild) {
      if (pageBuild !== serverBuild) {
        requestReload(serverBuild, 'page-older-than-server');
      }
    }

    function verifyActiveWorker(registration, serverBuild) {
      if (state.verifiedWorkerBuild === serverBuild) {
        clearAttempt();
        pageIsStale(serverBuild);

        return Promise.resolve();
      }
      var controlled = sw.controller;
      if (!controlled) {
        clearAttempt();
        pageIsStale(serverBuild);

        return Promise.resolve();
      }

      return askWorkerBuild(controlled, GET_BUILD_TIMEOUT_MS).then(function (answered) {
        if (answered === serverBuild) {
          state.verifiedWorkerBuild = serverBuild;
          clearAttempt();
          pageIsStale(serverBuild);

          return null;
        }
        remember('worker confirmed ' + (answered || 'nothing') + ' but server is ' + serverBuild);
        if (answered === null) {
          return escapeHatch(serverBuild, 'worker-silent-on-get-build');
        }
        noteAttempt(serverBuild);
        try {
          registration.update();
        } catch (error) {
          /* ignore */
        }

        return null;
      });
    }

    function reconcile(serverBuild) {
      if (!sw) {
        return Promise.resolve();
      }

      return sw.getRegistration(SW_SCOPE).then(function (registration) {
        if (!registration) {
          return registerAttempt(serverBuild);
        }
        var builds = workerBuilds(registration);
        if (builds.installing === serverBuild || builds.waiting === serverBuild) {
          noteAttempt(serverBuild);
          if (builds.waiting === serverBuild && registration.waiting) {
            try {
              registration.waiting.postMessage({ type: 'SKIP_WAITING' });
            } catch (error) {
              /* ignore */
            }
          }

          return null;
        }
        if (builds.active === serverBuild) {
          return verifyActiveWorker(registration, serverBuild);
        }

        return registerAttempt(serverBuild);
      }).catch(function (error) {
        remember('reconcile failed: ' + (error && error.message ? error.message : error));
      });
    }

    function scheduleNext() {
      env.clearTimeout(state.checkTimer);
      state.checkTimer = null;
      if (doc.visibilityState === 'hidden') {
        return;
      }
      var delay = state.failures > 0 ? backoffDelay(state.failures, env.random()) : CHECK_INTERVAL_MS;
      state.checkTimer = env.setTimeout(function () {
        state.checkTimer = null;
        check('timer');
      }, delay);
    }

    function scheduleCheck(delayMs, reason) {
      env.clearTimeout(state.checkTimer);
      state.checkTimer = env.setTimeout(function () {
        state.checkTimer = null;
        check(reason || 'scheduled');
      }, Math.max(0, delayMs));
    }

    function check(reason, options) {
      var opts = options || {};
      if (!sw || !pageBuild) {
        return Promise.resolve(null);
      }
      if (state.inFlight) {
        return state.inFlight;
      }
      var t = now();
      if (!opts.force && state.lastCheckAt && t - state.lastCheckAt < MIN_CHECK_GAP_MS) {
        scheduleNext();

        return Promise.resolve(null);
      }
      if (nav.onLine === false) {
        persistCheck(false, 'offline');
        remember('check skipped (offline)');

        return Promise.resolve(null);
      }
      state.lastCheckAt = t;
      remember('check (' + reason + ')');
      state.inFlight = fetchServerBuild().then(function (serverBuild) {
        state.failures = 0;
        state.serverBuild = serverBuild;
        persistCheck(true, reason);

        return reconcile(serverBuild);
      }, function (error) {
        state.failures += 1;
        persistCheck(false, error && error.message ? error.message : 'error');
        remember('version check failed: ' + (error && error.message ? error.message : error));
      }).then(function () {
        state.inFlight = null;
        scheduleNext();
      });

      return state.inFlight;
    }

    /* ---- events from the worker / page -------------------------------- */

    function onWorkerActivated(build, via) {
      state.lastActivation = { build: build, at: now(), via: via };
      state.verifiedWorkerBuild = isRealBuild(build) ? build : null;
      if (!state.hadController) {
        state.hadController = true;
        remember('first install activated (' + build + '): nothing to reload');

        return;
      }
      if (state.attempt && state.attempt.build === build) {
        clearAttempt();
      }
      var target = isRealBuild(build) ? build : state.serverBuild;
      if (!target) {
        check('activated', { force: true });

        return;
      }
      if (target !== pageBuild) {
        requestReload(target, 'worker-activated');
      }
    }

    function onServiceWorkerMessage(event) {
      var data = event && event.data;
      if (!data || typeof data !== 'object') {
        return;
      }
      if (data.type === 'PORTAL_SW_ACTIVATED') {
        onWorkerActivated(String(data.build || ''), 'message');
      }
    }

    function onControllerChange() {
      var had = state.hadController;
      state.hadController = true;
      if (!had) {
        remember('controllerchange: first claim of an uncontrolled page, nothing to reload');

        return;
      }
      askWorkerBuild(sw.controller, GET_BUILD_TIMEOUT_MS).then(function (build) {
        onWorkerActivated(build || 'legacy', 'controllerchange');
      });
    }

    function markInteraction() {
      state.lastInteractionAt = now();
    }

    function markSubmit() {
      state.pendingSubmitUntil = now() + SUBMIT_GRACE_MS;
      state.lastInteractionAt = now();
    }

    function bindListeners() {
      var passive = { capture: true, passive: true };
      ['pointerdown', 'touchstart', 'keydown', 'input', 'wheel', 'scroll', 'click'].forEach(function (type) {
        doc.addEventListener(type, markInteraction, passive);
      });
      doc.addEventListener('submit', markSubmit, true);
      doc.addEventListener('visibilitychange', function () {
        if (doc.visibilityState === 'visible') {
          check('visible');
        } else {
          env.clearTimeout(state.checkTimer);
          state.checkTimer = null;
          tryReload();
        }
      });
      env.addEventListener('online', function () {
        state.failures = 0;
        check('online', { force: true });
      });
      env.addEventListener('pageshow', function (event) {
        if (event && event.persisted) {
          check('pageshow', { force: true });
        }
      });
      sw.addEventListener('message', onServiceWorkerMessage);
      sw.addEventListener('controllerchange', onControllerChange);
    }

    function showToast(text) {
      try {
        var el = doc.createElement('div');
        el.setAttribute('role', 'status');
        el.setAttribute('dir', 'rtl');
        el.textContent = text;
        el.style.cssText = 'position:fixed;left:0;right:0;bottom:1rem;margin:0 auto;width:max-content;max-width:90vw;'
          + 'padding:.55rem 1rem;border-radius:999px;background:#111827;color:#fff;font:600 .85rem system-ui,sans-serif;'
          + 'z-index:2147483000;opacity:0;transition:opacity .25s;pointer-events:none;text-align:center';
        (doc.body || doc.documentElement).appendChild(el);
        env.setTimeout(function () {
          el.style.opacity = '1';
        }, 30);
        env.setTimeout(function () {
          el.style.opacity = '0';
          env.setTimeout(function () {
            if (el.parentNode) {
              el.parentNode.removeChild(el);
            }
          }, 350);
        }, TOAST_REVEAL_MS);
      } catch (error) {
        /* cosmetic only */
      }
    }

    function announceCompletedUpdate() {
      var updatedTo = storageGet(env.sessionStorage, JUST_UPDATED_KEY);
      if (!updatedTo) {
        return;
      }
      storageRemove(env.sessionStorage, JUST_UPDATED_KEY);
      if (updatedTo === pageBuild) {
        showToast('تم تحديث التطبيق إلى أحدث إصدار');
      }
    }

    function start() {
      if (state.started) {
        return Promise.resolve();
      }
      state.started = true;
      if (!sw || !env.isSecureContext || !pageBuild) {
        return Promise.resolve();
      }
      bindListeners();
      announceCompletedUpdate();

      return sw.getRegistration(SW_SCOPE).then(function (registration) {
        if (!registration) {
          return registerBuild(pageBuild).catch(noop).then(function () {
            scheduleCheck(env.random() * CHECK_JITTER_MAX_MS, 'after-first-install');
          });
        }
        var builds = workerBuilds(registration);
        var registered = builds.installing || builds.waiting || builds.active;
        if (registered !== pageBuild) {
          return check('load-mismatch', { force: true });
        }
        scheduleCheck(env.random() * CHECK_JITTER_MAX_MS, 'load');

        return null;
      }).catch(function (error) {
        remember('start failed: ' + (error && error.message ? error.message : error));
      });
    }

    /* ---- diagnostics -------------------------------------------------- */

    function describeWorker(worker) {
      if (!worker) {
        return Promise.resolve(null);
      }

      return askWorkerBuild(worker, GET_BUILD_TIMEOUT_MS).then(function (answered) {
        return { scriptURL: worker.scriptURL, state: worker.state, urlBuild: parseBuildFromUrl(worker.scriptURL), answeredBuild: answered };
      });
    }

    function getStatus() {
      var status = {
        origin: loc.origin || '',
        host: loc.host || '',
        pageBuild: pageBuild,
        scriptBuild: SCRIPT_BUILD_MARKER,
        online: nav.onLine,
        controller: !!(sw && sw.controller),
        serverBuild: null,
        serverError: null,
        registration: null,
        caches: [],
        lastCheck: null,
        failures: state.failures,
        pendingReload: state.pendingReload ? { target: state.pendingReload.target, waitingFor: state.pendingReload.waitingFor } : null,
        lastActivation: state.lastActivation,
        lastEscape: state.lastEscape,
        events: state.events.slice(-12)
      };
      try {
        status.lastCheck = JSON.parse(storageGet(env.localStorage, LAST_CHECK_KEY) || 'null');
      } catch (error) {
        status.lastCheck = null;
      }
      var tasks = [];
      tasks.push(fetchServerBuild().then(function (build) {
        status.serverBuild = build;
      }, function (error) {
        status.serverError = error && error.message ? error.message : 'error';
      }));
      if (sw) {
        tasks.push(sw.getRegistration(SW_SCOPE).then(function (registration) {
          if (!registration) {
            return null;
          }
          status.registration = { scope: registration.scope };

          return Promise.all([
            describeWorker(registration.active),
            describeWorker(registration.waiting),
            describeWorker(registration.installing)
          ]).then(function (workers) {
            status.registration.active = workers[0];
            status.registration.waiting = workers[1];
            status.registration.installing = workers[2];
          });
        }).catch(noop));
      }
      if (env.caches && env.caches.keys) {
        tasks.push(env.caches.keys().then(function (names) {
          status.caches = names;
        }).catch(noop));
      }

      return Promise.all(tasks).then(function () {
        var registration = status.registration || {};
        status.verdict = decideVerdict({
          pageBuild: pageBuild,
          serverBuild: status.serverBuild,
          activeBuild: registration.active ? (registration.active.answeredBuild || null) : null,
          waitingBuild: registration.waiting ? registration.waiting.urlBuild : null,
          installingBuild: registration.installing ? registration.installing.urlBuild : null,
          attemptBuild: state.attempt ? state.attempt.build : null,
          pendingReloadTarget: state.pendingReload ? state.pendingReload.target : null
        });

        return status;
      });
    }

    return {
      pageBuild: pageBuild,
      state: state,
      start: start,
      check: check,
      register: function (build) {
        return registerBuild(build || pageBuild);
      },
      getStatus: getStatus,
      onWorkerActivated: onWorkerActivated,
      requestReload: requestReload,
      escapeHatch: escapeHatch,
      tryReload: tryReload,
      snapshot: snapshot
    };
  }

  var SCRIPT_BUILD_MARKER = readScriptBuildMarker();

  function readScriptBuildMarker() {
    try {
      var current = typeof document !== 'undefined' ? document.currentScript : null;
      var src = current && current.src ? String(current.src) : '';
      var match = /[?&]v=([0-9A-Za-z._-]+)/.exec(src);

      return match ? match[1] : null;
    } catch (error) {
      return null;
    }
  }

  function safeStorage(win, name) {
    try {
      var store = win[name];
      var probe = '__portal_probe__';
      store.setItem(probe, '1');
      store.removeItem(probe);

      return store;
    } catch (error) {
      return null;
    }
  }

  function boot(win) {
    if (win.__portalPwaUpdateBooted) {
      return win.PortalPwaUpdate;
    }
    win.__portalPwaUpdateBooted = true;
    var env = {
      document: win.document,
      location: win.location,
      navigator: win.navigator,
      fetch: typeof win.fetch === 'function' ? win.fetch.bind(win) : null,
      AbortController: win.AbortController,
      MessageChannel: win.MessageChannel,
      caches: win.caches,
      isSecureContext: win.isSecureContext === true,
      sessionStorage: safeStorage(win, 'sessionStorage'),
      localStorage: safeStorage(win, 'localStorage'),
      now: function () {
        return Date.now();
      },
      setTimeout: win.setTimeout.bind(win),
      clearTimeout: win.clearTimeout.bind(win),
      random: Math.random,
      addEventListener: win.addEventListener.bind(win)
    };
    if (!env.fetch) {
      return null;
    }
    var controller = createController(env);
    win.PortalPwaUpdate = {
      buildUrl: buildSwUrl,
      register: controller.register,
      check: function () {
        return controller.check('api', { force: true });
      },
      getStatus: controller.getStatus,
      pageBuild: controller.pageBuild
    };
    controller.start();

    return win.PortalPwaUpdate;
  }

  return {
    parseBuildFromUrl: parseBuildFromUrl,
    isRealBuild: isRealBuild,
    buildSwUrl: buildSwUrl,
    backoffDelay: backoffDelay,
    isQuietPath: isQuietPath,
    evaluateReloadSafety: evaluateReloadSafety,
    inspectDocument: inspectDocument,
    readPageBuild: readPageBuild,
    decideVerdict: decideVerdict,
    createController: createController,
    boot: boot,
    constants: {
      SW_SCOPE: SW_SCOPE,
      VERSION_ENDPOINT: VERSION_ENDPOINT,
      CHECK_JITTER_MAX_MS: CHECK_JITTER_MAX_MS,
      CHECK_INTERVAL_MS: CHECK_INTERVAL_MS,
      MIN_CHECK_GAP_MS: MIN_CHECK_GAP_MS,
      FETCH_TIMEOUT_MS: FETCH_TIMEOUT_MS,
      BACKOFF_BASE_MS: BACKOFF_BASE_MS,
      BACKOFF_MAX_MS: BACKOFF_MAX_MS,
      GET_BUILD_TIMEOUT_MS: GET_BUILD_TIMEOUT_MS,
      ESCAPE_AFTER_MS: ESCAPE_AFTER_MS,
      IDLE_MS: IDLE_MS,
      SUBMIT_GRACE_MS: SUBMIT_GRACE_MS,
      RELOAD_RETRY_MS: RELOAD_RETRY_MS,
      RELOADED_KEY: RELOADED_KEY,
      ESCAPED_KEY: ESCAPED_KEY,
      JUST_UPDATED_KEY: JUST_UPDATED_KEY,
      LAST_CHECK_KEY: LAST_CHECK_KEY
    }
  };
});
