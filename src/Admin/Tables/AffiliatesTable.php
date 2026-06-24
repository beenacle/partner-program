<?php
/**
 * Native WP_List_Table for the affiliates list.
 *
 * Mirrors the previous hand-rolled list in AffiliatesScreen::render_list():
 * same columns, same per-affiliate action links (with the same per-row
 * nonces), and the exact same data source — AffiliateRepo::count()/search()
 * plus the single grouped CommissionRepo::sums_for_affiliates() lookup.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Admin\Tables;

use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Domain\CommissionRepo;
use PartnerProgram\Domain\TierResolver;
use PartnerProgram\Support\Money;
use PartnerProgram\Support\SettingsRepo;

defined( 'ABSPATH' ) || exit;

final class AffiliatesTable extends AbstractListTable {

	/**
	 * Per-affiliate commission sums for the current page, keyed by affiliate id.
	 * Populated once in prepare_items() so column rendering stays query-free.
	 *
	 * @var array<int, array{pending: float|int, approved: float|int, paid: float|int}>
	 */
	private array $sums = [];

	public function __construct() {
		parent::__construct( [ 'singular' => 'affiliate', 'plural' => 'affiliates', 'ajax' => false ] );
	}

	public function get_columns(): array {
		return [
			'id'       => __( 'ID', 'partner-program' ),
			'user'     => __( 'User', 'partner-program' ),
			'code'     => __( 'Code', 'partner-program' ),
			'status'   => __( 'Status', 'partner-program' ),
			'tier'     => __( 'Tier', 'partner-program' ),
			'rate'     => __( 'Rate', 'partner-program' ),
			'pending'  => __( 'Pending', 'partner-program' ),
			'approved' => __( 'Approved', 'partner-program' ),
			'paid'     => __( 'Paid', 'partner-program' ),
		];
	}

	/**
	 * Only the columns the underlying AffiliateRepo::search() query can safely
	 * ORDER BY are sortable — its whitelist is id/created_at/status, and of the
	 * displayed columns only id and status map onto it. The aggregate money
	 * columns and the user/code/tier/rate columns are computed or joined and
	 * are NOT sortable by the repo, so they are intentionally left out rather
	 * than risk a fatal or an unsupported sort.
	 */
	public function get_sortable_columns(): array {
		return [
			'id'     => [ 'id', false ],
			'status' => [ 'status', false ],
		];
	}

	public function no_items(): void {
		esc_html_e( 'No affiliates found.', 'partner-program' );
	}

	/**
	 * Status filter rendered as native list-table views (the row of links above
	 * the table). Mirrors the status dropdown the screen used before, preserving
	 * the current ?s= search term across view switches.
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$current = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$statuses = [
			''          => __( 'All statuses', 'partner-program' ),
			'pending'   => __( 'Pending', 'partner-program' ),
			'approved'  => __( 'Approved', 'partner-program' ),
			'suspended' => __( 'Suspended', 'partner-program' ),
			'rejected'  => __( 'Rejected', 'partner-program' ),
		];

		$base = [ 'page' => 'partner-program-affiliates' ];
		if ( '' !== $search ) {
			$base['s'] = $search;
		}

		$views = [];
		foreach ( $statuses as $value => $label ) {
			$args = $base;
			if ( '' !== $value ) {
				$args['status'] = $value;
			}
			$url   = add_query_arg( $args, admin_url( 'admin.php' ) );
			$class = $current === $value ? ' class="current"' : '';
			$views[ '' === $value ? 'all' : $value ] = sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( $url ),
				$class, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
				esc_html( $label )
			);
		}

		return $views;
	}

	protected function get_primary_column_name(): string {
		return 'id';
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'id':
				return '#' . (int) $item['id'];

			case 'user':
				$user = get_userdata( (int) $item['user_id'] );
				return esc_html( $user ? $user->user_email : '—' );

			case 'code':
				return '<code>' . esc_html( (string) $item['referral_code'] ) . '</code>';

			case 'status':
				return esc_html( (string) $item['status'] );

			case 'tier':
				$tier_key   = isset( $item['current_tier_key'] ) ? (string) $item['current_tier_key'] : '';
				$tier_label = '—';
				if ( '' !== $tier_key ) {
					$tier       = TierResolver::tier_for_key( $tier_key );
					$tier_label = $tier && ! empty( $tier['label'] ) ? (string) $tier['label'] : $tier_key;
				}
				return esc_html( $tier_label );

			case 'rate':
				return esc_html( $this->format_effective_rate( $item ) );

			case 'pending':
			case 'approved':
			case 'paid':
				$totals = $this->sums[ (int) $item['id'] ] ?? [ 'pending' => 0, 'approved' => 0, 'paid' => 0 ];
				return esc_html( Money::format( $totals[ $column_name ] ) );
		}

		return '';
	}

	/**
	 * Primary column: the affiliate ID, with the per-row action links rendered
	 * beneath it. The action URLs + per-row nonce (pp_affiliate_action_{id})
	 * are byte-for-byte the same ones the screen built before — they still link
	 * back to this same admin page, where handle_actions() processes them.
	 *
	 * @param array<string, mixed> $item
	 */
	public function column_id( $item ): string {
		$base = admin_url( 'admin.php?page=partner-program-affiliates' );

		$mk = static fn( string $action, string $label ): string => sprintf(
			'<a href="%s">%s</a>',
			esc_url(
				wp_nonce_url(
					add_query_arg( [ 'action' => 'pp_affiliate_' . $action, 'id' => (int) $item['id'] ], $base ),
					'pp_affiliate_action_' . $item['id']
				)
			),
			esc_html( $label )
		);

		$actions = [];
		$actions['edit'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( add_query_arg( [ 'action' => 'edit', 'id' => (int) $item['id'] ], $base ) ),
			esc_html__( 'Edit', 'partner-program' )
		);
		if ( 'approved' !== $item['status'] ) {
			$actions['approve'] = $mk( 'approve', __( 'Approve', 'partner-program' ) );
		}
		if ( 'suspended' !== $item['status'] ) {
			$actions['suspend'] = $mk( 'suspend', __( 'Suspend', 'partner-program' ) );
		}

		// row_actions() escapes the wrapper; each link above is already escaped.
		return '#' . (int) $item['id'] . $this->row_actions( $actions );
	}

	public function prepare_items(): void {
		$status = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$per_page = $this->get_items_per_page( 'pp_affiliates_per_page', 50 );
		$page     = $this->get_pagenum();

		$total_items = AffiliateRepo::count( [ 'status' => $status, 'search' => $search ] );

		// resolve_orderby() returns "column DIRECTION" validated against the
		// sortable whitelist (id/status). Split it back out so it feeds
		// AffiliateRepo::search()'s own orderby/order whitelist rather than
		// being interpolated as raw SQL — search() does NOT take raw clauses.
		$resolved = $this->resolve_orderby( 'id', 'desc' );
		$parts    = explode( ' ', $resolved );
		$orderby  = $parts[0];
		$order    = ( isset( $parts[1] ) && 'ASC' === $parts[1] ) ? 'ASC' : 'DESC';

		$rows = AffiliateRepo::search(
			[
				'status'   => $status,
				'search'   => $search,
				'page'     => $page,
				'per_page' => $per_page,
				'orderby'  => $orderby,
				'order'    => $order,
			]
		);

		// One grouped query for the whole page instead of N×3 (pending /
		// approved / paid) round-trips.
		$ids        = array_map( static fn ( array $r ): int => (int) $r['id'], $rows );
		$this->sums = CommissionRepo::sums_for_affiliates( $ids );

		$this->items = $rows;

		$this->finalize_headers();
		$this->set_pagination_args(
			[
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / max( 1, $per_page ) ),
			]
		);
	}

	/**
	 * Effective commission rate for a row — identical logic to the screen's
	 * former private helper (custom override > tier rate > base rate).
	 *
	 * @param array<string, mixed> $row
	 */
	private function format_effective_rate( array $row ): string {
		if ( isset( $row['default_commission_rate'] ) && '' !== $row['default_commission_rate'] ) {
			$r = rtrim( rtrim( (string) $row['default_commission_rate'], '0' ), '.' );
			return sprintf( '%s%% *', $r );
		}
		$tier_key = (string) ( $row['current_tier_key'] ?? '' );
		if ( '' !== $tier_key ) {
			$tier = TierResolver::tier_for_key( $tier_key );
			if ( $tier && isset( $tier['rate'] ) ) {
				return (string) $tier['rate'] . '%';
			}
		}
		$base = (float) ( new SettingsRepo() )->get( 'commissions.base_rate', 15 );
		return (string) $base . '%';
	}
}
