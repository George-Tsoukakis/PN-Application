=== QR ReBuilder Pro ===

Contributors: PharmacyNeeds
Tags: gs1, datamatrix, barcode, pharmacy, scanner
Requires at least: 6.1
Requires PHP: 8.2
Tested up to: 7.1
Stable tag: 2.16.1
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

Before anything is generated the server asks for an explicit declaration that the four values were read from the pack itself. The declaration is bound to the exact four values: changing any of them requires a new one. Since 2.16.0 the result carries no provenance note on screen, on the label or in the email, so the label stays compact. Every other server-side check still applies (GTIN check digit, date, character set, expiry confirmation). Logged-in users can always use it. Guests can use it only when guest access is enabled and the setting "Manual creation by guests" is on. That setting has been off by default since 2.15.5, so manual creation is normally reserved for logged-in users.

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

Email is available to verified pharmacists: administrators, plus users an administrator has approved with the "Verified pharmacist" checkbox on their profile (Users → Edit user; the Users list shows a "QR email" column with the pending requests). A self-declared "Φαρμακείο" registration alone no longer allows email. When email is allowed for the current user, the message can include:

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
* A separate permission check for email. Since 2.16.0 the default is "Verified pharmacists only" (`qrrp_verified_pharmacist`): administrators plus users an administrator approved on their profile. Self-declared pharmacies can use the tool but cannot send email until approved, so free registrations cannot turn the site into a spam relay. Sites with their own verification can use the `qrrp_is_verified_pharmacist` filter (arguments: `bool $is`, `int $user_id`).
* Optional guest access, disabled by default.
* A separate optional switch for guest email.
* Server-side GS1 validation.
* GTIN check digit validation.
* Server-side canonical GS1 rebuild.
* Consistency checks between the submitted fields and the raw data.
* Rate limiting.
* Size limits on raw input.
* Signature and dimension checks on the generated image.
* Temporary email attachments with a random name that never contains the Serial Number, permissions 0600 set before the bytes are written, and deletion right after sending.
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
* **Email rebuild links.** When you email a code, the rebuild link in that email needs the fields to still exist when the recipient clicks it, so PC, SN, LOT and EXP are held on the server under a random 128-bit token — never in the link itself. Only a SHA-256 hash of that token is stored, so the stored record cannot be turned back into a working link. The record is deleted as soon as the link is used to rebuild a code, and expires on its own after at most 7 days by default (filter `qrrp_rebuild_token_ttl`; 1 hour to 30 days). The plugin keeps at most 2,000 live rebuild links at a time (filter `qrrp_token_index_max`, 100–10,000). When that store is full, a new email is sent without a rebuild link and Site Health reports it; links already sent are never retired early.

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

= 2.16.1 =

**Fixes from a full review of 2.16.0. One parsing change: a code without separators that can also be read as a code missing one of the four fields now always asks for confirmation (22 of the 4,021 golden inputs; no field value changes).**

* Parsing: without Group Separators, `21ABCD10EFGH` can be SN "ABCD" plus LOT "EFGH", or only SN "ABCD10EFGH" on a pack without a LOT. The 2.16.0 short-value check did not catch it, so a LOT that is not on the pack could be printed without asking. Now, whenever the scan also reads completely as a code without one of PC/SN/LOT/EXP, confirmation is required and the warning names the alternative (for example `SN «ABCD10EFGH» χωρίς LOT`).
* Rebuild: extra GS1 fields of the pack (e.g. AI 240, AI 91) are no longer dropped silently after an unverified scan or a manual change without a verified baseline. Extras proven by the confirmed reading are carried; otherwise the request is refused with "extras_unprovable" instead of printing an incomplete code.
* Access: the 2.16.0 email migration now runs on the first request after an update, not only when an administrator opens wp-admin (automatic and FTP/CLI updates left email open until then). New admin notice when email is "same as the tool" and the tool is open to every logged-in user.
* Access: nobody can approve themselves as a verified pharmacist.
* Scanner workflow: after generating, printing, copying, downloading or emailing, focus returns to the scanner field. A scan that starts while a button has focus goes to the scanner, and its Enter can no longer press Print again (which reprinted the previous label). Print windows close after printing.
* Email: an exception during sending now tells the user that the link or confirmation was used up; text/plain alternative body; `lang` from the site language; temporary images are deleted even if PHP stops on max_execution_time; per-recipient limit treats Gmail dot variants as one address; the email button no longer shows a stale "Sending…" state; generating again clears the previous code's proof.
* Rate limiting: counters use a single atomic UPDATE, so concurrent legitimate guests no longer get false 429 responses (rows convert from the 2.16.0 format on their next hit). The hourly cleanup runs from WP-Cron (`qrrp_rl_sweep`) instead of inside a visitor's request. Opt-in helper `QRRP_Rate_Limiter::trusted_proxy_remote_addr` for sites behind a CDN.
* Settings: an invalid sender email keeps the previous value instead of switching to the admin email. Site Health also runs in the weekly background check and has a new test that lists filters relaxing the GS1 reading checks; `qrrp_century_reference_year` can move the window by at most one year.
* Accessibility and theming: warnings are announced once; the scanner field is described by its hint; button styles are scoped so themes no longer override them; the "fields changed" message names the real button.

= 2.16.0 =

**Review release: email only from verified pharmacists, no provenance notes on labels, safer reading of codes without separators.**

* Email: new default "Verified pharmacists only". An administrator approves each pharmacy once with the "Verified pharmacist" checkbox on the user's profile; the Users list gets a "QR email" column showing approved users and pending "Φαρμακείο" registrations. Before, anyone who registered for free as "Φαρμακείο" could email any address from the site's domain, with their own text in the customer field. The update moves "Pharmacists only" and "same as the tool" (for logged-in tools) to the new setting; "Free for everyone" and "Administrators only" are kept. Unapproved pharmacies see a short note instead of the email form.
* Labels: provenance notes ("User-declared…", "Manual change…", "Unverified reading…") are no longer shown in the summary, on the printed label, in the saved image or in the email. The confirmation steps before generating a code are unchanged.
* Parsing: a code without Group Separators is no longer accepted automatically when the most likely reading gives an SN or LOT shorter than 4 characters. Such a split usually means a field that is not on the pack was invented from another value (for example `21AB10CD` read as SN "AB" plus LOT "CD" when the pack has no LOT). It now asks for confirmation. Filter `qrrp_auto_inference_min_length` (1–20). Normal codes without separators are accepted as before.
* Email links: scanning a different pack after opening an emailed link no longer uses up that link.
* Printing: one label now reliably fits one page; before, a label taller than the page could spill onto a second and third sheet.
* Readme: corrected the default for guest manual creation, and added the missing 2.15.7 upgrade notice.

= 2.15.7 =

**Fixes from a code review. One parsing change: mixed-separator inputs now always ask for confirmation.**

* Parsing: when a code has explicit field boundaries (a Group Separator that ends a variable-length value, or parenthesised HRI) but a complete PC/SN/LOT/EXP reading is only possible by splitting a value the code had already closed, the reading is no longer accepted automatically. Example: `01…21AB17280331<GS>10LOT1` used to become SN "AB" plus an invented expiry 2028-03-31 without asking. It now needs confirmation, with a warning that a field may not exist in the original code. Inputs with no separators at all, or with a separator only after a fixed-length field such as 01, behave as before.
* Email rebuild links: the store holds 2,000 live links (was 500; filter `qrrp_token_index_max`, 100–10,000). New Site Health check "Email link capacity" warns at 80% and when emails went out without a link in the last 7 days. Live links are never deleted early, even if the filter lowers the cap.
* Email attachments: the temporary PNG is deleted right after a successful synchronous send (it used to stay on disk for about an hour). Sites whose mail plugin queues messages and reads attachments later should return true from the new filter `qrrp_mail_attachment_deferred` to keep the previous behaviour. Temporary file names now carry a per-site tag, so the cleanup and the uninstall purge never touch another site's files in a shared temp folder.
* Site Health: the legacy email-link check is cached for 12 hours instead of reading every stored link on each Site Health visit. When the `qrrp_page_cache_lifespan` filter overrides WP Rocket's value, the source is now reported as the filter.
* Greek keyboard layout: the browser no longer converts Greek characters to Latin itself (paste, and keystrokes without a physical key code). That conversion lost information (Σ always became S, ά became a) and hid the recovery from the server, so no confirmation was asked. The characters now reach the server unchanged, which recovers them and asks for confirmation. ΐ / ΰ now keep their W.
* Email limits: the site-wide email quota is checked first and charged last, so a request refused by the per-recipient or daily limit no longer uses up the site-wide quota.
* Email: the result of sending now appears right under the "Send email" button: a green confirmation naming the recipient, or a red error. The button shows "Sending…" meanwhile. Before, the message appeared only at the top of the tool, off screen, so sending looked silent.
* Accessibility: a finished analysis is announced to screen readers. An email reply that arrives after "New scan" no longer shows "Email sent" on the reset tool.
* DataMatrix: a symbol whose separators came out as a literal GS instead of FNC1 is now refused by default (filter `qrrp_datamatrix_require_fnc1`). The bundled library always writes FNC1; this only guards against a future library regression.
* Settings: no false "tool page is not published" warning when the site has no published pages. Smaller fixes: the tool-page detection runs once per request, the library version check times out after 5 seconds per package.

The full history is in CHANGELOG.md, shipped with the plugin.

== Upgrade Notice ==

= 2.16.1 =
Codes scanned without separators that could also be a pack missing a field now ask for confirmation. Existing pharmacist approvals are kept.

= 2.16.0 =
Email is now limited to verified pharmacists. After updating, approve each real pharmacy once under Users (column "QR email", checkbox on the profile); until then only administrators can send email. Provenance notes no longer appear on labels.

= 2.15.7 =
Temporary email images are deleted right after sending. If your mail plugin queues messages and sends them later, return true from `qrrp_mail_attachment_deferred`, or emails may go out without the image.

== License ==

QR ReBuilder Pro is free software licensed under the GNU General Public License version 2 or later.