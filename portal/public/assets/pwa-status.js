/**
 * On-device update diagnostics for the installed app (no address bar, no devtools).
 *
 * Five quick taps on the footer text (anything marked data-pwa-status-trigger) or opening any page with
 * ?pwa-status=1 shows whether THIS window runs the build the server serves, which service worker controls
 * it, how Chrome launched it (standalone vs. a browser toolbar) and whether any link would leave the app's
 * origin. It only reads state; the two buttons are "check now" and a caches-only reset (cookies and the
 * login are never touched).
 *
 * Plain ES2015 on purpose (see pwa-update.js). Pure helpers are exported for the Node tests.
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

  var TAPS_REQUIRED = 5;
  var TAP_GAP_MS = 1000;
  var TRIGGER_SELECTOR = '[data-pwa-status-trigger]';
  var PANEL_ID = 'pwa-status-panel';
  var QUERY_FLAG = 'pwa-status';

  var VERDICTS = {
    'UP TO DATE': {
      label: 'محدَّث',
      tone: 'ok',
      hint: 'الصفحة والخادم والعامل الخلفي على النسخة نفسها.'
    },
    'UPDATE PENDING': {
      label: 'التحديث قيد التطبيق',
      tone: 'warn',
      hint: 'توجد نسخة أحدث وجارٍ تثبيتها تلقائياً. انتظر نصف دقيقة ثم اضغط «فحص الآن».'
    },
    STALE: {
      label: 'نسخة قديمة',
      tone: 'bad',
      hint: 'على الخادم نسخة أحدث ولا يوجد تحديث قيد التنفيذ. اضغط «فحص الآن»، وإن بقيت الحالة كما هي اضغط «إعادة ضبط الذاكرة».'
    },
    UNKNOWN: {
      label: 'غير معروف',
      tone: 'muted',
      hint: 'تعذّرت قراءة نسخة الخادم (لا اتصال أو الخادم لا يجيب). يُعاد الفحص تلقائياً عند عودة الاتصال.'
    }
  };

  var WAIT_REASONS = {
    'quiet-path': 'صفحة حساسة (دخول/سلة/لوحة): لا إعادة تحميل في مكانها، والتنقل التالي يكون محدَّثاً',
    'pending-submit': 'نموذج قيد الإرسال',
    'file-selected': 'ملف مختار للرفع',
    'dialog-open': 'نافذة مفتوحة',
    'unsaved-input': 'نص مكتوب غير محفوظ',
    typing: 'جارٍ الكتابة',
    'recent-interaction': 'تفاعل حديث (ينتظر خمس ثوانٍ من الهدوء)',
    hidden: 'التطبيق في الخلفية',
    idle: 'خامل'
  };

  var DISPLAY_MODES = ['standalone', 'fullscreen', 'window-controls-overlay', 'minimal-ui'];

  function errorText(error) {
    return error && error.message ? String(error.message) : String(error);
  }

  /** Counts taps that follow each other within gapMs; true on the tap that completes the sequence. */
  function createTapCounter(options) {
    var opts = options || {};
    var required = opts.required || TAPS_REQUIRED;
    var gapMs = opts.gapMs || TAP_GAP_MS;
    var clock = opts.now || function () {
      return Date.now();
    };
    var count = 0;
    var last = 0;

    return {
      tap: function () {
        var at = clock();
        count = last && at - last <= gapMs ? count + 1 : 1;
        last = at;
        if (count >= required) {
          count = 0;
          last = 0;

          return true;
        }

        return false;
      },
      reset: function () {
        count = 0;
        last = 0;
      }
    };
  }

  function verdictInfo(verdict) {
    return VERDICTS[verdict] || VERDICTS.UNKNOWN;
  }

  /** standalone | fullscreen | window-controls-overlay | minimal-ui | browser */
  function displayModeOf(win) {
    try {
      if (win.navigator && win.navigator.standalone === true) {
        return 'standalone';
      }
      for (var i = 0; i < DISPLAY_MODES.length; i++) {
        if (win.matchMedia('(display-mode: ' + DISPLAY_MODES[i] + ')').matches) {
          return DISPLAY_MODES[i];
        }
      }
    } catch (error) {
      /* matchMedia missing: report as a plain browser tab */
    }

    return 'browser';
  }

  function registrableDomain(host) {
    var labels = String(host || '').toLowerCase().split(':')[0].split('.').filter(Boolean);

    return labels.slice(-2).join('.');
  }

  /**
   * Links that stay on the same site but change origin (www <-> apex, http <-> https). Inside an installed app
   * those are the navigations Chrome answers with the browser toolbar (X, address, three dots).
   */
  function findCrossHostLinks(anchors, location) {
    var result = { count: 0, samples: [] };
    var own = registrableDomain(location.host);
    for (var i = 0; i < anchors.length; i++) {
      var raw = anchors[i] && anchors[i].getAttribute ? anchors[i].getAttribute('href') : '';
      if (!raw || raw.charAt(0) === '#' || /^(mailto:|tel:|javascript:|sms:|whatsapp:)/i.test(raw)) {
        continue;
      }
      var url;
      try {
        url = new URL(raw, location.href);
      } catch (error) {
        continue;
      }
      if (url.protocol !== 'http:' && url.protocol !== 'https:') {
        continue;
      }
      if (url.origin !== location.origin && registrableDomain(url.host) === own && own !== '') {
        result.count++;
        if (result.samples.length < 5) {
          result.samples.push(url.href);
        }
      }
    }

    return result;
  }

  function pickManifest(manifest, location) {
    var m = manifest || {};
    var start = new URL(String(m.start_url || '/'), location.href);
    var iconSrc = m.icons && m.icons[0] ? String(m.icons[0].src || '') : '';
    var revision = /[?&]webapk=(\d+)/.exec(iconSrc);

    return {
      id: new URL(String(m.id || m.start_url || '/'), start.origin).href,
      start_url: start.href,
      scope: m.scope ? new URL(String(m.scope), start.href).href : null,
      display: m.display || null,
      name: m.name || null,
      short_name: m.short_name || null,
      iconRevision: revision ? revision[1] : null
    };
  }

  function relativeAge(at, now) {
    if (!at) {
      return 'لم يُسجَّل';
    }
    var seconds = Math.max(0, Math.round((now - at) / 1000));
    if (seconds < 60) {
      return 'قبل ' + seconds + ' ثانية';
    }
    if (seconds < 3600) {
      return 'قبل ' + Math.round(seconds / 60) + ' دقيقة';
    }

    return 'قبل ' + Math.round(seconds / 3600) + ' ساعة';
  }

  function buildTone(build, serverBuild) {
    if (!serverBuild) {
      return '';
    }

    return build === serverBuild ? 'ok' : 'warn';
  }

  function staleCacheNames(names, serverBuild) {
    if (!serverBuild) {
      return [];
    }

    return (names || []).filter(function (name) {
      if (name.indexOf('jawish-fonts-') === 0) {
        return false;
      }

      return name.indexOf('jawish-b-' + serverBuild + '-') !== 0;
    });
  }

  /** Flat list of {key, label, value, tone, hint, ltr} rows; the panel and the copied text both use it. */
  function summarizeReport(report, now) {
    var rows = [];
    var update = report.update || {};
    var registration = update.registration || {};
    var active = registration.active || null;
    var info = verdictInfo(update.verdict || 'UNKNOWN');
    var serverBuild = update.serverBuild || null;
    var activeBuild = active ? (active.answeredBuild || active.urlBuild || null) : null;
    var clock = now || Date.now();

    function add(key, label, value, tone, hint, ltr) {
      rows.push({ key: key, label: label, value: String(value), tone: tone || '', hint: hint || '', ltr: !!ltr });
    }

    add('verdict', 'الحالة', info.label, info.tone, info.hint, false);

    var mode = report.displayMode;
    if (mode === 'standalone' || mode === 'fullscreen' || mode === 'window-controls-overlay') {
      add('displayMode', 'وضع العرض', mode + ' — يعمل كتطبيق بلا شريط المتصفح', 'ok', '', true);
    } else if (mode === 'minimal-ui') {
      add('displayMode', 'وضع العرض', mode + ' — شريط متصفح مصغّر', 'warn', 'يظهر الشريط العلوي (X والعنوان و⋮) عندما يعرض كروم الصفحة خارج نطاق التطبيق المثبّت أو عندما تكون الأيقونة اختصاراً عادياً وليست تطبيقاً.', true);
    } else {
      add('displayMode', 'وضع العرض', 'browser — صفحة متصفح عادية', 'warn', 'هذه النافذة ليست تشغيلاً لتطبيق مثبّت. إن فتحتها من أيقونة الشاشة الرئيسية فهي اختصار عادي.', true);
    }

    var manifest = report.manifest || null;
    var scopeOrigin = null;
    if (manifest && manifest.scope) {
      try {
        scopeOrigin = new URL(manifest.scope).origin;
      } catch (error) {
        scopeOrigin = null;
      }
    }
    var originOk = !scopeOrigin || scopeOrigin === report.origin;
    add('origin', 'عنوان الصفحة', report.origin, originOk ? 'ok' : 'bad', originOk ? '' : 'نطاق التطبيق في الـmanifest (' + scopeOrigin + ') يختلف عن عنوان هذه الصفحة.', true);

    var pageBuild = update.pageBuild || null;
    add('pageBuild', 'نسخة الصفحة', pageBuild || 'غير موجودة', pageBuild ? buildTone(pageBuild, serverBuild) : 'bad', '', true);
    add('serverBuild', 'نسخة الخادم', serverBuild || (update.serverError ? 'تعذّر: ' + update.serverError : 'غير معروفة'), serverBuild ? 'ok' : 'muted', '', true);
    add('activeBuild', 'نسخة العامل الخلفي', activeBuild || 'لا يوجد عامل فعّال', activeBuild ? buildTone(activeBuild, serverBuild) : 'warn', '', true);

    if (active) {
      var legacy = !/[?&]b=/.test(String(active.scriptURL || ''));
      add('workerUrl', 'عنوان العامل الفعّال', active.scriptURL, legacy ? 'warn' : 'ok', legacy ? 'عامل قديم بلا ?b= — سيُستبدل تلقائياً بأول فحص ناجح.' : '', true);
    }
    var waiting = registration.waiting ? registration.waiting.urlBuild : null;
    var installing = registration.installing ? registration.installing.urlBuild : null;
    if (waiting || installing) {
      add('workerQueue', 'عامل جديد', (installing ? 'يُثبَّت: ' + installing + ' ' : '') + (waiting ? 'ينتظر: ' + waiting : ''), 'warn', '', true);
    }
    add('controller', 'العامل يتحكم بهذه الصفحة', update.controller ? 'نعم' : 'لا', update.controller ? 'ok' : 'warn', update.controller ? '' : 'يحدث عند أول تحميل بعد التثبيت أو قبل أن يستولي العامل الجديد على الصفحة.', false);

    var caches = report.caches || [];
    var stale = staleCacheNames(caches, serverBuild);
    add('caches', 'الذاكرة المؤقتة', caches.length ? caches.join('\n') : 'فارغة', stale.length ? 'warn' : 'ok', stale.length ? 'أسماء قديمة ستُحذف عند تفعيل العامل الجديد: ' + stale.join('، ') : '', true);

    add('online', 'الاتصال', report.online ? 'متصل' : 'غير متصل', report.online ? 'ok' : 'warn', report.online ? '' : 'التحديث يُطبَّق تلقائياً عند أول تحميل ناجح بعد عودة الاتصال.', false);
    var last = update.lastCheck || null;
    add('lastCheck', 'آخر فحص للنسخة', last ? relativeAge(last.at, clock) + (last.ok ? ' — نجح' : ' — فشل' + (last.detail ? ' (' + last.detail + ')' : '')) : 'لم يُسجَّل بعد', last ? (last.ok ? 'ok' : 'warn') : 'muted', update.failures ? 'محاولات فاشلة متتالية: ' + update.failures : '', false);

    if (update.pendingReload) {
      var why = update.pendingReload.waitingFor;
      add('pendingReload', 'إعادة تحميل مؤجَّلة', 'إلى ' + update.pendingReload.target + (why ? ' — ' + (WAIT_REASONS[why] || why) : ''), 'warn', '', false);
    }
    if (update.lastActivation) {
      add('lastActivation', 'آخر تفعيل لعامل', update.lastActivation.build + ' (' + (update.lastActivation.via || '?') + ') ' + relativeAge(update.lastActivation.at, clock), '', '', true);
    }
    if (update.lastEscape) {
      add('lastEscape', 'آخر إنقاذ تلقائي', update.lastEscape.target + ' — ' + update.lastEscape.reason + ' ' + relativeAge(update.lastEscape.at, clock), 'warn', '', true);
    }

    var registrations = report.registrations || [];
    var oddScope = registrations.filter(function (entry) {
      return entry.scope !== report.origin + '/';
    });
    add('registrations', 'تسجيلات العامل', registrations.length ? registrations.map(function (entry) {
      return entry.scope + ' → ' + (entry.scriptURL || '?');
    }).join('\n') : 'لا يوجد', registrations.length === 1 && oddScope.length === 0 ? 'ok' : 'warn', registrations.length > 1 ? 'أكثر من تسجيل واحد لهذا الأصل.' : '', true);

    var links = report.crossHostLinks || { count: 0, samples: [] };
    add('crossHostLinks', 'روابط تخرج من نطاق التطبيق', links.count ? links.count + '\n' + links.samples.join('\n') : 'لا يوجد', links.count ? 'bad' : 'ok', links.count ? 'هذه الروابط تفتح نطاقاً آخر للموقع نفسه، وفي التطبيق المثبّت يظهر عندها شريط المتصفح.' : '', true);

    if (manifest) {
      add('manifest', 'الـmanifest الحالي', ['id: ' + manifest.id, 'start_url: ' + manifest.start_url, 'scope: ' + manifest.scope, 'display: ' + manifest.display, 'short_name: ' + manifest.short_name, 'icon revision: ' + manifest.iconRevision].join('\n'), '', 'هذه نسخة الخادم الآن. الـmanifest الذي بُني عليه التطبيق المثبّت يظهر في chrome://webapks ويُحدِّثه كروم وحده خلال يوم إلى ثلاثة.', true);
    }
    add('userAgent', 'المتصفح', report.userAgent, '', '', true);

    for (var i = 0; i < (report.errors || []).length; i++) {
      add('error' + i, 'تنبيه', report.errors[i], 'warn', '', false);
    }

    return rows;
  }

  function reportToText(report, now) {
    var lines = ['تقرير حالة التطبيق — ' + report.generatedAt, report.url, ''];
    summarizeReport(report, now).forEach(function (row) {
      lines.push(row.label + ': ' + row.value.replace(/\n/g, '\n    '));
    });
    var events = report.update && report.update.events ? report.update.events : [];
    if (events.length) {
      lines.push('', 'آخر الأحداث:');
      events.forEach(function (event) {
        lines.push('  ' + new Date(event.at).toISOString() + ' ' + event.message);
      });
    }

    return lines.join('\n');
  }

  function collectReport(win) {
    var nav = win.navigator;
    var loc = win.location;
    var report = {
      generatedAt: new Date().toISOString(),
      url: loc.href,
      origin: loc.origin,
      host: loc.host,
      displayMode: displayModeOf(win),
      online: nav.onLine !== false,
      userAgent: String(nav.userAgent || ''),
      update: null,
      manifest: null,
      registrations: [],
      caches: [],
      crossHostLinks: { count: 0, samples: [] },
      errors: []
    };
    var tasks = [];
    var updater = win.PortalPwaUpdate;
    if (updater && typeof updater.getStatus === 'function') {
      tasks.push(Promise.resolve().then(function () {
        return updater.getStatus();
      }).then(function (status) {
        report.update = status;
      }, function (error) {
        report.errors.push('getStatus: ' + errorText(error));
      }));
    } else {
      report.errors.push('pwa-update.js لم يُحمَّل في هذه الصفحة، فلا يوجد محدِّث تلقائي هنا.');
    }
    if (nav.serviceWorker && typeof nav.serviceWorker.getRegistrations === 'function') {
      tasks.push(nav.serviceWorker.getRegistrations().then(function (list) {
        report.registrations = list.map(function (registration) {
          var worker = registration.active || registration.waiting || registration.installing;

          return { scope: registration.scope, scriptURL: worker ? worker.scriptURL : null };
        });
      }).catch(function (error) {
        report.errors.push('registrations: ' + errorText(error));
      }));
    }
    if (win.caches && typeof win.caches.keys === 'function') {
      tasks.push(win.caches.keys().then(function (names) {
        report.caches = names;
      }).catch(function (error) {
        report.errors.push('caches: ' + errorText(error));
      }));
    }
    if (typeof win.fetch === 'function') {
      tasks.push(win.fetch('/manifest.php', { cache: 'no-store', credentials: 'same-origin' }).then(function (response) {
        return response.json();
      }).then(function (json) {
        report.manifest = pickManifest(json, loc);
      }).catch(function (error) {
        report.errors.push('manifest: ' + errorText(error));
      }));
    }

    return Promise.all(tasks).then(function () {
      try {
        report.crossHostLinks = findCrossHostLinks(win.document.querySelectorAll('a[href]'), loc);
      } catch (error) {
        report.errors.push('links: ' + errorText(error));
      }

      return report;
    });
  }

  function resetCaches(win) {
    var steps = [];
    if (win.navigator.serviceWorker && win.navigator.serviceWorker.getRegistrations) {
      steps.push(win.navigator.serviceWorker.getRegistrations().then(function (list) {
        return Promise.all(list.map(function (registration) {
          return registration.unregister();
        }));
      }).catch(function () {}));
    }
    if (win.caches && win.caches.keys) {
      steps.push(win.caches.keys().then(function (names) {
        return Promise.all(names.map(function (name) {
          return win.caches.delete(name);
        }));
      }).catch(function () {}));
    }

    return Promise.all(steps);
  }

  var STYLE = [
    '#' + PANEL_ID + '{position:fixed;inset:0;z-index:2147483600;background:rgba(17,24,39,.55);display:flex;align-items:flex-end;justify-content:center;font:14px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;direction:rtl}',
    '#' + PANEL_ID + ' .ps-card{background:#fff;color:#111827;width:100%;max-width:36rem;max-height:92vh;overflow:auto;border-radius:16px 16px 0 0;padding:1rem 1rem 1.25rem;box-shadow:0 -8px 30px rgba(0,0,0,.25)}',
    '#' + PANEL_ID + ' h2{margin:0 0 .25rem;font-size:1.05rem}',
    '#' + PANEL_ID + ' .ps-sub{margin:0 0 .75rem;color:#6b7280;font-size:.8rem}',
    '#' + PANEL_ID + ' .ps-badge{display:block;border-radius:12px;padding:.6rem .8rem;margin-bottom:.75rem;font-weight:700}',
    '#' + PANEL_ID + ' .ps-badge small{display:block;font-weight:500;opacity:.9}',
    '#' + PANEL_ID + ' .ps-ok{background:#dcfce7;color:#166534}',
    '#' + PANEL_ID + ' .ps-warn{background:#fef3c7;color:#92400e}',
    '#' + PANEL_ID + ' .ps-bad{background:#fee2e2;color:#991b1b}',
    '#' + PANEL_ID + ' .ps-muted{background:#f3f4f6;color:#374151}',
    '#' + PANEL_ID + ' .ps-row{padding:.4rem 0;border-top:1px solid #e5e7eb}',
    '#' + PANEL_ID + ' .ps-k{display:block;color:#6b7280;font-size:.75rem}',
    '#' + PANEL_ID + ' .ps-v{display:block;white-space:pre-wrap;word-break:break-all}',
    '#' + PANEL_ID + ' .ps-v.ps-ltr{direction:ltr;text-align:left;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.78rem}',
    '#' + PANEL_ID + ' .ps-v.ps-ok,#' + PANEL_ID + ' .ps-v.ps-warn,#' + PANEL_ID + ' .ps-v.ps-bad{background:none}',
    '#' + PANEL_ID + ' .ps-v.ps-ok{color:#15803d}',
    '#' + PANEL_ID + ' .ps-v.ps-warn{color:#b45309}',
    '#' + PANEL_ID + ' .ps-v.ps-bad{color:#b91c1c}',
    '#' + PANEL_ID + ' .ps-hint{display:block;color:#6b7280;font-size:.75rem;margin-top:.15rem}',
    '#' + PANEL_ID + ' .ps-actions{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.9rem}',
    '#' + PANEL_ID + ' button{font:inherit;font-weight:700;border:0;border-radius:10px;padding:.55rem .9rem;background:#111827;color:#fff;cursor:pointer}',
    '#' + PANEL_ID + ' button.ps-secondary{background:#e5e7eb;color:#111827}',
    '#' + PANEL_ID + ' button.ps-danger{background:#b91c1c}',
    '#' + PANEL_ID + ' button[disabled]{opacity:.6}'
  ].join('\n');

  function createPanel(win) {
    var doc = win.document;
    if (doc.getElementById(PANEL_ID)) {
      return null;
    }
    if (!doc.getElementById(PANEL_ID + '-style')) {
      var style = doc.createElement('style');
      style.id = PANEL_ID + '-style';
      style.textContent = STYLE;
      (doc.head || doc.documentElement).appendChild(style);
    }

    function node(tag, className, text) {
      var element = doc.createElement(tag);
      if (className) {
        element.className = className;
      }
      if (text !== undefined) {
        element.textContent = text;
      }

      return element;
    }

    var overlay = node('div');
    overlay.id = PANEL_ID;
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', 'حالة تحديث التطبيق');
    var card = node('div', 'ps-card');
    var title = node('h2', '', 'حالة تحديث التطبيق');
    var sub = node('p', 'ps-sub', 'جارٍ الفحص…');
    var body = node('div');
    var actions = node('div', 'ps-actions');
    var checkButton = node('button', '', 'فحص الآن');
    var copyButton = node('button', 'ps-secondary', 'نسخ التقرير');
    var resetButton = node('button', 'ps-danger', 'إعادة ضبط الذاكرة');
    var closeButton = node('button', 'ps-secondary', 'إغلاق');
    [checkButton, copyButton, resetButton, closeButton].forEach(function (button) {
      button.type = 'button';
      actions.appendChild(button);
    });
    card.appendChild(title);
    card.appendChild(sub);
    card.appendChild(body);
    card.appendChild(actions);
    overlay.appendChild(card);
    (doc.body || doc.documentElement).appendChild(overlay);

    var lastReport = null;
    var resetArmed = false;
    var timers = [];

    function close() {
      timers.forEach(function (timer) {
        win.clearTimeout(timer);
      });
      doc.removeEventListener('keydown', onKey, true);
      if (overlay.parentNode) {
        overlay.parentNode.removeChild(overlay);
      }
    }

    function onKey(event) {
      if (event.key === 'Escape') {
        close();
      }
    }

    function render(report) {
      lastReport = report;
      while (body.firstChild) {
        body.removeChild(body.firstChild);
      }
      var rows = summarizeReport(report, Date.now());
      rows.forEach(function (row, index) {
        if (index === 0) {
          var badge = node('div', 'ps-badge ps-' + (row.tone || 'muted'), row.value);
          if (row.hint) {
            badge.appendChild(node('small', '', row.hint));
          }
          body.appendChild(badge);

          return;
        }
        var wrap = node('div', 'ps-row');
        wrap.appendChild(node('span', 'ps-k', row.label));
        wrap.appendChild(node('span', 'ps-v' + (row.ltr ? ' ps-ltr' : '') + (row.tone ? ' ps-' + row.tone : ''), row.value));
        if (row.hint) {
          wrap.appendChild(node('span', 'ps-hint', row.hint));
        }
        body.appendChild(wrap);
      });
      var events = report.update && report.update.events ? report.update.events : [];
      if (events.length) {
        var details = node('details', 'ps-row');
        details.appendChild(node('summary', 'ps-k', 'آخر الأحداث (' + events.length + ')'));
        details.appendChild(node('span', 'ps-v ps-ltr', events.map(function (event) {
          return new Date(event.at).toISOString().slice(11, 19) + ' ' + event.message;
        }).join('\n')));
        body.appendChild(details);
      }
      sub.textContent = report.url + ' — ' + report.generatedAt.slice(11, 19) + ' UTC';
    }

    function refresh() {
      sub.textContent = 'جارٍ الفحص…';
      checkButton.disabled = true;

      return collectReport(win).then(function (report) {
        render(report);
      }).then(function () {
        checkButton.disabled = false;
      }, function (error) {
        sub.textContent = 'تعذّر إنشاء التقرير: ' + errorText(error);
        checkButton.disabled = false;
      });
    }

    function checkNow() {
      var updater = win.PortalPwaUpdate;
      checkButton.disabled = true;
      sub.textContent = 'جارٍ الفحص…';
      var started = updater && typeof updater.check === 'function' ? Promise.resolve().then(function () {
        return updater.check();
      }).catch(function () {}) : Promise.resolve();

      return started.then(function () {
        return new Promise(function (resolve) {
          timers.push(win.setTimeout(resolve, 1500));
        });
      }).then(refresh);
    }

    function copyReport() {
      var text = lastReport ? reportToText(lastReport, Date.now()) : '';
      var done = function (ok) {
        copyButton.textContent = ok ? 'تم النسخ' : 'تعذّر النسخ';
        timers.push(win.setTimeout(function () {
          copyButton.textContent = 'نسخ التقرير';
        }, 2000));
      };
      if (win.navigator.clipboard && typeof win.navigator.clipboard.writeText === 'function') {
        win.navigator.clipboard.writeText(text).then(function () {
          done(true);
        }, function () {
          done(false);
        });

        return;
      }
      try {
        var area = doc.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', 'readonly');
        area.style.cssText = 'position:fixed;top:-1000px';
        doc.body.appendChild(area);
        area.select();
        var ok = doc.execCommand('copy');
        doc.body.removeChild(area);
        done(!!ok);
      } catch (error) {
        done(false);
      }
    }

    function reset() {
      if (!resetArmed) {
        resetArmed = true;
        resetButton.textContent = 'اضغط مرة أخرى للتأكيد';
        timers.push(win.setTimeout(function () {
          resetArmed = false;
          resetButton.textContent = 'إعادة ضبط الذاكرة';
        }, 4000));

        return;
      }
      resetButton.disabled = true;
      sub.textContent = 'جارٍ مسح الذاكرة المؤقتة (تسجيل الدخول لا يتأثر)…';
      resetCaches(win).then(function () {
        win.location.reload();
      });
    }

    checkButton.addEventListener('click', checkNow);
    copyButton.addEventListener('click', copyReport);
    resetButton.addEventListener('click', reset);
    closeButton.addEventListener('click', close);
    overlay.addEventListener('click', function (event) {
      if (event.target === overlay) {
        close();
      }
    });
    doc.addEventListener('keydown', onKey, true);
    try {
      closeButton.focus();
    } catch (error) {
      /* focus is cosmetic */
    }
    refresh();

    return { close: close, refresh: refresh, element: overlay };
  }

  function boot(win) {
    if (win.__portalPwaStatusBooted) {
      return win.PortalPwaStatus;
    }
    win.__portalPwaStatusBooted = true;
    var counter = createTapCounter();
    var api = {
      open: function () {
        return createPanel(win);
      },
      collect: function () {
        return collectReport(win);
      }
    };
    win.PortalPwaStatus = api;

    win.document.addEventListener('click', function (event) {
      var target = event.target && event.target.closest ? event.target.closest(TRIGGER_SELECTOR) : null;
      if (!target) {
        return;
      }
      if (counter.tap()) {
        api.open();
      }
    }, true);

    try {
      var params = new URLSearchParams(win.location.search);
      if (params.get(QUERY_FLAG) === '1') {
        var show = function () {
          api.open();
        };
        if (win.document.readyState === 'loading') {
          win.document.addEventListener('DOMContentLoaded', show);
        } else {
          show();
        }
      }
    } catch (error) {
      /* the query flag is a convenience, never required */
    }

    return api;
  }

  return {
    createTapCounter: createTapCounter,
    verdictInfo: verdictInfo,
    displayModeOf: displayModeOf,
    findCrossHostLinks: findCrossHostLinks,
    pickManifest: pickManifest,
    staleCacheNames: staleCacheNames,
    summarizeReport: summarizeReport,
    reportToText: reportToText,
    relativeAge: relativeAge,
    boot: boot,
    constants: {
      TAPS_REQUIRED: TAPS_REQUIRED,
      TAP_GAP_MS: TAP_GAP_MS,
      TRIGGER_SELECTOR: TRIGGER_SELECTOR,
      PANEL_ID: PANEL_ID,
      QUERY_FLAG: QUERY_FLAG
    }
  };
});
