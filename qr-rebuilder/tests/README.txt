QR ReBuilder Pro 2.15.6 — regression scripts (εκτός πακέτου plugin).
Τρέχουν με PHP 8.2+, χωρίς WordPress (stubs). GD προαιρετικό (χωρίς αυτό τα integration tests βγαίνουν SKIP)· pdo_sqlite για t_storage_longrun / t_token_sweep_edges (αλλιώς SKIP). Διαδρομή plugin: αυτόματα ο φάκελος qr-rebuilder-pro δίπλα στον φάκελο των tests (αρκεί να αποσυμπιεστούν τα δύο zip στον ίδιο φάκελο)· αλλιώς PDIR=/path/to/qr-rebuilder-pro.
  php tp1.php        provenance: SN=ABC δεν περνά πια ως scan
  php tp4.php        picker σε πραγματικά ασαφή σάρωση (αμετάβλητο)
  php t_rebuild.php  end-to-end rebuild: χωρίς ψευδές AI 240· 2.15.5: ρητός έλεγχος κάθε βήματος (πριν περνούσε και με αποτυχία)
  php t_race.php     ταυτόχρονη αποστολή με ίδιο σύνδεσμο: 1 email
  php t_mailer.php   κύκλος ζωής temp PNG, sweep, σήμανση στο email (χρειάζεται φάκελο tmpmail/)
  php golden.php     έξοδος parser για 4.021 εισόδους (σύγκριση πριν/μετά)
  php bench.php      CPU κατασκευασμένης εισόδου
  php t_greek_sigma.php  2.15.3: «Σ» = S ή W (picker και με τις δύο), «΅»→W, U+037E→q
  php t_expiry_dd00.php  2.15.3: AI 17 DD=00 διατηρείται (YYMM00), λήξη = τέλος μήνα, φίλτρο qrrp_preserve_expiry_day_zero
  php t_hri_split.php    2.15.3: HRI με επιπλέον AI μετά από τιμή μεταβλητού μήκους -> επιβεβαίωση
  php t_sigma_perf.php   2.15.3 review: κοινός προϋπολογισμός παραλλαγών «Σ», απαρίθμηση μόνο στα PC/SN/LOT/EXP
  php t_guest.php        2.15.3: επισκέπτες — user_declared, 403 manual_edit_not_allowed, email μόνο σε λίστα domains, όριο ανά παραλήπτη (+tag), σειρά ορίων, refresh_nonce
  php t_tokens_limiter.php  2.15.3: γεμάτο ευρετήριο tokens → απόρριψη νέου (κανένα ζωντανό δεν σβήνεται)· has_capacity / hit_subject σε in-memory $wpdb
  php golden.php > out.json && cmp out.json golden-2.15.3.json   (αναμενόμενη έξοδος 2.15.3· golden-2.15.2.json = πριν)

  php t_storage_longrun.php  2.15.4: rate limiter + tokens σε πραγματική SQL (SQLite), 30 προσομοιωμένες ημέρες, sweeps, races, uninstall (lib/sqlite_wpdb.php)
  php t_token_sweep_edges.php 2.15.4: QRRP_Tokens::sweep_expired() — decoys LIKE, λήξη ακριβώς «τώρα»
  php t_admin.php        2.15.4: μήνυμα άκυρων domains, φίλτρα παραληπτών, απόκρυψη προειδοποίησης (handler, nonce, uninstall)
  php t_datamatrix_gd.php 2.15.4: integration — πραγματικό DataMatrix (SKIP χωρίς GD)· 2.15.5: pad codewords κατά ISO/IEC 16022 §5.2.3
  php t_access_2155.php  2.15.5: όρια email συνδεδεμένων (50/ημέρα, 10/παραλήπτη, εξαίρεση διαχειριστών), χειροκίνητη δημιουργία επισκεπτών κλειστή + μετάπτωση, webmail στο endpoint
  php t_vendor_check.php 2.15.6: κουμπί «Έλεγχος για νέα έκδοση» (Packagist p2, dev/RC, σφάλματα δικτύου, admin/nonce/POST, escape, συνέπεια με το vendor) — χωρίς δίκτυο
  php t_admin_webmail.php 2.15.5: δημόσιες υπηρεσίες email στη λίστα domains επισκεπτών (sanitizer, πεδίο, φίλτρα)
  php t_invented_field.php 2.16.0: χωρίς GS, απίθανα κοντό SN/LOT (επινοημένο πεδίο) → επιβεβαίωση, ποτέ αυτόματα· φίλτρο qrrp_auto_inference_min_length
  php t_verified_email.php 2.16.0: email μόνο από εγκεκριμένους φαρμακοποιούς (δικαίωμα, έγκριση στο προφίλ, στήλη χρηστών, μετάπτωση)
  php golden.php > out.json && cmp out.json golden-2.16.0.json   (αναμενόμενη έξοδος 2.16.0· golden-2.15.7.json = πριν)

Renderer (2.15.4, lib/renderer.php): τα tests λογικής (t_guest, t_mailer, t_expiry_dd00) χρησιμοποιούν
ψεύτικο renderer και τρέχουν χωρίς GD· τα integration (t_rebuild, t_datamatrix_gd) βγαίνουν SKIP χωρίς GD.

Όλα μαζί:   ./run-all.sh      (2.15.5: FAIL και όταν ένα script δεν τυπώνει κανένα PASS ή βγαίνει με κωδικό ≠ 0)
Άλλη PHP:   PHP=php8.3 ./run-all.sh
Χωρίς GD:   NOGD=1 ./run-all.sh      (προσομοίωση σε Debian/Ubuntu)
.pot:       cd ../qr-rebuilder-pro && python3 ../tests/lib/makepot.py 2.15.6
Browser (Playwright, frontend JS): cd browser && npm install && npm test   — βλ. browser/README.txt
