# Release Notes for I got issues

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
