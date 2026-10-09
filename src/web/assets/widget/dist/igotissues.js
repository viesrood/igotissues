/**
 * I got issues: the report button and its window.
 *
 * No dependencies and no build step. Reads its configuration from the
 * #igotissues element the plugin renders, builds the button and the dialog,
 * and talks to the plugin's own endpoints. Works the same in the Craft control
 * panel and on any front-end template.
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
  var jsErrors = [];
  var state = { step: 'compose', text: '', error: null, forms: [], draft: null, returnFocus: null };
  var requestId = 0;

  // Collected from the moment the script runs, so a report can say what broke.
  window.addEventListener('error', function (event) {
    remember((event.message || 'Error') + (event.filename ? ' (' + event.filename + ':' + event.lineno + ')' : ''));
  });
  window.addEventListener('unhandledrejection', function (event) {
    var reason = event.reason;
    remember('Unhandled rejection: ' + (reason && reason.message ? reason.message : String(reason)));
  });

  function remember(message) {
    jsErrors.push(String(message).slice(0, 300));

    if (jsErrors.length > 5) {
      jsErrors.shift();
    }
  }

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
      jsErrors: jsErrors.slice()
    };
  }

  // The button

  var launcher = el('button', {
    type: 'button',
    class: 'igi-launcher igi-' + (config.position || 'bottom-right'),
    'aria-haspopup': 'dialog',
    onclick: function () {
      open(config.recentError || null);
    }
  }, [
    el('span', { class: 'igi-launcher-mark', 'aria-hidden': 'true', text: '!' }),
    el('span', { class: 'igi-launcher-label', text: t.button })
  ]);

  if (config.recentError) {
    launcher.classList.add('igi-has-error');
    launcher.querySelector('.igi-launcher-label').textContent = t.recentError;
  }

  // The dialog

  var body = el('div', { class: 'igi-body' });
  var message = el('p', { class: 'igi-message', role: 'alert', tabindex: '-1', hidden: 'hidden' });
  var tabNew = el('button', { type: 'button', class: 'igi-tab', text: t.newReport, onclick: function () { show('compose'); } });
  var tabMine = el('button', { type: 'button', class: 'igi-tab', text: t.mine, onclick: function () { show('mine'); } });

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
    var focusable = dialog.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled])');

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

  function open(error) {
    state.returnFocus = (error && error.returnFocus) || document.activeElement || launcher;
    state.error = error && error.id ? { id: error.id, title: error.title || '' } : null;
    dialog.hidden = false;
    backdrop.hidden = false;
    show('compose');
  }

  function close() {
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

    if (step === 'compose') {
      compose();
    } else if (step === 'review') {
      review();
    } else if (step === 'mine') {
      mine();
    }
  }

  function busy(text) {
    body.textContent = '';
    body.appendChild(el('p', { class: 'igi-busy', role: 'status', text: text }));
  }

  // Step 1: say it in your own words

  function compose() {
    var textarea = el('textarea', { id: 'igi-text', class: 'igi-input', rows: '6', maxlength: '5000' });
    textarea.value = state.text;

    var form = el('form', {
      class: 'igi-form',
      onsubmit: function (event) {
        event.preventDefault();
        state.text = textarea.value.trim();

        if (!state.error && state.text.length < 3) {
          textarea.focus();

          return;
        }

        draft();
      }
    }, [
      state.error ? el('p', { class: 'igi-about' }, [
        el('strong', { text: t.aboutError + ' ' }),
        el('span', { text: state.error.title })
      ]) : null,
      el('label', { class: 'igi-label', for: 'igi-text', text: t.prompt }),
      textarea,
      el('p', { class: 'igi-help', text: config.drafts ? t.promptHelp : t.promptHelpPlain }),
      el('div', { class: 'igi-actions' }, [
        el('button', { type: 'submit', class: 'igi-primary', text: t.continue })
      ])
    ]);

    body.appendChild(form);
    textarea.focus();
  }

  function draft() {
    var mine = ++requestId;

    busy(config.drafts ? t.drafting : t.loading);

    post(config.urls.draft, { text: state.text, page: page(), reportId: state.error ? state.error.id : null })
      .then(function (data) {
        if (mine !== requestId) {
          return;
        }

        state.forms = data.forms || [];
        state.draft = data.draft || { form: '', title: '', fields: {}, labels: [], duplicates: [] };
        show('review');
      })
      .catch(function (error) {
        if (mine !== requestId) {
          return;
        }

        show('compose');
        fail(error.message);
      });
  }

  // Step 2: check what will be sent

  function currentForm() {
    var found = null;

    state.forms.forEach(function (form) {
      if (form.id === state.draft.form) {
        found = form;
      }
    });

    return found || state.forms[0] || null;
  }

  function review() {
    var form = currentForm();

    if (!form) {
      show('compose');
      fail(t.failed);

      return;
    }

    state.draft.form = form.id;

    var kind = el('select', {
      id: 'igi-kind',
      class: 'igi-input',
      onchange: function () {
        collect();
        carryOver(form, kind.value);
        state.draft.form = kind.value;
        show('review');
      }
    }, state.forms.map(function (option) {
      var node = el('option', { value: option.id, text: option.name });
      node.selected = option.id === form.id;

      return node;
    }));

    var title = el('input', { id: 'igi-issue-title', class: 'igi-input', type: 'text', maxlength: '200', required: 'required' });
    title.value = state.draft.title || '';

    var fields = form.fields.map(function (field) {
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

    var duplicates = (state.draft.duplicates || []).length ? el('fieldset', { class: 'igi-duplicates' }, [
      el('legend', { class: 'igi-label', text: t.duplicates })
    ].concat(state.draft.duplicates.map(function (duplicate) {
      return duplicateChoice(duplicate.number, fill(t.sameAs, { number: duplicate.number }), duplicate);
    })).concat([duplicateChoice(0, t.newIssue, null)])) : null;

    var submit = el('button', { type: 'submit', class: 'igi-primary', text: t.send });

    var node = el('form', {
      class: 'igi-form',
      onsubmit: function (event) {
        event.preventDefault();
        collect();
        send(submit);
      }
    }, [
      el('p', { class: 'igi-help', text: t.review }),
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
      duplicates,
      el('div', { class: 'igi-actions' }, [
        el('button', { type: 'button', class: 'igi-secondary', text: t.back, onclick: function () { collect(); show('compose'); } }),
        submit
      ])
    ]));

    body.appendChild(node);
    title.focus();
  }

  function duplicateChoice(number, label, duplicate) {
    var id = 'igi-duplicate-' + number;
    var input = el('input', { type: 'radio', name: 'igi-duplicate', id: id, value: String(number) });
    input.checked = (state.draft.duplicateOf || 0) === number;

    return el('div', { class: 'igi-choice' }, [
      input,
      el('label', { for: id }, [
        document.createTextNode(label),
        duplicate ? el('span', { class: 'igi-help' }, [
          document.createTextNode(' '),
          el('a', { href: duplicate.url, target: '_blank', rel: 'noopener', text: duplicate.title }),
          document.createTextNode(duplicate.reason ? ' · ' + duplicate.reason : '')
        ]) : null
      ])
    ]);
  }

  // Read the review form back into the draft, so nothing typed is lost when
  // the reporter goes back or picks another kind of report.
  function collect() {
    var title = document.getElementById('igi-issue-title');
    var chosen = body.querySelector('input[name="igi-duplicate"]:checked');

    if (title) {
      state.draft.title = title.value.trim();
    }

    state.draft.fields = state.draft.fields || {};

    Array.prototype.forEach.call(body.querySelectorAll('[data-field]'), function (input) {
      state.draft.fields[input.getAttribute('data-field')] = input.value;
    });

    state.draft.duplicateOf = chosen ? parseInt(chosen.value, 10) : 0;
  }

  // Another form has other fields. Move what was written into its first
  // free-text field rather than dropping it.
  function carryOver(from, toId) {
    var to = null;

    state.forms.forEach(function (form) {
      if (form.id === toId) {
        to = form;
      }
    });

    if (!to || to.id === from.id) {
      return;
    }

    // The old form's own labels go with it; the new form brings its own.
    state.draft.labels = (state.draft.labels || []).filter(function (label) {
      return (from.labels || []).indexOf(label) === -1;
    });

    var known = {};
    var text = [];

    to.fields.forEach(function (field) {
      known[field.id] = true;
    });

    from.fields.forEach(function (field) {
      var value = (state.draft.fields[field.id] || '').trim();

      if (value && !known[field.id] && field.type !== 'dropdown') {
        text.push(value);
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
    var mine = ++requestId;

    submit.disabled = true;
    submit.textContent = t.sending;
    message.hidden = true;

    post(config.urls.send, {
      form: state.draft.form,
      title: state.draft.title,
      fields: state.draft.fields,
      labels: state.draft.labels || [],
      duplicateOf: state.draft.duplicateOf || 0,
      reportId: state.error ? state.error.id : null,
      page: page()
    })
      .then(function (data) {
        if (mine !== requestId) {
          return;
        }

        var wasDuplicate = (state.draft.duplicateOf || 0) > 0;

        state.text = '';
        state.draft = null;
        state.error = null;
        config.recentError = null;
        launcher.classList.remove('igi-has-error');
        launcher.querySelector('.igi-launcher-label').textContent = t.button;
        done(data, wasDuplicate);
      })
      .catch(function (error) {
        if (mine !== requestId) {
          return;
        }

        submit.disabled = false;
        submit.textContent = t.send;
        fail(error.message);
      });
  }

  function done(data, wasDuplicate) {
    state.step = 'done';
    body.textContent = '';

    var again = el('button', { type: 'button', class: 'igi-secondary', text: t.another, onclick: function () { show('compose'); } });

    body.appendChild(el('div', { class: 'igi-done', role: 'status' }, [
      el('p', { class: 'igi-thanks', text: fill(wasDuplicate ? t.added : t.sent, { number: data.number }) }),
      el('div', { class: 'igi-actions' }, [
        again,
        data.url ? el('a', { class: 'igi-primary', href: data.url, target: '_blank', rel: 'noopener', text: t.view }) : null
      ])
    ]));

    again.focus();
  }

  // What I reported before

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
  window.IGotIssues = { open: open, close: close };
})();
