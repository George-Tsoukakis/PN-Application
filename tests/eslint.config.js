/* ESLint (flat config) for the plugin's browser JavaScript. TEST/DEV only,
   not shipped. Run from tests/: `npm run lint:js`.

   The plugin files are classic scripts (IIFEs, `var`, 'use strict'), loaded
   by WordPress with wp_enqueue_script; they share window.__PlandoseNS (PD)
   and read the localized config objects that PHP prints
   (wp_localize_script / wp_add_inline_script). */
'use strict';

const js = require('@eslint/js');
const globals = require('globals');

module.exports = [
	{
		/* Patterns are relative to the repository root: `npm run lint:js`
		   runs eslint there with -c tests/eslint.config.js (a config's own
		   directory would put the plugin outside the base path). */
		files: ['plandose/assets/js/*.js', 'plandose/public/calendar.js'],
		...js.configs.recommended,
		languageOptions: {
			ecmaVersion: 2020,
			sourceType: 'script',
			globals: {
				...globals.browser,
				/* Printed by PHP (wp_localize_script / inline scripts). */
				PlandoseConfig: 'readonly',
				PlandoseAdminConfig: 'readonly',
				PlandoseI18n: 'readonly',
				PlandoseLoader: 'readonly',
				PlanDoseCalendar: 'readonly',
				__PlandoseNS: 'writable'
			}
		},
		linterOptions: {
			reportUnusedDisableDirectives: 'error'
		},
		rules: {
			...js.configs.recommended.rules,
			/* Style, not bugs: `catch (e) {}` with e unused, callback
			   signatures kept whole (function (i, j)), `_private` copies. */
			'no-unused-vars': ['error', { args: 'none', caughtErrors: 'none', varsIgnorePattern: '^_' }],
			/* Style: `\/` and `\-` inside character classes are harmless and
			   often clearer in the Rx regexes. */
			'no-useless-escape': 'off'
		}
	},
	{
		/* calendar-qr.js bundles qrcode-generator 1.4.4 (MIT, vendored as is,
		   lines «---- qrcode-generator» … «---- end of qrcode-generator»):
		   it reuses `var i` / `var row` in consecutive loops. */
		files: ['plandose/assets/js/calendar-qr.js'],
		rules: {
			'no-redeclare': 'off'
		}
	}
];
