=== PN Chat ===
Contributors: pharmacyneeds
Tags: chat, faq, chatbot, knowledge base, support
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.2
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

* Stored: the questions, and the e-mail address and name only if the visitor
  enters them. Questions are deleted automatically after 365 days (setting).
* IP addresses are not stored; a hash is kept briefly (up to one hour) for
  the rate limit.
* The conversation stays in the browser's sessionStorage until the tab is
  closed.
* Hooks into the WordPress personal data export and erase tools, and suggests
  text for the privacy policy.

== Changelog ==

= 1.0.2 =
* Close button: black circle with a red ×; the chat icons are styled so that themes cannot hide them.

= 1.0.1 =
* The chat button sits above the PlanDose button instead of covering it.
* Subtitle "για την PharmacyNeeds"; no note under the input by default.
* Plugin Check: table names through `%i` placeholders, direct-access guard in
  every PHP file, no Update URI header, English readme.

= 1.0.0 =
* First release.
