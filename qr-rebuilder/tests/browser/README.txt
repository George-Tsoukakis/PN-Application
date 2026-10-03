QR ReBuilder Pro 2.15.5 — browser regression tests (frontend, χωρίς WordPress)

Εκτέλεση (μία εντολή, από αυτόν τον φάκελο):

    npm install --no-audit --no-fund && npm test

  - Το npm install χρειάζεται μόνο την πρώτη φορά (PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1
    αν δεν θέλετε λήψη browser). Playwright 1.56.1 = Chromium build 1194, που υπάρχει
    ήδη στο /opt/pw-browsers (προεπιλογή του npm test· αλλάζει με PLAYWRIGHT_BROWSERS_PATH).
  - Διαδρομή plugin: PDIR=/path/to/qr-rebuilder-pro (προεπιλογή ../../qr-rebuilder-pro).
  - Έξοδος: PASS/FAIL ανά έλεγχο, exit code 1 σε αποτυχία. Απαιτεί php (8.2+) και node (18+).

Αρχεία
  render.php   Αποδίδει το πραγματικό markup από includes/class-qrrp-shortcode.php
               (QRRP_Shortcode::render()) με ελάχιστα stubs WordPress, πιάνει τα δεδομένα
               του wp_localize_script('QRRP') (με τη μετατροπή scalars→string του WP) και
               γράφει fixture.html: markup + window.QRRP + qrrp-style.css + qrrp-app.js.
               Σταθερό todayISO=2026-09-29, nonce 0123abcd89, canSendEmail/canManualEntry "1".
  run.mjs      Playwright: σερβίρει το fixture στο http://qrrp.test/tool/, τα assets από
               τον δίσκο και απαντά στα POST admin-ajax.php (qrrp_parse / qrrp_rebuild /
               qrrp_refresh_nonce) με mocks μέσω route(). Το mock του rebuild χτίζει το raw
               από τα πεδία του POST (ίδιο με canonicalRaw() του JS).

Έλεγχοι
  a. Fix 1  δεύτερη σάρωση με εκκρεμές/αποτυχημένο parse (HTTP 500 και success:false):
            output/results κρυφά, Print/Download/Copy/Email disabled πριν και μετά.
  b. Fix 8  invalid_nonce (JSON 403) και σκέτο "-1": refresh → retry με το νέο nonce·
            αποτυχία refresh → μήνυμα staleNonce, μόνο 2 αιτήματα· retry πάλι stale → 3 και τέλος.
  c. Fix 9  Enter+Tab suffix κρατά την εστίαση στο #qrrp-hw-input (ελέγχεται πριν απαντήσει
            το parse)· ανθρώπινο Tab >300 ms μετακινεί την εστίαση· parse που ολοκληρώνεται
            (επιτυχία ή σφάλμα) ενώ ο χρήστης είναι σε άλλο πεδίο επαναφέρει την εστίαση.
  d. Fix 10 #qrrp-status-live (status) / #qrrp-alert-live (alert) υπάρχουν, όχι display:none·
            σφάλμα → alert, κανονικό μήνυμα → status· #qrrp-status aria-hidden=true.
  e. Fix 6  EXP 2028-02-00 → πεδίο 2028-02-29, προειδοποίηση ΗΗ=00, POST exp=2028-02-00·
            αλλαγή σε 2028-02-15 → POST exp=2028-02-15.
  f.        provenance 'user_declared' → output κανονικά, χωρίς σημείωση «Δηλωμένο»
            (αφαιρέθηκε στην 2.16.0· το state() είναι null-safe για το #qrrp-summary-provenance).
  g. 2.15.7 ελληνικά αυτούσια στον server, ανακοίνωση parse, email μετά από «Νέα σάρωση».
  h. 2.16.1 Fix 1  εστίαση στον σαρωτή μετά από rebuild/Εκτύπωση/Αντιγραφή/Λήψη/email
            (όχι αν ο χρήστης είναι στο πεδίο email/πελάτη)· σάρωση με εστίαση στο κουμπί
            «Εκτύπωση» → καμία επανεκτύπωση, όλο το burst στο parse, ροή νέας σάρωσης·
            γνήσιο Enter/Space στο κουμπί εκτυπώνει κανονικά.
  i. 2.16.1 Fix 2  το παράθυρο εκτύπωσης κλείνει με afterprint, με print() που μπλόκαρε,
            ή (χωρίς afterprint) όταν ο χρήστης γυρίσει στο εργαλείο — όχι νωρίτερα· εστίαση
            πίσω στον σαρωτή. Το print() του popup είναι stub (τύλιγμα του window.open).
  j. 2.16.1 Fix 3  κουμπί email με επικαλυπτόμενες αποστολές: νέος κωδικός → σωστή ετικέτα,
            χωρίς aria-busy· η παλιά αποστολή δεν επαναφέρει το κουμπί της νέας.
  k. 2.16.1 Fixes 4-7  fieldsChanged = πραγματική ετικέτα κουμπιού (PHP = JS fallback),
            #qrrp-warnings χωρίς role=alert και ανακοίνωση στο #qrrp-status-live,
            aria-describedby στο .qrrp-hw-hint, χωρίς emailSent, κουμπιά ανθεκτικά σε
            «.entry-content button» του theme.

Οι έλεγχοι επαληθεύτηκαν με mutation: αφαίρεση κάθε fix από αντίγραφο του qrrp-app.js
κάνει τους αντίστοιχους ελέγχους FAIL (h–k: με PDIR στο 2.16.0 αποτυγχάνουν 18 έλεγχοι·
χωρίς μόνο τον document keydown listener ο [h] δείχνει επανεκτύπωση).
