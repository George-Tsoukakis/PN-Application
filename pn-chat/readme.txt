=== PN Chat ===
Contributors: pharmacyneeds
Tags: chat, faq, chatbot, knowledge base, support
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.8.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A chat assistant that answers from the knowledge you train it with and from your own pages. AI (Claude) is optional and off by default.

== Description ==

PN Chat adds a chat assistant to your site. By default it runs entirely
inside your WordPress, for free, and sends nothing to any outside service.
Two optional features, both off by default, use Claude (Anthropic): an
administrators' training assistant, and AI answers in the public chat (see
Privacy).

* **Answers only from your knowledge base.** Each entry holds the questions
  (as many phrasings as you like) and the answer. It never guesses.
* **Understands Greek as people really type it:** without accents, with common
  spelling mistakes, in Greeklish (Greek written in Latin letters), with
  different word endings, and with synonyms you define.
* **Combined answers:** a question about two topics gets both answers.
* **When it does not know,** it says so (the text is editable) and offers a
  form for the visitor's e-mail, so you can reply later.
* **Searches your own site:** when it has no trained answer, it shows the most
  relevant published pages and posts of your site, with a link. Still no AI
  and no outside service.
* **Optional AI training assistant (admin only):** Claude reads pages of your
  own site and drafts knowledge entries that you review before they are saved.
* **Optional AI answers in the chat:** when no trained answer fits but pages
  of the site are about the question, Claude answers from those pages. The
  answer is shown at once, labelled as automatic and not yet checked by a
  person, and waits in Questions for review. The AI answers only questions
  about the site's tools (a chat topic or a tool word such as print, label,
  plan, account) with no medical sign ("how many pills", side effects,
  symptoms, "500mg", "which medicine"), not close to a blocked topic; the
  model also flags medical questions itself, and those get no answer. All
  other questions get the site's pages and the e-mail form, as without AI.
  Filters `pnchat_ai_tools_only`, `pnchat_ai_medical_terms`,
  `pnchat_ai_medicine_words`, `pnchat_ai_advice_phrases`,
  `pnchat_ai_tool_words`.
* **Blocked questions:** questions it must not answer (for example medical
  advice) get your own message instead. You train them like answers.
* **Mobile friendly:** full screen on phones, stays above the on-screen
  keyboard, large touch targets, no zoom on iPhone. The chat button moves
  above other floating buttons in the same corner (PlanDose).

= Admin screens (menu "PN Chat") =

* **Training:** knowledge entries, synonyms, and a test box that shows what
  the assistant would answer and why.
* **Questions:** every question visitors asked. From an open question you can
  train the assistant (new entry, or add it to an existing one), reply by
  e-mail, block it or dismiss it.
* **Blocked:** questions that are never answered.
* **Brain:** download the whole knowledge base as a .json file (backup) and
  upload it again (merge or replace). A snapshot is kept automatically before
  every upload (the last 5).
* **Settings:** who sees the chat (everyone or logged-in users), floating
  button or shortcode, colour, texts, strictness, limits, notifications,
  privacy.

Shortcode: `[pn_chat]` shows the chat inside a page.

The first activation adds a few starter entries about PlanDose and one blocked
topic (medical questions). Review and change them freely.

== Installation ==

1. Upload the `pn-chat` folder to `/wp-content/plugins/`, or upload the zip
   from Plugins → Add New → Upload Plugin.
2. Activate the plugin.
3. Go to PN Chat → Training, review the starter entries and add your own.
4. For e-mail replies and notifications WordPress must be able to send mail;
   an SMTP plugin is recommended.

== Frequently Asked Questions ==

= Does it send questions anywhere? =

Not by default. The chat talks only to your own site (REST API namespace
`pn-chat/v1`). Only if you switch on "AI in the chat" are questions without a
trained answer sent to the Claude API, as described under Privacy.

= The site is behind Cloudflare (or another proxy). =

Then every visitor seems to come from the proxy's address and the per-visitor
limits become one limit for the whole site. Name the header that carries the
real address in wp-config.php, for example
`define( 'PNCHAT_IP_HEADER', 'HTTP_CF_CONNECTING_IP' );`. Only use a header
your proxy always sets: visitors can forge any other.

= How do I make it understand more questions? =

Add phrasings to the entries (one per line) and synonyms. From Questions, use
Training → "add the question to an existing entry". Check the result with the
test box. If it answers too easily or too rarely, change the strictness.

= Is anything lost if I delete the plugin? =

Not by default: the knowledge base and the questions are kept. They are
deleted only if you turn off the "keep data on uninstall" setting. Download a
copy from the Brain screen before big changes.

== Privacy ==

* AI answers in the chat (off by default): when there is no trained answer
  but pages of the site are about the question, the text of the question and
  those public pages are sent to the Claude API of Anthropic. The e-mail and
  name fields are never sent; e-mail addresses, Greek phone numbers and
  11-digit numbers (AMKA) typed inside the question are replaced before
  sending, but other personal details in the text are not detected. The
  answer is shown at once, marked as automatic, and waits for an
  administrator's review. Daily limit of AI calls set by the administrator.
* AI training assistant (off by default): when an administrator presses a
  "✨" button, the text of the chosen question (cleaned as above) and public
  pages of the site are sent to the Claude API of Anthropic
  (https://www.anthropic.com/legal/privacy). Nothing is sent otherwise.

* Stored: the questions, and the e-mail address and name only if the visitor
  enters them. Questions are deleted automatically after 365 days (setting).
* IP addresses are not stored. For the rate limit a hash is kept while the
  limit lasts (10 minutes to one hour) and deleted at the next chat question
  or by the hourly clean-up (WP-Cron, which runs when the site has visits). The page a question was asked on is stored without its
  query string.
* The conversation stays in the browser's sessionStorage until the tab is
  closed.
* Hooks into the WordPress personal data export and erase tools, and suggests
  text for the privacy policy.

== Changelog ==

= 1.8.3 =
* AI in the chat answers only questions about the site's tools with no medical sign. Word lists cannot know every medicine: on 20 new medical questions the 1.8.2 guard let 12 through, this one none.
* The model also returns "medical_advice"; when true, no answer is shown.
* Advice phrases ("which medicine", "is it safe", "does it help") count as medical even in a tool question; more conditions; «τι δόση» no longer catches «τη δόση».

= 1.8.2 =
* Before the first question the suggested questions sit right under the welcome (no empty area between them), on phones and desktop.
* Every group of suggested questions is open when they all fit; otherwise the first one, as before.

= 1.8.1 =
* Brain "replace": after a failure the previous entries are checked by content (not by number) and written back with their ids when needed; a failed COMMIT counts as a failure.
* AI daily limit: only calls that certainly never reached Anthropic are given back; a time-out or lost answer keeps counting.
* AI medical guard: tool questions that mention medicines ("print medicine labels in PlanDose", "dosage plan") reach the AI again; advice questions are still kept from it.
* Rate-limit IP hashes are deleted as soon as the limit ends (next question, hourly clean-up); privacy texts say so.
* Upgrading through deactivate/activate also moves the old AI usage totals.
* Failed deletes, synonym and settings saves, and AI draft saves are reported.

= 1.8.0 =
* Privacy export: every question of a person is exported (it stopped at 500 and said it was done).
* Brain upload "replace": the file is checked first; a file without a usable entry changes nothing, and the entries are swapped all at once or not at all.
* Brain download with questions keeps the AI answers waiting for review and the page of each question.
* AI daily limit, per-visitor limits and AI cost totals are atomic counters (parallel requests can no longer pass together). Calls that never reached Claude do not use the daily limit.
* The e-mail form and the "did this help?" buttons come back after the visitor opens another page.
* A save the database refused is reported as an error (entries, questions, e-mail, feedback); the same e-mail sent twice no longer notifies twice.
* Texts with {question} and {email} instead of %s; a "%" in a text can no longer break the chat.
* AI: questions close to a blocked topic or with medical words never go to the AI; medical questions are refused in the prompt; e-mails, phone numbers and AMKA inside a question are replaced before sending; the source link must be one of the pages sent. Texts about what is sent are corrected.
* No more "no AI" in the plugin description, readme and settings.
* PNCHAT_IP_HEADER for sites behind Cloudflare or another proxy.
* Site search reads only the pages that hold a question word; "update now" indexes in the background.
* Notifications follow the site's admin e-mail when no address is set; cache writes priced at 1.25x; page addresses stored without query strings; topic of an entry from its title and questions first; assets in the page head where possible; request time-out and Greek error texts in the chat; keyboard focus kept inside the full-screen chat; multisite uninstall.

= 1.7.0 =
* E-mail replies are sent as a light HTML message (one card in the site colour, no images) with the plain text as the alternative part: the visitor's question as a quote, "- " lines as a list, a line with only a link as a button, other links clickable, a short footer. The text is still written as plain text on the reply screen.
* Default subject "Απάντηση στην ερώτησή σας στην PharmacyNeeds" (changed on existing sites only where the old default was kept).

= 1.6.2 =
* API key: a new key is tested with Anthropic when the settings are saved (listing models, free); a rejected key is not saved and the screen says so. A pasted header line (`x-api-key: sk-ant-...`) is reduced to the key; text that is not a key is reported instead of silently ignored. With AI on, a saved key that Anthropic rejects is reported on every settings save.
* The "invalid x-api-key" error now names the key in use (its last characters, and whether it comes from wp-config.php, which overrides the settings) and how to replace it.

= 1.6.1 =
* A long answer is shown from its start (with the visitor's question above it) instead of scrolling to the e-mail form at the bottom; the visitor scrolls down to read on.

= 1.6.0 =
* "New conversation" button (↻) next to the close button: after a confirmation it clears the conversation on the visitor's device and the conversation topic, and shows the welcome and the suggested questions again. The questions stay in the admin log. Shown only when there is a conversation.

= 1.5.2 =
* HTML pasted into the answer editor's Visual tab (shown as tags) is turned back into HTML on save and when shown, also for answers saved that way before.

= 1.5.1 =
* claude-haiku-4-5 works as the AI model (it rejects the effort setting and fallbacks, which are no longer sent to it). Cost counter priced per model (Opus 5.5, Sonnet 5.5, Haiku 4.5).

= 1.5.0 =
* Optional AI answers in the chat: when no trained answer fits but pages of the site are about the question, Claude answers from those pages only; the answer is labelled as automatic and waits in Questions → "Απαντήσεις AI", where "Έλεγχος και έγκριση" opens a prefilled new entry. Daily limit, per-visitor limit, separate chat model; never for trained, refused or page-less questions.

= 1.4.0 =
* Conversation topics: a follow-up question that names no topic ("Είναι δωρεάν;") is answered for the topic of the previous answer ("…το QR ReBuilder"); naming another topic switches. Topics are edited on the Training screen.
* Matcher: a trained question that names a topic the visitor did not name scores lower. "δωρεάν" is a cost synonym. QR ReBuilder cost entry added to the starter brain.

= 1.3.0 =
* Suggested questions in groups ("# Title" lines) that open with one tap, and link buttons ("Text | /page/"). A "Συχνές ερωτήσεις" button brings them back after the first question.
* New defaults: a QR ReBuilder group and a PlanDose group, a shorter welcome, and a "how to use QR ReBuilder" entry with a link to its page. Existing sites get them only where the old default texts were never changed.

= 1.2.2 =
* Training form: a save with a missing question or answer keeps everything typed (and the question it came from) and says which field is missing.

= 1.2.1 =
* QR ReBuilder questions were answered with the PlanDose QR-reminders entry (its bare "qr" keyword). The starter entry is fixed on existing sites unless edited; QR ReBuilder and a "which QR?" entry are added.
* Matcher: weak matches on both sides, and weak second answers, no longer pass.

= 1.2.0 =
* AI training assistant (optional, admin only): Claude reads pages of your own site and drafts knowledge entries - for an unanswered question, or several from one page - that an administrator reviews before saving. The public chat never calls the API. API key in wp-config.php (PNCHAT_ANTHROPIC_API_KEY) or the settings, never shown again; running token and cost total; safety-classifier declines retried server-side.

= 1.1.0 =
* Site search: when no trained answer fits, the chat shows the most relevant published pages and posts of the site (title, matching sentence, link), still offering the e-mail form. Greek and Greeklish folding as for trained answers; indexed on save, in the background for existing pages; settings for post types, excluded pages and texts; "Update now" button.

= 1.0.3 =
* Matcher: a question that shares only one common word with a trained question (and also says other things) is no longer answered by it.

= 1.0.2 =
* Close button: black circle with a red ×; the chat icons are styled so that themes cannot hide them.

= 1.0.1 =
* The chat button sits above the PlanDose button instead of covering it.
* Subtitle "για την PharmacyNeeds"; no note under the input by default.
* Plugin Check: table names through `%i` placeholders, direct-access guard in
  every PHP file, no Update URI header, English readme.

= 1.0.0 =
* First release.
