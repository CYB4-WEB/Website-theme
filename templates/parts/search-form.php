<?php
/**
 * Template part: Advanced Search Form
 *
 * Multi-field AJAX search form for manga, novels, and videos.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$genres   = get_terms( array( 'taxonomy' => 'genre', 'hide_empty' => false ) );
$statuses = array(
	'ongoing'   => __( 'Ongoing', 'starter-theme' ),
	'completed' => __( 'Completed', 'starter-theme' ),
	'hiatus'    => __( 'Hiatus', 'starter-theme' ),
	'cancelled' => __( 'Cancelled', 'starter-theme' ),
);
$types = array(
	'manga' => __( 'Manga', 'starter-theme' ),
	'novel' => __( 'Novel', 'starter-theme' ),
	'comic' => __( 'Comic', 'starter-theme' ),
	'drama' => __( 'Drama', 'starter-theme' ),
	'video' => __( 'Video', 'starter-theme' ),
);
$sort_options = array(
	'latest'  => __( 'Latest', 'starter-theme' ),
	'popular' => __( 'Popular', 'starter-theme' ),
	'a-z'     => __( 'A &ndash; Z', 'starter-theme' ),
	'rating'  => __( 'Rating', 'starter-theme' ),
);
$current_year = (int) gmdate( 'Y' );
?>
<form class="advanced-search" role="search" aria-label="<?php esc_attr_e( 'Advanced search', 'starter-theme' ); ?>" data-advanced-search>

	<!-- Genres (checkboxes) -->
	<fieldset class="advanced-search__field advanced-search__field--genres">
		<legend><?php esc_html_e( 'Genres', 'starter-theme' ); ?></legend>
		<div class="advanced-search__genre-grid">
			<?php if ( ! empty( $genres ) && ! is_wp_error( $genres ) ) : ?>
				<?php foreach ( $genres as $genre ) : ?>
					<label class="advanced-search__checkbox">
						<input type="checkbox" name="genres[]" value="<?php echo esc_attr( $genre->slug ); ?>">
						<span><?php echo esc_html( $genre->name ); ?></span>
					</label>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
	</fieldset>

	<!-- Type -->
	<div class="advanced-search__field">
		<label for="adv-search-type"><?php esc_html_e( 'Type', 'starter-theme' ); ?></label>
		<select id="adv-search-type" name="type" class="advanced-search__select">
			<option value=""><?php esc_html_e( 'All Types', 'starter-theme' ); ?></option>
			<?php foreach ( $types as $val => $label ) : ?>
				<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>

	<!-- Status -->
	<div class="advanced-search__field">
		<label for="adv-search-status"><?php esc_html_e( 'Status', 'starter-theme' ); ?></label>
		<select id="adv-search-status" name="status" class="advanced-search__select">
			<option value=""><?php esc_html_e( 'All Statuses', 'starter-theme' ); ?></option>
			<?php foreach ( $statuses as $val => $label ) : ?>
				<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>

	<!-- Author / Artist -->
	<div class="advanced-search__field">
		<label for="adv-search-author"><?php esc_html_e( 'Author / Artist', 'starter-theme' ); ?></label>
		<input
			type="text"
			id="adv-search-author"
			name="author_artist"
			class="advanced-search__input"
			placeholder="<?php esc_attr_e( 'Author or artist name...', 'starter-theme' ); ?>"
			autocomplete="off"
			data-autocomplete="author_artist"
		>
	</div>

	<!-- Release year range -->
	<div class="advanced-search__field advanced-search__field--year-range">
		<span class="advanced-search__label"><?php esc_html_e( 'Release Year', 'starter-theme' ); ?></span>
		<div class="advanced-search__year-inputs">
			<label class="screen-reader-text" for="adv-search-year-from"><?php esc_html_e( 'From year', 'starter-theme' ); ?></label>
			<input type="number" id="adv-search-year-from" name="year_from" min="1950" max="<?php echo esc_attr( $current_year ); ?>" placeholder="<?php esc_attr_e( 'From', 'starter-theme' ); ?>" class="advanced-search__input advanced-search__input--year">
			<span aria-hidden="true">&ndash;</span>
			<label class="screen-reader-text" for="adv-search-year-to"><?php esc_html_e( 'To year', 'starter-theme' ); ?></label>
			<input type="number" id="adv-search-year-to" name="year_to" min="1950" max="<?php echo esc_attr( $current_year ); ?>" placeholder="<?php esc_attr_e( 'To', 'starter-theme' ); ?>" class="advanced-search__input advanced-search__input--year">
		</div>
	</div>

	<!-- Adult content filter -->
	<div class="advanced-search__field">
		<label class="advanced-search__checkbox">
			<input type="checkbox" name="exclude_adult" value="1">
			<span><?php esc_html_e( 'Exclude adult content', 'starter-theme' ); ?></span>
		</label>
	</div>

	<!-- Sort by -->
	<div class="advanced-search__field">
		<label for="adv-search-sort"><?php esc_html_e( 'Sort By', 'starter-theme' ); ?></label>
		<select id="adv-search-sort" name="sort" class="advanced-search__select">
			<?php foreach ( $sort_options as $val => $label ) : ?>
				<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>

	<!-- Actions -->
	<div class="advanced-search__actions">
		<button type="submit" class="btn btn--primary advanced-search__submit">
			<?php esc_html_e( 'Search', 'starter-theme' ); ?>
		</button>
		<button type="reset" class="btn btn--outline advanced-search__reset">
			<?php esc_html_e( 'Reset Filters', 'starter-theme' ); ?>
		</button>
	</div>

</form>
