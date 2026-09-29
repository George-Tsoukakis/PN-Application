# Job Listings – PharmacyNeeds  v9.9.41

Πλατφόρμα αγγελιών εργασίας αποκλειστικά για φαρμακεία.
Ελαφρύ, ασφαλές, χωρίς εξαρτήσεις.

---

## ⚡ Εγκατάσταση

1. Ανεβάστε τον φάκελο `job-listings` στο `/wp-content/plugins/`
2. Ενεργοποιήστε από **Admin → Plugins → Job Listings – PharmacyNeeds**
3. Πηγαίνετε **Settings → Permalinks → Save** (για τα rewrites)
4. Δημιουργήστε τις παρακάτω σελίδες:

| Σελίδα            | Shortcode         | Slug (προτεινόμενο) |
|-------------------|-------------------|---------------------|
| Αγγελίες          | `[listings]`      | `aggelies`          |
| Νέα Αγγελία       | `[new-listing]`   | `nea-aggelia`       |
| Dashboard         | `[dashboard]`     | `dashboard`         |

---

## 📋 Shortcodes

| Shortcode           | Για ποιον       | Τι κάνει                              |
|---------------------|-----------------|---------------------------------------|
| `[listings]`        | Όλοι            | Λίστα αγγελιών με φίλτρα (AJAX)      |
| `[new-listing]`     | Φαρμακοποιοί    | Φόρμα καταχώρησης / επεξεργασίας     |
| `[dashboard]`       | Φαρμακοποιοί    | Stats + διαχείριση αγγελιών          |
| `[recent-listings]` | Όλοι            | Grid πρόσφατων αγγελιών (αρχική)     |

**Attributes `[recent-listings]`:**

| Attribute       | Default | Περιγραφή                                      |
|-----------------|---------|------------------------------------------------|
| `count`         | `6`     | Αριθμός αγγελιών (max 24)                      |
| `columns`       | `3`     | Στήλες grid: `2` ή `3`                         |
| `title`         | —       | Heading πάνω από το grid (κενό = χωρίς heading)|
| `view_all_url`  | auto    | URL κουμπιού "Δείτε όλες" (auto-detect)        |
| `featured_first`| `true`  | Featured αγγελίες πρώτες                       |

---

## 📁 Δομή Plugin

```
job-listings/
├── job-jbli-listings.php    ← Bootstrap (constants, includes)
│
├── includes/
│   ├── jbli-helpers.php         ← Utility functions, permissions, labels
│   ├── jbli-capabilities.php    ← Caps για φαρμακοποιούς (runtime)
│   ├── jbli-post-type.php       ← CPT job_listing + status job-expired
│   ├── jbli-taxonomies.php      ← job_category + job_nomos (51 νομοί)
│   ├── jbli-storage.php         ← Dashboard/storage index/cache sync
│   ├── jbli-expiry.php          ← Hourly cron, λήξη 30 ημερών, HTML emails
│   ├── jbli-assets.php          ← Enqueue CSS/JS (vanilla JS, χωρίς jQuery)
│   ├── jbli-admin-columns.php   ← Backend columns, filters, row actions
│   ├── jbli-view-counter.php    ← Μοναδικές προβολές ανά αγγελία (IP hash)
│   ├── jbli-featured.php        ← Featured αγγελίες από admin (row action)
│   └── jbli-tokens.css          ← Frontend CSS Tokens
│
├── modules/
│   ├── form/
│   │   ├── jbli-form.php            ← Λογική [new-listing]
│   │   └── jbli-form-template.php   ← HTML φόρμας
│   │
│   ├── dashboard/
│   │   ├── jbli-dashboard.php           ← Λογική [dashboard]
│   │   └── jbli-dashboard-template.php  ← HTML dashboard
│   │
│   ├── listings/
│   │   ├── jbli-listings.php            ← Λογική [listings] + AJAX handler
│   │   ├── jbli-listings-template.php   ← HTML με AJAX hooks
│   │   └── jbli-listing-card.php        ← Card partial (επαναχρησιμοποιείται)
│   │
│   ├── single/
│   │   ├── jbli-single.php              ← Template override
│   │   └── jbli-single-template.php     ← HTML single αγγελίας
│   │
│   └── admin/
│       ├── jbli-admin-panel.php             ← Admin panel λογική
│       ├── jbli-admin-panel-template.php    ← Admin panel HTML
│       ├── jbli-admin-panel.css             ← Admin panel styles
│       ├── jbli-cache.php                   ← Settings & Cache λογική
│       └── jbli-cache-template.php          ← Settings & Cache HTML
│

```

---

## 🔑 Αναγνώριση Φαρμακοποιού

Διαβάζει `user_meta → account_type`. Αποδεκτές τιμές:
```
pharmacist | farmakopios | φαρμακοποιός | φαρμακοποιος | pharmacy | farmakeio | φαρμακείο
```

---

## ⚙️ Constants

```php
JL_VERSION  // '9.9.41'
JL_DIR      // Απόλυτο path plugin
JL_URL      // URL plugin
JL_CPT      // 'job_listing'
```

---

## 🗄️ Meta Keys

| Key                    | Περιγραφή                          |
|------------------------|------------------------------------|
| `jbli_position`        | Τίτλος θέσης                       |
| `jbli_salary`          | Αμοιβή (key)                       |
| `jbli_type`            | Τύπος απασχόλησης                  |
| `jbli_address`         | Διεύθυνση                          |
| `jbli_lat` / `jbli_lng`| Συντεταγμένες                      |
| `jbli_contact_phone`   | Τηλέφωνο αγγελίας                  |
| `jbli_pharmacy_name`   | Όνομα φαρμακείου                   |
| `jbli_expires`         | Ημ/νία λήξης (datetime)            |
| `jbli_expired`         | Flag λήξης (1/0)                   |
| `jbli_reminder_sent`   | Flag reminder email (1/0)          |
| `jbli_views`           | Αριθμός μοναδικών προβολών         |
| `jbli_featured`        | Featured flag (1/'')               |
| `jbli_email`           | Email αγγελίας                     |

---

## ⏰ Σύστημα Λήξης & Emails

- Αγγελία λήγει **30 ημέρες** μετά τη δημοσίευση
- **Hourly cron** → status `job-expired` + meta `_job_expired = 1` + HTML email λήξης
- **Daily cron** → HTML reminder email 3 μέρες πριν τη λήξη (αποστέλλεται μία φορά)
- Ανανέωση από dashboard ή admin panel → reset reminder flag

---

## ⭐ Featured Αγγελίες

- Admin μόνο → Row Action **"☆ Ορισμός Featured"** / **"★ Αφαίρεση Featured"**
- Featured αγγελίες εμφανίζονται **πρώτες** στη λίστα με ειδικό ribbon
- Ειδική στήλη (★) στο admin list view
- Meta key: `jbli_featured`

---

## 👁️ View Counter

- Μετράει μοναδικές προβολές ανά αγγελία
- Deduplication: IP hash + 6ωρο transient throttle (χωρίς αποθήκευση IP)
- Administrators δεν μετρώνται
- Εμφανίζεται στα cards και στη single σελίδα
- Meta key: `jbli_views`

---

## ⚡ AJAX Φίλτρα

- Τα φίλτρα (νομός, κατηγορία, τύπος, αμοιβή) ενημερώνουν τα αποτελέσματα **χωρίς page reload**
- Debounced text search (400ms)
- URL update με `history.replaceState` (bookmarkable / shareable)
- Vanilla JS — **χωρίς jQuery dependency**
- Graceful fallback: λειτουργεί και χωρίς JS (κανονικό GET)

---

## 🔒 Ασφάλεια

- `capability_type => ['job_listing', 'job_listings']` — custom capabilities με πλήρη Rank Math / Yoast συμβατότητα
- `show_in_rest => true` — Gutenberg support
- POST actions με **nonce** σε όλες τις φόρμες
- Ownership check: φαρμακοποιός επεξεργάζεται **μόνο δικές του** αγγελίες
- `$_SERVER['REQUEST_URI']` αντικαταστάθηκε με `get_permalink()` (XSS fix)
- Admin stats με `wp_count_posts()` αντί για 4× WP_Query
- Πλήρες `sanitize` + `escape` παντού

---

## 🎨 Προσαρμογή χρωμάτων

`assets/css/jl.css`:
```css
:root {
  --jl-green:    #0d7a3e;
  --jl-green-dk: #095e30;
  --jl-featured: #f0a500;
  --jl-radius:   10px;
}
```

---

## 📝 Changelog

### v9.9.51
- **UI**: Κάρτες & σελίδα αγγελίας — «Φαρμακείο:» πριν από το όνομα (χωρίς διπλό «Φαρμακείο» αν το όνομα ξεκινά ήδη έτσι)· «Αναζητά:» σε σκούρο μπλε (στη σελίδα αγγελίας σε λευκό σήμα πάνω στο πράσινο).
- **UI**: Σελίδα αγγελίας σε δύο στήλες — η αγγελία αριστερά, εικόνα/video δεξιά με κάρτα «Ενδιαφέρεστε για αυτή τη θέση;» και κουμπί Εκδήλωσης Ενδιαφέροντος. Νέα ρύθμιση «Σελίδα αγγελίας» στις Εικόνες/Video (κενό = ίδια με της φόρμας). Σε κινητό/tablet το πάνελ κρύβεται.
- **Email**: Νέο email προς το φαρμακείο — θέμα «Νέο ενδιαφέρον για τη θέση «…» — Όνομα», κουτιά «Στοιχεία υποψηφίου» (με κουμπιά κλήσης/απάντησης) και «Η αγγελία σας» (θέση, φαρμακείο, κατηγορία, νομός, απασχόληση, αμοιβή, λήξη), κουμπί προβολής αγγελίας· Reply-To στον υποψήφιο· δεν στέλνεται πλέον η IP του υποψηφίου. Νέο κέλυφος για όλα τα email του plugin.
- **Κινητό**: Popup — πεδία 16px (χωρίς zoom στο iPhone), πιο συμπαγής κεφαλίδα ώστε η «Αποστολή» να φαίνεται χωρίς κύλιση.

### v9.9.50
- **UI/Fix**: Το κουτί «Βρείτε αγγελίες εργασίας» κατεβαίνει πιο χαμηλά (80px σε desktop, 34px σε κινητό) ενώ η πράσινη ζώνη κρατά το ίδιο ύψος. Το εσωτερικό κενό της σελίδας μηδενιζόταν από τα `jbli-form.css`/`jbli-dashboard.css`, γι' αυτό το κουτί ακουμπούσε στην κορυφή της ζώνης· τώρα ορίζεται με ID selector.

### v9.9.49
- **UI**: Popup «Εκδήλωση Ενδιαφέροντος» — το πλαίσιο με τη θέση/φαρμακείο είναι πλέον ορθογώνιο με στρογγυλεμένες γωνίες (αντί για «χάπι») και έχει περισσότερο εσωτερικό κενό, ώστε το κείμενο να μην ακουμπά στις άκρες.

### v9.9.48
- **Fix**: Το κενό κάτω από το header της σελίδας αγγελιών (9.9.46) δεν εφαρμοζόταν — τα `jbli-form.css`/`jbli-dashboard.css` φορτώνουν μετά και το μηδένιζαν με `margin: 0 auto`. Τώρα ορίζεται με ID selector (υπολογισμένο: 0px → 64px).
- **Νέο**: Ρύθμιση «Τίτλος αγγελίας → Μέγιστοι χαρακτήρες» στις Ρυθμίσεις (προεπιλογή 15, εύρος 5–120).

### v9.9.47
- **Αλλαγή**: Ο τίτλος αγγελίας έχει όριο **15 χαρακτήρες** (μαζί με τα κενά) αντί για όριο λέξεων — `maxlength` στο πεδίο, έλεγχος στον server, ζωντανός μετρητής `x/15`. Αλλάζει με το φίλτρο `jbli_title_max_chars`.

### v9.9.46
- **UI**: Η σελίδα αγγελιών έχει κενό πάνω/κάτω ώστε να μην ακουμπά στο header του site.
- **Fix**: Πολύ μεγάλοι τίτλοι (ή μία τεράστια «λέξη») αναδιπλώνονται σε κάρτες, πρόσφατες, σελίδα αγγελίας, dashboard και popup αντί να κόβονται/βγαίνουν έξω.
- **Νέο**: Όριο λέξεων τίτλου (αντικαταστάθηκε στην 9.9.47).

### v9.9.45
- **UI**: Σελίδα αγγελιών — πράσινη hero ζώνη με διακριτικό μοτίβο πίσω από την αναζήτηση και απαλό φόντο κάτω από τις κάρτες.
- **UI**: Φόρμα νέας αγγελίας — πιο συμπαγής (μικρότερα πεδία/αποστάσεις, λεπτή κεφαλίδα) σε δύο στήλες, με εικόνα ή video στα δεξιά. Ορίζεται από Ρυθμίσεις → «Εικόνα / Video φόρμας» (εικόνα, MP4/WebM, YouTube ή Vimeo)· κενό = έτοιμη εικονογράφηση. Σε tablet γίνεται banner, σε κινητό κρύβεται.
- **UI**: Νέο popup «Εκδήλωση Ενδιαφέροντος» — πράσινη κεφαλίδα με τη θέση και το φαρμακείο, εικονίδια στα πεδία, animation, bottom-sheet σε κινητό, Enter για αποστολή, σημείωση απορρήτου. Νέο αρχείο `modules/apply/css/jbli-apply.css`.

### v9.9.44
- **Fix**: Η φόρμα νέας αγγελίας και τα κουμπιά του dashboard υποβάλλονται πλέον στην ίδια τη σελίδα, όχι στο `/wp-admin/admin-post.php`. Security plugins ή κώδικας theme που κρατούν τους μη-διαχειριστές έξω από το wp-admin (redirect στο `admin_init`) «κατάπιναν» σιωπηλά κάθε υποβολή φαρμακείου.
- **Fix**: Ο WP Rocket καθαρίζει κάθε σελίδα που εμφανίζει αγγελίες (με shortcode σε content ή page builder meta), όχι μόνο τη σελίδα με slug `aggelies`.

### v9.9.43
- **Fix**: Email αιτήσεων — τα `{phone}`, `{email}`, `{position}` αντικαθίστανται ξανά.
- **Fix**: Η φόρμα «Danger Zone» δεν απενεργοποιεί πλέον τις ρυθμίσεις απορρήτου.
- **Fix**: CSV υποβολών — επανήλθε η στήλη τηλεφώνου· προστασία από formula injection.
- **Security**: Έλεγχος nonce στο AJAX υποβολών· το IP για τις προβολές λαμβάνεται από proxy headers μόνο από Cloudflare/ιδιωτικό proxy.
- **Fix**: Υπολείμματα της μετονομασίας `jbli_` σε κλειδιά WordPress/schema.org (`supports`, `label`, στήλη τίτλου, `$typenow`, `post`, kses `target/title/class`, meta_query `type`, paginate_links `type`, JSON-LD `title/address`, shortcode `title`).
- **Fix**: Σελίδα αγγελίας — το redirect μη δημοσιευμένων γίνεται στο `template_redirect` (πριν το header).
- **Fix**: Πρόσφατες αγγελίες — σωστές «ημέρες που απομένουν» ανά κάρτα· flush cache σε κάδο/επαναφορά/featured.
- **Fix**: `[listings]` — η σελιδοποίηση `/page/N/` λειτουργεί· σωστές κλάσεις/`aria-current` στην AJAX σελιδοποίηση· φίλτρο αμοιβής «2200+».
- **Fix**: Modal αίτησης — εμφανίζεται ξανά η φόρμα μετά από επιτυχία· σωστό AJAX URL σε single/recent σελίδες.
- **Fix**: Dashboard — αγγελίες «σε αναμονή» δεν αυτο-εγκρίνονται από τον ιδιοκτήτη.
- **Fix**: Φόρμα — μετρητής χαρακτήρων, προστασία διπλού κλικ και σωστά μηνύματα validation (μεταφέρθηκαν στο `jbli-form.js`).
- **Fix**: Λήξη — αγγελία που ξαναδημοσιεύεται από το wp-admin λήγει κανονικά.
- **Fix**: Ενιαίο URL admin panel (`admin.php?page=jbli_admin_panel`)· διπλό μήνυμα αποθήκευσης.
- **Fix**: WP Rocket — εξαιρέσεις JS στα τρέχοντα αρχεία, ανανέωση config για τα reject URIs.
- **Fix**: Πίνακας φαρμακείων — διπλό email δεν μπλοκάρει πλέον το index· καθαρισμός στη διαγραφή χρήστη.
- **Compat**: PHP 8.4 (ρητά nullable παράμετροι)· account type αποθηκευμένο ως array.

### v9.9.42
- **Fix**: Η αμοιβή «2.200€+» απορριπτόταν πάντα στην υποβολή (`sanitize_key` έκοβε το `+`).
- **Fix**: Μήνυμα επιτυχίας στη σελίδα της αγγελίας μετά την υποβολή.
- **Fix**: Φόρμα/dashboard δεν μπαίνουν σε page cache (`DONOTCACHEPAGE`) — τέλος στο «Σφάλμα ασφαλείας» από ληγμένο nonce.
- **Fix**: Το cooldown υποβολής απελευθερώνεται αν η αποθήκευση αποτύχει.
- **DB 1.3.1**: Μετονομασία παλιών `_job_*` meta ανά αγγελία και rebuild του storage index μετά την αναβάθμιση· αφαιρέθηκαν μετατροπές με παλιά ονόματα στηλών.
- **Fix**: Η απεγκατάσταση διαγράφει τους τρέχοντες πίνακες.

### v9.9.41

- **Fix** (`jbli-form-template.php`): αφαιρέθηκε το `novalidate`. Όλα τα πεδία είχαν
  ήδη `required`, αλλά το `novalidate` ακύρωνε τον έλεγχο του browser — η φόρμα
  υποβαλλόταν κενή και το σφάλμα ερχόταν μετά από ολόκληρο round trip.
- **Fix** (`jbli-field-contact.php`): `type="jbli_email"` → `type="email"` και
  `autocomplete="jbli_email"` → `autocomplete="email"`. Ο άκυρος τύπος γινόταν
  `text`, οπότε δεν γινόταν κανένας έλεγχος μορφής και το κινητό δεν έβγαζε
  πληκτρολόγιο email.
- **Validation** (`jbli-validation.php`): συλλέγει πλέον ΟΛΑ τα σφάλματα αντί να
  σταματά στο πρώτο. Νέα `jbli_form_error_fields()` κρατά ποια πεδία απέτυχαν.
- **UX** (`jbli-form.js`, `jbli-form.css`): ελληνικά μηνύματα validation, κόκκινη
  επισήμανση πεδίου με inline μήνυμα, scroll + focus στο πρώτο πρόβλημα, και
  επαναεπισήμανση όσων απέρριψε ο server μετά το redirect. Έλεγχος 10 ψηφίων
  στο τηλέφωνο και client-side.
- **Admin** (`jbli-admin-panel.css`): οι τρεις διακόπτες απορρήτου σε πλέγμα 3
  στηλών αντί για στοιβαγμένη λίστα· στοιβάζονται ξανά κάτω από 1100px.

### v9.9.40

- **Assets** (`includes/jbli-assets.php`): τα module stylesheets δεν φορτώνονται πλέον σε κάθε σελίδα.
  Νέα `jbli_required_css_modules()` + filter `jbli_required_css_modules`.
  Σελίδα με μόνο `[recent-listings]` (αρχική) → `tokens` + `recent`.
  Σελίδα χωρίς περιεχόμενο αγγελιών → κανένα αρχείο.
  Κάθε άλλη περίπτωση → αμετάβλητη συμπεριφορά (και τα 5 αρχεία), επειδή τα
  `single`/`listings`/`form`/`dashboard` δανείζονται κλάσεις μεταξύ τους.
- **CSS** (`modules/recent/css/jbli-recent.css`): μεταφέρθηκαν `.jbli_btn_outline`
  (από `jbli-listings.css`) και `.jbli_empty` (από `jbli-dashboard.css`) — οι μόνοι
  δύο κανόνες που δανειζόταν το recent markup. Το module είναι πλέον αυτάρκες.
- **Fix** (`includes/jbli-no-cache-headers.php`): προστέθηκε το slug
  `diaxeirisi-aggelion`. Τα defaults ήταν `dashboard`/`nea-aggelia`, οπότε ο
  πραγματικός πίνακας διαχείρισης δεν έπαιρνε ποτέ no-cache headers.

### v9.9.37

- **Fix** (`job-jbli-listings.php`): Αφαιρέθηκε redundant `is_string($path)` check στο file loader — `$path` είναι πάντα string.
- **Fix** (`job-jbli-listings.php`): Το `activation` hook πλέον κάνει και αυτό `update_option('jbli_asset_bust')`. Χωρίς αυτό, σε fresh install το CSS/JS δεν έπαιρνε ποτέ cache bust γιατί το `check_version` δεν τρέχει την πρώτη φορά.
- **Fix** (`job-jbli-listings.php`): `deactivation` hook — `flush_rewrite_rules()` → `flush_rewrite_rules(false)` (soft flush, ίδιο με activation). Αποτρέπει περιττή εγγραφή στο `.htaccess` κατά deactivation.
- **Fix** (`jbli-uninstall.php`): Προστέθηκε `jbli_listings_page_id` στη λίστα options που διαγράφονται. Η option γραφόταν από `jbli-urls.php` αλλά δεν καθαριζόταν ποτέ κατά απεγκατάσταση.
- **Fix** (`jbli-uninstall.php`): Το bulk DELETE query για transients τώρα καλύπτει και τα rate-limit transients (`jbli_rate_*`, `jbli_edit_rate_*`) που έμεναν ορφανά στη βάση.
- **Fix** (`modules/form/form-parts/jbli-rate-limit.php`): Edit cooldown TTL διορθώθηκε από `15` (δευτερόλεπτα) σε `30` — το `15` επέτρεπε spam edits κάθε 15 sec.
- **Improvement** (`modules/form/form-parts/jbli-validation.php`): Προστέθηκε max-length validation για description (3000 χαρακτήρες plain text) που **εντελώς έλειπε**.
- **UX** (`modules/form/template-parts/jbli-field-position.php`): Προστέθηκε live character counter (`0/120`) με `aria-describedby` + `aria-live` για screen readers.
- **UX** (`modules/form/template-parts/jbli-field-consent.php`): Προστέθηκε `aria-required="true"` στο consent checkbox.
- **Cleanup** (`modules/form/template-parts/jbli-field-location.php`, `jbli-field-description.php`): Αφαιρέθηκαν inline `style=""` — μεταφέρθηκαν στο `jl-jbli-form.css` (`.jl-field--address`, `.jl-field--description`).
- **JS** (`assets/js/jl.js`): Νέα `initCharCounters()` function — live character counter για input fields με `data-max`.
- **CSS** (`assets/css/parts/jl-jbli-form.css`): Προστέθηκαν `.jl-field--address`, `.jl-char-counter`, `[data-warn="true"]` rules. Βελτιώθηκε `.jl-field__hint` spacing.

### v9.9.35

- **Feature** (`jbli-field-contact.php`, `jbli-form.php`, `jbli-validation.php`, `jbli-saving.php`): Το email επικοινωνίας γίνεται editable στη φόρμα. Προ-φορτώνεται από το stored meta της αγγελίας (edit mode) ή από το account email (new). Validated και αποθηκεύεται ανεξάρτητα από το account email.
- **Fix** (`jbli-saving.php`): Προσθήκη `$owner_id` param στο `job_listing_save_listing_meta()`. Σε admin edit, το pharmacy name διαβάζεται από τον αρχικό ιδιοκτήτη της αγγελίας — όχι από τον admin.
- **UI** (`jbli-listings-template.php`, `jl-jbli-listings.css`, `jl.css`): Νέο search panel με header/subtitle. CSS Grid για filters (4 cols → 2 → 1) και cards (3 cols → 2 → 1). Mobile-first responsive.
- **UI** (`jbli-settings-box.php`, `jbli-admin-panel.css`): Ρυθμίσεις Απορρήτου redesign με toggle cards, icons, και `:has()` + JS fallback.
- **Fix** (`jbli-single-template.php`): `str_ends_with()` → `substr()` για PHP 7.4 compatibility.
- **Fix** (`jbli-assets.php`): `strategy => defer` array → `true` (in_footer) για WordPress 5.8 compatibility.
- **Docs** (`README.MD`): Version sync από 9.9.31 → 9.9.35.

### v9.9.31

- **Refactor** (`modules/listings/jbli-listings.php`): Χωρίστηκε σε ξεχωριστά αρχεία.
- **Added** (`listings-parts/jbli-filters.php`): parse_filters() + build_query().
- **Added** (`listings-parts/jbli-cards.php`): bulk_fetch_terms(), get_card_data(), render_listing_card(), render_cards_html().
- **Fix**: Συγχρονισμός έκδοσης plugin σε `9.9.31`.

### v9.9.30

- **Improve** (`assets/js/jl.js`): Βελτίωση ποιότητας και accessibility.
- **Fix**: AbortController guard — παλαιότεροι browsers συνεχίζουν να λειτουργούν.
- **Add**: aria-busy στο container, aria-live/aria-atomic στο count element.
- **Add**: Retry button στο error state αντί μόνο για κείμενο.
- **Add**: aria-label/aria-current/aria-hidden στα pagination links.
- **Add**: Submit button auto-restore μετά 15 δευτερόλεπτα αν δεν γίνει redirect.
- **Fix**: HTML5 validation check πριν το disable του button.
- **Fix**: Συγχρονισμός έκδοσης plugin σε `9.9.30`.

### v9.9.29

- **Refactor** (`assets/css/jl.css`): Προστέθηκε Table of Contents (19 sections). Version header ενημερώθηκε.
- **Added** (`assets/css/parts/`): 7 source part files για future build process (jl-base, jl-form, jl-listings, jl-dashboard, jl-single, jl-responsive, jl-recent).
- **Fix**: Συγχρονισμός έκδοσης plugin σε `9.9.29`.

### v9.9.28

- **Refactor** (`includes/jbli-helpers.php`): Χωρίστηκε σε ξεχωριστά αρχεία.
- **Added** (`helpers-parts/jbli-strings.php`): strlen, substr, normalize_text.
- **Added** (`helpers-parts/jbli-terms.php`): Taxonomy term cache + invalidation.
- **Added** (`helpers-parts/jbli-expiry-display.php`): Expiry date display helpers.
- **Added** (`helpers-parts/jbli-notices.php`): Redirect notices + login gate HTML.
- **Added** (`helpers-parts/jbli-slugs.php`): Greek transliteration + slug builder.
- **Fix**: Συγχρονισμός έκδοσης plugin σε `9.9.28`.

### v9.9.27

- **Refactor** (`includes/jbli-expiry.php`): Χωρίστηκε σε ξεχωριστά αρχεία.
- **Added** (`expiry-parts/jbli-lifecycle.php`): future_datetime, publish_and_reset, renew, activate, deactivate.
- **Added** (`expiry-parts/jbli-actions.php`): Expiry batch processing + reminder logic.
- **Added** (`expiry-parts/jbli-cron.php`): Cron scheduling + hooks.
- **Added** (`expiry-parts/jbli-emails.php`): Transactional email notifications + HTML primitives.
- **Fix**: Συγχρονισμός έκδοσης plugin σε `9.9.27`.

### v9.9.26

- **Refactor** (`modules/admin/jbli-admin-panel-template.php`): Χωρίστηκε σε partial αρχεία.
- **Added** (`panel-parts/jbli-header.php`): Page title + action notice.
- **Added** (`panel-parts/jbli-stats.php`): Listing counts bar.
- **Added** (`panel-parts/jbli-filters.php`): Search + taxonomy + status filters.
- **Added** (`panel-parts/jbli-listings-table.php`): Results table + pagination.
- **Added** (`panel-parts/jbli-settings-box.php`): Privacy settings form.
- **Added** (`panel-parts/jbli-danger-zone.php`): Storage backfill + danger zone.
- **Added** (`panel-parts/jbli-confirm-script.php`): Inline JS confirm dialogs.
- **Fix**: Συγχρονισμός έκδοσης plugin σε `9.9.26`.

### v9.9.25

- **Refactor** (`modules/admin/jbli-admin-panel.php`): Χωρίστηκε σε ξεχωριστά αρχεία.
- **Added** (`admin-parts/jbli-registration.php`): Menu registration & assets.
- **Added** (`admin-parts/jbli-actions.php`): POST action handler με per-action helpers.
- **Added** (`admin-parts/jbli-stats.php`): Listing count statistics.
- **Added** (`admin-parts/jbli-buttons.php`): Action button form builder.
- **Added** (`admin-parts/jbli-render.php`): WP_Query builder & template renderer.
- **Fix**: Συγχρονισμός έκδοσης plugin σε `9.9.25`.

### v9.9.24

- **Refactor** (`modules/form/jbli-form-template.php`): Το template χωρίστηκε σε partial αρχεία.
- **Added** (`modules/form/template-parts/jbli-header.php`): Heading, subtitle, expired notice.
- **Added** (`modules/form/template-parts/jbli-field-position.php`): Job title input.
- **Added** (`modules/form/template-parts/jbli-field-category.php`): Category & nomos selects.
- **Added** (`modules/form/template-parts/jbli-field-contact.php`): Phone & email fields.
- **Added** (`modules/form/template-parts/jbli-field-salary-type.php`): Salary & type selects.
- **Added** (`modules/form/template-parts/jbli-field-location.php`): Address input + hidden lat/lng.
- **Added** (`modules/form/template-parts/jbli-field-description.php`): Description textarea.
- **Added** (`modules/form/template-parts/jbli-field-consent.php`): Consent checkbox (new only).
- **Fix**: Συγχρονισμός έκδοσης plugin σε `9.9.24`.

### v9.9.23

- **Refactor** (`modules/form/jbli-form.php`): Το form module χωρίστηκε σε ξεχωριστά αρχεία.
- **Added** (`modules/form/form-parts/jbli-permissions.php`): Έλεγχος πρόσβασης και επεξεργασίας αγγελίας.
- **Added** (`modules/form/form-parts/jbli-validation.php`): Sanitization και validation όλων των πεδίων.
- **Added** (`modules/form/form-parts/jbli-saving.php`): Αποθήκευση post, taxonomy terms και meta.
- **Added** (`modules/form/form-parts/jbli-rate-limit.php`): Έλεγχος ορίου ενεργών αγγελιών και cooldown.
- **Fix**: Συγχρονισμός έκδοσης plugin σε `9.9.23`.

### v9.9.22

- **Refactor** (`includes/jbli-storage.php`): Το storage layer χωρίστηκε σε ξεχωριστά αρχεία.
- **Added** (`includes/jbli-storage-schema.php`): Schema, dbDelta και migrations για custom tables.
- **Added** (`includes/jbli-storage-sync.php`): Sync logic για save/delete/trash actions.
- **Added** (`includes/jbli-storage-queries.php`): Dashboard queries και count helpers.
- **Added** (`includes/jbli-storage-migrations.php`): Backfill routine για παλιές αγγελίες.
- **Fix**: Συγχρονισμός έκδοσης plugin σε `9.9.22`.

### v9.9.21

- **Fix** (`job-listings/`): Μετονομασία φακέλου από `job-listings-improved` σε `job-listings` — σωστό plugin slug.
- **Fix** (version sync): Συγχρονισμός version comments σε `jl.css`, `jl.js`, `jbli-admin-panel.css` και `README.MD` στο `9.9.21`.
- **Fix** (`assets/css/jl.css`): Αφαιρέθηκε πλεονασματικός selector `.jl-recent__grid--cols-3`.
- **Fix** (`modules/form/jbli-form.php`): Προστέθηκε `admin_post_nopriv_job_listing_submit` — μη συνδεδεμένος χρήστης πλέον λαμβάνει σωστό redirect αντί για WordPress `0`.
- **Fix** (`modules/admin/jbli-cache.php`): Sanitize του `jbli_cache_action` POST field πριν το comparison.
- **Improvement** (`modules/single/jbli-single.php`): Το single template override ελέγχει πρώτα αν το theme έχει `single-job_listing.php`. Αν ναι, το αφήνει. Αλλιώς φορτώνει πάντα το plugin template (γενικά theme files όπως `jbli-single.php` αγνοούνται).

### v9.9.20

- **Fix** (`assets/css/jl.css`): Αφαιρέθηκε το global `flex-basis: 230px` στο `.jl-filter-field:nth-child(3)` που προκαλούσε τεράστιο κενό ανάμεσα στο "Τύπος απασχόλησης" και "Αμοιβή" στο mobile. Το rule μεταφέρθηκε αποκλειστικά σε `@media (min-width: 641px)` και `@media (min-width: 900px)`.
- **Fix** (`assets/css/jl.css`): Mobile filter layout — καθαρό column layout, reset όλων των desktop flex-basis per nth-child, buttons "Αναζήτηση" / "Καθαρισμός" stacked full-width, fix horizontal scroll.
- **Version bump** για αυτόματο cache bust του CSS/JS (`JL_VERSION` → 9.9.20).

### v9.9.19

- **Fix** (`assets/css/jl.css`): Αφαιρέθηκε το global `flex-basis: 230px` στο `.jl-filter-field:nth-child(3)` που προκαλούσε τεράστιο κενό ανάμεσα στο "Τύπος απασχόλησης" και "Αμοιβή" στο mobile. Το rule μεταφέρθηκε αποκλειστικά σε `@media (min-width: 641px)` και `@media (min-width: 900px)`.
- **Fix** (`assets/css/jl.css`): Mobile filter layout — καθαρό column layout, reset όλων των desktop flex-basis per nth-child, buttons "Αναζήτηση" / "Καθαρισμός" stacked full-width, fix horizontal scroll.
- **Version bump** για αυτόματο cache bust του CSS/JS (`JL_VERSION` → 9.9.19).

### v9.9.18

- **Bug Fix** (`modules/single/jbli-single-template.php`): Διορθώθηκε σπασμένο HTML — το κουμπί "Καλέστε τώρα" δεν είχε opening `<a>` tag, παράγοντας invalid HTML χωρίς PHP error.
- **Privacy** (`modules/form/jbli-form-template.php`): Προστέθηκε υποχρεωτικό checkbox συναίνεσης GDPR — εμφανίζεται μόνο σε νέες αγγελίες (όχι σε edits), συνεπές με το backend validation.
- **Privacy** (`modules/form/jbli-form.php`): Backend validation για consent checkbox μόνο σε νέες αγγελίες.
- **Perf** (`modules/recent/jbli-recent-jbli-listings.php`): Transient cache 10 λεπτών για το `[recent-listings]` shortcode με version salt (`job_listing_recent_cache_version`). Το flush γίνεται με `update_option` αντί για SQL LIKE — λειτουργεί σωστά και με persistent object caches (Redis/Memcached).
- **Security** (`includes/jbli-post-type.php`): `show_in_rest => false` στο custom status `job-expired`, συνεπές με το CPT.
- **Fix** (`README.MD`): `JL_VERSION` comment ενημερώθηκε σε `9.9.18`.

### v9.9.17
- **Fix** (`job-jbli-listings.php`): Διορθώθηκε ασυνέπεια version — plugin header, `JL_VERSION` constant και README ήταν εκτός sync. Όλα πλέον στο `9.9.17`.
- **Feature** (`modules/form/jbli-form.php` + `jbli-form-template.php`): Προστέθηκε backend αποθήκευση για πεδία `address`, `lat`, `lng`. Νέο πεδίο "Διεύθυνση Φαρμακείου" στη φόρμα (χειροκίνητη εισαγωγή).
- **Feature** (`modules/single/jbli-single-template.php`): Αντικαταστάθηκαν τα σκόρπια `itemprop` microdata με πλήρες `<script type="application/ld+json">` JobPosting schema (Google Jobs). Περιλαμβάνει `employmentType`, `baseSalary`, `validThrough`, `jobLocation` με `streetAddress`.
- **Privacy** (`modules/single/jbli-single-template.php`): Νέα options `job_listing_public_phone`, `job_listing_public_email`, `job_listing_public_address` ελέγχουν ποια στοιχεία επικοινωνίας εμφανίζονται δημόσια. Defaults: phone=on, email=off, address=off.
- **Privacy** (`modules/admin/jbli-admin-panel.php` + `jbli-admin-panel-template.php`): Νέο section "Στοιχεία Επικοινωνίας" στο admin panel για τη διαχείριση των παραπάνω options.
- **Privacy** (`modules/form/jbli-form-template.php`): Προστέθηκε ενημερωτικό κείμενο GDPR κοντά στα στοιχεία επικοινωνίας.
- **Refactor** (`includes/jbli-helpers.php`): Σπάσιμο του 938-γραμμού αρχείου σε 4 sub-files: `jbli-permissions.php`, `jbli-sanitizers.php`, `jbli-formatters.php`, `jbli-urls.php`. Το `jbli-helpers.php` φορτώνει αυτόματα τα sub-files.
- **Fix** (`includes/jbli-storage.php`): Προστέθηκε orphan cleanup στη migration v1.2.1 — διαγράφει `jbli_pharmacy_listings` rows που δείχνουν σε ανύπαρκτα `pharmacy_id` μετά το duplicate email cleanup.
- **Fix** (`modules/single/jbli-single-template.php` JSON-LD): `streetAddress` δεν περνάει πλέον σαν κενό string. `employmentType` δεν χρησιμοποιεί πλέον `strtoupper()` fallback — αν ο τύπος δεν είναι στο map, απλά παραλείπεται.
- **Fix** (`assets/js/jl.js`): Αφαιρέθηκε geolocation — η Διεύθυνση Φαρμακείου εισάγεται χειροκίνητα. Αφαιρέθηκαν τα αντίστοιχα i18n strings από `includes/jbli-assets.php`.

### v9.9.13
- **UI** (`modules/recent/jbli-recent-jbli-listings.php` + `assets/css/jl.css`): Redesigned empty state για το `[recent-listings]` shortcode. Αντί για plain text, εμφανίζει centered card με icon, τίτλο, μήνυμα και CTA κουμπί "Δείτε όλες τις αγγελίες".

### v9.9.12
Βελτιώσεις από πλήρη ανάγνωση κάθε αρχείου (τρίτος γύρος).

**🔴 Κρίσιμα**
- **Bug Fix** (`includes/jbli-expiry.php`): `job_listing_future_datetime()` χρησιμοποιούσε `current_time('timestamp', true)` (deprecated WP 5.3) → `time()`.
- **Bug Fix** (`includes/jbli-admin-columns.php`): Στήλη "Λήξη" στο admin list καλούσε `job_listing_expiry_date()` → `job_listing_get_or_create_expiry_date()` → `update_post_meta()` για κάθε row χωρίς expiry. 50 listings = 50 DB writes σε κάθε admin list load. Αντικαταστάθηκε με απευθείας `get_post_meta()` read.
- **Bug Fix** (`modules/form/jbli-form-template.php`): `job_salary` και `job_type` δεν υπήρχαν στη φόρμα — ο processor διάβαζε και αποθήκευε πάντα κενές τιμές. Προστέθηκαν `<select>` για Αμοιβή και Τύπο Απασχόλησης.

**🟡 Σημαντικά**
- **Perf** (`modules/recent/jbli-recent-jbli-listings.php`): Per-post `wp_get_post_terms()` → `job_listing_bulk_fetch_terms()` (2 queries για N listings).
- **Perf** (`modules/admin/jbli-admin-panel-template.php`): Ίδιο — per-post term queries → bulk fetch.
- **Perf** (`includes/jbli-rocket-compat.php`): `job_listing_rocket_purge_plugin_pages()` έκανε 3x `get_page_by_path()` DB queries σε κάθε purge event. Τώρα χρησιμοποιεί τα cached transients `jbli_listings_page_url` / `jbli_form_page_url` με fallback.
- **SEO** (`modules/single/jbli-single-template.php`): Προστέθηκαν hidden `<meta>` schema.org properties: `datePosted`, `validThrough`, `employmentType`, `jobLocation`. Απαιτούνται από Google Job Search.
- **Correctness** (`includes/jbli-helpers.php`): `job_listing_build_post_slug()` περνούσε hardcoded `'publish'` ως post_status — τώρα διαβάζει το πραγματικό status του post.
- **Reliability** (`modules/dashboard/jbli-dashboard.php`): Προστέθηκε `admin_post_nopriv_job_listing_dash_action` hook — un-logged users φτάνουν πλέον στον handler και γίνονται redirect στο login gracefully.

**🟢 Cleanup**
- **Perf** (`includes/jbli-storage.php`): `sync_listing_storage()` snapshot έκανε 6 `get_post_meta()` calls → τώρα 1.
- **Cleanup** (`modules/listings/jbli-listings.php`): `parse_filters()` χρησιμοποιούσε `term_exists()` (DB query) για validation → τώρα ελέγχει έναντι του `job_listing_get_terms()` που είναι cached.
- **Cleanup** (`includes/jbli-no-cache-headers.php`): `get_queried_object_id()` + `get_post()` → `get_queried_object()` (ένα call).
- **Docs** (`includes/jbli-helpers.php`): `get_or_create_expiry_date()` τεκμηριωμένο ως side-effect function. `sanitize_lat/lng` σημειωμένα ως reserved.

### v9.9.11
- **Bug Fix** (`modules/listings/jbli-listings-template.php`): Το bulk term optimization του v9.9.10 έπιανε μόνο το AJAX filtering. Στο initial page render, το template καλούσε ακόμα `get_card_data(get_the_ID())` χωρίς terms — άρα γίνονταν 2 queries ανά post. Προστέθηκε `bulk_fetch_terms()` και πέρασμα `$jbli_terms_map` στο loop, ίδιο pattern με το `render_cards_html()`.
- **Cleanup** (`includes/jbli-featured.php`): `job_listing_featured_invalidate_cache()` απλοποιήθηκε — αφαιρέθηκε άχρηστο `static $ref = null` και παραπλανητικά comments. Η function κάνει ένα πράγμα: `wp_cache_delete('jbli_featured_' . absint($post_id), 'job-listings')`.

### v9.9.10
- **Bug Fix** (`includes/jbli-view-counter.php`): `get_views()` με static cache έπαιρνε stale τιμή μετά από `track_view()`. Λύση: νέο `$force_refresh` parameter — `track_view()` καλεί `get_views($id, true)` που διαγράφει object cache, ξαναδιαβάζει από DB, και ενημερώνει **και** το static cache. Αφαιρέθηκε το άχρηστο `job_listing_views_set_cache()` helper.
- **Bug Fix** (`modules/listings/jbli-listings.php`): Το `bulk_fetch_terms()` δεν εξοικονομούσε queries γιατί το `get_card_data()` έκανε ακόμα `wp_get_post_terms()` ανά post πριν το overwrite. Λύση: `get_card_data()` δέχεται τώρα `array $terms = array()` — όταν περαστούν terms, παραλείπει τα per-post queries εντελώς. Το render loop περνά `$terms_map[$id]` απευθείας.
- **Cleanup** (`modules/recent/jbli-recent-jbli-listings.php`): `jbli_form_page_url` transient καθαρίζεται μαζί με `jbli_listings_page_url` στο save/delete/trash page.
- **Cleanup** (`includes/jbli-assets.php`): `should_enqueue_assets()` χρησιμοποιεί τώρα `get_queried_object()` με fallback σε `get_post()` — πιο αξιόπιστο όταν shortcode εμφανίζεται εκτός main query.

### v9.9.9
Βελτιώσεις από πλήρη ανάγνωση κάθε αρχείου.

- **Perf** (`modules/form/jbli-form.php`): `get_user_meta($user_id)` φόρτωνε **όλα** τα user meta. Αντικαταστάθηκε με per-key calls — γλιτώνει memory σε sites με WooCommerce billing meta.
- **Bug** (`includes/jbli-view-counter.php`): Static cache στο `get_views()` δεν ενημερωνόταν μετά το `track_view()`. Νέος `job_listing_views_set_cache()` helper + object cache priming. Same-request calls τώρα επιστρέφουν updated count.
- **Bug** (`includes/jbli-featured.php`): Static cache στο `is_featured()` δεν καθαριζόταν μετά από toggle. Νέος `job_listing_featured_invalidate_cache()` + wp_cache sentinel pattern.
- **Perf** (`includes/jbli-storage.php`): `get_or_create_pharmacy_id()` έκανε πάντα `UPDATE` ακόμα και αν name/email δεν είχαν αλλάξει. Τώρα κάνει `SELECT` για σύγκριση πρώτα.
- **Reliability** (`includes/jbli-storage.php`): `backfill_storage()` τώρα καλεί `set_time_limit(300)` + `wp_raise_memory_limit('admin')` — αποτρέπει timeout σε μεγάλα installs.
- **UX** (`modules/single/jbli-single-template.php`): Ο ιδιοκτήτης ληγμένης/draft αγγελίας τώρα redirect στο dashboard με notice αντί για silent redirect στο homepage.
- **Perf** (`modules/dashboard/jbli-dashboard.php`): `get_page_by_path('nea-aggelia')` γινόταν σε κάθε dashboard render. Τώρα cached σε transient 12h μέσω `job_listing_get_form_page_url()`.
- **Perf** (`modules/listings/jbli-listings.php`): Νέος `job_listing_bulk_fetch_terms()` — φέρνει taxonomy terms για όλα τα posts της σελίδας με 2 queries αντί για 2×N (24 queries → 2 για 12 posts).
- **Cleanup** (`includes/jbli-helpers.php`): `current_time('U')` (deprecated WP 5.3) → `time()`. Νέο `job_listing_ensure_expiry_date()` alias για πιο περιγραφικό naming.
- **Security** (`includes/jbli-post-type.php`): `can_export => false` — αγγελίες περιέχουν PII (email/phone).
- **Perf** (`job-jbli-listings.php`): `check_version()` με `static $ran` guard — δεν τρέχει πολλές φορές ανά request σε edge cases.
- **Cleanup** (`modules/admin/jbli-admin-panel.php`): `mb_strlen`/`mb_substr` → `job_listing_strlen`/`job_listing_substr` helpers.
- **Cleanup** (`jbli-uninstall.php`): Προστέθηκε `jbli_form_page_url` transient στον καθαρισμό.

### v9.9.8
Βρέθηκαν με πλήρη ανάγνωση κάθε αρχείου.

- **Fix 1** (`job-jbli-listings.php`): `job_listing_schedule_cron()` καλείται πλέον και στο activation hook. `flush_rewrite_rules()` → `flush_rewrite_rules(false)` (soft flush).
- **Fix 2** (`includes/jbli-expiry.php`): `current_time('timestamp', true)` → `time()` στο scheduling cron. Η `current_time` με timestamp ήταν deprecated από WP 5.3.
- **Fix 3** (`includes/jbli-capabilities.php`): `job_listing_block_admin_edit_access()` ελέγχει τώρα `$_GET['action'] === 'edit'` πριν κάνει `get_post()`. Σε κάθε admin request γλιτώνει DB call.
- **Fix 4** (`includes/jbli-post-type.php`): `show_in_rest => false`. Το REST API δεν χρειάζεται — το plugin έχει custom frontend. Επίσης taxonomies.
- **Fix 5** (`includes/jbli-post-type.php`): Αφαιρέθηκε το `revisions` από `supports`. Μειώνει rows στον `wp_posts`.
- **Fix 6** (`includes/jbli-post-type.php`): `exclude_from_search => true`. Οι αγγελίες εμφανίζονται μόνο μέσω `[listings]`, όχι στο native WP search.
- **Fix 7** (`includes/jbli-expiry.php`): Reminder email: lock (`update_post_meta`) γίνεται **πριν** την αποστολή. Αν το `wp_mail()` αποτύχει (`false`), το lock αφαιρείται για retry στο επόμενο cron. Η `send_reminder_email()` επιστρέφει τώρα `bool`.
- **Fix 8** (`includes/jbli-view-counter.php`): Νέος helper `job_listing_get_client_ip()` — ελέγχει `CF-Connecting-IP`, `X-Real-IP`, `X-Forwarded-For`, `REMOTE_ADDR`. Λύνει το πρόβλημα με Cloudflare όπου όλοι είχαν το ίδιο hash.
- **Fix 9** (`includes/jbli-view-counter.php`): Ο ιδιοκτήτης της αγγελίας (post_author) δεν μετράει πλέον ως view.
- **Fix 10** (`includes/jbli-storage.php`): Αφαιρέθηκε το `transition_post_status` sync hook — προκαλούσε διπλό `sync_listing_storage()` σε κάθε save. Το `save_post_{CPT}` καλύπτει όλες τις περιπτώσεις.
- **Fix 11** (`modules/listings/jbli-listings.php`): `posts_per_page` δεν είναι πλέον hardcoded. Υποστηρίζει `[listings per_page="24"]` και `apply_filters('job_listing_listings_per_page', 12)`.
- **Fix 12** (`modules/recent/jbli-recent-jbli-listings.php`): `featured_first` χρησιμοποιεί τώρα `meta_query` με `EXISTS` αντί για `meta_key` — αγγελίες χωρίς `_job_featured` δεν αποκλείονται πλέον από τη λίστα.
- **Fix 13** (`modules/recent/jbli-recent-jbli-listings.php`): `is_readable($card_path)` μεταφέρθηκε εκτός του loop — ένας έλεγχος αντί για N.
- **Fix 14** (`modules/recent/jbli-recent-jbli-listings.php`): Transient `jbli_listings_page_url` καθαρίζεται και στο `before_delete_post` και `trashed_post`, όχι μόνο στο `save_post`.
- **Fix 15** (`jbli-uninstall.php`): Προστέθηκε καθαρισμός `jbli_asset_bust` option, `jbli_listings_page_url` transient, και bulk delete όλων των `jbli_view_*` transients.

### v9.9.7
Βρέθηκαν με πλήρη ανάγνωση κώδικα.

- **Fix A** (`jbli-form-template.php`): Αφαιρέθηκε το `jbli_was_expired` hidden field — δεν χρησιμοποιείται πλέον από v9.9.5.
- **Fix B** (`includes/jbli-expiry.php`): Νέο `job_listing_deactivate_job()` helper — ολοκληρώνει το τρίπτυχο `renew/activate/deactivate`. Όλες οι status αλλαγές πλέον σε ένα αρχείο. Dashboard, admin-columns και admin-panel χρησιμοποιούν τον helper.
- **Fix C** (`modules/form/jbli-form.php`): `process_form_submit()` δεν κάνει πλέον redirect μόνη της. Για expired edits επιστρέφει `-(int)$post_id`. Ο handler αναγνωρίζει το αρνητικό αποτέλεσμα και κάνει το redirect με το warning notice.
- **Fix D** (`includes/jbli-assets.php`): `recent-listings` shortcode προστέθηκε στο `job_listing_asset_shortcodes()`. Χωρίς αυτό, οι σελίδες με `[recent-listings]` δεν φόρτωναν CSS/JS.
- **Fix E** (`jbli-admin-panel-template.php`): Προστέθηκαν τα `'backfilled'` και `'settings_saved'` στο `$done_msgs` map. Τα κουμπιά backfill και save settings εκτελούνταν σωστά αλλά δεν εμφάνιζαν success notice.
- **Fix F** (`includes/jbli-rocket-compat.php`): Προστέθηκαν purge hooks για `job_listing_activated` και `job_listing_deactivated`. Το WP Rocket cache δεν καθαριζόταν όταν μια αγγελία ενεργοποιούνταν ή απενεργοποιούνταν.

### v9.9.6
- **Feature** (`includes/jbli-expiry.php`): Νέο `job_listing_activate_job()` helper — ίδια δομή με `job_listing_renew_job()`. Το `wp_update_post()` τρέχει πρώτα· meta αλλάζουν μόνο σε επιτυχία. Επιστρέφει `true|WP_Error`.
- **Refactor** (`modules/dashboard/jbli-dashboard.php`): `case 'activate'` πλέον καλεί `job_listing_activate_job()`. Αφαιρέθηκε duplicated inline λογική.
- **Refactor** (`includes/jbli-admin-columns.php`): `job_listing_admin_activate_listing()` είναι πλέον thin wrapper γύρω από `job_listing_activate_job()` — η λογική σε ένα μέρος.
- **Refactor** (`modules/admin/jbli-admin-panel.php`): Non-expired `approve` πλέον καλεί `job_listing_activate_job()` αντί για inline `delete_meta` + `update_meta` + `wp_update_post`. Σωστή σειρά εγγυημένη από τον helper.
- **Docs** (`includes/jbli-storage.php`): Διορθώθηκε το `@return` docblock του `job_listing_backfill_storage()` — από `@return int` σε `@return array{success:bool,synced:int,error:string}`.

### v9.9.5
Bugs βρέθηκαν με πλήρη ανάγνωση κώδικα — δεν αναφέρθηκαν από εξωτερικό review.

- **Bug**: `jbli-expiry.php` — Διπλό docblock πάνω από `job_listing_renew_job()` (παλιό + νέο). Αφαιρέθηκε το παλιό.
- **Bug**: `jbli-form.php` — `get_post($edit_id)` καλούνταν 2 φορές στη `process_form_submit()`. Η δεύτερη (`$existing_post`) αντικαταστάθηκε με τη `$edit_post` που ήδη φορτώθηκε.
- **Bug**: `jbli-form.php` — Το `jbli_was_expired` hidden field διαβαζόταν ακόμα στον `adminpost_handler` και περνούσε στην `process_form_submit`, παρόλο που η συνάρτηση το αγνοεί. Αφαιρέθηκε εντελώς. Το expired-edit warning redirect μεταφέρθηκε μέσα στην `process_form_submit()` όπου γνωρίζει το πραγματικό `$was_expired`.
- **Bug**: `jbli-dashboard.php` — `case 'activate'` έκανε `delete_post_meta` + `update_post_meta` **πριν** το `wp_update_post()`. Αν το update απέτυχε, τα meta είχαν ήδη αλλαχτεί. Διορθώθηκε: `wp_update_post()` πρώτα, meta μόνο σε επιτυχία.
- **Bug**: `jbli-recent-jbli-listings.php` — `job_listing_recent_get_listings_page_url()` χρησιμοποιούσε `meta_key => '_job_listings_page'` που δεν υπάρχει σε καμία σελίδα, συνδυασμένο με `s => '[listings]'`. Αντικαταστάθηκε με απευθείας `LIKE` query στο `post_content`.
- **Cleanup**: `jbli-form.php` — Αφαιρέθηκε το τελευταίο "multi-account pharmacies" comment.

### v9.9.4
- **Fix #1** (`includes/jbli-expiry.php`): `job_listing_renew_job()` επιστρέφει πλέον `true|WP_Error` αντί για `void`. Το `wp_update_post()` τρέχει πρώτα — meta/expiry αλλάζουν **μόνο αν πετύχει**.
- **Fix #2** (`modules/admin/jbli-admin-panel.php`): `approve` και `renew` ελέγχουν το αποτέλεσμα του `job_listing_renew_job()` και καλούν `wp_die()` αντί να εμφανίζουν ψεύτικο success.
- **Fix #3** (`modules/dashboard/jbli-dashboard.php`): `renew` ελέγχει αποτέλεσμα και κάνει redirect με error notice αν αποτύχει.
- **Fix #4** (`includes/jbli-admin-columns.php`): `job_listing_admin_activate_listing()` και `job_listing_admin_deactivate_listing()` επιστρέφουν `true|WP_Error`. Ο handler ελέγχει το αποτέλεσμα και καλεί `wp_die()` σε αποτυχία. Και οι δύο κάνουν `sync_listing_storage()` μετά την επιτυχία.
- **Fix #5** (`includes/jbli-storage.php`): `job_listing_backfill_storage()` τώρα αρχικοποιεί `_job_featured = 0` σε παλιές αγγελίες που δεν έχουν το meta. Αποτρέπει παλιές αγγελίες να "εξαφανίζονται" από τη public λίστα (query requires `EXISTS`).
- **Fix #6** (`includes/jbli-storage.php`): Αφαιρέθηκαν παραπλανητικά "multi-account pharmacy" comments. Η τρέχουσα υλοποίηση είναι 1 user = 1 pharmacy.
- **Fix #7** (`modules/form/jbli-form.php`): `was_expired` υπολογίζεται πλέον server-side από το DB (`post_status` + `_job_expired` meta). Το hidden field `jbli_was_expired` αγνοείται για edit submissions.
- **Fix #8** (`includes/jbli-storage.php` + `jbli-admin-panel.php`): `job_listing_backfill_storage()` επιστρέφει `array(success, synced, error)` αντί για plain int. Admin panel ελέγχει `success` πριν εμφανίσει επιτυχία.

### v9.9.3
- **CSS**: Αφαιρέθηκαν τα τελευταία 16 `!important` από το `jl.css`. Αντικαταστάθηκαν με `:link/:visited` pseudo-selectors σε `.jl-btn`, `.jl-card__title a`, `.jl-pagination .page-numbers`, `.jl-info-card__cta` και `.jl-info-card__phone/email`. Τα 9 που απομένουν είναι αποκλειστικά στο `.jl-sr-only` (WCAG accessibility utility — χρειάζεται `!important` by design).
- **Bug Fix**: `approve` στο admin panel πλέον κάνει πάντα reset το `_job_expires` σε +30 ημέρες (unconditional), ακριβώς όπως το `activate` και το dashboard renew. Προηγουμένως ένα approve σε αγγελία με παλιό expiry δεν ανανέωνε τη λήξη.

### v9.9.2
- **Bug Fix**: Κατά την επεξεργασία αγγελίας, το URL (slug) δεν ενημερωνόταν ποτέ γιατί το `wp_update_post` δεν έπαιρνε `post_name`. Τώρα το slug αναδημιουργείται αυτόματα από τον τίτλο σε κάθε αποθήκευση, με πλήρη ελληνική μεταγραφή (transliteration) και `wp_unique_post_slug()` για αποφυγή conflicts.
- **Feature**: Νέο helper `job_listing_greek_to_slug()` + transliteration map 50 χαρακτήρων (κεφαλαία / πεζά / τονισμένα). Αναγνώσιμα Latin slugs από Ελληνικούς τίτλους (π.χ. "Φαρμακοποιός Αθήνα" → `farmakopios-athina`).
- **Feature**: Νέο shortcode `[recent-listings]` — responsive grid για αρχική σελίδα. Attributes: `count` (default 6), `columns` (2 ή 3), `title`, `view_all_url`, `featured_first`. Auto-detect URL σελίδας [listings]. Επαναχρησιμοποιεί το υπάρχον `jbli-listing-card.php` partial.

### v9.9.1
- **Bug Fix**: `mb_strlen()` / `mb_substr()` χωρίς fallback στο `jbli-form.php` (γρ. 272) και `jbli-listings.php` (γρ. 29-30) αντικαταστάθηκαν με `job_listing_strlen()` / `job_listing_substr()` helpers (ορίζονται στο `jbli-helpers.php`). Αποτρέπει fatal error σε servers χωρίς το PHP `mbstring` extension.
- **Bug Fix**: Έλειπαν οι `case 'backfill_storage'` και `case 'save_settings'` από το `switch` στο `jbli-admin-panel.php`. Τα κουμπιά "Συγχρονισμός όλων των αγγελιών" και "Ζώνη Κινδύνου" ήταν στο whitelist αλλά δεν εκτελούσαν καμία λογική. Διορθώθηκε.
- **CSS**: Αφαιρέθηκαν ~190 περιττά `!important` από το `.jl-single` section. Κρατήθηκαν μόνο τα justified (link color/decoration overrides που χρειάζονται για να νικούν theme resets). Η οπτική εμφάνιση δεν αλλάζει.
- **Code Quality**: `$address` στο `jbli-single-template.php` σχολιάστηκε ως "Reserved for future use" — ποτέ δεν αποθηκευόταν από τη φόρμα (dead code).
- **Docs**: Διορθώθηκε το README — `capability_type` δεν είναι `'post'` αλλά `['job_listing', 'job_listings']` (custom caps). Ο κώδικας ήταν ήδη σωστός, μόνο το documentation ήταν λάθος.

### v9.9.0
- **Bug Fix**: `job_listing_count_active_listings_for_user` επέστρεφε πάντα 0 ή 1 λόγω `posts_per_page => 1`. Διορθώθηκε σε `$max_active + 1`.
- **Bug Fix**: Το `activate` action στο dashboard δεν ανανέωνε πάντα το `_job_expires`. Αγγελίες που είχαν λήξει επανενεργοποιούνταν και ξαναλήγαν από το cron μέσα σε λίγα λεπτά. Τώρα γίνεται `update_post_meta` πάντα.
- **Bug Fix**: Timezone inconsistency — `gmdate('Y-m-d')` στο `get_or_create_expiry_date` αντικαταστάθηκε με `wp_date('Y-m-d H:i:s')` για συνέπεια με `current_time('mysql')`.
- **Feature**: Όριο ενεργών αγγελιών τώρα μετράει ανά **pharmacy_id** (όχι ανά user). Σωστό για φαρμακεία με πολλαπλούς λογαριασμούς.
- **Feature**: Νέος composite index `pharmacy_id, status` στο `wpjbli_pharmacy_listings` για γρήγορα queries dashboard.
- **Feature**: Dashboard φορτώνει αγγελίες από το storage index (`job_listing_get_dashboard_listing_ids`) αντί απευθείας WP_Query. Υποστηρίζει έως 100 αγγελίες ανά φαρμακείο.
- **Feature**: Νέα action `backfill_storage` στο admin panel — κουμπί "Συγχρονισμός όλων των αγγελιών".
- **Feature**: Ρύθμιση "Ζώνης Κινδύνου" στο admin panel — επιλογή διαγραφής ΟΛΩΝ των δεδομένων κατά την απεγκατάσταση.
- **Feature**: `jbli-uninstall.php` υποστηρίζει πλέον πλήρη διαγραφή δεδομένων (posts, tables, meta, options) αν έχει ενεργοποιηθεί στις ρυθμίσεις.
- **Feature**: Orphan cleanup — εγγραφές στο `wpjbli_pharmacy_listings` διαγράφονται/ενημερώνονται αυτόματα όταν διαγράφεται ή μεταφέρεται στον κάδο μια αγγελία.
- **Refactor**: Αφαιρέθηκε η διπλή λογική `is_pharmacist` — `jbli-capabilities.php` τώρα χρησιμοποιεί `job_listing_pharmacist_account_types()` και `job_listing_pharmacist_roles()` από `jbli-helpers.php`.
- **Refactor**: Aggressive cache invalidation — `job_listing_flush_pharmacy_cache()` εκτελείται σε κάθε sync/delete/trash ώστε τα counts να είναι πάντα ακριβή.
- **i18n**: Όλα τα error/success messages τυλίχθηκαν σε `__()` για μεταφρασιμότητα.
- **Docs**: Συγχρονισμός έκδοσης README → 9.9.0.

### v9.8.0
- **Bug Fix**: Διορθώθηκε σφάλμα στο cache invalidation taxonomy terms (`md5` → `sanitize_key`) που έκανε τα cached terms να μη διαγράφονται σωστά μετά από προσθήκη/επεξεργασία.
- **Deprecation Fix**: Αντικατάσταση `current_time('timestamp')` (deprecated WP 5.3+) με `current_time('U')` σε `jbli-helpers.php` και `jbli-expiry.php`.
- **Code Quality**: Διόρθωση εσοχής (indentation) σε `sanitize_phone()` και `get_or_create_expiry_date()`.
- **Docs**: Ενημέρωση `@since` tags και version header.

### v9.7.9
- Hard UI fix: added dedicated single-page wrapper spacing below the site header.
- Added compact form wrapper and stricter CSS for smaller submit/edit form UI.
- Updated plugin/CSS version to force browser cache refresh.

### v9.7.8
- Διορθώθηκαν πιο επιθετικά τα βελάκια των dropdown ώστε να μην μπαίνουν πάνω στο ελληνικό κείμενο.
- Έγινε πιο compact η πλήρης προβολή αγγελίας με μικρότερο hero, cards, paddings και spacing.

### v9.7.1
- **Data Safety**: Το uninstall δεν διαγράφει πλέον αγγελίες, meta, options ή custom tables.
- **Pro Storage Layer**: Προστέθηκαν custom tables `wpjbli_pharmacies` και `wpjbli_pharmacy_listings` για ξεχωριστό normalized χώρο δεδομένων ανά φαρμακείο, χωρίς να σπάει η συμβατότητα με CPT/SEO/permalinks.
- **Migration**: Σε activation/update γίνεται ασφαλές backfill υπαρχουσών αγγελιών στο νέο storage index.
- **Performance**: Ο έλεγχος ορίου ενεργών αγγελιών μπορεί να χρησιμοποιεί το νέο indexed storage.

### v9.0.0
- **Security**: Fix XSS — `$_SERVER['REQUEST_URI']` → `get_permalink()`
- **Performance**: Admin stats από 4× WP_Query → 1× `wp_count_posts()`
- **Emails**: HTML emails με responsive template + proper `From:` header
- **Emails**: Daily reminder 3 μέρες πριν λήξη (με flag αποτροπής διπλοαποστολής)
- **AJAX**: Φίλτρα χωρίς page reload + debounced search + URL history
- **JS**: Μετάβαση από jQuery σε Vanilla JS
- **Featured**: Admin μπορεί να επισημαίνει αγγελίες (εμφανίζονται πρώτες)
- **Views**: View counter με IP-hash deduplication

### v8.0.0
- `capability_type => ['job_listing', 'job_listings']` — custom capabilities, Rank Math / Yoast compatible
- `show_in_rest => true` — Gutenberg support
- Shortcodes: `[listings]` `[new-listing]` `[dashboard]`
- Καθαρό rename: όλα τα `pnjl_` → `job_listing_` / `jbli_`
- Batch limit στο expiry cron (50 posts ανά run)