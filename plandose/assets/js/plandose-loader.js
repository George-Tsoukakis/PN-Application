/**
 * PlanDose — plandose-loader.js
 *
 * The only PlanDose script a registered pharmacist's page loads up front.
 *
 * The whole tool is fifteen modules (sixteen with the Pro label module),
 * about 500 KB of JavaScript plus 43 KB of CSS. Rather than put them on
 * every page a pharmacist visits, whether or not the popup is ever
 * opened, this file (≈8 KB, mostly comments)
 * loads them on demand:
 *
 * - as soon as the pointer moves onto the button, it gets focus, or a
 *   finger touches it (so by the time the click lands the files are
 *   usually already there), and at the latest
 * - on the click itself, which then opens the popup once everything has
 *   run.
 *
 * The files and their order come from the server (PlandoseLoader.scripts,
 * built by Plandose_Frontend::assets() with the same versioned URLs and
 * the Pro-only label module for Pro accounts only). They are inserted
 * with async = false, which downloads them in parallel but runs them in
 * exactly that order — the order the modules depend on.
 *
 * Nothing about the tool itself changes: once app.js has run it binds
 * the button to PD.openModal(), and this loader steps aside.
 * PlandoseConfig (nonce, settings) is still printed in the page. The
 * dictionaries are not: they are separate scripts with long-cached,
 * content-versioned URLs (PlandoseLoader.i18n), fetched here together
 * with the modules and run before them. A dictionary that fails to load
 * is not fatal — every string has a Greek fallback in the modules.
 *
 * Opt out (load everything with the page) with:
 *
 *     add_filter( 'plandose_lazy_load', '__return_false' );
 */
(function () {
	'use strict';

	var L = window.PlandoseLoader;
	var trigger = document.getElementById('plandose-trigger');
	var overlay = document.getElementById('plandose-overlay');
	var closeBtn = document.getElementById('plandose-close');
	var app = document.getElementById('plandose-app');

	if (!L || !L.scripts || !L.scripts.length || !trigger || !overlay || !closeBtn || !app) {
		return;
	}

	var loading = null; /* Promise once loading has started; null = not started. */
	var openWhenReady = false;

	function loadStyle(href) {
		return new Promise(function (resolve) {
			var link = document.createElement('link');
			link.rel = 'stylesheet';
			link.href = href;
			link.id = 'plandose-css';
			/* A stylesheet that fails is not fatal — the tool works, only
			   unstyled — so both outcomes resolve. */
			link.onload = function () {
				resolve();
			};
			link.onerror = function () {
				resolve();
			};
			document.head.appendChild(link);
		});
	}

	/* optional: URLs whose failure is not fatal (the dictionaries). */
	function loadScripts(urls, optional) {
		return new Promise(function (resolve, reject) {
			var remaining = urls.length;
			var failed = false;
			var done = function () {
				remaining--;
				if (!remaining && !failed) {
					resolve();
				}
			};
			urls.forEach(function (src) {
				var s = document.createElement('script');
				s.src = src;
				/* Download in parallel, execute in insertion order. */
				s.async = false;
				s.onload = done;
				s.onerror = function () {
					if (optional && optional.indexOf(src) !== -1) {
						done();
						return;
					}
					if (!failed) {
						failed = true;
						reject(new Error('PlanDose: could not load ' + src));
					}
				};
				document.body.appendChild(s);
			});
		});
	}

	function load() {
		if (loading) {
			return loading;
		}
		var i18n = Array.isArray(L.i18n) ? L.i18n : [];
		loading = Promise.all([
			loadStyle(L.style),
			/* Dictionaries first: state.js reads them when it runs. */
			loadScripts(i18n.concat(L.scripts), i18n)
		]).then(function () {
			var PD = window.__PlandoseNS;
			if (!PD || typeof PD.openModal !== 'function') {
				throw new Error('PlanDose: tool did not start');
			}
			return PD;
		});
		/* A failure is NOT retried in place: some modules may already have
		   run, and running them again would bind every listener twice. The
		   error view asks for a page reload instead (clean start). */
		loading.catch(function () {});
		return loading;
	}

	/* aria-busy alone is not read out by most screen readers, so the
	   wait is also announced through a polite, visually hidden status. */
	var status = document.createElement('span');
	status.setAttribute('role', 'status');
	status.className = 'plandose-sr-only';
	trigger.parentNode.insertBefore(status, trigger.nextSibling);

	function setBusy(busy) {
		trigger.classList.toggle('plandose-loading', busy);
		if (busy) {
			trigger.setAttribute('aria-busy', 'true');
			status.textContent = L.loading || 'Loading…';
		} else {
			trigger.removeAttribute('aria-busy');
			status.textContent = '';
		}
	}

	/*
	 * Shown only when the files could not be fetched. The popup's own
	 * close handling lives in modal.js, which is exactly what did not
	 * load, so this wires a minimal one for the error view.
	 */
	/*
	 * The error view is aria-modal but has no Tab trap, so Tab and a
	 * screen reader's cursor could walk into the page behind it. Every
	 * sibling along the overlay's path up to <body> gets `inert` (+
	 * aria-hidden as a fallback); only what we changed is restored.
	 *
	 * The same helper exists in modal.js (PD.setBackgroundInert) and
	 * plandose-guest.js. It cannot be shared: this view exists precisely
	 * because the PD modules did not load, and the guest bundle never
	 * loads them. Keep the three in step.
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

	function showError() {
		var hide = function () {
			overlay.hidden = true;
			trigger.setAttribute('aria-expanded', 'false');
			document.body.classList.remove('plandose-no-scroll');
			setBackgroundInert(false);
			closeBtn.removeEventListener('click', hide);
			document.removeEventListener('keydown', onKey);
			try {
				trigger.focus();
			} catch (e) {
				/* Non-fatal. */
			}
		};
		var onKey = function (e) {
			if (e.key === 'Escape' || e.key === 'Esc') {
				hide();
			}
		};
		app.textContent = '';
		var p = document.createElement('p');
		p.className = 'plandose-loader-error';
		p.setAttribute('role', 'alert');
		p.textContent = L.failed || 'PlanDose could not be loaded. Check your connection and reload the page.';
		app.appendChild(p);
		overlay.hidden = false;
		/* Same as PD.openModal(): the trigger tells assistive tech the
		   dialog it controls is open. */
		trigger.setAttribute('aria-expanded', 'true');
		document.body.classList.add('plandose-no-scroll');
		/* No Tab trap here (modal.js did not load), but with the
		   page inert Tab can only reach the dialog's own controls. */
		setBackgroundInert(true);
		closeBtn.addEventListener('click', hide);
		document.addEventListener('keydown', onKey);
		try {
			closeBtn.focus();
		} catch (e) {
			/* Non-fatal. */
		}
	}

	function onClick(e) {
		/* Once the tool is up, app.js has its own listener; stay out. */
		if (window.__PlandoseNS && window.__PlandoseNS.__lazyReady) {
			return;
		}
		e.preventDefault();
		/* app.js may have run (and bound its own click listener) a moment
		   before __lazyReady is set; this listener was registered first,
		   so stopping here guarantees the popup opens exactly once. */
		e.stopImmediatePropagation();
		if (openWhenReady) {
			return; /* Double click while loading. */
		}
		openWhenReady = true;
		setBusy(true);
		load().then(function (PD) {
			PD.__lazyReady = true;
			setBusy(false);
			openWhenReady = false;
			PD.openModal();
		}, function () {
			setBusy(false);
			openWhenReady = false;
			showError();
		});
	}

	function prefetch() {
		load().then(function (PD) {
			PD.__lazyReady = true;
		}, function () {
			/* Reported on click, not on hover. */
		});
	}

	trigger.addEventListener('click', onClick);
	trigger.addEventListener('pointerenter', prefetch, { once: true });
	trigger.addEventListener('focus', prefetch, { once: true });
	trigger.addEventListener('touchstart', prefetch, { once: true, passive: true });
})();
