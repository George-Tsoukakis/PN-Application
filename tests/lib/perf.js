/* Time limits for the "no catastrophic backtracking" checks. TEST-ONLY.

   A wall-clock limit alone fails on a busy machine (a parallel test run,
   CI under load), although the code is fine. So a check passes when the
   work is either within the absolute budget (as on an idle machine) or
   grows linearly with its input: the full input is timed against a run
   of the same work on 1/8 of it, measured just before under the same
   load. Linear work takes ~8×; quadratic ~64×; exponential never ends.

   PD_PERF_FACTOR (default 1) multiplies every limit, for very slow
   machines. */
'use strict';

function factor() {
	const f = Number(process.env.PD_PERF_FACTOR);
	return Number.isFinite(f) && f > 0 ? f : 1;
}

function ms(fn) {
	const t0 = process.hrtime.bigint();
	const r = fn();
	return { ms: Number(process.hrtime.bigint() - t0) / 1e6, result: r };
}

/* run(n) does the work for input size n. Returns { ok, ms, result, why }.
   budgetMs: the absolute limit on an idle machine; maxRatio: the largest
   full/eighth time ratio accepted as linear (default 24: 3× headroom). */
function linearWithin(run, n, budgetMs, maxRatio) {
	const f = factor();
	const small = Math.max(1, Math.floor(n / 8));
	run(small); /* JIT warm-up */
	const smalls = [ms(() => run(small)).ms, ms(() => run(small)).ms, ms(() => run(small)).ms].sort((a, b) => a - b);
	const base = Math.max(smalls[1], 1); /* median; sub-ms timings are noise */
	const full = ms(() => run(n));
	const ratio = full.ms / base;
	const limitRatio = (maxRatio || 24) * f;
	const ok = full.ms < budgetMs * f || ratio < limitRatio;
	return {
		ok,
		ms: full.ms,
		result: full.result,
		why: Math.round(full.ms) + ' ms for n=' + n + ' (budget ' + budgetMs * f + ' ms), ' +
			Math.round(base) + ' ms for n=' + small + ', ratio ' + ratio.toFixed(1) + ' (limit ' + limitRatio + ')'
	};
}

module.exports = { factor, linearWithin };
