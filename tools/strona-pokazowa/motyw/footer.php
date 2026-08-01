<?php defined( 'ABSPATH' ) || exit; // Szablon ladowany przez WordPressa, nie wywolywany wprost. ?>
</main>
<footer><div class="container">
<div class="foot-grid">
<div><a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="logo" aria-label="Kredyt Kompas — strona główna">
<svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
<circle cx="20" cy="20" r="18" stroke="#10B981" stroke-width="2.2"/>
<circle cx="20" cy="20" r="2" fill="#10B981"/>
<path class="needle" d="M20 6 L24 20 L20 34 L16 20 Z" fill="#10B981" fill-opacity=".25" stroke="#10B981" stroke-width="1.6"/>
</svg>
<span>Kredyt<em>Kompas</em></span></a>
<p style="max-width:300px">Niezależni eksperci kredytowi. Porównujemy oferty kilkunastu banków i prowadzimy Cię przez cały proces — od analizy zdolności po podpisanie umowy.</p></div>
<div><h4>Nawigacja</h4><ul>
<li><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Start</a></li><li><a href="<?php echo esc_url( home_url( '/kredyty-hipoteczne/' ) ); ?>">Kredyty hipoteczne</a></li>
<li><a href="<?php echo esc_url( home_url( '/kalkulator/' ) ); ?>">Kalkulator zdolności</a></li><li><a href="<?php echo esc_url( home_url( '/o-nas/' ) ); ?>">O nas</a></li>
<li><a href="<?php echo esc_url( home_url( '/faq/' ) ); ?>">FAQ</a></li><li><a href="<?php echo esc_url( home_url( '/kontakt/' ) ); ?>">Kontakt</a></li></ul></div>
<div><h4>Kontakt</h4><ul>
<li><a href="tel:+48500678799">+48 500 678 799</a></li>
<li><a href="mailto:kontakt@kompas.pl">kontakt@kompas.pl</a></li>
<li>ul. Przykładowa 13/3<br>00-001 Warszawa</li></ul></div>
<div><h4>Godziny pracy</h4><ul>
<li>pon.–pt.: 9:00–18:00</li><li>sobota: 10:00–14:00</li><li>niedziela: nieczynne</li></ul></div>
</div>
<div class="foot-bottom">
<span>© 2026 Kredyt Kompas. Wszelkie prawa zastrzeżone.</span>
<div class="legal"><a href="<?php echo esc_url( home_url( '/polityka-prywatnosci/' ) ); ?>">Polityka prywatności</a><a href="<?php echo esc_url( home_url( '/polityka-prywatnosci/' ) ); ?>#rodo">RODO</a></div>
</div></div></footer>
<?php wp_footer(); ?>
</body></html>
