/**
 * PlanDose — rx-lines.js
 *
 * «Επικόλληση συνταγής», what each line of an ΗΔΙΚΑ print-out is. A line
 * is cut into segments (price rows and totals, dose lines, drug lines,
 * barcode tails); a drug line is recognised (brand, form code, strength,
 * pack — findBrand()) and read (drugName(), readStrength(), readPack()),
 * a drug line wrapped over several lines is joined (mergeDrugWraps()),
 * and the known header, footer, price and barcode lines are matched
 * whole. Needs rx-text.js; used by rx-parse.js.
 */
(function () {
	'use strict';

	var PD = window.__PlandoseNS;
	var R = PD && PD.rx && PD.rx._;

	/* Nothing of the reader runs if a file before this one is missing. */
	if (!R || !R.toLines) {
		return;
	}

	var latin = R.latin;
	var plain = R.plain;
	var clean = R.clean;
	var fold = R.fold;

	/* Segments that end the block of the previous medicine (or the header). */
	function isBoundary(line) {
		return '' === line ||
			PRICE_EXACT.test(line) ||
			/^ΔΟΣΟΛΟΓΙΑ\s*:/.test(line) ||
			TOTALS_HEADER.test(line) ||
			BARCODE_LINE.test(line) ||
			'' !== knownLineKind(line);
	}

	/* A Greek mu / micro sign (any case) before a Latin or Greek
	   G, where the text is not the plain «μg»: never folded to «MG». */
	var MU_G = /(\d)\s*[\u00b5\u03bc\u039c]\s*[Gg\u0393\u03b3]/g;
	var MU_G_ONE = new RegExp(MU_G.source);

	/* A strength in units per ml. */
	var UNITS_PER_ML = /(?:^|[^A-Z])(UNITS?|IU|U)\s*\/\s*ML\b/;

	/* ---------------------------------------------------------------- */
	/* Name: brand + first strength, without form, pack and status        */
	/* ---------------------------------------------------------------- */

	/* A dosage-form code: «F.C.TAB», «GR.TAB», «TAB», «CAPS», «NASPR.SUS»,
	   «AER.MD.INH», «INJ.SOL», «IN.SO.PF.P», «CREAM», … */
	var FORM_TOKEN = /^(?:[A-Z]{1,6}(?:\.[A-Z]{1,6})+\.?|TAB|TABS|CAP|CAPS|CREAM|OINTMENT|OINT|GEL|SYR|SUPP|DROPS?|PATCH|SACHET|SPRAY|EMULS?|LOT|SOL|SUSP?)$/;

	/* A strength, including combinations written with slashes
	   («100/6MCG/DOSE», «50/500MCG/DOSE») and units («100U/ML»). The unit
	   must end there («G» is not the start of «GR»). */
	var STRENGTH = /(\([\d.,+ ]+\)\s*|\d+(?:[.,]\d+)?(?:\s*\/\s*\d+(?:[.,]\d+)?){0,2}\s*)(ANTI-?XA\s*IU|MCG|MC|MG|G|IU|UNITS?|U|%|ML)(?![A-Z])(?:\s*\/\s*(\d+(?:[.,]\d+)?\s*ML|ML|DOSE|DOS|TAB|CAP|VIAL|AMP|[\d.,]+))?/i;

	/* A combination whose parts each carry a unit, in brackets
	   («(70mg+140mcg) (5600IU)/TAB», «(1500MG+1000 IU)/TAB», «(2%+0,5%)»):
	   kept whole — the first part alone names another product
	   (FOSAVANCE 70mg/2800IU and 70mg/5600IU). A bracketed amount right
	   after it («(5600IU)») belongs to it too. */
	var STRENGTH_UNIT = '(?:ANTI-?XA\\s*IU|MCG|MC|MG|G|IU|UNITS?|U|%|ML)(?![A-Z])';
	var STRENGTH_PART = '[\\d.,]+\\s*' + STRENGTH_UNIT;
	var COMBO_STRENGTH = new RegExp('\\((?:' + STRENGTH_PART + '\\s*\\+\\s*)+' + STRENGTH_PART + '\\)(?:\\s*\\(' + STRENGTH_PART + '\\))*' +
		'(?:\\s*\\/\\s*(\\d+(?:[.,]\\d+)?\\s*ML|ML|DOSE|DOS|TAB|CAP|VIAL|AMP))?', 'i');

	/* A number with a unit: what is left of a strength that was not read. */
	var STRENGTH_LEFT = new RegExp('\\d\\s*' + STRENGTH_UNIT, 'i');

	/* Where the pack starts («BTx5 PENSx3ML», «BT X 1BOTTLE
	   X30ML», «FLx…»). Nothing after it is a strength. */
	var PACK = /(^|[^A-Z])(BTX?|FLX?)(?=[^A-Z]|$)|\s[X×]\s*\d/i;

	function tidyStrength(m) {
		var amount = m[1].replace(/\s+/g, '');
		/* Units in IU as written, with or without a space
		   («250 IU/ML», «25000IU/CAP», «4500antiXA iu»). */
		var sp = /\s$/.test(m[1]) ? ' ' : '';
		var unit = m[2].toUpperCase().replace(/\s+/g, '');
		unit = /XA/.test(unit) ? sp + m[2].replace(/\s+/g, ' ').toLowerCase() : { MCG: 'mcg', MC: 'mcg', MG: 'mg', G: 'g', IU: sp + 'IU', U: 'U', UNIT: sp + 'IU', UNITS: sp + 'IU', '%': '%', ML: 'ml' }[unit] || unit.toLowerCase();
		var per = (m[3] || '').toUpperCase().replace(/\s+/g, '');
		var tail = '';
		if ('DOSE' === per || 'DOS' === per) {
			tail = '/δόση';
		} else if (/ML$/.test(per)) {
			tail = '/' + per.toLowerCase();
		}
		return amount + unit + tail;
	}

	/* Units in a combination: as tidyStrength() writes them. */
	function tidyUnits(text) {
		return text.replace(/(\d)\s*(ANTI-?XA\s*IU|MCG|MC|MG|G|IU|UNITS?|U|ML)(?![A-Z])/gi, function (all, d, u) {
			var k = u.toUpperCase().replace(/\s+/g, '');
			return d + ({ MCG: 'mcg', MC: 'mcg', MG: 'mg', G: 'g', ML: 'ml', U: 'U' }[k] || (/XA/.test(k) ? 'anti-XaIU' : 'IU'));
		});
	}

	/**
	 * The strength of a drug line, from the text after the form
	 * code and before the pack. `unsure` when any part of it is not in
	 * what was read: a number before it («1229,6(121,5Mg++)MG»), a «+»
	 * next to it («800MG+0,200(0,185) MG/15ML»), another amount with a
	 * unit after it, or no strength at all. The name then gets the
	 * «strength» confirmation — never a silently shortened strength.
	 */
	/* «340mcg+12mcg» — parts joined by «+», each with its unit. */
	var PLUS_STRENGTH = new RegExp(STRENGTH_PART + '(?:\\s*\\+\\s*' + STRENGTH_PART + ')+', 'i');

	/* Amounts with their units joined by «/» («5MG/160MG»,
	   «300MG/15G»; not «/ML», which is the concentration). */
	var SLASH_STRENGTH = new RegExp(STRENGTH_PART + '(?:\\s*\\/\\s*[\\d.,]+\\s*(?:MCG|MC|MG|G|IU|%)(?![A-Z]))+', 'i');

	/* «(1%+0.2)%» — a percentage combination with the sign after
	   the bracket. */
	var PCT_COMBO = /\((?:[\d.,]+%?\s*\+\s*)+[\d.,]+%?\)\s*%/;

	/* What may follow the strength without being a lost part of
	   it: a bracketed equivalent concentration («10mg/5ml (2mg/ml)»), or
	   the dose / volume note and the concentration of a pen («1MG/0,74
	   (1 δόση) 1,34MG/ML»). */
	var AFTER_OK = /^\s*(?:\(\s*[\d.,]+\s*(?:MCG|MG|G|IU)\s*\/\s*ML\s*\)|(?:\(\s*\d+\s*(?:ΔΟΣ[Α-Ω]*\.?|DOSES?)\s*\)\s*)?[\d.,]+\s*(?:MCG|MG|G|IU)\s*\/\s*ML)(?:\s+\d+(?:\s+ΠΡ\.?)?)?\s*$/i;

	function readStrength(head) {
		/* An ion content in brackets between the amount and its
		   unit («1229,6(121,5Mg++)MG») is not the strength: left out. */
		var ion = /(\d[\d.,]*)\((\s*[\d.,]+\s*(?:MG|MCG|G)\s*\+{1,2}\s*)\)/i.exec(head);
		head = head.replace(/(\d)\(\s*[\d.,]+\s*(?:MG|MCG|G)\s*\+{1,2}\s*\)/gi, '$1');
		var sm = STRENGTH.exec(head);
		var cm = COMBO_STRENGTH.exec(head);
		var pm = PLUS_STRENGTH.exec(head);
		var qm = PCT_COMBO.exec(head);
		var start;
		var end;
		var text;
		var perDropped = false;
		if (qm && (!sm || qm.index <= sm.index)) {
			start = qm.index;
			end = start + qm[0].length;
			text = qm[0].replace(/\s+/g, '');
		} else if (cm && (!sm || cm.index <= sm.index)) {
			start = cm.index;
			end = start + cm[0].length;
			var per = (cm[1] || '').toUpperCase().replace(/\s+/g, '');
			var body = cm[1] ? cm[0].slice(0, cm[0].lastIndexOf('/')) : cm[0];
			text = tidyUnits(body.replace(/\(([^)]*)\)/g, function (all, inner) {
				return '(' + inner.replace(/\s+/g, '') + ')';
			}).replace(/\)\s*\(/g, ') (').trim());
			if ('DOSE' === per || 'DOS' === per) {
				text += '/δόση';
			} else if (/ML$/.test(per)) {
				text += '/' + per.toLowerCase();
			}
		} else if (pm && sm && pm.index === sm.index) {
			start = pm.index;
			end = start + pm[0].length;
			text = tidyUnits(pm[0].replace(/\s+/g, ''));
		} else if (sm) {
			start = sm.index;
			end = start + sm[0].length;
			text = tidyStrength(sm);
			/* «5MG/160MG», «300MG/15G» — a second amount with its
			   own unit after «/» is part of the strength, kept whole. */
			var sl = SLASH_STRENGTH.exec(head.slice(start));
			if (sl && 0 === sl.index && sl[0].length > sm[0].length - (sm[3] ? sm[0].length - sm[0].lastIndexOf('/') : 0)) {
				end = start + sl[0].length;
				text = tidyUnits(sl[0].replace(/\s+/g, ''));
			} else if (/^[\d.,]+$/.test(sm[3] || '')) {
				/* «5MG/160»: a bare number after «/» was left out (the
				   volume of a pen dose, «1MG/0,74», is fine: drugName()). */
				perDropped = true;
			}
		} else {
			/* No number with a unit at all (a vaccine): nothing was
			   dropped, nothing to confirm. A number without its unit
			   («CREAM 0,02») is confirmed. */
			return { text: '', found: false, unsure: /\d/.test(head) };
		}
		var before = head.slice(0, start);
		var after = head.slice(end);
		/* A strength written without its leading zero («.25MG»,
		   «,5MG») is 0,25 / 0,5 — never 25 / 5, a hundred or ten times the
		   strength. The zero is put back (with the separator as written)
		   and the medicine goes through the form («strengthZero»), so the
		   pharmacist compares it with the prescription: a plain
		   «Επιβεβαιώνω» is not enough when the magnitude was in doubt. */
		/* Also with a space, a no-break space (PDF copy) or a tab
		   between the separator and the number («. 25MG», «,\u00a05MG»),
		   and with a doubled separator («..5MG», «.,5MG»): read as 25MG /
		   5MG — the spaced form with no warning at all. The last separator
		   is the decimal one.
		   A separator glued to a word before a space («SR. 5MG») is an
		   abbreviation, not a decimal point: left as it was. Glued to a
		   bracket or other punctuation («(. 25MG)», «/. 5MG») it is not. */
		/* A number split by a space around its separator («0. 25MG»,
		   «1 . 5MG», «2 ,5MG») was read from its last part only (25MG / 5MG)
		   behind a plain confirmation. The parts are joined back as written
		   and the medicine goes through the form («strengthSplit»). Checked
		   first: «1 . 5MG» is 1.5, not 0.5. */
		var splitNum = /(\d+)(?:\s+[.,]\s*|[.,]\s+)$/.exec(before);
		var splitNumber = !!splitNum && /^\d/.test(text);
		var leadSep = splitNumber ? null : (/(?:^|[^\d.,])[.,]*([.,])$/.exec(before) || /(?:^|[^0-9A-Za-z\u0370-\u03ff\u1f00-\u1fff])[.,]*([.,])\s+$/.exec(before));
		var leadingZero = !!leadSep && /^\d/.test(text);
		/* Any other dot or comma right before the number («1X. 5MG»,
		   «ΤΑΒ. 25MG»), or another number with only a space between («0 25MG»,
		   «1 5MG»), may be a lost zero or decimal point too: the number stays
		   as written, but the row goes through the form («strengthSep»),
		   never past a plain «Επιβεβαιώνω». */
		var sepAdjacent = !leadingZero && !splitNumber && /^\d/.test(text) && /[.,]\s*$|\d\s+$/.test(before);
		/* A unit left right after the strength («5MG/160» + «MG»)
		   or another «/amount unit» is a dropped part. */
		/* A strength glued to a dot or comma after a digit
		   («1.5MG» split oddly) is still only confirmed. */
		var unsure = /\d/.test(before) || (/[.,]$/.test(before) && !leadingZero) || /\+\s*$/.test(before) || /^\s*\+/.test(after) ||
			/^\s*(?:MCG|MC|MG|G|IU|%)(?![A-Z])/i.test(after) || /^\s*\/\s*[\d.,]+\s*(?:MCG|MC|MG|G|IU|%)(?![A-Z])/i.test(after) ||
			(STRENGTH_LEFT.test(after) && !AFTER_OK.test(plain(after)));
		/* The ion content stays in the name, where it was written
		   («1229,6(121,5MG++)MG»). */
		if (ion && 0 === text.indexOf(ion[1])) {
			text = ion[1] + '(' + tidyUnits(ion[2].replace(/\s+/g, '')) + ')' + text.slice(ion[1].length);
		}
		if (splitNumber) {
			text = splitNum[1] + /[.,]/.exec(before.slice(splitNum.index + splitNum[1].length))[0] + text;
		} else if (leadingZero) {
			text = '0' + leadSep[1] + text;
		}
		return { text: text, found: true, unsure: unsure, perDropped: perDropped, leadingZero: leadingZero, splitNumber: splitNumber, sepAdjacent: sepAdjacent };
	}

	/**
	 * What one box holds, from the drug line (status left out).
	 * `count`: tablets / capsules of a solid form («BTx30», «BT x 28»,
	 * «BTX1 VIAL HDPE X 31 CAPS», «BTX1X30», «4X10»); `single`: one
	 * pre-filled syringe or ampoule. Both are reliable. The others are
	 * only shown, never warned about: metered doses, containers (a vial
	 * is not always a dose), a volume in ml.
	 *
	 * @param {string} t    Latin capitals of the drug line.
	 * @param {string} g    The drug line in plain capitals (Greek kept).
	 * @param {string} form The form code.
	 */
	function readPack(t, g, form) {
		var p = {};
		var m;
		/* Also «MOD.R.CA.H» (modified-release hard capsule). */
		if (/(^|\.)(TABS?|TA|T|CAPS?|CA|SUPP)(\.|$)/.test(form)) {
			if ((m = /BTX1\s*VIAL.*?X\s*(\d+)\s*CAPS/.exec(t)) || (m = /(?:^|[^A-Z])BTX1X(\d+)/.exec(t)) ||
				(m = /(?:(?:^|[^A-Z])BT|BOTTLE)\s*X\s*(\d+)/.exec(t)) || (m = /(?:^|[^A-Z0-9,.])(\d+X\d+)\s*$/.exec(t))) {
				var parts = m[1].split('X');
				var count = parts.length > 1 ? parseInt(parts[0], 10) * parseInt(parts[1], 10) : parseInt(parts[0], 10);
				/* «BTx1FLx30»: one bottle of 30, not one tablet. */
				var inner = /^1$/.test(m[1]) && /X\s*(\d+)(?!\s*(?:ML|G)(?![A-Z]))/.exec(t.slice(m.index + m[0].length));
				p.count = inner ? parseInt(inner[1], 10) : count;
			}
		} else if ((/BT\s*X\s*1\s*(PF(?![.,]?\s?PEN)|AMP|VIAL\+1)/.test(t) || /(^|[^0-9])1 PF\.SYR X/.test(t)) && /INJ|IN\.SO|PFS/.test(t)) {
			/* (never a pen: it holds several doses) */
			p.single = 1;
		}
		if ((m = /(\d+)\s*(?:DOSES|ΔΟΣΕΙΣ|ΨΕΚΑΣΜΟΙ)/.exec(g)) || (m = /(?:DISKUS|ΕΙΣΠΝΟΗΣ)\s*X\s*(\d+)/.exec(g))) {
			p.doses = parseInt(m[1], 10);
		}
		if ((m = /BT\s*X\s*(\d+)\s*(?:AMPS?|PF,?\s*SYRS?|VIALS?|SACH)/.exec(t)) || (m = /(?:^|[^0-9])(\d+)PF,SYR/.exec(t))) {
			p.containers = parseInt(m[1], 10);
		}
		if ((m = /(?:BOTTLE\s*X|FLX|BTX)\s*(\d+(?:,\d+)?)\s*ML/.exec(t))) {
			p.ml = parseFloat(m[1].replace(',', '.'));
		}
		return p;
	}

	function drugName(block) {
		var text = clean(block)
			/* «(Πρωτότυπο …)», «(Γενόσημο)», however the line broke. */
			.replace(/\((?:Πρωτότυπο|Γενόσημο)[^)]*\)?/g, ' ')
			.replace(/\s+/g, ' ').trim();
		var words = text.split(' ');
		var formAt = -1;
		for (var i = 1; i < words.length; i++) {
			if (FORM_TOKEN.test(latin(words[i]).toUpperCase())) {
				formAt = i;
				break;
			}
		}
		/* The brand as written, with its brackets («LIPIDIL (NT)»,
		   «TOUJEO (SOLOSTAR)», «VAQTA(ΕΜΒΟΛΙΟ … Α)»): they can tell two
		   products apart. */
		var brand = (formAt > 0 ? words.slice(0, formAt) : words.slice(0, 1)).join(' ').trim();
		/* A Greek mu or micro sign before «G» that is not the
		   plain «μg» (made MCG in normalizeInput()) — «100ΜG», «100μG»,
		   «100µG», «100μgr» — would fold to «100MG», 1000 times the
		   strength. It is never read: no strength, and confirmed. */
		var restRaw = formAt > 0 ? words.slice(formAt + 1).join(' ') : words.slice(1).join(' ');
		var muG = MU_G_ONE.test(restRaw);
		var rest = latin(restRaw.replace(MU_G, '$1\u00a4'));
		/* Only what comes before the pack can be the strength
		   («LANTUS … 100U/ML BTx5 PENSx3ML» is not «3ML»). */
		var pack = PACK.exec(rest);
		/* Also «TUBx15G», «PF.SYR», «VIALS x 15ML», … */
		var packWord = PACK_TOKEN.exec(rest.toUpperCase());
		var packAt = pack ? pack.index : -1;
		if (packWord && (packAt < 0 || packWord.index + packWord[1].length < packAt)) {
			packAt = packWord.index + packWord[1].length;
		}
		/* … or a Greek pack («1 πρ. συσκ. τύπου πένας»). */
		var packGr = PACK_GREEK.exec(plain(rest));
		if (packGr && (packAt < 0 || packGr.index + packGr[1].length < packAt)) {
			packAt = packGr.index + packGr[1].length;
		}
		var head = packAt >= 0 ? rest.slice(0, packAt) : rest;
		var st = readStrength(head);
		var strength = st.text;
		/* A strength only after the pack is not guessed: left out of the
		   name and flagged. So is a drug line with no strength
		   (a vaccine: «HEXYON INJ.SUSP BTx1PF,SYR»), and one whose
		   strength was only partly read. */
		/* No strength at all is confirmed except on an injectable
		   / vaccine form (INJ, SUSP, PFS, a vial, an ampoule), whose drug
		   line often has none. */
		/* And eye / ear drops (combined ear drops often have none). */
		var injectableForm = formAt > 0 && /INJ|SUS|PFS|PF\.?SYR|VIAL|AMP|IN\.S|DRO|DR\.|(^|\.)EA\.|(^|\.)EY\./.test(latin(words[formAt]).toUpperCase());
		var strengthUnsure = formAt > 0 && (st.unsure || muG || (!st.found && !injectableForm) || (st.perDropped && !injectableForm));
		/* (pharmacy's choice) Brand and form as written, without
		   the pack, plus the strength — «SINTROM TAB 1MG» and
		   «SINTROM TAB 4MG» must never print as the same «SINTROM TAB».
		   Latin units in capitals, like the rest of the name. */
		var nameStrength = strength.replace(/[a-z]+/g, function (u) {
			return u.toUpperCase();
		});
		return {
			name: brand + (formAt > 0 ? ' ' + words[formAt] : '') + (nameStrength ? ' ' + nameStrength.trim() : ''),
			strength: strength,
			strengthUnsure: strengthUnsure,
			strengthZero: !!st.leadingZero,
			strengthSplit: !!st.splitNumber,
			strengthSep: !!st.sepAdjacent,
			form: formAt > 0 ? latin(words[formAt]).toUpperCase() : '',
			latinText: latin(text).toUpperCase(),
			pack: readPack(latin(text).toUpperCase(), plain(text), formAt > 0 ? latin(words[formAt]).toUpperCase() : ''),
			rawText: text,
			sure: formAt > 0
		};
	}

	/* ---------------------------------------------------------------- */
	/* Dose line                                                          */
	/* ---------------------------------------------------------------- */

	/* ---------------------------------------------------------------- */
	/* Every character after the first medicine is consumed by */
	/* a fully matched known pattern, or it is an unknown fragment.     */
	/* ---------------------------------------------------------------- */

	var MONEY = '\\d+,\\d\\d';

	/* A price row, matched whole: percentage, quantity, then 1–6 money
	   fields (Τιμή, Αποζ., Σύνολο, Διαφορά, Ασφ., Ταμείου; «ΙΦΕΤ» instead
	   of a price). Six fields is complete. */
	var PRICE_EXACT = /^\d{1,3}% \d+(?: (?:\d+,\d\d|ΙΦΕΤ|IFET)){1,6}$/;

	/* Inside a line: a price row or the totals header, bounded by spaces. */
	var PRICE_OR_TOTALS = /Μονάδος Αποζ\. Ασφ\. Ασφ\/νου Ταμείου|\d{1,3}% \d{1,3}% \d{1,3}% Άλλο|\d{1,3}% \d+(?: (?:\d+,\d\d|ΙΦΕΤ|IFET)){1,6}/g;

	var TOTALS_HEADER = /^\d{1,3}% \d{1,3}% \d{1,3}% Άλλο$/;

	function priceFields(seg) {
		return seg.split(' ').length - 2;
	}

	function isMoneyOnly(seg) {
		return new RegExp('^' + MONEY + '(?: ' + MONEY + ')*$').test(seg);
	}

	function moneyCount(seg) {
		return seg.split(' ').length;
	}

	/* A sequence of small quantities («0,25 0,50 0,25 0,50 …», «1/2 1/4
	   1/2»): a dose that changes by day, never a fixed schedule. */
	function isDoseSequence(seg) {
		var toks = seg.split(' ');
		if (toks.length < 3) {
			return false;
		}
		var frac = false;
		for (var i = 0; i < toks.length; i++) {
			var m = /^(\d+)(?:[.,](\d+))?$|^(\d+)\/(\d+)$/.exec(toks[i]);
			if (!m) {
				return false;
			}
			var v = m[3] ? parseInt(m[3], 10) / (parseInt(m[4], 10) || 1) : parseFloat(m[1] + '.' + (m[2] || '0'));
			if (!(v <= 20)) {
				return false;
			}
			if (m[2] || m[3]) {
				frac = true;
			}
		}
		return frac;
	}

	/* Known lines of the print-out, each matched WHOLE (a line that only
	   starts like one is not one). 'list' starts the medicine list, 'end'
	   closes it (totals, footer, page break, next prescription's header),
	   'neutral' is the price table's headings. */
	var NAME2 = '[\\u0386-\\u03a9A-Z][\\u0386-\\u03a9A-Z\\-]*(?: [\\u0386-\\u03a9A-Z][\\u0386-\\u03a9A-Z\\-]*)?';
	var KNOWN_LINES = [
		['list', /^Μονάδος Αποζ\. Ασφ\. Ασφ\/νου Ταμείου$/],
		['neutral', /^(Συμ\. Ποσότητα|\(τεμάχια\)|Τιμή \(€\) Σύνολο|\(€\)|Διαφορά|Συμμετοχή \(€\)|Διαφορά \(€\)|Σύνολο \(€\)|Τιμή \(€\))$/],
		['end', new RegExp('^ΣΥΝΟΛΟ : ' + MONEY + ' €$')],
		['end', new RegExp('^ΣΥΜΜΕΤΟΧΗ ΑΣΦΑΛΙΣΜΕΝΟΥ : ' + MONEY + ' €$')],
		['end', new RegExp('^ΔΙΑΦΟΡΑ ΠΛΗΡΩΤΕΑ ΑΠΟ (?:ΑΣΦ\\/ΝΟ|ΤΑΜΕΙΟ) : ' + MONEY + ' €$')],
		['end', new RegExp('^ΠΛΗΡΩΤΕΟ ΠΟΣΟ ΑΠΟ (?:ΑΣΦ\\/ΝΟ|ΤΑΜΕΙΟ) € ' + MONEY + '$')],
		['end', /^(Κολλήστε|ταινία|Κολλήστε ταινία)$/],
		['end', /^Σελίδα \d+ ?(?:\/|από) ?\d+$/],
		['end', /^ΕΛΛΗΝΙΚΗ ΔΗΜΟΚΡΑΤΙΑ$/],
		['end', /^ΥΠΟΥΡΓΕΙΟ(?:,? (?:ΕΡΓΑΣΙΑΣ|ΚΟΙΝΩΝΙΚΗΣ|ΚΟΙΝΩΝΙΚΩΝ|ΑΣΦΑΛΙΣΗΣ|ΑΛΛΗΛΕΓΓΥΗΣ|ΠΡΟΝΟΙΑΣ|ΥΠΟΘΕΣΕΩΝ|ΥΓΕΙΑΣ|ΚΑΙ|&))+$/],
		['end', /^Σ Υ Ν Τ Α Γ Η$/],
		/* The dotted-initials lines seen on the real print-outs
		   («O.N.» under the ministry's name, «Ο.Γ.Α.», «Ι.Κ.Α.-Ε.Τ.Α.Μ.» on the
		   older ones); a generic «Χ.Χ.» rule took in «P.O.» or «Π.Χ.-Μ.Φ.». */
		['end', /^(?:[OΟ]\.[NΝ]\.|[OΟ]\.Γ\.[AΑ]\.|Ι\.Κ\.Α\.-[EΕ]\.[TΤ]\.[AΑ]\.[MΜ]\.)$/],
		['end', /^«\S*¬$/],
		['end', /^\d{6,}$/],
		['end', /^\d{2}\/\d{2}\/\d{2}(?:\d{2})?$/],
		['end', /^(?:ΘΕΡΑΠΕΙΑ|ΧΡΟΝΙΑ ΠΑΘΗΣΗ|ΕΚΑΣ) :(?: (?:ΝΑΙ|ΟΧΙ))?$/],
		['end', /^(?:ΑΠΟ|ΕΩΣ) : \d{2}\/\d{2}\/\d{2}(?:\d{2})?$/],
		['end', /^ΕΠΑΝΑΛΗΨΗ : \d+ \/ \d+(?: \(ανά \d+ημ\.\))?$/],
		['end', /^(?:«\S*¬ )?ΗΜ\/ΝΙΑ ΕΚΤΕΛΕΣΗΣ:$/],
		['end', /^(?:Α\.Μ\.Κ\.Α\.|Ε\.Τ\.Α\.Α\.) ΦΑΡΜΑΚΟΠΟΙΟΥ:$/],
		['end', /^Α\.Φ\.Μ\. ΦΑΡΜΑΚΕΙΟΥ:$/],
		['end', /^\(ΥΠΟΓΡΑΦΗ(?:-ΣΦΡΑΓΙΔΑ)?\)(?: \(ΥΠΟΓΡΑΦΗ ΑΣΦΑΛΙΣΜΕΝΟΥ\))?(?: \(ΥΠΟΓΡΑΦΗ-ΣΦΡΑΓΙΔΑ\))?$/],
		['end', /^ΣΤΟΙΧΕΙΑ ΙΑΤΡΟΥ ΣΤΟΙΧΕΙΑ ΑΣΘΕΝΗ$/],
		['end', new RegExp('^ΕΠΩΝΥΜΟ : ' + NAME2 + ' ΕΠΩΝΥΜΟ : ' + NAME2 + '$')],
		['end', new RegExp('^ΟΝΟΜΑ : ' + NAME2 + ' ΟΝΟΜΑ : ' + NAME2 + '$')],
		['end', /^Α\.Μ\.Κ\.Α\. : \d+ Α\.Μ\.Κ\.Α\. : \d+$/],
		['end', /^Ε\.Τ\.Α\.Α\. : \d+ Α\.Μ\.Α\. : \d+(?: [Α-Ω]+)?$/],
		['end', /^ΤΗΛΕΦΩΝΟ : \d+$/],
		['end', /^ΣΥΜΠΛΗΡΩΝΕΤΑΙ ΑΠΟ ΤΟΝ ΦΑΡΜΑΚΟΠΟΙΟ$/],
		/* (Measured on 235 real print-outs, each matched whole):
		   the prescription barcode in its barcode font, alone or glued to
		   «ΗΜ/ΝΙΑ ΕΚΤΕΛΕΣΗΣ:» (F01); its number, 13 digits and the fund
		   code (F03); the fund's line of the header (F04, F05, closed
		   lists); the reason for a zero co-payment (F07); the signature
		   captions glued to the page number in a Firefox copy (F08). */
		['end', /^\u00cd[\x21-\x7e\u00a1-\u00ff]{9}\u00ce(?: ΗΜ\/ΝΙΑ ΕΚΤΕΛΕΣΗΣ:)?$/],
		['end', /^\d{13} \d{3}$/],
		['end', /^(?:Ι\.Κ\.Α\.(?: - πρώην (?:Ο\.Π\.Α\.Δ\.|Τ\.Υ\.Δ\.Κ\.Υ\.))?|E\.T\.A\.A\. - (?:Τ\.Υ\.Μ\.Ε\.Δ\.Ε\.|Τ\.Υ\.Υ\.)|Τ\.Α\.Υ\.Τ\.Ε\.Κ\.Ω\. - (?:Κ\.Α\.Π\. Δ\.Ε\.Η\.|Τ\.Α\.Π\. Ο\.Τ\.Ε\.)|Δικαιούχοι του Ν\.\d{4}\/\d{4}|Πολίτες Εξωτερικού)$/],
		['end', /^(?:Ίδρυμα Κοινωνικών Ασφαλίσεων(?: - πρώην (?:Ο\.Π\.Α\.Δ\.|Τ\.Υ\.Δ\.Κ\.Υ\.))?|ΕΤΑΑ - Τομέας Υγείας (?:Μηχανικών & Ε\.Δ\.Ε\.|Υγειονομικών)|ΤΑΥΤΕΚΩ - Ταμείο Ασθενείας Προσωπικού (?:ΔΕΗ|ΟΤΕ)|Οργανισμός Ασφάλισης Ελεύθερων Επαγγελματιών|Οργανισμός Γεωργικών Ασφαλίσεων|Οίκος Ναύτου|Δικαιούχοι του άρθρου \d+ του Ν\.\d{4}\/\d{4}|Πολίτες Εξωτερικού χωρίς ΕΚΑΑ, ΑΜΚΑ ή άλλο εθνικό αριθμό τύπου ΑΜΚΑ) \d{13} \d{3}$/],
		/* The reason for a zero co-payment, only the values seen
		   (anything else after the label is unknown text). */
		['end', /^ΑΙΤΙΑ ΜΗΔΕΝΙΚΗΣ ΣΥΜ\/ΧΗΣ: (?:ΚΥΗΣΗ ΚΑΙ ΛΟΧΕΙΑ|ΝΕΦΡΟΠΑΘΕΙΣ ΣΕ ΑΙΜΟΚΑΘΑΡΣΗ|ΤΕΛ\. ΣΤΑΔ\. ΧΡΟΝ\. ΝΕΦΡΙΚΗΣ ΝΟΣΟΥ ΕΞΩΝΕΦΡΙΚΗ ΚΑΘΑΡΣΗ)$/],
		['end', /^(?:\((?:ΥΠΟΓΡΑΦΗ|ΥΠΟΓΡΑΦΗ-ΣΦΡΑΓΙΔΑ|ΥΠΟΓΡΑΦΗ ΑΣΦΑΛΙΣΜΕΝΟΥ)\) ?){1,3}Σελίδα \d+ ?(?:\/|από) ?\d+$/]
	];

	function knownLineKind(seg) {
		for (var i = 0; i < KNOWN_LINES.length; i++) {
			if (KNOWN_LINES[i][1].test(seg)) {
				return KNOWN_LINES[i][0];
			}
		}
		return '';
	}

	/* The barcode pages: «Barcode: …», «PC: …», «SN: …», «Batch: …».
	   Only the shapes of the 235 real print-outs — Barcode 13
	   digits, PC 14 digits, SN 8–16 letters/digits, Batch 2–9
	   letters/digits or «25-025»; SN and Batch with at least one digit,
	   so «SN: HS» or «Batch: STOP-3D» is never taken for one. */
	var BARCODE_VALUE = '(?:Barcode: \\d{13}|PC: \\d{14}|SN: (?=[A-Z]*\\d)[0-9A-Z]{8,16}|Batch: (?:(?=[A-Z]*\\d)[0-9A-Z]{2,9}|\\d{2}-\\d{3}))';
	var BARCODE_LINE = new RegExp('^' + BARCODE_VALUE + '$');

	/* … also glued to the end of the sticker's drug line. */
	var BARCODE_TAIL = new RegExp(' (' + BARCODE_VALUE + ')$');

	/* ---- the drug line ---- */

	/* The pack part of an ΗΔΙΚΑ drug line («BTx30», «BT x 28», «ΒΤΧ10»,
	   «btx28», «FLx10», «BTx1FLx30», «TUB x 10 G», «AMP BTx10», «40MG/VIAL
	   1VIALX40MG», «BTX 1PF.SYR», «BTx3 PF.PENS», «DISKUSx60»), on the text
	   folded to Latin capitals. */
	var PACK_TOKEN = /(^|[^A-Z])(BTX?|FLX?|TUBX?|VIALS?X?|AMPS?X?|PF[.,]?\s?SYRS?|PF\.?PENS?|STRIPS?|PENS?X?|SACHETS?X?|BOTTLES?X?|DISKUSX?|CARTRIDGES?X?|INHALERX?|BLIST[A-Z]*)(?=[^A-Z]|$)/;

	/* … or in Greek («1 πρ. συσκ.», «φιαλίδιο», «φύσιγγες», «κουτί»). */
	var PACK_GREEK = /(^|[^Α-Ω])(ΣΥΣΚ|ΦΙΑΛ|ΦΥΣΙΓΓ|ΚΟΥΤ|ΣΩΛΗΝΑΡ|ΠΕΝΑ|ΣΑΚΚΟΣ|ΤΑΙΝΙΑ)/;

	/* … or a count pack alone («100MG 4X10»): at least 2 × 5, so
	   an instruction such as «1X1» or «2X1» is never a pack. */
	function hasPackCount(text) {
		var re = /(^|[^A-Z0-9,.])(\d+)X(\d+)(?=[^A-Z0-9,.]|$)/g;
		var m;
		while ((m = re.exec(text))) {
			if (parseInt(m[2], 10) >= 2 && parseInt(m[3], 10) >= 5) {
				return true;
			}
		}
		return false;
	}


	/* The words a pack / strength / status part may be made of (derived
	   from all 22 samples). A token of the text after the form code is
	   part of the drug line only when, with these words removed, nothing
	   but digits and signs is left. */
	var TAIL_WORDS = [
		'BLISTERS', 'BLISTER', 'BLIST', 'BOTTLES', 'BOTTLE', 'DISKUS', 'SACHETS', 'SACHET', 'CARTRIDGES', 'CARTRIDGE',
		'INHALER', 'DOSES', 'DOSE', 'DOS', 'TABLETS', 'TABS', 'TAB', 'CAPSULES', 'CAPS', 'CAP', 'AMPOULES', 'AMPS', 'AMP',
		'VIALS', 'VIAL', 'PENS', 'PEN', 'SYRS', 'SYR', 'PF', 'BT', 'FL', 'TUB', 'MCG', 'MC', 'MG', 'ML', 'IU', 'UNITS', 'UNIT',
		'SOLOSTAR', 'OPA', 'ALU', 'PVC', 'PP', 'PCS', 'W', 'G', 'U', 'X',
		'ΔΟΣΕΙΣ', 'ΔΟΣΗ', 'ΔΙΣΚΙΑ', 'ΔΙΣΚΙΟ', 'ΚΑΨΟΥΛΕΣ', 'ΦΑΚΕΛΙΣΚΟΙ', 'ΦΙΑΛΙΔΙΑ', 'ΦΙΑΛΙΔΙΟ', 'ΦΥΣΙΓΓΕΣ', 'ΤΕΜΑΧΙΑ',
		'ΔΟΣΙΜΕΤΡΙΚΗ', 'ΣΥΡΙΓΓΑ', 'ΠΡ', 'ΣΥΣΚ', 'ΤΥΠΟΥ', 'ΠΕΝΑΣ', 'ΒΕΛΟΝΕΣ', 'ΚΟΥΤΙ',
		'ΠΡΩΤΟΤΥΠΟ', 'ΘΕΡΑΠΕΥΤΙΚΗ', 'ΚΑΤΗΓΟΡΙΑ', 'ΧΩΡΙΣ', 'ΓΕΝΟΣΗΜΟ', 'ΣΕ', 'ΜΕ',
		/* Packaging words of the 235 real print-outs. */
		'HDPE', 'LDPE', 'LPDE', 'PVDC', 'ALUMINIUM', 'AL', 'FOILS', 'FOIL', 'BLST', 'BL', 'STRIPS', 'STRIP', 'BAG', 'SOLV',
		'ACTUATION', 'ACTUATIONS', 'PLASTIC', 'STAINLESS', 'STEEL', 'SUPP', 'EX-VALVE', 'SD', 'SUP', 'SACH', 'W/V', 'W/W', 'V/V', 'ANTIXA', 'ANTI-XA',
		'ΣΑΚΚΟΣ', 'ΦΙΑΛΗ', 'ΠΛΑΣΤ', 'ΦΑΚ', 'ΣΥΣΚΕΥΗ', 'ΠΕΡΙΕΚΤΕΣ', 'ΕΝΣΩΜΑΤΩΜΕΝΗ', 'DOUBLESTAR', 'ΕΙΣΠΝΟΗΣ', 'ΕΙΣΠΝΟΩΝ', 'ΤΟΥΛΑΧΙΣΤΟΝ', 'ΨΕΚΑΣΜΟΙ', 'ΠΕΡΙΕΚΤΗ',
		'ΜΕΤΡΗΤΗ', 'ΔΟΣΗΣ', 'ΤΑΙΝΙΑ', 'ΚΥΨΕΛΗ', 'ΚΥΨΕΛΕΣ', 'ΔΟΣΟΜΕΤΡΙΚΗ', 'ΣΤΑΓΟΝΟΜΕΤΡΙΚΑ', 'ΒΕΛΟΝΑ', 'ΞΕΧΩΡΙΣΤΕΣ', 'ΔΟΣ',
		/* «BTx1PF.SYRx0,5ML (γυάλινη) (DOSE) + 2 βελόνες» (VAXELIS). */
		'ΓΥΑΛΙΝΗ', 'ΓΥΑΛΙΝΕΣ', 'ΓΥΑΛΙΝΟ', 'ΓΥΑΛΙΝΑ'
	].map(fold).sort(function (a, b) {
		return b.length - a.length;
	});

	var TAIL_RE = new RegExp(TAIL_WORDS.map(function (w) {
		return w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	}).join('|'), 'g');

	/* A dose-like fraction («1/2», «(1/4)», «+1/2», «1+1/2»,
	   «1/2-1/4») is never part of a pack: it ends the drug line, so it
	   is shown as unread text (a variable dose such as SINTROM
	   «1/4 1/2 1/4»), never absorbed silently. */
	function isFractionToken(tok) {
		var core = fold(tok).replace(/^[(\[]+|[)\]]+$/g, '');
		return /^[+\-]?(?:\d+\+)?\d+\/\d+(?:[+\-](?:\d+\+)?\d+\/\d+)*$/.test(core) ||
			/* …or glued to what is taken («1/2ΔΙΣΚΙΟ», «3X2TABS»). */
			/^(?:\d+\/\d+|\d+[X×]\d+)\s*(?:TABS?$|CAPS?$)/.test(core) ||
			/^[(\[]*(?:\d+\/\d+|\d+[XΧ×]\d+)\s*(?:ΔΙΣΚΙ|ΚΑΨΟΥΛ)/.test(plain(tok));
	}

	function isTailToken(tok) {
		var core = tok.replace(/^[(\[]+|[)\]]+$/g, '');
		if (FORM_TOKEN.test(latin(core).toUpperCase())) {
			return true;
		}
		return /^[\d\s.,+\/()\[\]\-%*:]*$/.test(fold(tok).replace(TAIL_RE, ' '));
	}

	/* English instruction words: a brand never runs back over one of
	   them (an extra signal that only ever flags more). */
	var NOT_BRAND = /^(TAKE|WITH|WITHOUT|FOOD|WATER|MEALS?|SWITCH|TO|ALTERNATE|ALTERNATING|AFTER|BEFORE|MAX|MAXIMUM|HALF|QUARTER|STOP|EXCEPT|THEN|UNTIL|IF|WHEN|FROM|FOR|AND|OR|NOT|NO|SKIP|OFF|ON|PER|AT|USE|APPLY|CHEW|WELL|SWALLOW|WHOLE|INSTEAD|OF|ONLY|EVERY|EACH|DAILY|DAYS|WEEKS?|WEEKLY|BEDTIME|MORNING|EVENING|NOON|MON|TUE|WED|THU|FRI|SAT|SUN|MONDAY|TUESDAY|WEDNESDAY|THURSDAY|FRIDAY|SATURDAY|SUNDAY|AS|DIRECTED|NEEDED|REQUIRED|PRN|PRO|RE|NATA|SOS|BID|TID|QID|QD|OD|TDS|BD|TAPER|PAUSE|THIN|LAYER|ALCOHOL|INR|TABLETS?|CAPSULES?|GIVE|INJECT|INHALE|PUFFS?|TIMES?|HOURS?|CONTINUE|START|HOLD|REDUCE|INCREASE|DECREASE|CHECK|MONITOR|SEE|NOTE|ACCORDING|LEVEL|SUGAR|PAIN|FEVER|NAUSEA|SLEEP|IN|THE|A|AN|BY|MOUTH|EYE|EAR|NOSE|SKIN|LEFT|RIGHT|BOTH|PO|HS|QHS|Q\d+H|STAT|AM|PM|IM|SC|IV|SL|OD|X\d+|WEAN|TITRATE|LOADING|BOLUS|RESCUE|BACKUP|TEST|KEEP|LOW|HIGH|CRUSH|DISSOLVE|CONTROL|PRWI|PROI|BRADY|VRADY|MESIMERI|APOGEVMA|MISO|META|PRIN|FAGHTO|FAGITO|KATHE|SE|PERIPTOSI|MONO|PONAEI|POTE|OTAN|EAN|NERO|YPNO|IMERA|IMERES)$/;

	/* A word of a Latin brand («OMNIC», «TOCAS», «(SOLOSTAR)», «DEXA-RHINASPRAY-N»). */
	function isBrandWord(w) {
		var core = w.replace(/^\(/, '').replace(/\)$/, '');
		return /^[A-Z][A-Z0-9\-\/+.']*$/.test(core.toUpperCase()) &&
			/[A-Za-z]/.test(core) && !/[Ͱ-Ͽ]/.test(core) && !NOT_BRAND.test(core.toUpperCase()) &&
			!FORM_TOKEN.test(core.toUpperCase());
	}

	/**
	 * Find the drug line inside a segment. For each form code,
	 * the brand is the Latin words right before it (at most three, «&»
	 * between them allowed; a Greek brand only when it is the first word
	 * and no Latin one exists), and after the form every token must belong
	 * to the strength / pack / status — which must include a strength and,
	 * after it, a pack. Returns the word range, or null.
	 */
	/* Memo for one parse (the same segment is looked at several times). */
	var brandMemo = {};

	/* maxWords: how many Latin words the brand may have (3; 1 when the
	   drug line does not start its physical line, round 6). */
	function findBrand(seg, maxWords) {
		var max = maxWords || 3;
		var key = max + '|' + seg;
		if (!Object.prototype.hasOwnProperty.call(brandMemo, key)) {
			brandMemo[key] = findBrandUncached(seg, max);
		}
		return brandMemo[key];
	}

	/* Words that follow a brand without making it another
	   medicine («MICARDIS PLUS», «SERETIDE DISKUS», «OMNIC TOCAS»,
	   «TETAGAM P»). A brand made of its main word and only these is not
	   confirmed; any other word before the form still is («PO BESPAR»). */
	var BRAND_QUALIFIER = /^(PLUS|FORTE|XR|MF|GR|ORO|FAST|C|P|FOL|DISKUS|NEXTHALER|ELLIPTA|GENUAIR|TOCAS|OAW|HEXA|POLIO|FLEX|PEN|HCT|H|AM|COMP|DUO|MET)$/;

	/* «ROSUVASTATIN/WIN MEDICA»: the substance / the company. Both parts
	   real words (never an instruction such as «PO/BID»). */
	/* Only with a company name seen on the real print-outs
	   (the word after «/», then the other words of the brand), so
	   «NIGHTLY/ORAL ROSUVA» still asks. */
	var MAH_FIRST = /^(ACARPIA|WIN|RAFARM|ARITI|SANDOZ|GENEPHARM|REKO|STADA|MEDICAL)$/;
	var MAH_REST = /^(MEDICA|PHARMAQUALITY)$/;

	function isInnMah(brandWords) {
		var m = /^([A-Z][A-Z0-9+\-]*)\/([A-Z][A-Z0-9\-]*)$/.exec(brandWords[0]);
		return !!m && m[1].length >= 5 && !NOT_BRAND.test(m[1]) && MAH_FIRST.test(m[2]) &&
			brandWords.slice(1).every(function (x) {
				return MAH_REST.test(x);
			});
	}

	/* A number in the brand — the ones seen on the real
	   print-outs, or the same number as the strength right after the
	   form («ORBIMAG 300 EF.TAB 300MG»). «BESPAR 2 TAB 10MG» is not. */
	var BRAND_NUMBER = /^(GARDASIL 9|PREVENAR 20|ORBIMAG 300)$/;

	function brandNumberOk(words, j, f) {
		if (!/^\d{1,3}$/.test(words[j])) {
			return false;
		}
		if (BRAND_NUMBER.test(words[j - 1] + ' ' + words[j])) {
			return true;
		}
		var m = /^\(?(\d+)/.exec(words[f + 1] || '');
		return !!m && m[1] === words[j];
	}

	/* The one «/WORD» brand part seen («FUSIDIC /TARGET»). */
	var SLASH_WORD = /^\/(TARGET)$/;

	/* A Latin brand with a Greek description in brackets glued
	   to it («VAQTA(ΕΜΒΟΛΙΟ ΚΑΤΑ ΤΗΣ ΗΠΑΤΙΤΙΔΑΣ Α)») is one brand word.
	   Returns the index of its first word, or -1. */
	function greekBracketBrand(words, j) {
		if (!/^[\u0386-\u03ce]*\)$/.test(words[j])) {
			return -1;
		}
		for (var k = j; k >= 0 && k >= j - 8; k--) {
			var m = /^([A-Z][A-Z0-9\-]*)\([\u0386-\u03ce]*(\))?$/.exec(words[k]);
			if (m) {
				/* One word «ABC(ΛΕΞΗ)» or «ABC(ΛΕΞΗ» … «ΛΕΞΗ)». */
				if ((k === j) !== !!m[2] || !isBrandWord(m[1])) {
					return -1;
				}
				return k;
			}
			if (k < j && !/^[\u0386-\u03ce]+$/.test(words[k])) {
				return -1;
			}
		}
		return -1;
	}

	/* Brackets still open in a text. */
	function parenDepth(t) {
		return (t.match(/\(/g) || []).length - (t.match(/\)/g) || []).length;
	}

	/* The pack words a lone count may follow (on fold()ed text). */
	var LONE_COUNT_AFTER = new RegExp('(' + ['BLIST', 'BLST', 'BL', 'STRIP', 'BT', 'AMP', 'TAB', 'CAP', 'VIAL', 'FL', 'PEN', 'DOSE', 'SACHET',
		'BOTTLE', 'TUB', 'ΣΥΣΚΕΥΗ', 'ΕΙΣΠΝΟ', 'ΤΑΙΝΙΑ'].map(fold).join('|') + ')');

	function findBrandUncached(seg, maxWords) {
		var words = seg.split(' ');
		for (var f = 1; f < words.length; f++) {
			if (!FORM_TOKEN.test(latin(words[f]).toUpperCase())) {
				continue;
			}
			var start = f;
			var count = 0;
			for (var j = f - 1; j >= 0 && count < maxWords; j--) {
				if ('&' === words[j] && j > 0 && isBrandWord(words[j - 1])) {
					start = j;
					continue;
				}
				/* A number of 1–3 digits or a «/WORD» right after a
				   brand word is part of the brand, not counted («ORBIMAG
				   300», «GARDASIL 9», «FUSIDIC /TARGET»), only
				   right before the form code («BESPAR 2 X TAB» is not), and
				   only the «/WORD» seen. */
				if (j === f - 1 && j > 0 && isBrandWord(words[j - 1]) && (brandNumberOk(words, j, f) || SLASH_WORD.test(words[j]))) {
					start = j;
					continue;
				}
				var gb = greekBracketBrand(words, j);
				if (gb >= 0) {
					start = gb;
					count++;
					j = gb;
					continue;
				}
				/* The one brand made of instruction words. */
				if ('WEEKLY' === words[j] && j >= 2 && 'ONCE' === words[j - 1] && 'FOSAMAX' === words[j - 2]) {
					start = j - 2;
					count++;
					j -= 2;
					continue;
				}
				if (!isBrandWord(words[j])) {
					break;
				}
				start = j;
				/* «(SOLOSTAR)» qualifies the brand; it is not counted. */
				if (!/^\(.*\)$/.test(words[j])) {
					count++;
				}
			}
			if (!count) {
				if (1 === f && /^[Ά-Ω][Ά-Ω\/\-]{2,}$/.test(words[0])) {
					start = 0;
				} else {
					continue;
				}
			}
			/* After the status «(Πρωτότυπο …» / «(Γενόσημο)» only status
			   words; a bare decimal («0,25») is never part of a pack —
			   except a pack volume, «x 2,5 ML». */
			var end = f + 1;
			var inStatus = false;
			var countAt = -1;
			while (end < words.length) {
				var tk = words[end];
				if (/^\((?:Πρωτότυπο|Γενόσημο)/.test(tk)) {
					inStatus = true;
				}
				var packVolume = /^\d+[.,]\d+$/.test(tk) && end + 1 < words.length && /^(ML|G)(?![A-Z])/i.test(latin(words[end + 1])) &&
					/[xX×Χ]$/.test(words[end - 1]);
				/* A strength without its unit right after the form,
				   then the pack («FUNGORAL CREAM 0,02 TUBx30 G»): the line is
				   read, the strength is not (drugName() confirms it). */
				var bareStrength = end === f + 1 && /^\d+[.,]\d+$/.test(tk) && end + 1 < words.length &&
					PACK_TOKEN.test(latin(words[end + 1]).toUpperCase());
				/* «BTx1 inhaler (plastic/stainless steel) with 60
				   actuations» — «with» only in exactly that place. */
				var withActuations = /^with$/i.test(tk) && /^\d+$/.test(words[end + 1] || '') && /^actuations?$/i.test(words[end + 2] || '');
				/* TRESIBA's pen, «5 PF.PEN-γυαλί(FlexTouch)x3ML»,
				   only on an insulin drug line (units per ml). */
				var flexPen = /^PF\.?PEN-ΓΥΑΛΙ(?:\(FLEXTOUCH\)X\d+(?:[.,]\d+)?ML)?$/.test(plain(tk)) &&
					UNITS_PER_ML.test(latin(words.slice(f + 1, end).join(' ')).toUpperCase());
				/* Exactly «ΠΕΡΙΕΚΤΕΣ ΜΙΑΣ ΔΟΣΗΣ» (single-dose
				   containers), the bottle shape «(OVAL)», and an ear solution
				   whose form is repeated as «EA.SOL EA, SOL». */
				var singleDose = 'ΜΙΑΣ' === plain(tk) && /^ΠΕΡΙΕΚΤ/.test(plain(words[end - 1])) && 'ΔΟΣΗΣ' === plain(words[end + 1] || '');
				var oval = /^\(OVAL\)$/i.test(tk);
				var formRepeat = end === f + 1 && 'EA,' === tk.toUpperCase() && 'EA.SOL' === latin(words[f]).toUpperCase() &&
					'SOL' === String(words[end + 1] || '').toUpperCase();
				if (inStatus ? !STATUS_WORD.test(tk) : (!bareStrength && !withActuations && !flexPen && !singleDose && !oval && !formRepeat &&
					(!isTailToken(tk) || (!packVolume && /^\(?\d+[.,]\d+\)?$/.test(tk)) || (isFractionToken(tk) && !/^(?:MCG|MC|MG|G|ML|IU|U|%)(?![A-Z])/i.test(latin(words[end + 1] || '')))))) {
					break;
				}
				/* A lone count («x4)», «X3») only right after a pack word
				   («BLIST x4)», «BL», «STRIP», «συσκευή εισπνοών x30»)
				   or closing a bracket of this drug line («(BLIST 3 x7)»);
				   anywhere else it may be an instruction. */
				if (!inStatus && /^[xX×Χ]\d+[)\]]?$/.test(tk) &&
					!(/\)$/.test(tk) && parenDepth(words.slice(f + 1, end).join(' ')) > 0) &&
					!LONE_COUNT_AFTER.test(fold(words[end - 1]))) {
					break;
				}
				/* After the box count («BTx20», «BT x 28», «BTX 60»),
				   outside brackets, only what the real print-outs have there:
				   no second count («BTx20 3X5», «BTx20 1X2»), no «x N» straight
				   after the box («BTx20 X 2 TABS» — «VIAL HDPE X 31 CAPS» is
				   fine), a bare number only after «x» or «+», «TABS» / «CAPS»
				   only after a number. Anything else is not the drug line. */
				if (!inStatus && !/^\(/.test(tk) && parenDepth(words.slice(f + 1, end).join(' ')) <= 0) {
					var fk = fold(tk);
					var prevF = end > f + 1 ? fold(words[end - 1]) : '';
					if (countAt < 0) {
						if (/^(?:BT|FL)X?\d/.test(fk) || (/^\d+$/.test(fk) && (/^(?:BT|FL)X?$/.test(prevF) ||
							(/^[X×]$/.test(prevF) && end > f + 2 && /^(?:BT|FL)$/.test(fold(words[end - 2])))))) {
							countAt = end;
						}
					} else if (/^\d+[X×]\d+\)?$/.test(fk) || (/^[X×]\d*$/.test(fk) && end - 1 === countAt) ||
						(/^\d+(?:[.,]\d+)?$/.test(fk) && !/[X×+]$/.test(prevF) && 'WITH' !== prevF) ||
						(/^(?:TABS?|CAPS?|TABLETS|CAPSULES)$/.test(fk) && !/\d$/.test(prevF)) ||
						/* A second strength after the box («BTx30 20MG»). */
						(/^\d+(?:[.,]\d+)?(?:MG|MCG|G|IU)$/.test(fk) && !/[X×+]$/.test(prevF))) {
						break;
					}
				}
				end++;
			}
			var tail = latin(words.slice(f + 1, end).join(' ')).toUpperCase();
			/* A pack is required; the strength is not (a vaccine
			   has none — its name is then always confirmed, drugName()). */
			if (!PACK_TOKEN.test(tail) && !PACK_GREEK.test(plain(tail)) && !hasPackCount(tail)) {
				continue;
			}
			/* Words of the brand before its last one; a word in brackets
			   («TOUJEO (SOLOSTAR)»), a number or a «/WORD» is not counted. */
			var brandWords = words.slice(start, f).filter(function (x) {
				/* (the Greek words of «VAQTA(ΕΜΒΟΛΙΟ … Α)» are one word) */
				return '&' !== x && !/^\(.*\)$/.test(x) && !/^\d{1,3}$/.test(x) && !/^\//.test(x) && !/^[\u0386-\u03ce]+\)?$/.test(x);
			});
			var plainWords = brandWords.length;
			/* Known qualifiers after the main word, or an
			   «INN/MAH» first word, are not words glued before the brand. */
			if (plainWords > 1 && ('FOSAMAX ONCE WEEKLY' === brandWords.join(' ') || isInnMah(brandWords) || brandWords.slice(1).every(function (x) {
				return BRAND_QUALIFIER.test(x);
			}))) {
				plainWords = 1;
			}
			return { start: start, end: end, words: words, lead: Math.max(0, plainWords - 1) };
		}
		return null;
	}

	/** A segment that is a drug line and nothing else. */
	function isBrandLine(seg) {
		var b = findBrand(seg);
		return !!b && 0 === b.start && b.end === b.words.length;
	}

	/* For the tests: the same check. */
	PD.rxIsDrugLine = isBrandLine;

	/* The status the print-out adds after the pack, wrapped onto the next
	   line: «κατηγορία με γενόσημο)», «(Πρωτότυπο)», «γενόσημο)», and the
	   exact end of a wrapped pack before it («DOSES) (Πρωτότυπο …»,
	   «ΣΥΡΙΓΓΑ 5ML) (Γενόσημο)», «πέναςX1,5ML+4 βελόνες (Πρωτότυπο)»).
	   Only right after a drug line. */
	var STATUS_WORD = /^\(?(Πρωτότυπο|πρωτότυπο|σε|θεραπευτική|κατηγορία|με|χωρίς|γενόσημο|Γενόσημο)\)?$/;

	function isStatusLine(line) {
		var words = line.split(' ');
		var k = -1;
		for (var i = 0; i < words.length; i++) {
			if (STATUS_WORD.test(words[i])) {
				k = i;
				break;
			}
		}
		if (k < 0 || k > 3) {
			return false;
		}
		for (var j = k; j < words.length; j++) {
			if (!STATUS_WORD.test(words[j])) {
				return false;
			}
		}
		if (!/Πρωτότυπο|γενόσημο/i.test(words.slice(k).join(' '))) {
			return false;
		}
		/* The pack continuation before it: pack words and numbers only. */
		for (var p = 0; p < k; p++) {
			/* …and each carries a pack word of its own («ML», «DOSES»,
			   «ΣΥΡΙΓΓΑ»), not just a number or a lone «x» («X3»). */
			var letters = fold(words[p]).replace(/[\d\s.,+\/()\[\]\-%*:]/g, '');
			if (!isTailToken(words[p]) || letters.length < 2) {
				return false;
			}
			/* A fraction or a counted tablet / capsule («1/2ΔΙΣΚΙΟ»,
			   «3X2TABS») is an instruction, not the end of a pack. */
			if (/\d\s*\/\s*\d/.test(words[p]) || isFractionToken(words[p])) {
				return false;
			}
		}
		return true;
	}

	/* A dose-like fragment, for lines no medicine was read from. */
	var DOSE_LIKE = [
		/(^|\s)[xΧχ×]\s+\d+\s*ημέρ/i,
		/(^|\s)[xΧχ×]\s+εφάπαξ/i,
		/\d+\s+φορ(?:ά|ές|α|ες)\s+(?:την|τον)\s+(?:ημέρα|ημερα|εβδομάδα|εβδομαδα|μήνα|μηνα)/i,
		/κάθε\s+\d+\s+ώρ/i
	];

	function isDoseLike(text) {
		return DOSE_LIKE.some(function (re) {
			return re.test(text);
		});
	}

	/**
	 * One line of the paste → its segments. A line is cut
	 * before and after every price row and totals header (matched whole,
	 * between spaces), after a complete dose line, and around every drug
	 * line; whatever is left between them is a segment of its own.
	 */
	function segmentLine(line) {
		var out = [];
		var rest = line;
		var pieces = [];
		var re = new RegExp(PRICE_OR_TOTALS.source, 'g');
		var last = 0;
		var m;
		while ((m = re.exec(rest))) {
			var s = m.index;
			var e = s + m[0].length;
			if ((s > 0 && ' ' !== rest.charAt(s - 1)) || (e < rest.length && ' ' !== rest.charAt(e))) {
				re.lastIndex = s + 1;
				continue;
			}
			pieces.push([rest.slice(last, s), false]);
			pieces.push([m[0], true]);
			last = e;
		}
		pieces.push([rest.slice(last), false]);
		pieces.forEach(function (pc) {
			var text = pc[0].trim();
			if (!text) {
				return;
			}
			if (pc[1]) {
				out.push(text);
				return;
			}
			/* «… (Γενόσημο) Batch: 25-025» — the sticker's
			   barcode line glued to its drug line. */
			var bt = BARCODE_TAIL.exec(text);
			if (bt) {
				splitBrands(text.slice(0, bt.index).trim(), out, out.length > 0);
				out.push(bt[1]);
				return;
			}
			if (/^ΔΟΣΟΛΟΓΙΑ\s*:/.test(text)) {
				var dm = DOSE_LINE.exec(text);
				if (dm && dm[0].length < text.length) {
					out.push(dm[0]);
					splitBrands(text.slice(dm[0].length).trim(), out, true);
				} else {
					out.push(text);
				}
				return;
			}
			splitBrands(text, out, out.length > 0);
		});
		return out.length ? out : [''];
	}

	/* `glued` — something (a price row, a dose line, the list
	   header, another drug line) comes before this text on the same
	   physical line. Then the brand is one word only: any other word
	   before it was glued on and is unknown text, never part of the name.
	   In an unedited paste a drug line starts its own line. */
	function splitBrands(text, out, glued) {
		if (!text) {
			return;
		}
		var b = findBrand(text, glued ? 1 : 3);
		if (!b) {
			out.push(text);
			return;
		}
		if (b.start > 0) {
			out.push(b.words.slice(0, b.start).join(' '));
		}
		out.push(b.words.slice(b.start, b.end).join(' '));
		splitBrands(b.words.slice(b.end).join(' '), out, true);
	}

	/** Words before the brand's main word (0 for a one-word brand). */
	function brandLead(seg) {
		var b = findBrand(seg, 3);
		return b ? b.lead : 0;
	}

	/* A drug line wrapped over up to four lines
	   («ROSUVASTATIN/WIN MEDICA F.C.TAB» / «10MG/TAB BTx30 (Γενόσημο)»)
	   is joined into one (mergeDrugWraps()):
	   1. an incomplete drug line with its form code takes the SHORTEST
	      run of next lines that makes it one complete drug line; each of
	      those lines carries a word of its own (a pack or unit word —
	      never just «X 2» or a number) or closes a bracket left open;
	   2. a complete drug line then takes, one at a time, only a line
	      that closes its open bracket, a status line («(Πρωτότυπο σε» /
	      «κατηγορία με γενόσημο)») or what follows a final «+».
	   A continuation never is a segment glued after another one, and a
	   first line glued after something else keeps its one-word brand.
	   The prescription text keeps the original line breaks. */
	var STATUS_END = /(?:\((?:Πρωτότυπο|Γενόσημο)[^()]*\)|γενόσημο\))$/;

	function continuationOk(line) {
		return fold(line).replace(/[\d\s.,+\/()\[\]\-%*:]/g, '').replace(/^X+$/, '').length >= 2;
	}

	/* `line` closes the bracket still open in `text`, and after that
	   bracket holds only status words («3x10) (Γενόσημο)»). */
	function closesBracket(text, line) {
		var depth = parenDepth(text);
		if (depth <= 0) {
			return false;
		}
		for (var c = 0; c < line.length; c++) {
			var ch = line.charAt(c);
			if ('(' === ch) {
				depth++;
			} else if (')' === ch && 0 === --depth) {
				var rest = line.slice(c + 1).trim();
				return '' === rest || rest.split(' ').every(function (w) {
					return STATUS_WORD.test(w);
				});
			}
		}
		return false;
	}

	/* The end of a wrapped pack and the status after it («ΣΥΡΙΓΓΑ Χ 5ML
	   (Γενόσημο)», «φιαλίδια ) x2ML (Πρωτότυπο …)», «(ΤΟΥΛΑΧΙΣΤΟΝ 120
	   ΨΕΚΑΣΜΟΙ) (Γενόσημο)»). Before the status, every word carries a
	   pack word of its own, or is a bracket, or a lone «x» before an
	   amount with its unit («Χ 5ML»); a bare number only inside brackets.
	   «2 TABS (Γενόσημο)» or «X 2 (Γενόσημο)» are not. */
	function statusWrapOk(line, openDepth) {
		if (!STATUS_END.test(line)) {
			return false;
		}
		var words = line.split(' ');
		var k = -1;
		for (var i = words.length - 1; i >= 0; i--) {
			if (/^\((?:Πρωτότυπο|Γενόσημο)/.test(words[i])) {
				k = i;
				break;
			}
		}
		if (k < 1) {
			return false;
		}
		for (var j = k; j < words.length; j++) {
			if (!STATUS_WORD.test(words[j]) && !/^\((?:Πρωτότυπο|Γενόσημο)\)?$/.test(words[j])) {
				return false;
			}
		}
		var letters = function (w) {
			return fold(w || '').replace(/[\d\s.,+\/()\[\]\-%*:]/g, '').length;
		};
		var depth = openDepth > 0 ? openDepth : 0;
		for (var p = 0; p < k; p++) {
			var w = words[p];
			/* «… steel) with 60 actuations (Πρωτότυπο …)» */
			if (/^with$/i.test(w) && /^\d+$/.test(words[p + 1] || '') && /^actuations?$/i.test(words[p + 2] || '') && p + 2 < k) {
				p += 2;
				continue;
			}
			var ok = (letters(w) >= 2 && isTailToken(w)) || /^[()\[\]]+$/.test(w) ||
				(depth + parenDepth(w.replace(/\)+$/, '')) > 0 && /^\(?\d+(?:[.,]\d+)?\)?$/.test(w)) ||
				(/^[xXΧχ×]$/.test(w) && /\d/.test(words[p + 1] || '') && letters(words[p + 1]) >= 2);
			if (!ok) {
				return false;
			}
			depth += parenDepth(w);
		}
		return true;
	}

	/* A line that ends the last word and then repeats the drug
	   line's own tail, with the status («… + 2» / «βελόνες x 0,5 ML SOLV
	   ( 1 Δόσ.) + 2 βελόνες (Πρωτότυπο)», PROQUAD): a wrap, not extra. */
	function repeatsTail(text, line) {
		var words = line.split(' ');
		var k = words.length;
		for (var i = words.length - 1; i >= 1; i--) {
			if (/^\((?:Πρωτότυπο|Γενόσημο)/.test(words[i])) {
				k = i;
				break;
			}
		}
		for (var j = k; j < words.length; j++) {
			if (!STATUS_WORD.test(words[j]) && !/^\((?:Πρωτότυπο|Γενόσημο)\)?$/.test(words[j])) {
				return false;
			}
		}
		if (k < 2 || !/^[^\d\s()]+$/.test(words[0]) || continuationOk(words[0]) === false) {
			return false;
		}
		var repeat = words.slice(1, k).join('');
		return repeat.length >= 10 && (text + words[0]).replace(/\s+/g, '').indexOf(repeat) !== -1;
	}

	function mergeDrugWraps(lines, glued, tooLong) {
		var out = [];
		var outLong = {};
		var origOf = {};
		var plainText = function (k) {
			var l = lines[k];
			return '' !== l && !tooLong[k] && !isBoundary(l) && !/^ΔΟΣΟΛΟΓΙΑ\s*:/.test(l);
		};
		for (var i = 0; i < lines.length; i++) {
			var first = lines[i];
			var take = 0;
			/* A continuation starts its own line (a segment glued after
			   another on the same line was cut there on purpose). */
			var canContinue = function (k) {
				return i + k < lines.length && plainText(i + k) && !glued[i + k];
			};
			if (plainText(i)) {
				var max = glued[i] ? 1 : 3;
				var full = isWhole(first, max);
				var hasForm = first.split(' ').slice(1).some(function (w) {
					return FORM_TOKEN.test(latin(w).toUpperCase());
				});
				var joinedText = first;
				/* 1: the shortest join that makes an incomplete drug line
				   (with its form code) complete. */
				if (!full && hasForm) {
					var trial = first;
					for (var k = 1; k <= 3 && canContinue(k) && (continuationOk(lines[i + k]) || closesBracket(trial, lines[i + k])); k++) {
						trial += ' ' + lines[i + k];
						if (isWhole(trial, max)) {
							take = k;
							joinedText = trial;
							break;
						}
					}
				}
				/* 2: a complete drug line takes, one by one, the lines that
				   close a bracket it left open, its status («(Πρωτότυπο σε
				   θεραπευτική» / «κατηγορία με γενόσημο)»), or what follows a
				   final «+» — while it stays one complete drug line. */
				if (full || take) {
					/* A pack volume cut by the line break — «… LDPE X» and
					   «0,35» on the first line, «ML (Γενόσημο)» on the next. */
					var vol = lines[i + take + 1];
					var unitLine = lines[i + take + 2];
					if (i + take + 2 < lines.length && glued[i + take + 1] && /^\d+[.,]\d+$/.test(vol || '') && /[xX×Χ]$/.test(joinedText) &&
						plainText(i + take + 2) && !glued[i + take + 2] && /^(ML|G)(?![A-Z])/i.test(unitLine) &&
						isWhole(joinedText + ' ' + vol + ' ' + unitLine, max)) {
						joinedText += ' ' + vol + ' ' + unitLine;
						take += 2;
					}
					while (take < 3 && canContinue(take + 1) && !STATUS_END.test(joinedText)) {
						var next = lines[i + take + 1];
						var fits = closesBracket(joinedText, next) || isStatusLine(next) || statusWrapOk(next, parenDepth(joinedText)) || repeatsTail(joinedText, next) ||
							(/\+$/.test(joinedText) && continuationOk(next));
						if (!fits || !isWhole(joinedText + ' ' + next, max)) {
							break;
						}
						joinedText += ' ' + next;
						take++;
					}
				}
			}
			if (take) {
				/* The original line breaks (a segment glued on the same line
				   joins with a space). */
				var orig = lines[i];
				for (var o = i + 1; o <= i + take; o++) {
					orig += (glued[o] ? ' ' : '\n') + lines[o];
				}
				origOf[out.length] = orig;
				out.push(lines.slice(i, i + take + 1).join(' '));
				i += take;
			} else {
				if (tooLong[i]) {
					outLong[out.length] = true;
				}
				out.push(first);
			}
		}
		return { lines: out, tooLong: outLong, origOf: origOf };
	}

	/* The whole text is one drug line, with a brand of at most `max` words. */
	function isWhole(text, max) {
		var b = findBrand(text, max);
		return !!b && 0 === b.start && b.end === b.words.length;
	}

	/* Quantity: «1», «1/2», «0,5», «1 1/2», «1 ½», «1½». */
	var DOSE_LINE = /^ΔΟΣΟΛΟΓΙΑ\s*:\s*([\d\/.,½¼¾]+(?:\s+\d+\s*\/\s*\d+|\s*[½¼¾])?)\s+(.*?)\s+[xΧχ×]\s+(.+?)\s+[xΧχ×]\s+(\d+)\s*ημέρ(?:ες|ας|α)?/i;

	/* The memo holds whole segments of the paste: rx-parse.js empties it
	   before and after every parse. */
	function clearBrandMemo() {
		brandMemo = {};
	}

	R.isBoundary = isBoundary;
	R.isBrandLine = isBrandLine;
	R.brandLead = brandLead;
	R.drugName = drugName;
	R.segmentLine = segmentLine;
	R.mergeDrugWraps = mergeDrugWraps;
	R.isStatusLine = isStatusLine;
	R.isDoseLike = isDoseLike;
	R.isDoseSequence = isDoseSequence;
	R.knownLineKind = knownLineKind;
	R.isMoneyOnly = isMoneyOnly;
	R.moneyCount = moneyCount;
	R.priceFields = priceFields;
	R.clearBrandMemo = clearBrandMemo;
	R.DOSE_LINE = DOSE_LINE;
	R.PRICE_EXACT = PRICE_EXACT;
	R.TOTALS_HEADER = TOTALS_HEADER;
	R.BARCODE_LINE = BARCODE_LINE;
	R.UNITS_PER_ML = UNITS_PER_ML;
})();
