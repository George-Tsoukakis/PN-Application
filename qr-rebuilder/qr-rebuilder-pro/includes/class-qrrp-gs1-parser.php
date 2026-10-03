<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GS1 parser/builder for the pharmaceutical fields used by QR Rebuilder Pro:
 * AI 01 (GTIN), AI 21 (Serial), AI 10 (LOT) and AI 17 (Expiry).
 *
 * Analysis is staged, strictest first:
 *  1. Normalisation removes only transport noise (BOM, scanner suffix,
 *     "GS1:" label, whitespace, symbology identifier, visible GS, HRI).
 *  2. Pass 1 searches with only the four rebuilt AIs.
 *  3. Pass 2, only when pass 1 finds no complete reading, also recognises
 *     the other GS1 AIs. They are not editable fields, but they are carried
 *     into the rebuilt code when passthrough_for_reading() proves them from
 *     the original scan; a scan whose extras cannot be proven is refused by
 *     the AJAX layer (extras_unprovable), never rebuilt without them.
 *  4. Anything else falls back to a linear best-effort read that is always
 *     low confidence and always requires confirmation.
 *
 * GS1 data is never repaired silently: every boundary that was computed
 * rather than read from a separator is reported.
 */
final class QRRP_GS1_Parser {

	const GROUP_SEPARATOR = "\x1D";

	/*
	 * Η σειρά που παράγει το εργαλείο. Με το 21 τελευταίο χρειάζεται ένας μόνο
	 * GS ανάμεσα στα δύο πεδία μεταβλητού μήκους, που συχνά δίνει μικρότερο
	 * σύμβολο DataMatrix. Δεν είναι απαίτηση της GS1 και δεν αφορά την ανάγνωση:
	 * ο parser δέχεται κάθε σειρά. Η canonicalRawCandidates() του qrrp-app.js
	 * την καθρεφτίζει, άρα μια αλλαγή εδώ θέλει πρώτα αλλαγή στον client.
	 */
	const CANONICAL_ORDER = array( '01', '17', '10', '21' );

	/**
	 * Ίδιο όριο με την qrrp_max_raw_bytes(). Αντίγραφο, επειδή ο parser δεν
	 * καλεί global συναρτήσεις του plugin· οι τιμές πρέπει να συμφωνούν.
	 */
	private const MAX_RAW_BYTES = 4096;
	private const MAX_SOLUTIONS = 2000;
	public const MAX_ADMISSIBLE_READINGS = 64;

	/**
	 * Ταβάνι κόμβων της backtrack() ανά κλήση collect_solutions().
	 *
	 * Το MAX_SOLUTIONS περιορίζει μόνο τις λύσεις που κρατάμε· μια κατασκευασμένη
	 * είσοδος (π.χ. «90AB91AB92AB…») μπορεί να εξερευνά εκθετικά πολλούς κόμβους
	 * χωρίς να βγάζει καμία λύση. Πραγματικές συσκευασίες κοστίζουν δεκάδες
	 * κόμβους και οι δυσκολότερες ρεαλιστικές περίπου 2.200. Η εξάντληση
	 * σημαίνεται ως 'truncated': χαμηλή εμπιστοσύνη, καμία αυτόματη αποδοχή.
	 */
	private const MAX_SEARCH_NODES = 20000;

	/**
	 * Ταβάνι δοκιμών μήκους (εσωτερικός βρόχος της backtrack()) ανά κλήση
	 * collect_solutions(), από την 2.15.2. Ρεαλιστικές σαρώσεις: λίγες εκατοντάδες
	 * δοκιμές. Το όριο καλύπτει ~2.200 κόμβους × 90 μήκη (η δυσκολότερη
	 * ρεαλιστική περίπτωση του MAX_SEARCH_NODES), ενώ μια κατασκευασμένη είσοδος
	 * 4 KB έφτανε ~1.800.000.
	 */
	private const MAX_SEARCH_WORK = 200000;

	/**
	 * 2.15.3: έως πόσα «Σ» (S ή W) απαριθμούνται όλοι οι συνδυασμοί (2^n
	 * αναλύσεις). Πάνω από αυτό μόνο «όλα W».
	 */
	private const MAX_SIGMA_ENUMERATED = 4;

	/** Πόσα βήματα αναζήτησης απομένουν στην τρέχουσα collect_solutions(). */
	private static $search_nodes_left = 0;

	/** Πόσες δοκιμές μήκους απομένουν στην τρέχουσα collect_solutions(). */
	private static $search_work_left = 0;

	/** True όταν η τρέχουσα αναζήτηση σταμάτησε επειδή τελείωσε το ταβάνι. */
	private static $search_budget_exhausted = false;

	/**
	 * 2.15.3: κοινός προϋπολογισμός όλων των παραλλαγών «Σ» μιας κλήσης (null =
	 * ανενεργός). Όλες μαζί δεν ξεπερνούν μία αναζήτηση (MAX_SEARCH_NODES /
	 * MAX_SEARCH_WORK), άρα η κλήση κοστίζει έως ~2× μιας χωρίς «Σ».
	 */
	private static $variant_pool = null;
	private const REQUIRED_FIELDS = array( 'PC', 'SN', 'LOT', 'EXP' );

	/** Symbology identifiers που δηλώνουν GS1 δεδομένα (DataMatrix, QR, GS1-128, DataBar, DotCode). */
	private const GS1_SYMBOLOGY_IDENTIFIERS = array( ']d2', ']Q3', ']C1', ']e0', ']J1' );

	/** Ορατές μορφές του Group Separator (εκτός charset 82, άρα χωρίς σύγκρουση με τιμές). */
	private const VISIBLE_GS_PATTERN = '\{GS\}|\[GS\]|\\\\x1[dD]|\\\\u001[dD]|␝';

	/**
	 * Τα δύο πρώτα ψηφία των AI προκαθορισμένου μήκους (GS1 General
	 * Specifications, Figure 7.8.5-2). Μόνο μετά από αυτά παραλείπεται το
	 * FNC1/GS· κάθε άλλο AI, ακόμη και σταθερού μήκους (π.χ. 7003, 422, 8005),
	 * χρειάζεται διαχωριστή όταν δεν είναι τελευταίο.
	 */
	private const PREDEFINED_LENGTH_PREFIXES = array(
		'00', '01', '02', '03', '04', '11', '12', '13', '14', '15', '16',
		'17', '18', '19', '20', '31', '32', '33', '34', '35', '36', '41',
	);
	private const GS1_CHARSET_PATTERN = '/^[!"%&\'()*+,\-.\/0-9:;<=>?A-Z_a-z]*$/';

	public static function default_ai_table() {
		return array(
			'01' => array(
				'label'  => 'PC',
				'name'   => 'Product Code / GTIN',
				'length' => 14,
				'type'   => 'numeric',
			),
			'10' => array(
				'label'          => 'LOT',
				'name'           => 'Batch / Lot Number',
				'length'         => 0,
				'max'            => 20,
				'typical_length' => self::option_length( 'qrrp_lot_fallback_length', 6 ),
				'type'           => 'alnum',
			),
			'17' => array(
				'label'  => 'EXP',
				'name'   => 'Expiration Date (YYMMDD)',
				'length' => 6,
				'type'   => 'date',
			),
			'21' => array(
				'label'          => 'SN',
				'name'           => 'Serial Number',
				'length'         => 0,
				'max'            => 20,
				'typical_length' => self::option_length( 'qrrp_sn_fallback_length', 8 ),
				'type'           => 'alnum',
			),
		);
	}

	/*
	 * Πίνακες των επιπλέον AI (GS1 Barcode Syntax Dictionary). Αναγνωρίζονται
	 * και παρακάμπτονται, δεν ξαναχτίζονται ως πεδία.
	 *
	 * AI => array( μήκος ή μέγιστο, τύπος[, υποχρεωτικά αρχικά ψηφία] ).
	 * Ο τύπος δεν είναι διακοσμητικός: το AI 242 είναι N..6, άρα «242ABC» δεν
	 * είναι έγκυρο GS1 και δεν πρέπει να διαβάζεται ως καθαρή σάρωση.
	 */
	private const EXTRA_FIXED_AIS = array(
		'00'   => array( 18, 'numeric' ), '02'   => array( 14, 'numeric' ), '03'   => array( 14, 'numeric' ),
		'11'   => array( 6, 'date' ),     '12'   => array( 6, 'date' ),     '13'   => array( 6, 'date' ),
		'15'   => array( 6, 'date' ),     '16'   => array( 6, 'date' ),     '20'   => array( 2, 'numeric' ),
		'410'  => array( 13, 'numeric' ), '411'  => array( 13, 'numeric' ), '412'  => array( 13, 'numeric' ),
		'413'  => array( 13, 'numeric' ), '414'  => array( 13, 'numeric' ), '415'  => array( 13, 'numeric' ),
		'416'  => array( 13, 'numeric' ), '417'  => array( 13, 'numeric' ), '422'  => array( 3, 'numeric' ),
		'424'  => array( 3, 'numeric' ),  '426'  => array( 3, 'numeric' ),  '7040' => array( 4, 'alnum', 1 ),
		'8005' => array( 6, 'numeric' ),  '8006' => array( 18, 'numeric' ), '8017' => array( 18, 'numeric' ),
		'8018' => array( 18, 'numeric' ), '8026' => array( 18, 'numeric' ), '7006' => array( 6, 'numeric' ),
		'7003' => array( 10, 'numeric' ), '7001' => array( 13, 'numeric' ), '402'  => array( 17, 'numeric' ),
		'4307' => array( 2, 'alnum' ),    '4309' => array( 20, 'numeric' ), '4317' => array( 2, 'alnum' ),
		'4321' => array( 1, 'numeric' ),  '4322' => array( 1, 'numeric' ),  '4323' => array( 1, 'numeric' ),
		'4324' => array( 10, 'numeric' ), '4325' => array( 10, 'numeric' ), '4326' => array( 6, 'numeric' ),
		'7241' => array( 2, 'numeric' ),  '7250' => array( 8, 'numeric' ),  '7251' => array( 12, 'numeric' ),
		'7252' => array( 1, 'numeric' ),  '7258' => array( 3, 'alnum' ),    '8001' => array( 14, 'numeric' ),
		'8040' => array( 15, 'numeric' ), '8041' => array( 15, 'numeric' ), '8042' => array( 32, 'numeric' ),
		'8111' => array( 4, 'numeric' ),
	);

	/* Μεταβλητού μήκους: το πρώτο στοιχείο είναι το μέγιστο μήκος. */
	private const EXTRA_VARIABLE_AIS = array(
		'22'   => array( 20, 'alnum' ),     '235'  => array( 28, 'alnum' ),     '240'  => array( 30, 'alnum' ),
		'241'  => array( 30, 'alnum' ),     '242'  => array( 6, 'numeric' ),    '243'  => array( 20, 'alnum' ),
		'250'  => array( 30, 'alnum' ),     '251'  => array( 30, 'alnum' ),     '253'  => array( 30, 'alnum', 13 ),
		'254'  => array( 20, 'alnum' ),     '255'  => array( 25, 'numeric' ),   '30'   => array( 8, 'numeric' ),
		'37'   => array( 8, 'numeric' ),    '400'  => array( 30, 'alnum' ),     '401'  => array( 30, 'alnum' ),
		'403'  => array( 30, 'alnum' ),     '420'  => array( 20, 'alnum' ),     '421'  => array( 12, 'alnum', 3 ),
		'423'  => array( 15, 'numeric' ),   '425'  => array( 15, 'numeric' ),   '427'  => array( 3, 'alnum' ),
		'7002' => array( 30, 'alnum' ),     '7004' => array( 4, 'numeric' ),    '7005' => array( 12, 'alnum' ),
		'7007' => array( 12, 'numeric' ),   '7008' => array( 3, 'alnum' ),      '7009' => array( 10, 'alnum' ),
		'7010' => array( 2, 'alnum' ),      '710'  => array( 20, 'alnum' ),     '711'  => array( 20, 'alnum' ),
		'712'  => array( 20, 'alnum' ),     '713'  => array( 20, 'alnum' ),     '714'  => array( 20, 'alnum' ),
		'715'  => array( 20, 'alnum' ),     '716'  => array( 20, 'alnum' ),     '717'  => array( 20, 'alnum' ),
		'8003' => array( 30, 'alnum', 14 ), '8004' => array( 30, 'alnum' ),     '8008' => array( 12, 'numeric' ),
		'8010' => array( 30, 'alnum' ),     '8011' => array( 12, 'numeric' ),   '8012' => array( 20, 'alnum' ),
		'8013' => array( 25, 'alnum' ),     '8019' => array( 10, 'numeric' ),   '8020' => array( 25, 'alnum' ),
		'90'   => array( 30, 'alnum' ),     '91'   => array( 90, 'alnum' ),     '92'   => array( 90, 'alnum' ),
		'93'   => array( 90, 'alnum' ),     '94'   => array( 90, 'alnum' ),     '95'   => array( 90, 'alnum' ),
		'96'   => array( 90, 'alnum' ),     '97'   => array( 90, 'alnum' ),     '98'   => array( 90, 'alnum' ),
		'99'   => array( 90, 'alnum' ),     '4300' => array( 35, 'alnum' ),     '4301' => array( 35, 'alnum' ),
		'4302' => array( 70, 'alnum' ),     '4303' => array( 70, 'alnum' ),     '4304' => array( 70, 'alnum' ),
		'4305' => array( 70, 'alnum' ),     '4306' => array( 70, 'alnum' ),     '4308' => array( 30, 'alnum' ),
		'4310' => array( 35, 'alnum' ),     '4311' => array( 35, 'alnum' ),     '4312' => array( 70, 'alnum' ),
		'4313' => array( 70, 'alnum' ),     '4314' => array( 70, 'alnum' ),     '4315' => array( 70, 'alnum' ),
		'4316' => array( 70, 'alnum' ),     '4318' => array( 20, 'alnum' ),     '4319' => array( 30, 'alnum' ),
		'4320' => array( 35, 'alnum' ),     '4330' => array( 7, 'alnum', 6 ),   '4331' => array( 7, 'alnum', 6 ),
		'4332' => array( 7, 'alnum', 6 ),   '4333' => array( 7, 'alnum', 6 ),   '7011' => array( 10, 'numeric' ),
		'7020' => array( 20, 'alnum' ),     '7021' => array( 20, 'alnum' ),     '7022' => array( 20, 'alnum' ),
		'7023' => array( 30, 'alnum' ),     '7041' => array( 4, 'alnum' ),      '7240' => array( 20, 'alnum' ),
		'7242' => array( 25, 'alnum' ),     '7253' => array( 40, 'alnum' ),     '7254' => array( 40, 'alnum' ),
		'7255' => array( 10, 'alnum' ),     '7256' => array( 90, 'alnum' ),     '7257' => array( 70, 'alnum' ),
		'7259' => array( 40, 'alnum' ),     '8002' => array( 20, 'alnum' ),     '8007' => array( 34, 'alnum' ),
		'8009' => array( 50, 'alnum' ),     '8014' => array( 25, 'alnum' ),     '8030' => array( 90, 'alnum' ),
		'8043' => array( 20, 'numeric' ),   '8110' => array( 70, 'alnum' ),     '8112' => array( 70, 'alnum' ),
		'8200' => array( 70, 'alnum' ),
	);

	/*
	 * AI μέτρησης (31nn-36nn): το τελευταίο ψηφίο είναι η θέση υποδιαστολής
	 * και η GS1 ορίζει μόνο 0-5. Κάθε τιμή εδώ είναι η αρχή μιας δεκάδας
	 * (n0..n5)· οι δεκάδες που λείπουν (317n, 338n, …) δεν έχουν ανατεθεί.
	 */
	private const MEASURE_AI_STARTS = array(
		3100, 3110, 3120, 3130, 3140, 3150, 3160, 3200, 3210, 3220, 3230, 3240, 3250, 3260,
		3270, 3280, 3290, 3300, 3310, 3320, 3330, 3340, 3350, 3360, 3370, 3400, 3410, 3420,
		3430, 3440, 3450, 3460, 3470, 3480, 3490, 3500, 3510, 3520, 3530, 3540, 3550, 3560,
		3570, 3600, 3610, 3620, 3630, 3640, 3650, 3660, 3670, 3680, 3690,
	);

	/* 39nn: array( πρώτο, τελευταίο, σταθερό μήκος ή 0, μέγιστο ). */
	private const AMOUNT_AI_SPANS = array(
		array( 3900, 3909, 0, 15 ), /* ποσό πληρωμής */
		array( 3910, 3919, 0, 18 ), /* ποσό + νόμισμα ISO */
		array( 3920, 3929, 0, 15 ), /* τιμή */
		array( 3930, 3939, 0, 18 ), /* τιμή + νόμισμα ISO */
		array( 3940, 3943, 4, 4 ),  /* ποσοστό έκπτωσης */
		array( 3950, 3955, 6, 6 ),  /* τιμή ανά μονάδα μέτρησης */
	);

	/*
	 * Δομικοί περιορισμοί πέρα από τύπο και μέγιστο μήκος: 'min' (υποχρεωτικά
	 * συστατικά), 'lengths' (μόνο αυτά τα μήκη), 'validator' (ημερομηνία/ώρα).
	 * Π.χ. το AI 255 είναι GCN 13 ψηφίων + προαιρετικό serial, το 423 σειρά
	 * τριψήφιων κωδικών χώρας, το 7007 μία ή δύο πραγματικές ημερομηνίες.
	 */
	private const EXTRA_AI_STRUCTURE = array(
		'253'  => array( 'min' => 13 ),
		'255'  => array( 'min' => 13 ),
		'421'  => array( 'min' => 4 ),
		'423'  => array( 'lengths' => array( 3, 6, 9, 12, 15 ) ),
		'425'  => array( 'lengths' => array( 3, 6, 9, 12, 15 ) ),
		'7003' => array( 'validator' => 'yymmdd_hhmi' ),
		'7006' => array( 'validator' => 'yymmdd_groups' ),
		'7007' => array( 'lengths' => array( 6, 12 ), 'validator' => 'yymmdd_groups' ),
		'8003' => array( 'min' => 14 ),
		'8008' => array( 'lengths' => array( 8, 10, 12 ), 'validator' => 'yymmdd_time' ),
		'4324' => array( 'validator' => 'yymmd0_hhmi' ),
		'4325' => array( 'validator' => 'yymmd0_hhmi' ),
		'4326' => array( 'validator' => 'yymmdd_groups' ),
		'4330' => array( 'min' => 6, 'lengths' => array( 6, 7 ) ),
		'4331' => array( 'min' => 6, 'lengths' => array( 6, 7 ) ),
		'4332' => array( 'min' => 6, 'lengths' => array( 6, 7 ) ),
		'4333' => array( 'min' => 6, 'lengths' => array( 6, 7 ) ),
		'7011' => array( 'lengths' => array( 6, 10 ), 'validator' => 'yymmdd_opt_time' ),
		'7250' => array( 'validator' => 'yyyymmdd_opt_time' ),
		'7251' => array( 'validator' => 'yyyymmdd_opt_time' ),
		'8043' => array( 'min' => 18 ),
	);

	/**
	 * Άλλα GS1 AI που μπορεί νόμιμα να υπάρχουν δίπλα στα PC/SN/LOT/EXP. Δεν
	 * εξάγονται ως πεδία, αλλά ο parser πρέπει να τα αναγνωρίζει και να τα
	 * παρακάμπτει, αλλιώς μια έγκυρη συσκευασία με π.χ. AI 240 ή εθνικό κωδικό
	 * αποζημίωσης (710-717) δεν διαβάζεται.
	 *
	 * 'length' > 0: σταθερό μήκος· 'length' = 0: μεταβλητό έως 'max'. Το αν
	 * ακολουθεί FNC1 το αποφασίζει η is_predefined_length_ai(), όχι το μήκος.
	 *
	 * @return array AI => ορισμός.
	 */
	private static function extra_ai_table() {
		static $table = null;

		if ( null !== $table ) {
			return $table;
		}

		$variable = self::EXTRA_VARIABLE_AIS;

		/* PROCESSOR # n: N3 (χώρα) + X..27. */
		for ( $code = 7030; $code <= 7039; $code++ ) {
			$variable[ (string) $code ] = array( 30, 'alnum', 3 );
		}

		/* CERT # n: X2 (σχήμα) + X..28. */
		for ( $code = 7230; $code <= 7239; $code++ ) {
			$variable[ (string) $code ] = array( 30, 'alnum' );
		}

		$table = array();

		foreach ( array( self::EXTRA_FIXED_AIS, $variable ) as $index => $group ) {
			foreach ( $group as $ai => $definition ) {
				$entry = array(
					'label'  => '',
					'name'   => 'AI ' . $ai,
					'length' => 0 === $index ? (int) $definition[0] : 0,
					'max'    => (int) $definition[0],
					'type'   => $definition[1],
				);

				if ( isset( $definition[2] ) ) {
					$entry['numeric_prefix'] = (int) $definition[2];
				}

				$table[ (string) $ai ] = $entry;
			}
		}

		foreach ( self::MEASURE_AI_STARTS as $start ) {
			for ( $code = $start; $code <= $start + 5; $code++ ) {
				$table[ (string) $code ] = array(
					'label'  => '',
					'name'   => 'AI ' . $code,
					'length' => 6,
					'max'    => 6,
					'type'   => 'numeric',
				);
			}
		}

		foreach ( self::AMOUNT_AI_SPANS as $span ) {
			list( $first, $last, $length, $max ) = $span;

			for ( $code = $first; $code <= $last; $code++ ) {
				$table[ (string) $code ] = array(
					'label'  => '',
					'name'   => 'AI ' . $code,
					'length' => $length,
					'max'    => $max,
					'type'   => 'numeric',
				);
			}
		}

		$structure = self::EXTRA_AI_STRUCTURE;

		/* PROCESSOR # n: τουλάχιστον 4 χαρακτήρες· CERT # n: τουλάχιστον 3. */
		foreach ( range( 7030, 7039 ) as $code ) {
			$structure[ (string) $code ] = array( 'min' => 4 );
		}

		foreach ( range( 7230, 7239 ) as $code ) {
			$structure[ (string) $code ] = array( 'min' => 3 );
		}

		/* Οι οικογένειες με νόμισμα ISO έχουν υποχρεωτικό τριψήφιο κωδικό. */
		foreach ( array_merge( range( 3910, 3919 ), range( 3930, 3939 ) ) as $code ) {
			$structure[ (string) $code ] = array( 'min' => 4 );
		}

		foreach ( $structure as $ai => $rules ) {
			if ( isset( $table[ $ai ] ) ) {
				$table[ $ai ] = array_merge( $table[ $ai ], $rules );
			}
		}

		return $table;
	}

	/**
	 * True όταν το AI ανήκει στον πίνακα προκαθορισμένου μήκους της GS1, δηλαδή
	 * δεν ακολουθείται από FNC1/GS. Το «σταθερό μήκος» μόνο του δεν αρκεί.
	 *
	 * @param string $ai Application Identifier (2-4 ψηφία).
	 * @return bool
	 */
	public static function is_predefined_length_ai( $ai ) {
		$ai = is_scalar( $ai ) ? (string) $ai : '';

		return strlen( $ai ) >= 2
			&& ctype_digit( $ai )
			&& in_array( substr( $ai, 0, 2 ), self::PREDEFINED_LENGTH_PREFIXES, true );
	}

	/** Target AIs plus the recognise-and-skip ones. */
	private static function full_ai_table() {
		return self::get_ai_table() + self::extra_ai_table();
	}

	/**
	 * Read the Application Identifier at $pos. GS1 AIs are prefix-free, so the
	 * longest match is the only possible one; we still probe 4 -> 2 digits so a
	 * four-digit AI is never mistaken for a two-digit one.
	 *
	 * @return array|null array( ai, definition ) or null when nothing matches.
	 */
	private static function match_ai( $raw, $pos, $ai_table ) {
		for ( $length = 4; $length >= 2; $length-- ) {
			$candidate = substr( $raw, $pos, $length );

			if ( strlen( $candidate ) !== $length || ! ctype_digit( $candidate ) ) {
				continue;
			}

			if ( isset( $ai_table[ $candidate ] ) ) {
				return array( $candidate, $ai_table[ $candidate ] );
			}
		}

		return null;
	}

	/** Where a matched AI stores its value: a real field, or an ignored extra. */
	private static function ai_storage_key( $ai, $def ) {
		return ( isset( $def['label'] ) && '' !== $def['label'] ) ? $def['label'] : '__ai_' . $ai;
	}

	/** Human label used when reporting an inferred boundary. */
	private static function ai_report_label( $ai, $def ) {
		return ( isset( $def['label'] ) && '' !== $def['label'] ) ? $def['label'] : 'AI ' . $ai;
	}

	/**
	 * Αναλύει ένα σαρωμένο GS1 payload στα πεδία PC/SN/LOT/EXP.
	 *
	 * @param mixed $raw Το payload όπως ήρθε από τον σαρωτή ή το πρόχειρο.
	 * @return array Πεδία, προειδοποιήσεις και σημαίες εμπιστοσύνης.
	 */
	public static function parse( $raw ) {
		if ( ! is_scalar( $raw ) ) {
			return self::empty_result( __( 'Μη έγκυρα δεδομένα QR.', 'qr-rebuilder-pro' ) );
		}

		$raw = (string) $raw;

		if ( '' === $raw ) {
			return self::empty_result( __( 'Δεν δόθηκαν δεδομένα QR.', 'qr-rebuilder-pro' ) );
		}

		if ( strlen( $raw ) > self::MAX_RAW_BYTES ) {
			return self::empty_result( __( 'Τα δεδομένα QR υπερβαίνουν το επιτρεπτό μέγεθος.', 'qr-rebuilder-pro' ) );
		}

		$ai_table      = self::get_ai_table();
		$normalization = self::normalize_scan_input( $raw, $ai_table );

		if ( '' === $normalization['raw'] ) {
			return self::result_with_context(
				self::empty_result( __( 'Δεν έμειναν αναγνώσιμα GS1 δεδομένα μετά την κανονικοποίηση της σάρωσης.', 'qr-rebuilder-pro' ) ),
				$normalization['warnings'],
				$normalization['meta']
			);
		}

		if ( isset( $normalization['meta']['hri_error'] ) ) {
			return self::result_with_context(
				self::empty_result(
					sprintf(
						/* translators: %s: why the parenthesised HRI text was rejected. */
						__( 'Η μορφή HRI με παρενθέσεις δεν αναγνωρίστηκε: %s. Χρησιμοποιήστε σάρωση ή raw GS1 δεδομένα.', 'qr-rebuilder-pro' ),
						(string) $normalization['meta']['hri_error']
					)
				),
				$normalization['warnings'],
				$normalization['meta']
			);
		}

		$result = self::analyse_payload(
			$normalization['raw'],
			$ai_table,
			$normalization['warnings'],
			$normalization['meta']
		);

		if ( ! empty( $normalization['meta']['literal_gs_text'] ) ) {
			$result = self::with_literal_gs_alternative( $result, $raw, $ai_table );
		}

		if ( ! empty( $normalization['meta']['ambiguous_sigma'] ) ) {
			$result = self::with_sigma_alternatives( $result, $raw, $ai_table, $normalization );
		}

		if ( ! empty( $normalization['meta']['hri_split_alternatives'] ) ) {
			$result = self::with_hri_split_alternatives( $result, $normalization['meta'] );
		}

		return $result;
	}

	/**
	 * Η κύρια ανάλυση ενός ήδη κανονικοποιημένου payload.
	 *
	 * @param string $raw      Κανονικοποιημένο GS1 element string.
	 * @param array  $ai_table Ο πίνακας των τεσσάρων AI.
	 * @param array  $warnings Προειδοποιήσεις της κανονικοποίησης.
	 * @param array  $meta     Μεταδεδομένα της κανονικοποίησης.
	 * @return array Το δημόσιο αποτέλεσμα της parse().
	 */
	private static function analyse_payload( $raw, $ai_table, $warnings, $meta ) {
		$search          = self::search_readings( $raw, $ai_table );
		$solutions       = $search['solutions'];
		$truncated       = $search['truncated'];
		$extra_ais_found = $search['extra_ais_found'];

		if ( $truncated ) {
			$warnings[] = __( 'Η σάρωση δημιούργησε πάρα πολλές πιθανές ερμηνείες. Η ανάλυση περιορίστηκε και χρειάζεται χειροκίνητο έλεγχο.', 'qr-rebuilder-pro' );
		}

		if ( empty( $solutions ) ) {
			$fallback                     = self::best_effort_parse( $raw, self::full_ai_table() );
			$fallback['search_truncated'] = $truncated;

			return self::result_with_context( $fallback, $warnings, $meta );
		}

		if ( $extra_ais_found ) {
			$warnings[] = __( 'Ο κωδικός περιείχε και άλλα GS1 πεδία εκτός των PC/SN/LOT/EXP. Δεν εμφανίζονται ως επεξεργάσιμα πεδία. Στον νέο κωδικό θα διατηρηθούν μόνο όσα επαληθευτούν με ασφάλεια από την αρχική πηγή.', 'qr-rebuilder-pro' );
		}

		$analysis             = self::assess_solutions( $solutions, $ai_table );
		$fields               = $analysis['best'];
		$inferred_boundaries  = isset( $fields['__inferred_boundaries'] ) ? (int) $fields['__inferred_boundaries'] : 0;
		$inferred_fields      = isset( $fields['__inferred_fields'] ) && is_array( $fields['__inferred_fields'] )
			? array_values( array_unique( array_filter( $fields['__inferred_fields'], 'is_string' ) ) )
			: array();
		$redundant_separators = isset( $fields['__redundant_separators'] ) ? (int) $fields['__redundant_separators'] : 0;

		self::strip_internal_fields( $fields );

		$chosen_signature = self::target_signature( $fields );

		$cross_check = ( $inferred_boundaries > 0 && ! $extra_ais_found && ! $truncated )
			? self::conflicting_extra_ai_readings( $raw, $fields, $inferred_boundaries )
			: array(
				'conflicts' => array(),
				'truncated' => false,
			);

		$extra_ai_conflicts    = $cross_check['conflicts'];
		$cross_check_truncated = ! empty( $cross_check['truncated'] );

		if ( $cross_check_truncated ) {
			$warnings[] = __( 'Η σάρωση έχει πάρα πολλές πιθανές ερμηνείες και δεν ήταν δυνατό να ελεγχθούν όλες αυτόματα. Ελέγξτε τα PC, SN, LOT και EXP με τη συσκευασία πριν συνεχίσετε.', 'qr-rebuilder-pro' );
		}

		$checks             = self::check_chosen_fields( $fields, $warnings, $meta );
		$extra_ai_ambiguous = ! empty( $extra_ai_conflicts );
		$needs_review       = $checks['layout_recovered'] || ! empty( $meta['literal_gs_text'] );

		/*
		 * 2.15.7: όριο συνάγεται ενώ ο κωδικός έχει ρητά όρια (Group Separator ή
		 * παρενθέσεις HRI). Ένας scanner στέλνει είτε όλους τους separators είτε
		 * κανέναν· μικτή είσοδος σημαίνει ότι η ανάγνωση σπάει τιμή που ο κωδικός
		 * είχε κλείσει και μπορεί να «δημιουργήσει» πεδίο που δεν υπάρχει
		 * (π.χ. 21AB17280331<GS>10LOT → SN «AB» + επινοημένο EXP). Ποτέ αυτόματα.
		 */
		$explicit_boundaries = $inferred_boundaries > 0
			&& (
				self::has_terminating_separator( $raw )
				|| ( isset( $meta['input_mode'] ) && 'parenthesized_hri' === $meta['input_mode'] )
			);

		if ( $explicit_boundaries ) {
			$needs_review = true;
			$warnings[]   = __( 'Ο κωδικός είχε ρητά όρια πεδίων (Group Separator ή παρενθέσεις), αλλά για να βρεθούν όλα τα PC/SN/LOT/EXP χρειάστηκε να χωριστεί τιμή που ο κωδικός είχε ήδη κλείσει. Κάποιο πεδίο μπορεί να μην υπάρχει στον αρχικό κωδικό. Ελέγξτε όλα τα πεδία με τη συσκευασία πριν συνεχίσετε.', 'qr-rebuilder-pro' );
		}

		/*
		 * 2.16.0: χωρίς separators, μια σάρωση που δεν έχει κάποιο πεδίο (ή που
		 * κόπηκε) δίνει κι αυτή «πλήρη» ανάγνωση: το «10»/«17» μέσα στο SN
		 * διαβάζεται ως νέο AI (21AB10CD → SN «AB» + επινοημένο LOT «CD»). Από το
		 * string μόνο δεν ξεχωρίζει από κανονικό κωδικό· το σημάδι είναι το
		 * απίθανα κοντό SN/LOT. Τότε ποτέ αυτόματα.
		 */
		$short_inferred = $inferred_boundaries > 0
			? self::short_variable_fields( $fields )
			: array();

		/*
		 * Αυτόματη αποδοχή συναγόμενων ορίων μόνο όταν η ανάγνωση είναι
		 * αποδεδειγμένη: μία πλήρης έγκυρη ερμηνεία, ολοκληρωμένη αναζήτηση,
		 * έγκυρο GTIN, όχι DD=00 που αλλάζει (2.15.3: μόνο στο παλιό μοντέλο)
		 * και τίποτα που θέλει ανθρώπινα μάτια.
		 */
		$safe_inference = $inferred_boundaries > 0
			&& ! $truncated
			&& ! $cross_check_truncated
			&& ! $analysis['ambiguous']
			&& ! $extra_ai_ambiguous
			&& $checks['all_required_present']
			&& $checks['gtin_valid']
			&& ! $checks['exp_day_needs_review']
			&& ! $needs_review;

		/*
		 * 2.16.1: η ίδια σάρωση διαβάζεται ολόκληρη και ως κωδικός χωρίς κάποιο
		 * πεδίο (21ABCD10EFGH = SN «ABCD» + LOT «EFGH» ή μόνο SN «ABCD10EFGH»).
		 * Από το string δεν ξεχωρίζουν, και το κοντό μήκος δεν πιάνει το
		 * 21ABCD10EFGH. Μια συσκευασία χωρίς LOT δεν παίρνει ποτέ αυτόματα
		 * επινοημένο LOT.
		 */
		$absent_readings = $inferred_boundaries > 0
			? self::field_absent_readings( $solutions, $fields )
			: array();

		if ( $safe_inference && array() !== $absent_readings ) {
			$safe_inference = false;
			$warnings[]     = sprintf(
				/* translators: %s: the competing reading(s), e.g. 'SN «ABCD10EFGH» χωρίς LOT'. */
				__( 'Ο κωδικός δεν περιείχε Group Separator και διαβάζεται και ως κωδικός χωρίς κάποιο πεδίο: %s. Αν η συσκευασία δεν έχει αυτό το πεδίο, ο parser το δημιούργησε κόβοντας άλλη τιμή. Ελέγξτε όλα τα πεδία με τη συσκευασία πριν συνεχίσετε.', 'qr-rebuilder-pro' ),
				implode( ' / ', $absent_readings )
			);
		}

		/* Το μήνυμα μόνο όταν αυτό ήταν ο λόγος· αλλιώς ζητείται ήδη έλεγχος. */
		if ( $safe_inference && array() !== $short_inferred ) {
			$safe_inference = false;
			$warnings[]     = sprintf(
				/* translators: %s: comma-separated field values, e.g. SN «AB», LOT «CD». */
				__( 'Ο κωδικός δεν περιείχε Group Separator και η πιθανότερη ανάγνωση δίνει ασυνήθιστα κοντές τιμές: %s. Αν η συσκευασία δεν έχει κάποιο από αυτά τα πεδία, ο parser μπορεί να το δημιούργησε κόβοντας άλλη τιμή. Ελέγξτε όλα τα πεδία με τη συσκευασία πριν συνεχίσετε.', 'qr-rebuilder-pro' ),
				implode( ', ', $short_inferred )
			);
		}

		$requires_confirmation = $truncated
			|| $cross_check_truncated
			|| $needs_review
			|| $analysis['ambiguous']
			|| $extra_ai_ambiguous
			|| ( $inferred_boundaries > 0 && ! $safe_inference )
			|| ! $checks['all_required_present']
			|| ! $checks['gtin_valid']
			|| $checks['exp_day_needs_review'];

		if ( $extra_ai_ambiguous ) {
			self::report_extra_ai_conflicts( $extra_ai_conflicts, $chosen_signature, $analysis, $warnings );
		}

		if ( $safe_inference && (bool) apply_filters( 'qrrp_notify_auto_inference', true ) ) {
			$warnings[] = self::auto_inference_notice( $fields, $inferred_fields );
		}

		if ( $inferred_boundaries > 0 && ! $safe_inference ) {
			self::add_inferred_boundary_warning( $fields, $inferred_fields, $inferred_boundaries, $warnings );
		}

		if ( $redundant_separators > 0 ) {
			$warnings[] = __( 'Αγνοήθηκε περιττός Group Separator μετά από fixed-length πεδίο.', 'qr-rebuilder-pro' );
		}

		if ( $analysis['ambiguous'] ) {
			$warnings[] = self::ambiguity_warning( $analysis, $inferred_fields );
		}

		$confidence = $truncated ? 'low' : $analysis['confidence'];

		if ( ! $checks['all_required_present'] || ! $checks['gtin_valid'] ) {
			$confidence = 'low';
		} elseif ( $inferred_boundaries > 0 && 'high' === $confidence ) {
			$confidence = 'medium';
		}

		return array(
			'fields'                  => $fields,
			'warnings'                => $warnings,
			'confidence'              => $confidence,
			'ambiguous'               => $analysis['ambiguous'] || $extra_ai_ambiguous,
			'alternative_count'       => $extra_ai_ambiguous
				? max( 2, (int) $analysis['alternative_count'] + count( $extra_ai_conflicts ) )
				: $analysis['alternative_count'],
			'inferred_boundaries'     => $inferred_boundaries,
			'inferred_fields'         => $inferred_fields,
			'inference_used'          => $inferred_boundaries > 0,
			'inference_auto_accepted' => $safe_inference,
			'requires_confirmation'   => $requires_confirmation,
			'normalization'           => $meta,
			'search_truncated'        => $truncated,
			'cross_check_truncated'   => $cross_check_truncated,
			'exp_day_unspecified'     => $checks['exp_day_was_zero'],
			'exp_in_past'             => $checks['exp_in_past'],
			'extra_ais_present'       => $extra_ais_found || ! empty( $meta['hri_extra_ais'] ),
			'contested_fields'        => isset( $analysis['contested_fields'] )
				? self::contested_fields_for_display( (array) $analysis['contested_fields'] )
				: array(),
		);
	}

	/**
	 * Πέρασμα 1 με τα τέσσερα AI· πέρασμα 2 με τον πλήρη πίνακα μόνο όταν το
	 * πρώτο δεν δίνει πλήρη ανάγνωση. Η σημαία truncated του περάσματος 2
	 * διατηρείται ακόμη κι όταν κρατιούνται οι λύσεις του περάσματος 1.
	 *
	 * @return array { solutions, truncated, extra_ais_found }
	 */
	private static function search_readings( $raw, $ai_table ) {
		$pass               = self::collect_solutions( $raw, $ai_table );
		$extra_ais_found    = false;
		$extended_truncated = false;

		if ( ! self::has_complete_solution( $pass['solutions'] ) ) {
			$extended = self::collect_solutions( $raw, self::full_ai_table() );

			if ( self::has_complete_solution( $extended['solutions'] ) ) {
				$pass            = $extended;
				$extra_ais_found = true;
			} else {
				$extended_truncated = ! empty( $extended['truncated'] );
			}
		}

		return array(
			'solutions'       => $pass['solutions'],
			'truncated'       => $pass['truncated'] || $extended_truncated,
			'extra_ais_found' => $extra_ais_found,
		);
	}

	/**
	 * Έλεγχοι της επιλεγμένης ανάγνωσης: μορφή EXP, GTIN, πεδία που λείπουν,
	 * ύποπτοι χαρακτήρες διάταξης, ληγμένη ημερομηνία. Γράφει τις
	 * προειδοποιήσεις με σταθερή σειρά και μετατρέπει το EXP σε ISO.
	 *
	 * @return array Σημαίες: exp_day_was_zero, all_required_present, gtin_valid,
	 *               layout_recovered, exp_in_past.
	 */
	private static function check_chosen_fields( &$fields, &$warnings, $meta ) {
		/* Το DD=00 (τέλος μήνα) διαβάζεται ως έχει. */
		$exp_day_was_zero = isset( $fields['EXP'] )
			&& is_scalar( $fields['EXP'] )
			&& (bool) preg_match( '/^\d{4}00$/', (string) $fields['EXP'] );

		/*
		 * 2.15.3: το «00» διατηρείται στον νέο κωδικό, άρα τίποτα δεν επινοείται
		 * και δεν χρειάζεται επιβεβαίωση. Μόνο με το παλιό μοντέλο (φίλτρο
		 * qrrp_preserve_expiry_day_zero = false) προτείνεται ημέρα και ζητείται.
		 */
		$exp_day_needs_review = $exp_day_was_zero && ! self::preserve_expiry_day_zero();

		if ( isset( $fields['EXP'] ) ) {
			$fields['EXP'] = self::format_yymmdd( $fields['EXP'], $warnings );
		}

		$all_required_present = self::all_required_fields_present( $fields );
		$gtin_valid           = self::has_field_value( $fields, 'PC' )
			&& self::gtin_check_digit_is_valid( $fields['PC'] );

		if ( self::has_field_value( $fields, 'PC' ) && ! $gtin_valid ) {
			$warnings[] = __( 'Το PC (GTIN) απέτυχε τον έλεγχο ψηφίου ελέγχου GS1 — πιθανό σφάλμα σάρωσης. Ελέγξτε το πριν συνεχίσετε.', 'qr-rebuilder-pro' );
		}

		self::add_missing_warnings( $fields, $warnings );

		/*
		 * «;» ή «:» σε SN/LOT είναι νόμιμοι χαρακτήρες, αλλά συνήθως σημαίνουν
		 * το πλήκτρο Q σε ελληνική διάταξη. Δεν αλλάζουν αυτόματα.
		 */
		$suspicious_layout_chars = empty( $meta['layout_recovered'] )
			&& (
				( self::has_field_value( $fields, 'SN' ) && preg_match( '/[;:]/', (string) $fields['SN'] ) )
				|| ( self::has_field_value( $fields, 'LOT' ) && preg_match( '/[;:]/', (string) $fields['LOT'] ) )
			);

		if ( $suspicious_layout_chars ) {
			$warnings[] = __( 'ΠΡΟΣΟΧΗ — το Serial ή το LOT περιέχει «;» ή «:». Αν στη συσκευασία υπάρχει στη θέση τους «Q», το πληκτρολόγιο ήταν σε ελληνική διάταξη. Ελέγξτε τα με τη συσκευασία.', 'qr-rebuilder-pro' );
		}

		$layout_recovered = ! empty( $meta['layout_recovered'] )
			|| $suspicious_layout_chars
			|| ! empty( $meta['non_gs1_symbology'] );
		$exp_in_past      = self::exp_is_in_past( $fields );

		if ( $exp_in_past ) {
			$warnings[] = sprintf(
				/* translators: %s: expiry date in YYYY-MM-DD form. */
				__( 'Η ημερομηνία λήξης (%s) έχει ήδη παρέλθει. Ελέγξτε το προϊόν πριν συνεχίσετε.', 'qr-rebuilder-pro' ),
				(string) $fields['EXP']
			);
		}

		return array(
			'exp_day_was_zero'     => $exp_day_was_zero,
			'exp_day_needs_review' => $exp_day_needs_review,
			'all_required_present' => $all_required_present,
			'gtin_valid'           => $gtin_valid,
			'layout_recovered'     => $layout_recovered,
			'exp_in_past'          => $exp_in_past,
		);
	}

	/**
	 * Αναφέρει τις ανταγωνιστικές αναγνώσεις με επιπλέον AI και τις προσθέτει
	 * στο contested_fields μετά την επιλογή, ώστε να μην αλλάζει η προεπιλογή.
	 */
	private static function report_extra_ai_conflicts( array $conflicts, array $chosen_signature, array &$analysis, array &$warnings ) {
		$alternatives = array();

		foreach ( $conflicts as $conflict ) {
			$differences = array();

			foreach ( self::REQUIRED_FIELDS as $label ) {
				$mine  = isset( $chosen_signature[ $label ] ) ? $chosen_signature[ $label ] : '';
				$other = isset( $conflict[ $label ] ) ? $conflict[ $label ] : '';

				if ( $mine !== $other ) {
					$differences[] = sprintf( '%s «%s»', $label, $other );
				}
			}

			if ( ! empty( $differences ) ) {
				$alternatives[] = implode( ', ', $differences );
			}
		}

		if ( ! empty( $alternatives ) ) {
			$warnings[] = sprintf(
				/* translators: %s: the competing reading, e.g. 'SN «ABC»'. */
				__( 'Ο κωδικός δεν περιείχε Group Separator και υπάρχει και δεύτερη έγκυρη ανάγνωση, επειδή τμήμα της τιμής μπορεί να είναι άλλο GS1 πεδίο: %s. Ελέγξτε τα πεδία με τη συσκευασία πριν συνεχίσετε.', 'qr-rebuilder-pro' ),
				implode( ' / ', $alternatives )
			);
		}

		$analysis['contested_fields'] = self::merge_rival_signatures(
			isset( $analysis['contested_fields'] ) ? (array) $analysis['contested_fields'] : array(),
			$chosen_signature,
			$conflicts
		);
	}

	/** Ενημέρωση για τιμές που υπολογίστηκαν αντί να διαβαστούν από separator. */
	private static function auto_inference_notice( $fields, $inferred_fields ) {
		$resolved = array();

		foreach ( $inferred_fields as $label ) {
			$resolved[] = sprintf(
				'%s «%s»',
				$label,
				self::has_field_value( $fields, $label ) ? (string) $fields[ $label ] : ''
			);
		}

		return sprintf(
			/* translators: %s: comma-separated list of the inferred fields with their values, e.g. «SN «ABC4032», LOT «NK4032»». */
			__( 'Ο κωδικός δεν περιείχε Group Separator. Τα όρια υπολογίστηκαν ως η πιθανότερη ανάγνωση: %s. Ρίξτε μια ματιά στη συσκευασία για επιβεβαίωση.', 'qr-rebuilder-pro' ),
			implode( ', ', $resolved )
		);
	}

	/**
	 * Το «<GS>» ερμηνεύεται ως διαχωριστής, αλλά οι «<» και «>» ανήκουν στο
	 * charset 82, άρα μπορεί να είναι και μέρος της τιμής. Η ανάγνωση με το
	 * κείμενο ως τιμή προσφέρεται ως εναλλακτική και ζητείται πάντα επιβεβαίωση.
	 *
	 * @param array  $result   Αποτέλεσμα με το «<GS>» ως διαχωριστή.
	 * @param string $raw      Η αρχική είσοδος.
	 * @param array  $ai_table Ο πίνακας των τεσσάρων AI.
	 * @return array
	 */
	private static function with_literal_gs_alternative( array $result, $raw, $ai_table ) {
		$result['requires_confirmation']   = true;
		$result['inference_auto_accepted'] = false;

		$literal = self::normalize_scan_input( $raw, $ai_table, false );

		if ( '' === $literal['raw'] || isset( $literal['meta']['hri_error'] ) ) {
			return $result;
		}

		$alternative = self::analyse_payload( $literal['raw'], $ai_table, array(), $literal['meta'] );
		$fields      = isset( $result['fields'] ) && is_array( $result['fields'] ) ? $result['fields'] : array();

		if ( ! self::all_required_fields_present( $alternative['fields'] ) ) {
			return $result;
		}

		$chosen = self::target_signature( $fields );
		$rival  = self::target_signature( $alternative['fields'] );

		if ( $chosen === $rival ) {
			return $result;
		}

		$differences = array();

		foreach ( self::REQUIRED_FIELDS as $label ) {
			if ( $chosen[ $label ] !== $rival[ $label ] ) {
				$differences[] = sprintf( '%s «%s»', $label, $rival[ $label ] );
			}
		}

		$result['contested_fields']  = self::merge_rival_signatures(
			isset( $result['contested_fields'] ) ? (array) $result['contested_fields'] : array(),
			$chosen,
			array( $rival )
		);
		$result['ambiguous']         = true;
		$result['alternative_count'] = max( 2, (int) $result['alternative_count'] + 1 );

		if ( 'high' === $result['confidence'] ) {
			$result['confidence'] = 'medium';
		}

		$result['warnings'][] = sprintf(
			/* translators: %s: the fields as read when "<GS>" is part of the value. */
			__( 'Αν το «<GS>» είναι μέρος της τιμής και όχι διαχωριστής, η ανάγνωση είναι: %s. Επιλέξτε την τιμή που υπάρχει στη συσκευασία.', 'qr-rebuilder-pro' ),
			implode( ', ', $differences )
		);

		return $result;
	}

	/**
	 * True when an ISO date (YYYY-MM-DD) lies before today. Public so the
	 * rebuild endpoint can check the date actually being built, independently
	 * of any earlier parse.
	 */
	public static function date_is_in_past( $iso_date ) {
		$iso_date = self::expiry_effective_date( is_scalar( $iso_date ) ? (string) $iso_date : '' );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $iso_date ) ) {
			return false;
		}

		return $iso_date < wp_date( 'Y-m-d' );
	}

	/**
	 * 2.15.3: η ημερομηνία με την οποία κρίνεται η λήξη. Το «YYYY-MM-00» (GS1
	 * DD=00) ισχύει έως την τελευταία ημέρα του μήνα· κάθε άλλη τιμή μένει ως έχει.
	 */
	public static function expiry_effective_date( $iso_date ) {
		$iso_date = is_scalar( $iso_date ) ? (string) $iso_date : '';

		if ( ! preg_match( '/^(\d{4})-(\d{2})-00$/', $iso_date, $matches ) ) {
			return $iso_date;
		}

		$year  = (int) $matches[1];
		$month = (int) $matches[2];

		if ( $month < 1 || $month > 12 ) {
			return $iso_date;
		}

		return sprintf( '%04d-%02d-%02d', $year, $month, self::last_day_of_month( $year, $month ) );
	}

	/** 2.15.3: τελευταία ημέρα του μήνα (χωρίς εξάρτηση από την ext-calendar). */
	private static function last_day_of_month( $year, $month ) {
		$first = DateTimeImmutable::createFromFormat( '!Y-m-d', sprintf( '%04d-%02d-01', (int) $year, (int) $month ) );

		return $first ? (int) $first->format( 't' ) : 28;
	}

	/**
	 * 2.15.3: διατήρηση του DD=00 στον νέο κωδικό (προεπιλογή). Με false
	 * επιστρέφει το παλιό μοντέλο: προτείνεται η τελευταία ημέρα του μήνα.
	 */
	public static function preserve_expiry_day_zero() {
		return (bool) apply_filters( 'qrrp_preserve_expiry_day_zero', true );
	}

	/** True when a successfully decoded expiry date lies before today. */
	private static function exp_is_in_past( $fields ) {
		if ( ! self::has_field_value( $fields, 'EXP' ) ) {
			return false;
		}

		return self::date_is_in_past( $fields['EXP'] );
	}

	/**
	 * Αφαιρεί μόνο θόρυβο μεταφοράς (BOM, suffix σαρωτή, «GS1:», κενά,
	 * symbology identifier, ορατές μορφές GS, HRI) χωρίς να επινοεί τιμές.
	 *
	 * @param string $raw                  Η είσοδος όπως ήρθε.
	 * @param array  $ai_table             Ο πίνακας των τεσσάρων AI.
	 * @param bool   $gs_text_as_separator Αν το κείμενο «<GS>» μετατρέπεται σε ASCII 29.
	 * @param string $sigma_letters        2.15.3: S/W ανά «Σ» της ελληνικής διάταξης (κενό = όλα S).
	 * @return array { raw, warnings, meta }
	 */
	private static function normalize_scan_input( $raw, $ai_table, $gs_text_as_separator = true, $sigma_letters = '' ) {
		$warnings = array();
		$meta     = array(
			'changed'              => false,
			'symbology_identifier' => '',
			'input_mode'           => 'raw',
			'original_bytes'       => strlen( $raw ),
		);

		if ( 0 === strncmp( $raw, "\xEF\xBB\xBF", 3 ) ) {
			$raw             = substr( $raw, 3 );
			$meta['changed'] = true;
			$warnings[]      = __( 'Αφαιρέθηκε UTF-8 BOM από την αρχή των δεδομένων.', 'qr-rebuilder-pro' );
		}

		/* NBSP, zero-width χαρακτήρες και BOM δεν ανήκουν ποτέ σε GS1 δεδομένα. */
		$invisible = preg_replace( '/\xC2\xA0|\xE2\x80[\x8B-\x8D]|\xEF\xBB\xBF/', '', $raw );
		if ( is_string( $invisible ) && $invisible !== $raw ) {
			$raw             = $invisible;
			$meta['changed'] = true;
			$warnings[]      = __( 'Αφαιρέθηκαν αόρατοι χαρακτήρες (non-breaking space ή zero-width) που προέρχονται από αντιγραφή κειμένου.', 'qr-rebuilder-pro' );
		}

		/*
		 * Το charset 82 δεν έχει κενά, οπότε κάθε κενό (και στη μέση, από
		 * αναδιπλωμένο κείμενο) είναι θόρυβος. Ο ASCII 29 δεν είναι κενό.
		 */
		$unlabelled = preg_replace( '/^\s*GS1\s*:\s*/i', '', $raw );
		if ( is_string( $unlabelled ) && $unlabelled !== $raw ) {
			$raw             = $unlabelled;
			$meta['changed'] = true;
			$warnings[]      = __( 'Αφαιρέθηκε το πρόθεμα «GS1:» που αντιγράφηκε μαζί με τα δεδομένα.', 'qr-rebuilder-pro' );
		}

		$trimmed = preg_replace( '/^[\x00\x09\x0A\x0B\x0C\x0D ]+|[\x00\x09\x0A\x0B\x0C\x0D ]+$/', '', $raw );
		if ( is_string( $trimmed ) && $trimmed !== $raw ) {
			$raw             = $trimmed;
			$meta['changed'] = true;
			$warnings[]      = __( 'Αφαιρέθηκαν χαρακτήρες αρχής/τέλους από scanner suffix ή copy/paste.', 'qr-rebuilder-pro' );
		}

		$layout = self::undo_greek_layout( $raw, $sigma_letters );
		if ( $layout !== $raw ) {
			/* 2.15.3: πόσα «Σ» διαβάστηκαν ως S ή W — η parse() ζητά επιλογή. */
			$sigma_count = substr_count( $raw, 'Σ' );

			if ( $sigma_count > 0 ) {
				$meta['ambiguous_sigma'] = $sigma_count;
			}

			$raw                      = $layout;
			$meta['changed']          = true;
			$meta['layout_recovered'] = true;
			$warnings[]               = __( 'ΠΡΟΣΟΧΗ — η σάρωση περιείχε ελληνικούς χαρακτήρες: το πληκτρολόγιο ήταν σε ελληνική διάταξη όταν διαβάστηκε ο κωδικός. Οι χαρακτήρες επαναφέρθηκαν στα λατινικά τους πλήκτρα, ΑΛΛΑ μια σάρωση σε λάθος διάταξη μπορεί να έχει χάσει ή να έχει διπλώσει χαρακτήρες. ΕΛΕΓΞΤΕ πεδίο προς πεδίο — ή, ασφαλέστερα, αλλάξτε τη γλώσσα σε αγγλικά και σαρώστε ξανά.', 'qr-rebuilder-pro' );
		}

		/*
		 * Το «]Q3» σε ελληνική διάταξη φτάνει ως «]:3» (Shift+Q = «:»). Χωρίς
		 * ελληνικά γράμματα η undo_greek_layout() δεν το αγγίζει, αλλά το
		 * πρόθεμα αρκεί ως απόδειξη διάταξης για τα «;»/«:» που ακολουθούν.
		 */
		if ( 0 === strncmp( $raw, ']:3', 3 ) ) {
			$rest            = substr( $raw, 3 );
			$mapped          = strtr( $rest, array( ';' => 'q', ':' => 'Q' ) );
			$raw             = ']Q3' . $mapped;
			$meta['changed'] = true;

			if ( $mapped !== $rest ) {
				$meta['layout_recovered'] = true;
				$warnings[]               = __( 'ΠΡΟΣΟΧΗ — ο identifier «]:3» σημαίνει ότι το πληκτρολόγιο ήταν σε ελληνική διάταξη. Τα «;» και «:» επαναφέρθηκαν σε «q» και «Q». Ελέγξτε τα πεδία με τη συσκευασία.', 'qr-rebuilder-pro' );
			}
		}

		$compacted = preg_replace( '/[\x00\x09\x0A\x0B\x0C\x0D ]+/', '', $raw );
		if ( is_string( $compacted ) && $compacted !== $raw ) {
			$raw             = $compacted;
			$meta['changed'] = true;
			$warnings[]      = __( 'Αφαιρέθηκαν κενά/αλλαγές γραμμής μέσα στα δεδομένα (προέρχονται από αντιγραφή αναδιπλωμένου κειμένου· τα GS1 δεδομένα δεν περιέχουν ποτέ κενά).', 'qr-rebuilder-pro' );
		}

		if ( preg_match( '/^\][A-Za-z0-9][0-9]/', $raw, $match ) ) {
			$identifier = $match[0];
			$candidate  = substr( $raw, 3 );
			$probe      = preg_replace( '/^(?:\x1D|' . self::VISIBLE_GS_PATTERN . '|<GS>)+/', '', $candidate );

			if ( self::looks_like_supported_payload( (string) $probe ) ) {
				$raw                          = $candidate;
				$meta['changed']              = true;
				$meta['symbology_identifier'] = $identifier;

				if ( ! in_array( $identifier, self::GS1_SYMBOLOGY_IDENTIFIERS, true ) ) {
					/* Π.χ. «]d1» = απλό DataMatrix, όχι GS1 — θέλει μάτια. */
					$meta['non_gs1_symbology'] = true;
					$warnings[]                = sprintf(
						/* translators: %s: the symbology identifier that was removed, e.g. ]d2. */
						__( 'Αφαιρέθηκε scanner symbology identifier «%s», αλλά δεν είναι ο αναμενόμενος GS1 identifier. Ελέγξτε την πηγή.', 'qr-rebuilder-pro' ),
						$identifier
					);
				}
			}
		}

		/*
		 * Ορατές μορφές του GS πριν από AI ή στο τέλος. Το «<GS>» είναι
		 * ειδική περίπτωση: τα «<» και «>» ανήκουν στο charset 82, άρα μπορεί
		 * να είναι και περιεχόμενο τιμής. Η parse() το διαβάζει ως διαχωριστή,
		 * προσφέρει και την άλλη ανάγνωση και ζητά πάντα επιβεβαίωση.
		 */
		$has_gs_text = (bool) preg_match( '/<GS>(?=\d{2}|$)/', $raw );
		$visible     = self::VISIBLE_GS_PATTERN . ( $gs_text_as_separator && $has_gs_text ? '|<GS>' : '' );
		$converted   = preg_replace( '/(?:' . $visible . ')(?=\d{2}|$)/', self::GROUP_SEPARATOR, $raw );
		if ( is_string( $converted ) && $converted !== $raw ) {
			$raw             = $converted;
			$meta['changed'] = true;
			$warnings[]      = __( 'Μετατράπηκε ορατή αναπαράσταση Group Separator σε ASCII 29.', 'qr-rebuilder-pro' );
		}

		if ( $has_gs_text ) {
			$meta['literal_gs_text'] = true;
			$warnings[]              = $gs_text_as_separator
				? __( 'Τα δεδομένα περιείχαν το κείμενο «<GS>» και διαβάστηκε ως Group Separator. Επειδή το GS1 επιτρέπει τους χαρακτήρες «<» και «>» μέσα σε Serial ή LOT, ελέγξτε τα πεδία με τη συσκευασία.', 'qr-rebuilder-pro' )
				: __( 'Τα δεδομένα περιέχουν το κείμενο «<GS>». Διατηρήθηκε ΩΣ ΕΧΕΙ, επειδή το GS1 επιτρέπει τους χαρακτήρες «<» και «>» μέσα σε Serial ή LOT. Αν εννοούσατε πραγματικό Group Separator, χρησιμοποιήστε τη μορφή [GS] ή σαρώστε ξανά τη συσκευασία.', 'qr-rebuilder-pro' );
		}

		$hri_extra_ais    = array();
		$hri_extra_values = array();
		$hri_error        = '';
		$hri_alternatives = array();
		$hri              = self::normalize_hri( $raw, $ai_table, $hri_extra_ais, $hri_extra_values, $hri_error, $hri_alternatives );
		if ( null !== $hri ) {
			$raw                = $hri;
			$meta['changed']    = true;
			$meta['input_mode'] = 'parenthesized_hri';
			$warnings[]         = __( 'Αναγνωρίστηκε μορφή HRI με παρενθέσεις και μετατράπηκε σε GS1 element string.', 'qr-rebuilder-pro' );

			/* 2.15.3: αμφίσημα όρια HRI· η parse() ζητά επιβεβαίωση. */
			if ( ! empty( $hri_alternatives ) ) {
				$meta['hri_split_alternatives'] = $hri_alternatives;
			}

			if ( ! empty( $hri_extra_ais ) ) {
				$meta['hri_extra_ais']    = $hri_extra_ais;
				$meta['hri_extra_values'] = $hri_extra_values;
				$warnings[]               = sprintf(
					/* translators: %s: comma separated list of GS1 Application Identifiers, e.g. "240, 30". */
					__( 'Ο κωδικός περιείχε και άλλα GS1 πεδία (AI %s) εκτός των PC/SN/LOT/EXP. Δεν εμφανίζονται ως επεξεργάσιμα πεδία. Στον νέο κωδικό θα διατηρηθούν μόνο όσα επαληθευτούν με ασφάλεια από την αρχική πηγή.', 'qr-rebuilder-pro' ),
					implode( ', ', $hri_extra_ais )
				);
			}
		} elseif ( '' !== $raw && '(' === $raw[0] ) {
			/* Μοιάζει με HRI αλλά δεν είναι έγκυρο: η parse() το αναφέρει με την αιτία. */
			$meta['hri_error'] = '' !== $hri_error
				? $hri_error
				: __( 'πιθανό διπλό ή άκυρο AI', 'qr-rebuilder-pro' );
		}

		$clean_edges = trim( $raw, self::GROUP_SEPARATOR );
		if ( $clean_edges !== $raw ) {
			$raw             = $clean_edges;
			$meta['changed'] = true;
			$warnings[]      = __( 'Αφαιρέθηκε περιττός Group Separator από την αρχή ή το τέλος.', 'qr-rebuilder-pro' );
		}

		$collapsed = preg_replace( '/\x1D{2,}/', self::GROUP_SEPARATOR, $raw );
		if ( is_string( $collapsed ) && $collapsed !== $raw ) {
			$raw             = $collapsed;
			$meta['changed'] = true;
			$warnings[]      = __( 'Συγχωνεύτηκαν διαδοχικοί Group Separators.', 'qr-rebuilder-pro' );
		}

		$meta['normalized_bytes'] = strlen( $raw );

		return array(
			'raw'      => $raw,
			'warnings' => $warnings,
			'meta'     => $meta,
		);
	}

	/**
	 * Επαναφέρει χαρακτήρες που πέρασαν από ελληνική διάταξη πληκτρολογίου.
	 *
	 * Ο σαρωτής στέλνει πατήματα πλήκτρων: με ελληνική διάταξη το «M» φτάνει ως
	 * «Μ» (U+039C). Το charset 82 δεν έχει ελληνικά γράμματα, οπότε η αντιστοίχιση
	 * γίνεται κατά θέση πλήκτρου. Το αποτέλεσμα θέλει πάντα επιβεβαίωση, γιατί
	 * μια σάρωση σε λάθος διάταξη μπορεί να έχει χάσει ή διπλασιάσει χαρακτήρες.
	 *
	 * @param string $raw           Η είσοδος.
	 * @param string $sigma_letters 2.15.3: S/W ανά εμφάνιση «Σ» (κενό = όλα S).
	 */
	private static function undo_greek_layout( $raw, $sigma_letters = '' ) {
		static $map = null;

		if ( null === $map ) {
			$positional = array(
				'a' => 'α', 'b' => 'β', 'c' => 'ψ', 'd' => 'δ', 'e' => 'ε',
				'f' => 'φ', 'g' => 'γ', 'h' => 'η', 'i' => 'ι', 'j' => 'ξ',
				'k' => 'κ', 'l' => 'λ', 'm' => 'μ', 'n' => 'ν', 'o' => 'ο',
				'p' => 'π', 'r' => 'ρ', 's' => 'σ', 't' => 'τ', 'u' => 'θ',
				'v' => 'ω', 'w' => 'ς', 'x' => 'χ', 'y' => 'υ', 'z' => 'ζ',
			);

			$accented = array(
				'ά' => 'a', 'έ' => 'e', 'ή' => 'h', 'ί' => 'i', 'ό' => 'o',
				'ύ' => 'y', 'ώ' => 'v', 'ϊ' => 'i', 'ϋ' => 'y', 'ΐ' => 'i',
				'ΰ' => 'y',
			);

			$map = array();

			foreach ( $positional as $latin => $greek ) {
				$map[ $greek ]                        = $latin;
				$map[ self::greek_upper( $greek ) ]   = strtoupper( $latin );
			}

			foreach ( $accented as $greek => $latin ) {
				$map[ $greek ]                      = $latin;
				$map[ self::greek_upper( $greek ) ] = strtoupper( $latin );
			}

			/*
			 * 2.15.3: το «Σ» βγαίνει από Shift+S και, σε Linux (xkb), από Shift+W.
			 * Δεν αντιστοιχίζεται εδώ· κάθε εμφάνιση παίρνει S ή W από το
			 * $sigma_letters και η parse() ζητά επιλογή από τον χρήστη.
			 */
			unset( $map['Σ'] );

			/* 2.15.3: Shift+W στα Windows δίνει «΅» (U+0385)· δεν είναι ποτέ S. */
			$map["\u{0385}"] = 'W';

			/*
			 * Στην ελληνική διάταξη το Q γράφει «;» και το Shift+Q «:». Τα τονισμένα
			 * γράμματα βγαίνουν από νεκρό πλήκτρο (US «;») + γράμμα, άρα «ά» → «;a».
			 * 2.15.3: κάποιες διατάξεις στέλνουν το ελληνικό ερωτηματικό U+037E
			 * αντί για «;»· ίδιο πλήκτρο, ίδια αντιστοίχιση («q»).
			 */
			$map[';']        = 'q';
			$map[':']        = 'Q';
			$map["\u{037E}"] = 'q';
			$map['ά'] = ';a';
			$map['έ'] = ';e';
			$map['ή'] = ';h';
			$map['ί'] = ';i';
			$map['ό'] = ';o';
			$map['ύ'] = ';y';
			$map['ώ'] = ';v';
			$map['Ά'] = ';A';
			$map['Έ'] = ';E';
			$map['Ή'] = ';H';
			$map['Ί'] = ';I';
			$map['Ό'] = ';O';
			$map['Ύ'] = ';Y';
			$map['Ώ'] = ';V';
			$map['ϊ'] = ':i';
			$map['ϋ'] = ':y';
			$map['Ϊ'] = ':I';
			$map['Ϋ'] = ':Y';

			/*
			 * 2.15.7: το «΅» (Shift+W στα Windows) είναι και νεκρό πλήκτρο
			 * διαλυτικών με τόνο: W + i δίνει «ΐ», W + y δίνει «ΰ». Πριν γίνονταν
			 * «i» / «y» και χανόταν το W (π.χ. SN ΑΒΐ9 → ABi9 αντί για ABWi9).
			 */
			$map['ΐ'] = 'Wi';
			$map['ΰ'] = 'Wy';
		}

		/*
		 * Τα «;» και «:» αντιστρέφονται μόνο με απόδειξη ελληνικής διάταξης (ένα
		 * ελληνικό γράμμα). Αλλιώς είναι νόμιμοι χαρακτήρες και η parse() τους σημαδεύει.
		 */
		if ( ! preg_match( '/[\x{0370}-\x{03FF}\x{1F00}-\x{1FFF}]/u', $raw ) ) {
			return $raw;
		}

		$mapped = strtr( $raw, $map );

		if ( false === strpos( $mapped, 'Σ' ) ) {
			return $mapped;
		}

		/* 2.15.3: η i-οστή εμφάνιση του «Σ» γίνεται W μόνο αν το ζητά το $sigma_letters. */
		$parts  = explode( 'Σ', $mapped );
		$output = array_shift( $parts );

		foreach ( $parts as $index => $part ) {
			$output .= ( isset( $sigma_letters[ $index ] ) && 'W' === $sigma_letters[ $index ] ? 'W' : 'S' ) . $part;
		}

		return $output;
	}

	/**
	 * 2.15.3: οι εναλλακτικές S/W μόνο για τα «Σ» των δεικτών $core (τα άλλα
	 * μένουν S), χωρίς την «όλα S». Έως MAX_SIGMA_ENUMERATED δείκτες
	 * απαριθμούνται όλοι οι συνδυασμοί· πάνω από αυτό μόνο «όλα W» (δείχνει ήδη
	 * όλες τις θέσεις).
	 *
	 * @param int   $count Πόσα «Σ» είχε η σάρωση.
	 * @param int[] $core  Οι δείκτες (0..count-1) των «Σ» που απαριθμούνται.
	 * @return string[] Συμβολοσειρές από S/W, μία θέση ανά «Σ».
	 */
	private static function sigma_variant_letters( $count, array $core ) {
		$count = max( 0, (int) $count );
		$core  = array_values( $core );
		$total = count( $core );

		if ( 0 === $count || 0 === $total ) {
			return array();
		}

		$variants = array();
		$masks    = $total <= self::MAX_SIGMA_ENUMERATED
			? range( 1, ( 1 << $total ) - 1 )
			: array( ( 1 << $total ) - 1 );

		foreach ( $masks as $mask ) {
			$letters = str_repeat( 'S', $count );

			foreach ( $core as $bit => $index ) {
				if ( $mask & ( 1 << $bit ) ) {
					$letters[ $index ] = 'W';
				}
			}

			$variants[] = $letters;
		}

		return $variants;
	}

	/**
	 * 2.15.3: ποια «Σ» πολλαπλασιάζονται σε S/W. Μόνο όσα πέφτουν μέσα σε
	 * PC/SN/LOT/EXP της βασικής ανάγνωσης (όλα S)· ένα «Σ» σε επιπλέον AI δεν
	 * πολλαπλασιάζει αναγνώσεις, αφήνει τα extras αναπόδεικτα. Οι θέσεις
	 * βρίσκονται συγκρίνοντας την κανονικοποίηση «όλα S» με την «όλα W»· αν
	 * διαφέρουν σε μήκος (π.χ. «[GΣ]»), το σχέδιο είναι άγνωστο: καμία
	 * απαρίθμηση, μόνο γενική προειδοποίηση.
	 *
	 * @param string $source        Η είσοδος όπως ήρθε.
	 * @param array  $normalization Η προεπιλεγμένη κανονικοποίηση (όλα S).
	 * @return array { known: bool, variants: string[], offsets: int[], outside: int }
	 */
	private static function sigma_plan( $source, array $normalization ) {
		static $plans = array();

		$count = isset( $normalization['meta']['ambiguous_sigma'] ) ? (int) $normalization['meta']['ambiguous_sigma'] : 0;
		$plan  = array(
			'known'    => false,
			'variants' => array(),
			'offsets'  => array(),
			'outside'  => $count,
		);
		$base  = isset( $normalization['raw'] ) ? (string) $normalization['raw'] : '';

		if ( 0 === $count || '' === $base ) {
			return $plan;
		}

		$key = md5( $source ) . ':' . self::century_reference_year();

		if ( isset( $plans[ $key ] ) ) {
			return $plans[ $key ];
		}

		$all_w   = self::normalize_scan_input( $source, self::get_ai_table(), true, str_repeat( 'W', $count ) );
		$offsets = array();

		if ( strlen( $all_w['raw'] ) !== strlen( $base ) ) {
			return $plan;
		}

		for ( $i = 0, $length = strlen( $base ); $i < $length; $i++ ) {
			if ( $base[ $i ] === $all_w['raw'][ $i ] ) {
				continue;
			}

			if ( 'S' !== $base[ $i ] || 'W' !== $all_w['raw'][ $i ] ) {
				return $plan;
			}

			$offsets[] = $i;
		}

		if ( count( $offsets ) !== $count ) {
			return $plan;
		}

		/* Η βασική ανάγνωση: ίδια ανάλυση με την parse() (οι αναζητήσεις είναι στην cache). */
		$reading = self::analyse_payload( $base, self::get_ai_table(), array(), $normalization['meta'] );

		if ( ! empty( $reading['search_truncated'] ) || ! self::all_required_fields_present( $reading['fields'] ) ) {
			return $plan;
		}

		$inside = self::sigma_offsets_in_core( $base, $offsets, $reading['fields'] );
		$core   = array();

		foreach ( $offsets as $index => $offset ) {
			if ( isset( $inside[ $offset ] ) ) {
				$core[] = $index;
			}
		}

		$plan = array(
			'known'    => true,
			'variants' => self::sigma_variant_letters( $count, $core ),
			'offsets'  => $offsets,
			'outside'  => $count - count( $core ),
		);

		if ( count( $plans ) >= 8 ) {
			$plans = array();
		}

		$plans[ $key ] = $plan;

		return $plan;
	}

	/**
	 * 2.15.3: ποιες θέσεις «Σ» του κανονικοποιημένου raw καλύπτει η τιμή ενός
	 * από τα PC/SN/LOT της ανάγνωσης (AI + τιμή, S ή W στις θέσεις «Σ»). Αν η
	 * τιμή εμφανίζεται πολλές φορές μετρά κάθε εμφάνιση: υπερκάλυψη σημαίνει
	 * μόνο περισσότερες παραλλαγές, ποτέ αθέατο «Σ».
	 *
	 * @return array Θέση → true.
	 */
	private static function sigma_offsets_in_core( $raw, array $offsets, array $fields ) {
		$is_sigma = array_flip( $offsets );
		$inside   = array();
		$length   = strlen( $raw );

		foreach ( array( '01' => 'PC', '10' => 'LOT', '21' => 'SN' ) as $ai => $label ) {
			$value = isset( $fields[ $label ] ) && is_scalar( $fields[ $label ] ) ? (string) $fields[ $label ] : '';
			$size  = strlen( $value );

			if ( 0 === $size ) {
				continue;
			}

			for ( $at = strpos( $raw, $ai ); false !== $at && $at + 2 + $size <= $length; $at = strpos( $raw, $ai, $at + 1 ) ) {
				$start = $at + 2;
				$match = true;

				for ( $j = 0; $j < $size; $j++ ) {
					$have = $raw[ $start + $j ];
					$want = $value[ $j ];

					if ( $have !== $want && ! ( isset( $is_sigma[ $start + $j ] ) && ( 'S' === $want || 'W' === $want ) ) ) {
						$match = false;
						break;
					}
				}

				if ( ! $match ) {
					continue;
				}

				for ( $j = 0; $j < $size; $j++ ) {
					if ( isset( $is_sigma[ $start + $j ] ) ) {
						$inside[ $start + $j ] = true;
					}
				}
			}
		}

		return $inside;
	}

	/** 2.15.3: ανοίγει τον κοινό προϋπολογισμό των παραλλαγών· false αν ήταν ήδη ανοιχτός. */
	private static function open_variant_budget() {
		if ( null !== self::$variant_pool ) {
			return false;
		}

		self::$variant_pool = array(
			'nodes' => self::MAX_SEARCH_NODES,
			'work'  => self::MAX_SEARCH_WORK,
		);

		return true;
	}

	/** 2.15.3: κλείνει τον κοινό προϋπολογισμό μόνο αν τον άνοιξε ο ίδιος καλών. */
	private static function close_variant_budget( $opened ) {
		if ( $opened ) {
			self::$variant_pool = null;
		}
	}

	/**
	 * 2.15.3: το «Σ» της ελληνικής διάταξης είναι S ή W. Για τα «Σ» μέσα σε
	 * PC/SN/LOT/EXP προσθέτει τις αναγνώσεις με W στο contested_fields (ο picker
	 * τις προσφέρει) και ονομάζει πεδίο και θέσεις· για τα υπόλοιπα γενική
	 * προειδοποίηση. Ποτέ υψηλή εμπιστοσύνη.
	 *
	 * @param array  $result        Αποτέλεσμα με όλα τα «Σ» ως S.
	 * @param string $raw           Η αρχική είσοδος.
	 * @param array  $ai_table      Ο πίνακας των τεσσάρων AI.
	 * @param array  $normalization Η προεπιλεγμένη κανονικοποίηση.
	 * @return array
	 */
	private static function with_sigma_alternatives( array $result, $raw, $ai_table, array $normalization ) {
		$result['requires_confirmation']   = true;
		$result['inference_auto_accepted'] = false;

		if ( 'high' === $result['confidence'] ) {
			$result['confidence'] = 'medium';
		}

		$fields     = isset( $result['fields'] ) && is_array( $result['fields'] ) ? $result['fields'] : array();
		$chosen     = self::target_signature( $fields );
		$rivals     = array();
		$positions  = array();
		$incomplete = ! empty( $result['search_truncated'] ) || ! empty( $result['cross_check_truncated'] );
		$plan       = array(
			'known'    => false,
			'variants' => array(),
			'outside'  => 0,
		);
		$opened     = self::open_variant_budget();

		try {
			/* Κομμένη αναζήτηση θα κοβόταν και στις παραλλαγές (ίδια δομή). */
			if ( ! $incomplete ) {
				$plan = self::sigma_plan( $raw, $normalization );
			}

			foreach ( $plan['variants'] as $letters ) {
				$variant = self::normalize_scan_input( $raw, $ai_table, true, $letters );

				if ( '' === $variant['raw'] || isset( $variant['meta']['hri_error'] ) ) {
					continue;
				}

				$alternative = self::analyse_payload( $variant['raw'], $ai_table, array(), $variant['meta'] );

				/* Εξαντλήθηκε ο κοινός προϋπολογισμός: η λίστα μένει ημιτελής. */
				if ( ! empty( $alternative['search_truncated'] ) || ! empty( $alternative['cross_check_truncated'] ) ) {
					$incomplete = true;
					break;
				}

				if ( ! self::all_required_fields_present( $alternative['fields'] ) ) {
					continue;
				}

				$rival = self::target_signature( $alternative['fields'] );

				if ( $rival === $chosen ) {
					continue;
				}

				$rivals[] = $rival;

				/* Τα S/W είναι γράμματα, άρα τα όρια δεν αλλάζουν: σύγκριση θέση προς θέση. */
				foreach ( self::REQUIRED_FIELDS as $label ) {
					$mine  = $chosen[ $label ];
					$other = $rival[ $label ];

					if ( $mine === $other || strlen( $mine ) !== strlen( $other ) ) {
						continue;
					}

					for ( $i = 0, $length = strlen( $mine ); $i < $length; $i++ ) {
						if ( 'S' === $mine[ $i ] && 'W' === $other[ $i ] ) {
							$positions[ $label ][ $i + 1 ] = true;
						}
					}
				}
			}
		} finally {
			self::close_variant_budget( $opened );
		}

		if ( ! empty( $rivals ) ) {
			$result['contested_fields']  = self::merge_rival_signatures(
				isset( $result['contested_fields'] ) ? (array) $result['contested_fields'] : array(),
				$chosen,
				$rivals
			);
			$result['ambiguous']         = true;
			$result['alternative_count'] = max( 2, (int) $result['alternative_count'] + count( $rivals ) );
		}

		$places = array();

		foreach ( self::REQUIRED_FIELDS as $label ) {
			if ( empty( $positions[ $label ] ) ) {
				continue;
			}

			$list = array_keys( $positions[ $label ] );
			sort( $list, SORT_NUMERIC );

			$places[] = sprintf(
				/* translators: 1: field label (SN, LOT), 2: comma-separated character positions. */
				__( '%1$s θέση %2$s', 'qr-rebuilder-pro' ),
				$label,
				implode( ', ', $list )
			);
		}

		if ( ! empty( $places ) ) {
			$result['warnings'][] = sprintf(
				/* translators: %s: fields and character positions, e.g. "SN θέση 3, 7". */
				__( 'ΠΡΟΣΟΧΗ — το «Σ» της ελληνικής διάταξης βγαίνει και από το S και από το W (Shift+W σε Linux). Μπορεί να είναι «S» ή «W»: %s. Επιλέξτε την τιμή που είναι τυπωμένη στη συσκευασία.', 'qr-rebuilder-pro' ),
				implode( ' · ', $places )
			);
		}

		if ( $plan['known'] && $plan['outside'] > 0 ) {
			$result['warnings'][] = __( 'ΠΡΟΣΟΧΗ — η σάρωση περιείχε «Σ», που στην ελληνική διάταξη βγαίνει και από το S και από το W (Shift+W σε Linux). Διαβάστηκε ως «S» σε πεδίο που δεν αλλάζει τα PC/SN/LOT/EXP. Ελέγξτε τον κωδικό με τη συσκευασία ή σαρώστε ξανά με αγγλική διάταξη.', 'qr-rebuilder-pro' );
		}

		if ( ! $plan['known'] || $incomplete ) {
			$result['warnings'][] = __( 'ΠΡΟΣΟΧΗ — η σάρωση περιείχε «Σ», που στην ελληνική διάταξη βγαίνει και από το S και από το W (Shift+W σε Linux). Διαβάστηκε ως «S» χωρίς πλήρη έλεγχο της εναλλακτικής. Σαρώστε ξανά με αγγλική διάταξη.', 'qr-rebuilder-pro' );
		}

		return $result;
	}

	/** Κεφαλαιοποίηση ελληνικού γράμματος χωρίς εξάρτηση από την mbstring. */
	private static function greek_upper( $letter ) {
		$pairs = array(
			'α' => 'Α', 'β' => 'Β', 'γ' => 'Γ', 'δ' => 'Δ', 'ε' => 'Ε',
			'ζ' => 'Ζ', 'η' => 'Η', 'θ' => 'Θ', 'ι' => 'Ι', 'κ' => 'Κ',
			'λ' => 'Λ', 'μ' => 'Μ', 'ν' => 'Ν', 'ξ' => 'Ξ', 'ο' => 'Ο',
			'π' => 'Π', 'ρ' => 'Ρ', 'σ' => 'Σ', 'ς' => 'Σ', 'τ' => 'Τ',
			'υ' => 'Υ', 'φ' => 'Φ', 'χ' => 'Χ', 'ψ' => 'Ψ', 'ω' => 'Ω',
			'ά' => 'Ά', 'έ' => 'Έ', 'ή' => 'Ή', 'ί' => 'Ί', 'ό' => 'Ό',
			'ύ' => 'Ύ', 'ώ' => 'Ώ', 'ϊ' => 'Ϊ', 'ϋ' => 'Ϋ', 'ΐ' => 'Ϊ',
			'ΰ' => 'Ϋ',
		);

		return isset( $pairs[ $letter ] ) ? $pairs[ $letter ] : $letter;
	}

	private static function looks_like_supported_payload( $value ) {
		/* An element string always starts with a numeric AI, HRI with '('. */
		return (bool) preg_match( '/^(?:\d{2}|\()/', $value );
	}

	/**
	 * Μετατρέπει πλήρως έγκυρο HRI με παρενθέσεις σε element string, αλλιώς null.
	 *
	 * Οι παρενθέσεις είναι ρητά όρια, οπότε αναγνωρίζεται κάθε γνωστό AI (όχι μόνο
	 * τα τέσσερα): αλλιώς ένα «(240)» θα κατέληγε μέσα στην τιμή του προηγούμενου
	 * πεδίου, αφού το charset 82 επιτρέπει παρενθέσεις. Άγνωστο ή διπλό AI ή
	 * άκυρη τιμή σημαίνει απόρριψη (fail closed), με την αιτία στο $error.
	 *
	 * @param string $input        Το HRI κείμενο.
	 * @param array  $ai_table     Ο πίνακας των τεσσάρων AI που ξαναχτίζονται.
	 * @param array  $extra_ais    Έξοδος: τα επιπλέον AI που βρέθηκαν.
	 * @param array  $extra_values Έξοδος: τα επιπλέον AI με τις τιμές τους.
	 * @param string $error        Έξοδος: η αιτία απόρριψης, όταν επιστρέφεται null.
	 * @param array  $alternatives 2.15.3 έξοδος: αναγνώσεις όπου ένα επιπλέον AI
	 *                             είναι μέρος της προηγούμενης τιμής (βλ. παρακάτω).
	 * @return string|null
	 */
	private static function normalize_hri( $input, $ai_table, &$extra_ais = array(), &$extra_values = array(), &$error = '', &$alternatives = array() ) {
		$extra_ais    = array();
		$extra_values = array();
		$error        = '';
		$alternatives = array();
		$entries      = array();

		if ( '' === $input || '(' !== $input[0] ) {
			return null;
		}

		/* GS1 AIs are 2-4 digits; the parentheses carry the whole AI explicitly. */
		if ( ! preg_match_all( '/\((\d{2,4})\)/', $input, $matches, PREG_OFFSET_CAPTURE ) || 0 !== (int) $matches[0][0][1] ) {
			$error = __( 'δεν ξεκινά με Application Identifier σε παρενθέσεις', 'qr-rebuilder-pro' );
			return null;
		}

		$full_table = self::full_ai_table();
		$fields     = array();
		$order      = array();
		$used       = array();
		$count      = count( $matches[0] );
		$length     = strlen( $input );

		for ( $i = 0; $i < $count; $i++ ) {
			$ai          = $matches[1][ $i ][0];
			$marker      = $matches[0][ $i ][0];
			$value_start = (int) $matches[0][ $i ][1] + strlen( $marker );
			$value_end   = ( $i + 1 < $count ) ? (int) $matches[0][ $i + 1 ][1] : $length;
			$value       = trim( substr( $input, $value_start, $value_end - $value_start ) );

			if ( isset( $used[ $ai ] ) ) {
				/* translators: %s: GS1 Application Identifier. */
				$error = sprintf( __( 'το AI (%s) εμφανίζεται δύο φορές', 'qr-rebuilder-pro' ), $ai );
				return null;
			}

			if ( ! isset( $full_table[ $ai ] ) ) {
				/* translators: %s: GS1 Application Identifier. */
				$error = sprintf( __( 'άγνωστο AI (%s)', 'qr-rebuilder-pro' ), $ai );
				return null;
			}

			if ( ! self::value_matches_definition( $value, $full_table[ $ai ] ) ) {
				$error = sprintf(
					/* translators: 1: the rejected value, 2: GS1 Application Identifier. */
					__( 'η τιμή «%1$s» δεν είναι έγκυρη για το AI (%2$s)', 'qr-rebuilder-pro' ),
					self::utf8_scrub( $value ),
					$ai
				);
				return null;
			}

			$used[ $ai ] = true;
			$entries[]   = array(
				'ai'     => $ai,
				'marker' => $marker,
				'value'  => $value,
				'def'    => $full_table[ $ai ],
			);

			if ( isset( $ai_table[ $ai ] ) ) {
				$fields[ $ai_table[ $ai ]['label'] ] = $value;
				$order[]                             = $ai;
				continue;
			}

			$extra_ais[]    = $ai;
			$extra_values[] = array(
				'ai'    => $ai,
				'value' => $value,
			);
		}

		/* Only extras and no rebuildable field: nothing to hand to build(). */
		if ( empty( $order ) ) {
			$extra_ais    = array();
			$extra_values = array();
			$error        = __( 'δεν περιέχει κανένα από τα AI (01), (17), (10), (21)', 'qr-rebuilder-pro' );

			return null;
		}

		/*
		 * 2.15.3: οι παρενθέσεις ανήκουν στο charset 82, άρα το «(21)AB(90)CD»
		 * διαβάζεται και ως SN «AB(90)CD». Κανόνας: ένα επιπλέον AI (όχι 01/17/10/21)
		 * είναι αμφίσημο όταν η προηγούμενη τιμή, μαζί με το «(AI)» και την τιμή
		 * του, παραμένει έγκυρη για το προηγούμενο AI (μεταβλητό μήκος, χωράει στο
		 * μέγιστο, επιτρέπει τους χαρακτήρες). Τα τέσσερα AI δεν ελέγχονται: αν
		 * απορροφηθούν λείπει υποχρεωτικό πεδίο, άρα η ανάγνωση δεν είναι έγκυρη.
		 * Σταθερού μήκους (01, 17) και αριθμητικά πεδία δεν απορροφούν ποτέ.
		 */
		for ( $i = 1, $total = count( $entries ); $i < $total; $i++ ) {
			$entry    = $entries[ $i ];
			$previous = $entries[ $i - 1 ];

			if ( isset( $ai_table[ $entry['ai'] ] ) ) {
				continue;
			}

			$merged = $previous['value'] . $entry['marker'] . $entry['value'];

			if ( ! self::value_matches_definition( $merged, $previous['def'] ) ) {
				continue;
			}

			$alt_fields = array();
			$alt_extras = array();

			foreach ( $entries as $index => $item ) {
				if ( $index === $i ) {
					continue;
				}

				$value = ( $index === $i - 1 ) ? $merged : $item['value'];

				if ( isset( $ai_table[ $item['ai'] ] ) ) {
					$alt_fields[ $ai_table[ $item['ai'] ]['label'] ] = $value;
				} else {
					$alt_extras[] = array(
						'ai'    => $item['ai'],
						'value' => $value,
					);
				}
			}

			$alternatives[] = array(
				'ai'       => $entry['ai'],
				'after_ai' => $previous['ai'],
				'label'    => isset( $ai_table[ $previous['ai'] ] ) ? $ai_table[ $previous['ai'] ]['label'] : '',
				'merged'   => $merged,
				'fields'   => $alt_fields,
				'extras'   => $alt_extras,
			);
		}

		return self::build( $fields, $order );
	}

	/**
	 * 2.15.3: HRI όπου ένα επιπλέον AI ακολουθεί τιμή μεταβλητού μήκους. Ζητά
	 * πάντα επιβεβαίωση και, όταν αλλάζει κάποιο από τα τέσσερα πεδία, προσθέτει
	 * την εναλλακτική τιμή στο contested_fields για τον picker.
	 *
	 * @param array $result Αποτέλεσμα με τις παρενθέσεις ως όρια πεδίων.
	 * @param array $meta   Τα meta της κανονικοποίησης (hri_split_alternatives).
	 * @return array
	 */
	private static function with_hri_split_alternatives( array $result, array $meta ) {
		$result['requires_confirmation']   = true;
		$result['inference_auto_accepted'] = false;
		$result['ambiguous']               = true;

		if ( 'high' === $result['confidence'] ) {
			$result['confidence'] = 'medium';
		}

		$fields = isset( $result['fields'] ) && is_array( $result['fields'] ) ? $result['fields'] : array();
		$chosen = self::target_signature( $fields );
		$rivals = array();

		foreach ( (array) $meta['hri_split_alternatives'] as $alternative ) {
			$rival_fields = $alternative['fields'];

			if ( isset( $rival_fields['EXP'] ) ) {
				$ignored             = array();
				$rival_fields['EXP'] = self::format_yymmdd( $rival_fields['EXP'], $ignored );
			}

			if ( self::all_required_fields_present( $rival_fields ) ) {
				$rival = self::target_signature( $rival_fields );

				if ( $rival !== $chosen ) {
					$rivals[] = $rival;
				}
			}

			$result['warnings'][] = sprintf(
				/* translators: 1: the extra AI, 2: the preceding field (e.g. "SN" or "AI 240"), 3: the value if the parentheses belong to it. */
				__( 'Στη μορφή HRI το «(%1$s)» ακολουθεί πεδίο μεταβλητού μήκους (%2$s). Οι παρενθέσεις επιτρέπονται μέσα σε τιμή GS1, οπότε μπορεί να είναι νέο πεδίο ή μέρος της τιμής: %2$s «%3$s». Ελέγξτε με τη συσκευασία.', 'qr-rebuilder-pro' ),
				$alternative['ai'],
				'' !== $alternative['label'] ? $alternative['label'] : 'AI ' . $alternative['after_ai'],
				$alternative['merged']
			);
		}

		if ( ! empty( $rivals ) ) {
			$result['contested_fields'] = self::merge_rival_signatures(
				isset( $result['contested_fields'] ) ? (array) $result['contested_fields'] : array(),
				$chosen,
				$rivals
			);
		}

		$result['alternative_count'] = max( 2, (int) $result['alternative_count'] + count( (array) $meta['hri_split_alternatives'] ) );

		return $result;
	}

	/**
	 * Validate edited fields and build the authoritative GS1 element string.
	 * EXP is expected as YYYY-MM-DD here. 2.15.3: και «YYYY-MM-00» (GS1 DD=00),
	 * που χτίζεται ως YYMM00, εκτός αν qrrp_preserve_expiry_day_zero = false.
	 */
	public static function validate_and_build( $fields, $extras = array() ) {
		$errors       = array();
		$clean        = array();
		$clean_extras = array();
		$fields       = is_array( $fields ) ? $fields : array();
		$ai_table     = self::get_ai_table();
		$pc           = self::scalar_trim( isset( $fields['PC'] ) ? $fields['PC'] : '' );

		if ( '' === $pc ) {
			$errors[] = __( 'Το πεδίο PC (GTIN) είναι υποχρεωτικό.', 'qr-rebuilder-pro' );
		} elseif ( ! preg_match( '/^\d{14}$/', $pc ) ) {
			$errors[] = __( 'Το PC (GTIN, AI 01) πρέπει να αποτελείται από ακριβώς 14 ψηφία.', 'qr-rebuilder-pro' );
		} elseif ( ! self::gtin_check_digit_is_valid( $pc ) ) {
			$errors[] = __( 'Το PC (GTIN, AI 01) απέτυχε τον έλεγχο του ψηφίου ελέγχου GS1 (check digit) — πιθανό λάθος σάρωσης ή πληκτρολόγησης.', 'qr-rebuilder-pro' );
		} else {
			$clean['PC'] = $pc;
		}

		$variable_fields = array(
			'SN'  => array( 'ai' => '21', 'name' => __( 'SN (Serial Number, AI 21)', 'qr-rebuilder-pro' ) ),
			'LOT' => array( 'ai' => '10', 'name' => __( 'LOT (Batch/Lot, AI 10)', 'qr-rebuilder-pro' ) ),
		);

		foreach ( $variable_fields as $label => $info ) {
			$value = self::scalar_trim( isset( $fields[ $label ] ) ? $fields[ $label ] : '' );
			$max   = (int) $ai_table[ $info['ai'] ]['max'];

			if ( '' === $value ) {
				/* translators: %s: field name, e.g. PC, SN, LOT or EXP. */
				$errors[] = sprintf( __( 'Το πεδίο %s είναι υποχρεωτικό.', 'qr-rebuilder-pro' ), $info['name'] );
			} elseif ( strlen( $value ) > $max ) {
				/* translators: 1: field name, 2: maximum number of characters allowed by GS1. */
				$errors[] = sprintf( __( 'Το %1$s υπερβαίνει το μέγιστο επιτρεπτό μήκος (%2$d χαρακτήρες) του GS1.', 'qr-rebuilder-pro' ), $info['name'], $max );
			} elseif ( ! preg_match( self::GS1_CHARSET_PATTERN, $value ) ) {
				/* translators: %s: field name, e.g. PC, SN, LOT or EXP. */
				$errors[] = sprintf( __( 'Το %s περιέχει χαρακτήρες που δεν επιτρέπονται από το GS1 (character set 82).', 'qr-rebuilder-pro' ), $info['name'] );
			} else {
				$clean[ $label ] = $value;
			}
		}

		$exp = self::scalar_trim( isset( $fields['EXP'] ) ? $fields['EXP'] : '' );
		if ( '' === $exp ) {
			$errors[] = __( 'Το πεδίο EXP (ημερομηνία λήξης) είναι υποχρεωτικό.', 'qr-rebuilder-pro' );
		} elseif ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $exp, $matches ) ) {
			$errors[] = __( 'Το EXP πρέπει να είναι έγκυρη ημερομηνία (YYYY-MM-DD).', 'qr-rebuilder-pro' );
		} elseif (
			! checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] )
			/* 2.15.3: «YYYY-MM-00» (DD=00, τέλος μήνα) δεκτό όταν διατηρείται το 00. */
			&& ! ( '00' === $matches[3] && (int) $matches[2] >= 1 && (int) $matches[2] <= 12 && self::preserve_expiry_day_zero() )
		) {
			$errors[] = __( 'Το EXP δεν είναι πραγματική ημερομηνία.', 'qr-rebuilder-pro' );
		} elseif ( ! self::year_survives_gs1_round_trip( (int) $matches[1] ) ) {
			/*
			 * GS1 stores the expiry as YYMMDD — two digits only. A year outside
			 * the rolling 100-year window cannot be recovered from those two
			 * digits: 2077 would be written as "77" and read back as 1977.
			 * Refuse to build a code that decodes to a different date than the
			 * one the user confirmed on screen.
			 */
			$range = self::gs1_year_range();

			$errors[] = sprintf(
				/* translators: 1: first supported year, 2: last supported year. */
				__( 'Το EXP πρέπει να ανήκει στα έτη %1$d-%2$d. Το GS1 αποθηκεύει μόνο τα δύο τελευταία ψηφία του έτους (YYMMDD), οπότε έτος εκτός αυτού του εύρους θα διαβαζόταν ως διαφορετική ημερομηνία κατά τη σάρωση.', 'qr-rebuilder-pro' ),
				$range['first'],
				$range['last']
			);
		} else {
			$clean['EXP'] = $exp;
		}

		/*
		 * Τα extras είναι descriptors που απέδειξε ο server, όχι επεξεργάσιμα πεδία·
		 * ξαναελέγχονται με τον πλήρη πίνακα πριν φτάσουν στην έξοδο.
		 */
		if ( ! is_array( $extras ) ) {
			$errors[] = __( 'Τα επιπλέον GS1 πεδία δεν έχουν έγκυρη μορφή passthrough.', 'qr-rebuilder-pro' );
		} else {
			$full_ai_table = self::full_ai_table();
			$used_extra_ais = array();

			foreach ( $extras as $descriptor ) {
				if (
					! is_array( $descriptor )
					|| ! isset( $descriptor['ai'], $descriptor['value'] )
					|| ! is_scalar( $descriptor['ai'] )
					|| ! is_scalar( $descriptor['value'] )
				) {
					$errors[] = __( 'Τα επιπλέον GS1 πεδία δεν έχουν έγκυρη μορφή passthrough.', 'qr-rebuilder-pro' );
					break;
				}

				$ai    = (string) $descriptor['ai'];
				$value = (string) $descriptor['value'];

				if (
					isset( $ai_table[ $ai ] )
					|| ! isset( $full_ai_table[ $ai ] )
					|| isset( $used_extra_ais[ $ai ] )
					|| ! self::value_matches_definition( $value, $full_ai_table[ $ai ] )
				) {
					$errors[] = __( 'Τα επιπλέον GS1 πεδία δεν έχουν έγκυρη μορφή passthrough.', 'qr-rebuilder-pro' );
					break;
				}

				$used_extra_ais[ $ai ] = true;
				$clean_extras[] = array(
					'ai'    => $ai,
					'value' => $value,
				);
			}
		}

		/* Το passthrough_raw είναι πάντα string, και στο σφάλμα. */
		if ( ! empty( $errors ) ) {
			return array(
				'raw'             => '',
				'errors'          => $errors,
				'passthrough_raw' => '',
			);
		}

		if ( empty( $clean_extras ) ) {
			return array(
				'raw'             => self::build( $clean ),
				'errors'          => array(),
				'passthrough_raw' => '',
			);
		}

		$built = self::build_with_extras( $clean, $clean_extras );

		return array(
			'raw'             => $built['raw'],
			'errors'          => array(),
			'passthrough_raw' => $built['passthrough_raw'],
		);
	}

	/**
	 * Σειρά 01·17·10·extras·21, με FNC1/GS μετά από κάθε AI που δεν είναι
	 * προκαθορισμένου μήκους, εκτός από το τελευταίο. Επιστρέφει και το
	 * σειριοποιημένο fragment των extras (passthrough_raw).
	 */
	private static function build_with_extras( $fields, $extras ) {
		$ai_table      = self::get_ai_table();
		$full_ai_table = self::full_ai_table();
		$sequence      = array();

		foreach ( array( '01', '17', '10' ) as $ai ) {
			$def   = $ai_table[ $ai ];
			$label = $def['label'];
			$value = isset( $fields[ $label ] ) ? (string) $fields[ $label ] : '';

			if ( 'date' === $def['type'] ) {
				$value = self::to_yymmdd( $value );
			}

			$sequence[] = array(
				'ai'    => $ai,
				'value' => $value,
				'def'   => $def,
			);
		}

		/* Τα όρια των extras μέσα στο $sequence, για το fragment. */
		$extras_from = count( $sequence );

		foreach ( $extras as $descriptor ) {
			$ai = (string) $descriptor['ai'];
			$sequence[] = array(
				'ai'    => $ai,
				'value' => (string) $descriptor['value'],
				'def'   => $full_ai_table[ $ai ],
			);
		}

		$extras_to = count( $sequence );

		$sequence[] = array(
			'ai'    => '21',
			'value' => isset( $fields['SN'] ) ? (string) $fields['SN'] : '',
			'def'   => $ai_table['21'],
		);

		$output      = '';
		$passthrough = '';
		$count       = count( $sequence );

		foreach ( $sequence as $index => $item ) {
			$chunk = $item['ai'] . $item['value'];

			if ( ! self::is_predefined_length_ai( $item['ai'] ) && $index < $count - 1 ) {
				$chunk .= self::GROUP_SEPARATOR;
			}

			$output .= $chunk;

			/*
			 * Το fragment βγαίνει από την ίδια σειριοποίηση με το raw, ώστε ο client να
			 * συνενώνει ακριβώς τα ίδια bytes, μαζί με τους GS τους.
			 */
			if ( $index >= $extras_from && $index < $extras_to ) {
				$passthrough .= $chunk;
			}
		}

		return array(
			'raw'             => $output,
			'passthrough_raw' => $passthrough,
		);
	}

	public static function gtin_check_digit_is_valid( $pc ) {
		$pc = is_scalar( $pc ) ? (string) $pc : '';
		if ( ! preg_match( '/^\d{14}$/', $pc ) ) {
			return false;
		}

		$sum = 0;
		for ( $i = 12, $weight = 3; $i >= 0; $i--, $weight = ( 3 === $weight ? 1 : 3 ) ) {
			$sum += (int) $pc[ $i ] * $weight;
		}

		return ( ( 10 - ( $sum % 10 ) ) % 10 ) === (int) $pc[13];
	}

	private static function scalar_trim( $value ) {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Μήνυμα ασάφειας που λέει τι να ελεγχθεί στη συσκευασία ανά πεδίο
	 * («SN = 1234 (εναλλακτικά: 12, 1)»), όχι απλώς πόσες ερμηνείες υπάρχουν.
	 * Η επιλεγμένη τιμή παρουσιάζεται ως η πιθανότερη, όχι ως ισότιμη. Μία
	 * πρόταση χωρίς HTML ή αλλαγές γραμμής (το JS την εμφανίζει ως κείμενο),
	 * με έως τρεις εναλλακτικές ανά πεδίο.
	 *
	 * @param array $analysis        Το αποτέλεσμα της assess_solutions().
	 * @param array $inferred_fields Πεδία με συναγόμενα όρια, ως εφεδρεία.
	 * @return string
	 */
	private static function ambiguity_warning( array $analysis, array $inferred_fields ) {
		$contested = isset( $analysis['contested_fields'] ) ? (array) $analysis['contested_fields'] : array();
		$chosen    = isset( $analysis['best'] ) ? (array) $analysis['best'] : array();
		$parts     = array();

		foreach ( self::REQUIRED_FIELDS as $label ) {
			if ( empty( $contested[ $label ] ) || ! isset( $chosen[ $label ] ) ) {
				continue;
			}

			$picked = (string) $chosen[ $label ];
			$others = array();

			foreach ( $contested[ $label ] as $value ) {
				if ( (string) $value !== $picked ) {
					$others[] = (string) $value;
				}
			}

			if ( empty( $others ) ) {
				continue;
			}

			$shown = array_slice( $others, 0, 3 );

			if ( count( $others ) > count( $shown ) ) {
				$shown[] = '…';
			}

			$parts[] = sprintf(
				/* translators: 1: field label, 2: the chosen value, 3: comma-separated alternative values. */
				__( '%1$s = %2$s (εναλλακτικά: %3$s)', 'qr-rebuilder-pro' ),
				$label,
				$picked,
				implode( ', ', $shown )
			);
		}

		/*
		 * Όταν η ασάφεια προέρχεται μόνο από επιπλέον AI, τα τέσσερα πεδία δεν
		 * διαφωνούν και δεν υπάρχουν εναλλακτικές τιμές: γενική διατύπωση.
		 */
		if ( empty( $parts ) ) {
			return sprintf(
				/* translators: 1: number of readings found, 2: fields to review. */
				__( 'Η ανάγνωση δεν ήταν μονοσήμαντη: βρέθηκαν %1$d πιθανές ερμηνείες. Επιλέχθηκε η πιθανότερη — ελέγξτε στη συσκευασία: %2$s.', 'qr-rebuilder-pro' ),
				(int) $analysis['alternative_count'],
				! empty( $inferred_fields ) ? implode( '/', $inferred_fields ) : 'SN/LOT'
			);
		}

		$safe = array();

		foreach ( self::REQUIRED_FIELDS as $label ) {
			if ( empty( $contested[ $label ] ) ) {
				$safe[] = $label;
			}
		}

		$message = sprintf(
			/* translators: %s: per-field list such as "SN = 1234 (alternatives: 12, 1)". */
			__( 'Η ανάγνωση δεν ήταν μονοσήμαντη. Το εργαλείο επέλεξε την πιθανότερη — ελέγξτε στη συσκευασία: %s.', 'qr-rebuilder-pro' ),
			implode( ' · ', $parts )
		);

		if ( ! empty( $safe ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: comma-separated field labels that are not in doubt. */
				__( 'Τα %s δεν αμφισβητούνται.', 'qr-rebuilder-pro' ),
				implode( ', ', $safe )
			);
		}

		return $message;
	}

	private static function assess_solutions( $solutions, $ai_table ) {
		$typical = array();
		foreach ( $ai_table as $def ) {
			if ( ! empty( $def['typical_length'] ) ) {
				$typical[ $def['label'] ] = (int) $def['typical_length'];
			}
		}

		$best            = array();
		$best_score      = null;
		$best_inferred   = PHP_INT_MAX;
		$best_fields     = 0;
		$seen            = array();
		$complete_seen   = array();
		$field_options   = array_fill_keys( self::REQUIRED_FIELDS, array() );
		$required_total  = count( self::REQUIRED_FIELDS );

		foreach ( $solutions as $solution ) {
			$signature = self::solution_signature( $solution );
			if ( isset( $seen[ $signature ] ) ) {
				continue;
			}
			$seen[ $signature ] = true;

			$field_count = 0;
			foreach ( self::REQUIRED_FIELDS as $required ) {
				$field_count += self::has_field_value( $solution, $required ) ? 1 : 0;
			}

			$inferred = isset( $solution['__inferred_boundaries'] ) ? (int) $solution['__inferred_boundaries'] : 0;

			/*
			 * A solution counts as a distinct *complete* interpretation only when
			 * every required field is present. Two solutions that yield the same
			 * PC/SN/LOT/EXP values (even via different inference paths) are the
			 * same interpretation and are collapsed by the value signature.
			 */
			if ( $field_count === $required_total ) {
				$complete_seen[ self::field_value_signature( $solution ) ] = true;

				/*
				 * Κρατάμε και τις τιμές ανά πεδίο, όχι μόνο το πλήθος των αναγνώσεων, ώστε
				 * το μήνυμα και το contested_fields να λένε τι να ελεγχθεί. Μόνο πλήρεις
				 * λύσεις είναι εναλλακτικές· μια ημιτελής είναι αποτυχία.
				 */
				foreach ( self::REQUIRED_FIELDS as $required ) {
					$value = (string) $solution[ $required ];

					if ( ! isset( $field_options[ $required ][ $value ] ) ) {
						$field_options[ $required ][ $value ] = true;
					}
				}
			}

			$score = ( $field_count * 10000 ) - ( $inferred * 500 );

			if ( self::has_field_value( $solution, 'PC' ) && self::gtin_check_digit_is_valid( $solution['PC'] ) ) {
				$score += 100;
			}

			foreach ( $typical as $label => $expected ) {
				if ( isset( $solution[ $label ] ) ) {
					$score -= abs( strlen( $solution[ $label ] ) - $expected );
				}
			}

			/*
			 * The heuristic score only decides which interpretation is presented
			 * first. On an exact tie prefer the reading with fewer inferred
			 * boundaries so the "best" pick stays deterministic.
			 */
			if (
				null === $best_score
				|| $score > $best_score
				|| ( $score === $best_score && $inferred < $best_inferred )
			) {
				$best_score    = $score;
				$best_inferred = $inferred;
				$best          = $solution;
				$best_fields   = $field_count;
			}
		}

		/*
		 * Ambiguity is driven by how many genuinely different *complete* GS1
		 * readings exist — not by heuristic-score ties. The score chooses the
		 * default; the count decides whether the user must confirm. This keeps
		 * typical SN/LOT lengths as a ranking hint without letting them turn a
		 * real fork into a silent auto-accept.
		 */
		$distinct_complete = count( $complete_seen );
		$ambiguous         = $distinct_complete > 1;

		$confidence = 'high';
		if ( $best_fields < $required_total || $distinct_complete > 3 ) {
			$confidence = 'low';
		} elseif ( $ambiguous ) {
			$confidence = 'medium';
		}

		/*
		 * Μόνο τα πεδία που πράγματι διαφωνούν μπαίνουν στη λίστα ελέγχου.
		 */
		$contested = array();

		foreach ( $field_options as $label => $values ) {
			if ( count( $values ) > 1 ) {
				/*
				 * Οι τιμές ήταν κλειδιά πίνακα και η PHP κάνει τα αριθμητικά κλειδιά int·
				 * το strval() κρατά τον τύπο string (και τα αρχικά μηδενικά στο JSON).
				 */
				$contested[ $label ] = array_map( 'strval', array_keys( $values ) );
			}
		}

		return array(
			'best'              => $best,
			'ambiguous'         => $ambiguous,
			'alternative_count' => max( 1, $distinct_complete ),
			'confidence'        => $confidence,
			'contested_fields'  => $contested,
		);
	}

	/** Signature of just the required GS1 field values (ignores inference metadata). */
	private static function field_value_signature( $solution ) {
		$parts = array();
		foreach ( self::REQUIRED_FIELDS as $label ) {
			$parts[] = $label . '=' . ( isset( $solution[ $label ] ) ? (string) $solution[ $label ] : '' );
		}

		return implode( '|', $parts );
	}

	private static function solution_signature( $solution ) {
		$parts = array();
		foreach ( self::REQUIRED_FIELDS as $label ) {
			$parts[] = $label . '=' . ( isset( $solution[ $label ] ) ? $solution[ $label ] : '' );
		}
		$parts[] = 'I=' . ( isset( $solution['__inferred_boundaries'] ) ? (int) $solution['__inferred_boundaries'] : 0 );

		$inferred_fields = isset( $solution['__inferred_fields'] ) && is_array( $solution['__inferred_fields'] )
			? $solution['__inferred_fields']
			: array();
		sort( $inferred_fields, SORT_STRING );
		$parts[] = 'IF=' . implode( ',', $inferred_fields );

		return implode( '|', $parts );
	}

	public static function build( $fields, $order = self::CANONICAL_ORDER ) {
		if ( ! is_array( $fields ) || ! is_array( $order ) ) {
			return '';
		}

		$ai_table    = self::get_ai_table();
		$valid_order = array();
		foreach ( $order as $ai ) {
			$ai = is_scalar( $ai ) ? (string) $ai : '';
			if ( isset( $ai_table[ $ai ] ) && ! in_array( $ai, $valid_order, true ) ) {
				$valid_order[] = $ai;
			}
		}

		$output = '';
		$count  = count( $valid_order );
		foreach ( $valid_order as $index => $ai ) {
			$def   = $ai_table[ $ai ];
			$label = $def['label'];
			$value = isset( $fields[ $label ] ) && is_scalar( $fields[ $label ] ) ? (string) $fields[ $label ] : '';

			if ( 'date' === $def['type'] ) {
				$value = self::to_yymmdd( $value );
			}

			$output .= $ai . $value;
			if ( ! self::is_predefined_length_ai( $ai ) && $index < $count - 1 ) {
				$output .= self::GROUP_SEPARATOR;
			}
		}

		return $output;
	}

	public static function format_yymmdd( $yymmdd, &$warnings = array() ) {
		if ( ! is_array( $warnings ) ) {
			$warnings = array();
		}

		$yymmdd = is_scalar( $yymmdd ) ? (string) $yymmdd : '';
		if ( ! preg_match( '/^\d{6}$/', $yymmdd ) ) {
			$warnings[] = __( 'Μη έγκυρη ημερομηνία λήξης (αναμένονται 6 ψηφία YYMMDD).', 'qr-rebuilder-pro' );
			return '';
		}

		$yy   = (int) substr( $yymmdd, 0, 2 );
		$mm   = (int) substr( $yymmdd, 2, 2 );
		$dd   = (int) substr( $yymmdd, 4, 2 );
		$year = self::yy_to_year( $yy );

		if ( $mm < 1 || $mm > 12 ) {
			$warnings[] = __( 'Μη έγκυρη ημερομηνία λήξης.', 'qr-rebuilder-pro' );
			return '';
		}

		if ( 0 === $dd ) {
			$first_day = DateTimeImmutable::createFromFormat( '!Y-m-d', sprintf( '%04d-%02d-01', $year, $mm ) );
			if ( ! $first_day ) {
				$warnings[] = __( 'Μη έγκυρη ημερομηνία λήξης.', 'qr-rebuilder-pro' );
				return '';
			}

			$last_day = (int) $first_day->format( 't' );

			/*
			 * 2.15.3: το «00» διατηρείται (ISO «YYYY-MM-00», YYMM00 στον κωδικό)· η
			 * λήξη κρίνεται με την τελευταία ημέρα του μήνα. Ουδέτερη διατύπωση,
			 * χωρίς ισχυρισμό για κανόνα GS1 που δεν έχει επαληθευτεί.
			 */
			if ( self::preserve_expiry_day_zero() ) {
				$warnings[] = sprintf(
					/* translators: 1: month/year, e.g. 02/2028, 2: last day of that month. */
					__( 'Η ημερομηνία λήξης δεν έχει ημέρα (DD=00): ισχύει έως το τέλος του μήνα %1$s (%2$s). Ο νέος κωδικός διατηρεί το «00» όπως στην αρχική σάρωση.', 'qr-rebuilder-pro' ),
					sprintf( '%02d/%04d', $mm, $year ),
					sprintf( '%04d-%02d-%02d', $year, $mm, $last_day )
				);

				return sprintf( '%04d-%02d-00', $year, $mm );
			}

			$dd         = $last_day;
			$warnings[] = sprintf(
				/* translators: 1: original scanned value, 2: proposed date. */
				__( 'ΠΡΟΣΟΧΗ — ο σαρωμένος κωδικός δεν περιείχε ημέρα λήξης: η αρχική τιμή ήταν %1$s (μόνο μήνας/έτος, DD=00). Προτείνεται %2$s (τέλος μήνα) — επιβεβαιώστε ή διορθώστε την πραγματική ημερομηνία πριν τη δημιουργία.', 'qr-rebuilder-pro' ),
				sprintf( '%04d-%02d-00', $year, $mm ),
				sprintf( '%04d-%02d-%02d', $year, $mm, $dd )
			);
		}

		if ( ! checkdate( $mm, $dd, $year ) ) {
			$warnings[] = __( 'Μη έγκυρη ημερομηνία λήξης.', 'qr-rebuilder-pro' );
			return '';
		}

		return sprintf( '%04d-%02d-%02d', $year, $mm, $dd );
	}

	/**
	 * Γυρίζει τις αμφισβητούμενες τιμές στη δημόσια μορφή τους.
	 *
	 * Εσωτερικά το EXP είναι YYMMDD (μορφή ταυτότητας), ενώ το fields['EXP']
	 * είναι ISO. Ο picker του JS γράφει την τιμή απευθείας σε <input type="date">,
	 * όπου ένα YYMMDD αδειάζει σιωπηλά το πεδίο. Η μετατροπή γίνεται εδώ, στο
	 * δημόσιο όριο, με την format_yymmdd() (ίδιος χειρισμός DD=00).
	 *
	 * @param array $contested Χάρτης πεδίο → λίστα υποψήφιων τιμών.
	 * @return array Ο ίδιος χάρτης με τιμές σε μορφή παρουσίασης.
	 */
	private static function contested_fields_for_display( array $contested ) {
		if ( ! isset( $contested['EXP'] ) || ! is_array( $contested['EXP'] ) ) {
			return $contested;
		}

		$display = array();

		foreach ( $contested['EXP'] as $value ) {
			$value = is_scalar( $value ) ? (string) $value : '';

			/* Τιμή ήδη σε ISO μένει ως έχει· δεύτερη μετατροπή θα την κατέστρεφε. */
			if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				$display[] = $value;
				continue;
			}

			/* Οι προειδοποιήσεις για την ημερομηνία δόθηκαν ήδη για την επιλεγμένη τιμή. */
			$ignored   = array();
			$display[] = self::format_yymmdd( $value, $ignored );
		}

		$contested['EXP'] = $display;

		return $contested;
	}


	public static function to_yymmdd( $iso_date ) {
		$iso_date = is_scalar( $iso_date ) ? (string) $iso_date : '';
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $iso_date, $matches ) ) {
			return $iso_date;
		}

		$year  = (int) $matches[1];
		$month = (int) $matches[2];
		$day   = (int) $matches[3];

		/* 2.15.3: «YYYY-MM-00» = GS1 DD=00 (τέλος μήνα) → YYMM00. */
		if ( 0 === $day && $month >= 1 && $month <= 12 ) {
			return substr( $matches[1], 2, 2 ) . $matches[2] . '00';
		}

		return checkdate( $month, $day, $year )
			? substr( $matches[1], 2, 2 ) . $matches[2] . $matches[3]
			: $iso_date;
	}

	public static function get_ai_table() {
		return self::default_ai_table();
	}

	/**
	 * Τρέχει την αναζήτηση με έναν πίνακα AI.
	 *
	 * Δύο ανεξάρτητα ταβάνια, MAX_SOLUTIONS (πλήθος λύσεων) και MAX_SEARCH_NODES
	 * (βήματα), καταλήγουν στην ίδια σημαία 'truncated': η αναζήτηση δεν
	 * ολοκληρώθηκε, άρα καμία αυτόματη αποδοχή.
	 *
	 * @return array { solutions, truncated }
	 */
	private static function collect_solutions( $raw, $ai_table ) {
		/*
		 * 2.15.2: cache ανά αίτημα PHP. Ένα rebuild ψάχνει το ίδιο raw με τον ίδιο
		 * πίνακα 3–8 φορές (parse, admissible_readings, passthrough). Το αποτέλεσμα
		 * εξαρτάται μόνο από raw, πίνακα και έτος αναφοράς του αιώνα (dates).
		 */
		static $cache = array();

		$key = md5( $raw ) . ':' . md5( serialize( $ai_table ) ) . ':'
			. self::century_reference_year();

		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		if ( count( $cache ) >= 8 ) {
			$cache = array();
		}

		$result = self::collect_solutions_uncached( $raw, $ai_table );

		/*
		 * 2.15.3: αποτέλεσμα κομμένο από τον κοινό προϋπολογισμό των «Σ» δεν
		 * μπαίνει στην cache· μια κανονική αναζήτηση του ίδιου raw έχει πλήρες ταβάνι.
		 */
		if ( null !== self::$variant_pool && $result['truncated'] ) {
			return $result;
		}

		$cache[ $key ] = $result;

		return $cache[ $key ];
	}

	/** Η πραγματική αναζήτηση· βλ. collect_solutions(). */
	private static function collect_solutions_uncached( $raw, $ai_table ) {
		$solutions = array();
		$truncated = false;

		$nodes = self::MAX_SEARCH_NODES;
		$work  = self::MAX_SEARCH_WORK;

		/* 2.15.3: μέσα σε παραλλαγές «Σ» ισχύει ό,τι απομένει στον κοινό προϋπολογισμό. */
		if ( null !== self::$variant_pool ) {
			$nodes = min( $nodes, self::$variant_pool['nodes'] );
			$work  = min( $work, self::$variant_pool['work'] );
		}

		self::$search_nodes_left       = $nodes;
		self::$search_work_left        = $work;
		self::$search_budget_exhausted = false;

		foreach ( self::backtrack( $raw, 0, array(), array(), $ai_table ) as $solution ) {
			$solutions[] = $solution;

			if ( count( $solutions ) >= self::MAX_SOLUTIONS ) {
				$truncated = true;
				break;
			}
		}

		if ( null !== self::$variant_pool ) {
			self::$variant_pool['nodes'] = max( 0, self::$variant_pool['nodes'] - ( $nodes - max( 0, self::$search_nodes_left ) ) );
			self::$variant_pool['work']  = max( 0, self::$variant_pool['work'] - ( $work - max( 0, self::$search_work_left ) ) );
		}

		return array(
			'solutions' => $solutions,
			'truncated' => $truncated || self::$search_budget_exhausted,
		);
	}

	/**
	 * AI που εμφανίζονται στην πράξη σε φαρμακευτικές συσκευασίες δίπλα στα
	 * 01/21/10/17. Χρησιμοποιείται μόνο όταν ένας integrator απενεργοποιήσει την
	 * αυστηρή ασάφεια (qrrp_strict_ambiguity = false)· ποτέ δεν αποφασίζει ότι
	 * ένα AI υπάρχει.
	 */
	const PHARMA_PLAUSIBLE_AIS = array(
		/*
		 * AI 22 (CPV): η GS1 το ζευγαρώνει ρητά με GTIN, οπότε ένα SN «ABC22X»
		 * χωρίς GS πρέπει να θεωρείται αμφίβολο. Η λίστα είναι content prior για
		 * σήμανση προς άνθρωπο, όχι δήλωση συμμόρφωσης.
		 */
		'22',
		/* Πρόσθετα αναγνωριστικά προϊόντος — ολόκληρη η ομάδα 2xx. */
		'235', '240', '241', '242', '243',
		'250', '251', '253', '254', '255',
		/* Ποσότητες. */
		'30', '37',
		/* Εθνικοί κωδικοί αποζημίωσης (NHRN) — Γερμανία, Γαλλία, Ισπανία, Πορτογαλία κ.ά. */
		'710', '711', '712', '713', '714', '715', '716',
		/* Ημερομηνίες/ώρες πέρα από το AI 17. */
		'7003', '7004', '7005', '7006', '7007',
		/* Λοιπά που συναντώνται σε ρυθμιζόμενα προϊόντα υγείας. */
		'8017', '8018', '8200',
	);

	/**
	 * Η λίστα PHARMA_PLAUSIBLE_AIS μετά το φίλτρο qrrp_pharma_plausible_ais.
	 * Το φίλτρο μπορεί μόνο να προσθέσει AI (περισσότερη προσοχή, ποτέ λιγότερη).
	 */
	private static function pharma_plausible_ais() {
		$base = self::PHARMA_PLAUSIBLE_AIS;

		/*
		 * Η βάση διατηρείται πάντα (array_merge)· από το φίλτρο γίνονται δεκτά μόνο
		 * scalar που μοιάζουν με AI (2-4 ψηφία), τα υπόλοιπα αγνοούνται.
		 */
		$filtered = apply_filters( 'qrrp_pharma_plausible_ais', $base );

		if ( ! is_array( $filtered ) ) {
			return $base;
		}

		$additions = array();

		foreach ( $filtered as $ai ) {
			if ( ! is_scalar( $ai ) ) {
				continue;
			}

			$ai = (string) $ai;

			if ( preg_match( '/^\d{2,4}$/', $ai ) ) {
				$additions[] = $ai;
			}
		}

		return array_values( array_unique( array_merge( $base, $additions ) ) );
	}

	/**
	 * Προσθέτει τις τιμές των ανταγωνιστικών αναγνώσεων στις αμφισβητούμενες.
	 *
	 * Δεν κρίνει ποια είναι σωστή: καταγράφει μόνο ποιες τιμές διεκδικούν κάθε
	 * πεδίο. Κενή τιμή στον ανταγωνιστή (πεδίο που δεν παράγει) δεν είναι
	 * εναλλακτική. Η επιλεγμένη τιμή μπαίνει πρώτη σε κάθε λίστα.
	 *
	 * @param array $contested Οι εναλλακτικές από την assess_solutions().
	 * @param array $chosen    Η υπογραφή της επιλεγμένης ανάγνωσης.
	 * @param array $rivals    Οι υπογραφές των ανταγωνιστών.
	 * @return array
	 */
	private static function merge_rival_signatures( array $contested, array $chosen, array $rivals ) {
		foreach ( $rivals as $rival ) {
			if ( ! is_array( $rival ) ) {
				continue;
			}

			foreach ( self::REQUIRED_FIELDS as $label ) {
				$other = isset( $rival[ $label ] ) ? (string) $rival[ $label ] : '';
				$mine  = isset( $chosen[ $label ] ) ? (string) $chosen[ $label ] : '';

				if ( '' === $other || $other === $mine ) {
					continue;
				}

				if ( empty( $contested[ $label ] ) ) {
					$contested[ $label ] = ( '' === $mine ) ? array() : array( $mine );
				}

				if ( ! in_array( $other, $contested[ $label ], true ) ) {
					$contested[ $label ][] = $other;
				}
			}
		}

		/*
		 * Κανονικοποίηση σε κάθε πεδίο, και σε όσα ήρθαν έτοιμα από την
		 * assess_solutions(): η επιλεγμένη τιμή πρώτη (είναι αυτή που δείχνει ήδη
		 * η φόρμα), χωρίς διπλότυπα, και πεδίο με μία τιμή δεν αμφισβητείται.
		 */
		foreach ( $contested as $label => $values ) {
			$mine       = isset( $chosen[ $label ] ) ? (string) $chosen[ $label ] : '';
			$normalized = array();

			if ( '' !== $mine ) {
				$normalized[] = $mine;
			}

			foreach ( (array) $values as $value ) {
				$value = (string) $value;

				if ( '' === $value || in_array( $value, $normalized, true ) ) {
					continue;
				}

				$normalized[] = $value;
			}

			/* Πεδίο με μία μόνο υποψήφια τιμή δεν αμφισβητείται. */
			if ( count( $normalized ) < 2 ) {
				unset( $contested[ $label ] );
				continue;
			}

			$contested[ $label ] = $normalized;
		}

		return $contested;
	}

	/** Οι τέσσερις τιμές μιας ανάγνωσης, για σύγκριση δύο αναγνώσεων. */
	private static function target_signature( $fields ) {
		$signature = array();

		foreach ( self::REQUIRED_FIELDS as $label ) {
			$signature[ $label ] = ( isset( $fields[ $label ] ) && is_scalar( $fields[ $label ] ) )
				? (string) $fields[ $label ]
				: '';
		}

		return $signature;
	}

	/**
	 * Το EXP σε μορφή ταυτότητας (YYMMDD). Δέχεται και ISO, επειδή οι
	 * συγκρινόμενες αναγνώσεις έρχονται από διαφορετικές διαδρομές (parser: YYMMDD,
	 * HTTP/email token: ISO). Ανύπαρκτη ISO ημερομηνία μένει ως έχει: η ταυτότητα
	 * συγκρίνει, δεν επικυρώνει.
	 */
	private static function expiry_identity( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';

		return self::to_yymmdd( $value );
	}

	/**
	 * Η ταυτότητα μιας ανάγνωσης ως σταθερή συμβολοσειρά:
	 *
	 *     PC=08006540718100|SN=YN68XRRFDZP|LOT=NK4032|EXP=280331
	 *
	 * Απαντά στο «είναι αυτά τα τέσσερα η ίδια ανάγνωση;» για το membership, το
	 * binding του email token, το tuple_fp του challenge και το changed_fields.
	 * Περιλαμβάνει μόνο τις τιμές, όχι το πώς προέκυψαν. Το «|» είναι εκτός
	 * charset 82, άρα η κωδικοποίηση είναι μονοσήμαντη· η σειρά των πεδίων είναι
	 * μέρος του wire contract. Εσωτερική μορφή (EXP σε YYMMDD), δεν στέλνεται
	 * στον client.
	 *
	 * @param array $fields Πεδία με κεφαλαία κλειδιά· το EXP σε ISO ή YYMMDD.
	 * @return string Η ταυτότητα· πεδία που λείπουν γίνονται κενά.
	 */
	public static function canonical_reading_signature( $fields ) {
		$signature = self::target_signature( is_array( $fields ) ? $fields : array() );

		$signature['EXP'] = self::expiry_identity(
			isset( $signature['EXP'] ) ? $signature['EXP'] : ''
		);

		return self::field_value_signature( $signature );
	}

	/**
	 * Return extra GS1 AIs only when the accepted four-field reading proves them:
	 * a complete search, at least one solution matching the accepted reading,
	 * and exact ordered consensus across every matching solution. When the
	 * matching solutions disagree and the scan carries real Group Separators,
	 * the single strict reading (no inferred boundary, as any GS1 decoder
	 * reads the symbol) decides. Otherwise the result is unproven and
	 * unproven_ais lists the extra AIs at stake.
	 *
	 * @param string $raw           Original trusted source raw/HRI.
	 * @param array  $anchor_fields Accepted PC/SN/LOT/EXP reading.
	 * @return array { proven: bool, extras: array, truncated: bool, unproven_ais?: string[] }
	 */
	public static function passthrough_for_reading( $raw, $anchor_fields ) {
		$result = array(
			'proven'    => false,
			'extras'    => array(),
			'truncated' => false,
		);

		if ( ! is_scalar( $raw ) || ! is_array( $anchor_fields ) ) {
			return $result;
		}

		$normalization = self::normalize_scan_input( (string) $raw, self::get_ai_table() );
		$outcome       = self::passthrough_in_normalization( $normalization, $anchor_fields );

		/*
		 * 2.15.3: με «Σ» (S ή W) κάθε παραλλαγή είναι admissible. Τα extras
		 * αποδεικνύονται μόνο αν συμφωνούν όλες οι παραλλαγές που δίνουν την
		 * ίδια ανάγνωση· ένα «Σ» μέσα σε extra AI τα αφήνει αναπόδεικτα.
		 */
		if ( ! empty( $normalization['meta']['ambiguous_sigma'] ) ) {
			if ( $outcome['result']['truncated'] ) {
				return $outcome['result'];
			}

			$matched = $outcome['matched'] > 0 ? array( $outcome['result'] ) : array();
			$opened  = self::open_variant_budget();

			try {
				$plan = self::sigma_plan( (string) $raw, $normalization );

				foreach ( self::alternative_normalizations( (string) $raw, $normalization['meta'], $plan['variants'] ) as $variant ) {
					$other = self::passthrough_in_normalization( $variant, $anchor_fields );

					if ( $other['result']['truncated'] ) {
						return $other['result'];
					}

					if ( $other['matched'] > 0 ) {
						$matched[] = $other['result'];
					}
				}
			} finally {
				self::close_variant_budget( $opened );
			}

			$combined = empty( $matched ) ? $outcome['result'] : self::combine_passthrough_results( $matched );

			/*
			 * «Σ» εκτός PC/SN/LOT/EXP της ανάγνωσης (anchor) βρίσκεται σε επιπλέον
			 * AI: S ή W δεν αποδεικνύεται, άρα ούτε τα extras.
			 */
			if ( ! empty( $combined['proven'] ) && ! empty( $combined['extras'] ) ) {
				$covered = $plan['known']
					? count( self::sigma_offsets_in_core( (string) $normalization['raw'], $plan['offsets'], $anchor_fields ) )
					: -1;

				if ( $covered < count( $plan['offsets'] ) || ! $plan['known'] ) {
					$combined = self::combine_passthrough_results(
						array(
							$combined,
							array(
								'proven'    => false,
								'extras'    => array(),
								'truncated' => false,
							),
						)
					);
				}
			}

			return $combined;
		}

		/* Η ανάγνωση με το «<GS>» ως κείμενο τιμής είναι εξίσου admissible. */
		if (
			0 === $outcome['matched']
			&& ! $outcome['result']['truncated']
			&& ! empty( $normalization['meta']['literal_gs_text'] )
		) {
			$literal = self::normalize_scan_input( (string) $raw, self::get_ai_table(), false );
			$outcome = self::passthrough_in_normalization( $literal, $anchor_fields );
		}

		return $outcome['result'];
	}

	/**
	 * 2.15.3: οι admissible κανονικοποιήσεις πέρα από την προεπιλεγμένη:
	 * «<GS>» ως κείμενο τιμής και κάθε παραλλαγή S/W των «Σ».
	 *
	 * @param string   $source Η είσοδος όπως ήρθε.
	 * @param array    $meta   Τα meta της προεπιλεγμένης κανονικοποίησης.
	 * @param string[] $sigma  Οι παραλλαγές S/W από την sigma_plan() (μόνο «Σ» των PC/SN/LOT/EXP).
	 * @return array Λίστα κανονικοποιήσεων (μη κενό raw).
	 */
	private static function alternative_normalizations( $source, array $meta, array $sigma = array() ) {
		$gs_modes = array( true );

		if ( ! empty( $meta['literal_gs_text'] ) ) {
			$gs_modes[] = false;
		}

		$letters_list = array_merge( array( '' ), $sigma );
		$variants     = array();

		foreach ( $letters_list as $letters ) {
			foreach ( $gs_modes as $gs_as_separator ) {
				/* Η προεπιλογή (όλα S, «<GS>» ως διαχωριστής) δεν επαναλαμβάνεται. */
				if ( '' === $letters && $gs_as_separator ) {
					continue;
				}

				$variant = self::normalize_scan_input( $source, self::get_ai_table(), $gs_as_separator, $letters );

				if ( '' !== $variant['raw'] ) {
					$variants[] = $variant;
				}
			}
		}

		return $variants;
	}

	/**
	 * 2.15.3: ενώνει αποτελέσματα passthrough για την ίδια ανάγνωση από
	 * διαφορετικές κανονικοποιήσεις. Απόδειξη μόνο με πλήρη συμφωνία.
	 *
	 * @param array $results Αποτελέσματα με matched > 0.
	 * @return array { proven, extras, truncated, unproven_ais? }
	 */
	private static function combine_passthrough_results( array $results ) {
		$first = $results[0];
		$agree = true;
		$ais   = array();

		foreach ( $results as $result ) {
			if ( empty( $result['proven'] ) || serialize( $result['extras'] ) !== serialize( $first['extras'] ) ) {
				$agree = false;
			}

			foreach ( (array) $result['extras'] as $extra ) {
				$ais[ (string) $extra['ai'] ] = true;
			}

			foreach ( isset( $result['unproven_ais'] ) ? (array) $result['unproven_ais'] : array() as $ai ) {
				$ais[ (string) $ai ] = true;
			}
		}

		if ( $agree ) {
			return $first;
		}

		$unproven = array_map( 'strval', array_keys( $ais ) );
		sort( $unproven, SORT_STRING );

		return array(
			'proven'       => false,
			'extras'       => array(),
			'truncated'    => false,
			'unproven_ais' => $unproven,
		);
	}

	/**
	 * Η απόδειξη των extras μέσα σε μία συγκεκριμένη κανονικοποίηση.
	 *
	 * @return array { result: {proven, extras, truncated}, matched: int }
	 */
	private static function passthrough_in_normalization( $normalization, $anchor_fields ) {
		$result = array(
			'proven'    => false,
			'extras'    => array(),
			'truncated' => false,
		);
		$matched = 0;

		$normalized = isset( $normalization['raw'] ) ? (string) $normalization['raw'] : '';

		if ( '' === $normalized ) {
			return array( 'result' => $result, 'matched' => 0 );
		}

		$complete            = self::complete_readings( $normalized );
		$result['truncated'] = ! empty( $complete['truncated'] );

		if ( $result['truncated'] ) {
			return array( 'result' => $result, 'matched' => 0 );
		}

		$anchor_signature = self::canonical_reading_signature( $anchor_fields );
		$consensus        = null;
		$meta             = isset( $normalization['meta'] ) && is_array( $normalization['meta'] )
			? $normalization['meta']
			: array();
		$is_hri           = isset( $meta['input_mode'] ) && 'parenthesized_hri' === $meta['input_mode'];
		$hri_extras       = $is_hri && isset( $meta['hri_extra_values'] ) && is_array( $meta['hri_extra_values'] )
			? array_values( $meta['hri_extra_values'] )
			: array();

		$disagreement = false;
		$strict       = array();
		$seen_ais     = array();

		foreach ( $complete['solutions'] as $solution ) {
			if ( self::canonical_reading_signature( $solution ) !== $anchor_signature ) {
				continue;
			}

			$matched++;
			$extras = array();

			if ( $is_hri ) {
				$extras = $hri_extras;
			} else {
				foreach ( $solution as $key => $value ) {
					$key = (string) $key;

					if ( 0 !== strpos( $key, '__ai_' ) ) {
						continue;
					}

					$extras[] = array(
						'ai'    => substr( $key, 5 ),
						'value' => is_scalar( $value ) ? (string) $value : '',
					);
				}
			}

			foreach ( $extras as $extra ) {
				$seen_ais[ $extra['ai'] ] = true;
			}

			/* Ανάγνωση που σέβεται κάθε GS της σάρωσης: κανένα όριο δεν μαντεύτηκε. */
			if ( empty( $solution['__inferred_boundaries'] ) ) {
				$strict[] = $extras;
			}

			if ( null === $consensus ) {
				$consensus = $extras;
				continue;
			}

			if ( $consensus !== $extras ) {
				$disagreement = true;
			}
		}

		/*
		 * 2.15.3: HRI με αμφίσημο όριο. Η ανάγνωση (anchor) επιλέγει τα extras: με
		 * SN «AB» το (90) είναι extra, με SN «AB(90)CD» όχι. Αν ίδια ανάγνωση
		 * δίνει διαφορετικά extras (AI μέσα σε τιμή άλλου extra), δεν αποδεικνύονται.
		 */
		if ( $is_hri && ! empty( $meta['hri_split_alternatives'] ) ) {
			$candidates = $matched > 0 ? array( serialize( $hri_extras ) => $hri_extras ) : array();

			foreach ( (array) $meta['hri_split_alternatives'] as $alternative ) {
				if ( self::canonical_reading_signature( $alternative['fields'] ) === $anchor_signature ) {
					$candidates[ serialize( $alternative['extras'] ) ] = $alternative['extras'];
					$matched++;
				}
			}

			if ( count( $candidates ) > 1 ) {
				$ais = array();

				foreach ( $candidates as $extras ) {
					foreach ( $extras as $extra ) {
						$ais[ (string) $extra['ai'] ] = true;
					}
				}

				$result['unproven_ais'] = array_map( 'strval', array_keys( $ais ) );
				sort( $result['unproven_ais'], SORT_STRING );

				return array( 'result' => $result, 'matched' => $matched );
			}

			if ( 1 === count( $candidates ) ) {
				$result['proven'] = true;
				$result['extras'] = array_values( $candidates )[0];

				return array( 'result' => $result, 'matched' => $matched );
			}
		}

		if ( 0 === $matched ) {
			return array( 'result' => $result, 'matched' => 0 );
		}

		/*
		 * Διαφωνία μεταξύ αναγνώσεων. Όταν η σάρωση περιέχει πραγματικούς GS,
		 * ισχύει η αυστηρή ανάγνωση: κάθε πεδίο μεταβλητού μήκους τελειώνει στον
		 * GS του ή στο τέλος, όπως το διαβάζει κάθε τυπικός αποκωδικοποιητής GS1.
		 * Οι υπόλοιπες αναγνώσεις προϋποθέτουν GS που λείπει. Αν δεν υπάρχει
		 * μοναδική αυστηρή ανάγνωση, τίποτα δεν αποδεικνύεται και ο καλών
		 * παίρνει τα AI που διακυβεύονται (unproven_ais), ώστε να αρνηθεί ρητά.
		 */
		if ( $disagreement ) {
			$has_separator  = ! $is_hri && false !== strpos( $normalized, self::GROUP_SEPARATOR );
			$strict_outcome = array_values( array_unique( array_map( 'serialize', $strict ) ) );

			if ( ! $has_separator || 1 !== count( $strict_outcome ) ) {
				/* Τα αριθμητικά κλειδιά PHP γίνονται int· τα AI επιστρέφονται ως string. */
				$result['unproven_ais'] = array_map( 'strval', array_keys( $seen_ais ) );
				sort( $result['unproven_ais'], SORT_STRING );

				return array( 'result' => $result, 'matched' => $matched );
			}

			$consensus = $strict[0];
		}

		$result['proven'] = true;
		$result['extras'] = null === $consensus ? array() : $consensus;

		return array( 'result' => $result, 'matched' => $matched );
	}

	/**
	 * Membership oracle: ποιες πλήρεις αναγνώσεις επιτρέπει αυτή η σάρωση.
	 *
	 * Χρησιμοποιεί πάντα τον πλήρη πίνακα AI (μια ανάγνωση μέσω AI 240 ή 714
	 * είναι εξίσου πραγματική) και την ίδια κανονικοποίηση με την parse(). Όταν
	 * η είσοδος έχει κείμενο «<GS>», περιλαμβάνονται και οι αναγνώσεις όπου
	 * είναι μέρος τιμής. Το truncated ξεχωρίζει το «δεν ταιριάζει» (πλήρες
	 * σύνολο) από το «άγνωστο» (κομμένη αναζήτηση). Το EXP είναι YYMMDD.
	 *
	 * @param string $raw Το raw της σάρωσης, όπως ήρθε.
	 * @return array { readings: list<array{PC,SN,LOT,EXP}>, truncated: bool }
	 */
	public static function admissible_readings( $raw ) {
		$source = is_scalar( $raw ) ? (string) $raw : '';

		$normalization = self::normalize_scan_input( $source, self::get_ai_table() );
		$raw           = $normalization['raw'];

		/*
		 * Τίποτα αναγνώσιμο: κενό και πλήρες σύνολο (ο καλών το ξεχωρίζει από το
		 * «δεν ταιριάζει» με δικό του έλεγχο).
		 */
		if ( '' === $raw ) {
			return array(
				'readings'  => array(),
				'truncated' => false,
			);
		}

		$complete = self::complete_readings( $raw );
		$readings = array();
		$meta     = $normalization['meta'];

		/*
		 * Με «<GS>» στην είσοδο είναι admissible και η ανάγνωση όπου είναι κείμενο
		 * τιμής. 2.15.3: με «Σ» και οι παραλλαγές S/W των PC/SN/LOT/EXP, όλες με
		 * κοινό προϋπολογισμό· κομμένο σύνολο είναι ήδη «άγνωστο», οπότε τότε
		 * δεν δοκιμάζονται.
		 */
		$opened = empty( $meta['ambiguous_sigma'] ) ? false : self::open_variant_budget();

		try {
			$sigma = ( ! empty( $meta['ambiguous_sigma'] ) && ! $complete['truncated'] )
				? self::sigma_plan( $source, $normalization )['variants']
				: array();

			foreach ( self::alternative_normalizations( $source, $meta, $sigma ) as $variant ) {
				$alternative           = self::complete_readings( $variant['raw'] );
				$complete['solutions'] = array_merge( $complete['solutions'], $alternative['solutions'] );
				$complete['truncated'] = $complete['truncated'] || $alternative['truncated'];
			}
		} finally {
			self::close_variant_budget( $opened );
		}

		/*
		 * 2.15.3: στο HRI, ένα επιπλέον AI μετά από τιμή μεταβλητού μήκους μπορεί
		 * να είναι μέρος της τιμής· και αυτή η ανάγνωση είναι admissible.
		 */
		if ( ! empty( $normalization['meta']['hri_split_alternatives'] ) ) {
			foreach ( (array) $normalization['meta']['hri_split_alternatives'] as $alternative ) {
				if ( self::all_required_fields_present( $alternative['fields'] ) ) {
					$complete['solutions'][] = $alternative['fields'];
				}
			}
		}

		/*
		 * Dedupe με την ταυτότητα: ίδιες τέσσερις τιμές είναι η ίδια ανάγνωση,
		 * όπως κι αν προέκυψαν.
		 */
		foreach ( $complete['solutions'] as $solution ) {
			$signature = self::target_signature( $solution );

			$readings[ self::canonical_reading_signature( $signature ) ] = $signature;
		}

		/*
		 * Το όριο εξόδου εφαρμόζεται μετά το dedupe· αν κόψει, το truncated γίνεται true.
		 */
		$output_truncated = count( $readings ) > self::MAX_ADMISSIBLE_READINGS;

		if ( $output_truncated ) {
			$readings = array_slice(
				$readings,
				0,
				self::MAX_ADMISSIBLE_READINGS,
				true
			);
		}

		return array(
			'readings'  => array_values( $readings ),
			'truncated' => (bool) $complete['truncated'] || $output_truncated,
		);
	}


	/**
	 * Πλήρεις αναγνώσεις του πλήρους πίνακα AI που διαφωνούν με την επιλεγμένη.
	 *
	 * Το πέρασμα 2 τρέχει μόνο όταν το πέρασμα 1 δεν βρει πλήρη ανάγνωση, οπότε
	 * χωρίς GS μια ανάγνωση των τεσσάρων AI μπορεί να κρύβει άλλη εξίσου έγκυρη:
	 *
	 *     010800654071810021ABC240XYZ10LOT117280331
	 *     21 ABC240XYZ   ή   21 ABC · 240 XYZ
	 *
	 * Δεν υπάρχει τρόπος να αποδειχθεί ποια ισχύει, άρα η ασάφεια δηλώνεται.
	 * Τρέχει μόνο όταν έχει συναχθεί όριο.
	 *
	 * @return array { conflicts: list<array>, truncated: bool }
	 */
	private static function conflicting_extra_ai_readings( $raw, $fields, $chosen_inferred ) {
		$chosen    = self::target_signature( $fields );
		$cross     = self::complete_readings( $raw );
		$conflicts = array();

		/*
		 * Fail closed: ημιτελής έλεγχος δεν είναι απόδειξη απουσίας σύγκρουσης,
		 * γι' αυτό επιστρέφεται και το truncated.
		 */
		$truncated = ! empty( $cross['truncated'] );

		/* Μία φορά έξω από τον βρόχο, για να μην τρέχει το φίλτρο ανά λύση. */
		$plausible_ais = self::pharma_plausible_ais();

		/*
		 * Αυστηρή ασάφεια (default): κάθε πλήρης, συντακτικά έγκυρη ανταγωνιστική
		 * ανάγνωση που αλλάζει τα πεδία ακυρώνει την αυτόματη αποδοχή, π.χ.
		 * «21ABC403210LOT1…» = SN «ABC4032» ή SN «ABC» + AI 403 «2». Ένας integrator
		 * μπορεί ρητά να επιστρέψει στο παλιό content prior με
		 * add_filter( 'qrrp_strict_ambiguity', '__return_false' ).
		 */
		$strict = (bool) apply_filters( 'qrrp_strict_ambiguity', true );

		/* Η πληρότητα ελέγχθηκε ήδη στην complete_readings(). */
		foreach ( $cross['solutions'] as $solution ) {

			/*
			 * Μόνο με strict=false: ένας ανταγωνιστής αγνοείται όταν έχει extra AI εκτός
			 * PHARMA_PLAUSIBLE_AIS και χρειάζεται περισσότερα συναγόμενα όρια. Heuristic,
			 * όχι απόδειξη ότι η εναλλακτική είναι λάθος.
			 */
			$rival_extras = array();

			foreach ( $solution as $key => $ignored ) {
				if ( 0 === strpos( (string) $key, '__ai_' ) ) {
					$rival_extras[] = substr( (string) $key, 5 );
				}
			}

			$solution_inferred = isset( $solution['__inferred_boundaries'] )
				? (int) $solution['__inferred_boundaries']
				: 0;

			$plausible = ! empty( $rival_extras );

			foreach ( $rival_extras as $rival_ai ) {
				if ( ! in_array( $rival_ai, $plausible_ais, true ) ) {
					$plausible = false;
					break;
				}
			}

			if ( ! $strict && ! $plausible && $solution_inferred > (int) $chosen_inferred ) {
				continue;
			}

			$signature = self::target_signature( $solution );

			if ( $signature === $chosen ) {
				continue;
			}

			$conflicts[ implode( '|', $signature ) ] = $signature;

			/* Two examples are enough to prove ambiguity; stop collecting. */
			if ( count( $conflicts ) >= 2 ) {
				break;
			}
		}

		return array(
			'conflicts' => array_values( $conflicts ),
			'truncated' => $truncated,
		);
	}

	/**
	 * Όλες οι λύσεις του πλήρους πίνακα AI που έχουν και τα τέσσερα πεδία.
	 *
	 * Κοινός ορισμός για την conflicting_extra_ai_readings() και την
	 * admissible_readings(). Κανένα φιλτράρισμα εδώ· οι λύσεις κρατούν τα
	 * μεταδεδομένα τους (__ai_*, __inferred_boundaries) για τον καλούντα.
	 *
	 * @param string $raw Κανονικοποιημένο GS1 payload.
	 * @return array { solutions: list<array>, truncated: bool }
	 */
	private static function complete_readings( $raw ) {
		$cross    = self::collect_solutions( $raw, self::full_ai_table() );
		$complete = array();

		foreach ( $cross['solutions'] as $solution ) {
			if ( self::all_required_fields_present( $solution ) ) {
				$complete[] = $solution;
			}
		}

		return array(
			'solutions' => $complete,
			'truncated' => ! empty( $cross['truncated'] ),
		);
	}

	/**
	 * 2.15.7: true όταν κάποιος Group Separator κλείνει τιμή μεταβλητού μήκους
	 * στην αυστηρή ανάγνωση (μεταβλητή τιμή έως τον επόμενο GS). GS μόνο μετά από
	 * πεδία σταθερού μήκους (π.χ. 01<GS>) δεν δείχνει όρια μεταβλητών πεδίων.
	 * Αν η αυστηρή ανάγνωση σταματήσει πριν περάσει όλους τους GS, επιστρέφει
	 * true (συντηρητικά).
	 */
	private static function has_terminating_separator( $raw ) {
		if ( false === strpos( $raw, self::GROUP_SEPARATOR ) ) {
			return false;
		}

		$ai_table = self::full_ai_table();
		$len      = strlen( $raw );
		$pos      = 0;

		while ( $pos < $len ) {
			if ( self::GROUP_SEPARATOR === $raw[ $pos ] ) {
				++$pos;
				continue;
			}

			$matched = self::match_ai( $raw, $pos, $ai_table );
			if ( null === $matched ) {
				break;
			}

			list( $ai, $def ) = $matched;
			$value_pos        = $pos + strlen( $ai );

			if ( $def['length'] > 0 ) {
				$pos = $value_pos + (int) $def['length'];
				continue;
			}

			$gs_pos = strpos( $raw, self::GROUP_SEPARATOR, $value_pos );
			if ( false === $gs_pos ) {
				return false;
			}

			if ( $gs_pos > $value_pos ) {
				return true;
			}

			$pos = $gs_pos + 1;
		}

		return false !== strpos( $raw, self::GROUP_SEPARATOR, min( $pos, $len ) );
	}

	/** True when at least one reading contains every required field. */
	private static function has_complete_solution( $solutions ) {
		foreach ( $solutions as $solution ) {
			if ( self::all_required_fields_present( $solution ) ) {
				return true;
			}
		}

		return false;
	}

	private static function backtrack( $raw, $pos, $fields, $used, $ai_table ) {
		/*
		 * Το ταβάνι μετριέται σε κάθε κόμβο, γιατί η έκρηξη ζει στα κλαδιά που δεν
		 * δίνουν λύση. Μετά την εξάντληση η αναδρομή ξετυλίγεται κρατώντας ό,τι βρέθηκε.
		 */
		if ( self::$search_budget_exhausted ) {
			return;
		}

		if ( --self::$search_nodes_left < 0 ) {
			self::$search_budget_exhausted = true;
			return;
		}

		$len = strlen( $raw );
		if ( $pos === $len ) {
			yield $fields;
			return;
		}

		if ( $pos > $len - 2 ) {
			return;
		}

		$matched = self::match_ai( $raw, $pos, $ai_table );
		if ( null === $matched || isset( $used[ $matched[0] ] ) ) {
			return;
		}

		list( $ai, $def )  = $matched;
		$store_key         = self::ai_storage_key( $ai, $def );
		$report_label      = self::ai_report_label( $ai, $def );
		$value_pos         = $pos + strlen( $ai );
		$used_next         = $used;
		$used_next[ $ai ] = true;

		if ( $def['length'] > 0 ) {
			$value = substr( $raw, $value_pos, $def['length'] );
			if ( strlen( $value ) !== $def['length'] || ! self::value_matches_definition( $value, $def ) ) {
				return;
			}

			$next_pos    = $value_pos + $def['length'];
			$next_fields = $fields;
			$next_fields[ $store_key ] = $value;

			/*
			 * Μετά από AI προκαθορισμένου μήκους ο GS είναι περιττός· μετά από
			 * κάθε άλλο σταθερού μήκους είναι υποχρεωτικός. Η τιμή διαβάζεται
			 * σωστά και όταν ο υποχρεωτικός GS λείπει.
			 */
			if ( $next_pos < $len && self::GROUP_SEPARATOR === $raw[ $next_pos ] ) {
				++$next_pos;

				if ( self::is_predefined_length_ai( $ai ) ) {
					$next_fields['__redundant_separators'] = isset( $next_fields['__redundant_separators'] )
						? ( (int) $next_fields['__redundant_separators'] + 1 )
						: 1;
				}
			}

			yield from self::backtrack( $raw, $next_pos, $next_fields, $used_next, $ai_table );
			return;
		}

		$gs_pos = strpos( $raw, self::GROUP_SEPARATOR, $value_pos );
		if ( false !== $gs_pos ) {
			$value = substr( $raw, $value_pos, $gs_pos - $value_pos );

			/*
			 * Primary interpretation: the value runs up to the next Group
			 * Separator. This is the exact, high-confidence reading when the
			 * separators are all present.
			 */
			if ( self::value_matches_definition( $value, $def ) ) {
				$next_fields = $fields;
				$next_fields[ $store_key ] = $value;
				if ( 'SN' === $def['label'] ) {
					$next_fields['__sn_exact'] = true;
				}

				yield from self::backtrack( $raw, $gs_pos + 1, $next_fields, $used_next, $ai_table );
			}

			/*
			 * Continue with the length scan: a later GS does not prove the value runs up
			 * to it, because an earlier separator may be missing. Candidates that would
			 * swallow the GS are rejected by value_matches_type(), so both readings stay
			 * distinct and are compared in assess_solutions().
			 */
		}

		$upper = min( (int) $def['max'], $len - $value_pos );
		for ( $length = $upper; $length >= 1; $length-- ) {
			/*
			 * 2.15.2: κάθε υποψήφιο μήκος χρεώνεται σε δεύτερο ταβάνι. Το ταβάνι
			 * κόμβων μόνο δεν αρκεί: κάθε κόμβος κάνει έως ~90 δοκιμές (match_ai,
			 * substr, regex), και μια κατασκευασμένη είσοδος 4 KB κόστιζε ~1 s ανά
			 * αναζήτηση. Εξάντληση = truncated, όπως το ταβάνι κόμβων.
			 */
			if ( --self::$search_work_left < 0 ) {
				self::$search_budget_exhausted = true;
				return;
			}

			$next_pos   = $value_pos + $length;
			$is_terminal = $next_pos === $len;

			/* Prune impossible splits before validating/copying candidate data. */
			if ( ! $is_terminal ) {
				if ( $next_pos > $len - 2 ) {
					continue;
				}

				$next_match = self::match_ai( $raw, $next_pos, $ai_table );
				if ( null === $next_match || isset( $used_next[ $next_match[0] ] ) ) {
					continue;
				}
			}

			$candidate = substr( $raw, $value_pos, $length );
			if ( ! self::value_matches_definition( $candidate, $def ) ) {
				continue;
			}

			$next_fields = $fields;
			$next_fields[ $store_key ] = $candidate;
			if ( $is_terminal ) {
				if ( 'SN' === $def['label'] ) {
					$next_fields['__sn_exact'] = true;
				}
			} else {
				$next_fields['__inferred_boundaries'] = isset( $next_fields['__inferred_boundaries'] )
					? ( (int) $next_fields['__inferred_boundaries'] + 1 )
					: 1;

				if ( ! isset( $next_fields['__inferred_fields'] ) || ! is_array( $next_fields['__inferred_fields'] ) ) {
					$next_fields['__inferred_fields'] = array();
				}

				if ( ! in_array( $report_label, $next_fields['__inferred_fields'], true ) ) {
					$next_fields['__inferred_fields'][] = $report_label;
				}
			}

			yield from self::backtrack( $raw, $next_pos, $next_fields, $used_next, $ai_table );
		}
	}

	private static function best_effort_parse( $raw, $ai_table ) {
		$fields     = array();
		$warnings   = array();
		$conflicted = array();
		$pos        = 0;
		$len        = strlen( $raw );

		while ( $pos < $len ) {
			if ( self::GROUP_SEPARATOR === $raw[ $pos ] ) {
				$warnings[] = __( 'Αγνοήθηκε περιττός Group Separator κατά την best-effort ανάλυση.', 'qr-rebuilder-pro' );
				++$pos;
				continue;
			}

			$matched = self::match_ai( $raw, $pos, $ai_table );
			if ( null === $matched ) {
				$warnings[] = sprintf(
					/* translators: 1: the unrecognised Application Identifier digits, 2: character position in the payload. */
					__( 'Άγνωστο Application Identifier «%1$s» στη θέση %2$d — η ανάλυση σταμάτησε εκεί.', 'qr-rebuilder-pro' ),
					self::utf8_excerpt( $raw, $pos, 2 ),
					$pos
				);
				break;
			}

			list( $ai, $def ) = $matched;
			$store_key        = self::ai_storage_key( $ai, $def );
			$pos             += strlen( $ai );

			if ( $def['length'] > 0 ) {
				$value = substr( $raw, $pos, $def['length'] );
				if ( strlen( $value ) !== $def['length'] ) {
					/* translators: %s: label of the GS1 field, e.g. SN or LOT. */
					$warnings[] = sprintf( __( 'Το πεδίο %s έχει μη έγκυρο μήκος.', 'qr-rebuilder-pro' ), $def['label'] );
					break;
				}
				$pos += $def['length'];

				/* Ο GS μετά από μη-predefined AI σταθερού μήκους είναι κανονικός. */
				if ( $pos < $len && self::GROUP_SEPARATOR === $raw[ $pos ] && ! self::is_predefined_length_ai( $ai ) ) {
					++$pos;
				}
			} else {
				$gs_pos = strpos( $raw, self::GROUP_SEPARATOR, $pos );
				if ( false !== $gs_pos ) {
					$value = substr( $raw, $pos, $gs_pos - $pos );
					$pos   = $gs_pos + 1;
				} else {
					$value = substr( $raw, $pos );
					$pos   = $len;
				}
			}

			if ( '' !== $def['label'] ) {
				self::add_value_warnings( $value, $def, $warnings );
			}

			if ( 'date' === $def['type'] && '' !== $def['label'] ) {
				$value = self::format_yymmdd( $value, $warnings );
			}

			$value = self::utf8_scrub( $value );

			/*
			 * Διπλό AI με άλλη τιμή: καμία από τις δύο δεν επιλέγεται. Το πεδίο
			 * μένει κενό ώστε να συμπληρωθεί από τη συσκευασία.
			 */
			if ( isset( $conflicted[ $store_key ] ) ) {
				continue;
			}

			if ( array_key_exists( $store_key, $fields ) ) {
				if ( (string) $fields[ $store_key ] === (string) $value ) {
					/* translators: %s: label of the GS1 field that was repeated. */
					$warnings[] = sprintf( __( 'Το πεδίο %s εμφανίστηκε περισσότερες από μία φορές. Κρατήθηκε η τελευταία τιμή.', 'qr-rebuilder-pro' ), self::ai_report_label( $ai, $def ) );
					continue;
				}

				$warnings[] = sprintf(
					/* translators: 1: label of the GS1 field, 2: first value, 3: second value. */
					__( 'Το πεδίο %1$s εμφανίστηκε δύο φορές με διαφορετικές τιμές («%2$s» και «%3$s»). Δεν επιλέχθηκε καμία — συμπληρώστε τη σωστή τιμή από τη συσκευασία.', 'qr-rebuilder-pro' ),
					self::ai_report_label( $ai, $def ),
					(string) $fields[ $store_key ],
					(string) $value
				);
				$conflicted[ $store_key ] = true;
				unset( $fields[ $store_key ] );
				continue;
			}

			$fields[ $store_key ] = $value;
		}

		self::strip_internal_fields( $fields );

		if ( self::has_field_value( $fields, 'PC' ) && ! self::gtin_check_digit_is_valid( $fields['PC'] ) ) {
			$warnings[] = __( 'Το PC (GTIN) απέτυχε τον έλεγχο ψηφίου ελέγχου GS1 — πιθανό σφάλμα σάρωσης. Ελέγξτε το πριν συνεχίσετε.', 'qr-rebuilder-pro' );
		}

		self::add_missing_warnings( $fields, $warnings );

		return array(
			'fields'                => $fields,
			'warnings'              => $warnings,
			'confidence'            => 'low',
			'ambiguous'             => false,
			'alternative_count'     => 0,
			'requires_confirmation' => true,
		);
	}

	/**
	 * Έως $chars χαρακτήρες UTF-8 από τη θέση byte $pos, χωρίς μισούς
	 * χαρακτήρες πολλών bytes (το αποτέλεσμα μπαίνει σε μήνυμα προς JSON).
	 */
	private static function utf8_excerpt( $raw, $pos, $chars ) {
		$tail = preg_replace( '/^[\x80-\xBF]+/', '', (string) substr( $raw, $pos, $chars * 4 ) );

		for ( $cut = strlen( $tail ); $cut > 0; $cut-- ) {
			$piece = substr( $tail, 0, $cut );

			if ( preg_match( '//u', $piece ) ) {
				return preg_match( '/^.{1,' . (int) $chars . '}/su', $piece, $match ) ? $match[0] : '';
			}
		}

		return '';
	}

	/** Αντικαθιστά άκυρα bytes UTF-8 με «?», ώστε η τιμή να περνά σε JSON. */
	private static function utf8_scrub( $value ) {
		$value = (string) $value;

		if ( preg_match( '//u', $value ) ) {
			return $value;
		}

		return (string) preg_replace_callback(
			'/([\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})|./s',
			static function ( $match ) {
				return isset( $match[1] ) && '' !== $match[1] ? $match[1] : '?';
			},
			$value
		);
	}

	private static function add_value_warnings( $value, $def, &$warnings ) {
		if ( 'numeric' === $def['type'] && ! ctype_digit( $value ) ) {
			/* translators: %s: label of the GS1 field. */
			$warnings[] = sprintf( __( 'Το πεδίο %s πρέπει να περιέχει μόνο ψηφία.', 'qr-rebuilder-pro' ), $def['label'] );
		} elseif ( 'alnum' === $def['type'] && ! preg_match( self::GS1_CHARSET_PATTERN, $value ) ) {
			/* translators: %s: label of the GS1 field. */
			$warnings[] = sprintf( __( 'Το πεδίο %s περιέχει χαρακτήρες εκτός του επιτρεπτού GS1 character set.', 'qr-rebuilder-pro' ), $def['label'] );
		}

		if ( 0 === $def['length'] && ( '' === $value || strlen( $value ) > (int) $def['max'] ) ) {
			/* translators: 1: label of the GS1 field, 2: maximum number of characters allowed for it. */
			$warnings[] = sprintf( __( 'Το πεδίο %1$s έχει μη έγκυρο μήκος (1-%2$d χαρακτήρες).', 'qr-rebuilder-pro' ), $def['label'], (int) $def['max'] );
		}
	}

	private static function value_matches_definition( $value, $def ) {
		if ( '' === $value ) {
			return false;
		}

		if ( $def['length'] > 0 && strlen( $value ) !== (int) $def['length'] ) {
			return false;
		}

		if ( 0 === $def['length'] && strlen( $value ) > (int) $def['max'] ) {
			return false;
		}

		if ( ! self::value_matches_type( $value, $def['type'] ) ) {
			return false;
		}

		$length = strlen( $value );

		/* Shortest legal value — the mandatory components of the definition. */
		if ( ! empty( $def['min'] ) && $length < (int) $def['min'] ) {
			return false;
		}

		/* Fields built from repeated fixed groups have only discrete lengths. */
		if ( ! empty( $def['lengths'] ) && ! in_array( $length, $def['lengths'], true ) ) {
			return false;
		}

		/*
		 * Composite fields: a fixed number of LEADING digits is mandatory and
		 * the remainder is free text — AI 253 is thirteen digits then an
		 * optional X..17, AI 8003 is fourteen then X..16. Checking only the
		 * overall character set would accept "253ABC" as a clean read.
		 */
		if ( ! empty( $def['numeric_prefix'] ) ) {
			$prefix = (int) $def['numeric_prefix'];

			if ( $length < $prefix || ! ctype_digit( substr( $value, 0, $prefix ) ) ) {
				return false;
			}
		}

		if ( ! empty( $def['validator'] ) ) {
			return self::value_matches_structure( $value, $def['validator'] );
		}

		return true;
	}

	/**
	 * Date and time structures inside an Application Identifier.
	 *
	 * A field declared "six numeric digits" is not satisfied by 999999: the
	 * standard says those six digits are YYMMDD, and a parser that accepts an
	 * impossible date is reporting a clean read for data that cannot exist.
	 * The same applies to the hour, minute and second groups.
	 *
	 * These use the STRICT date test, not the one the expiry field uses. GS1
	 * separates the two itself: AI 11/12/13/15/16/17 are defined as "yymmd0",
	 * where day 00 is the legacy "end of month" form, while AI 7003/7006/7007
	 * and 8008 are defined as "yymmdd" and require a real day. Reusing the
	 * lenient test here would accept 270100 as a production date.
	 */
	private static function value_matches_structure( $value, $validator ) {
		if ( 'yymmdd_groups' === $validator ) {
			foreach ( str_split( $value, 6 ) as $group ) {
				if ( ! self::is_valid_yymmdd_strict( $group ) ) {
					return false;
				}
			}

			return true;
		}

		if ( 'yymmdd_hhmi' === $validator || 'yymmdd_time' === $validator ) {
			return self::is_valid_yymmdd_strict( substr( $value, 0, 6 ) )
				&& self::is_valid_clock( substr( $value, 6 ) );
		}

		/*
		 * A date whose time part is OPTIONAL (AI 7011). The clock is only
		 * checked when it is actually present — is_valid_clock() rejects an
		 * empty string on purpose, because for 7003/8008 the time is mandatory.
		 */
		if ( 'yymmdd_opt_time' === $validator ) {
			$time = substr( $value, 6 );

			return self::is_valid_yymmdd_strict( substr( $value, 0, 6 ) )
				&& ( '' === $time || self::is_valid_clock( $time ) );
		}

		/* Four-digit year date (AI 7250/7251), time optional. */
		if ( 'yyyymmdd_opt_time' === $validator ) {
			$time = substr( $value, 8 );

			return self::is_valid_yyyymmdd( substr( $value, 0, 8 ) )
				&& ( '' === $time || self::is_valid_clock( $time ) );
		}

		/*
		 * AI 4324/4325 are defined as "yymmd0", i.e. the legacy day-00 form is
		 * legal there — the same rule AI 17 follows — so this deliberately uses
		 * the LENIENT date test rather than the strict one.
		 */
		if ( 'yymmd0_hhmi' === $validator ) {
			return self::is_valid_yymmdd( substr( $value, 0, 6 ) )
				&& self::is_valid_clock( substr( $value, 6 ) );
		}

		return true;
	}

	/** YYYYMMDD with an explicit four-digit year — no century window involved. */
	private static function is_valid_yyyymmdd( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{8}$/', $value ) ) {
			return false;
		}

		return checkdate(
			(int) substr( $value, 4, 2 ),
			(int) substr( $value, 6, 2 ),
			(int) substr( $value, 0, 4 )
		);
	}

	/**
	 * YYMMDD with a real day — no legacy day 00.
	 *
	 * Kept separate from is_valid_yymmdd() on purpose: that one must keep
	 * accepting day 00 so an old expiry code still scans (and gets warned
	 * about). Merging the two would either break those scans or silently
	 * weaken every field the standard defines as a true date.
	 */
	private static function is_valid_yymmdd_strict( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{6}$/', $value ) ) {
			return false;
		}

		$mm = (int) substr( $value, 2, 2 );
		$dd = (int) substr( $value, 4, 2 );

		if ( $dd < 1 ) {
			return false;
		}

		return checkdate( $mm, $dd, self::yy_to_year( (int) substr( $value, 0, 2 ) ) );
	}

	/** Hours, then optional minutes and seconds, in two-digit groups. */
	private static function is_valid_clock( $value ) {
		if ( '' === $value || 0 !== strlen( $value ) % 2 ) {
			return false;
		}

		$limits = array( 23, 59, 59 );
		$groups = str_split( $value, 2 );

		if ( count( $groups ) > count( $limits ) ) {
			return false;
		}

		foreach ( $groups as $index => $group ) {
			if ( ! ctype_digit( $group ) || (int) $group > $limits[ $index ] ) {
				return false;
			}
		}

		return true;
	}

	private static function value_matches_type( $value, $type ) {
		if ( 'numeric' === $type ) {
			return '' !== $value && ctype_digit( $value );
		}
		if ( 'date' === $type ) {
			return self::is_valid_yymmdd( $value );
		}
		if ( 'alnum' === $type ) {
			return '' !== $value && (bool) preg_match( self::GS1_CHARSET_PATTERN, $value );
		}
		return true;
	}

	private static function is_valid_yymmdd( $value ) {
		if ( ! preg_match( '/^\d{6}$/', $value ) ) {
			return false;
		}

		$yy = (int) substr( $value, 0, 2 );
		$mm = (int) substr( $value, 2, 2 );
		$dd = (int) substr( $value, 4, 2 );
		if ( $mm < 1 || $mm > 12 ) {
			return false;
		}

		return 0 === $dd || checkdate( $mm, $dd, self::yy_to_year( $yy ) );
	}

	/**
	 * The window of four-digit years that YYMMDD can represent without loss.
	 * Kept in one place so validation and decoding can never drift apart.
	 */
	public static function gs1_year_range() {
		$reference = self::century_reference_year();

		return array(
			'first' => $reference - 49,
			'last'  => $reference + 50,
		);
	}

	/** True when writing this year as two digits and reading it back is lossless. */
	public static function year_survives_gs1_round_trip( $year ) {
		$year = (int) $year;

		return self::yy_to_year( $year % 100 ) === $year;
	}

	private static function century_reference_year() {
		$real_year = (int) wp_date( 'Y' );

		if ( $real_year < 1000 || $real_year > 9999 ) {
			$real_year = (int) gmdate( 'Y' );
		}

		$current_year = (int) apply_filters( 'qrrp_century_reference_year', $real_year );

		/*
		 * 2.16.1: το φίλτρο μετακινεί το παράθυρο το πολύ ±1 έτος (π.χ. για
		 * ζώνη ώρας ή tests). Πριν δεχόταν 1000–9999, και ένα plugin μπορούσε
		 * σιωπηλά να διαβάζει το «28» ως 1928.
		 */
		return max( $real_year - 1, min( $real_year + 1, $current_year ) );
	}

	private static function yy_to_year( $yy ) {
		$yy = max( 0, min( 99, (int) $yy ) );

		/*
		 * GS1 two-digit years are interpreted in a rolling 100-year window:
		 * 49 years in the past through 50 years in the future, relative to the
		 * current year. A fixed century pivot (for example 50) becomes incorrect
		 * as time advances.
		 */
		$current_year = self::century_reference_year();
		$century      = (int) floor( $current_year / 100 ) * 100;
		$year    = $century + $yy;

		if ( $year < $current_year - 49 ) {
			$year += 100;
		} elseif ( $year > $current_year + 50 ) {
			$year -= 100;
		}

		return $year;
	}

	/**
	 * 2.16.1: πλήρεις αναγνώσεις της σάρωσης στις οποίες λείπει κάποιο από τα
	 * τέσσερα πεδία, ως «SN «ABCD10EFGH» χωρίς LOT» για το μήνυμα. Η αναζήτηση
	 * τις βρίσκει ήδη (καταναλώνουν όλο το string)· η assess_solutions() τις
	 * θεωρεί αποτυχίες, άρα χωρίς αυτόν τον έλεγχο δεν μετρούσαν ως ασάφεια.
	 *
	 * @param array $solutions Λύσεις της αναζήτησης.
	 * @param array $chosen    Η επιλεγμένη (πλήρης) ανάγνωση.
	 * @return string[]
	 */
	private static function field_absent_readings( array $solutions, array $chosen ) {
		$out = array();

		foreach ( $solutions as $solution ) {
			if ( ! is_array( $solution ) || ! self::has_field_value( $solution, 'PC' ) ) {
				continue;
			}

			$missing = array();
			$changed = array();

			foreach ( self::REQUIRED_FIELDS as $label ) {
				if ( ! self::has_field_value( $solution, $label ) ) {
					$missing[] = $label;
					continue;
				}

				/* Το EXP της επιλεγμένης είναι ήδη ISO, της λύσης YYMMDD. */
				$value = 'EXP' === $label ? self::format_yymmdd_quiet( $solution[ $label ] ) : (string) $solution[ $label ];

				if ( ! isset( $chosen[ $label ] ) || (string) $chosen[ $label ] !== $value ) {
					$changed[] = sprintf( '%s «%s»', $label, $value );
				}
			}

			if ( array() === $missing ) {
				continue;
			}

			$text = sprintf(
				/* translators: 1: the changed field values, 2: the missing field labels. */
				__( '%1$s χωρίς %2$s', 'qr-rebuilder-pro' ),
				array() === $changed ? __( 'ίδια πεδία', 'qr-rebuilder-pro' ) : implode( ', ', $changed ),
				implode( ', ', $missing )
			);

			if ( ! in_array( $text, $out, true ) ) {
				$out[] = $text;
			}

			if ( count( $out ) >= 3 ) {
				break;
			}
		}

		return $out;
	}

	/** 2.16.1: YYMMDD → YYYY-MM-DD για μήνυμα, χωρίς προειδοποιήσεις. */
	private static function format_yymmdd_quiet( $value ) {
		$ignored = array();

		return (string) self::format_yymmdd( $value, $ignored );
	}

	/**
	 * 2.16.0: SN / LOT κάτω από το ελάχιστο μήκος αυτόματης αποδοχής, ως
	 * «SN «AB»» για το μήνυμα. Φίλτρο qrrp_auto_inference_min_length (1–20,
	 * προεπιλογή 4).
	 *
	 * @param array $fields Επιλεγμένη ανάγνωση.
	 * @return string[]
	 */
	private static function short_variable_fields( $fields ) {
		$min = (int) apply_filters( 'qrrp_auto_inference_min_length', 4 );
		$min = max( 1, min( 20, $min ) );
		$out = array();

		foreach ( array( 'SN', 'LOT' ) as $label ) {
			if ( self::has_field_value( $fields, $label ) && strlen( (string) $fields[ $label ] ) < $min ) {
				$out[] = sprintf( '%s «%s»', $label, $fields[ $label ] );
			}
		}

		return $out;
	}

	/**
	 * Explain exactly which variable field boundary had to be inferred because
	 * the scanner payload did not contain the required Group Separator.
	 */
	private static function add_inferred_boundary_warning( $fields, $inferred_fields, $count, &$warnings ) {
		$labels = array_values( array_unique( array_filter( (array) $inferred_fields, 'is_string' ) ) );

		if ( 1 === $count && 1 === count( $labels ) ) {
			$label = $labels[0];
			$value = self::has_field_value( $fields, $label ) ? (string) $fields[ $label ] : '';

			if ( 'SN' === $label ) {
				$warnings[] = sprintf(
					/* translators: %s: the Serial Number as read by the parser. */
					__( 'Έλειπε Group Separator μετά το SN. Το όριο του Serial Number υπολογίστηκε από τον parser. Ελέγξτε ότι το SN είναι «%s».', 'qr-rebuilder-pro' ),
					$value
				);
				return;
			}

			if ( 'LOT' === $label ) {
				$warnings[] = sprintf(
					/* translators: %s: the Batch/Lot Number as read by the parser. */
					__( 'Έλειπε Group Separator μετά το LOT. Το όριο του Batch/Lot Number υπολογίστηκε από τον parser. Ελέγξτε ότι το LOT είναι «%s».', 'qr-rebuilder-pro' ),
					$value
				);
				return;
			}

			$warnings[] = sprintf(
				/* translators: 1: label of the GS1 field, 2: the value as read by the parser. */
				__( 'Έλειπε Group Separator μετά το πεδίο %1$s. Το όριό του υπολογίστηκε από τον parser. Ελέγξτε ότι η τιμή είναι «%2$s».', 'qr-rebuilder-pro' ),
				$label,
				$value
			);
			return;
		}

		$review = array();
		foreach ( $labels as $label ) {
			if ( self::has_field_value( $fields, $label ) ) {
				$review[] = sprintf( '%s «%s»', $label, $fields[ $label ] );
			} else {
				$review[] = $label;
			}
		}

		if ( ! empty( $review ) ) {
			$warnings[] = sprintf(
				/* translators: 1: number of variable-length field boundaries inferred by the parser, 2: comma-separated list of the fields to review. */
				_n(
					'Έλειπε Group Separator και %1$d όριο μεταβλητού πεδίου υπολογίστηκε από τον parser. Ελέγξτε: %2$s.',
					'Έλειπαν Group Separators και %1$d όρια μεταβλητών πεδίων υπολογίστηκαν από τον parser. Ελέγξτε: %2$s.',
					$count,
					'qr-rebuilder-pro'
				),
				$count,
				implode( ', ', $review )
			);
			return;
		}

		$warnings[] = sprintf(
			/* translators: %d: number of variable-length field boundaries inferred by the parser. */
			_n(
				'Έλειπε Group Separator και %d όριο μεταβλητού πεδίου υπολογίστηκε από τον parser. Απαιτείται χειροκίνητος έλεγχος.',
				'Έλειπαν Group Separators και %d όρια μεταβλητών πεδίων υπολογίστηκαν από τον parser. Απαιτείται χειροκίνητος έλεγχος.',
				$count,
				'qr-rebuilder-pro'
			),
			$count
		);
	}


	private static function strip_internal_fields( &$fields ) {
		foreach ( array_keys( $fields ) as $key ) {
			if ( 0 === strpos( (string) $key, '__' ) ) {
				unset( $fields[ $key ] );
			}
		}
	}

	private static function has_field_value( $fields, $key ) {
		return isset( $fields[ $key ] ) && '' !== (string) $fields[ $key ];
	}

	private static function all_required_fields_present( $fields ) {
		foreach ( self::REQUIRED_FIELDS as $required ) {
			if ( ! self::has_field_value( $fields, $required ) ) {
				return false;
			}
		}

		return true;
	}

	private static function add_missing_warnings( $fields, &$warnings ) {
		foreach ( self::REQUIRED_FIELDS as $required ) {
			/* Παρόν αλλά άκυρο (π.χ. EXP με μήνα 13) έχει ήδη δική του προειδοποίηση. */
			if ( ! self::has_field_value( $fields, $required ) && ! array_key_exists( $required, $fields ) ) {
				/* translators: %s: field name, e.g. PC, SN, LOT or EXP. */
				$warnings[] = sprintf( __( 'Το πεδίο %s λείπει από τα δεδομένα.', 'qr-rebuilder-pro' ), $required );
			}
		}
	}

	private static function result_with_context( $result, $warnings, $normalization ) {
		$result['warnings']            = array_merge( $warnings, isset( $result['warnings'] ) ? $result['warnings'] : array() );
		$result['normalization']       = $normalization;
		$result['inferred_boundaries'] = isset( $result['inferred_boundaries'] ) ? (int) $result['inferred_boundaries'] : 0;
		$result['inferred_fields']     = isset( $result['inferred_fields'] ) && is_array( $result['inferred_fields'] )
			? array_values( $result['inferred_fields'] )
			: array();
		$result['inference_used']          = ! empty( $result['inference_used'] ) || $result['inferred_boundaries'] > 0;
		$result['inference_auto_accepted'] = ! empty( $result['inference_auto_accepted'] );
		$result['requires_confirmation']   = isset( $result['requires_confirmation'] )
			? (bool) $result['requires_confirmation']
			: true;
		$result['search_truncated']      = ! empty( $result['search_truncated'] );
		$result['cross_check_truncated'] = ! empty( $result['cross_check_truncated'] );
		$result['exp_day_unspecified']   = ! empty( $result['exp_day_unspecified'] );
		$result['exp_in_past']           = ! empty( $result['exp_in_past'] );
		$result['extra_ais_present']     = ! empty( $result['extra_ais_present'] );
		return $result;
	}

	private static function empty_result( $warning ) {
		return array(
			'fields'              => array(),
			'warnings'            => array( $warning ),
			'confidence'          => 'low',
			'ambiguous'           => false,
			'alternative_count'   => 0,
			'inferred_boundaries'   => 0,
			'inferred_fields'       => array(),
			'inference_used'          => false,
			'inference_auto_accepted' => false,
			'requires_confirmation'   => true,
			'normalization'         => array(),
			'search_truncated'       => false,
			'cross_check_truncated'  => false,
			'exp_day_unspecified'    => false,
			'exp_in_past'            => false,
			'extra_ais_present'      => false,
		);
	}

	/**
	 * Τυπικό μήκος SN/LOT από τις ρυθμίσεις, 1-20. Με absint() όπως η
	 * QRRP_Admin::sanitize_gs1_length(), ώστε οι δύο να συμφωνούν και σε αρνητικές τιμές.
	 */
	private static function option_length( $option, $default ) {
		return min( 20, max( 1, absint( get_option( $option, $default ) ) ) );
	}
}