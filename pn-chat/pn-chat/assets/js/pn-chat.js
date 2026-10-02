/**
 * PN Chat widget. No dependencies, no outside services: questions go only to
 * this site's REST API (pn-chat/v1).
 */
(function () {
	'use strict';

	var cfg = window.PNChatConfig || {};
	var STORE_KEY = 'pnchat:v1';
	var MAX_KEPT = 40;

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				if (k === 'text') {
					node.textContent = attrs[k];
				} else if (k === 'className') {
					node.className = attrs[k];
				} else if (k.indexOf('on') === 0 && typeof attrs[k] === 'function') {
					node.addEventListener(k.slice(2), attrs[k]);
				} else if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) {
					node.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
				}
			});
		}
		(children || []).forEach(function (c) {
			if (c) {
				node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
			}
		});
		return node;
	}

	function load() {
		try {
			var raw = window.sessionStorage.getItem(STORE_KEY);
			var data = raw ? JSON.parse(raw) : null;
			if (data && Array.isArray(data.messages)) {
				return data;
			}
		} catch (e) { /* storage blocked or corrupt: start fresh */ }
		return { open: false, messages: [] };
	}

	function save(state) {
		try {
			window.sessionStorage.setItem(STORE_KEY, JSON.stringify({
				open: state.open,
				topic: state.topic || '',
				messages: state.messages.slice(-MAX_KEPT)
			}));
		} catch (e) { /* ignore */ }
	}

	function api(path, body, retried) {
		var headers = { 'Content-Type': 'application/json' };
		if (cfg.nonce && !retried) {
			headers['X-WP-Nonce'] = cfg.nonce;
		}
		return fetch(cfg.api + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers,
			body: JSON.stringify(body)
		}).then(function (res) {
			return res.json().catch(function () { return {}; }).then(function (data) {
				if (!res.ok) {
					// An expired nonce on a long-open page: try once without it.
					if (!retried && data && data.code === 'rest_cookie_invalid_nonce') {
						return api(path, body, true);
					}
					var err = new Error((data && data.message) || 'Κάτι πήγε στραβά. Δοκιμάστε ξανά.');
					err.status = res.status;
					throw err;
				}
				return data;
			});
		});
	}

	var ICON_CHAT = '<svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 3C6.5 3 2 6.6 2 11c0 2.2 1.1 4.2 3 5.6V21l4.1-2.3c.9.2 1.9.3 2.9.3 5.5 0 10-3.6 10-8s-4.5-8-10-8zm-4 9.3a1.3 1.3 0 1 1 0-2.6 1.3 1.3 0 0 1 0 2.6zm4 0a1.3 1.3 0 1 1 0-2.6 1.3 1.3 0 0 1 0 2.6zm4 0a1.3 1.3 0 1 1 0-2.6 1.3 1.3 0 0 1 0 2.6z"/></svg>';
	// Two stroked lines with their own colour: theme CSS for svg/path fills cannot hide it.
	var ICON_CLOSE = '<svg class="pnchat__close-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M6 6 18 18M18 6 6 18" fill="none" stroke="#ef4444" stroke-width="3" stroke-linecap="round"/></svg>';
	var ICON_UP = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M2 21h4V9H2v12zm20-11a2 2 0 0 0-2-2h-6.3l1-4.6v-.3c0-.4-.2-.8-.4-1.1L13.2 1 6.6 7.6C6.2 8 6 8.5 6 9v10a2 2 0 0 0 2 2h9c.8 0 1.5-.5 1.8-1.2l3-7.1c.1-.2.2-.5.2-.7v-2z"/></svg>';
	var ICON_DOWN = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M22 3h-4v12h4V3zM2 14a2 2 0 0 0 2 2h6.3l-1 4.6v.3c0 .4.2.8.4 1.1l1.1 1L17.4 16.4c.4-.4.6-.9.6-1.4V5a2 2 0 0 0-2-2H7c-.8 0-1.5.5-1.8 1.2l-3 7.1c-.1.2-.2.5-.2.7v2z"/></svg>';
	var ICON_SEND = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path fill="currentColor" d="M3 20.5 21 12 3 3.5 3 10l12 2-12 2z"/></svg>';

	function Chat(host, inline) {
		this.host = host;
		this.inline = inline;
		this.state = load();
		this.busy = false;
		this.build();
		this.restore();
		if (inline || this.state.open) {
			this.open(false);
		}
	}

	Chat.prototype.build = function () {
		var self = this;
		var uid = 'pnchat-' + Math.random().toString(36).slice(2, 8);

		this.root = el('div', {
			className: 'pnchat ' + (this.inline ? 'pnchat--inline' : 'pnchat--floating') + (cfg.position === 'left' ? ' pnchat--left' : '')
		});

		this.log = el('div', { className: 'pnchat__log', role: 'log', 'aria-live': 'polite', 'aria-relevant': 'additions', tabindex: '0', 'aria-label': 'Συνομιλία' });

		this.input = el('textarea', {
			className: 'pnchat__input',
			id: uid + '-q',
			rows: '1',
			maxlength: String(cfg.maxLength || 500),
			placeholder: cfg.placeholder || '',
			'aria-label': cfg.placeholder || 'Ερώτηση',
			enterkeyhint: 'send',
			autocomplete: 'off'
		});
		this.input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
				e.preventDefault();
				self.submit();
			}
		});
		this.input.addEventListener('input', function () { self.autosize(); });

		this.sendBtn = el('button', { type: 'submit', className: 'pnchat__send', 'aria-label': 'Αποστολή' });
		this.sendBtn.innerHTML = ICON_SEND;

		var form = el('form', { className: 'pnchat__form', onsubmit: function (e) { e.preventDefault(); self.submit(); } }, [this.input, this.sendBtn]);

		this.chips = el('div', { className: 'pnchat__chips', role: 'group', 'aria-label': 'Συχνές ερωτήσεις' });
		this.buildChips(uid);
		// Hidden while the suggestions show; after the first question it brings them back.
		this.topicsBtn = el('button', { type: 'button', className: 'pnchat__topics', 'aria-expanded': 'false', hidden: true, text: 'Συχνές ερωτήσεις', onclick: function () { self.toggleChips(); } });

		var closeBtn = null;
		if (!this.inline) {
			closeBtn = el('button', { type: 'button', className: 'pnchat__close', 'aria-label': 'Κλείσιμο', onclick: function () { self.close(true); } });
			closeBtn.innerHTML = ICON_CLOSE;
		}

		var header = el('div', { className: 'pnchat__header' }, [
			el('div', { className: 'pnchat__heading' }, [
				el('h2', { className: 'pnchat__title', id: uid + '-t', text: cfg.title || 'Chat' }),
				cfg.subtitle ? el('p', { className: 'pnchat__subtitle', text: cfg.subtitle }) : null
			]),
			closeBtn
		]);

		this.panel = el('div', {
			className: 'pnchat__panel',
			role: this.inline ? 'region' : 'dialog',
			'aria-labelledby': uid + '-t',
			hidden: this.inline ? null : true
		}, [
			header,
			this.log,
			this.chips,
			this.topicsBtn,
			form,
			cfg.privacy ? el('p', { className: 'pnchat__privacy', text: cfg.privacy }) : null
		]);

		if (!this.inline) {
			this.launcher = el('button', {
				type: 'button',
				className: 'pnchat__launcher',
				'aria-label': cfg.title || 'Chat',
				'aria-expanded': 'false',
				onclick: function () { self.toggle(); }
			});
			this.launcher.innerHTML = ICON_CHAT;
			this.root.appendChild(this.launcher);
			document.addEventListener('keydown', function (e) {
				if (e.key === 'Escape' && !self.panel.hidden) {
					self.close(true);
				}
			});
		}
		this.root.appendChild(this.panel);
		this.host.appendChild(this.root);

		// Other floating buttons in the same corner (PlanDose): sit above them.
		if (!this.inline) {
			var place = function () { self.placeLauncher(); };
			window.addEventListener('resize', place);
			place();
			window.setTimeout(place, 600);
			window.setTimeout(place, 2500);
		}

		// Phones: keep the panel above the on-screen keyboard.
		if (window.visualViewport && !this.inline) {
			var fit = function () {
				self.root.style.setProperty('--pnchat-vh', window.visualViewport.height + 'px');
			};
			window.visualViewport.addEventListener('resize', fit);
			fit();
		}
	};

	/**
	 * Moves the launcher above a visible fixed button of the same corner
	 * (cfg.avoid selectors, by default PlanDose's #plandose-trigger).
	 */
	Chat.prototype.placeLauncher = function () {
		var left = cfg.position === 'left';
		var bottom = null;
		var side = null;
		(cfg.avoid || []).forEach(function (sel) {
			var nodes;
			try {
				nodes = document.querySelectorAll(sel);
			} catch (e) {
				return;
			}
			Array.prototype.forEach.call(nodes, function (n) {
				var r = n.getBoundingClientRect();
				if (!r.width || !r.height || getComputedStyle(n).visibility === 'hidden') {
					return;
				}
				var onLeft = r.left + r.width / 2 < window.innerWidth / 2;
				if (onLeft !== left) {
					return;
				}
				var b = window.innerHeight - r.top + 12;
				if (bottom === null || b > bottom) {
					bottom = b;
					side = left ? r.left : window.innerWidth - r.right;
				}
			});
		});
		this.root.style.bottom = bottom === null ? '' : bottom + 'px';
		this.root.style[left ? 'left' : 'right'] = bottom === null ? '' : Math.max(side, 8) + 'px';
	};

	/**
	 * Suggested questions, in groups («# Ερωτήσεις για …» in the settings).
	 * A titled group opens with one tap (the first one starts open); an item
	 * with a url is a link to that page instead of a question.
	 */
	Chat.prototype.buildChips = function (uid) {
		var self = this;
		var groups = cfg.suggestions || [];
		// Settings saved before groups existed arrive as plain strings.
		if (groups.length && typeof groups[0] === 'string') {
			groups = [{ title: '', items: groups.map(function (t) { return { text: t }; }) }];
		}
		var firstTitled = true;
		groups.forEach(function (g, gi) {
			var list = el('div', { className: 'pnchat__chip-list' });
			(g.items || []).forEach(function (it) {
				if (it.url) {
					list.appendChild(el('a', { className: 'pnchat__chip pnchat__chip--link', href: it.url, text: it.text + ' →' }));
				} else {
					list.appendChild(el('button', { type: 'button', className: 'pnchat__chip', text: it.text, onclick: function () { self.send(it.text); } }));
				}
			});
			if (!g.title) {
				self.chips.appendChild(list);
				return;
			}
			var id = uid + '-g' + gi;
			var open = firstTitled;
			firstTitled = false;
			list.id = id;
			list.hidden = !open;
			var head = el('button', {
				type: 'button',
				className: 'pnchat__group',
				'aria-expanded': open ? 'true' : 'false',
				'aria-controls': id,
				text: g.title,
				onclick: function () {
					var nowOpen = list.hidden;
					list.hidden = !nowOpen;
					head.setAttribute('aria-expanded', nowOpen ? 'true' : 'false');
				}
			});
			self.chips.appendChild(el('div', { className: 'pnchat__chip-group' }, [head, list]));
		});
		if (!groups.length) {
			this.chips.hidden = true;
		}
	};

	/** Hides the suggestions (a question was asked) and offers them again. */
	Chat.prototype.hideChips = function () {
		this.chips.hidden = true;
		this.topicsBtn.hidden = !(cfg.suggestions || []).length;
		this.topicsBtn.setAttribute('aria-expanded', 'false');
	};

	/** Shows the suggestions again after the conversation started. */
	Chat.prototype.toggleChips = function () {
		this.chips.hidden = !this.chips.hidden;
		this.topicsBtn.setAttribute('aria-expanded', this.chips.hidden ? 'false' : 'true');
		if (!this.chips.hidden) {
			this.chips.scrollTop = 0;
		}
	};

	Chat.prototype.autosize = function () {
		this.input.style.height = 'auto';
		this.input.style.height = Math.min(this.input.scrollHeight, 120) + 'px';
	};

	Chat.prototype.toggle = function () {
		if (this.panel.hidden) {
			this.open(true);
		} else {
			this.close(true);
		}
	};

	Chat.prototype.open = function (focus) {
		this.panel.hidden = false;
		this.root.classList.add('is-open');
		if (this.launcher) {
			this.launcher.setAttribute('aria-expanded', 'true');
			document.documentElement.classList.add('pnchat-open');
		}
		if (!this.log.childNodes.length && cfg.welcome) {
			this.addBot({ text: cfg.welcome }, false);
		}
		this.state.open = !this.inline;
		save(this.state);
		this.scroll();
		if (focus) {
			this.input.focus();
		}
	};

	Chat.prototype.close = function (focusLauncher) {
		if (this.inline) {
			return;
		}
		this.panel.hidden = true;
		this.root.classList.remove('is-open');
		this.launcher.setAttribute('aria-expanded', 'false');
		document.documentElement.classList.remove('pnchat-open');
		this.state.open = false;
		save(this.state);
		if (focusLauncher) {
			this.launcher.focus();
		}
	};

	Chat.prototype.scroll = function () {
		this.log.scrollTop = this.log.scrollHeight;
	};

	Chat.prototype.restore = function () {
		var self = this;
		this.state.messages.forEach(function (m) {
			if (m.role === 'user') {
				self.addUser(m.text, false);
			} else {
				self.addBot(m, false);
			}
		});
		if (this.state.messages.length) {
			this.hideChips();
		}
	};

	Chat.prototype.remember = function (m) {
		this.state.messages.push(m);
		save(this.state);
	};

	Chat.prototype.addUser = function (text, keep) {
		this.log.appendChild(el('div', { className: 'pnchat__msg pnchat__msg--user' }, [el('div', { className: 'pnchat__bubble', text: text })]));
		if (keep) {
			this.remember({ role: 'user', text: text });
		}
		this.scroll();
	};

	/**
	 * A bot message: plain text, trained answers (server-sanitised HTML),
	 * refusals, and the "leave your e-mail" message.
	 */
	Chat.prototype.addBot = function (m, keep, live) {
		var wrap = el('div', { className: 'pnchat__msg pnchat__msg--bot' });
		if (m.text) {
			wrap.appendChild(el('div', { className: 'pnchat__bubble', text: m.text }));
		}
		(m.items || []).forEach(function (it) {
			var card = el('div', { className: 'pnchat__bubble pnchat__answer' + (it.kind === 'block' ? ' pnchat__answer--block' : '') + (it.kind === 'site' ? ' pnchat__answer--site' : '') + (it.kind === 'ai' ? ' pnchat__answer--ai' : '') });
			if (it.label) {
				card.appendChild(el('p', { className: 'pnchat__answer-label', text: it.label }));
			}
			// Site pages always show their title; trained answers only when combined.
			if (it.title && (it.kind === 'site' || m.items.length > 1)) {
				card.appendChild(el('p', { className: 'pnchat__answer-title', text: it.title }));
			}
			var body = el('div', { className: 'pnchat__answer-body' });
			body.innerHTML = it.html; // Sanitised by the server (wp_kses allow-list).
			card.appendChild(body);
			wrap.appendChild(card);
		});
		if (m.message) {
			wrap.appendChild(el('div', { className: 'pnchat__bubble pnchat__bubble--notice', text: m.message }));
		}
		if (live && live.ask_email) {
			wrap.appendChild(this.emailForm(live));
		}
		if (live && live.feedback) {
			wrap.appendChild(this.feedbackRow(live, wrap));
		}
		this.log.appendChild(wrap);
		if (keep) {
			this.remember({ role: 'bot', text: m.text || '', items: m.items || [], message: m.message || '' });
		}
		this.scroll();
		return wrap;
	};

	Chat.prototype.typing = function (on) {
		if (on) {
			this.typingNode = el('div', { className: 'pnchat__msg pnchat__msg--bot pnchat__typing', 'aria-label': 'Γράφει…' }, [
				el('div', { className: 'pnchat__bubble' }, [el('span'), el('span'), el('span')])
			]);
			this.log.appendChild(this.typingNode);
			this.scroll();
			// An AI answer takes a while: say so after a few seconds.
			if (cfg.aiWait) {
				var node = this.typingNode;
				var self = this;
				this.typingTimer = window.setTimeout(function () {
					if (node === self.typingNode) {
						node.querySelector('.pnchat__bubble').appendChild(el('em', { className: 'pnchat__wait', text: cfg.aiWait }));
						self.scroll();
					}
				}, 3000);
			}
		} else if (this.typingNode) {
			window.clearTimeout(this.typingTimer);
			this.typingNode.remove();
			this.typingNode = null;
		}
	};

	Chat.prototype.submit = function () {
		var q = this.input.value.trim();
		if (q) {
			this.send(q);
		}
	};

	Chat.prototype.send = function (q) {
		var self = this;
		if (this.busy) {
			return;
		}
		this.busy = true;
		this.sendBtn.disabled = true;
		this.hideChips();
		this.input.value = '';
		this.autosize();
		this.addUser(q, true);
		this.typing(true);
		api('/ask', { question: q, page: window.location.href.split('#')[0], context: this.state.topic || '' }).then(function (res) {
			self.typing(false);
			// The topic of this answer is the context of the next question.
			if (res.topic) {
				self.state.topic = res.topic;
				save(self.state);
			}
			self.addBot({ text: res.intro || '', items: res.items || [], message: res.message || '' }, true, res);
		}).catch(function (err) {
			self.typing(false);
			self.addBot({ text: err.message }, false);
		}).then(function () {
			self.busy = false;
			self.sendBtn.disabled = false;
		});
	};

	Chat.prototype.emailForm = function (live) {
		var self = this;
		var uid = 'pnchat-e-' + live.id;
		var email = el('input', { type: 'email', id: uid, className: 'pnchat__field', required: true, autocomplete: 'email', inputmode: 'email', placeholder: 'το e-mail σας', value: live.user_email || '' });
		var name = el('input', { type: 'text', className: 'pnchat__field', autocomplete: 'name', placeholder: 'Όνομα / Φαρμακείο (προαιρετικό)', 'aria-label': 'Όνομα (προαιρετικό)', maxlength: '190' });
		// Honeypot, hidden from people and screen readers.
		var trap = el('input', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off', className: 'pnchat__trap', 'aria-hidden': 'true' });
		var btn = el('button', { type: 'submit', className: 'pnchat__btn', text: 'Αποστολή' });
		var status = el('p', { className: 'pnchat__form-status', role: 'status' });
		var form = el('form', { className: 'pnchat__email' }, [
			el('label', { 'for': uid, className: 'pnchat__label', text: 'E-mail για απάντηση' }),
			email, name, trap, btn, status
		]);
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			if (!email.value.trim()) {
				email.focus();
				return;
			}
			btn.disabled = true;
			status.textContent = 'Αποστολή…';
			api('/email', { id: live.id, token: live.token, email: email.value.trim(), name: name.value.trim(), website: trap.value }).then(function (res) {
				var done = el('div', { className: 'pnchat__bubble pnchat__bubble--ok', text: res.message || 'Ευχαριστούμε!' });
				form.replaceWith(done);
				self.remember({ role: 'bot', text: res.message || 'Ευχαριστούμε!' });
				self.scroll();
			}).catch(function (err) {
				btn.disabled = false;
				status.textContent = err.message;
			});
		});
		return form;
	};

	Chat.prototype.feedbackRow = function (live, wrap) {
		var self = this;
		var row = el('div', { className: 'pnchat__feedback', role: 'group', 'aria-label': 'Σας βοήθησε η απάντηση;' });
		function vote(helpful) {
			row.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
			api('/feedback', { id: live.id, token: live.token, helpful: helpful }).then(function (res) {
				row.replaceWith(el('p', { className: 'pnchat__feedback-done', text: helpful ? 'Ευχαριστούμε!' : '' }));
				if (!helpful) {
					var more = el('div', { className: 'pnchat__bubble pnchat__bubble--notice', text: res.message });
					wrap.appendChild(more);
					if (res.ask_email && !wrap.querySelector('.pnchat__email')) {
						wrap.appendChild(self.emailForm(live));
					}
					self.scroll();
				}
			}).catch(function () {
				row.remove();
			});
		}
		row.appendChild(el('span', { text: 'Σας βοήθησε;' }));
		// Icons, not emoji: WordPress' emoji script would swap emoji for remote images.
		var up = el('button', { type: 'button', className: 'pnchat__vote', 'aria-label': 'Ναι, βοήθησε', onclick: function () { vote(true); } });
		up.innerHTML = ICON_UP;
		var down = el('button', { type: 'button', className: 'pnchat__vote', 'aria-label': 'Όχι, δεν βοήθησε', onclick: function () { vote(false); } });
		down.innerHTML = ICON_DOWN;
		row.appendChild(up);
		row.appendChild(down);
		return row;
	};

	function boot() {
		if (!cfg.api) {
			return;
		}
		var inline = document.querySelector('[data-pnchat-inline]');
		if (inline) {
			window.PNChat = new Chat(inline, true);
			return;
		}
		var floating = document.querySelector('[data-pnchat-floating]');
		if (floating) {
			window.PNChat = new Chat(floating, false);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
}());
