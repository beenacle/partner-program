<?php
/**
 * Base for the plugin's native WP_List_Table admin lists.
 *
 * Encapsulates the boilerplate every list shares: loading core's WP_List_Table,
 * registering the column set for the Screen Options "Columns" panel, applying
 * hidden columns, and resolving a whitelisted sort. Subclasses implement
 * get_columns()/prepare_items() and the per-column rendering.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Admin\Tables;

defined( 'ABSPATH' ) || exit;

// WP_List_Table is only loaded on demand in wp-admin; pull it in so this class
// can extend it when the autoloader first reaches this file.
if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

abstract class AbstractListTable extends \WP_List_Table {

	/**
	 * Register the column set for this screen's Screen Options "Columns" panel
	 * so users can hide columns natively. Called once on the page's load hook.
	 */
	public function register_screen_columns(): void {
		add_filter( "manage_{$this->screen->id}_columns", [ $this, 'get_columns' ] );
	}

	/**
	 * Finalize the [columns, hidden, sortable, primary] header tuple — call at
	 * the end of prepare_items() so hidden-column preferences take effect.
	 */
	protected function finalize_headers(): void {
		$this->_column_headers = [
			$this->get_columns(),
			get_hidden_columns( $this->screen ),
			$this->get_sortable_columns(),
			$this->get_primary_column_name(),
		];
	}

	/**
	 * Resolve a safe ORDER BY clause from the request against the sortable
	 * column whitelist. Returns "column DIRECTION" with both sides validated, so
	 * the result is always safe to interpolate into SQL.
	 *
	 * @param string $default_col Column to sort by when none/invalid is requested.
	 * @param string $default_dir 'asc' or 'desc'.
	 */
	protected function resolve_orderby( string $default_col, string $default_dir = 'desc' ): string {
		$sortable  = $this->get_sortable_columns();
		$allowed   = [];
		foreach ( $sortable as $key => $spec ) {
			// Spec is [db_column, is_default] or just a bool; map the request key
			// to the real DB column, defaulting to the array key itself.
			$allowed[ $key ] = is_array( $spec ) ? (string) $spec[0] : $key;
		}

		$requested = isset( $_GET['orderby'] ) ? sanitize_key( (string) $_GET['orderby'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$col       = $allowed[ $requested ] ?? ( $allowed[ $default_col ] ?? $default_col );

		$dir = isset( $_GET['order'] ) && 'asc' === strtolower( (string) $_GET['order'] ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['order'] ) && 'asc' === strtolower( $default_dir ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$dir = 'ASC';
		}

		return $col . ' ' . $dir;
	}
}
