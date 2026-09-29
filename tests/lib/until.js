/* Polls a condition instead of sleeping a fixed time. TEST-ONLY.

   A fixed wait (e.g. 150 ms for a flow built from 30-40 ms timers) fails
   when the machine is busy; polling waits exactly as long as needed, up to
   a generous limit, and fails with a clear message if it never happens. */
'use strict';

function until(cond, ms, what) {
	const end = Date.now() + (ms || 10000);
	return new Promise((resolve, reject) => {
		(function poll() {
			let ok = false;
			try {
				ok = !!cond();
			} catch (e) {
				return reject(e);
			}
			if (ok) {
				return resolve();
			}
			if (Date.now() > end) {
				return reject(new Error('timed out waiting for ' + (what || 'the condition')));
			}
			setTimeout(poll, 5);
		})();
	});
}

module.exports = { until };
