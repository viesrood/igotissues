# I got issues

A Craft CMS 5 plugin that turns what people run into on a site into GitHub issues.

- **A report button** in the control panel and on the front end. Someone fills in your
  repository's own issue form, without leaving the site.
- **A technical report goes along**, the way a problem report does on a Mac: what the
  server knows, the server error the reporter just ran into, and what the browser saw
  before the report. The reporter can read it before sending.
- **Errors are recorded** once per distinct error, with a count. They wait in the control
  panel until someone makes an issue of them.
- **No webhook.** The plugin fetches issue states itself, so it also works on a site
  GitHub cannot reach.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Credentials](#credentials)
- [Configuration](#configuration)
- [The report button](#the-report-button)
- [The technical report](#the-technical-report)
- [Errors](#errors)
- [Keeping issue states up to date](#keeping-issue-states-up-to-date)
- [Events](#events)
- [Security](#security)
- [License](#license)

## Requirements

Craft CMS 5.0 or later and PHP 8.2 or later.

## Installation

```bash
composer require viesrood/igotissues
php craft plugin/install igotissues
```

## Credentials

Put these in `.env`:

```
IGOTISSUES_GITHUB_TOKEN=
IGOTISSUES_GITHUB_REPO=owner/name
```

**GitHub token.** Create a [fine-grained personal access token](https://github.com/settings/personal-access-tokens)
with access to only the one repository, and these repository permissions:

| Permission | Access |
|---|---|
| Issues | Read and write |
| Contents | Read |
| Metadata | Read |

Contents is only used to read `.github/ISSUE_TEMPLATE/*.yml`.

After saving, use **Test the saved connection** under Settings → Plugins → I got issues.
It tells you when the token is refused and which labels from your issue forms do not
exist in the repository.

## Configuration

Every setting is in the control panel and can be overridden per environment in
`config/igotissues.php`; see [`examples/config/igotissues.php`](examples/config/igotissues.php).

| Setting | Default | |
|---|---|---|
| `githubToken`, `githubRepo` | env references | See above. |
| `githubApiUrl` | `https://api.github.com/` | For GitHub Enterprise Server: `https://<host>/api/v3/`. |
| `reportPermission` | `accessCp` | The permission a user needs to report. Admins always may. |
| `position` | `bottom-right` | Or `bottom-left`. |
| `autoInject` | `false` | Add the button to front-end pages through `endBody()`. |
| `includeReporter` | `true` | Put the reporter's name in the issue. |
| `autoFields` | `[]` | Form fields the site fills in itself. See below. |
| `skipFields` | `[]` | Form fields that are left out. See below. |
| `captureErrors` | `true` | Record unexpected errors. |
| `captureInDevMode` | `false` | Also while devMode is on. |
| `captureConsole` | `false` | Also from console commands. |
| `reopenOnRecurrence` | `true` | Reopen a closed issue when its error comes back. |
| `releaseFile` | `''` | A file whose first line names the release; falls back to the git commit. |
| `timeout` | `15` | Seconds to wait for GitHub. |

## The report button

In the control panel the button is always there for users who may report.

On the front end, add it to your layout, just before `</body>`:

```twig
{{ igotissues_widget() }}
```

It outputs nothing for a visitor who is not logged in or may not report, so it is safe
on every page. If your layout calls `{% do view.endBody() %}`, you can turn on
`autoInject` instead.

The widget brings its own stylesheet and script and depends on nothing else on the page.
A page can open the window itself with `window.IGotIssues.open()`.

**The button keeps clear of what sits at the bottom of the screen.** In the control panel it
moves up above Craft's notifications while they show. On a front end, mark anything you fix
to the bottom edge, such as a selection bar or a cookie notice, and the button does the same:

```html
<div class="fixed inset-x-0 bottom-0" data-igotissues-clear>…</div>
```


**Your issue forms decide what a report looks like.** The plugin reads
`.github/ISSUE_TEMPLATE/*.yml` from the repository and uses their fields, title prefix and
labels, so an issue made here reads like one filed through GitHub's own form. `textarea`,
`input` and `dropdown` fields are used; `markdown` and `checkboxes` are skipped. A
repository without forms gets a built-in bug form and feature form.

**Fields the site can answer itself.** An issue form often asks for things the site
already knows, or for a log the technical report already carries. In
`config/igotissues.php`:

```php
// Field id => the label of a line in the technical report.
'autoFields' => ['page-url' => 'URL', 'environment' => 'Environment'],

// Field ids that are not shown and not put in the issue.
'skipFields' => ['logs'],
```

The reporter does not see either kind. A dropdown that is filled in this way only takes a
value that is one of its options; add a line with the right wording through
`Reports::EVENT_DEFINE_CONTEXT` when the built-in one does not match.

Labels are never created. A label that does not exist in the repository is left off.

## The technical report

Every report carries a technical report, folded away under what the reporter wrote. It
is put together for them; they only describe what they saw.

| Part | What is in it |
|---|---|
| The page | URL, title, site, viewport, browser, language |
| The installation | Environment, release, Craft, PHP, database, every plugin with its version |
| The server error | When the reporter ran into an error in the last ten minutes: its class, message, where it was thrown and the stack trace |
| The browser | The last thirty things that happened before the report: script errors, failed requests, `console.error` messages, and which links and buttons were clicked |

The window has a **What is sent along** section that shows exactly this, before anything
is sent. The server error can be left out with a checkbox.

What is never collected: anything typed into a field, request bodies, query strings of
requests, cookies, and the arguments in a stack trace.

## Errors

With `captureErrors` on, every unexpected error (an uncaught exception, or an HTTP error
of 500 and up) is recorded under **Reports**. Two errors are the same error when they
are thrown from the same place through the same code of your own; the message is not
compared, because it usually carries an id. The same error raises a counter.

Nothing goes to GitHub by itself. Open the error, choose **Make an issue**, and you get
the same window with that error attached. Choose **Ignore** for one that needs no
issue.

A user who ran into an error in the last ten minutes sees the button turn red and ask
what they were doing.

## Keeping issue states up to date

The Reports page and the "My reports" tab fetch the state of the issues from GitHub, at
most once every five minutes. To also reopen issues whose error came back while nobody
was looking, run this from cron:

```bash
php craft igotissues/sync
```

## Events

```php
use viesrood\igotissues\events\CaptureEvent;
use viesrood\igotissues\events\DefineContextEvent;
use viesrood\igotissues\services\Capture;
use viesrood\igotissues\services\Reports;
use yii\base\Event;

// Add your own lines to the context of every report.
Event::on(Reports::class, Reports::EVENT_DEFINE_CONTEXT, function (DefineContextEvent $event) {
    $event->context['Tenant'] = MyModule::currentTenant();
});

// Keep an exception that is expected on your site out of the Reports list.
Event::on(Capture::class, Capture::EVENT_BEFORE_CAPTURE, function (CaptureEvent $event) {
    if ($event->exception instanceof MyExpectedException) {
        $event->isValid = false;
    }
});
```

## Security

- **What leaves the site.** A report goes to GitHub, and nowhere else. Before that, the
  plugin removes the values of environment variables whose name says they hold a secret,
  bearer tokens, credentials in URLs, token-like query parameters and the installation
  path. This is a safety net, not a guarantee: a reporter can still type a password into
  the form, and an error message can carry data the scrubber does not know is private.
  Use a private repository if that matters to you.
- **The browser log is untrusted.** It is typed by a browser, so the server only accepts
  known kinds of lines, bounds their length and scrubs them like any other text.
- **Who can report.** Only logged-in users with the configured permission. Every endpoint
  requires a POST with a CSRF token and is rate-limited per user.
- Credentials are only used server-side.

## License

MIT. See [LICENSE.md](LICENSE.md).
