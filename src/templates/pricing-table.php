<?php
/**
 * Manager pricing table template.
 *
 * Rendered from {@see \WCPricebook\Shortcodes::render_table()}. Available scope:
 *
 * @var \WCPricebook\Shortcodes $this       Shortcodes instance (exposes engine/config).
 * @var int                     $product_id Current product ID.
 * @var array                   $terms      Product flag + category term objects.
 *
 * @package WCPricebook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$product = wc_get_product( $product_id );
if ( ! $product ) {
	echo '<div>' . esc_html__( 'Pricing table unavailable.', 'wc-pricebook' ) . '</div>';
	return;
}

$engine = $this->engine;
$config = $this->config;

/* Build the rows: MSRP first, then every configured tier. */
$rows = array( 'msrp' => __( 'MSRP', 'wc-pricebook' ) );
foreach ( $config->tiers() as $tier_key => $tier ) {
	$rows[ $tier_key ] = $tier['label'];
}
?>
<div class="pricebook-table-wrap">
	<?php if ( ! empty( $terms ) ) : ?>
		<div class="pricebook-terms" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;font-size:12px;">
			<?php foreach ( $terms as $term ) : ?>
				<a href="<?php echo esc_url( (string) get_term_link( $term ) ); ?>"
					style="background:#f1f1f1;border-radius:999px;padding:2px 10px;color:#555;text-decoration:none;">
					<?php echo esc_html( $term->name ); ?>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<table class="pricebook-table" style="width:100%;border-collapse:collapse;">
		<thead>
			<tr>
				<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;"><?php esc_html_e( 'Role', 'wc-pricebook' ); ?></th>
				<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;"><?php esc_html_e( 'Regular Price', 'wc-pricebook' ); ?></th>
				<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;"><?php esc_html_e( 'Sale Price', 'wc-pricebook' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php
			foreach ( $rows as $tier_key => $label ) {
				$regular = $engine->price_as_tier( $product_id, $tier_key, false );
				$sale    = $engine->price_as_tier( $product_id, $tier_key, true );

				$regular_price = $regular[0];
				$sale_price    = $sale[0];

				$is_msrp = ( 'msrp' === $tier_key );
				if ( ! $is_msrp && ( '' === (string) $regular_price || $regular_price <= 0 ) ) {
					continue;
				}

				$has_sale = '' !== (string) $sale_price && is_numeric( $sale_price ) && (float) $sale_price > 0 && (float) $sale_price < (float) $regular_price;
				?>
				<tr>
					<td style="padding:6px;border-bottom:1px solid #f0f0f0;"><?php echo esc_html( $label ); ?></td>
					<td style="padding:6px;border-bottom:1px solid #f0f0f0;">
						<?php echo ( '' !== (string) $regular_price ) ? wp_kses_post( wc_price( $regular_price ) ) : '----'; ?>
					</td>
					<td style="padding:6px;border-bottom:1px solid #f0f0f0;">
						<?php echo $has_sale ? wp_kses_post( wc_price( $sale_price ) ) : '----'; ?>
					</td>
				</tr>
				<?php
			}

			/* Per-user overrides, if configured. */
			$user_pricing_meta = $config->user_pricing_meta();
			$user_pricing      = '' !== $user_pricing_meta ? get_post_meta( $product_id, $user_pricing_meta, true ) : '';
			if ( is_array( $user_pricing ) ) {
				foreach ( $user_pricing as $row ) {
					if ( ! isset( $row['user-id'] ) ) {
						continue;
					}
					$u = get_user_by( 'id', $row['user-id'] );
					if ( ! $u ) {
						continue;
					}
					$resolved = $engine->price_for_user( null, $product, (int) $row['user-id'], false );
					?>
					<tr>
						<td style="padding:6px;border-bottom:1px solid #f0f0f0;"><?php echo esc_html( $u->user_email ); ?></td>
						<td style="padding:6px;border-bottom:1px solid #f0f0f0;"><?php echo wp_kses_post( wc_price( $resolved[0] ) ); ?></td>
						<td style="padding:6px;border-bottom:1px solid #f0f0f0;">----</td>
					</tr>
					<?php
				}
			}
			?>
		</tbody>
	</table>
</div>
<?php
// The engine and config properties are accessed via $this in this template.
unset( $engine, $config );
