QR ReBuilder Pro 2.16.0 — browser regression tests (frontend, χωρίς WordPress)

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
  f. 2.16.0 καμία σήμανση προέλευσης (π.χ. «Δηλωμένο») στο output ούτε στην εκτυπωμένη
            ετικέτα· μία ετικέτα 62×29 mm = ακριβώς μία σελίδα PDF (πριν: 3).
  g. 2.15.7 ελληνικά φτάνουν αυτούσια στον server· ανακοίνωση ανάλυσης· απάντηση email
            μετά από «Νέα σάρωση» δεν δείχνει «στάλθηκε».
  h. 2.15.7 ορατή επιβεβαίωση email κάτω από το κουμπί (αποστολή/επιτυχία/σφάλμα/άκυρη
            διεύθυνση), καθαρίζει σε νέα διεύθυνση και «Νέα σάρωση».
  i. 2.16.0 νέα σάρωση μετά από σύνδεσμο email δεν στέλνει (δεν «καίει») το token του συνδέσμου.

Οι έλεγχοι επαληθεύτηκαν με mutation: αφαίρεση κάθε fix από αντίγραφο του qrrp-app.js
κάνει τους αντίστοιχους ελέγχους FAIL.
