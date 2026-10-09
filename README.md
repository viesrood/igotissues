# I got issues

A Craft CMS 5 plugin that turns what people run into on a site into GitHub issues.

- **A report button** in the control panel and on the front end. Someone describes a
  problem or a wish in their own words.
- **Claude drafts the issue** in the shape of your repository's own issue forms and
  points out an open issue that looks the same. A person checks it and sends it.
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
IGOTISSUES_ANTHROPIC_KEY=
```

**GitHub token.** Create a [fine-grained personal access token](https://github.com/settings/personal-access-tokens)
with access to only the one repository, and these repository permissions:

| Permission | Access |
|---|---|
| Issues | Read and write |
| Contents | Read |
| Metadata | Read |

Contents is only used to read `.github/ISSUE_TEMPLATE/*.yml`.

**Anthropic API key.** Optional. Without it, reporting works the same with an empty form
instead of a draft.

After saving, use **Test the saved connection** under Settings → Plugins → I got issues.
It tells you when the token is refused and which labels from your issue forms do not
exist in the repository.

## Configuration

Every setting is in the control panel and can be overridden per environment in
`config/igotissues.php`; see [`examples/config/igotissues.php`](examples/config/igotissues.php).

| Setting | Default | |
|---|---|---|
| `githubToken`, `githubRepo`, `anthropicApiKey` | env references | See above. |
| `githubApiUrl` | `https://api.github.com/` | For GitHub Enterprise Server: `https://<host>/api/v3/`. |
| `model` | `claude-opus-5-5` | The Claude model that writes the draft. |
| `draftInstructions` | `''` | How issues are written on this project, e.g. "Write in Dutch." |
| `reportPermission` | `accessCp` | The permission a user needs to report. Admins always may. |
| `position` | `bottom-right` | Or `bottom-left`. |
| `autoInject` | `false` | Add the button to front-end pages through `endBody()`. |
| `includeReporter` | `true` | Put the reporter's name in the issue. |
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

**Your issue forms decide what a report looks like.** The plugin reads
`.github/ISSUE_TEMPLATE/*.yml` from the repository and uses their fields, title prefix and
labels, so an issue made here reads like one filed through GitHub's own form. `textarea`,
`input` and `dropdown` fields are used; `markdown` and `checkboxes` are skipped. A
repository without forms gets a built-in bug form and feature form.

Labels are never created. A label that does not exist in the repository is left off.

## Errors

With `captureErrors` on, every unexpected error (an uncaught exception, or an HTTP error
of 500 and up) is recorded under **Reports**. Two errors are the same error when they
are thrown from the same place through the same code of your own; the message is not
compared, because it usually carries an id. The same error raises a counter.

Nothing goes to GitHub by itself. Open the error, choose **Make an issue**, and you get
the same window with a draft about that error. Choose **Ignore** for one that needs no
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

- **What leaves the site.** A report goes to GitHub and, for a draft, to the Claude API.
  Before that, the plugin removes the values of environment variables whose name says
  they hold a secret, bearer tokens, credentials in URLs, token-like query parameters and
  the installation path. Stack traces carry no arguments. Request bodies are never sent.
  This is a safety net, not a guarantee: a reporter can still type a password into the
  form, and an error message can carry data the scrubber does not know is private. Use a
  private repository if that matters to you.
- **Prompt injection.** The reporter's text and error messages are given to Claude as
  data, and its reply is validated against the repository's real forms, labels and open
  issues. A person confirms every issue.
- **Who can report.** Only logged-in users with the configured permission. Every endpoint
  requires a POST with a CSRF token and is rate-limited per user.
- Credentials are only used server-side.

## License

MIT. See [LICENSE.md](LICENSE.md).
