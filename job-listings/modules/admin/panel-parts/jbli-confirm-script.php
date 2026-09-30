<?php
/**
 * Admin panel partial: Confirm dialog script
 *
 * Inline JS for data-confirm buttons (delete, dangerous settings).
 * Intentionally tiny and inline — no external file dependency.
 *
 * @package JobListings
 * @since   9.9.25
 */

defined( 'ABSPATH' ) || exit;
?>

<script>
document.addEventListener('DOMContentLoaded', function () {
	document.querySelectorAll('button[data-confirm]').forEach(function (btn) {
		var form = btn.closest('form');

		if ( ! form ) { return; }


		form.addEventListener('submit', function (e) {

			if ( btn.dataset.submitted )
			{
				e.preventDefault();
				return false;
			}

			var message = btn.getAttribute('data-confirm') || 'Να διαγραφεί η αγγελία;';

			if ( ! window.confirm(message ) )
			{
				e.preventDefault();
				return false;
			}

			btn.dataset.submitted = '1';
			btn.disabled = true;
		});
	});
});
</script>
