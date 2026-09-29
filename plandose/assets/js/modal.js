/**
 * PlanDose — modal.js
 * Part of the modular frontend JavaScript files located in assets/js/.
 * All modules share one namespace object (window.__PlandoseNS, aliased as PD).
 * Mutable state lives on PD.s.*; helpers, config, DOM refs and constants live on PD.*
 */
(function () {
	'use strict';

	var PD = window.__PlandoseNS;

	/* Namespace missing → the tool is not available on this page
	   (guest/no-permission or required DOM absent). Bail out quietly. */
	if (!PD) {
		return;
	}

	/*
	 * Sync the modal header (title + subtitle) to the active dictionary.
	 * The header markup is server-rendered in Greek, so on open — and on
	 * every language switch — we overwrite it from PD.t. Falls back to the
	 * originally-rendered text if a key is somehow missing.
	 */
	PD.applyHeaderLang = function applyHeaderLang() {
		if (PD.modalHeadTitleEl) {
			PD.modalHeadTitleEl.textContent = PD.txt('modalTitle', PD.defaultHeadTitle);
		}
		if (PD.modalHeadSubEl) {
			PD.modalHeadSubEl.textContent = PD.txt('modalSubtitle', PD.defaultHeadSub);
		}
		PD.applyLangToggleLabel();
	};

	/*
	 * Toggle button label. For Pro it shows the *target* language (tap to
	 * switch): "EN" while in Greek, "ΕΛ" while in English.
	 *
	 * For non-Pro the label is left exactly as render_modal() printed it,
	 * which is "EN/EL" — not "Pro". The button is a locked teaser either way; the padlock and
	 * the `is-locked` class carry that, and PD.setLang() refuses to switch
	 * regardless of what the label says.
	 */
	PD.applyLangToggleLabel = function applyLangToggleLabel() {
		var btn = document.getElementById('plandose-lang-toggle');
		if (!btn) {
			return;
		}
		var label = btn.querySelector('.plandose-lang-label');
		if (!label || !PD.IS_PRO) {
			return;
		}
		label.textContent = (PD.lang === 'el') ? 'EN' : 'ΕΛ';
	};

	/* ---------------------------------------------------------------
	 * Focus management
	 *
	 * The dialog markup declares role="dialog" aria-modal="true", which
	 * promises the browser and assistive tech that focus stays inside it
	 * while it is open. Nothing enforced that: focus stayed on the page
	 * behind the overlay, so one Tab left the "modal" entirely.
	 * --------------------------------------------------------------- */

	PD.FOCUSABLE_SELECTOR = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

	/*
	 * The element that currently owns the focus trap: the close-confirm
	 * box when it is up (it is its own alertdialog, stacked on top),
	 * otherwise the modal itself.
	 */
	PD.focusScope = function focusScope() {
		return document.querySelector('#plandose-confirm-close .plandose-confirm-box') ||
			document.getElementById('plandose-modal');
	};

	/*
	 * Focusable descendants of `root`, in DOM order. Recomputed on every
	 * Tab rather than cached: buildApp() rebuilds the whole form on step
	 * changes and on the language toggle, and steps are shown/hidden with
	 * the `hidden` attribute, so any cached list would go stale at once.
	 * getClientRects() is what filters the hidden ones out — an element
	 * inside a display:none step has none.
	 */
	PD.focusableIn = function focusableIn(root) {
		if (!root) {
			return [];
		}
		return Array.prototype.filter.call(
			root.querySelectorAll(PD.FOCUSABLE_SELECTOR),
			function (el) {
				return el.getClientRects().length > 0;
			}
		);
	};

	/* Move focus to the first sensible target inside the open dialog. */
	PD.focusFirstIn = function focusFirstIn(root) {
		var candidates = PD.focusableIn(root);
		if (candidates.length) {
			candidates[0].focus();
		}
	};

	/*
	 * Keep Tab / Shift+Tab inside the active scope by wrapping around at
	 * either end. Also pulls focus back in if it somehow escaped (browser
	 * chrome, a click behind the overlay, an element removed mid-rebuild).
	 */
	PD.trapFocus = function trapFocus(e) {
		if (e.key !== 'Tab' || PD.overlay.hidden) {
			return;
		}
		var scope = PD.focusScope();
		if (!scope) {
			return;
		}
		var items = PD.focusableIn(scope);
		if (!items.length) {
			return;
		}
		var first = items[0];
		var last = items[items.length - 1];
		var active = document.activeElement;

		if (!scope.contains(active)) {
			e.preventDefault();
			(e.shiftKey ? last : first).focus();
			return;
		}
		if (e.shiftKey && active === first) {
			e.preventDefault();
			last.focus();
			return;
		}
		if (!e.shiftKey && active === last) {
			e.preventDefault();
			first.focus();
		}
	};

	/*
	 * True when an action just dropped the focus — the focused
	 * control was disabled, hidden or removed (a rebuild) — while the
	 * dialog is open. Callers then move it somewhere sensible; focus is
	 * never taken from a control that still holds it.
	 */
	PD.focusWasLost = function focusWasLost(prev) {
		if (!PD.overlay || PD.overlay.hidden) {
			return false;
		}
		if (!prev || prev === document.body || prev === document.documentElement) {
			return true;
		}
		return !document.contains(prev) ||
			!!prev.disabled ||
			!!(prev.closest && prev.closest('[hidden]'));
	};

	/*
	 * The Tab trap keeps the keyboard in, but a screen reader's
	 * virtual cursor could still wander into the page behind the dialog.
	 * Every sibling along the overlay's path up to <body> gets `inert`
	 * (+ aria-hidden for browsers without inert). Only what we changed is
	 * recorded, so attributes the page set itself survive closing. The
	 * close-confirm box is appended to <body> later, so it is never hit.
	 */
	PD.setBackgroundInert = function setBackgroundInert(on) {
		var changed = PD.s.inertChanged || [];
		PD.s.inertChanged = [];
		changed.forEach(function (rec) {
			if (rec.inert) {
				rec.el.inert = false;
				rec.el.removeAttribute('inert');
			}
			if (rec.aria) {
				rec.el.removeAttribute('aria-hidden');
			}
		});
		if (!on) {
			return;
		}
		var node = PD.overlay;
		while (node && node.parentNode && node !== document.body) {
			Array.prototype.forEach.call(node.parentNode.children, function (el) {
				if (el === node || el.id === 'plandose-confirm-close' || /^(SCRIPT|STYLE|LINK)$/.test(el.tagName)) {
					return;
				}
				var rec = { el: el, inert: false, aria: false };
				if (!el.hasAttribute('inert')) {
					el.setAttribute('inert', '');
					rec.inert = true;
				}
				if (!el.hasAttribute('aria-hidden')) {
					el.setAttribute('aria-hidden', 'true');
					rec.aria = true;
				}
				if (rec.inert || rec.aria) {
					PD.s.inertChanged.push(rec);
				}
			});
			node = node.parentNode;
		}
	};

	if (!PD.s.focusTrapBound) {
		PD.s.focusTrapBound = true;
		document.addEventListener('keydown', PD.trapFocus);
	}

	/*
	 * Every module in this bundle — including this one — is enqueued only
	 * for a pharmacist who passed can_use_tool() server-side (see
	 * Plandose_Frontend::assets()). A guest gets assets/js/plandose-guest.js
	 * instead, and their view is server-rendered in render_modal().
	 */
	/** aria-expanded on the floating trigger. */
	PD.setTriggerExpanded = function setTriggerExpanded(open) {
		var trigger = document.getElementById('plandose-trigger');
		if (trigger) {
			trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
		}
	};

	PD.openModal = function openModal() {
		/* Remembered so closeModal() can hand focus back to whatever
		   opened the dialog — normally the floating trigger, but the
		   modal can also be opened from elsewhere on the page. */
		PD.s.lastFocusedBeforeModal = document.activeElement;

		PD.overlay.hidden = false;
		/* The trigger tells assistive tech the dialog is open. */
		PD.setTriggerExpanded(true);
		document.body.classList.add('plandose-no-scroll');
		PD.setBackgroundInert(true);
		PD.applyHeaderLang();
		PD.s.currentStep = 1;
		PD.s.isCheckingPrint = false;
		PD.s.isPreparingPrint = false;
		PD.s.isPrinting = false;
		PD.s.printCreditLocked = false;
		/* A label window left from an earlier session is closed
		   (this also releases isPrintingLabels). */
		if (typeof PD.closeLabelWindow === 'function') {
			PD.closeLabelWindow();
		}
		PD.s.isPrintingLabels = false;
		if (typeof PD.clearPrintedIdleTimer === 'function') {
			PD.clearPrintedIdleTimer();
		}
		/* Nothing left locked from an earlier session. */
		if (typeof PD.syncPrintLock === 'function') {
			PD.syncPrintLock();
		}
		PD.setModalInert(false);
		PD.resetPlanState();
		PD.buildApp();
		/*
		 * The header and print-status requests carry the nonce, so they are
		 * sent only AFTER refreshNonce() has settled. Sent in parallel with
		 * it they would carry the very nonce it is about to replace, and on a
		 * page left open for hours both would come back "-1".
		 * refreshNonce() always resolves, so a failed refresh still lets
		 * them run with the nonce there is.
		 */
		PD.refreshNonce().then(function () {
			if (PD.overlay.hidden) {
				return;
			}
			PD.loadHeader(function () {
				PD.renderPreview();
			});
			if (PD.config.showPrintCounter) {
				PD.loadPrintStatus();
			}
		});
		PD.startNonceRefresh();

		/* After buildApp(), so the dialog's contents exist. Focus goes to
		   the close button rather than the first text field: auto-focusing
		   an input pops the on-screen keyboard open on a phone before the
		   pharmacist has even seen the form, and the close button is the
		   first focusable element anyway, so Tab reaches the fields
		   immediately. */
		if (PD.closeBtn && PD.closeBtn.getClientRects().length > 0) {
			PD.closeBtn.focus();
		} else {
			PD.focusFirstIn(document.getElementById('plandose-modal'));
		}
	};

	PD.closeModal = function closeModal() {
		if (PD.s.isPrinting || PD.s.isCheckingPrint || PD.s.isPreparingPrint) {
			return;
		}
		PD.stopNonceRefresh();
		/* A print window whose browser never fired 'afterprint'
		   would otherwise stay open with the patient's plan in it. */
		if (typeof PD.clearPrintedIdleTimer === 'function') {
			PD.clearPrintedIdleTimer();
		}
		/* Closing clears the plan anyway. */
		if (typeof PD.clearDraftIdleTimer === 'function') {
			PD.clearDraftIdleTimer();
		}
		if (PD.s.activePrintWindow && typeof PD.closePrintWindow === 'function') {
			PD.closePrintWindow(PD.s.activePrintWindow);
		}
		/* The same for the Pro label window, which holds the
		   patient's name and medicines too. Closing it cancels a label
		   print still in its dialog — the data must not outlive the popup. */
		if (typeof PD.closeLabelWindow === 'function') {
			PD.closeLabelWindow();
		}
		PD.overlay.hidden = true;
		PD.setTriggerExpanded(false);
		document.body.classList.remove('plandose-no-scroll');
		/* Before focus goes back to the (inert) trigger below. */
		PD.setBackgroundInert(false);
		PD.clearMessage();

		/* The confirmation promises "οι πληροφορίες του πλάνου θα
		   χαθούν": the patient's name and medicines must not stay in memory
		   or in the hidden form and preview. On a pharmacy PC shared by
		   several people the data must be gone the moment the popup closes. */
		PD.resetPlanState();
		if (PD.app) {
			PD.app.innerHTML = '';
		}
		if (typeof PD.clearPrintFrame === 'function') {
			PD.clearPrintFrame();
		}

		/* Return focus to where it was, so a keyboard user is not dropped
		   back at the top of the document. Falls back to the trigger when
		   the original element is gone from the DOM. */
		var back = PD.s.lastFocusedBeforeModal;
		PD.s.lastFocusedBeforeModal = null;
		if (back && document.contains(back) && back.getClientRects().length > 0) {
			back.focus();
		} else if (PD.trigger) {
			PD.trigger.focus();
		}
	};

	/**
	 * The dialog under the close-confirmation box is inert while
	 * the box is up, so a screen reader cannot wander into the form
	 * behind it. Only what this set is undone.
	 */
	PD.setModalInert = function setModalInert(on) {
		var modal = document.getElementById('plandose-modal');
		var rec = PD.s.modalInertRec;
		PD.s.modalInertRec = null;
		if (rec && rec.el) {
			if (rec.inert) {
				rec.el.inert = false;
				rec.el.removeAttribute('inert');
			}
			if (rec.aria) {
				rec.el.removeAttribute('aria-hidden');
			}
		}
		if (!on || !modal) {
			return;
		}
		rec = { el: modal, inert: false, aria: false };
		if (!modal.hasAttribute('inert')) {
			modal.setAttribute('inert', '');
			rec.inert = true;
		}
		if (!modal.hasAttribute('aria-hidden')) {
			modal.setAttribute('aria-hidden', 'true');
			rec.aria = true;
		}
		PD.s.modalInertRec = rec;
	};

	PD.removeConfirmClose = function removeConfirmClose() {
		var existing = document.getElementById('plandose-confirm-close');
		if (existing && existing.parentNode) {
			existing.parentNode.removeChild(existing);
		}
		/* Before focus goes back into the dialog below. */
		PD.setModalInert(false);

		/* Dismissing the prompt returns to the modal, not to the page
		   behind it. Skipped when the modal is already closing (the "Ναι"
		   path), since closeModal() handles focus itself. */
		if (PD.overlay.hidden) {
			return;
		}
		var back = PD.s.lastFocusedBeforeConfirm;
		PD.s.lastFocusedBeforeConfirm = null;
		if (back && document.contains(back) && back.getClientRects().length > 0) {
			back.focus();
		} else if (PD.closeBtn) {
			PD.closeBtn.focus();
		}
	};

	PD.showConfirmClose = function showConfirmClose() {
		PD.removeConfirmClose();
		PD.s.lastFocusedBeforeConfirm = document.activeElement;
		var wrap = document.createElement('div');
		wrap.id = 'plandose-confirm-close';
		wrap.innerHTML = '<div class="plandose-confirm-box" role="alertdialog" aria-modal="true" aria-labelledby="plandose-confirm-text">' + '<p id="plandose-confirm-text">' + PD.escapeHtml(PD.txt('confirmCloseText', 'Είστε σίγουροι ότι θέλετε να τερματίσετε αυτή την ενέργεια; Οι πληροφορίες του πλάνου θα χαθούν και η ενέργεια αυτή δεν μπορεί να αναιρεθεί.')) + '</p>' + '<div class="plandose-confirm-actions">' + '<button type="button" class="plandose-btn plandose-btn-light" id="plandose-confirm-no">' + PD.escapeHtml(PD.txt('no', 'Όχι')) + '</button>' + '<button type="button" class="plandose-btn plandose-btn-primary" id="plandose-confirm-yes">' + PD.escapeHtml(PD.txt('yes', 'Ναι')) + '</button>' + '</div>' + '</div>';
		document.body.appendChild(wrap);
		PD.setModalInert(true);
		var noBtn = document.getElementById('plandose-confirm-no');
		var yesBtn = document.getElementById('plandose-confirm-yes');
		if (noBtn) {
			noBtn.addEventListener('click', PD.removeConfirmClose);
		}
		if (yesBtn) {
			yesBtn.addEventListener('click', function () {
				PD.removeConfirmClose();
				PD.closeModal();
			});
		}

		/* Focus lands on "Όχι", not "Ναι". Escape opens this prompt, and
		   Enter activates whatever holds focus — with "Ναι" pre-focused,
		   Escape followed by Enter silently discarded the whole plan. The
		   safe option is the default; confirming still takes one Tab. */
		if (noBtn) {
			noBtn.focus();
		} else if (yesBtn) {
			yesBtn.focus();
		}
	};

	/**
	 * Entry point for every way the modal can be asked to close. Only
	 * actually closes (discarding whatever the pharmacist has typed) after
	 * explicit confirmation. The guest view has no such prompt because it
	 * has no plan data to lose — and does not run through this file at
	 * all (see the note above openModal()).
	 */
	PD.requestCloseModal = function requestCloseModal() {
		if (PD.s.isPrinting || PD.s.isCheckingPrint || PD.s.isPreparingPrint) {
			/* Say why nothing happened. */
			PD.announceCloseBlocked();
			return;
		}
		PD.showConfirmClose();
	};

	/**
	 * Tell (screen readers included) why Close / Escape is
	 * ignored while a print is running. A separate live region, so the
	 * print's own message is not overwritten.
	 */
	PD.announceCloseBlocked = function announceCloseBlocked() {
		var modal = document.getElementById('plandose-modal');
		if (!modal) {
			return;
		}
		var el = document.getElementById('pd-close-blocked');
		if (!el) {
			el = document.createElement('p');
			el.id = 'pd-close-blocked';
			el.className = 'pd-close-blocked';
			/* role="alert" matches aria-live="assertive" (role
			   status is polite by definition). */
			el.setAttribute('role', 'alert');
			el.setAttribute('aria-live', 'assertive');
			modal.appendChild(el);
		}
		/* Emptied first so the same text is announced again on a repeat. */
		el.textContent = '';
		setTimeout(function () {
			el.textContent = PD.txt('closeBlockedPrinting', 'Η εκτύπωση βρίσκεται σε εξέλιξη — το παράθυρο δεν μπορεί να κλείσει μέχρι να ολοκληρωθεί.');
		}, 30);
	};
})();