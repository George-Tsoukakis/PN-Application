<?php
/**
 * 1.8.3: the AI guard measured on questions labelled by hand
 * (fixtures/ai-medical-*.php). The AI may answer only questions about the
 * site's tools with no medical sign; the model's own «medical_advice» flag
 * is a second check. TEST-ONLY.
 */
require __DIR__ . '/lib.php';

foreach ( array( 'ai-medical-questions.php', 'ai-medical-holdout.php' ) as $f ) {
	$cases = require __DIR__ . '/fixtures/' . $f;
	$leaks = array();
	$kept  = 0;
	foreach ( $cases as $q => $medical ) {
		$may = PNChat_AI::may_answer( $q );
		if ( $medical && $may ) {
			$leaks[] = $q;
		}
		$kept += ( ! $medical && ! $may ) ? 1 : 0;
	}
	pnt_same( array(), $leaks, "$f: no medical question reaches the AI (" . array_sum( $cases ) . ' medical)' );
	$other = count( $cases ) - array_sum( $cases );
	echo "info   $f: $kept of $other other questions get no AI answer (site pages and e-mail form instead)\n";
}

// The word lists alone, on the tuning set: tool questions are not called medical.
$cases = require __DIR__ . '/fixtures/ai-medical-questions.php';
$wrong = array();
foreach ( $cases as $q => $medical ) {
	if ( PNChat_AI::is_medical( $q ) !== (bool) $medical ) {
		$wrong[] = $q;
	}
}
pnt_same( array(), $wrong, 'is_medical(): all 80 tuning questions labelled right' );

// The model's own check: «medical_advice» true means no answer.
pnt_settings( array( 'ai_chat' => 1, 'site_search' => 1, 'ai_chat_daily' => 50 ) );
update_option( 'pnchat_ai_key', 'sk-ant-test-0000000000000000000000', false );
pnt_defer(
	function () {
		global $wpdb;
		delete_option( 'pnchat_ai_key' );
		pnt_clear_questions();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE k LIKE %s OR k LIKE %s', PNChat_Counter::table(), 'ai_chat:%', 'rl:%' ) );
	}
);
$page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Λογότυπο στην εκτύπωση',
		'post_content' => 'Το λογότυπο στην εκτύπωση αλλάζει από τις Ρυθμίσεις. Ανεβάστε το λογότυπο του φαρμακείου σε PNG.',
	)
);
pnt_defer(
	function () use ( $page_id ) {
		wp_delete_post( $page_id, true );
	}
);
$medical_flag = true;
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$medical_flag ) {
		if ( false === strpos( $url, 'api.anthropic.com' ) ) {
			return $pre;
		}
		return pnt_claude_reply(
			array(
				'medical_advice' => $medical_flag,
				'found'          => true,
				'note'           => 'ok',
				'entry'          => array(
					'title'      => 'Εκτύπωση',
					'phrasings'  => array( 'Πώς τυπώνω το πλάνο;' ),
					'keywords'   => array(),
					'answer'     => '<p>Από το κουμπί Εκτύπωση.</p>',
					'source_url' => '',
				),
			)
		);
	},
	10,
	3
);
list( , $r ) = pnt_rest( '/ask', array( 'question' => 'Πώς αλλάζω το λογότυπο στην εκτύπωση;' ), '198.51.100.40' );
pnt_same( 'site', $r['status'] ?? null, 'model says medical_advice: no AI answer, the site\'s page instead' );
$medical_flag = false;
list( , $r ) = pnt_rest( '/ask', array( 'question' => 'Πώς αλλάζει το λογότυπο της εκτύπωσης;' ), '198.51.100.41' );
pnt_same( 'ai', $r['status'] ?? null, 'model says not medical: the AI answer is shown' );

pnt_done();
