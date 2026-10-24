# PHP Git Graffiti

Draw on your GitHub contribution graph, one day at a time.

A small PHP CLI script for cron. Edit a text picture, fill in your GitHub settings,
and run `php run.php`. No Composer, framework, database or web server.

## Files

- **`DRAW-TEXT.txt`** — your picture, drawn with `#` characters.
- **`config.php`** — your local GitHub settings, copied from `config.example.php`.
- **`run.php`** — the file to run from cron.
- **`STATUS.txt`** — a readable progress report, maintained by the script.
- **`var/run.log`** — a timestamped history of runs, skips and errors.
- `var/state.json` — saved start date and progress; do not delete or edit it.
- `var/canvas/` — the separate Git working copy containing the drawing commits.

Local settings, status, logs and state are ignored by Git. The script keeps its
source repository separate from the canvas repository.

## Draw

Edit [DRAW-TEXT.txt](DRAW-TEXT.txt). The supplied drawing says `HELLO-GIT`.
Use exactly seven rows: Sunday at the top, Saturday at the bottom. Each column
represents one week. `#` means a pixel; a dot or space means background. Shorter
rows are padded on the right. The maximum width is 52 weeks.

For example, `HI`:

```text
.......
#.#.###
#.#..#.
###..#.
#.#..#.
#.#.###
.......
```

## Configure and start

Requires PHP **8.1+ CLI** with `proc_open`, Git, SSH and outgoing access to GitHub.
Development was checked on PHP 8.3. Put the project outside the public web root.

1. Create a **separate empty GitHub repository** for the canvas, without a README,
   license or .gitignore. This source repository is not the canvas.
2. Copy `config.example.php` to `config.php`. Fill in the canvas SSH URL, your Git
   name and an email associated with your GitHub account. You can use your exact
   [GitHub noreply address](https://github.com/settings/emails).
3. Configure a dedicated SSH key with write access to the canvas repository.
   Follow GitHub's [deploy-key instructions](https://docs.github.com/en/authentication/connecting-to-github-with-ssh/managing-deploy-keys).
   Verify the host key and noninteractive connection manually before enabling cron.
4. Preview without creating files, making commits or accessing GitHub:

   ```bash
   php run.php --dry-run
   ```

5. Start the schedule and enable cron:

   ```bash
   php run.php
   ```

There is no separate initialization command. The script prepares the canvas
when its first commit is needed. Settings and drawing are frozen by the first
real run; changing them later stops execution with an error instead of silently
moving pixels. Keep `var/` across updates and use `git pull` to update the source.

## Sunday and progress

- The first real run saves its timestamp and a Sunday start date. If today is
  Sunday, it starts today; otherwise it waits for the next Sunday.
- That date is saved **before any Git operation**. Repeated cron runs do not move
  the start date or advance the drawing.
- The start must be confirmed by an actual Sunday run, even if Sunday's row is
  blank. If cron misses the first Sunday entirely, the script waits for the next
  Sunday and logs the reschedule. It never begins midway through the first week.
- Once started, the calendar decides the position: Sunday is row 1, Monday row 2,
  and the next Sunday begins the next column. The default timezone is **GMT+0**,
  without daylight-saving time; days are counted by calendar dates.
- `STATUS.txt` shows the first run, scheduled and confirmed start, full elapsed
  drawing days, current day/row/column, last run, last successfully processed day
  and last result. Its text is in Russian.
- Missing calendar days are reported in `var/run.log`; no backdated commits are
  made and later pixels keep their calendar dates.
- Repeated runs do not duplicate commits. Pending pushes are retried, including
  on background days and after the drawing period ends.
- A lock prevents simultaneous jobs. Git failures preserve local commits and
  produce a nonzero exit code. The script never force-pushes or rewrites history.

Do not delete `var/state.json` to restart an existing drawing. It holds the
calendar anchor. A new independent drawing needs a separate project directory
and a fresh empty canvas repository.

## Cron

Check the paths with `command -v php git ssh`. Use the absolute PHP path and
include Git and SSH in cron's PATH. Example user crontab:

```cron
PATH=/usr/local/bin:/usr/bin:/bin
15 * * * * /absolute/path/to/php /home/your-user/php-git-graffiti/run.php
```

In a hosting panel, select every hour at minute 15 and enter only the command.
The script writes its own `var/run.log`; shell redirection is optional. Hourly
execution gives failed jobs another chance the same day. The hosting scheduler
controls the execution time; `timezone` in the config selects the drawing day.

## About the graph

Generated commits are empty and labelled `graffiti:`: this is contribution art.
Your other contributions affect the background and green shades. The canvas
must be a standalone repository, and `main` must be its default branch after the
first push. Commits need an email linked to your account. GitHub may take up to
24 hours to update the graph. See the [contribution rules](https://docs.github.com/en/account-and-profile/reference/profile-contributions-reference).

Inspired by [gitfiti](https://github.com/gelstudios/gitfiti) and
[Contributions-Pen](https://github.com/Kurisu-A/Contributions-Pen). MIT license.
