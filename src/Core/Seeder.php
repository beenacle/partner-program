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
			[
				'key'     => 'module-2-peptide-research-library',
				'title'   => __( 'Module 2: Peptide Research Library', 'partner-program' ),
				'content' => self::module_2_content(),
			],
			[
				'key'     => 'module-3-marketing-resources',
				'title'   => __( 'Module 3: Marketing Resources', 'partner-program' ),
				'content' => self::module_3_content(),
			],
			[
				'key'     => 'module-4-faq',
				'title'   => __( 'Module 4: Frequently Asked Questions', 'partner-program' ),
				'content' => self::module_4_content(),
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

	private static function module_2_content(): string {
		$peptides = [
			[
				'name'     => __( 'Tirzepatide', 'partner-program' ),
				'class'    => __( 'Dual GIP / GLP-1 receptor agonist (investigational compound)', 'partner-program' ),
				'moa'      => __( 'Published research describes tirzepatide as a single peptide that acts as an agonist at two incretin receptors: the glucose-dependent insulinotropic polypeptide (GIP) receptor and the glucagon-like peptide-1 (GLP-1) receptor. Its mechanism of action is studied in the context of metabolic research.', 'partner-program' ),
				'research' => __( 'The mechanism and metabolic research endpoints have been investigated in the peer-reviewed SURPASS clinical research program. Reference these as published research findings, not as outcomes for personal use.', 'partner-program' ),
				'history'  => __( 'Developed by Eli Lilly and first characterized in the scientific literature in the late 2010s.', 'partner-program' ),
				'refs'     => [
					__( 'SURPASS clinical research program — peer-reviewed journals (e.g. The New England Journal of Medicine, The Lancet).', 'partner-program' ),
					__( 'Search "tirzepatide" on PubMed for the full publication history.', 'partner-program' ),
				],
			],
			[
				'name'     => __( 'Retatrutide', 'partner-program' ),
				'class'    => __( 'Triple GIP / GLP-1 / glucagon receptor agonist (investigational compound)', 'partner-program' ),
				'moa'      => __( 'Published research describes retatrutide as a single peptide acting as an agonist at three receptors — GIP, GLP-1, and the glucagon receptor — often referred to in the literature as a "triple agonist." Its mechanism is studied in metabolic research.', 'partner-program' ),
				'research' => __( 'Phase 2 research has been published in the peer-reviewed literature. Reference it as research findings only.', 'partner-program' ),
				'history'  => __( 'Developed by Eli Lilly; Phase 2 research results were published in 2023.', 'partner-program' ),
				'refs'     => [
					__( 'Phase 2 research publication, The New England Journal of Medicine (2023).', 'partner-program' ),
					__( 'Search "retatrutide" on PubMed.', 'partner-program' ),
				],
			],
			[
				'name'     => __( 'Cagrilintide', 'partner-program' ),
				'class'    => __( 'Long-acting amylin analog / amylin receptor agonist (investigational compound)', 'partner-program' ),
				'moa'      => __( 'Published research describes cagrilintide as a long-acting analog of the hormone amylin that acts at amylin receptors. It has been studied in the literature on its own and in combination with semaglutide.', 'partner-program' ),
				'research' => __( 'Investigated in published research, including combination research referred to in the literature as CagriSema. Reference it as research findings only.', 'partner-program' ),
				'history'  => __( 'Developed by Novo Nordisk.', 'partner-program' ),
				'refs'     => [
					__( 'Published Phase 1b / Phase 2 research in peer-reviewed journals.', 'partner-program' ),
					__( 'Search "cagrilintide" on PubMed.', 'partner-program' ),
				],
			],
			[
				'name'     => __( 'Semaglutide', 'partner-program' ),
				'class'    => __( 'GLP-1 receptor agonist (investigational compound)', 'partner-program' ),
				'moa'      => __( 'Published research describes semaglutide as an agonist at the glucagon-like peptide-1 (GLP-1) receptor. Its mechanism is extensively characterized in the peer-reviewed literature.', 'partner-program' ),
				'research' => __( 'One of the most extensively studied compounds in its class, with a large peer-reviewed literature base, including the SUSTAIN, PIONEER, and STEP research programs. Reference these as research findings only.', 'partner-program' ),
				'history'  => __( 'Developed by Novo Nordisk and characterized in the literature beginning in the 2010s.', 'partner-program' ),
				'refs'     => [
					__( 'SUSTAIN / PIONEER / STEP research program publications in peer-reviewed journals.', 'partner-program' ),
					__( 'Search "semaglutide" on PubMed.', 'partner-program' ),
				],
			],
		];

		$html  = '<p>' . esc_html__( 'This library summarizes the published research landscape for several research compounds. It is for education about the science only. These compounds are supplied strictly as research-use-only materials. Point interested parties to the peer-reviewed literature, and never provide quantity, reconstitution, injection, or personal-use guidance.', 'partner-program' ) . '</p>';

		foreach ( $peptides as $p ) {
			$html .= '<h2>' . esc_html( $p['name'] ) . '</h2>';
			$html .= '<p><em>' . esc_html( $p['class'] ) . '</em></p>';
			$html .= '<h3>' . esc_html__( 'Mechanism of action', 'partner-program' ) . '</h3><p>' . esc_html( $p['moa'] ) . '</p>';
			$html .= '<h3>' . esc_html__( 'Research landscape', 'partner-program' ) . '</h3><p>' . esc_html( $p['research'] ) . '</p>';
			$html .= '<h3>' . esc_html__( 'Historical development', 'partner-program' ) . '</h3><p>' . esc_html( $p['history'] ) . '</p>';
			$html .= '<h3>' . esc_html__( 'Literature references', 'partner-program' ) . '</h3><ul>';
			foreach ( $p['refs'] as $ref ) {
				$html .= '<li>' . esc_html( $ref ) . '</li>';
			}
			$html .= '</ul>';
		}

		$html .= '<h2>' . esc_html__( 'Compliance reminder', 'partner-program' ) . '</h2>';
		$html .= '<p>' . esc_html__( 'Discuss mechanism of action, published studies, research findings, and historical development only. Do not provide quantity, dosing, reconstitution, or injection guidance, do not make personal-use or weight-related claims, and do not position any compound as a substitute for Ozempic, Wegovy, Mounjaro, or Zepbound.', 'partner-program' ) . '</p>';

		return $html;
	}

	private static function module_3_content(): string {
		$html  = '<p>' . esc_html__( 'Use only approved, compliant messaging. Every public post must keep the research-use-only framing and include the required disclosure. Approved images and videos are published in the Materials tab of your portal.', 'partner-program' ) . '</p>';

		$html .= '<h2>' . esc_html__( 'Approved social media posts', 'partner-program' ) . '</h2>';
		$html .= '<p>' . esc_html__( 'Copy, adapt, and always keep the disclosure:', 'partner-program' ) . '</p>';
		$posts = [
			__( 'Curious about the science behind today\'s most-studied research compounds? The {program_name} supplies research-use-only materials for qualified research. Explore the published literature — not personal-use guidance. I may earn a commission; products are research use only and not for human consumption.', 'partner-program' ),
			__( 'New to research peptides? Start with the published studies. The {program_name} provides research-use-only compounds for qualified research purposes — not for human consumption, medical use, or personal use. #ResearchUseOnly', 'partner-program' ),
			__( 'My role is to help people understand research-only language and the published research landscape — not to give personal-use, dosing, or injection guidance. Always review the peer-reviewed literature. I may earn a commission through the {program_name}.', 'partner-program' ),
		];
		$html .= '<ul>';
		foreach ( $posts as $post ) {
			$html .= '<li>' . esc_html( $post ) . '</li>';
		}
		$html .= '</ul>';

		$html .= '<h2>' . esc_html__( 'Approved hashtags', 'partner-program' ) . '</h2>';
		$html .= '<p>#ResearchUseOnly #ResearchPeptides #ForResearchUseOnly #LabResearch #MetabolicResearch #PeptideScience #PublishedResearch</p>';

		$html .= '<h2>' . esc_html__( 'Images &amp; videos', 'partner-program' ) . '</h2>';
		$html .= '<p>' . esc_html__( 'Approved graphics and video clips are published in the Materials tab of your portal. Use only approved assets — do not create your own imagery that implies personal use.', 'partner-program' ) . '</p>';

		$html .= '<h2>' . esc_html__( 'Email templates', 'partner-program' ) . '</h2>';
		$html .= '<p><strong>' . esc_html__( 'Template — Introduction', 'partner-program' ) . '</strong></p>';
		$html .= '<blockquote>'
			. esc_html__( 'Subject: Research-use-only compounds from the {program_name}', 'partner-program' ) . '<br /><br />'
			. esc_html__( 'Hi [name], I share information about research-use-only compounds and the published scientific literature behind them. These materials are for qualified research only — not for human consumption or personal use. If you would like to review the research, reply and I will point you to published studies. I may earn a commission through the partner program.', 'partner-program' )
			. '</blockquote>';
		$html .= '<p>' . esc_html__( 'Every email must include the research-use-only and affiliate-commission disclosure.', 'partner-program' ) . '</p>';

		return $html;
	}

	private static function module_4_content(): string {
		$faqs = [
			[
				__( 'What is the difference between semaglutide and tirzepatide?', 'partner-program' ),
				__( 'They are different research compounds with different receptor targets. Published research describes semaglutide as a GLP-1 receptor agonist and tirzepatide as a dual GIP/GLP-1 receptor agonist. Both are supplied for research use only. Point people to the published literature rather than comparing personal-use outcomes.', 'partner-program' ),
			],
			[
				__( 'Where can I learn more?', 'partner-program' ),
				__( 'Review the peer-reviewed scientific literature. PubMed and the journal publications referenced in Module 2 are the best starting points. We do not provide personal-use, dosing, or reconstitution guidance.', 'partner-program' ),
			],
			[
				__( 'Why do you say "research use only"?', 'partner-program' ),
				__( 'These compounds are supplied strictly as research-use-only materials for qualified laboratory research. They are not for human consumption, are not medications or supplements, and are not FDA-approved for personal or medical use. RUO labeling is taken seriously, so all messaging stays research-focused.', 'partner-program' ),
			],
			[
				__( 'What studies are available?', 'partner-program' ),
				__( 'Published clinical research programs exist for several of these compounds — for example the SURPASS, SUSTAIN, PIONEER, and STEP programs in the peer-reviewed literature. Share these as published research findings, never as personal-use outcomes.', 'partner-program' ),
			],
			[
				__( 'Can you tell me how to use it or how much to take?', 'partner-program' ),
				__( 'No. We cannot provide personal-use, quantity, injection, or reconstitution guidance — these are research-use-only materials. We can point you to the published research so you can review the science.', 'partner-program' ),
			],
		];

		$html = '<p>' . esc_html__( 'Compliant answers to questions affiliates commonly hear. Use this language to stay research-focused.', 'partner-program' ) . '</p>';
		foreach ( $faqs as $faq ) {
			$html .= '<h3>' . esc_html( $faq[0] ) . '</h3><p>' . esc_html( $faq[1] ) . '</p>';
		}

		return $html;
	}
}
