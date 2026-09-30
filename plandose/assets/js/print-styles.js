/**
 * PlanDose — print-styles.js
 * Part of the modular frontend JavaScript files located in assets/js/.
 * All modules share one namespace object (window.__PlandoseNS, aliased as PD).
 * Mutable state lives on PD.s.*; helpers, config, DOM refs and constants live on PD.*
 *
 * Holds only the print stylesheet (PD.PRINT_STYLES), kept out of state.js so
 * application state and the large print CSS blob live in separate files. Must
 * load before print.js, which injects this stylesheet into the print document.
 *
 * The stylesheet is a single template-literal string so it reads like a normal
 * CSS file (one rule per line, grouped by section). print.js drops it verbatim
 * into a <style> tag in the print window/iframe, so ordinary CSS whitespace and
 * comments are harmless here.
 *
 * The on-screen preview uses this same stylesheet: preview.js scopes a copy
 * of it to the preview's paper (PD.ensurePreviewStyles), so what the
 * pharmacist checks on screen is laid out like the printout. There is no
 * second copy of these rules in plandose.css.
 */
(function () {
	'use strict';

	var PD = window.__PlandoseNS;

	/* Namespace missing → the tool is not available on this page
	   (guest/no-permission or required DOM absent). Bail out quietly. */
	if (!PD) {
		return;
	}

	PD.PRINT_STYLES = `
/* ---- Page & base ---- */
@page { size: A4; margin: 12mm 10mm; }
* { box-sizing: border-box; }
/* Print the backgrounds (day-card headers, the yellow notes box,
   the intro) with the browser's DEFAULT print settings,
   where "Background graphics" is off. Colour only — no layout change. */
html, body, * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body { margin: 0; background: #ffffff; color: #1a1a1a; font-family: Arial, Helvetica, sans-serif; }
.pd-print-document { width: 100%; background: #ffffff; color: #1a1a1a; }

/* ---- Document header / brand ---- */
.pd-print-header { display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #0f6e56; padding-bottom: 9px; margin-bottom: 12px; }
.pd-print-brand { display: flex; align-items: center; gap: 10px; }
.pd-print-brand strong { display: block; font-size: 18px; color: #0f6e56; font-weight: 800; }
.pd-print-brand span { display: block; margin-top: 2px; font-size: 10px; color: #666666; }
.pd-print-brand .pd-print-start { font-size: 11px; font-weight: 800; color: #0f6e56; }

/* ---- Intro paragraph ---- */
.pd-print-intro { margin: 0 0 12px; padding: 8px 12px; border-radius: 8px; background: #eef8f5; border: 1px solid rgba(15, 110, 86, 0.2); color: #205146; font-size: 10.5px; font-weight: 700; line-height: 1.4; }
.pd-print-intro strong { color: #0f6e56; font-weight: 900; }

/* ---- Pharmacy / patient info boxes ---- */
.pd-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px; }
.pd-info-box { border: 1px solid #cfcfcb; border-radius: 10px; padding: 8px 10px; background: #ffffff; break-inside: avoid; page-break-inside: avoid; }
.pd-info-box h3 { font-size: 10px; letter-spacing: 0.01em; color: #0f6e56; margin: 0 0 5px; font-weight: 800; }
.pd-info-box p { font-size: 11px; margin: 0 0 2px; }
.pd-info-box .pd-info-name { font-size: 13px; font-weight: 800; }

/* ---- List of the medicines — name left, dose right, aligned ---- */
.pd-med-key { border: 1px solid #cfe3dc; border-radius: 10px; background: #f3f9f7; padding: 8px 10px; margin-bottom: 12px; break-inside: avoid; page-break-inside: avoid; }
.pd-med-key h3 { margin: 0 0 4px; font-size: 12px; font-weight: 900; color: #0f6e56; }
.pd-med-key-row { display: grid; grid-template-columns: minmax(0, 38fr) minmax(0, 62fr); column-gap: 10px; padding: 4px 0; border-top: 0.5px solid #d7e6e0; font-size: 10.5px; line-height: 1.35; }
.pd-med-key-row:first-of-type { border-top: 0; }
.pd-med-key-name { font-weight: 900; min-width: 0; }
.pd-med-key-dose { min-width: 0; color: #222222; }
.pd-drug-course { font-size: 9.5px; font-weight: 800; color: #0f6e56; margin: 2px 0 0; }
/* Notes are warnings — main text colour, ≥ 10.5px, readable
   without the tinted background (which a printer may drop). */
.pd-drug-notes { display: inline-block; font-size: 10.5px; font-weight: 700; font-style: normal; color: #1a1a1a; background: #fff6d2; border: 1px solid #b8a24a; border-radius: 8px; padding: 2px 7px; margin-top: 3px; line-height: 1.35; }

/* ---- One card per day, two per row ---- */
.pd-day-row { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 8px; break-inside: avoid; page-break-inside: avoid; }
.pd-day-card { flex: 0 0 calc(50% - 4px); min-width: 0; border: 1.5px solid #9fc8ba; border-radius: 9px; overflow: hidden; background: #ffffff; break-inside: avoid; page-break-inside: avoid; }
/* A day with many doses gets the full width, one line per dose
   (notes after the name), so it still fits one A4 page. Its row may then
   break between its two cards, never inside one. */
.pd-day-card-dense { flex-basis: 100%; }
.pd-day-row:has(.pd-day-card-dense) { break-inside: auto; page-break-inside: auto; }
.pd-day-card-dense .pd-day-dose { padding: 2px 0; }
.pd-day-card-dense .pd-day-dose-notes { display: inline; margin-left: 8px; }
/* Dark text on a light tint with a rule under it, so the date
   stays readable when the browser strips backgrounds. */
.pd-day-card-head { display: flex; justify-content: space-between; align-items: baseline; gap: 6px; padding: 6px 9px; background: #e3f1ec; color: #0b4d3c; border-bottom: 1.5px solid #0f6e56; }
.pd-day-card-head strong { font-size: 15px; font-weight: 900; }
.pd-day-card-head span { font-size: 9.5px; font-weight: 700; }
.pd-day-card-body { padding: 4px 9px 5px; }
.pd-day-slot { display: flex; gap: 6px; align-items: flex-start; padding: 4px 0; border-top: 1px solid #cfe3dc; }
.pd-day-slot:first-child { border-top: 0; }
.pd-day-slot-label { flex: 0 0 60px; font-size: 11px; font-weight: 900; color: #0f6e56; padding-top: 2px; }
.pd-day-slot-items { flex: 1 1 auto; min-width: 0; }
.pd-day-dose { display: flex; gap: 6px; align-items: flex-start; font-size: 10.5px; line-height: 1.3; padding: 3px 0; }
.pd-day-dose + .pd-day-dose { border-top: 0.5px solid #e7e7e3; }
.pd-day-dose-name { flex: 1 1 auto; font-weight: 700; min-width: 0; }
.pd-day-dose-notes { display: block; font-size: 10.5px; font-weight: 400; font-style: normal; color: #1a1a1a; margin-top: 1px; line-height: 1.3; }
/* The dose must never be clipped by the card (overflow: hidden): a
   short one stays on one line (number and unit are joined by a no-break
   space), a long one wraps within at most 60% of the line. break-word,
   not anywhere, so «1 Δισκίο» is never split to make room for the name. */
.pd-day-dose-amount { flex: 0 1 auto; max-width: 60%; font-weight: 900; white-space: normal; overflow-wrap: break-word; word-wrap: break-word; text-align: right; }
.pd-day-dose .plandose-box { flex: 0 0 auto; margin-top: 1px; }
.plandose-box { display: inline-block; width: 12px; height: 12px; border: 1.3px solid #333333; border-radius: 2px; background: #ffffff; }

/* ---- Disclaimer / thanks / empty state ---- */
.plandose-disclaimer { font-size: 9px; line-height: 1.3; color: #444444; border-top: 0.5px solid #cccccc; margin-top: 10px; padding-top: 5px; }
.pd-print-thanks { margin: 10px 0 0; font-size: 13px; font-weight: 800; line-height: 1.4; color: #0f6e56; text-align: center; }
.plandose-empty { border: 1px dashed #cccccc; background: #ffffff; color: #555555; padding: 10px; border-radius: 8px; }

/* ---- Long unbroken text (a pasted barcode, a URL, a name with
   no spaces) must wrap inside its box instead of running off the page or
   stretching the grid. overflow-wrap:anywhere is the real rule; the
   word-break line is the fallback for engines without "anywhere". ---- */
.pd-med-key-name,
.pd-med-key-dose,
.pd-day-dose-name,
.pd-day-dose-notes,
.pd-drug-course,
.pd-drug-notes,
.pd-info-box p,
.pd-info-box h3,
.pd-print-intro,
.pd-print-brand span,
.plandose-disclaimer,
.pd-print-thanks { word-break: break-word; overflow-wrap: anywhere; }
.pd-drug-notes { max-width: 100%; }
.pd-info-grid > .pd-info-box { min-width: 0; }

/* ---- «Υπενθυμίσεις στο κινητό» QR (calendar-qr.js), a third box
   next to pharmacy and patient. The QR keeps a white quiet zone and a
   fixed physical size so a phone reads it from the paper. ---- */
.pd-info-grid.pd-info-grid-cal { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto; }
.pd-cal-box { display: flex; gap: 8px; align-items: center; border-color: #9fc8ba; }
.pd-cal-qr { flex: 0 0 34mm; width: 34mm; height: 34mm; padding: 1.5mm; background: #ffffff; }
.pd-cal-qr svg { display: block; width: 100%; height: 100%; }
.pd-cal-text { width: 34mm; min-width: 0; }
.pd-cal-text h3 { margin: 0 0 3px; }
.pd-cal-text p { font-size: 9.5px; line-height: 1.3; margin: 0 0 3px; }
.pd-cal-text .pd-cal-private { color: #4d665f; font-size: 8.5px; margin: 0; }

/* The Pro medicine labels are not on the A4 sheet — they print on the
   label printer from PD.buildLabelPrintDocument() (pro-labels.js). */

/* ---- Keep atomic blocks from splitting across printed pages ---- */
@media print {
	.pd-info-box,
	.pd-med-key,
	.pd-day-row { break-inside: avoid; page-break-inside: avoid; }
	.pd-day-card { break-inside: avoid; page-break-inside: avoid; }
	.pd-day-row:has(.pd-day-card-dense) { break-inside: auto; page-break-inside: auto; }
}
`;
})();