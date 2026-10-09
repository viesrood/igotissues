/**
 * I got issues: the report button and its window.
 *
 * No dependencies and no build step. Reads its configuration from the
 * #igotissues element the plugin renders, builds the button and the dialog,
 * and talks to the plugin's own endpoints. Works the same in the Craft control
 * panel and on any front-end template.
 *
 * From the moment it loads it also keeps a short log of what goes wrong on the
 * page (script errors, failed requests, console errors) and of the last
 * clicks. That log goes along with a report as its technical details, and the
 * reporter can read it before sending.
 */
(function () {
  'use strict';

  var root = document.getElementById('igotissues');

  if (!root || root.getAttribute('data-ready')) {
    return;
  }

  var config;

  try {
    config = JSON.parse(root.getAttribute('data-config') || '{}');
  } catch (e) {
    return;
  }

  if (!config.urls) {
    return;
  }

  root.setAttribute('data-ready', '1');

  var t = config.t || {};
  var MAX_EVENTS = 30;
  var events = [];
  var state = { step: 'form', loaded: false, error: null, includeError: true, forms: [], preview: '', draft: null, returnFocus: null };
  var requestId = 0;

  // ---------------------------------------------------------------- the log

  function remember(type, text) {
    var now = new Date();

    events.push({
      time: [now.getHours(), now.getMinutes(), now.getSeconds()].map(function (n) {
        return (n < 10 ? '0' : '') + n;
      }).join(':'),
      type: type,
      text: String(text).replace(/\s+/g, ' ').slice(0, 300)
    });

    if (events.length > MAX_EVENTS) {
      events.shift();
    }
  }

  // Our own requests are not part of what happened on the page.
  function isOwn(url) {
    return String(url).indexOf('igotissues/reports/') !== -1;
  }

  // The path is enough to recognise a request; the query can carry anything.
  function shortUrl(url) {
    try {
      var parsed = new URL(String(url), window.location.href);

      return (parsed.origin === window.location.origin ? '' : parsed.origin) + parsed.pathname;
    } catch (e) {
      return String(url).split('?')[0];
    }
  }

  remember('page', window.location.pathname);

  window.addEventListener('error', function (event) {
    if (event.message) {
      remember('error', event.message + (event.filename ? ' (' + shortUrl(event.filename) + ':' + event.lineno + ')' : ''));
    } else if (event.target && event.target !== window && (event.target.src || event.target.href)) {
      // A script, stylesheet or image that failed to load.
      remember('request', 'failed to load ' + shortUrl(event.target.src || event.target.href));
    }
  }, true);

  window.addEventListener('unhandledrejection', function (event) {
    var reason = event.reason;

    remember('error', 'Unhandled rejection: ' + (reason && reason.message ? reason.message : String(reason)));
  });

  if (window.console && typeof window.console.error === 'function') {
    var consoleError = window.console.error;

    window.console.error = function () {
      try {
        remember('console', Array.prototype.map.call(arguments, function (argument) {
          return argument && argument.message ? argument.message : String(argument);
        }).join(' '));
      } catch (e) {
        // Logging must never break the page it is watching.
      }

      return consoleError.apply(this, arguments);
    };
  }

  if (typeof window.fetch === 'function') {
    var nativeFetch = window.fetch;

    window.fetch = function (input, init) {
      var url = typeof input === 'string' ? input : (input && input.url) || '';
      var method = ((init && init.method) || (input && input.method) || 'GET').toUpperCase();
      var promise = nativeFetch.apply(this, arguments);

      if (!isOwn(url)) {
        promise.then(function (response) {
          if (response.status >= 400) {
            remember('request', method + ' ' + shortUrl(url) + ' returned ' + response.status);
          }
        }, function () {
          remember('request', method + ' ' + shortUrl(url) + ' failed');
        });
      }

      return promise;
    };
  }

  if (window.XMLHttpRequest) {
    var open = window.XMLHttpRequest.prototype.open;

    window.XMLHttpRequest.prototype.open = function (method, url) {
      var request = this;

      if (!isOwn(url)) {
        request.addEventListener('loadend', function () {
          if (request.status === 0 || request.status >= 400) {
            remember('request', String(method).toUpperCase() + ' ' + shortUrl(url) + (request.status ? ' returned ' + request.status : ' failed'));
          }
        });
      }

      return open.apply(this, arguments);
    };
  }

  // What was clicked, never what was typed.
  document.addEventListener('click', function (event) {
    var target = event.target && event.target.closest ? event.target.closest('a, button, [role="button"], input[type="submit"], summary') : null;

    if (!target || root.contains(target)) {
      return;
    }

    var label = (target.getAttribute('aria-label') || target.textContent || target.value || '').replace(/\s+/g, ' ').trim().slice(0, 60);

    remember('click', target.tagName.toLowerCase() + (target.id ? '#' + target.id : '') + (label ? ' "' + label + '"' : ''));
  }, true);

  // ------------------------------------------------------------- helpers

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    var key;

    for (key in attrs || {}) {
      if (!Object.prototype.hasOwnProperty.call(attrs, key) || attrs[key] === null || attrs[key] === undefined) {
        continue;
      }

      if (key === 'text') {
        node.textContent = attrs[key];
      } else if (key.slice(0, 2) === 'on') {
        node.addEventListener(key.slice(2), attrs[key]);
      } else {
        node.setAttribute(key, attrs[key]);
      }
    }

    (children || []).forEach(function (child) {
      if (child) {
        node.appendChild(child);
      }
    });

    return node;
  }

  function fill(template, values) {
    return String(template || '').replace(/\{(\w+)\}/g, function (match, name) {
      return values[name] !== undefined ? values[name] : match;
    });
  }

  function post(url, data) {
    var headers = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

    if (config.csrf) {
      headers['X-CSRF-Token'] = config.csrf.value;
    }

    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: headers,
      body: JSON.stringify(data || {})
    }).then(function (response) {
      return response.json().catch(function () {
        return {};
      }).then(function (body) {
        if (!response.ok || !body.success) {
          throw new Error(body.error || t.failed);
        }

        return body;
      });
    });
  }

  function page() {
    return {
      url: window.location.href,
      title: document.title,
      viewport: window.innerWidth + ' x ' + window.innerHeight,
      language: navigator.language || '',
      events: events.slice()
    };
  }

  // ---------------------------------------------------------- the button

  var launcher = el('button', {
    type: 'button',
    class: 'igi-launcher igi-' + (config.position || 'bottom-right'),
    'aria-haspopup': 'dialog',
    onclick: function () {
      openWindow(null);
    }
  }, [
    el('span', { class: 'igi-launcher-mark', 'aria-hidden': 'true', text: '!' }),
    el('span', { class: 'igi-launcher-label', text: t.button })
  ]);

  function alertLauncher(on) {
    launcher.classList.toggle('igi-has-error', on);
    launcher.querySelector('.igi-launcher-label').textContent = on ? t.recentError : t.button;
  }

  alertLauncher(!!config.recentError);

  // ---------------------------------------------------------- the dialog

  var body = el('div', { class: 'igi-body' });
  var message = el('p', { class: 'igi-message', role: 'alert', tabindex: '-1', hidden: 'hidden' });
  var tabNew = el('button', { type: 'button', class: 'igi-tab', text: t.newReport, onclick: function () { collect(); show('form'); } });
  var tabMine = el('button', { type: 'button', class: 'igi-tab', text: t.mine, onclick: function () { collect(); show('mine'); } });

  var dialog = el('div', {
    class: 'igi-dialog igi-' + (config.position || 'bottom-right'),
    role: 'dialog',
    'aria-modal': 'true',
    'aria-labelledby': 'igi-title',
    hidden: 'hidden'
  }, [
    el('div', { class: 'igi-head' }, [
      el('h2', { id: 'igi-title', class: 'igi-title', text: t.title }),
      el('button', { type: 'button', class: 'igi-close', 'aria-label': t.close, text: '×', onclick: close })
    ]),
    el('div', { class: 'igi-tabs' }, [tabNew, tabMine]),
    message,
    body
  ]);

  var backdrop = el('div', { class: 'igi-backdrop', hidden: 'hidden', onclick: close });

  root.appendChild(launcher);
  root.appendChild(backdrop);
  root.appendChild(dialog);

  dialog.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      event.stopPropagation();
      close();

      return;
    }

    if (event.key !== 'Tab') {
      return;
    }

    // Keep focus inside the dialog while it is open.
    var focusable = dialog.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), summary');

    if (!focusable.length) {
      return;
    }

    var first = focusable[0];
    var last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  // `about` is an error to report on: { id, title }. Without it the server
  // looks for one this user ran into moments ago.
  function openWindow(about) {
    state.returnFocus = (about && about.returnFocus) || document.activeElement || launcher;
    dialog.hidden = false;
    backdrop.hidden = false;

    if (about && about.id) {
      state.loaded = false;
      state.draft = null;
    }

    if (state.loaded) {
      show('form');
    } else {
      load(about && about.id ? about.id : null);
    }
  }

  function close() {
    collect();
    requestId++;
    dialog.hidden = true;
    backdrop.hidden = true;

    if (state.returnFocus && state.returnFocus.focus) {
      state.returnFocus.focus();
    }
  }

  function fail(text) {
    message.textContent = text || t.failed;
    message.hidden = false;
    message.focus();
  }

  function show(step) {
    state.step = step;
    message.hidden = true;
    message.textContent = '';
    body.textContent = '';
    tabNew.setAttribute('aria-pressed', step === 'mine' ? 'false' : 'true');
    tabMine.setAttribute('aria-pressed', step === 'mine' ? 'true' : 'false');

    if (step === 'mine') {
      mine();
    } else if (state.loaded) {
      form();
    } else {
      load(null);
    }
  }

  function busy(text) {
    body.textContent = '';
    body.appendChild(el('p', { class: 'igi-busy', role: 'status', text: text }));
  }

  // The forms of the repository, and what would be sent along from this page.
  function load(errorId) {
    var current = ++requestId;

    state.step = 'form';
    busy(t.loading);

    post(config.urls.forms, { page: page(), reportId: errorId })
      .then(function (data) {
        if (current !== requestId) {
          return;
        }

        state.forms = data.forms || [];
        state.preview = data.preview || '';
        state.error = data.error || null;
        state.includeError = true;
        state.loaded = true;
        state.draft = state.draft || { form: state.forms.length ? state.forms[0].id : '', title: '', fields: {} };
        show('form');
      })
      .catch(function (error) {
        if (current !== requestId) {
          return;
        }

        body.textContent = '';
        fail(error.message);
      });
  }

  // -------------------------------------------------------------- the form

  function currentForm() {
    var found = null;

    state.forms.forEach(function (candidate) {
      if (candidate.id === state.draft.form) {
        found = candidate;
      }
    });

    return found || state.forms[0] || null;
  }

  function form() {
    var chosen = currentForm();

    if (!chosen) {
      fail(t.failed);

      return;
    }

    state.draft.form = chosen.id;

    var kind = el('select', {
      id: 'igi-kind',
      class: 'igi-input',
      onchange: function () {
        collect();
        carryOver(chosen, kind.value);
        state.draft.form = kind.value;
        show('form');
        document.getElementById('igi-kind').focus();
      }
    }, state.forms.map(function (option) {
      var node = el('option', { value: option.id, text: option.name });
      node.selected = option.id === chosen.id;

      return node;
    }));

    var title = el('input', { id: 'igi-issue-title', class: 'igi-input', type: 'text', maxlength: '200', required: 'required' });
    title.value = state.draft.title || '';

    var fields = chosen.fields.map(function (field) {
      var id = 'igi-field-' + field.id;
      var value = (state.draft.fields || {})[field.id] || '';
      var input;

      if (field.type === 'dropdown') {
        input = el('select', { id: id, class: 'igi-input', 'data-field': field.id }, [el('option', { value: '', text: t.choose })].concat(
          field.options.map(function (option) {
            var node = el('option', { value: option, text: option });
            node.selected = option === value;

            return node;
          })
        ));
      } else if (field.type === 'input') {
        input = el('input', { id: id, class: 'igi-input', type: 'text', 'data-field': field.id });
        input.value = value;
      } else {
        input = el('textarea', { id: id, class: 'igi-input', rows: '3', 'data-field': field.id });
        input.value = value;
      }

      if (field.required) {
        input.required = true;
      }

      if (field.description) {
        input.setAttribute('aria-describedby', id + '-help');
      }

      return el('div', { class: 'igi-field' }, [
        el('label', { class: 'igi-label', for: id }, [
          document.createTextNode(field.label),
          field.required ? el('span', { class: 'igi-required', text: ' (' + t.required + ')' }) : null
        ]),
        field.description ? el('p', { class: 'igi-help', id: id + '-help', text: field.description }) : null,
        input
      ]);
    });

    var include = null;

    if (state.error) {
      var checkbox = el('input', { type: 'checkbox', id: 'igi-include-error' });
      checkbox.checked = state.includeError;

      include = el('div', { class: 'igi-about' }, [
        el('div', { class: 'igi-choice' }, [
          checkbox,
          el('label', { for: 'igi-include-error' }, [
            el('strong', { text: t.includeError }),
            el('span', { class: 'igi-error-title', text: state.error.title })
          ])
        ])
      ]);
    }

    var submit = el('button', { type: 'submit', class: 'igi-primary', text: t.send });

    var node = el('form', {
      class: 'igi-form',
      onsubmit: function (event) {
        event.preventDefault();
        collect();
        send(submit);
      }
    }, [
      el('p', { class: 'igi-help', text: t.intro }),
      include,
      state.forms.length > 1 ? el('div', { class: 'igi-field' }, [
        el('label', { class: 'igi-label', for: 'igi-kind', text: t.kind }),
        kind
      ]) : null,
      el('div', { class: 'igi-field' }, [
        el('label', { class: 'igi-label', for: 'igi-issue-title' }, [
          document.createTextNode(t.issueTitle),
          el('span', { class: 'igi-required', text: ' (' + t.required + ')' })
        ]),
        title
      ])
    ].concat(fields).concat([
      el('details', { class: 'igi-details' }, [
        el('summary', { text: t.whatIsSent }),
        el('p', { class: 'igi-help', text: t.whatIsSentHelp }),
        el('pre', { class: 'igi-preview', tabindex: '0', text: state.preview })
      ]),
      el('div', { class: 'igi-actions' }, [submit])
    ]));

    body.appendChild(node);
    title.focus();
  }

  // Read the form back into the draft, so nothing typed is lost when the
  // window is closed, a tab is switched or another kind of report is picked.
  function collect() {
    var title = document.getElementById('igi-issue-title');
    var include = document.getElementById('igi-include-error');

    if (!state.draft || !title) {
      return;
    }

    state.draft.title = title.value.trim();
    state.draft.fields = state.draft.fields || {};

    Array.prototype.forEach.call(body.querySelectorAll('[data-field]'), function (input) {
      state.draft.fields[input.getAttribute('data-field')] = input.value;
    });

    if (include) {
      state.includeError = include.checked;
    }
  }

  // Another form has other fields. Move what was written into its first
  // free-text field rather than dropping it.
  function carryOver(from, toId) {
    var to = null;

    state.forms.forEach(function (candidate) {
      if (candidate.id === toId) {
        to = candidate;
      }
    });

    if (!to || to.id === from.id) {
      return;
    }

    var known = {};
    var text = [];

    to.fields.forEach(function (field) {
      known[field.id] = true;
    });

    from.fields.forEach(function (field) {
      var value = (state.draft.fields[field.id] || '').trim();

      if (value && !known[field.id] && field.type !== 'dropdown') {
        text.push(value);
        state.draft.fields[field.id] = '';
      }
    });

    to.fields.some(function (field) {
      if (field.type !== 'textarea' || (state.draft.fields[field.id] || '').trim()) {
        return false;
      }

      state.draft.fields[field.id] = text.join('\n\n');

      return true;
    });
  }

  function send(submit) {
    var current = ++requestId;
    var chosen = currentForm();
    var fields = {};

    // Only the fields of the form that is sent.
    chosen.fields.forEach(function (field) {
      fields[field.id] = state.draft.fields[field.id] || '';
    });

    submit.disabled = true;
    submit.textContent = t.sending;
    message.hidden = true;

    post(config.urls.send, {
      form: chosen.id,
      title: state.draft.title,
      fields: fields,
      reportId: state.error && state.includeError ? state.error.id : null,
      page: page()
    })
      .then(function (data) {
        if (current !== requestId) {
          return;
        }

        state.draft = null;
        state.loaded = false;
        state.error = null;
        config.recentError = null;
        alertLauncher(false);
        done(data);
      })
      .catch(function (error) {
        if (current !== requestId) {
          return;
        }

        submit.disabled = false;
        submit.textContent = t.send;
        fail(error.message);
      });
  }

  function done(data) {
    state.step = 'done';
    body.textContent = '';

    var again = el('button', { type: 'button', class: 'igi-secondary', text: t.another, onclick: function () { show('form'); } });

    body.appendChild(el('div', { class: 'igi-done', role: 'status' }, [
      el('p', { class: 'igi-thanks', text: fill(t.sent, { number: data.number }) }),
      el('div', { class: 'igi-actions' }, [
        again,
        data.url ? el('a', { class: 'igi-primary', href: data.url, target: '_blank', rel: 'noopener', text: t.view }) : null
      ])
    ]));

    again.focus();
  }

  // ------------------------------------------------- what I reported before

  function mine() {
    var current = ++requestId;

    busy(t.loading);

    post(config.urls.mine, {})
      .then(function (data) {
        if (current !== requestId) {
          return;
        }

        body.textContent = '';

        if (!data.reports || !data.reports.length) {
          body.appendChild(el('p', { class: 'igi-help', text: t.noReports }));

          return;
        }

        body.appendChild(el('ul', { class: 'igi-list' }, data.reports.map(function (report) {
          return el('li', {}, [
            report.url
              ? el('a', { href: report.url, target: '_blank', rel: 'noopener', text: '#' + report.number + ' ' + report.title })
              : el('span', { text: report.title }),
            el('span', { class: 'igi-state igi-state-' + (report.state === 'closed' ? 'closed' : 'open'), text: report.state === 'closed' ? t.closed : t.open })
          ]);
        })));
      })
      .catch(function (error) {
        if (current !== requestId) {
          return;
        }

        body.textContent = '';
        fail(error.message);
      });
  }

  // For a page that wants to open the window itself, e.g. "Make an issue"
  // on a recorded error: IGotIssues.open({ id: 12, title: '...' }).
  window.IGotIssues = { open: openWindow, close: close };
})();
