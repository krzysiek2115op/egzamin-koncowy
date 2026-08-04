<?php
/**
 * Nagłówek motywu Kredyt Kompas (identyczny na wszystkich stronach).
 */
defined( 'ABSPATH' ) || exit; // Szablon ladowany przez WordPressa, nie wywolywany wprost.

$kk_slug = kk_current_slug();
?><!DOCTYPE html>
<?php
/*
 * Język i kodowanie ZE WORDPRESSA, nie z klawiatury.
 *
 * `lang="pl"` i `charset="UTF-8"` były wpisane wprost, więc instalacja
 * postawiona po angielsku ogłaszała czytnikom ekranu i wyszukiwarkom polski.
 * `language_attributes()` i `bloginfo( 'charset' )` to standardowy sposób
 * WordPressa; motyw demonstracyjny ma pokazywać, jak się to robi, a nie jak
 * się tego obchodzi.
 */
?>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://images.unsplash.com" crossorigin>
<meta name="theme-color" content="#081730">
<meta property="og:type" content="website">
<meta property="og:locale" content="pl_PL">
<meta property="og:site_name" content="Kredyt Kompas">
<meta property="og:title" content="Kredyt Kompas — eksperci kredytów hipotecznych">
<meta property="og:description" content="Niezależni eksperci kredytów hipotecznych w Warszawie. Porównujemy oferty kilkunastu banków. Bezpłatna konsultacja i kalkulator zdolności online.">
<meta property="og:image" content="https://images.unsplash.com/photo-1556761175-b413da4baf72?auto=format&fit=crop&w=1200&q=80">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'%3E%3Ccircle cx='20' cy='20' r='18' fill='none' stroke='%2310B981' stroke-width='3'/%3E%3Cpath d='M20 6 L24 20 L20 34 L16 20 Z' fill='%2310B981'/%3E%3C/svg%3E">
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "FinancialService",
      "@id": "<?php echo esc_url( home_url( '/' ) ); ?>#organization",
      "name": "Kredyt Kompas",
      "description": "Niezależni eksperci kredytów hipotecznych w Warszawie. Porównujemy oferty kilkunastu banków, negocjujemy warunki i prowadzimy klienta przez cały proces kredytowy.",
      "url": "<?php echo esc_url( home_url( '/' ) ); ?>",
      "telephone": "+48500678799",
      "email": "kontakt@kompas.pl",
      "priceRange": "Bezpłatnie dla klienta (0 zł)",
      "currenciesAccepted": "PLN",
      "address": { "@type": "PostalAddress", "streetAddress": "ul. Przykładowa 13/3", "postalCode": "00-001", "addressLocality": "Warszawa", "addressCountry": "PL" },
      "areaServed": { "@type": "City", "name": "Warszawa" },
      "openingHoursSpecification": [
        { "@type": "OpeningHoursSpecification", "dayOfWeek": ["Monday","Tuesday","Wednesday","Thursday","Friday"], "opens": "09:00", "closes": "18:00" },
        { "@type": "OpeningHoursSpecification", "dayOfWeek": "Saturday", "opens": "10:00", "closes": "14:00" }
      ]
    }
  ]
}
</script>
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<header class="nav on-hero"><div class="container nav-inner">
<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="logo" aria-label="Kredyt Kompas — strona główna">
<svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
<circle cx="20" cy="20" r="18" stroke="#10B981" stroke-width="2.2"/>
<circle cx="20" cy="20" r="2" fill="#10B981"/>
<path class="needle" d="M20 6 L24 20 L20 34 L16 20 Z" fill="#10B981" fill-opacity=".25" stroke="#10B981" stroke-width="1.6"/>
</svg>
<span>Kredyt<em>Kompas</em></span></a>
<nav aria-label="Menu główne"><ul class="nav-links">
<?php foreach ( kk_menu_items() as $slug => $label ) :
	$active = ( $slug === $kk_slug ) ? ' class="active"' : '';
	?><li><a href="<?php echo esc_url( kk_url( $slug ) ); ?>"<?php echo $active; ?>><?php echo esc_html( $label ); ?></a></li>
<?php endforeach; ?>
</ul></nav>
<a class="nav-cta" href="<?php echo esc_url( home_url( '/kontakt/' ) ); ?>#formularz">Bezpłatna konsultacja</a>
<button class="burger" aria-label="Otwórz menu" aria-expanded="false"><span></span><span></span><span></span></button>
</div></header>
<main id="main">
