# PN Chat tests

Functional tests against a real, throw-away WordPress (MariaDB/MySQL) and
Chromium. They change PN Chat's data on that site: never run them on the live
site.

## Run

1. A test WordPress with PN Chat active (floating button), served over HTTP,
   e.g. `php -S 127.0.0.1:8898 -t /path/to/wordpress`. Its `siteurl` and
   `home` must be that address. Copy the plugin into `wp-content/plugins/`
   (a symlink gives wrong asset URLs).
2. From `tests/`: `npm ci` (playwright-core for the browser checks).
3. Run:

   ```sh
   WP_LOAD=/path/to/wordpress/wp-load.php PN_BASE=http://127.0.0.1:8898 tests/pn-chat/run.sh
   ```

   Without `PN_BASE` the Chromium checks are skipped. Exit status 0 = all
   passed. Chromium is looked for at `/opt/pw-browsers/chromium-1194/...`;
   set `CHROMIUM_PATH` otherwise.

Claude is never called: its replies are faked through `pre_http_request`, and
no mail is sent (`pre_wp_mail`).

## What is covered

| File | What |
|---|---|
| `test-text-alone.php` | Greek/Greeklish folding, without WordPress |
| `test-counter-1800.php` | Atomic counters; **30 parallel PHP processes** on a limit of 5 (exactly 5 pass; the 1.7.0 way is shown for comparison); AI daily limit, give-back only for calls that never left the site; usage migration (also through activation); clean-up of ended limits |
| `test-privacy-brain-1800.php` | GDPR export/erase of 700 questions; **brain backup and restore**: replace with an invalid file, a failing insert (InnoDB and MyISAM, same number of entries, ids kept), a refused COMMIT, an empty snapshot; questions backup with AI drafts |
| `test-rest-ai-1800.php` | REST: e-mail states, repeated e-mails, database failures, feedback, texts with `%`, page address; AI: personal details removed, foreign links and sources, refusal guard, daily limit on errors and time-outs, rate limit |
| `test-ai-guard-1830.php` | The AI medical guard on hand-labelled questions (`fixtures/ai-medical-questions.php`, 80, used for tuning; `fixtures/ai-medical-holdout.php`, 40, written afterwards); the model's own `medical_advice` flag |
| `test-search-admin-1800.php` | Site search prefilter gives the full scan's results; background re-index; entry topics; assets; admin messages on refused saves, deletes, synonyms and settings |
| `test-zz-uninstall-1800.php` | Uninstall with and without "keep data": reading queue and uploaded PDFs always go, proposals with the data (runs last, re-creates the tables) |
| `test-learn-1900.php` | «Προτάσεις AI»: a page, a web page (menus and scripts left out), a PDF address and an uploaded PDF (sent as a document block, file deleted after); nothing reaches the chat before approval; keyword hygiene, similarity, stray questions; duplicates and rejected titles; changed-only; monthly limit, uses given back when Claude was never asked; lock, AI off, stop, weekly; approve / merge / reject / bulk handlers; short keywords match only exactly |
| `test-smalltalk-1900.php` | Small talk: praise, ok, bye and complaints detected (questions that start with praise stay questions); reply in the topic of the conversation, not logged; complaints logged with the e-mail form; texts from Ρυθμίσεις; a trained entry wins |
| `browser-1800.mjs` | Chromium, desktop and phone: e-mail form and feedback survive another page; Greek network errors; suggestions right under the welcome, groups open when they fit; full-screen focus; small talk brings the suggestions back |

To check the guard on the site's own questions: download the brain with the
questions (Εγκέφαλος → Λήψη, «Μαζί και τα ερωτήματα»), label them like the
fixtures, and add them as a new fixture file.
