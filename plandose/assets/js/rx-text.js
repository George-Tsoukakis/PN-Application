/**
 * PlanDose — rx-text.js
 *
 * «Επικόλληση συνταγής», the pasted text before any line is read:
 * Unicode clean-up (NFC, invisible characters, no-break spaces, «μg»),
 * the Greek / Latin look-alike folding every later match relies on, the
 * limits of what is read (MAX_INPUT, MAX_LINE) and the split into lines
 * (every «ΔΟΣΟΛΟΓΙΑ :» starts one; spacer lines are dropped).
 *
 * The reader is five files, loaded in this order (each needs the ones
 * before it): rx-text → rx-lines → rx-parse → rx-review → rx-import.
 * They share their internal helpers on PD.rx._ — not an API, only for
 * the rx modules that come after; the public entry points stay on PD
 * (PD.parsePrescription, PD.rxImportHtml, PD.wireRxImport, …).
 */
(function () {
	'use strict';

	var PD = window.__PlandoseNS;

	if (!PD) {
		return;
	}

	/* The internal helpers of the rx modules (see above). */
	var R = {};
	PD.rx = { _: R };

	/* Greek capitals that look like Latin ones. The print-outs mix them
	   («ΒΤΧ10», «ΒΤx30»), so codes are compared after folding to Latin. */
	var LOOKALIKE = {
		'Α': 'A', 'Β': 'B', 'Ε': 'E', 'Ζ': 'Z', 'Η': 'H', 'Ι': 'I', 'Κ': 'K',
		'Μ': 'M', 'Ν': 'N', 'Ο': 'O', 'Ρ': 'P', 'Τ': 'T', 'Υ': 'Y', 'Χ': 'X'
	};

	function latin(text) {
		return String(text).replace(/[ΑΒΕΖΗΙΚΜΝΟΡΤΥΧ]/g, function (c) {
			return LOOKALIKE[c];
		});
	}

	var TO_GREEK = {};
	Object.keys(LOOKALIKE).forEach(function (g) {
		TO_GREEK[LOOKALIKE[g]] = g;
	});

	function isGreekCap(ch) {
		return !!ch && /[Α-Ω]/.test(ch);
	}

	/* Upper-case without Greek accents, for matching the dose phrases. A
	   Latin lookalike next to a Greek letter is folded to Greek: the
	   print-outs contain «ΕΙΣΠΝOΕΣ» with a Latin O. Whole Latin words
	   («ML») are left alone. Written as a loop, not a lookbehind regex
	   (Safari < 16.4 has none). */
	function plain(text) {
		var s = String(text).toUpperCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
		var out = '';
		for (var i = 0; i < s.length; i++) {
			var c = s.charAt(i);
			if (TO_GREEK[c] && (isGreekCap(s.charAt(i - 1)) || isGreekCap(s.charAt(i + 1)))) {
				c = TO_GREEK[c];
			}
			out += c;
		}
		return out;
	}

	/* plain() with EVERY Latin lookalike folded to Greek («AN ΠΟΝΑΕΙ»). */
	function greekAll(text) {
		return plain(text).replace(/[ABEZHIKMNOPTYX]/g, function (c) {
			return TO_GREEK[c];
		});
	}

	function clean(line) {
		return String(line).replace(/[ \t]+/g, ' ').replace(/\s+/g, ' ').trim();
	}

	function fold(text) {
		return latin(plain(text));
	}

	/**
	 * What a copy from a PDF viewer or a web page adds: decomposed
	 * accents, soft hyphens and zero-width characters inside words
	 * (an invisible hyphen in «ΔΟΣΟΛΟΓΙΑ»), non-breaking spaces. Left in, they hid the
	 * «ΔΟΣΟΛΟΓΙΑ :» anchor and the medicine was silently skipped.
	 */
	function normalizeInput(text) {
		return String(text).normalize('NFC')
			.replace(/[\u00ad\u200b-\u200d\u2060\ufeff]/g, '')
			.replace(/[\u00a0\u2007\u202f]/g, ' ')
			/* PDFium marks a hyphen at a line break as U+FFFE
			   («EX\ufffeVALVE»). */
			.replace(/\ufffe/g, '-')
			/* «112μg» / «112µg» is micrograms. Read here, before
			   any Greek/Latin folding: folded, «μg» would read as «MG»
			   (1000 times the strength), and left Greek it hid the strength. */
			.replace(/(\d)\s*[\u00b5\u03bc]g(?![A-Za-z])/g, '$1MCG');
	}

	/* Longest line read. The dose-line regex backtracks on very
	   long lines; a longer dose line is refused with a warning. */
	var MAX_LINE = 1000;

	/* Longest paste read at all (a prescription is under 20 KB). */
	var MAX_INPUT = 200000;
	PD.RX_MAX_INPUT = MAX_INPUT;

	/* A pasted text with a blank line after every line (some PDF
	   viewers): blank lines then mean nothing and are dropped. */
	function dropSpacerLines(lines) {
		var blank = lines.filter(function (l) {
			return '' === l;
		}).length;
		if (blank >= 5 && blank >= (lines.length - blank) * 0.6) {
			return lines.filter(function (l) {
				return '' !== l;
			});
		}
		return lines;
	}

	/* «ΔΟΣΟΛΟΓΙΑ :» anywhere on a line — after the name when a PDF copy
	   joined the lines, after a bullet, in lower case, with Latin
	   look-alike letters («ΔOΣOΛOΓIA») or spaces before the colon — starts
	   a line of its own, so no medicine is skipped. */
	var ANCHOR = /[ \t]*[•·*\-–]?[ \t]*Δ[ΟΌοόOo]Σ[ΟΌοόOo]Λ[ΟΌοόOo]Γ[ΙΊιίIi][ΑΆαάAa]\s*[:：][ \t]*/gi;

	/* Linear. Whether the anchor starts its line is worked out
	   from the text since the previous anchor only (no scan back to the
	   line start for every match, which was quadratic on text without
	   line breaks). */
	function splitAnchors(text) {
		var prevEnd = 0;
		var lineBlank = true;
		return text.replace(ANCHOR, function (match, offset, str) {
			var seg = str.slice(prevEnd, offset);
			var nl = seg.lastIndexOf('\n');
			if (nl !== -1) {
				lineBlank = /^\s*$/.test(seg.slice(nl + 1));
			} else {
				lineBlank = lineBlank && /^\s*$/.test(seg);
			}
			var out = (lineBlank ? '' : '\n') + 'ΔΟΣΟΛΟΓΙΑ : ';
			prevEnd = offset + match.length;
			lineBlank = false;
			return out;
		});
	}

	/* The paste → clean lines, each «ΔΟΣΟΛΟΓΙΑ :» at the start of one. */
	function toLines(raw) {
		return dropSpacerLines(splitAnchors(raw.replace(/\r\n?/g, '\n')).split('\n').map(clean));
	}

	R.latin = latin;
	R.plain = plain;
	R.greekAll = greekAll;
	R.clean = clean;
	R.fold = fold;
	R.normalizeInput = normalizeInput;
	R.MAX_LINE = MAX_LINE;
	R.MAX_INPUT = MAX_INPUT;
	R.toLines = toLines;
})();
