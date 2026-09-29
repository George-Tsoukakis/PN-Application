(function () {
	'use strict';

	function syncPill(pill) {
		var input = pill ? pill.querySelector('input[type="checkbox"]') : null;

		if (!input) {
			return;
		}

		// Styling only: the native checkbox inside the <label> already
		// exposes its checked state to assistive technology.
		pill.classList.toggle('is-checked', input.checked);
	}

	function initCheckboxPills() {
		document.querySelectorAll('.pd-checkbox-pill').forEach(function (pill) {
			var input = pill.querySelector('input[type="checkbox"]');

			if (!input) {
				return;
			}

			syncPill(pill);

			if ('1' === pill.dataset.plandoseBound) {
				return;
			}

			pill.dataset.plandoseBound = '1';

			input.addEventListener('change', function () {
				syncPill(pill);
			});
		});
	}

	function confirmationMessage(action) {
		var cfg = (window.PlandoseAdminConfig && window.PlandoseAdminConfig.i18n) || {};
		var messages = {
			make_free: cfg.confirmMakeFree || 'Σίγουρα θέλετε να μετατρέψετε αυτή τη συνδρομή σε Free;',
			deny_access: cfg.confirmDenyAccess || 'Σίγουρα θέλετε να αποκλείσετε την πρόσβαση αυτού του χρήστη στο PlanDose;',
			reset_access: cfg.confirmResetAccess || 'Σίγουρα θέλετε να καταργήσετε τη χειροκίνητη ρύθμιση πρόσβασης και να επιστρέψετε στον αυτόματο έλεγχο;'
		};

		return Object.prototype.hasOwnProperty.call(messages, action)
			? messages[action]
			: '';
	}

	function initConfirmActions() {
		document.querySelectorAll('form.pd-inline-form').forEach(function (form) {
			var actionInput = form.querySelector('input[name="pd_action"]');

			if (!actionInput || '1' === form.dataset.plandoseConfirmBound) {
				return;
			}

			form.dataset.plandoseConfirmBound = '1';

			form.addEventListener('submit', function (event) {
				var message = confirmationMessage(actionInput.value);

				if (message && !window.confirm(message)) {
					event.preventDefault();
					event.stopPropagation();
				}
			});
		});
	}

	/*
	 * Forms marked .pd-confirm-submit ask data-confirm before submitting
	 * (replaces inline onsubmit handlers).
	 */
	function initConfirmSubmit() {
		document.querySelectorAll('form.pd-confirm-submit[data-confirm]').forEach(function (form) {
			if ('1' === form.dataset.plandoseSubmitConfirmBound) {
				return;
			}

			form.dataset.plandoseSubmitConfirmBound = '1';

			form.addEventListener('submit', function (event) {
				var message = form.getAttribute('data-confirm') || '';

				if (message && !window.confirm(message)) {
					event.preventDefault();
					event.stopPropagation();
				}
			});
		});
	}

	/*
	 * Choosing an invoice file submits its upload form at once (replaces
	 * an inline onchange handler).
	 */
	function initInvoiceAutoSubmit() {
		document.querySelectorAll('input.pd-invoice-file[type="file"]').forEach(function (input) {
			if ('1' === input.dataset.plandoseBound) {
				return;
			}

			input.dataset.plandoseBound = '1';

			input.addEventListener('change', function () {
				var form = input.form;

				if (!form || !input.files || !input.files.length) {
					return;
				}

				if (typeof form.requestSubmit === 'function') {
					form.requestSubmit();
				} else {
					form.submit();
				}
			});
		});
	}

	/*
	 * Quick-pick buttons next to the Pro duration field. They are plain
	 * type="button" and only write into the number input, so exactly one
	 * custom_days value is ever submitted and the field stays the single
	 * source of truth — the admin can still type any number afterwards.
	 */
	function initProDayPresets() {
		document.querySelectorAll('.pd-pro-preset').forEach(function (button) {
			if ('1' === button.dataset.plandoseBound) {
				return;
			}

			button.dataset.plandoseBound = '1';

			button.addEventListener('click', function () {
				var input = document.getElementById(button.dataset.pdTarget);

				if (!input) {
					return;
				}

				input.value = button.dataset.pdDays;
				input.dispatchEvent(new Event('change', { bubbles: true }));
				input.focus();
			});
		});
	}

	/*
	 * <details data-pd-lazy-url> boxes fetch their body the first time they
	 * are opened (admin-ajax, JSON { success, data: { html } }). The HTML is
	 * built and escaped by the server, the same markup the no-JS fallback
	 * link renders. On failure the fallback link is put back and the next
	 * opening tries again.
	 */
	function lazyMessage(key, fallback) {
		var cfg = (window.PlandoseAdminConfig && window.PlandoseAdminConfig.i18n) || {};

		return cfg[key] || fallback;
	}

	function initLazyDetails() {
		document.querySelectorAll('details[data-pd-lazy-url]').forEach(function (box) {
			var target = box.querySelector('[data-pd-lazy-target]');
			var url = box.getAttribute('data-pd-lazy-url');
			var fallback;

			if (!target || !url || '1' === box.dataset.plandoseBound || typeof window.fetch !== 'function') {
				return;
			}

			box.dataset.plandoseBound = '1';
			fallback = target.innerHTML;

			function fail(message) {
				var note = document.createElement('p');

				note.className = 'pd-danger';
				note.textContent = message || lazyMessage('lazyError', 'Οι λεπτομέρειες δεν φορτώθηκαν.');
				target.innerHTML = fallback;
				target.insertBefore(note, target.firstChild);
				target.removeAttribute('aria-busy');
				delete box.dataset.pdLazyState;
			}

			function load() {
				if (!box.open || box.dataset.pdLazyState) {
					return;
				}

				box.dataset.pdLazyState = 'loading';
				target.setAttribute('aria-busy', 'true');
				target.textContent = lazyMessage('lazyLoading', 'Φόρτωση…');

				window.fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
					.then(function (response) {
						return response.json().catch(function () {
							return null;
						});
					})
					.then(function (body) {
						if (!body || true !== body.success || !body.data || 'string' !== typeof body.data.html) {
							fail(body && body.data && 'string' === typeof body.data.message ? body.data.message : '');
							return;
						}

						target.innerHTML = body.data.html;
						target.removeAttribute('aria-busy');
						box.dataset.pdLazyState = 'loaded';
					})
					.catch(function () {
						fail('');
					});
			}

			box.addEventListener('toggle', load);
			load();
		});
	}

	function init() {
		initCheckboxPills();
		initConfirmActions();
		initConfirmSubmit();
		initInvoiceAutoSubmit();
		initProDayPresets();
		initLazyDetails();
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', init, { once: true });
	} else {
		init();
	}
}());