<?php
/**
 * Szablon strony — renderuje treść (bespoke HTML z oryginału) między
 * nagłówkiem a stopką. <main id="main"> otwiera header.php, zamyka footer.php.
 */
get_header();
while ( have_posts() ) :
	the_post();
	the_content();
endwhile;
get_footer();
