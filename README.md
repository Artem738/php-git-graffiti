# PHP Git Graffiti

Draw text on your GitHub contribution graph, one day at a time.

A small PHP CLI program for ordinary Linux hosting. It reads a picture from
`DRAW-TEXT.txt` and creates today's commits when cron runs. PHP and Git are the
only runtime dependencies: no Composer, framework, database or web server.

This is contribution art. Generated commits are empty and explicitly labelled
`graffiti:`. They do not represent software development activity.

## Requirements

- PHP **8.1+ CLI** with `proc_open` enabled.
- Git, SSH and outgoing access to GitHub.
- A cron task and a writable directory outside the site's public web root.
- A GitHub account and a **separate empty repository** for the drawing.

Development checks have been run on PHP 8.3. Older supported PHP versions have
not yet been tested. SSH host-key verification remains enabled.

## Draw your message

Open **[DRAW-TEXT.txt](DRAW-TEXT.txt)** and draw directly with `#` characters.
The supplied picture says `HELLO-GIT`. You can replace it with any word or picture.
For example, this is `HI`:

```text
.......
#.#.###
#.#..#.
###..#.
#.#..#.
#.#.###
.......
```

- `#` is a lit pixel: create commits on that day.
- A dot `.` or a space is background: no new commits.
- Use exactly **seven rows**, Sunday at the top, Saturday at the bottom.
- Each column is one week. The widest row sets the width, up to 52 columns.
- Shorter rows are padded with background on the right. Empty rows are allowed.

There are no built-in fonts or letter codes to edit in PHP. The supplied
`HELLO-GIT` drawing is 35 weeks wide.

## Preview

```bash
php graffiti.php preview
php graffiti.php plan > plan.csv
php graffiti.php run --dry-run --date=2026-10-26
```

Without `config.php`, these commands use `config.example.php`. They do not run Git
or create files (the `>` redirection above creates `plan.csv` if you use it).

## Configure

```bash
cp config.example.php config.php
```

Edit `config.php`:

- `drawing_file`: the plain text picture, default `DRAW-TEXT.txt`. Relative paths
  are resolved beside the config file.
- `start_date`: the Sunday on which the first column begins, in `YYYY-MM-DD` form.
  Change the example date to your chosen Sunday.
- `timezone`: the timezone used to choose today's pixel, for example
  `Europe/Kyiv`. Commit timestamps include the corresponding UTC offset.
- `commits_per_pixel`: an integer from 1 to 100. This is a count, not a guaranteed
  green shade: your other contributions affect the graph's colors and background.
- `remote`: the SSH clone URL of your **canvas repository**.
- `author_name` / `author_email`: your Git identity. Use the exact GitHub-provided
  noreply email from [GitHub email settings](https://github.com/settings/emails)
  if you do not want to publish your personal address.
- `canvas_dir`: a dedicated working copy, default `var/canvas`. Relative paths
  are resolved beside the config file. Never use a real project's repository.

`config.php`, `var/` and logs are ignored by this project's Git repository.
Keep SSH private keys outside the project, normally in `~/.ssh/`.

The source repository (`php-git-graffiti`) and canvas repository are separate.
Create the canvas on GitHub **without a README, license or .gitignore**.

## Connect the hosting account to GitHub

Set up a dedicated SSH key for the canvas, add its public key to the canvas
repository's **Settings → Deploy keys**, and enable **Allow write access**.
For multiple keys on the same hosting account, select the canvas key with an SSH
host alias in `~/.ssh/config` and use that alias in `remote`.

Follow GitHub's [deploy-key instructions](https://docs.github.com/en/authentication/connecting-to-github-with-ssh/managing-deploy-keys).
Verify GitHub's host key and the SSH connection manually before enabling cron.
The program disables password prompts so an unattended run cannot wait for input.
An encrypted SSH key needs a suitable noninteractive agent; a terminal-only
`ssh-agent` session is usually not available to cron.

## Initialize and run

```bash
php graffiti.php preview
php graffiti.php init
php graffiti.php run --dry-run
php graffiti.php run
```

`init` checks remote access and refuses a remote with existing branches. It creates
the local canvas on `main` and saves the drawing's identity inside `.git/`.
It creates **no commits and performs no push**. Remote write permission is first
tested when a real push is needed.

`run` creates the configured number of empty commits for today's lit pixel and
pushes to `main`. On a background day it creates no commits, but it still retries
any pending push. If there are no commits yet, the empty GitHub repository stays
empty until the first lit day. Make sure `main` is the repository's default branch
after the first push.

Drawing settings are fixed by `init`, so a cron run cannot silently switch to a
different pattern. To change the drawing's pixels, dates, intensity, timezone or author email,
choose a new empty canvas repository and a new empty `canvas_dir`, then run `init`.
Keep existing canvases if you want their contributions to remain visible.

## Cron

First check the executable paths on your hosting account:

```bash
command -v php git ssh
```

Example **user crontab** (replace paths with the results above):

```cron
PATH=/usr/local/bin:/usr/bin:/bin
15 * * * * /absolute/path/to/php /home/your-user/php-git-graffiti/graffiti.php run >> /home/your-user/php-git-graffiti/var/cron.log 2>&1
```

Initialize the canvas before adding cron; this also creates `var/` for the log.
In a hosting control panel, choose “every hour, at minute 15” and enter only the
command following the five schedule fields. Include the directories containing
Git and SSH in cron's `PATH`.

Hourly execution gives failed runs another opportunity during the same day. The
program reads already-created pixel commits, so repeated runs do not duplicate
them. A nonblocking file lock prevents overlapping jobs. The hour is interpreted
by the hosting scheduler; `timezone` in the config determines which calendar day
is painted. Add log rotation through your host if this runs long term.

## Behavior and limits

- Real runs always use the current day. `--date` is allowed only with `--dry-run`.
  Missed days are **not backfilled**. No future or past commits are generated.
- A failed push leaves commits locally. A later run retries them, including on a
  background day, without creating them again.
- A run interrupted between commits resumes from the missing pixel indices.
- A run crossing local midnight stops creating commits for the previous day.
- Keep the canvas exclusive to this program. It refuses uncommitted files, a
  different branch/origin, or remote history that is not an ancestor of its local
  history. It never pulls, rebases, resets or force-pushes.
- If another writer updates the remote during execution, the normal push can be
  rejected; local commits are preserved for inspection.
- Git operations have a 60-second timeout. A failure exits with code 1 and writes
  its error to stderr. Successful jobs write a short dated result to stdout.
- The graph includes contributions from your other repositories. Creating this
  tool does not give control over the exact appearance of the whole graph.
- GitHub requires an author email associated with your account and qualifying
  commits in the repository's default branch. The canvas must be a standalone
  repository, not a fork. Graph updates can take up to 24 hours.

See GitHub's [contribution rules](https://docs.github.com/en/account-and-profile/reference/profile-contributions-reference)
and [missing-contribution troubleshooting](https://docs.github.com/en/account-and-profile/how-tos/contribution-settings/troubleshooting-missing-contributions).

## Commands

```text
php graffiti.php preview
php graffiti.php plan
php graffiti.php init
php graffiti.php run --dry-run
php graffiti.php run --dry-run --date=2026-10-26
php graffiti.php run
php graffiti.php --version
php graffiti.php help
```

All commands accept `--config=/absolute/path/config.php` after the command name.

## Development checks

```bash
php -l graffiti.php
php tests/check.php
```

Checks use temporary local repositories and never contact GitHub. They cover
calendar alignment, DST, malformed inputs, read-only preview, duplicate prevention,
partial runs, failed pushes, background-day retries and conflicting remote changes.

## Inspiration and license

Inspired by [gitfiti](https://github.com/gelstudios/gitfiti) and existing PHP
projects such as [Contributions-Pen](https://github.com/Kurisu-A/Contributions-Pen).
This implementation focuses on daily cron execution and small hosting setups.

MIT license. See [LICENSE](LICENSE).
