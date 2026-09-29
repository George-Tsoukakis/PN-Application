/* A fake clock for the timers of one jsdom window. TEST-ONLY.

   The plugin's modules call setTimeout/clearTimeout on the window (the
   global of the page), so replacing them there drives every timer of the
   tool without real waiting — no fixed sleeps that fail under CPU load.
   Date is not faked. Install it before the code under test arms a timer. */
'use strict';

function install(w) {
	let now = 0;
	let seq = 0;
	const timers = new Map();
	const realSet = w.setTimeout;
	const realClear = w.clearTimeout;
	w.setTimeout = (fn, ms) => {
		const id = ++seq;
		timers.set(id, { id, at: now + Math.max(0, Number(ms) || 0), fn });
		return id;
	};
	w.clearTimeout = (id) => {
		timers.delete(id);
	};
	/* Runs every timer due within ms, in time order (ties: arming order),
	   including timers armed by those timers. */
	function advance(ms) {
		const end = now + Math.max(0, ms || 0);
		for (;;) {
			let next = null;
			for (const t of timers.values()) {
				if (t.at <= end && (!next || t.at < next.at || (t.at === next.at && t.id < next.id))) {
					next = t;
				}
			}
			if (!next) {
				break;
			}
			timers.delete(next.id);
			now = next.at;
			next.fn();
		}
		now = end;
	}
	return {
		advance,
		now: () => now,
		pending: () => timers.size,
		restore() {
			w.setTimeout = realSet;
			w.clearTimeout = realClear;
		}
	};
}

module.exports = { install };
