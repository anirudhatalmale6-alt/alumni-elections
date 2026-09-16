# Alumni Elections

A self-contained platform for running alumni elections: email-only accounts,
candidate profiles with photos, one tamper-evident vote per alumnus inside a
fixed voting window, and results that are published on a schedule you choose.

Plain PHP 8 and SQLite. No Composer, no build step, no external services — it
runs on ordinary shared hosting as well as on a VPS.

---

## What it does

**Voter**
- Registers with an email address only. No social logins, no third-party identity provider.
- Confirms the address through an emailed link before the account can do anything.
- Reads candidate profiles: photo, headline, and a full manifesto page per candidate.
- Casts one ballot per election, with one choice per position, inside the voting window.
- Gets a receipt code proving the ballot was accepted.
- Sees results when the election's rules say they become public.

**Admin**
- Creates elections with a start time, an end time and a timezone.
- Adds positions and candidates, and uploads candidate photos.
- Manages the alumni roll (who is allowed to register) and individual accounts.
- Watches live turnout while voting is open.
- Publishes or re-hides results.
- Cannot vote — see below.

**Auditor** (read-only oversight)
- Sees turnout and the full audit trail at any time.
- Cannot create or change anything, and cannot vote.

The three roles are separated in the database and enforced on every request, not
merely hidden in the interface.

---

## How the integrity claims actually work

Nothing here relies on a promise in the UI. Each item is enforced where it
cannot be bypassed, and each has a test that proves it.

**One vote per alumnus.** `vote_receipts` carries
`UNIQUE (election_id, position_id, user_id)`. A second ballot — from a refresh, a
double click, two browser tabs, or a direct `INSERT` with a SQL client — is
rejected by the database itself. The whole submission runs in one transaction,
so a rejected ballot leaves nothing behind.

`tests/concurrency.php` proves this under a real race: it launches N separate PHP
processes that all submit a ballot for the same voter at the same instant.
Exactly one is accepted every round; the rest get a clean refusal.

**The ballot cannot be traced to a voter.** `ballots` has no `user_id` column at
all. The record of *who voted* (`vote_receipts`) and the record of *what was
voted* (`ballots`) are separate tables with no join between them. No
administrator can produce that link, because it is not stored anywhere. The
turnout page says so on its face.

**Tampering with the count is detectable.** Every ballot is hashed together with
the hash of the previous ballot in that election, forming a chain. Editing,
inserting or deleting a ballot row directly in the database breaks every hash
from that point on. The results page and the turnout page both verify the chain
and report the first bad row; `php bin/console.php verify-chain <id>` does the
same from the command line.

This is a tamper-*evident* record, and that is the honest word for it. It proves
the stored ballots have not been altered since they were cast. It is not a
blockchain and does not pretend to be.

**The audit trail cannot be rewritten.** `audit_log` has `BEFORE UPDATE` and
`BEFORE DELETE` triggers that abort. An administrator cannot quietly edit history
even with direct database access. Ballot entries in the trail record that a vote
was accepted and its receipt code — never the voter or the choice.

**Administrators cannot vote.** Only `voter` accounts can cast a ballot, checked
in `cast_ballot()` itself and not only in the interface. This keeps the people
running the election out of its result.

**The ballot paper locks.** The moment the first ballot is recorded, positions
and candidates can no longer be added or removed, and a candidate with votes
cannot be deleted. Changing the paper underneath people who have already voted
would make the result impossible to defend. While an election is open but nobody
has voted yet, the admin screen warns clearly that any alumnus could vote at that
moment and that the paper locks as soon as one does.

**The voting window is the server's clock.** Never the browser's. A ballot posted
before the open time or after the close time is refused.

---

## Install

Requirements: PHP 8.0 or newer with `pdo_sqlite`, `gd` and `mbstring`. That is
the default on essentially every host.

```bash
# 1. Put the files on the server. The web root must point at public/,
#    so that src/ and data/ are never reachable over HTTP.

# 2. Make the two writable directories writable by the web server user.
chmod -R 775 data public/uploads

# 3. Create the first administrator.
php bin/console.php create-admin you@example.org 'a-strong-password' 'Your Name'

# 4. Load the alumni roll (or do it from the admin screen later).
php bin/console.php roll-add alum1@example.org alum2@example.org
```

The database file is created automatically on first use, and migrations run by
themselves.

### Configuration

Copy the settings you want to change into `data/config.local.php`, returning an
array. That file overrides `src/config.php` and is not overwritten by updates.

```php
<?php
return [
    'site_name'    => 'Old Boys & Girls Association',
    'base_url'     => 'https://elections.example.org',   // no trailing slash
    'mail_driver'  => 'mail',        // 'log' while testing — see below
    'mail_from'    => 'no-reply@example.org',
    'registration' => 'roll',        // 'roll' | 'approval' | 'open'
];
```

**`base_url` matters more than it looks.** It is what confirmation and
password-reset emails put in their links. Leave it empty and the site works the
address out from each request, so a fresh install sends working links without any
setup — but set it explicitly on the live site, because a derived value comes
from the `Host` header the visitor sent, and an emailed link is the last place you
want that. The admin dashboard shows a warning until you set it.

`registration` decides who may hold an account:

| value      | who can register                                            |
|------------|-------------------------------------------------------------|
| `roll`     | only addresses the admin has put on the alumni roll (default) |
| `approval` | anyone may sign up, but an admin must approve before they vote |
| `open`     | anyone with a working email address                          |

### Mail

The default driver is `log`: messages are written to `data/mail.log` and nothing
is sent. Keep it there while you are testing, so no half-finished invitation ever
reaches a real alumnus. Switch `mail_driver` to `mail` only on the live site.

### Web server

`public/.htaccess` is included for Apache and LiteSpeed: it routes everything
through `public/index.php` and refuses to serve the database or the mail log.

On nginx, point the root at `public/` and use:

```nginx
location / { try_files $uri $uri/ /index.php?$query_string; }
```

For local development, PHP's own server does not read `.htaccess`, so use the
supplied router:

```bash
php -S 127.0.0.1:8400 -t public bin/dev_server.php
```

---

## Trying it out

```bash
php bin/console.php seed-demo     # sample election, candidates and ballots
php bin/demo_photos.php           # placeholder candidate portraits
```

Demo accounts (password shown; change or delete them before going live):

| account               | password     | role                            |
|-----------------------|--------------|---------------------------------|
| `admin@alumni.test`   | `admin12345`   | admin                         |
| `auditor@alumni.test` | `auditor12345` | auditor, read-only            |
| `voter1@alumni.test`  | `voter12345`   | voter, has already voted      |
| `voter6@alumni.test`  | `voter12345`   | voter, ballot still unused    |

The demo portraits are abstract initials on a coloured field — placeholders, not
pictures of real or invented people. Replace them with real candidate photos.

---

## Tests

219 assertions across four suites, all passing.

```bash
php tests/run.php            # 113 assertions against a real SQLite database
php tests/concurrency.php 16 # 23 assertions: 16 processes racing to vote as one voter

# Browser suites (need Playwright with Chromium, and the site running)
php -S 127.0.0.1:8400 -t public bin/dev_server.php &
BASE_URL=http://127.0.0.1:8400 python3 tests/browser/site_flows.py   # 68 assertions
BASE_URL=http://127.0.0.1:8400 python3 tests/browser/admin_flows.py  # 15 assertions
```

The browser suites provision the accounts they need, so they can be run over and
over against the same database. `site_flows.py` registers a fresh alumnus through
the real signup path each run, pulls the confirmation link out of `data/mail.log`
and clicks it, then votes. `admin_flows.py` builds an election from nothing —
including uploading a real candidate photo through the form and checking it is
served back — and then votes in it.

`tests/run.php` runs against a throwaway database file, not a mock, so the
unique constraints and triggers that carry the integrity rules are the things
actually being exercised. It covers registration in all three modes, email
confirmation, sign-in and lockout, password reset, the voting window, the
one-vote rule, ballot forgery attempts, abstentions, counting and ties, the hash
chain (including detecting a tampered and a deleted row), audit-log
immutability, results visibility for every mode and role, photo upload rejection
and path traversal.

---

## Layout

```
public/          web root — index.php, CSS, uploaded photos
  index.php      single entry point; routing, CSRF and auth checks live here
src/
  config.php     settings, overridable from data/config.local.php
  db.php         PDO/SQLite bootstrap, schema migrations, query helpers
  helpers.php    escaping, CSRF, flash messages, view rendering, timezones
  auth.php       registration, sign-in, roles, password reset
  elections.php  the domain: windows, ballots, counting, the hash chain
  audit.php      append-only trail
  mail.php       log / mail transports
  controllers/   site, account, voting, admin
views/           templates
bin/             console.php, seed_demo.php, demo_photos.php, dev_server.php
tests/           run.php, concurrency.php
data/            SQLite database, mail log — must NOT be web reachable
```

## Operational notes

- **Back up `data/elections.sqlite` before and after every election.** It is the
  entire record. Copy the `-wal` file with it, or run
  `sqlite3 data/elections.sqlite ".backup backup.sqlite"` for a consistent copy.
- Keep `data/` outside the web root, which it already is as long as the web root
  points at `public/`.
- Serve the site over HTTPS. Session cookies are marked `secure` automatically
  when the request arrives over HTTPS.
- Passwords are stored with PHP's `password_hash()` and re-hashed on sign-in when
  the default algorithm changes.
