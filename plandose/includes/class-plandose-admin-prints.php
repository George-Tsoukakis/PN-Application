<?php
/**
 * PlanDose → Εκτυπώσεις — every print of the pharmacies, newest
 * first, from the print log (Plandose_Print_Log).
 *
 * Read-only. Needs the PlanDose capability and, like the subscriber lists
 * and the audit log, «list_users»: the rows name pharmacies and show their
 * email addresses.
 *
 * Filters (GET, sanitized): pharmacy search (name, email, ΑΦΜ — the same
 * search as the subscriber list), one pharmacy (user=ID, from a card link),
 * kind, a date range in the site's time zone, rows per page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plandose_Admin_Prints {

	/** Admin page slug. */
	const PAGE = 'plandose-prints';

	/**
	 * URL of the screen, optionally for one pharmacy.
	 *
	 * @param int $user_id Pharmacy user ID, 0 for all.
	 * @return string
	 */
	public static function url( $user_id = 0 ) {
		$args = array( 'page' => self::PAGE );

		if ( $user_id ) {
			$args['user'] = absint( $user_id );
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * The filters of this request, sanitized.
	 *
	 * @return array{search:string,user:int,kind:string,from:string,to:string,paged:int,per_page:int}
	 */
	private static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen; every value is sanitized and only filters a SELECT.
		$get = static function ( $key ) {
			return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		};
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$kind     = $get( 'kind' );
		$per_page = (int) $get( 'per_page' );
		$from     = $get( 'from' );
		$to       = $get( 'to' );

		return array(
			'search'   => $get( 's' ),
			'user'     => absint( $get( 'user' ) ),
			'kind'     => in_array( $kind, Plandose_Print_Log::kinds(), true ) ? $kind : '',
			'from'     => null !== Plandose_Print_Log::day_start_ts( $from ) ? $from : '',
			'to'       => null !== Plandose_Print_Log::day_start_ts( $to ) ? $to : '',
			'paged'    => max( 1, min( 1000000, absint( $get( 'paged' ) ) ) ),
			'per_page' => in_array( $per_page, array( 50, 100, 200 ), true ) ? $per_page : 50,
		);
	}

	/**
	 * User IDs a search matches (the subscriber list's own search), or
	 * array( 0 ) when nothing matches. At most
	 * Plandose_Subscriber_Query::MAX_SUBSCRIBER_PAGE_SIZE: 'capped' tells
	 * the screen that the search may match more pharmacies than it shows.
	 *
	 * @param string $search Search text.
	 * @return array{ids:int[],capped:bool}
	 */
	private static function search_user_ids( $search ) {
		if ( ! class_exists( 'Plandose_Subscriber_Query' ) ) {
			return array( 'ids' => array( 0 ), 'capped' => false );
		}

		$result = Plandose_Subscriber_Query::query_subscribers(
			array(
				'search'     => $search,
				'per_page'   => Plandose_Subscriber_Query::MAX_SUBSCRIBER_PAGE_SIZE,
				'with_total' => false,
			)
		);

		$ids = array();

		foreach ( (array) $result['items'] as $item ) {
			$ids[] = (int) $item->user->ID;
		}

		return array(
			'ids'    => $ids ? $ids : array( 0 ),
			'capped' => count( $ids ) >= Plandose_Subscriber_Query::MAX_SUBSCRIBER_PAGE_SIZE,
		);
	}

	/**
	 * Name and email of each pharmacy in these rows, with one users query.
	 *
	 * @param object[] $rows Log rows.
	 * @return array<int,array{name:string,email:string,deleted:bool}>
	 */
	private static function labels( $rows ) {
		$ids = array_values( array_unique( array_filter( array_map( static function ( $row ) {
			return (int) $row->user_id;
		}, $rows ) ) ) );

		$labels = array();

		if ( ! $ids ) {
			return $labels;
		}

		cache_users( $ids );

		foreach ( $ids as $id ) {
			$user = get_userdata( $id );

			if ( $user ) {
				// User meta may hold an array (another plugin, an import):
				// only a scalar is a name, never «Array».
				$name          = Plandose_Access::get_meta_with_fallback( $id, Plandose_Access::PHARMACY_NAME_META_KEYS );
				$name          = is_scalar( $name ) ? trim( (string) $name ) : '';
				$labels[ $id ] = array(
					'name'    => '' !== $name ? $name : (string) $user->display_name,
					'email'   => $user->user_email,
					'deleted' => false,
				);
				continue;
			}

			$snapshot      = method_exists( 'Plandose_Admin', 'deleted_account_snapshot' ) ? Plandose_Admin::deleted_account_snapshot( $id ) : null;
			$labels[ $id ] = array(
				/* translators: %d: user ID */
				'name'    => ( is_array( $snapshot ) && ! empty( $snapshot['name'] ) ) ? (string) $snapshot['name'] : sprintf( __( 'Λογαριασμός #%d', 'plandose' ), $id ),
				'email'   => ( is_array( $snapshot ) && ! empty( $snapshot['email'] ) ) ? (string) $snapshot['email'] : '',
				'deleted' => true,
			);
		}

		return $labels;
	}

	/**
	 * URL of page $page with the current filters.
	 *
	 * @param int $page Page number.
	 * @return string Raw URL (escape on output).
	 */
	private static function page_url( $page ) {
		return remove_query_arg( array( 'plandose_result' ), add_query_arg( 'paged', (int) $page ) );
	}

	/**
	 * Render the screen.
	 */
	public static function render_page() {
		if ( ! current_user_can( Plandose_Admin::capability() ) ) {
			wp_die( esc_html__( 'Δεν έχετε δικαίωμα πρόσβασης.', 'plandose' ) );
		}

		// The rows name pharmacies and show their email addresses.
		if ( ! Plandose_Admin::can_view_accounts() ) {
			wp_die( esc_html__( 'Το ιστορικό εκτυπώσεων χρειάζεται επιπλέον το δικαίωμα «list_users».', 'plandose' ), '', array( 'response' => 403 ) );
		}

		$f = self::filters();

		Plandose_Admin::page_header(
			__( 'PlanDose → Εκτυπώσεις', 'plandose' ),
			__( 'Κάθε εκτύπωση πλάνου των φαρμακείων, από τη νεότερη. Δεν περιέχει στοιχεία ασθενών ή φαρμάκων· κρατιέται 12 μήνες.', 'plandose' )
		);

		self::render_summary();

		$user_ids = array();
		$capped   = false;

		if ( $f['user'] ) {
			$user_ids = array( $f['user'] );
		} elseif ( '' !== $f['search'] ) {
			$found    = self::search_user_ids( $f['search'] );
			$user_ids = $found['ids'];
			$capped   = $found['capped'];
		}

		$query  = array(
			'user_ids' => $user_ids,
			'kind'     => $f['kind'],
			'from'     => $f['from'],
			'to'       => $f['to'],
			'paged'    => $f['paged'],
			'per_page' => $f['per_page'],
		);
		$result = Plandose_Print_Log::query( $query );

		// A page past the end (an old link, a typed number): the last page.
		if ( ! $result['error'] && ! $result['rows'] && $result['total'] > 0 ) {
			$f['paged']     = (int) ceil( $result['total'] / $f['per_page'] );
			$query['paged'] = $f['paged'];
			$result         = Plandose_Print_Log::query( $query );
		}

		$labels   = self::labels( $result['rows'] );
		$filtered = $f['user'] || '' !== $f['search'] || '' !== $f['kind'] || '' !== $f['from'] || '' !== $f['to'];

		self::render_filters( $f );
		?>
		<div class="plandose-main-card plandose-main-card-full plandose-prints">
			<?php if ( $f['user'] ) : ?>
				<?php $one = self::labels( array( (object) array( 'user_id' => $f['user'] ) ) ); ?>
				<p class="pd-prints-scope">
					<?php
					printf(
						/* translators: %s: pharmacy name */
						esc_html__( 'Εκτυπώσεις του φαρμακείου %s', 'plandose' ),
						'<strong>' . esc_html( isset( $one[ $f['user'] ] ) ? $one[ $f['user'] ]['name'] : '#' . $f['user'] ) . '</strong>'
					);
					?>
					<a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Όλα τα φαρμακεία', 'plandose' ); ?></a>
				</p>
			<?php endif; ?>

			<?php if ( $capped ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					printf(
						/* translators: %1$s: maximum number of pharmacies a search covers */
						esc_html__( 'Η αναζήτηση ταιριάζει σε περισσότερα από %1$s φαρμακεία· εμφανίζονται μόνο οι εκτυπώσεις των πρώτων %1$s. Περιορίστε την αναζήτηση για πλήρη αποτελέσματα.', 'plandose' ),
						esc_html( number_format_i18n( Plandose_Subscriber_Query::MAX_SUBSCRIBER_PAGE_SIZE ) )
					);
					?>
				</p></div>
			<?php endif; ?>

			<?php if ( $result['error'] ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'Το ιστορικό εκτυπώσεων δεν διαβάστηκε (σφάλμα βάσης δεδομένων). Ανανεώστε τη σελίδα· αν συνεχίζεται, δείτε PlanDose → Διαγνωστικά.', 'plandose' ); ?></p></div>
			<?php elseif ( empty( $result['rows'] ) ) : ?>
				<p class="plandose-empty">
					<?php
					if ( $filtered ) {
						esc_html_e( 'Καμία εκτύπωση με αυτά τα φίλτρα.', 'plandose' );
					} else {
						esc_html_e( 'Δεν υπάρχουν ακόμη εκτυπώσεις. Η καταγραφή ξεκίνησε με την έκδοση 1.29.0· για παλαιότερους μήνες υπάρχουν μόνο τα σύνολα στις κάρτες των φαρμακείων.', 'plandose' );
					}
					?>
				</p>
			<?php else : ?>
				<div class="pd-table-scroll">
					<table class="widefat striped plandose-table pd-prints-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Ημερομηνία', 'plandose' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Φαρμακείο', 'plandose' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Εκτύπωση', 'plandose' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Συνδρομή', 'plandose' ); ?></th>
								<th scope="col" class="pd-num"><?php esc_html_e( 'Του μήνα', 'plandose' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $result['rows'] as $row ) : ?>
							<?php
							$uid   = (int) $row->user_id;
							$label = isset( $labels[ $uid ] ) ? $labels[ $uid ] : array( 'name' => '#' . $uid, 'email' => '', 'deleted' => true );
							$ts    = (int) $row->printed_ts;
							?>
							<tr>
								<td>
									<time datetime="<?php echo esc_attr( gmdate( 'c', $ts ) ); ?>">
										<?php echo esc_html( wp_date( 'd/m/Y', $ts ) ); ?>
										<span class="pd-muted"><?php echo esc_html( wp_date( 'H:i', $ts ) ); ?></span>
									</time>
								</td>
								<td>
									<?php if ( $label['deleted'] ) : ?>
										<?php echo esc_html( $label['name'] ); ?>
										<span class="pd-badge pd-muted-badge"><?php esc_html_e( 'Διαγραμμένος λογαριασμός', 'plandose' ); ?></span>
									<?php else : ?>
										<a href="<?php echo esc_url( self::url( $uid ) ); ?>"><?php echo esc_html( $label['name'] ); ?></a>
									<?php endif; ?>
									<?php if ( '' !== $label['email'] ) : ?>
										<span class="pd-prints-email pd-muted"><?php echo esc_html( $label['email'] ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( Plandose_Print_Log::KIND_REPRINT === $row->kind ) : ?>
										<span class="pd-badge pd-muted-badge"><?php esc_html_e( 'Δωρεάν επανεκτύπωση', 'plandose' ); ?></span>
									<?php else : ?>
										<span class="pd-badge paid"><?php esc_html_e( 'Χρεώθηκε', 'plandose' ); ?></span>
									<?php endif; ?>
								</td>
								<td><span class="pd-badge <?php echo (int) $row->is_pro ? 'pro' : 'free'; ?>"><?php echo (int) $row->is_pro ? 'Pro' : 'Free'; ?></span></td>
								<td class="pd-num">
									<?php echo Plandose_Print_Log::KIND_CHARGE === $row->kind && (int) $row->month_count > 0 ? esc_html( number_format_i18n( (int) $row->month_count ) ) : '<span class="pd-muted">—</span>'; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php self::render_pagination( (int) $result['total'], $f['per_page'], $f['paged'] ); ?>
			<?php endif; ?>
		</div>
		<?php
		Plandose_Admin::page_footer();
	}

	/**
	 * The KPI tiles' periods: label and start (00:00 site time of today,
	 * 6 and 29 days before). Counted in calendar days in the site's time
	 * zone, not in 86400-second steps, which are an hour off across a
	 * daylight-saving change.
	 *
	 * @param DateTimeImmutable|null $now «Now» (tests); default the current time.
	 * @return array<int,array{0:string,1:int}>
	 */
	public static function summary_periods( $now = null ) {
		$now   = $now instanceof DateTimeImmutable ? $now : new DateTimeImmutable( 'now', wp_timezone() );
		$today = $now->setTimezone( wp_timezone() )->setTime( 0, 0, 0 );

		return array(
			array( __( 'Σήμερα', 'plandose' ), $today->getTimestamp() ),
			array( __( 'Τελευταίες 7 ημέρες', 'plandose' ), $today->modify( '-6 days' )->getTimestamp() ),
			array( __( 'Τελευταίες 30 ημέρες', 'plandose' ), $today->modify( '-29 days' )->getTimestamp() ),
		);
	}

	/**
	 * Today, the last 7 and the last 30 days, as KPI tiles.
	 */
	private static function render_summary() {
		$periods = self::summary_periods();
		$all     = Plandose_Print_Log::counts_since_each( wp_list_pluck( $periods, 1 ) );
		?>
		<div class="plandose-kpis">
			<?php foreach ( $periods as $i => $period ) : ?>
				<?php $counts = ( is_array( $all ) && isset( $all[ $i ] ) ) ? $all[ $i ] : null; ?>
				<div class="plandose-kpi">
					<span aria-hidden="true">🖨️</span>
					<div>
						<strong><?php echo null === $counts ? '—' : esc_html( number_format_i18n( $counts['charge'] ) ); ?></strong>
						<em>
							<?php echo esc_html( $period[0] ); ?>
							<?php if ( $counts && $counts['reprint'] ) : ?>
								<?php
								/* translators: %s: number of free reprints */
								echo esc_html( sprintf( __( '+ %s δωρεάν επανεκτυπώσεις', 'plandose' ), number_format_i18n( $counts['reprint'] ) ) );
								?>
							<?php endif; ?>
						</em>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * The filter form (GET).
	 *
	 * @param array $f filters().
	 */
	private static function render_filters( $f ) {
		?>
		<form class="plandose-filter-card" method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
			<?php if ( $f['user'] ) : ?>
				<input type="hidden" name="user" value="<?php echo esc_attr( (string) $f['user'] ); ?>" />
			<?php else : ?>
				<label class="plandose-filter-field plandose-filter-search">
					<span><?php esc_html_e( 'Φαρμακείο', 'plandose' ); ?></span>
					<input type="search" name="s" value="<?php echo esc_attr( $f['search'] ); ?>" placeholder="<?php esc_attr_e( 'Όνομα, email, ΑΦΜ…', 'plandose' ); ?>" />
				</label>
			<?php endif; ?>

			<label class="plandose-filter-field">
				<span><?php esc_html_e( 'Εκτύπωση', 'plandose' ); ?></span>
				<select name="kind">
					<option value="" <?php selected( $f['kind'], '' ); ?>><?php esc_html_e( 'Όλες', 'plandose' ); ?></option>
					<option value="charge" <?php selected( $f['kind'], 'charge' ); ?>><?php esc_html_e( 'Χρεώθηκαν', 'plandose' ); ?></option>
					<option value="reprint" <?php selected( $f['kind'], 'reprint' ); ?>><?php esc_html_e( 'Δωρεάν επανεκτυπώσεις', 'plandose' ); ?></option>
				</select>
			</label>

			<label class="plandose-filter-field">
				<span><?php esc_html_e( 'Από', 'plandose' ); ?></span>
				<input type="date" name="from" value="<?php echo esc_attr( $f['from'] ); ?>" />
			</label>

			<label class="plandose-filter-field">
				<span><?php esc_html_e( 'Έως', 'plandose' ); ?></span>
				<input type="date" name="to" value="<?php echo esc_attr( $f['to'] ); ?>" />
			</label>

			<label class="plandose-filter-field">
				<span><?php esc_html_e( 'Ανά σελίδα', 'plandose' ); ?></span>
				<select name="per_page">
					<?php foreach ( array( 50, 100, 200 ) as $n ) : ?>
						<option value="<?php echo esc_attr( (string) $n ); ?>" <?php selected( $f['per_page'], $n ); ?>><?php echo esc_html( (string) $n ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>

			<div class="plandose-filter-actions">
				<button class="button button-primary"><?php esc_html_e( 'Εφαρμογή', 'plandose' ); ?></button>
				<a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Καθαρισμός', 'plandose' ); ?></a>
			</div>
		</form>
		<?php
	}

	/**
	 * «Showing X–Y of Z» and previous / next.
	 *
	 * @param int $total    Rows matching.
	 * @param int $per_page Rows per page.
	 * @param int $paged    Current page.
	 */
	private static function render_pagination( $total, $per_page, $paged ) {
		$pages = max( 1, (int) ceil( $total / max( 1, $per_page ) ) );
		$first = ( ( $paged - 1 ) * $per_page ) + 1;
		$last  = min( $total, $paged * $per_page );
		?>
		<div class="plandose-pagination-bar">
			<p class="plandose-pagination-count">
				<?php
				printf(
					/* translators: 1: first row on this page, 2: last row on this page, 3: total rows. */
					esc_html__( 'Εμφάνιση %1$s–%2$s από %3$s', 'plandose' ),
					esc_html( number_format_i18n( $first ) ),
					esc_html( number_format_i18n( $last ) ),
					esc_html( number_format_i18n( $total ) )
				);
				?>
			</p>
			<?php if ( $pages > 1 ) : ?>
				<nav class="plandose-pagination" aria-label="<?php esc_attr_e( 'Σελίδες ιστορικού εκτυπώσεων', 'plandose' ); ?>">
					<?php if ( $paged > 1 ) : ?>
						<a class="pd-page-step" href="<?php echo esc_url( self::page_url( $paged - 1 ) ); ?>" rel="prev"><?php esc_html_e( 'Νεότερες', 'plandose' ); ?></a>
					<?php endif; ?>
					<span class="active">
						<?php
						/* translators: 1: current page, 2: number of pages */
						echo esc_html( sprintf( __( 'Σελίδα %1$s από %2$s', 'plandose' ), number_format_i18n( $paged ), number_format_i18n( $pages ) ) );
						?>
					</span>
					<?php if ( $paged < $pages ) : ?>
						<a class="pd-page-step" href="<?php echo esc_url( self::page_url( $paged + 1 ) ); ?>" rel="next"><?php esc_html_e( 'Παλαιότερες', 'plandose' ); ?></a>
					<?php endif; ?>
				</nav>
			<?php endif; ?>
		</div>
		<?php
	}
}
