/**
 * PlanDose — plandose-guest.js
 *
 * The entire frontend for a visitor who is not a registered pharmacist.
 *
 * Loaded INSTEAD of the tool modules (state / api / validation /
 * preview / medicine-form / print-styles / calendar-qr / print / the five
 * rx-* files / modal / app, plus pro-labels for Pro), which a
 * guest can never use: Plandose_Frontend::assets() picks one bundle or the
 * other, never both.
 *
 * There is nothing to build here. The guest view — the free-tier box, the
 * bullet list and the login CTA — is server-rendered by
 * Plandose_Frontend::render_modal(), so this file only opens and closes the
 * popup that already exists in the page.
 *
 * Deliberately standalone: no PD namespace, no PlandoseConfig, no
 * dictionaries, no nonce. Keeping it free of localized data is also what
 * lets a guest page stay safely cacheable (a nonce baked into cached HTML
 * goes stale).
 *
 * Behaviour matches the logged-in modal exactly: the trigger opens, the ×
 * button and Escape close, focus moves into the dialog and stays there. Backdrop clicks do NOT close, same as the tool.
 */
(function () {
	'use strict';

	var overlay = document.getElementById('plandose-overlay');
	var trigger = document.getElementById('plandose-trigger');
	var closeBtn = document.getElementById('plandose-close');

	/* Any anchor missing → the modal was not rendered on this page. */
	if (!overlay || !trigger || !closeBtn) {
		return;
	}

	var NO_SCROLL_CLASS = 'plandose-no-scroll';
	var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
	var lastFocused = null;

	/* Visible focusable elements inside the dialog, in DOM order. */
	function focusables() {
		var modal = document.getElementById('plandose-modal') || overlay;
		return Array.prototype.filter.call(modal.querySelectorAll(FOCUSABLE), function (el) {
			return el.getClientRects().length > 0;
		});
	}

	/*
	 * The Tab trap keeps the keyboard in, but a screen reader's
	 * virtual cursor could still read the page behind the dialog. Every
	 * sibling along the overlay's path up to <body> gets `inert` (+
	 * aria-hidden as a fallback); only what we changed is restored.
	 */
	var inertChanged = [];
	function setBackgroundInert(on) {
		inertChanged.forEach(function (rec) {
			if (rec.inert) {
				rec.el.removeAttribute('inert');
			}
			if (rec.aria) {
				rec.el.removeAttribute('aria-hidden');
			}
		});
		inertChanged = [];
		if (!on) {
			return;
		}
		var node = overlay;
		while (node && node.parentNode && node !== document.body) {
			Array.prototype.forEach.call(node.parentNode.children, function (el) {
				if (el === node || /^(SCRIPT|STYLE|LINK)$/.test(el.tagName)) {
					return;
				}
				var rec = { el: el, inert: !el.hasAttribute('inert'), aria: !el.hasAttribute('aria-hidden') };
				if (rec.inert) {
					el.setAttribute('inert', '');
				}
				if (rec.aria) {
					el.setAttribute('aria-hidden', 'true');
				}
				if (rec.inert || rec.aria) {
					inertChanged.push(rec);
				}
			});
			node = node.parentNode;
		}
	}

	function openModal() {
		lastFocused = document.activeElement;
		overlay.hidden = false;
		trigger.setAttribute('aria-expanded', 'true');
		document.body.classList.add(NO_SCROLL_CLASS);
		setBackgroundInert(true);

		/* The dialog is aria-modal, so focus has to move into it
		   (same as the tool: the close button, which does not pop up a
		   phone keyboard). */
		try {
			closeBtn.focus();
		} catch (e) {
			/* Non-fatal. */
		}
	}

	function closeModal() {
		overlay.hidden = true;
		trigger.setAttribute('aria-expanded', 'false');
		document.body.classList.remove(NO_SCROLL_CLASS);
		setBackgroundInert(false);

		/* Return focus to where it came from, so a keyboard user is not
		   dropped back at the top of the document. */
		var back = lastFocused;
		lastFocused = null;
		try {
			if (back && document.contains(back) && back.getClientRects().length > 0) {
				back.focus();
			} else {
				trigger.focus();
			}
		} catch (e) {
			/* Non-fatal. */
		}
	}

	/* Keep Tab / Shift+Tab inside the open dialog. */
	function trapFocus(e) {
		if (e.key !== 'Tab' || overlay.hidden) {
			return;
		}
		var items = focusables();
		if (!items.length) {
			return;
		}
		var first = items[0];
		var last = items[items.length - 1];
		var active = document.activeElement;
		if (!overlay.contains(active)) {
			e.preventDefault();
			(e.shiftKey ? last : first).focus();
		} else if (e.shiftKey && active === first) {
			e.preventDefault();
			last.focus();
		} else if (!e.shiftKey && active === last) {
			e.preventDefault();
			first.focus();
		}
	}

	trigger.addEventListener('click', openModal);
	closeBtn.addEventListener('click', closeModal);

	document.addEventListener('keydown', trapFocus);

	document.addEventListener('keydown', function (e) {
		/* 'Esc' is the legacy key name still reported by older Edge/IE. */
		if ((e.key === 'Escape' || e.key === 'Esc') && !overlay.hidden) {
			closeModal();
		}
	});
})();
