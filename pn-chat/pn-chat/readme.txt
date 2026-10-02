=== PN Chat ===
Contributors: pharmacyneeds
Tags: chat, faq, chatbot, knowledge base, support
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.5.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A chat assistant that answers only from the knowledge you train it with. No AI, no third-party services.

== Description ==

PN Chat adds a chat assistant to your site. It runs entirely inside your
WordPress, for free: it does **not** use artificial intelligence and does not
send anything to any outside service.

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
  The public chat never uses AI.
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

No. The chat talks only to your own site (REST API namespace `pn-chat/v1`).
There is no connection to any AI or other service.

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
  but pages of the site are about the question, the visitor's question
  (never their e-mail or name) and those public pages are sent to the Claude
  API of Anthropic; the answer is marked as automatic and waits for an
  administrator's review. Daily limit set by the administrator.
* AI training assistant (off by default): when an administrator presses a
  "✨" button, the chosen question (never the visitor's e-mail or name) and
  public pages of the site are sent to the Claude API of Anthropic
  (https://www.anthropic.com/legal/privacy). Nothing is sent otherwise.

* Stored: the questions, and the e-mail address and name only if the visitor
  enters them. Questions are deleted automatically after 365 days (setting).
* IP addresses are not stored; a hash is kept briefly (up to one hour) for
  the rate limit.
* The conversation stays in the browser's sessionStorage until the tab is
  closed.
* Hooks into the WordPress personal data export and erase tools, and suggests
  text for the privacy policy.

== Changelog ==

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
