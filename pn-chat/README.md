# PN Chat

WordPress plugin: PharmacyNeeds' own chat assistant. No AI and no outside
service; it answers only from what the admins teach it (PN Chat →
Εκπαίδευση). User documentation (Greek): `pn-chat/readme.txt`.

```
pn-chat/            the plugin (this folder is what goes into the zip)
tests/              tests, never shipped
build-zip.sh        builds build/pn-chat-<version>.zip
```

## How it answers

`includes/class-pnchat-text.php` folds Greek and Greeklish to one phonetic
form (accents, ι/η/υ/ει/οι, ο/ω, double letters, θ/th/8...).
`includes/class-pnchat-matcher.php` scores every trained phrasing against the
question (idf-weighted F1 with prefix matching for Greek endings, synonyms,
keywords), splits questions on «και / ;» to answer several topics at once,
and lets refusals win close calls.

## Tests

```
php tests/matcher-test.php                       # matcher only, no WordPress
DB_HOST=localhost tests/setup-wp.sh              # WordPress + MariaDB at :8898
php tests/wp-test.php /tmp/pnchat-wp/wp-load.php # brain backup/restore, REST, privacy
cd tests && npm ci && node e2e.mjs               # phone widget + admin flow (Chromium)
```

`CHROMIUM=/path/to/chrome` picks the browser; `PNCHAT_SHOTS=dir` saves screenshots.
CI runs all of them in the `pn-chat` job of `.github/workflows/ci.yml`.
