
(function () {
	'use strict';

	function jbli_open_modal( jbli_modal_id ) {
		var jbli_modal = document.getElementById( jbli_modal_id );
		if ( ! jbli_modal ) { return; }

		jbli_modal.hidden = false;
		document.body.style.overflow = 'hidden';

		var jbli_first = jbli_modal.querySelector( '.jbli_apply_input' );
		if ( jbli_first ) { setTimeout( function () { jbli_first.focus(); }, 60 ); }
	}

	function jbli_close_modal( jbli_modal_id ) {
		var jbli_modal = document.getElementById( jbli_modal_id );
		if ( ! jbli_modal ) { return; }

		jbli_modal.hidden = true;
		document.body.style.overflow = '';

		var jbli_notice = jbli_modal.querySelector( '.jbli_apply_modal_notice' );
		if ( jbli_notice ) {
			jbli_notice.hidden      = true;
			jbli_notice.className   = 'jbli_apply_modal_notice';
			jbli_notice.textContent = '';
		}

		var jbli_inputs = jbli_modal.querySelectorAll( '.jbli_apply_input' );
		jbli_inputs.forEach( function ( jbli_input ) { jbli_input.value = ''; } );

		var jbli_submit_label   = jbli_modal.querySelector( '.jbli_apply_modal_submit_label' );
		var jbli_submit_loading = jbli_modal.querySelector( '.jbli_apply_modal_submit_loading' );
		if ( jbli_submit_label )   { jbli_submit_label.hidden   = false; }
		if ( jbli_submit_loading ) { jbli_submit_loading.hidden = true; }

		var jbli_btn = jbli_modal.querySelector( '.jbli_apply_modal_submit' );
		if ( jbli_btn ) { jbli_btn.disabled = false; }
	}

	function jbli_submit_apply( jbli_modal_id ) {
		var jbli_modal = document.getElementById( jbli_modal_id );
		if ( ! jbli_modal ) { return; }

		var jbli_name  = jbli_modal.querySelector( '[name="applicant_name"]' );
		var jbli_phone = jbli_modal.querySelector( '[name="applicant_phone"]' );
		var jbli_email = jbli_modal.querySelector( '[name="applicant_email"]' );
		var jbli_pid   = jbli_modal.querySelector( '[name="post_id"]' );
		var jbli_nc    = jbli_modal.querySelector( '[name="nonce"]' );

		var jbli_notice      = jbli_modal.querySelector( '.jbli_apply_modal_notice' );
		var jbli_submit_btn   = jbli_modal.querySelector( '.jbli_apply_modal_submit' );
		var jbli_submit_label = jbli_modal.querySelector( '.jbli_apply_modal_submit_label' );
		var jbli_submit_load  = jbli_modal.querySelector( '.jbli_apply_modal_submit_loading' );

		function jbli_show_notice( jbli_msg, jbli_type ) {
			if ( ! jbli_notice ) { return; }
			jbli_notice.textContent = jbli_msg;
			jbli_notice.className   = 'jbli_apply_modal_notice jbli_apply_modal_notice_' + jbli_type;
			jbli_notice.hidden      = false;
		}

		if ( ! jbli_name.value.trim() || ! jbli_phone.value.trim() || ! jbli_email.value.trim() ) {
			jbli_show_notice(
				( window.jbli_data && window.jbli_data.i18n && window.jbli_data.i18n.generic_error )
					|| 'Παρακαλώ συμπληρώστε όλα τα πεδία.',
				'error'
			);
			return;
		}

		jbli_submit_btn.disabled = true;
		if ( jbli_submit_label ) { jbli_submit_label.hidden = true; }
		if ( jbli_submit_load )  { jbli_submit_load.hidden  = false; }

		var jbli_form_data = new FormData();
		jbli_form_data.append( 'action',          'jbli_apply' );
		jbli_form_data.append( 'post_id',         jbli_pid   ? jbli_pid.value   : '' );
		jbli_form_data.append( 'nonce',           jbli_nc    ? jbli_nc.value    : '' );
		jbli_form_data.append( 'applicant_name',  jbli_name.value.trim() );
		jbli_form_data.append( 'applicant_phone', jbli_phone.value.trim() );
		jbli_form_data.append( 'applicant_email', jbli_email.value.trim() );

		var jbli_ajax_url = ( window.jbli_data && window.jbli_data.ajaxurl )
			|| '/wp-admin/admin-ajax.php';

		fetch( jbli_ajax_url, { method: 'POST', body: jbli_form_data } )
			.then( function ( jbli_r ) { return jbli_r.json(); } )
			.then( function ( jbli_res ) {
				if ( jbli_res.success ) {
					jbli_show_notice(
						jbli_res.data && jbli_res.data.jbli_message
							? jbli_res.data.jbli_message
							: 'Εστάλη επιτυχώς!',
						'success'
					);
					var jbli_form_wrap = jbli_modal.querySelector( '.jbli_apply_modal_form_wrap' );
					if ( jbli_form_wrap ) { jbli_form_wrap.style.display = 'none'; }
				} else {
					jbli_show_notice(
						jbli_res.data && jbli_res.data.jbli_message
							? jbli_res.data.jbli_message
							: 'Παρουσιάστηκε σφάλμα.',
						'error'
					);
					jbli_submit_btn.disabled = false;
					if ( jbli_submit_label ) { jbli_submit_label.hidden = false; }
					if ( jbli_submit_load )  { jbli_submit_load.hidden  = true; }
				}
			} )
			.catch( function () {
				jbli_show_notice( 'Παρουσιάστηκε σφάλμα. Δοκιμάστε ξανά.', 'error' );
				jbli_submit_btn.disabled = false;
				if ( jbli_submit_label ) { jbli_submit_label.hidden = false; }
				if ( jbli_submit_load )  { jbli_submit_load.hidden  = true; }
			} );
	}

	document.addEventListener( 'click', function ( jbli_e ) {
		var jbli_t = jbli_e.target.closest( '[data-jbli_apply_open]' );
		if ( jbli_t ) { jbli_open_modal( jbli_t.getAttribute( 'data-jbli_apply_open' ) ); return; }

		var jbli_c = jbli_e.target.closest( '[data-jbli_apply_close]' );
		if ( jbli_c ) { jbli_close_modal( jbli_c.getAttribute( 'data-jbli_apply_close' ) ); return; }

		var jbli_s = jbli_e.target.closest( '[data-jbli_apply_submit]' );
		if ( jbli_s ) { jbli_submit_apply( jbli_s.getAttribute( 'data-jbli_apply_submit' ) ); return; }
	} );

	document.addEventListener( 'keydown', function ( jbli_e ) {
		if ( jbli_e.key !== 'Escape' ) { return; }
		var jbli_open = document.querySelector( '.jbli_apply_modal:not([hidden])' );
		if ( jbli_open ) { jbli_close_modal( jbli_open.id ); }
	} );

}());
