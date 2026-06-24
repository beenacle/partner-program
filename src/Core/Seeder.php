<?php
/**
 * Seeds default portal training content (pp_module posts).
 *
 * Idempotent: every seeded module carries a `_pp_seed_key` meta flag, so
 * re-running never duplicates a module. Pass $force to overwrite the body of
 * an already-seeded module with the current default.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Core;

use PartnerProgram\Support\SettingsRepo;

defined( 'ABSPATH' ) || exit;

final class Seeder {

	private const SEED_META = '_pp_seed_key';

	/**
	 * @return array<string, string> map of seed_key => 'created'|'updated'|'skipped'
	 */
	public static function seed_modules( bool $force = false ): array {
		$program = (string) ( new SettingsRepo() )->get( 'general.program_name', __( 'Partner Program', 'partner-program' ) );
		$result  = [];

		foreach ( self::default_modules() as $order => $module ) {
			$key      = $module['key'];
			$existing = self::find_seeded( $key );
			$content  = str_replace( '{program_name}', $program, $module['content'] );

			if ( $existing && ! $force ) {
				$result[ $key ] = 'skipped';
				continue;
			}

			$postarr = [
				'post_type'    => 'pp_module',
				'post_status'  => 'publish',
				'post_title'   => $module['title'],
				'post_content' => $content,
				'menu_order'   => $order + 1,
			];

			if ( $existing ) {
				$postarr['ID']  = $existing;
				$result[ $key ] = 'updated';
			} else {
				$result[ $key ] = 'created';
			}

			$id = wp_insert_post( $postarr, true );
			if ( ! is_wp_error( $id ) && $id ) {
				update_post_meta( (int) $id, self::SEED_META, $key );
			} else {
				$result[ $key ] = 'error';
			}
		}

		return $result;
	}

	private static function find_seeded( string $key ): ?int {
		$posts = get_posts(
			[
				'post_type'        => 'pp_module',
				// Explicit list incl. 'trash': the 'any' status excludes
				// trashed posts, so without this a re-seed after an admin
				// trashed Module 1 would create a duplicate.
				'post_status'      => [ 'publish', 'pending', 'draft', 'future', 'private', 'trash' ],
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'meta_key'         => self::SEED_META,
				'meta_value'       => $key,
				'suppress_filters' => true,
			]
		);
		return $posts ? (int) $posts[0] : null;
	}

	/**
	 * @return array<int, array{key:string, title:string, content:string}>
	 */
	private static function default_modules(): array {
		return [
			[
				'key'     => 'module-1-compliance-fundamentals',
				'title'   => __( 'Module 1: Compliance Fundamentals', 'partner-program' ),
				'content' => self::module_1_content(),
			],
		];
	}

	private static function module_1_content(): string {
		$rows = [
			[ 'shots', 'research compounds' ],
			[ 'weight loss', 'metabolic research' ],
			[ 'dose / dosing', 'study concentration / research protocol' ],
			[ 'take / use', 'evaluate / study' ],
			[ 'inject', 'reconstitute for lab research only' ],
			[ 'patient / customer using it', 'researcher / research subject model' ],
			[ 'results', 'research findings / published data' ],
			[ 'treatment / therapy', 'investigational compound' ],
			[ 'safe / effective', 'studied / under investigation' ],
		];
		$table = '<table><thead><tr><th>' . esc_html__( 'Do not say', 'partner-program' ) . '</th><th>' . esc_html__( 'Say instead', 'partner-program' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$table .= '<tr><td>' . esc_html( $row[0] ) . '</td><td>' . esc_html( $row[1] ) . '</td></tr>';
		}
		$table .= '</tbody></table>';

		$html  = '<h2>' . esc_html__( 'What "Research Use Only" (RUO) means', 'partner-program' ) . '</h2>';
		$html .= '<p>' . esc_html__( 'Every product is sold strictly as a research-use-only material for qualified laboratory research. RUO products are NOT:', 'partner-program' ) . '</p>';
		$html .= '<ul>'
			. '<li>' . esc_html__( 'For human consumption', 'partner-program' ) . '</li>'
			. '<li>' . esc_html__( 'Medications or prescription drugs', 'partner-program' ) . '</li>'
			. '<li>' . esc_html__( 'Dietary supplements', 'partner-program' ) . '</li>'
			. '<li>' . esc_html__( 'For weight loss, injections, dosing, or treatment', 'partner-program' ) . '</li>'
			. '<li>' . esc_html__( 'FDA-approved for personal or medical use', 'partner-program' ) . '</li>'
			. '</ul>';
		$html .= '<p>' . esc_html__( 'Your role is to help people understand the research-only language, the published research landscape, and how to stay compliant when discussing these compounds. You cannot provide dosing, injection, medical, or personal-use instructions.', 'partner-program' ) . '</p>';

		$html .= '<h2>' . esc_html__( 'Words to use vs. words to avoid', 'partner-program' ) . '</h2>';
		$html .= $table;

		$html .= '<h2>' . esc_html__( 'FTC disclosure requirements', 'partner-program' ) . '</h2>';
		$html .= '<p>' . esc_html__( 'The FTC requires that paid endorsements and health-related claims be truthful, non-misleading, and clearly disclosed. Include this disclosure on every post:', 'partner-program' ) . '</p>';
		$html .= '<blockquote>' . esc_html__( '"I may earn a commission through the {program_name}. Products are sold for research use only and are not for human consumption."', 'partner-program' ) . '</blockquote>';

		$html .= '<h2>' . esc_html__( 'Social media compliance', 'partner-program' ) . '</h2>';
		$html .= '<ul>'
			. '<li>' . esc_html__( 'Keep content research-focused: mechanism of action, published studies, and research findings — never personal results.', 'partner-program' ) . '</li>'
			. '<li>' . esc_html__( 'Never explain how much to take, how to inject, how to reconstitute for personal use, or how often to use.', 'partner-program' ) . '</li>'
			. '<li>' . esc_html__( 'Never position any product as a substitute for Ozempic, Wegovy, Mounjaro, or Zepbound.', 'partner-program' ) . '</li>'
			. '<li>' . esc_html__( 'Always include the affiliate and research-use-only disclosure.', 'partner-program' ) . '</li>'
			. '</ul>';
		$html .= '<p><strong>' . esc_html__( 'Recommended compliance footer:', 'partner-program' ) . '</strong></p>';
		$html .= '<blockquote>' . esc_html__( 'Products are intended for research use only. Not for human consumption. Not intended to diagnose, treat, cure, or prevent any disease. Not FDA-approved for personal or medical use. Affiliates may receive commission from qualifying purchases.', 'partner-program' ) . '</blockquote>';

		$html .= '<h2>' . esc_html__( 'The affiliate agreement', 'partner-program' ) . '</h2>';
		$html .= '<p>' . esc_html__( 'By participating you agree to follow this compliance policy. Violations — including human-use, dosing, or weight-loss claims — can result in termination from the program and forfeiture of unpaid commissions. When in doubt: keep it research-focused, disclose, and avoid human-use language.', 'partner-program' ) . '</p>';

		return $html;
	}
}
