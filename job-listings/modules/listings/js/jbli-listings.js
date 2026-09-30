
(function () {
	'use strict';

	var jbli_cfg  = window.jbli_data || {};
	var jbli_i18n = jbli_cfg.i18n || {};

	function jbli_esc_html(jbli_value) {
		return String(jbli_value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function jbli_esc_attr(jbli_value) {
		return jbli_esc_html(jbli_value);
	}

	function jbli_pluralise_jobs(count) {
		var n = parseInt(count, 10) || 0;
		if (n === 0) { return 'Δεν βρέθηκαν αγγελίες'; }
		if (n === 1) { return 'Βρέθηκε 1 αγγελία'; }
		return 'Βρέθηκαν ' + jbli_esc_html(n) + ' αγγελίες';
	}

	function jbli_make_abort_controller() {
		if (typeof AbortController !== 'undefined') {
			return new AbortController();
		}
		return null;
	}

	document.addEventListener('DOMContentLoaded', function () {
		var jbli_ajax_pag = document.getElementById('jbli_pagination');
		if (jbli_ajax_pag) { jbli_ajax_pag.style.display = ''; }

		jbli_init_ajax_filters();
		jbli_init_delete_confirm();
		/* Form submit-loading and the character counter live in jbli-form.js. */
	});

	function jbli_init_ajax_filters() {
		var jbli_form      = document.getElementById('jbli_filters_form');
		var jbli_container = document.getElementById('jbli_cards_container');
		var jbli_count_el   = document.getElementById('jbli_results_count');
		var jbli_spinner   = document.getElementById('jbli_spinner');
		var jbli_clear_btn  = document.getElementById('jbli_filters_clear');

		if (!jbli_form || !jbli_container || !jbli_cfg.ajaxurl) { return; }

		if (jbli_count_el && !jbli_count_el.getAttribute('aria-live')) {
			jbli_count_el.setAttribute('aria-live', 'polite');
			jbli_count_el.setAttribute('aria-atomic', 'true');
		}

		var jbli_debounce_timer;
		var jbli_current_page = 1;
		var jbli_controller  = null;
		var jbli_is_loading   = false;

		function jbli_get_form_data(jbli_page) {
			var data = new FormData(jbli_form);
			data.set('action', 'jbli_filter');
			data.set('jbli_nonce',  jbli_cfg.jbli_nonce || '');
			data.set('paged',  jbli_page || 1);
			return data;
		}

		function jbli_set_loading(jbli_loading) {
			jbli_is_loading = jbli_loading;

			if (jbli_spinner) {
				jbli_spinner.style.display = jbli_loading ? 'inline-block' : 'none';
			}

			jbli_container.classList.toggle('is-loading', jbli_loading);
			jbli_container.style.opacity = jbli_loading ? '0.55' : '';

			jbli_container.setAttribute('aria-busy', jbli_loading ? 'true' : 'false');
		}

		function jbli_show_error(jbli_message) {
			if (!jbli_count_el) { return; }

			var jbli_retry_label = jbli_i18n.retry || 'Δοκιμάστε ξανά';
			jbli_count_el.innerHTML =
				jbli_esc_html(jbli_message) +
				' <button class="jbli_retry_btn" type="button">' +
				jbli_esc_html(jbli_retry_label) +
				'</button>';

			var jbli_retry_btn = jbli_count_el.querySelector('.jbli_retry_btn');
			if (jbli_retry_btn) {
				jbli_retry_btn.addEventListener('click', function () {
					jbli_do_ajax(jbli_current_page);
				});
			}
		}

		function jbli_do_ajax(jbli_page) {
			if (jbli_controller) { jbli_controller.abort(); }
			jbli_controller = jbli_make_abort_controller();

			jbli_set_loading(true);

			var jbli_fetch_options = {
				method:      'POST',
				body:        jbli_get_form_data(jbli_page || 1),
				credentials: 'same-origin'
			};

			if (jbli_controller) {
				jbli_fetch_options.signal = jbli_controller.signal;
			}

			fetch(jbli_cfg.ajaxurl, jbli_fetch_options)
				.then(function (jbli_res) {
					if (!jbli_res.ok) {
						throw new Error('Network response was not ok (' + jbli_res.status + ')');
					}
					return jbli_res.json();
				})
				.then(function (jbli_res) {
					if (!jbli_res || !jbli_res.success || !jbli_res.data) {
						throw new Error('Invalid AJAX response');
					}

					var data = jbli_res.data;

					jbli_container.innerHTML = typeof data.html === 'string' ? data.html : '';

					var jbli_old_modals = document.querySelectorAll('.jbli_apply_modal[data-jbli_ajax]');
					jbli_old_modals.forEach(function(m) { if (m.parentNode) { m.parentNode.removeChild(m); } });

					var jbli_modals_wrap = jbli_container.querySelector('.jbli_ajax_modals');
					if (jbli_modals_wrap) {
						var jbli_new_modals = jbli_modals_wrap.querySelectorAll('.jbli_apply_modal');
						jbli_new_modals.forEach(function(m) {
							m.setAttribute('data-jbli_ajax', '1');
							document.body.appendChild(m);
						});
						if (jbli_modals_wrap.parentNode) { jbli_modals_wrap.parentNode.removeChild(jbli_modals_wrap); }
					}
					if (jbli_count_el) {
						jbli_count_el.textContent = jbli_pluralise_jobs(data.count);
					}

					jbli_current_page = parseInt(data.paged, 10) || 1;
					jbli_render_pagination(parseInt(data.max_pages, 10) || 0, jbli_current_page);
					jbli_update_url();
				})
				.catch(function (error) {
					if (error && error.name === 'AbortError') { return; }

					jbli_show_error(jbli_i18n.generic_error || 'Παρουσιάστηκε σφάλμα. Δοκιμάστε ξανά.');
				})
				.finally(function () {
					jbli_set_loading(false);
				});
		}

		jbli_form.querySelectorAll('select').forEach(function (jbli_select) {
			jbli_select.addEventListener('change', function () {
				jbli_current_page = 1;
				jbli_do_ajax(1);
			});
		});

		/* 9.9.62: the map under the results selects a νομός in the filter. */
		var jbli_nomos_select = document.getElementById('f_nomos');

		function jbli_mark_map(jbli_id) {
			document.querySelectorAll('#jbli_map [data-jbli_nomos]').forEach(function (jbli_el) {
				jbli_el.classList.toggle('is_active', jbli_el.getAttribute('data-jbli_nomos') === String(jbli_id));
			});
		}

		if (jbli_nomos_select) {
			jbli_nomos_select.addEventListener('change', function () { jbli_mark_map(jbli_nomos_select.value); });

			document.addEventListener('click', function (e) {
				var jbli_pin = e.target.closest ? e.target.closest('#jbli_map [data-jbli_nomos]') : null;
				if (!jbli_pin || e.metaKey || e.ctrlKey || e.shiftKey) { return; }

				var jbli_id = jbli_pin.getAttribute('data-jbli_nomos');
				if (!jbli_nomos_select.querySelector('option[value="' + jbli_id + '"]')) { return; }

				e.preventDefault();
				jbli_nomos_select.value = jbli_id;
				jbli_nomos_select.dispatchEvent(new Event('change', { bubbles: true }));

				var jbli_root = document.getElementById('jbli_listings_root');
				if (jbli_root && jbli_root.scrollIntoView) { jbli_root.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
			});
		}

		var jbli_search_input = document.getElementById('jbli_search_input');
		if (jbli_search_input) {
			jbli_search_input.addEventListener('input', function () {
				window.clearTimeout(jbli_debounce_timer);
				jbli_debounce_timer = window.setTimeout(function () {
					jbli_current_page = 1;
					jbli_do_ajax(1);
				}, 350);
			});
		}

		jbli_form.addEventListener('submit', function (e) {
			e.preventDefault();
			if (jbli_is_loading) { return; }
			jbli_current_page = 1;
			jbli_do_ajax(1);
		});

		if (jbli_clear_btn) {
			jbli_clear_btn.addEventListener('click', function (e) {
				e.preventDefault();
				jbli_form.reset();
				jbli_current_page = 1;
				jbli_do_ajax(1);

				if (window.history && window.history.replaceState) {
					history.replaceState(null, '', window.location.pathname);
				}
			});
		}

		function jbli_render_pagination(jbli_max_pages, jbli_current) {
			var jbli_pagination = document.getElementById('jbli_pagination');
			if (!jbli_pagination) { return; }

			if (!jbli_max_pages || jbli_max_pages <= 1) {
				jbli_pagination.innerHTML = '';
				return;
			}

			var jbli_html = '<nav aria-label="' + jbli_esc_attr(jbli_i18n.pagination_label || 'Σελιδοποίηση αγγελιών') + '">';
			jbli_html += '<div id="jbli_ajax_pagination" class="jbli_ajax_pages">';

			if (jbli_current > 1) {
				jbli_html += '<a class="page-numbers prev" data-jbli_page="' + jbli_esc_attr(jbli_current - 1) + '" href="#" aria-label="' + jbli_esc_attr(jbli_i18n.prev_page || 'Προηγούμενη σελίδα') + '">← Προηγ.</a>';
			}

			for (var i = 1; i <= jbli_max_pages; i++) {
				if (i === jbli_current) {
					jbli_html += '<span class="page-numbers current" aria-current="page">' + jbli_esc_html(i) + '</span>';
				} else if (i === 1 || i === jbli_max_pages || Math.abs(i - jbli_current) <= 2) {
					jbli_html += '<a class="page-numbers" data-jbli_page="' + jbli_esc_attr(i) + '" href="#" aria-label="' + jbli_esc_attr((jbli_i18n.page_n || 'Σελίδα') + ' ' + i) + '">' + jbli_esc_html(i) + '</a>';
				} else if (Math.abs(i - jbli_current) === 3) {
					jbli_html += '<span class="page-numbers dots" aria-hidden="true">…</span>';
				}
			}

			if (jbli_current < jbli_max_pages) {
				jbli_html += '<a class="page-numbers next" data-jbli_page="' + jbli_esc_attr(jbli_current + 1) + '" href="#" aria-label="' + jbli_esc_attr(jbli_i18n.next_page || 'Επόμενη σελίδα') + '">Επόμ. →</a>';
			}

			jbli_html += '</div></nav>';
			jbli_pagination.innerHTML = jbli_html;

			jbli_pagination.querySelectorAll('a.page-numbers').forEach(function (jbli_link) {
				jbli_link.addEventListener('click', function (e) {
					e.preventDefault();
					if (jbli_is_loading) { return; }

					var jbli_page = parseInt(jbli_link.getAttribute('data-jbli_page'), 10) || 1;
					jbli_do_ajax(jbli_page);

					window.scrollTo({
						top:      jbli_container.getBoundingClientRect().top + window.pageYOffset - 80,
						behavior: 'smooth'
					});
				});
			});
		}

		function jbli_update_url() {
			if (!window.URLSearchParams || !window.history || !window.history.replaceState) { return; }

			var jbli_params   = new URLSearchParams();
			var jbli_form_data = new FormData(jbli_form);

			jbli_form_data.forEach(function (jbli_value, key) {
				if (key === 'action' || key === 'nonce') { return; }
				if (jbli_value) { jbli_params.set(key, jbli_value); }
			});

			if (jbli_current_page > 1) { jbli_params.set('paged', jbli_current_page); }

			var jbli_qs = jbli_params.toString();
			history.replaceState(null, '', jbli_qs ? '?' + jbli_qs : window.location.pathname);
		}
	}

	function jbli_init_delete_confirm() {
		document.addEventListener('submit', function (e) {
			var jbli_form = e.target.closest('.jbli_dash_action_form, .jbli_inline_form, .jbli_ap_form');
			if (!jbli_form) { return; }

			var jbli_btn = jbli_form.querySelector('button[data-confirm]');
			if (!jbli_btn) { return; }

			if (jbli_btn.dataset.submitted) {
				e.preventDefault();
				return;
			}

			var jbli_message = jbli_btn.getAttribute('data-confirm') || jbli_i18n.confirm_delete || 'Να διαγραφεί η αγγελία; Δεν υπάρχει αναίρεση.';

			if (!window.confirm(jbli_message)) {
				e.preventDefault();
				return;
			}

			jbli_btn.dataset.submitted = '1';
			jbli_btn.disabled = true;
			jbli_btn.classList.add('is-loading');
		});
	}

}());
