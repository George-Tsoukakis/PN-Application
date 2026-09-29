/**
 * PlanDose — calendar-qr.js (1.28.2)
 * Part of the modular frontend JavaScript files located in assets/js/.
 *
 * «Υπενθυμίσεις στο κινητό»: a QR code on the printed A4 sheet. The patient
 * scans it and the plan opens on public/calendar.html, which builds a
 * calendar file (.ics) ON THE PHONE. Nothing reaches the server:
 *
 * - The plan travels in the URL fragment (after «#»), which browsers never
 *   send in a request. calendar.html has a CSP with connect-src 'none'.
 * - The payload is built from PD.dayDoses(), the very function that builds
 *   the day cards of the sheet, so the calendar has exactly the doses of the
 *   paper: the same days, the same parts of the day, the doses carried over
 *   to the extra day, weekly / monthly / every-N-days medicines included.
 * - The patient's name is never in the QR.
 *
 * Payload — compact binary, then base64url (a QR a phone reads from paper
 * holds ~600 characters; JSON of a 5-medicine plan was 800+):
 *   byte     version (1)
 *   byte     flags (bit 0: English)
 *   uint16   day zero, days since 2000-01-01
 *   uint16   number of days (n)
 *   str      pharmacy name ('' = none)
 *   byte     medicines, then for each: str name, str dose, str notes
 *   byte     tracks, then for each: byte medicine, byte slot, ⌈n/8⌉ bytes
 *            bitmap (bit d, LSB first = a dose on day d)
 *   slot: 0 morning, 1 noon, 2 afternoon, 3 evening, 4 any time of the day
 *   str: byte length + text in the «Greek byte» code: ASCII as itself,
 *        U+0370–U+03EF as one byte 0x80–0xFF, anything else 0x7F + 3 bytes
 *        of the code point. Greek names take half the bytes of UTF-8.
 * public/calendar.js decodes exactly this; keep the two in step.
 *
 * Bundles qrcode-generator 1.4.4 (Kazuhiko Arase, MIT licence, see below)
 * INSIDE this module's scope, as PD.qrcode: it defines no global, so it
 * cannot clash with another QR library on the site.
 */
(function () {
	var PD = window.__PlandoseNS;

	/* Namespace missing → the tool is not available on this page. */
	if (!PD) {
		return;
	}

/*@@VENDOR@@*/

	PD.qrcode = qrcode;

	/**
	 * Longest link printed as a QR: ≤ version 18 (89 modules) at error
	 * correction L, i.e. modules of ~0.38 mm in the 34 mm box — readable by
	 * a phone camera from a laser or inkjet print.
	 */
	PD.CAL_MAX_LINK = 620;

	/** Longest plan the patient page accepts (public/calendar.js decode()). */
	PD.CAL_MAX_DAYS = 400;

	PD.CAL_SLOTS = ['morning', 'noon', 'afternoon', 'evening', PD.ANYTIME_SLOT || 'anytime'];

	/* By code points, never splitting an emoji into a lone surrogate. */
	function clip(value, max) {
		var a = Array.from(String(value || '').replace(/\s+/g, ' ').trim());
		return a.length > max ? a.slice(0, max - 1).join('') + '…' : a.join('');
	}

	/* 1.28.2: the same list as isFormatChar() in public/calendar.js. */
	function calFormatChar(c) {
		return (c >= 0x7f && c <= 0x9f) || 0xad === c || 0x61c === c || 0x180e === c ||
			(c >= 0x200b && c <= 0x200f) || (c >= 0x202a && c <= 0x202e) ||
			(c >= 0x2060 && c <= 0x206f) || 0xfeff === c || (c >= 0xfff9 && c <= 0xfffb) ||
			(c >= 0xe0000 && c <= 0xe007f);
	}

	/** Text → «Greek byte» code (see the header). */
	PD.calText = function calText(str) {
		var out = [];
		Array.from(String(str || '')).forEach(function (ch) {
			var c = ch.codePointAt(0);
			if (c < 0x7f && c >= 0x20) {
				out.push(c);
			} else if (c >= 0x370 && c <= 0x3ef) {
				out.push(0x80 + (c - 0x370));
			} else if (c >= 0x20 && (c < 0xd800 || c > 0xdfff) && !calFormatChar(c)) {
				/* Lone surrogates are dropped: the page refuses them.
				   1.28.2: so are invisible format characters (bidi
				   overrides, zero-width marks, C1 controls) — the patient
				   page refuses those too (public/calendar.js). */
				out.push(0x7f, (c >> 16) & 0xff, (c >> 8) & 0xff, c & 0xff);
			}
		});
		return out;
	};

	/** Length-prefixed text, shortened by whole characters to ≤ 255 bytes. */
	function pushStr(bytes, str) {
		var s = String(str || '');
		var enc = PD.calText(s);
		while (enc.length > 255) {
			s = Array.from(s).slice(0, -1).join('');
			enc = PD.calText(s);
		}
		bytes.push(enc.length);
		enc.forEach(function (b) {
			bytes.push(b);
		});
	}

	function pushU16(bytes, n) {
		bytes.push((n >> 8) & 0xff, n & 0xff);
	}

	PD.calB64url = function calB64url(bytes) {
		var bin = '';
		for (var i = 0; i < bytes.length; i++) {
			bin += String.fromCharCode(bytes[i]);
		}
		return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	};

	/** Days since 2000-01-01 of a YYYY-MM-DD date (calendar days, no DST). */
	PD.calDayNumber = function calDayNumber(iso) {
		var p = String(iso).split('-').map(Number);
		return Math.round((Date.UTC(p[0], p[1] - 1, p[2]) - Date.UTC(2000, 0, 1)) / 86400000);
	};

	/**
	 * The plan as the sheet shows it, or null (no medicine / no dose).
	 * Call inside a day pass (buildPrintHtml() opens one).
	 *
	 * @param {{notes?:boolean, pharmacy?:boolean}} opts What to include.
	 * @return {?{v:number,l:string,s:string,n:number,f:string,m:Array,t:Array}}
	 */
	PD.calendarPayload = function calendarPayload(opts) {
		opts = opts || {};
		var total = PD.planDayCount();
		if (!PD.s.items.length || !(total >= 1) || total > PD.CAL_MAX_DAYS) {
			return null;
		}
		var meds = [];
		var medIndex = {};
		var bits = {};
		var order = [];
		for (var d = 0; d < total; d++) {
			PD.dayDoses(d).forEach(function (group) {
				var slot = PD.CAL_SLOTS.indexOf(group.key);
				if (-1 === slot) {
					return;
				}
				group.entries.forEach(function (entry) {
					if (!(entry.index in medIndex)) {
						medIndex[entry.index] = meds.length;
						meds.push([
							clip(entry.item.name, 60),
							clip(PD.doseAmountText(entry.item), 30),
							false === opts.notes ? '' : clip(entry.item.notes, 50)
						]);
					}
					var key = medIndex[entry.index] + ':' + slot;
					if (!bits[key]) {
						bits[key] = new Array(Math.ceil(total / 8)).fill(0);
						order.push(key);
					}
					bits[key][d >> 3] |= 1 << (d & 7);
				});
			});
		}
		if (!meds.length || meds.length > 40 || order.length > 200) {
			return null;
		}
		var pharmacy = PD.s.header && PD.s.header.name;
		return {
			v: 1,
			l: 'en' === PD.lang ? 'en' : 'el',
			s: PD.isoDate(PD.dayAt(0)),
			n: total,
			f: false !== opts.pharmacy && pharmacy ? clip(pharmacy, 40) : '',
			m: meds,
			t: order.map(function (key) {
				var p = key.split(':');
				return [parseInt(p[0], 10), parseInt(p[1], 10), bits[key]];
			})
		};
	};

	/** A payload → the text after «#». */
	PD.calEncode = function calEncode(p) {
		var bytes = [p.v, 'en' === p.l ? 1 : 0];
		pushU16(bytes, PD.calDayNumber(p.s));
		pushU16(bytes, p.n);
		pushStr(bytes, p.f);
		bytes.push(p.m.length);
		p.m.forEach(function (m) {
			pushStr(bytes, m[0]);
			pushStr(bytes, m[1]);
			pushStr(bytes, m[2]);
		});
		bytes.push(p.t.length);
		p.t.forEach(function (t) {
			bytes.push(t[0], t[1]);
			for (var i = 0; i < t[2].length; i++) {
				bytes.push(t[2][i]);
			}
		});
		return PD.calB64url(bytes);
	};

	/**
	 * The link in the QR, or '' (feature off, nothing to plan, or a plan too
	 * long for a readable QR even without notes and pharmacy name).
	 */
	PD.calendarLink = function calendarLink() {
		var base = PD.config && PD.config.calendarUrl;
		if (!base || 'string' !== typeof base) {
			return '';
		}
		/* The QR stores bytes: an IDN host or a Greek folder name must be
		   percent-encoded ASCII, or the phone opens a garbled address. */
		try {
			base = encodeURI(decodeURI(base));
		} catch (e) {
			return '';
		}
		var tries = [{}, { notes: false }, { notes: false, pharmacy: false }];
		var notesDropped = false;
		var pharmacyDropped = false;
		for (var i = 0; i < tries.length; i++) {
			var payload = PD.calendarPayload(tries[i]);
			if (!payload) {
				return '';
			}
			var link = base + '#' + PD.calEncode(payload);
			if (link.length <= PD.CAL_MAX_LINK) {
				/* 1.28.7: the pharmacy name, left out by the last try, is
				   said too. */
				PD.calLastInfo = { state: notesDropped ? 'noNotes' : 'full', noPharmacy: pharmacyDropped };
				return link;
			}
			/* 1.28.5: the next try leaves the notes out — worth telling
			   only when some medicine had notes. */
			if (0 === i) {
				notesDropped = payload.m.some(function (m) {
					return '' !== m[2];
				});
			}
			if (1 === i) {
				pharmacyDropped = '' !== payload.f;
			}
		}
		PD.calLastInfo = { state: 'tooBig' };
		return '';
	};

	/**
	 * 1.28.5: what the pharmacist must know about the QR of the current
	 * plan, or '' — shown above the preview, never printed. The QR used to
	 * lose the notes, or be left out, without a word.
	 */
	PD.calendarQrNotice = function calendarQrNotice() {
		PD.calLastInfo = null;
		try {
			PD.calendarLink();
		} catch (e) {
			return '';
		}
		var info = PD.calLastInfo;
		PD.calLastInfo = null;
		if (!info) {
			return '';
		}
		if ('tooBig' === info.state) {
			return PD.txt('calQrTooBig', 'Το πλάνο είναι πολύ μεγάλο για το QR «Υπενθυμίσεις στο κινητό»: το φύλλο θα τυπωθεί χωρίς QR.');
		}
		if (info.noPharmacy) {
			return PD.txt('calQrNoPharmacy', 'Το όνομα του φαρμακείου (και τυχόν σημειώσεις των φαρμάκων) δεν χωρούν στο QR «Υπενθυμίσεις στο κινητό»: στο κινητό οι υπενθυμίσεις θα είναι χωρίς αυτά. Στο τυπωμένο φύλλο υπάρχουν κανονικά.');
		}
		if ('noNotes' === info.state) {
			return PD.txt('calQrNoNotes', 'Οι σημειώσεις των φαρμάκων δεν χωρούν στο QR «Υπενθυμίσεις στο κινητό»: οι υπενθυμίσεις στο κινητό θα είναι χωρίς σημειώσεις. Στο τυπωμένο φύλλο υπάρχουν κανονικά.');
		}
		return '';
	};

	/** The QR box of the A4 sheet, or ''. */
	PD.calendarQrHtml = function calendarQrHtml() {
		var link;
		try {
			link = PD.calendarLink();
		} catch (e) {
			/* Never block a print over the QR. */
			return '';
		}
		if (!link) {
			return '';
		}
		var qr = PD.qrcode(0, 'L');
		qr.addData(link, 'Byte');
		qr.make();
		var svg = qr.createSvgTag({ cellSize: 2, margin: 0, scalable: true });
		return '<div class="pd-info-box pd-cal-box">' +
			'<div class="pd-cal-qr">' + svg + '</div>' +
			'<div class="pd-cal-text"><h3>' + PD.escapeHtml(PD.txt('calQrTitle', 'Υπενθυμίσεις στο κινητό')) + '</h3>' +
			'<p>' + PD.escapeHtml(PD.txt('calQrText', 'Σκανάρετε με την κάμερα του κινητού: οι δόσεις μπαίνουν στο ημερολόγιό σας με ειδοποίηση.')) + '</p>' +
			'<p class="pd-cal-private">' + PD.escapeHtml(PD.txt('calQrPrivate', 'Δεν στέλνεται στο φαρμακείο. Το QR περιέχει τα φάρμακα του πλάνου.')) + '</p></div>' +
			'</div>';
	};
})();
