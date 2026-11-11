# Release Notes for I got issues

## 3.0.0-beta.2 - 2026-11-11

### Fixed

- **`igotissues_widget()` no longer disappears after a queue job on Craft 6.**
  Craft 6 binds Twig as a scoped service, and Laravel's queue worker flushes scoped instances
  after every job. The Twig extension was registered once, on the first instance, so any template
  rendered after a job in the same process failed with "Unknown function". The extension is now
  registered on every Twig instance that is resolved.

## 3.0.0-beta.1 - 2026-11-11

### Changed

- **Requires Craft CMS 6.0.0-beta.1 or later and PHP 8.5.** The plugin runs as a legacy plugin
  through `craftcms/yii2-adapter`, which it now requires. It is not yet rewritten for Craft 6's
  Laravel base classes.

### Fixed

- **A redirect from a template is no longer recorded as an error on Craft 6.** `{% redirect %}`
  and `{% exit %}` throw Laravel's `HttpResponseException` there, and HTTP errors are Symfony
  exceptions with a status code. Both are now judged by their status, like Yii's `HttpException`.

## 2.2.2 - 2026-11-10

### Fixed

- **A 404 from a template is no longer recorded as an error.** `{% exit 404 %}` throws its
  `NotFoundHttpException` inside a Twig runtime error, and the recorder only looked at the
  outer exception, so every such page became a report. It now looks inside, as Craft's own
  error handler does. A Twig error that wraps a real fault is still recorded.

## 2.2.1 - 2026-11-10

### Changed

- **Craft's `sensitiveKeywords` count as secret names.** Besides `key`, `token`, `secret` and
  the like, an env var whose name contains one of the words in the `security` component's
  `sensitiveKeywords` is now removed from reports too. A site that names a secret in another
  language (`..._SLEUTEL`) adds the word there once, and both Craft's logs and the reports
  leave it out.

## 2.2.0 - 2026-10-31

### Changed

- **The button keeps clear of notices.** When a control panel notification or an element a
  site marks with `data-igotissues-clear` overlaps the button, the button moves up until it
  sits just above it, and drops back when it is gone. Before, the button covered the
  bottom of Craft's notifications (bottom-left by default, full width on a narrow screen)
  and anything a site fixed to the bottom of the screen. Nothing is hidden, and Craft's
  notifications are not touched.

## 2.1.0 - 2026-10-09

### Added

- `autoFields`: form fields the site fills in itself from the technical report, such as
  the page URL or the environment. The reporter no longer sees them.
- `skipFields`: form fields that are neither shown nor put in the issue, for a "paste your
  log here" field the technical report makes redundant.

## 2.0.1 - 2026-10-09

### Changed

- A new plugin icon.

## 2.0.0 - 2026-10-09

### Removed

- **Drafts by Claude.** The plugin no longer talks to any AI service. The settings
  `anthropicApiKey`, `model` and `draftInstructions` are gone; remove them from
  `config/igotissues.php`, where an unknown setting is an error.
- Pointing a report at an open issue it duplicates. That depended on the draft.

### Changed

- The window opens straight on the repository's issue form.
- **Every report carries a technical report**, folded away under the issue: the page and
  the installation (now with the database and every plugin's version), the server error
  the reporter just ran into, and what the browser saw before the report (script errors,
  failed requests, `console.error` messages and the last clicks). The window shows it
  under "What is sent along" before anything is sent.
- A server error the reporter ran into in the last ten minutes is attached by itself,
  with a checkbox to leave it out.

## 1.0.0 - 2026-10-09

### Added

- A **report button** in the control panel and on the front end, for users with a
  configurable permission. The reporter describes a problem or a wish in their own
  words; the page, the browser and recent JavaScript errors are added for them.
- **Drafts by Claude.** With an Anthropic API key, Claude turns the report into an issue
  in the shape of the repository's own issue forms and points out an open issue that
  looks the same. The reporter checks and changes the draft before anything is sent.
  Without a key the same window opens with an empty form.
- **GitHub issues.** A confirmed report becomes an issue, or a comment on the issue it
  duplicates. Issue forms, labels and open issues are read from the repository.
- **Error capture.** Unexpected errors are recorded once per distinct error, with a
  count, and wait under Reports until someone makes an issue of them. A user who just
  ran into an error is asked what they were doing.
- A **Reports** control panel section with the state of every issue, a "My reports" tab
  in the window, and `igotissues/sync` for cron. No webhook is needed, so the site does
  not have to be reachable from GitHub.
- A closed issue is commented on and reopened when its error comes back.
- `Reports::EVENT_DEFINE_CONTEXT` and `Capture::EVENT_BEFORE_CAPTURE` for site-specific
  context and for errors that should not be recorded.
- Dutch and English translations.

### Security

- Reports are scrubbed before they leave the site: values of secret-looking environment
  variables, bearer tokens, credentials in URLs, token-like query parameters and the
  installation path. Stack traces carry no arguments and request bodies are never sent.
- What the reporter typed and what an error says is passed to Claude as data. The reply
  is validated against the repository's real forms, labels and open issues.
- Credentials are only used server-side. Nothing GitHub or the Claude API says in an
  error reaches the browser.
