# PlanDose — δοκιμές (δεν μπαίνουν στο πακέτο)

Φάκελοι του repository: `plandose/` (το plugin), `job-listings/` (plugin αγγελιών — `npm run test:jobs`), `tests/` (αυτός ο φάκελος), `build/` (zip), `.github/workflows/ci.yml` (CI).
Οι δοκιμές βρίσκουν το plugin μόνες τους στο `../plandose/assets/js`, `../plugin/plandose/assets/js` ή `../assets/js`
(ή όπου δείχνει το `PLANDOSE_JS`). Δεν μπαίνουν ποτέ σε πραγματικό site.

```
cd tests
npm ci                      # Node ≥ 20
npm test                    # jsdom, χωρίς WordPress (χρειάζεται και php για το i18n-keys)
npm run test:visual         # A4 σε πραγματικό Chromium, χωρίς WordPress
npm run test:visual -- --update   # ξαναγράφει τα baselines — ελέγξτε τα PNG πριν τα κρατήσετε
npm run test:wp             # WordPress + MariaDB: χρεώσεις, ρυθμίσεις
npm run test:browser        # WordPress + Chromium: smoke, «Είδος», «Επικόλληση συνταγής»
sh php/run.sh               # δοκιμές PHP μέσα στο WordPress (WP_LOAD=/path/wp-load.php)
```

`php/run.sh` τρέχει τα `php/test-*.php` και μετά, όπως το CI, τους ελέγχους διαχείρισης `php/admin/storage-admin-1270.php "$WP_LOAD"`
και `node --test --test-concurrency=1 php/admin/diagnostics-http-1270.test.js` (από το `tests/`· `PD_WP_PATH` προεπιλογή ο φάκελος του
`WP_LOAD`). `PD_SKIP_ADMIN=1`: χωρίς αυτούς. Έξοδος: 0 όλα πέρασαν, 1 αποτυχία, 2 δεν βρέθηκε WordPress.

Αναμονή σε δοκιμές jsdom: `lib/until.js` (`until(cond, ms, what)`) αντί για σταθερό sleep ή τοπικό αντίγραφο.
Ημερομηνίες: το `npm test` κλειδώνει τη ζώνη ώρας (`TZ`, αλλαγή με `PD_TZ`)· η «σημερινή» ημερομηνία (`isoAhead` του harness και
το `Date` του plugin μέσα στο jsdom) είναι από προεπιλογή η πραγματική. Όπου το αποτέλεσμα εξαρτάται από το ημερολόγιο (μήκος μηνών,
ημέρα 29–31, δίσεκτο έτος), η δοκιμή κλειδώνει το «σήμερα»: `load({ now: '2026-01-05T09:00:00' })` (και `loadLoader`/`loadGuest`)
αντικαθιστά το `Date` του παραθύρου με ένα ρολόι που ξεκινά από εκεί (τοπική ώρα) και προχωρά κανονικά· το `env.isoAhead(n)` μετρά
από αυτή την ημέρα. Χωρίς `now` όλα μένουν όπως πριν. Τα χρονόμετρα (`setTimeout`) τα οδηγεί ξεχωριστά το `lib/fake-clock.js`.

Όρια χρόνου (έλεγχοι για «καταστροφικό backtracking» σε regex): `lib/perf.js` (`linearWithin(run, n, budgetMs)`) — περνά αν η
δουλειά μένει κάτω από το όριο ή, σε φορτωμένο μηχάνημα, αν μεγαλώνει γραμμικά (το πλήρες μέγεθος έναντι του 1/8, μετρημένα
διαδοχικά). Πολύ αργό μηχάνημα: `PD_PERF_FACTOR` (βλ. πίνακα).

## Ρυθμίσεις (μεταβλητές περιβάλλοντος, `lib/env.js`)

| Μεταβλητή | Προεπιλογή | Τι |
|---|---|---|
| `PLANDOSE_JS` | αυτόματα | φάκελος `assets/js` του plugin |
| `PD_BASE` | `http://127.0.0.1:8899` | το δοκιμαστικό WordPress |
| `PD_WP_PATH` | `/home/claude/wpenv` | φάκελος του WordPress (`wp-load.php`, `debug.log`) |
| `PD_WP_CLI` | `$PD_WP_PATH/wp` | wp-cli (δημιουργεί προσωρινούς χρήστες `pdt_*`) |
| `PD_DEBUG_LOG` | `$PD_WP_PATH/debug.log` | |
| `PD_DB_HOST/PORT/USER/PASS/NAME/PREFIX` | `127.0.0.1`/–/`wp`/`wp`/`wptest`/`wp_` | βάση |
| `PD_FREE_USER`/`PD_FREE_PASS` | `pharm1`/`pharmpass` | «Φαρμακείο», Free |
| `PD_PRO_USER`/`PD_PRO_PASS` | `pharmpro`/`pharmpass` | «Φαρμακείο», Pro |
| `PD_CHARGES_USER` | (προσωρινός `pdt_*`) | `login:pass` σταθερού χρήστη για τις χρεώσεις |
| `PD_CHROMIUM` | αυτόματα | εκτελέσιμο Chromium· αλλιώς το νεότερο `chromium-*` στο `PLAYWRIGHT_BROWSERS_PATH`, `/opt/pw-browsers`, `~/.cache/ms-playwright` |
| `PD_VISUAL_TOLERANCE` | `0.002` | ποσοστό διαφορετικών pixel ανά σελίδα |
| `PD_VISUAL_ONLY` | όλα | π.χ. `long-names,mixed` |
| `PD_VISUAL_PIXELS` | `1` | `0`: μόνο οι έλεγχοι διάταξης, χωρίς baselines |
| `PD_PERF_FACTOR` | `1` | πολλαπλασιάζει τα όρια χρόνου του `lib/perf.js` (π.χ. `3` σε πολύ αργό CI) |

## Browser (Node + jsdom): `npm test`

`harness.js`: `load()` φορτώνει τα modules με τη σειρά που τα στέλνει το plugin
(state → api → validation → preview → medicine-form → print-styles → print → [pro-labels] → rx-text → rx-lines → rx-parse → rx-review → rx-import → modal → app),
`loadLoader()` το `plandose-loader.js` (τα modules φορτώνονται από τον ίδιο τον loader), `loadGuest()` το `plandose-guest.js`.
`load({ i18n: 'real' })` φορτώνει τα πραγματικά λεξικά της PHP (`tools/extract-i18n.php`, χωρίς WordPress).

- `i18n-keys.test.js`: κάθε κλειδί που ζητά η JS (`PD.txt('…')`, `t('…')`, πίνακες μονάδων/συχνοτήτων) υπάρχει και στο ελληνικό
  και στο αγγλικό λεξικό της PHP, και τα `%s`/`%d` συμφωνούν σε JS fallback, el, en. Κλειδιά που ζητήθηκαν στο `../i18n-requests/*.json`
  αλλά δεν μπήκαν ακόμη στην PHP αναφέρονται ονομαστικά.
- `loader-guest.test.js`: lazy loading (σειρά, μία φορά, Free χωρίς pro-labels, σφάλμα φόρτωσης) και teaser επισκέπτη.
- `*-1270.test.js`: οι διορθώσεις της 1.27.0· `*-1275.test.js`: της 1.27.5.
- `safety-1261.test.js`, `a11y.test.js`, `day-cards.test.js`, `labels-after-new-patient.test.js`, `dose-parser.test.js`,
  `label-notes-warning.test.js`, `charged-no-sheet.test.js`: παλαιότερες διορθώσεις.
- `wp-integration/print-log-1290.test.js` / `php/test-print-log-1290.php`: το ιστορικό εκτυπώσεων της 1.29.0 (μία γραμμή ανά εκτύπωση, οθόνη, δικαιώματα, GDPR).
- `fixes-1301.test.js` / `php/test-fixes-1301.php` / `wp-integration/request-id-1301.test.js`: οι διορθώσεις της 1.30.1 (περιεκτικότητα «1.000MG» στη φόρμα, μετρητής στην αλλαγή του μήνα, ρυθμός και απόσταση ωρών στη σελίδα QR, υποχρεωτικό `request_id`).
- `fixes-1287.test.js`: οι διορθώσεις της 1.28.7 (περιεκτικότητα «. 25MG» / «0. 25MG» με κενό, NBSP, διπλό διαχωριστικό· μήνυμα QR για το όνομα φαρμακείου).
- `php/test-fixes-1286.php`: οι διορθώσεις της 1.28.6 (σύνδεσμοι σελίδων, τεχνικά σφάλματα μόνο σε διαχειριστές, νεκρές ρυθμίσεις, μεταφορά τιμολογίων με cron από γραμμή εντολών).
- `fixes-1285.test.js` / `php/test-fixes-1285.php`: οι διορθώσεις της 1.28.5 («.25MG» → 0.25MG και υποχρεωτική φόρμα, μνήμη του αναλυτή, ειδοποίηση QR, αδράνεια πλάνου που δεν τυπώθηκε, απεγκατάσταση με παλιά τιμολόγια, `wp plandose check` χωρίς διαχειριστή, έλεγχος αρχείων).
- `fixes-1282.test.js` / `php/test-fixes-1282.php`: οι διορθώσεις της 1.28.2 (διπλό φάρμακο, εβδομαδιαία σε δύο εγγραφές, δισκίο↔κάψουλα,
  3 δεκαδικά mg, UID/bidi ημερολογίου, reset μήνα, make_free CAS, undo/lock ownership, uninstall, stats cache).
- `php/test-unarchived-1283.php`: 1.28.3 — μήνες ιστορικού που δεν αρχειοθετήθηκαν (καταγραφή, ειδοποίηση, Διαγνωστικά, επανάληψη, κουμπιά).
- `rx-import.test.js`: οι 22 ανωνυμοποιημένες συνταγές του `rx-samples/` έναντι του `expected.json`· `rx-import-review.test.js`.

## A4 σε Chromium: `npm run test:visual` (`visual/`)

`visual/a4-regression.mjs` χτίζει το φύλλο A4 (`PD.buildPrintHtml()` + `PD.PRINT_STYLES`, πραγματικά λεξικά, σταθερή ημερομηνία
5/1/2026) για δύσκολα πλάνα: πολύ μεγάλα ονόματα (και χωρίς κενά), σημειώσεις 300 χαρακτήρων, 10 φάρμακα ανά 6 ώρες, 60 ημέρες,
εβδομαδιαία/μηνιαία, μικτό. Ελέγχει αυτόματα, με print media: τίποτα δεν ξεχειλίζει από το κουτί του, κείμενα δεν επικαλύπτονται,
καμία κάρτα ημέρας δεν κόβεται σε δύο σελίδες (σημάδια στην αρχή/τέλος κάθε κάρτας, `pdftotext`· χωρίς αυτό ελέγχεται μόνο ο
κανόνας CSS: κάθε κάρτα, και κάθε σειρά χωρίς πυκνή κάρτα πλήρους πλάτους, `break-inside: avoid` και μικρότερη από σελίδα), όλα τα
ονόματα/σημειώσεις και το υποσέλιδο (αποποίηση ευθύνης, οι δύο γραμμές ευχαριστιών — οι προεπιλογές του
`Plandose_Settings::default_settings()`, όπως τις δίνει το `tools/extract-i18n.php`) υπάρχουν ολόκληρα, ακόμη και με `PD_VISUAL_PIXELS=0`. Τα PDF πάνε στο `visual/out/` (δεν μπαίνει στο git) και κάθε σελίδα (`pdftoppm`, poppler-utils) συγκρίνεται με το
`visual/baseline/*.png` (pixelmatch). Τα baselines εξαρτώνται από τις γραμματοσειρές του μηχανήματος: ξαναφτιάχνονται με
`npm run test:visual -- --update` όταν αλλάζει σκόπιμα η εκτύπωση.

## WordPress + MariaDB (`wp-integration/`)

Στήσιμο: `tools/setup-wp.sh` (το χρησιμοποιεί και το CI) ή, στο περιβάλλον ανάπτυξης, `/home/claude/wpenv/start.sh`
(MariaDB + `php -S 127.0.0.1:8899`, βλ. `/home/claude/wpenv/READY`). Χρειάζεται:

- `wp-config.php`: `WP_ENVIRONMENT_TYPE` = `'local'` **και** `PLANDOSE_TESTS` = `true`, `PLANDOSE_TEST_USERS` = `'pharm1,pharmpro,pdt_*'`·
- `wp-integration/pd-test-mu.php` στο `wp-content/mu-plugins/`: βοηθήματα και fault injection με πραγματικό `KILL` της σύνδεσης.
  Ανάγνωση με GET (`?pd_test=nonce|state`), κάθε αλλαγή με POST + nonce (`reset`, `fault`, `hold_lock`, `charge_later`, `age`)·
  μόνο για τους χρήστες του `PLANDOSE_TEST_USERS` ή διαχειριστές. Χρήση από JS: `lib/wp-client.js`.
- χρήστες `pharm1` (Free) και `pharmpro` (Pro), `account_type` = «Φαρμακείο»· οι δοκιμές χρεώσεων φτιάχνουν δικό τους `pdt_*`.

Αρχεία:

- `charges-1242.test.js`: κλείδωμα εκτύπωσης, χαμένη σύνδεση γύρω από τον μετρητή, replay, δωρεάν επανεκτυπώσεις, ταυτόχρονα αιτήματα.
- `charges-disconnect.test.js`: χαμένη σύνδεση ακριβώς πριν από την εγγραφή χρέωσης, μετά την εγγραφή/πριν το COMMIT, πριν το COMMIT,
  και απάντηση που δεν έφτασε ποτέ στον browser· μετά: ίδιο αίτημα ξανά (μία χρέωση), «Εκτύπωση ξανά» (δωρεάν), μετά το παράθυρο (νέα χρέωση).
- `charges-1270.test.js`, `settings-frontend-1242.php`: βλ. τα σχόλια των αρχείων.
- `browser-smoke.mjs`, `units-1242.mjs`, `rx-import-e2e.mjs`: πραγματικός Chromium (`npm run test:browser`).

## Εργαλεία (`tools/`)

- `make-pot.php <plugin-dir> <out.pot>`: POT χωρίς WP-CLI (`npm run pot:check` → `../build/plandose.pot.check`).
- `extract-i18n.php <plugin-dir>`: τα δύο λεξικά της JS σε JSON· το `Plandose_Settings::setting('x', …)` δίνει την προεπιλογή του
  `default_settings()` (όπως σε site που δεν άλλαξε τις ρυθμίσεις).
- `make-rx-expected.js`: διαφορές parser ↔ `rx-samples/expected.json` (exit 1 αν υπάρχουν)· `--write` για ενημέρωση.
- `setup-wp.sh`: δοκιμαστικό WordPress. `upgrade-check.sh [<commit>]`: αναβάθμιση από παλιό commit (προεπιλογή το «baseline») σε προσωρινό site/βάση — σχήμα (InnoDB, replay_count), δεδομένα, εκτύπωση, debug.log· καθαρίζει μόνο του. `run-scripts.js`: τρέχει σειριακά scripts με κοινό αποτέλεσμα.
- `php-qa/`: στατική ανάλυση PHP (μόνο για ανάπτυξη, δεν μπαίνει στο zip). `npm run qa:php` ή, μέσα στον φάκελο,
  `composer install && vendor/bin/phpstan && vendor/bin/phpcs`. PHPStan level 5 με stubs WordPress/WP-CLI (`phpstan.neon`·
  εξαιρέσεις μόνο στοχευμένες, με αιτιολόγηση) και PHPCS με τους κανόνες ασφάλειας/DB/i18n του WPCS 3 και PHPCompatibilityWP
  (`phpcs.xml`· χωρίς κανόνες μορφοποίησης). Χωρίς baseline· εξαιρέσεις στον κώδικα μόνο ως
  `// phpcs:ignore Sniff.Name -- αιτία` (το ελέγχει το `php-qa-1300.test.js`). Τα Composer plugins είναι κλειστά (τρέχει και ως root).
- `visual/rx-a4-preview.mjs`, `visual/rx-labels-preview.mjs`, `visual/rx-review-shot.mjs`: εικόνες/PDF για έλεγχο με το μάτι
  (χωρίς assertions), στο `visual/out/tools/`.

## CI (`.github/workflows/ci.yml`)

`php-lint` (PHP 8.0–8.4), `php-qa` (PHPStan + PHPCS, `tools/php-qa/`), `js-tests` (Node 20/22), `visual` (Chromium· σύγκριση pixel μόνο με τη μεταβλητή `PD_VISUAL_PIXELS=1`),
`wp-integration` (MariaDB service, `tools/setup-wp.sh`, `php/run.sh`, `test:wp`, `test:browser`) και `build`
(`build/build-zip.sh`: ίδια έκδοση σε header, `PLANDOSE_VERSION`, readme «Stable tag», CHANGELOG· `php -l`· κανένα αρχείο δοκιμών στο
πακέτο· artifact `plandose-<version>.zip`).
