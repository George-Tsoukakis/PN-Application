
(function () {
	'use strict';

	function jbli_init_privacy_toggles() {
		var jbli_items = document.querySelectorAll( '.jbli_prv_item' );
		if ( ! jbli_items.length ) { return; }

		jbli_items.forEach( function ( jbli_item ) {
			var jbli_cb     = jbli_item.querySelector( '.jbli_prv_toggle_input' );
			var jbli_toggle = jbli_item.querySelector( '.jbli_prv_toggle' );
			if ( ! jbli_cb ) { return; }

			function jbli_sync() {
				jbli_item.classList.toggle( 'jbli_prv_item_on', jbli_cb.checked );
				if ( jbli_toggle ) {
					jbli_toggle.classList.toggle( 'jbli_prv_toggle_on', jbli_cb.checked );
				}
			}

			jbli_sync();

			jbli_item.addEventListener( 'click', function ( jbli_e ) {
				if ( jbli_e.target.tagName === 'A' ) { return; }
				var jbli_in_label = jbli_e.target.closest( '.jbli_prv_toggle' );
				if ( jbli_in_label ) { return; }
				jbli_cb.checked = ! jbli_cb.checked;
				jbli_sync();
			} );

			jbli_cb.addEventListener( 'change', jbli_sync );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', jbli_init_privacy_toggles );

}());

(function () {
	'use strict';

	if ( typeof ajaxurl === 'undefined' ) { return; }

	var jbli_modal    = document.getElementById( 'jbli_sub_modal' );
	var jbli_body     = document.getElementById( 'jbli_sub_body' );
	var jbli_close_btn = document.getElementById( 'jbli_sub_close' );
	var jbli_export_a  = document.getElementById( 'jbli_sub_export' );

	if ( ! jbli_modal ) { return; }

	function jbli_update_export_link() {
		var jbli_post_id     = jbli_export_a.getAttribute( 'data-post-id' );
		var jbli_nonce      = jbli_export_a.getAttribute( 'data-export-nonce' );
		var jbli_checked_ids = [];

		if ( ! jbli_post_id || ! jbli_nonce ) { return; }

		jbli_body.querySelectorAll( '.jbli_sub_row_cb' ).forEach( function ( jbli_cb ) {
			if ( jbli_cb.checked ) {
				jbli_checked_ids.push( jbli_cb.value );
			}
		} );

		if ( jbli_checked_ids.length === 0 ) {
			jbli_export_a.classList.add( 'jbli_disabled' );
		} else {
			jbli_export_a.classList.remove( 'jbli_disabled' );
		}

		jbli_export_a.href =
			ajaxurl.replace( 'admin-ajax.php', 'admin.php' ) +
			'?action=jbli_export_submissions' +
			'&post_id=' + jbli_post_id +
			'&_wpnonce=' + jbli_nonce +
			'&ids=' + jbli_checked_ids.join( ',' );
	}

	function jbli_open_modal( jbli_post_id, jbli_export_nonce ) {
		jbli_modal.classList.add( 'jbli_active' );
		jbli_body.innerHTML = '<p class="jbli_sub_modal_text_muted">Φόρτωση…</p>';
		jbli_export_a.classList.remove( 'jbli_active' );

		jbli_export_a.setAttribute( 'data-post-id', jbli_post_id );
		jbli_export_a.setAttribute( 'data-export-nonce', jbli_export_nonce );

		var jbli_form_data = new FormData();
		jbli_form_data.append( 'action',  'jbli_apply_submissions' );
		jbli_form_data.append( 'post_id', jbli_post_id );
		jbli_form_data.append(
			'nonce',
			document.querySelector( '[data-jbli_sub_nonce' + jbli_post_id + ']' )
				.dataset[ 'jbli_sub_nonce' + jbli_post_id ]
		);

		fetch( ajaxurl, { method: 'POST', body: jbli_form_data, credentials: 'same-origin' } )
			.then( function ( jbli_r ) { return jbli_r.json(); } )
			.then( function ( jbli_json ) {
				if ( jbli_json.success ) {
					jbli_body.innerHTML = jbli_json.data.html;

					if ( jbli_json.data.count > 0 ) {
						var jbli_select_all = jbli_body.querySelector( '#jbli_sub_select_all' );
						var jbli_row_cbs    = jbli_body.querySelectorAll( '.jbli_sub_row_cb' );

						if ( jbli_select_all ) {
							jbli_select_all.addEventListener( 'change', function () {
								var jbli_checked = jbli_select_all.checked;
								jbli_row_cbs.forEach( function ( jbli_cb ) {
									jbli_cb.checked = jbli_checked;
								} );
								jbli_update_export_link();
							} );
						}

						jbli_row_cbs.forEach( function ( jbli_cb ) {
							jbli_cb.addEventListener( 'change', function () {
								if ( jbli_select_all ) {
									var jbli_all_checked = true;
									jbli_row_cbs.forEach( function ( jbli_c ) {
										if ( ! jbli_c.checked ) { jbli_all_checked = false; }
									} );
									jbli_select_all.checked = jbli_all_checked;
								}
								jbli_update_export_link();
							} );
						} );

						jbli_update_export_link();
						jbli_export_a.classList.add( 'jbli_active' );
					}
				} else {
					jbli_body.innerHTML =
						'<p style="color:#dc2626;">' +
						( jbli_json.data && jbli_json.data.jbli_message
							? jbli_json.data.jbli_message
							: 'Σφάλμα φόρτωσης.' ) +
						'</p>';
				}
			} )
			.catch( function () {
				jbli_body.innerHTML = '<p style="color:#dc2626;">Σφάλμα δικτύου.</p>';
			} );
	}

	if ( jbli_close_btn ) {
		jbli_close_btn.addEventListener( 'click', function () {
			jbli_modal.classList.remove( 'jbli_active' );
		} );
	}

	jbli_modal.addEventListener( 'click', function ( jbli_e ) {
		if ( jbli_e.target === jbli_modal ) { jbli_modal.classList.remove( 'jbli_active' ); }
	} );

	document.addEventListener( 'keydown', function ( jbli_e ) {
		if ( jbli_e.key === 'Escape' ) { jbli_modal.classList.remove( 'jbli_active' ); }
	} );

	document.addEventListener( 'click', function ( jbli_e ) {
		var jbli_btn = jbli_e.target.closest( '[data-jbli_open_submissions]' );
		if ( ! jbli_btn ) { return; }
		var jbli_pid    = jbli_btn.dataset.jbli_open_submissions;
		var jbli_enonce = jbli_btn.dataset.jbli_export_nonce;
		jbli_open_modal( jbli_pid, jbli_enonce );
	} );

}());
