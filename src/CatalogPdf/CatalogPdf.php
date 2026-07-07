<?php
/**
 * Product-catalog PDF module.
 *
 * A generic, print-ready product catalog rendered to PDF via mPDF. It loops the
 * WooCommerce product categories and renders a price table per category:
 *   - "full matrix" view: MSRP + one column per configured tier, and
 *   - "restricted" view: MSRP + the viewer's own price ("Your Price").
 *
 * Everything site-specific is exposed as a filter so a host (e.g. a companion
 * plugin or glue) can shape it without touching this class:
 *   - wc_pricebook_catalog_pdf_enabled            (bool)                 default false
 *   - wc_pricebook_catalog_pdf_shortcode          (string tag)           default 'wc_pricebook_catalog'
 *   - wc_pricebook_catalog_pdf_columns            (array key=>label)     default MSRP + all tiers
 *   - wc_pricebook_catalog_pdf_show_full_matrix   (bool, int $user_id)   default current-user-is-manager
 *   - wc_pricebook_catalog_pdf_excluded_categories(int[] term IDs)       default []
 *   - wc_pricebook_catalog_pdf_skip_product       (bool, int $product_id)default false
 *   - wc_pricebook_catalog_pdf_your_price_label   (string)               default 'Your Price'
 *   - wc_pricebook_catalog_pdf_button_label       (string)
 *   - wc_pricebook_catalog_pdf_filename           (string)
 *   - wc_pricebook_catalog_pdf_mpdf_config        (array)
 *
 * Requires the mPDF library (a Composer dependency of this plugin).
 *
 * @package WCPricebook\CatalogPdf
 */

namespace WCPricebook\CatalogPdf;

use WCPricebook\Config;
use WCPricebook\Context;
use WCPricebook\PriceEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CatalogPdf {

	/** admin-post.php action for the download endpoint. */
	const ACTION = 'wc_pricebook_catalog_pdf';

	/** Default shortcode tag (a host typically overrides this via filter). */
	const DEFAULT_SHORTCODE = 'wc_pricebook_catalog';

	/** @var Config */
	private $config;

	/** @var Context */
	private $context;

	/** @var PriceEngine */
	private $engine;

	public function __construct( Config $config, Context $context, PriceEngine $engine ) {
		$this->config  = $config;
		$this->context = $context;
		$this->engine  = $engine;
	}

	/**
	 * Register the shortcode + download endpoint. No-op unless a host enables the
	 * feature via the wc_pricebook_catalog_pdf_enabled filter.
	 */
	public function register() {
		/** @var bool $enabled */
		$enabled = apply_filters( 'wc_pricebook_catalog_pdf_enabled', false );
		if ( ! $enabled ) {
			return;
		}

		$tag = (string) apply_filters( 'wc_pricebook_catalog_pdf_shortcode', self::DEFAULT_SHORTCODE );
		if ( '' !== $tag ) {
			add_shortcode( $tag, array( $this, 'render_buttons' ) );
		}

		// URL-only shortcodes: output just the download URL (for custom links/buttons).
		$pdf_url_tag = (string) apply_filters( 'wc_pricebook_catalog_pdf_url_shortcode', 'catalog_pdf_url' );
		if ( '' !== $pdf_url_tag ) {
			add_shortcode( $pdf_url_tag, array( $this, 'pdf_url_shortcode' ) );
		}
		$csv_url_tag = (string) apply_filters( 'wc_pricebook_catalog_csv_url_shortcode', 'catalog_csv_url' );
		if ( '' !== $csv_url_tag ) {
			add_shortcode( $csv_url_tag, array( $this, 'csv_url_shortcode' ) );
		}

		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_download' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle_download' ) );
	}

	/**
	 * Shortcode: the PDF download URL (raw, for use in an href / link field).
	 *
	 * @return string
	 */
	public function pdf_url_shortcode() {
		return esc_url_raw( $this->download_url( 'pdf' ) );
	}

	/**
	 * Shortcode: the CSV download URL (raw, for use in an href / link field).
	 *
	 * @return string
	 */
	public function csv_url_shortcode() {
		return esc_url_raw( $this->download_url( 'csv' ) );
	}

	/**
	 * Nonce-protected download URL for the given format.
	 *
	 * @param string $format 'pdf' or 'csv'.
	 * @return string Raw URL (plain ampersands); callers escape for their context.
	 */
	private function download_url( $format ) {
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'format'   => $format,
				'_wpnonce' => wp_create_nonce( self::ACTION ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Shortcode output: download buttons for the catalog. Renders both PDF and CSV
	 * by default; pass format="pdf" or format="csv" to render just one.
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_buttons( $atts = array() ) {
		$atts   = shortcode_atts( array( 'format' => 'both' ), is_array( $atts ) ? $atts : array() );
		$format = in_array( $atts['format'], array( 'pdf', 'csv', 'both' ), true ) ? $atts['format'] : 'both';

		$out = '';
		if ( 'csv' !== $format ) {
			$out .= $this->button(
				'pdf',
				(string) apply_filters( 'wc_pricebook_catalog_pdf_button_label', __( 'Download Product Catalog (PDF)', 'wc-pricebook' ) )
			);
		}
		if ( 'pdf' !== $format ) {
			$out .= $this->button(
				'csv',
				(string) apply_filters( 'wc_pricebook_catalog_csv_button_label', __( 'Download Product Catalog (CSV)', 'wc-pricebook' ) )
			);
		}
		return $out;
	}

	/**
	 * A single download button for the given format.
	 *
	 * @param string $format 'pdf' or 'csv'.
	 * @param string $label  Button text.
	 * @return string
	 */
	private function button( $format, $label ) {
		$url = $this->download_url( $format );

		return sprintf(
			'<a href="%s" class="wc-pricebook-catalog-%s-button" style="display:inline-block;'
			. 'margin:0 8px 8px 0;padding:10px 18px;background:#2872c6;color:#fff;text-decoration:none;'
			. 'border-radius:4px;font-weight:600;">%s</a>',
			esc_url( $url ),
			esc_attr( $format ),
			esc_html( $label )
		);
	}

	/**
	 * admin-post handler: verify nonce, then stream the requested format.
	 */
	public function handle_download() {
		check_admin_referer( self::ACTION );

		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : 'pdf';
		if ( 'csv' === $format ) {
			$this->stream_csv();
		}
		$this->stream_pdf();
	}

	/**
	 * Build the catalog PDF and stream it as a download.
	 */
	private function stream_pdf() {
		if ( ! class_exists( \Mpdf\Mpdf::class ) ) {
			wp_die( esc_html__( 'PDF library (mPDF) is not installed.', 'wc-pricebook' ) );
		}

		$html     = $this->build_html();
		$filename = (string) apply_filters( 'wc_pricebook_catalog_pdf_filename', 'catalog-' . gmdate( 'Y-m-d' ) . '.pdf' );

		$tmp_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'wc-pricebook-mpdf';
		wp_mkdir_p( $tmp_dir );

		$mpdf_config = apply_filters(
			'wc_pricebook_catalog_pdf_mpdf_config',
			array(
				'mode'          => 'utf-8',
				'format'        => 'Letter',
				'margin_left'   => 10,
				'margin_right'  => 10,
				'margin_top'    => 12,
				'margin_bottom' => 12,
				'tempDir'       => $tmp_dir,
			)
		);

		$mpdf                  = new \Mpdf\Mpdf( $mpdf_config );
		$mpdf->showImageErrors = false; // Skip (don't fatal on) an unreachable logo image.
		$mpdf->WriteHTML( $html );
		$mpdf->Output( $filename, \Mpdf\Output\Destination::DOWNLOAD );
		exit;
	}

	/**
	 * Build the catalog CSV and stream it as a download.
	 *
	 * Columns: SKU, Name, Price (the viewer's own price), Description, Category, URL.
	 * The URL cell is a spreadsheet =HYPERLINK() so it shows a "Click to View" link.
	 */
	private function stream_csv() {
		$filename = (string) apply_filters( 'wc_pricebook_catalog_csv_filename', 'catalog-' . gmdate( 'Y-m-d' ) . '.csv' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel reads accents correctly.
		echo $this->build_csv(); // phpcs:ignore WordPress.Security.EscapeOutput -- CSV body, not HTML.
		exit;
	}

	/**
	 * Build the catalog CSV body: SKU, Name, Price (viewer's own), Description,
	 * Category, URL (a =HYPERLINK "Click to View" cell). One row per product.
	 *
	 * @return string
	 */
	private function build_csv() {
		$fh = fopen( 'php://temp', 'r+' );
		fputcsv( $fh, array( 'SKU', 'Name', 'Price', 'Description', 'Category', 'URL' ) );

		foreach ( $this->catalog_groups() as $group ) {
			$category_name = $group['term']->name;
			foreach ( $group['products'] as $product ) {
				if ( apply_filters( 'wc_pricebook_catalog_pdf_skip_product', false, $product->ID ) ) {
					continue;
				}
				$wc_product = wc_get_product( $product->ID );
				if ( ! $wc_product ) {
					continue;
				}
				fputcsv(
					$fh,
					array(
						$wc_product->get_sku(),
						$product->post_title,
						$this->user_price_number( $wc_product ),
						$this->product_description( $wc_product ),
						$category_name,
						$this->hyperlink_cell( get_permalink( $product->ID ) ),
					)
				);
			}
		}

		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );

		return $csv;
	}

	/**
	 * Build the full catalog HTML mPDF renders.
	 *
	 * @return string
	 */
	private function build_html() {
		$user_id   = $this->context->pricing_user_id();
		$show_all  = $this->show_full_matrix( $user_id );
		$columns   = $show_all ? $this->columns() : array( 'msrp' => __( 'MSRP', 'wc-pricebook' ) );
		$your_price_label = (string) apply_filters( 'wc_pricebook_catalog_pdf_your_price_label', __( 'Your Price', 'wc-pricebook' ) );

		$logo_html = $this->site_logo_html();
		$site_name = get_bloginfo( 'name' );
		$user      = wp_get_current_user();

		$groups = $this->catalog_groups();

		ob_start();
		?>
		<style>
			body { font-family: sans-serif; }
			h2 { margin-top: 18px; margin-bottom: 4px; font-size: 14px; color: #111; }
			table { width: 100%; border-collapse: collapse; }
			thead th { background-color: #f0f0f0; text-align: left; padding: 4px; font-size: 10px; }
			td { padding: 4px; font-size: 10px; border-bottom: 1px solid #e0e0e0; }
			.catalog-title { font-size: 22px; font-weight: 700; color: #2872c6; }
			.catalog-site { font-size: 12px; color: #333; }
			.catalog-date { font-size: 13px; color: #2872c6; }
			.catalog-user { font-size: 11px; color: #666; }
			.catalog-rule { border: 0; border-top: 3px solid #000; margin: 8px 0 12px 0; }
		</style>

		<table style="width:100%;border-collapse:collapse;margin-bottom:6px;">
			<tr>
				<td style="border:0;vertical-align:middle;">
					<?php echo $logo_html; // phpcs:ignore WordPress.Security.EscapeOutput -- built from an esc_attr'd local path / esc_url in site_logo_html(). ?>
					<?php if ( '' === $logo_html && '' !== $site_name ) : ?>
						<span class="catalog-title"><?php echo esc_html( $site_name ); ?></span>
					<?php endif; ?>
				</td>
				<td style="border:0;text-align:right;vertical-align:middle;">
					<div class="catalog-title"><?php esc_html_e( 'Product Catalog', 'wc-pricebook' ); ?></div>
					<?php if ( '' !== $logo_html && '' !== $site_name ) : ?>
						<div class="catalog-site"><?php echo esc_html( $site_name ); ?></div>
					<?php endif; ?>
					<div class="catalog-date"><?php echo esc_html( date_i18n( 'F j, Y' ) ); ?></div>
					<?php if ( $user->exists() ) : ?>
						<div class="catalog-user"><?php echo esc_html( $user->user_email ); ?></div>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<hr class="catalog-rule" />
		<?php

		if ( empty( $groups ) ) {
			echo '<p>' . esc_html__( 'No categories found.', 'wc-pricebook' ) . '</p>';
			return ob_get_clean();
		}

		foreach ( $groups as $group ) {
			$category = $group['term'];
			$products = $group['products'];

			echo '<h2>' . esc_html( $category->name ) . '</h2>';

			if ( empty( $products ) ) {
				echo '<p>' . esc_html__( 'No products found in this category.', 'wc-pricebook' ) . '</p>';
				continue;
			}

			echo '<table><thead><tr>';
			echo '<th style="width:' . ( $show_all ? '25' : '50' ) . '%;">' . esc_html__( 'Product Name', 'wc-pricebook' ) . '</th>';
			echo '<th>' . esc_html__( 'SKU', 'wc-pricebook' ) . '</th>';
			foreach ( $columns as $label ) {
				echo '<th>' . esc_html( $label ) . '</th>';
			}
			if ( ! $show_all ) {
				echo '<th>' . esc_html( $your_price_label ) . '</th>';
			}
			echo '</tr></thead><tbody>';

			foreach ( $products as $product ) {
				if ( apply_filters( 'wc_pricebook_catalog_pdf_skip_product', false, $product->ID ) ) {
					continue;
				}
				$wc_product = wc_get_product( $product->ID );
				if ( ! $wc_product ) {
					continue;
				}

				echo '<tr>';
				echo '<td>' . esc_html( $product->post_title ) . '</td>';
				echo '<td>' . esc_html( $wc_product->get_sku() ) . '</td>';
				foreach ( array_keys( $columns ) as $tier_key ) {
					echo '<td>' . wp_kses_post( $this->tier_cell( $product->ID, $tier_key ) ) . '</td>';
				}
				if ( ! $show_all ) {
					echo '<td>' . wp_kses_post( $this->user_cell( $wc_product ) ) . '</td>';
				}
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		return ob_get_clean();
	}

	/**
	 * Full-matrix column set: MSRP + every configured tier, filterable so a host
	 * can pick which tiers appear, in what order, with what labels.
	 *
	 * @return array<string,string> Ordered [ tier_key => column label ]. 'msrp' allowed.
	 */
	private function columns() {
		$default = array( 'msrp' => __( 'MSRP', 'wc-pricebook' ) );
		foreach ( $this->config->tiers() as $key => $tier ) {
			$default[ $key ] = isset( $tier['label'] ) ? $tier['label'] : $key;
		}
		$columns = apply_filters( 'wc_pricebook_catalog_pdf_columns', $default );
		return is_array( $columns ) ? $columns : $default;
	}

	/**
	 * Whether this viewer sees the full tier matrix (vs MSRP + "Your Price").
	 *
	 * @param int $user_id Resolved pricing user ID.
	 * @return bool
	 */
	private function show_full_matrix( $user_id ) {
		$default = $this->context->current_user_is_manager();
		return (bool) apply_filters( 'wc_pricebook_catalog_pdf_show_full_matrix', $default, $user_id );
	}

	/**
	 * Rendered price cell for a specific tier (or 'msrp').
	 *
	 * @param int    $product_id Product ID.
	 * @param string $tier_key   Tier key or 'msrp'.
	 * @return string
	 */
	private function tier_cell( $product_id, $tier_key ) {
		$result = $this->engine->price_as_tier( $product_id, $tier_key );
		return $this->format_price( is_array( $result ) ? $result[0] : $result );
	}

	/**
	 * Rendered "Your Price" cell for the current user.
	 *
	 * @param \WC_Product $product Product object.
	 * @return string
	 */
	private function user_cell( $product ) {
		$result = $this->engine->price_for_user( null, $product );
		return $this->format_price( is_array( $result ) ? $result[0] : $result );
	}

	/**
	 * Catalog contents shared by the PDF and CSV builders: product categories
	 * (honoring the excluded-categories filter) each with their published products,
	 * so both outputs include exactly the same set.
	 *
	 * @return array<int,array{term:\WP_Term,products:array<int,\WP_Post>}>
	 */
	private function catalog_groups() {
		$excluded   = (array) apply_filters( 'wc_pricebook_catalog_pdf_excluded_categories', array() );
		$categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'orderby'    => 'name',
				'order'      => 'ASC',
				'exclude'    => $excluded,
			)
		);

		if ( empty( $categories ) || is_wp_error( $categories ) ) {
			return array();
		}

		$groups = array();
		foreach ( $categories as $category ) {
			$products = get_posts(
				array(
					'post_type'      => 'product',
					'posts_per_page' => -1,
					'post_status'    => 'publish',
					'orderby'        => 'title',
					'order'          => 'ASC',
					'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						array(
							'taxonomy' => 'product_cat',
							'field'    => 'term_id',
							'terms'    => $category->term_id,
						),
					),
				)
			);
			$groups[] = array(
				'term'     => $category,
				'products' => $products,
			);
		}

		return $groups;
	}

	/**
	 * The viewer's own price as a plain number ("51.25"), or '' for empty/zero.
	 *
	 * @param \WC_Product $product Product object.
	 * @return string
	 */
	private function user_price_number( $product ) {
		$result = $this->engine->price_for_user( null, $product );
		$price  = is_array( $result ) ? $result[0] : $result;
		if ( empty( $price ) || 0.0 === (float) $price ) {
			return '';
		}
		return number_format( (float) $price, 2, '.', '' );
	}

	/**
	 * The product description as a single line of plain text (tags stripped,
	 * whitespace collapsed), falling back to the short description.
	 *
	 * @param \WC_Product $product Product object.
	 * @return string
	 */
	private function product_description( $product ) {
		$text = $product->get_description();
		if ( '' === trim( (string) $text ) ) {
			$text = $product->get_short_description();
		}
		$text = wp_strip_all_tags( (string) $text );
		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * A spreadsheet HYPERLINK cell that renders "Click to View" linking to the URL.
	 *
	 * @param string $url Product permalink.
	 * @return string
	 */
	private function hyperlink_cell( $url ) {
		if ( ! $url ) {
			return '';
		}
		// Pass the raw formula; fputcsv wraps the field and doubles the inner quotes
		// so Excel/Sheets parse =HYPERLINK("url","Click to View") correctly.
		return '=HYPERLINK("' . esc_url_raw( $url ) . '","Click to View")';
	}

	/**
	 * Blank for empty/zero, otherwise wc_price().
	 *
	 * @param mixed $price Raw price.
	 * @return string
	 */
	private function format_price( $price ) {
		if ( empty( $price ) || 0.0 === (float) $price ) {
			return '';
		}
		return wc_price( $price );
	}

	/**
	 * The site's Customizer custom logo as an <img> mPDF can embed. Resolves to a
	 * local file path (no network round-trip), falling back to the attachment URL.
	 *
	 * @return string
	 */
	private function site_logo_html() {
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( ! $logo_id ) {
			return '';
		}

		$path = get_attached_file( $logo_id );
		if ( $path && file_exists( $path ) ) {
			return '<img src="' . esc_attr( $path ) . '" style="height:56px;" />';
		}

		$url = wp_get_attachment_image_url( $logo_id, 'full' );
		return $url ? '<img src="' . esc_url( $url ) . '" style="height:56px;" />' : '';
	}
}
