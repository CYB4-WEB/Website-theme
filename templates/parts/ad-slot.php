<?php
/**
 * Template part: Ad Slot
 *
 * Renders an ad container for a given location. The ad code is pulled
 * from theme options via the filterable `starter_get_ad` helper.
 *
 * @package starter-theme
 * @since   1.0.0
 *
 * @param array $args {
 *     @type string $location  Ad placement key, e.g. 'before_content', 'sidebar'.
 *     @type string $class     Optional extra CSS class.
 * }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ad_location = isset( $args['location'] ) ? sanitize_key( $args['location'] ) : '';
$ad_class    = isset( $args['class'] ) ? sanitize_html_class( $args['class'] ) : '';

if ( empty( $ad_location ) ) {
	return;
}

/**
 * Filters the ad code for a specific location.
 *
 * @param string $ad_code  HTML ad code (empty string if none configured).
 * @param string $location The ad placement key.
 */
$ad_code = apply_filters( 'starter_get_ad', '', $ad_location );

if ( empty( $ad_code ) ) {
	return;
}
?>
<div class="ad-slot ad-slot--<?php echo esc_attr( $ad_location ); ?> <?php echo esc_attr( $ad_class ); ?>" data-ad-location="<?php echo esc_attr( $ad_location ); ?>" aria-label="<?php esc_attr_e( 'Advertisement', 'starter-theme' ); ?>" role="complementary">
	<div class="ad-slot__inner">
		<?php echo wp_kses_post( $ad_code ); ?>
	</div>
</div>
