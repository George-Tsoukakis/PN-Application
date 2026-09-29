=== QR ReBuilder Pro ===

Contributors: PharmacyNeeds
Tags: gs1, datamatrix, barcode, pharmacy, scanner
Requires at least: 6.1
Requires PHP: 8.2
Tested up to: 7.1
Stable tag: 2.15.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scan, parse and rebuild GS1 DataMatrix codes. Extracts GTIN, Serial Number, LOT and Expiry, then generates a new valid symbol.

== Description ==

QR ReBuilder Pro is a WordPress tool for scanning, parsing, validating and rebuilding GS1 DataMatrix codes.

It is designed primarily for pharmaceutical packs and supports the four Application Identifiers those packs carry:

* AI 01 - GTIN / Product Code
* AI 21 - Serial Number
* AI 10 - Batch / Lot Number
* AI 17 - Expiration Date

Data can be entered with an external barcode scanner (keyboard wedge) or typed and pasted by hand. After parsing, the user can review and correct every field before a new GS1 DataMatrix is generated.

Only the server builds the authoritative output payload and the DataMatrix image, from validated data. The browser may reconstruct the same GS1 element string locally, but purely as a consistency check: it compares its own reconstruction against the one the server returned and refuses the result if the two disagree. Browser-computed data is never used as the authoritative output.

= Main features =

* Scanning of GS1 data with an external scanner.
* Manual entry of raw GS1 data.
* Extraction of GTIN, Serial Number, LOT and expiry date.
* Support for a real ASCII 29 Group Separator.
* Support for visible separator forms such as [GS].
* Support for GS1 HRI format with Application Identifiers in brackets.
* Support for scanner symbology identifiers.
* GS1 GTIN check digit validation.
* Server-side validation of every field.
* Server-side generation of the authoritative GS1 raw element string.
* Recognition of additional GS1 Application Identifiers present in the same code - all 541 AIs of the official GS1 Barcode Syntax Dictionary are covered. Extra fields proven from the original scan are carried unchanged into the rebuilt code.
* Detection of ambiguous or incomplete scans.
* Strict-by-default ambiguity handling: when separators are missing, any complete competing reading requires manual confirmation; the older content-prior policy remains available through the `qrrp_strict_ambiguity` filter.
* Detection and repair of a scan made with a Greek keyboard layout.
* Protection against character duplication during live scanning.
* Verification that the produced symbol really is a GS1 DataMatrix (leading FNC1).
* Server-side generation of GS1 DataMatrix ECC200 through tc-lib-barcode.
* PNG output with a solid white background, suitable for label printers.
* Printing of the GS1 DataMatrix together with its data.
* Copying of the raw GS1 data.
* Sending of the GS1 DataMatrix and its data by email.
* Capability-based access control.
* Optional guest access, disabled by default.
* Separate optional switch for guest email.
* AJAX nonce protection.
* Rate limiting on the AJAX endpoints.
* Validation and size limits on all incoming data.
* No subscription system and no usage limits.

== Shortcode ==

To show the tool on a page or post, use:

[qr_rebuilder_pro]

== Installation ==

1. Upload the `qr-rebuilder-pro` folder to `/wp-content/plugins/`.
2. Activate the plugin from WordPress Admin > Plugins.
3. Open the QR ReBuilder Pro settings and choose the access options you need.
4. Create or edit a WordPress page.
5. Add the shortcode:

[qr_rebuilder_pro]

6. Save the page and open it on the front end.

The server needs PHP 8.2 or later with the GD extension enabled. Tools > Site Health reports the exact cause if either requirement is missing.

== Usage ==

= Scanning =

Click the external scanner field and scan the GS1 DataMatrix. The plugin sends the raw data to the server for parsing and validation.

= Manual entry =

Alternatively choose manual entry and paste the raw GS1 data. Both a real ASCII 29 Group Separator and the visible [GS] representation are accepted.

= Creating a code from scratch =

"Manual entry" also opens the four fields - PC, SN, LOT and EXP - empty, so a pharmacist can type them from the printed text on the pack (for example when the 2D code is damaged and cannot be scanned) and create a new GS1 DataMatrix without any scan.

Before anything is generated the server asks for an explicit declaration that the four values were read from the pack itself. The declaration is bound to the exact four values: changing any of them requires a new one. The result is labelled as a manual reconstruction, never as a scan. Every other server-side check still applies (GTIN check digit, date, character set, expiry confirmation). Logged-in users can always use it. Guests can use it when guest access is enabled and the setting "Manual creation by guests" is on (the default since 2.13.2); switching that setting off limits manual creation to logged-in users.

= Reviewing the fields =

After parsing, the following are shown:

* PC / GTIN
* SN / Serial Number
* LOT / Batch Number
* EXP / Expiration Date

If the parser had to infer the end of a variable-length field - which is common, because many scanners do not transmit the Group Separator - a note appears with the values it determined.

When the reading is unique and fully valid, the flow continues without a mandatory confirmation. When the Group Separator is missing and the parser finds any complete competing interpretation, the default strict ambiguity policy requires manual confirmation. This strict check adds no extra confirmation when real ASCII 29 separators are present. Sites that deliberately prefer the older content-prior policy can return `false` from the `qrrp_strict_ambiguity` filter. A missing field, a failed GTIN check digit, or a scan repaired from a Greek keyboard layout can also require manual confirmation.

An expired pack is a separate case. The reading may be perfectly certain while the product itself has expired, so that situation has its own explicit confirmation, and the rule is enforced on the server as well.

= Generating the GS1 DataMatrix =

After reviewing the fields, press the generate button. The data is validated again on the server, the authoritative GS1 raw element string is built there, and the GS1 DataMatrix ECC200 image is returned to the front end.

= Email =

When email is allowed for the current user, the message can include:

* The PNG image, with a solid white background.
* PC / GTIN.
* Serial Number.
* LOT.
* Expiration date.
* The raw GS1 data.
* Optional customer details and print date.

Email uses the WordPress `wp_mail()` function and therefore depends on the email or SMTP configuration of the site.

== GS1 Date Handling ==

AI 17 stores the expiry date as YYMMDD.

QR ReBuilder Pro resolves the two-digit year with a rolling century window around the current year, and refuses any year that could not survive that round trip.

A day of `00` (YYMM00, "valid until the end of the month") is kept as `00` in the rebuilt code and needs no confirmation; for the expiry check it counts as the last day of that month. Sites that prefer the older behaviour (suggest the last day and ask for confirmation) can return `false` from the `qrrp_preserve_expiry_day_zero` filter.

== Security ==

QR ReBuilder Pro applies several layers of control:

* WordPress nonce verification on AJAX requests.
* Capability checks for authenticated users. The access setting offers "Free for everyone", "Logged-in users only", "Pharmacists only" and "Administrators only".
* "Pharmacists only" means administrators plus accounts whose registration category is "Φαρμακείο" (Pharmacy). That category is self-declared by the user at registration; sites that verify pharmacies elsewhere can plug their own check into the `qrrp_is_pharmacist` filter (arguments: `bool $is`, `int $user_id`).
* A separate permission check for email. A missing email-permission setting defaults to pharmacists only (`qrrp_pharmacist`); an already saved value, including the explicit empty value meaning "same as the tool capability", is preserved.
* Optional guest access, disabled by default.
* A separate optional switch for guest email.
* Server-side GS1 validation.
* GTIN check digit validation.
* Server-side canonical GS1 rebuild.
* Consistency checks between the submitted fields and the raw data.
* Rate limiting.
* Size limits on raw input.
* Signature and dimension checks on the generated image.
* Temporary email attachments with a random name that never contains the Serial Number, permissions 0600 set before the bytes are written, and deletion after sending.
* Rebuild links in email carry an opaque token only. The token stays valid until a rebuild completes successfully, and is invalidated at that point. It is deliberately not invalidated merely by opening the link, so that automated link checks performed by mail gateways cannot consume it before the recipient does.
* Verification that the symbol was encoded as a GS1 DataMatrix, with a leading FNC1, before it is returned. `WP_DEBUG` alone can never bypass this verification; any diagnostic override requires the explicit `QRRP_ALLOW_UNVERIFIED_GS1` constant.

== Frequently Asked Questions ==

= Does it create a QR Code or a GS1 DataMatrix? =

It creates a GS1 DataMatrix ECC200. QR ReBuilder Pro is the product name, but the symbol the tool produces is a GS1 DataMatrix.

= Which GS1 Application Identifiers are supported? =

The plugin extracts and rebuilds AI 01 (GTIN), 21 (Serial Number), 10 (LOT) and 17 (Expiration Date).

Since version 2.5.0 it also recognises every other Application Identifier in the official GS1 Barcode Syntax Dictionary, so that codes carrying additional fields are parsed correctly instead of being rejected. Since version 2.15.1 those extra fields are carried into the new code, unchanged and in canonical order, when the server can prove them from the original scan. A scan whose extra fields cannot be proven is refused rather than rebuilt without them, so no field of the pack is silently dropped.

= Can guests use the tool? =

Yes, but only if that option is explicitly enabled in the settings. Guest access is disabled by default.

= Can guests send email? =

Guest email is a separate option and is also disabled by default. Guests can only send to the domains you list (normally only your pharmacy's own domain); public email services such as gmail.com or outlook.com are not accepted in that list.

= Can guests create a code by typing the fields? =

Only if you enable "Manual creation by guests". Since 2.15.5 it is off by default: guests can rebuild what they scan, but typing PC, SN, LOT and EXP without a scan is reserved for logged-in users.

= Is an external scanner required? =

No. An external scanner can be used, or the raw GS1 data can be entered by hand.

= Is there a usage limit or a subscription? =

No. Since version 1.7.0 the tool has no subscription system, no Pro access and no monthly quota.

= Does the plugin store the scanned data? =

The plugin keeps no history of scans or generated labels. Pack data (PC, SN, LOT, EXP) is stored in plain form only in one case, email links, described below. What is written to the database:

* **Monthly usage counters.** One option holds, per month, aggregate numbers only: codes generated, GS1 rejections and how hard reads were (for example how many had no separators or needed confirmation). It never holds pack data, users, IP addresses or times. The two public numbers are also written to a small static file, `uploads/qrrp/usage.json`.
* **Rate-limit entries.** Counters that expire with their window: 10 to 15 minutes for most actions, 24 hours for the per-user daily email limit and the per-recipient email limits. Each holds a counter under a key that is an MD5 hash of the action and the actor: the user ID for logged-in users, or a keyed hash (HMAC-SHA-256 with the site salt) of the IP address for guests. Per-recipient email limits use a keyed hash of the normalised recipient address (combined with the user ID for logged-in senders) instead. Neither the IP address nor the email address is ever stored. Entries left behind are removed by the hourly cleanup after at most 2 days.
* **Short-lived confirmation tokens (15 minutes).** While you review and confirm a reading, the server keeps tokens that bind the confirmation to the exact data. They hold keyed fingerprints (HMAC-SHA-256) of the field values and of the raw scan, never the values themselves, so no plaintext Serial Number is stored.
* **Email rebuild links.** When you email a code, the rebuild link in that email needs the fields to still exist when the recipient clicks it, so PC, SN, LOT and EXP are held on the server under a random 128-bit token — never in the link itself. Only a SHA-256 hash of that token is stored, so the stored record cannot be turned back into a working link. The record is deleted as soon as the link is used to rebuild a code, and expires on its own after at most 7 days by default (filter `qrrp_rebuild_token_ttl`; 1 hour to 30 days). The plugin keeps at most 500 live rebuild links at a time. When that store is full, a new email is sent without a rebuild link; links already sent are never retired early.

Uninstalling the plugin removes all of the above, including entries kept in an external object cache such as Redis when they can be located.

= Does the plugin connect to external services? =

Only in one case: when an administrator presses "Check for a new version" in the "DataMatrix library" card of the settings. The plugin then makes an HTTPS request to the public PHP package repository (repo.packagist.org) to read the newest version numbers of tc-lib-barcode and tc-lib-color. No data about the site, its users or its scans is sent. Nothing is downloaded or installed. At no other time does the plugin connect to any outside service.

= Does the plugin support WordPress Multisite? =

It has not been tested on WordPress Multisite, and network-wide activation is not supported: activate it per site. Activation and upgrade routines act on the current site only. Deleting the plugin runs the uninstall routine for every site of the network.

= What label size does printing work with? =

The layout was designed and tested on Zebra-type labels of about 100 × 47 mm, with the code on the left and the fields on the right, in one row.

When the label is narrower than the code plus about 45 mm (about 70 mm with the default 24 mm code), the layout stacks: code on top, fields below. This is deliberate — there is no room for two readable columns — but it needs **much more height**. Measured in the preview tool with the code at 24 mm:

* in a row, 100 mm wide: about 45 mm high with the note enabled
* stacked, narrower than 70 mm: about 76 mm high, even without the note and the fields

In practice small labels, for example 54 × 25 mm, cannot fit the content with any setting. The height is set by the text, not by the code: the GS1 line is about 60 characters and wraps over several lines in a narrow column, so making the code smaller does not help.

If your label is borderline, the two settings that save height are "Show note" and "Show fields" («Εμφάνιση σημείωσης», «Εμφάνιση στοιχείων»). If the label is still too small, the code is shrunk to fit, but never below the minimum printable size for GS1 DataMatrix (a floor of about 14 mm with the default settings); below that it overflows visibly instead of printing unreadably small.

== Changelog ==

= 2.15.6 =

* Settings: new "DataMatrix library" card with a "Check for a new version" button. It shows the bundled tc-lib-barcode and tc-lib-color versions and, on request, the newest stable release of each from the public PHP package repository (repo.packagist.org), with a link to what changed. It only reports; it never installs anything, because every library upgrade goes through the checked procedure in `vendor/QRRP-VENDOR-NOTES.md` and a new plugin release.
* This is the plugin's only outbound connection, made only when an administrator presses the button. Nothing about the site is sent (not even its URL in the User-Agent). The last result is kept in one non-autoloaded option, removed on uninstall.

= 2.15.5 =

**Security-defaults release from a code review: stricter email and guest defaults, updated barcode library. No change to parsing (4,021-input comparison identical).**

* Email by logged-in users: new abuse limits, because "Pharmacist" can be a self-declared registration category. At most 50 emails per user per 24 hours (filter `qrrp_user_email_daily_limit`) and at most 10 from the same user to the same address per 24 hours (filter `qrrp_user_email_per_recipient_limit`). The per-address limit is counted per sender, so one account cannot block an address for everyone else; many accounts together are still capped by the site-wide email quota. Administrators are exempt (filter `qrrp_user_email_limits_exempt`).
* Guest email: public email services (gmail.com, outlook.com, yahoo, icloud and others; filter `qrrp_webmail_domains`) are no longer accepted in "Guest email: allowed domains", because they would let any visitor send email from your site to all of their users. Such entries already saved are ignored and flagged in the settings. Filter `qrrp_guest_email_allow_webmail` restores the old behaviour.
* Manual creation by guests is now off by default. On update it is switched off only on sites where guest access is off (so nothing changes for sites that already use guest access).
* DataMatrix: bundled tc-lib-barcode updated to 2.16.4 and tc-lib-color to 3.0.7. The old version wrote the pad codewords of the symbol with an off-by-one position (ISO/IEC 16022 §5.2.3). Labels still scanned correctly, because scanners ignore padding, but the symbol was not strictly conformant. Every symbol is still verified by the plugin's own decoder before it is shown.
* Documentation: corrected the readme where it no longer matched the code (extra AIs are kept in the rebuilt code, day `00` needs no confirmation, the printed code can shrink down to a minimum size, rate-limit and token storage details).
* Tests (outside the package): `t_rebuild` now fails when the rebuild fails (it could pass before); `run-all.sh` fails a script that prints no PASS or exits non-zero; new `t_access_2155`, `t_admin_webmail` and a pad-codeword check in `t_datamatrix_gd`.

= 2.15.4 =

**Small maintenance release: storage cleanup, guest-email policy and test infrastructure. No change to parsing, DataMatrix or the main tool.**

* Storage: expired short-lived tokens (confirmations, output proofs) are now also removed by the plugin's hourly cleanup. Before, only WordPress's daily cron removed them, so on sites with WP-Cron disabled they piled up in `wp_options`.
* Guest email: at most 3 emails per recipient per 24 hours (was per hour). Filters `qrrp_guest_email_per_recipient_limit` and `qrrp_guest_email_per_recipient_window` (1–24 h) adjust it.
* Guest email: with an empty domain list, guest email is off (form hidden and the server refuses with `guest_email_disabled`), even if a `qrrp_guest_email_recipient_allowed` filter exists. **If your site uses that filter to allow recipients without a domain list, guest sending stops after this update until you also return `true` from the new `qrrp_guest_email_open_recipients` filter.**
* Settings: invalid entries in "Guest email: allowed domains" (or more than 50) are listed in a notice instead of being dropped silently.
* Settings: the "Pharmacists only" warning can be hidden per administrator ("Hide"). It changes only what is shown; access control is unchanged.
* Tests (outside the package): logic tests run without GD using a stand-in renderer; real DataMatrix tests are reported as SKIP when GD is missing instead of FAIL; new long-running storage tests on a real SQL engine (30 simulated days); full suite verified on PHP 8.3 and 8.4, with and without GD.

= 2.15.3 =

**Hardening release: guests, email, labels and scan reading.**

* Labels: a new scan now clears the previous pack's code from the screen before the request is sent, so a failed or slow scan can never leave the old code printable.
* Guests: anything created by a guest is labelled "User-declared" in the summary, on the printed label and in the email, because the server cannot prove that a guest's "scan" came from a real scanner. With "Manual creation by guests" off, guests can no longer change values away from what the scan contains either.
* Guest email is now deny-by-default: guests can only send to domains listed in the new setting "Guest email: allowed domains" (empty = none), at most 3 emails per hour per recipient (plus-addresses count as the same recipient). The site-wide guest caps are checked before any per-IP counter is written. Guest emails no longer contain a prefill link.
* Email links: when the link store is full, a new email goes out without a link instead of invalidating links that were already sent.
* Expiry with day 00 (YYMM00) is kept as 00 in the rebuilt code (valid until the end of the month) instead of being rewritten to the last day. Filter `qrrp_preserve_expiry_day_zero` (return false) restores the old behaviour.
* Greek keyboard layout: Σ can be S or W, so the affected field is now offered as a choice instead of being silently read as S.
* HRI with parentheses: an AI marker that could be part of a value (for example `(21)AB(90)CD`) now requires confirmation instead of being split silently.
* Pages served from a cache after their security token expired now fetch a fresh token and retry once, instead of asking for a hard refresh.
* Scanners that send Enter+Tab no longer move focus away from the scanner field; focus returns to it after every scan.
* Screen readers: status and error messages are announced reliably (errors as alerts).
* Settings: a warning appears when "Pharmacists only" relies on open, self-declared registration. Use the `qrrp_is_pharmacist` filter to link it to verified accounts.
* Filter change: `qrrp_guest_email_recipient_allowed` now receives the domain-list decision (not `true`) and the lowercased address.

= 2.15.2 =

**Security and correctness release from an independent code review.**

* Provenance: when the parser has one clear reading of a scan (for example SN `ABC24012`) and a different admissible reading is submitted (SN `ABC`, which assumes a missing separator), the request is now treated as a manual change: it needs the explicit confirmation step, is recorded as a change of that field, and extra AIs are taken from the parser's reading. Before, it passed as a plain scan and could add an AI (for example 240) that no standard decoder reads from the pack. A client-declared baseline no longer overrides the parser's clear reading.
* Email: single-use handles (email-link token, confirmation) are consumed before the message is sent, so two simultaneous requests with the same link can no longer send two emails. If SMTP then fails, the user rebuilds the code and sends again. The usual path (after a rebuild) is unchanged.
* Performance: the GS1 search now also budgets the candidate lengths it tries, and repeated searches of the same scan within one request are cached. A crafted 4 KB input dropped from about 2.7 s to about 0.12 s of CPU per rebuild. Results for real scans are unchanged (4,021-input comparison).
* Email attachments: the temporary PNG is kept after a successful hand-off to `wp_mail()`, so queued SMTP plugins still find it, and is removed by a sweep about an hour later (WP-Cron, rescheduled while files remain) or on uninstall. Failed sends still delete it immediately.
* Manual changes are now labelled in the result summary, on the printed label and in the email (for example "Manual change: SN").
* Pages opened with `?qrrp_token` send `Referrer-Policy: no-referrer` and `noindex`.
* Settings: notices no longer land inside the header, fields have proper labels, and an unpublished tool page is shown and kept instead of being reset silently on the next save.
* Login and contact buttons use the page permalink (works with plain permalinks and WPML/Polylang).
* Primary button contrast meets WCAG AA (at least 4.5:1).

= 2.15.1 =

**Correctness fix: the rebuilt code no longer loses an extra AI of the pack.**

* An extra AI (for example 240) that a theoretical alternative reading overshadowed (such as `240ABC4032` read as `240=ABC` + `403=2`) was silently dropped, and the code was built without it. When the scan has real Group Separators, the strict reading (no inferred boundary) now decides, which is how every standard GS1 decoder reads the symbol, and the field is kept.
* When extra AIs cannot be proven safely, rebuild and email are refused with a 409 `extras_unprovable` that names the AIs. An incomplete code is never produced without a clear notice.
* The `qrrp_token` of the email link is removed from the address bar once the data is loaded.
* Parser comment corrected; new regression script for the AI 240 case (kept outside the package, like the other tests).

= 2.15.0 =

**Quality and security release. Fixes every issue found in a full code review of 2.14.4.**

GS1 parsing and building:

* Extra AIs with a fixed length that is not GS1 "predefined" (for example 7003, 8005, 422) are now followed by a separator when the code is built. Before, the generated element string was not conformant and could be misread by strict scanners.
* A separator typed as the text `<GS>` is read as a separator, but the tool always asks for confirmation. Before, it was accepted into the LOT without warning.
* `]C1`, `]e0` and `]J1` are recognised as GS1 symbology identifiers. An identifier followed directly by a separator, `]:3` from a Greek keyboard, a trailing `[GS]`, NBSP and zero-width characters are handled.
* A duplicate AI with two different values is no longer resolved silently. An invalid expiry date is returned empty, and malformed HRI gets a clear error.

DataMatrix:

* Every symbol is verified from the final PNG: module grid, finder pattern, Reed-Solomon, leading FNC1 and the decoded payload. If the check cannot run, nothing is produced.
* Input is limited to the DataMatrix capacity and the GS1 character set. Oversized images are rejected before rendering. Output always comes from GD.

Security and robustness:

* Guest rate limits group IPv6 addresses by /64 and add site-wide caps for parse and rebuild. Expired rate-limit rows are cleaned up fully, every hour.
* Short-lived confirmation tokens no longer share the email-link index, so a flood cannot evict other users' tokens. Single-use tokens are consumed atomically.
* Guest emails no longer include the free-text customer name. A new `qrrp_guest_email_recipient_allowed` filter can restrict recipients.
* Settings sanitizers only read form data on this plugin's settings save.
* Email links only resolve email tokens.

WordPress integration:

* Translations in `/languages` now load. A `.pot` template is included.
* The access migration only runs once, when upgrading from a version below 2.14.1. The default email permission is "Pharmacists only".
* The tool no longer disappears when an SEO or page-builder plugin runs the shortcode early. Its stylesheet loads in the page head.
* The login button uses the site's published `login` page if one exists, otherwise the WordPress login, and returns to the tool afterwards. Filter: `qrrp_login_url`.
* The "Contact" button appears only if a published `contact` page exists. Filter: `qrrp_contact_url`.
* Access settings always offer "Logged-in users only" («Μόνο συνδεδεμένοι χρήστες»), which is the default for new installs, alongside the other three choices.
* An email link that has expired or was already used now shows a clear message.
* Site Health: a WP Rocket cache that never expires is reported as critical when guest access is on. The Site Health checks moved to their own class.
* Uninstall cleans every site on multisite, and also removes leftover temporary files.

Front end:

* Scanner handling:
  * The pause before a scan without Enter/Tab is submitted is now 300 ms. It can be changed with the `qrrp_scanner_idle_ms` filter.
  * A scan split by a short pause is joined back together, with a warning.
  * Alt+Numpad scanners and Japanese keyboards are handled.
  * Letter case still follows only the scanner's Shift, so a forgotten Caps Lock cannot change SN/LOT.
* Accessibility:
  * The focus ring is clearly visible.
  * Screen readers no longer repeat the warnings on every key press.
  * Focus is no longer pulled away from other controls.
* Error handling: a stale page, a denied request and an HTTP error each get a clear message, including when sending email.
* The printed date follows the server date. The saved image is drawn without blur, and there is a print stylesheet for the page.

Code:

* Removed internal code that nothing called: the unused `qrrp_audit_*` functions, `QRRP_DataMatrix::jpg_bytes_from_raw()`, `QRRP_Mailer::token_transient_key()`, and the old guest checkbox fields. The Site Health methods moved from `QRRP_Admin` to `QRRP_Site_Health`. No filters or actions were removed.
* Historical comments were moved to CHANGELOG.md. The code itself is about 40% shorter to read.
* Large files were split into focused classes: rate limiter, Site Health, and the access, print and upgrade helpers.
* About 1,300 regression tests were written for these fixes. They are kept outside the plugin package.

= 2.14.4 =

* When the tool is locked, its place now shows the access card, the disclaimer and the counters, as in the open tool.
* The counters refresh live: when the page opens and every minute while it stays open, also in the locked state.

= 2.14.3 =

* The access message (for example the "Pharmacies only" card, «Μόνο για φαρμακεία») now appears **in place of the tool** on the page, next to the title, instead of at the end of the page.

= 2.14.2 =

* With "Pharmacists only", anyone without access now sees a clear "Pharmacies only" card instead of a generic message:
  * Guest: what is needed (business category: Pharmacy), three steps and a "Log in / Register" button to /login/.
  * Logged-in account that is not a pharmacy: an explanation and a "Contact" button to /contact/.

= 2.14.1 =

* "Who can send email" now has **the same three choices** as access: Free for everyone (including guests) / Pharmacists only / Administrators only. The separate "Email from guests" checkbox was removed; "Free for everyone" covers it.
* On upgrade, the old "Contributors and above" settings become "Pharmacists only" automatically. "Free for everyone" access stays as it is.

= 2.14.0 =

**Simple access setting.**

* "Who can use the tool" now has three clear choices: **Free for everyone** (including guests), **Pharmacists only** (accounts with registration category "Φαρμακείο" / Pharmacy), **Administrators only**. Administrators always have access.
* The separate "Guests without login" checkbox was removed; it follows from "Free for everyone".
* "Who can send email" has the same easy choices: Everyone with access to the tool / Pharmacists only / Administrators only.
* An account that is not a pharmacy gets a clear message about what is missing.
* Older settings (for example "Contributors and above") are shown as they are, so saving the page changes nothing you did not choose.

The full history is in CHANGELOG.md, shipped with the plugin.

== Upgrade Notice ==

= 2.15.6 =
Adds a "Check for a new version" button for the bundled DataMatrix library in the settings. No change to scanning, parsing or email.

= 2.15.5 =
Stricter defaults: logged-in users get daily and per-recipient email limits (administrators exempt), public email domains such as gmail.com are ignored in the guest domain list, and manual creation by guests is off by default. Also updates the barcode library for strictly conformant DataMatrix padding. Recommended for all sites.

= 2.15.4 =
Cleans up expired tokens on sites without WP-Cron, and tightens guest email to 3 messages per recipient per day. If you allow guest recipients through the `qrrp_guest_email_recipient_allowed` filter without a domain list, also return true from `qrrp_guest_email_open_recipients`, or guest email stops. Recommended for all sites.

= 2.15.3 =
Guest email becomes deny-by-default: if guests send email on your site, add the allowed domains in the settings after updating, otherwise guest email stays off. Also fixes a stale label that could remain printable after a failed scan. Recommended for all sites.

= 2.15.2 =
Closes a provenance gap that could add an unproven AI to a rebuilt code, prevents duplicate emails from one link, and limits CPU use on crafted input. Recommended for all sites.

= 2.15.1 =
Fixes a case where an extra AI of the pack (for example 240) could be left out of the rebuilt code. Recommended for all sites.

= 2.15.0 =
Fixes every issue from a full code review: GS1 separator placement, stronger DataMatrix verification, guest rate limits, token handling, translations and accessibility. Recommended for all sites.

== License ==

QR ReBuilder Pro is free software licensed under the GNU General Public License version 2 or later.