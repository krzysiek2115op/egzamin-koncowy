<?php
/**
 * Fallback motywu. Strony korzystają z page.php; to obsługuje pozostałe
 * widoki (archiwa, 404, wyniki wyszukiwania) w minimalnej formie.
 */
get_header();
if ( have_posts() ) :
	while ( have_posts() ) :
		the_post();
		echo '<section class="section"><div class="container">';
		the_title( '<h1>', '</h1>' );
		the_content();
		echo '</div></section>';
	endwhile;
else :
	echo '<section class="section"><div class="container"><h1>Nie znaleziono</h1><p>Strona nie istnieje. <a href="' . esc_url( home_url( '/' ) ) . '">Wróć na stronę główną</a>.</p></div></section>';
endif;
get_footer();
