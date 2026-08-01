<?php
/**
 * Formularz B2B wtyczki MP Lead Intake — shortcode [mp_lead_intake_form].
 *
 * Renderuje formularz i ładuje minimalne, neutralne style (dziedziczące wygląd
 * motywu) oraz skrypt wysyłający zgłoszenie przez "1 AJAX".
 *
 * Oficjalne API: add_shortcode, wp_enqueue_script/style, wp_localize_script,
 * wp_create_nonce.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode i renderowanie formularza.
 */
class MP_Lead_Intake_Form {

	const SHORTCODE = 'mp_lead_intake_form';

	/**
	 * Kraje UE (ISO 3166-1 alpha-2) do wyboru w formularzu — VIES obsługuje
	 * wyłącznie kraje UE. Wyjątek: Grecja jako 'EL' (nie ISO 'GR') — REST API
	 * Komisji Europejskiej (VIES) przyjmuje ten kod krajowy dla Grecji zamiast
	 * ISO 'GR'; ta jedna rozbieżność NIE jest opisana w docs/dzial-03/biala-lista-vat-api.md
	 * (pobranym wcześniej fragmencie), więc nie cytujemy jej jako stamtąd.
	 */
	const COUNTRIES = array(
		'PL' => 'Polska',
		'AT' => 'Austria',
		'BE' => 'Belgia',
		'BG' => 'Bułgaria',
		'CY' => 'Cypr',
		'CZ' => 'Czechy',
		'DE' => 'Niemcy',
		'DK' => 'Dania',
		'EE' => 'Estonia',
		'EL' => 'Grecja',
		'ES' => 'Hiszpania',
		'FI' => 'Finlandia',
		'FR' => 'Francja',
		'HR' => 'Chorwacja',
		'HU' => 'Węgry',
		'IE' => 'Irlandia',
		'IT' => 'Włochy',
		'LT' => 'Litwa',
		'LU' => 'Luksemburg',
		'LV' => 'Łotwa',
		'MT' => 'Malta',
		'NL' => 'Holandia',
		'PT' => 'Portugalia',
		'RO' => 'Rumunia',
		'SE' => 'Szwecja',
		'SI' => 'Słowenia',
		'SK' => 'Słowacja',
	);

	/**
	 * Rejestruje shortcode i rejestrację assetów.
	 *
	 * @return void
	 */
	public static function register() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Rejestruje (bez ładowania) style i skrypt formularza.
	 *
	 * @return void
	 */
	public static function register_assets() {
		wp_register_style( 'mp-lead-form', MP_LEAD_INTAKE_URL . 'assets/css/mp-form.css', array(), MP_LEAD_INTAKE_VERSION );
		wp_register_script( 'mp-lead-form', MP_LEAD_INTAKE_URL . 'assets/js/mp-form.js', array(), MP_LEAD_INTAKE_VERSION, true );
		wp_localize_script(
			'mp-lead-form',
			'MP_LEAD_INTAKE',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => MP_Lead_Intake_Ajax::ACTION,
			)
		);
	}

	/**
	 * Renderuje formularz (shortcode).
	 *
	 * @param array $atts Atrybuty shortcode. `heading` (domyślnie "1") — pokaż
	 *              nagłówek H1 + krótki wstęp; wyłącz `heading="0"`, gdy
	 *              shortcode jest wklejany w treść, która ma już własny tytuł.
	 * @return string HTML.
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts( array( 'heading' => '1' ), (array) $atts, self::SHORTCODE );

		wp_enqueue_style( 'mp-lead-form' );
		wp_enqueue_script( 'mp-lead-form' );

		$nonce = wp_create_nonce( 'mp_lead_intake' );

		ob_start();
		?>
		<div class="mp-lead-intake-wrap">
		<?php if ( '0' !== (string) $atts['heading'] ) : ?>
			<?php // Literały (nie MP_Lead_Intake_Page::TITLE/DESCRIPTION) — WPCS I18n wymaga dosłownego stringa w __()/esc_html__(). ?>
			<h1 class="mp-lead-intake-title"><?php esc_html_e( 'Zapytanie ofertowe', 'mp-lead-intake' ); ?></h1>
			<p class="mp-lead-intake-lead"><?php esc_html_e( 'Wypełnij formularz zapytania ofertowego, a nasz zespół skontaktuje się z Tobą z indywidualną ofertą.', 'mp-lead-intake' ); ?></p>
		<?php endif; ?>
		<form class="mp-lead-form" method="post" novalidate>
			<div class="mp-field">
				<label for="mp-company"><?php esc_html_e( 'Nazwa firmy', 'mp-lead-intake' ); ?> *</label>
				<input type="text" id="mp-company" name="company_name" required>
			</div>
			<div class="mp-field">
				<label for="mp-nip"><?php esc_html_e( 'NIP', 'mp-lead-intake' ); ?> *</label>
				<input type="text" id="mp-nip" name="nip" inputmode="numeric" required>
			</div>
			<div class="mp-field">
				<label for="mp-email">E-mail *</label>
				<input type="email" id="mp-email" name="email" required>
			</div>
			<div class="mp-field">
				<label for="mp-phone"><?php esc_html_e( 'Telefon', 'mp-lead-intake' ); ?></label>
				<input type="tel" id="mp-phone" name="phone">
			</div>
			<div class="mp-field">
				<label for="mp-segment"><?php esc_html_e( 'Segment / branża', 'mp-lead-intake' ); ?></label>
				<input type="text" id="mp-segment" name="segment">
			</div>
			<div class="mp-field">
				<label for="mp-country"><?php esc_html_e( 'Kraj', 'mp-lead-intake' ); ?></label>
				<select id="mp-country" name="country">
					<?php foreach ( self::COUNTRIES as $code => $label ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>"<?php echo ( 'PL' === $code ) ? ' selected' : ''; ?>><?php echo esc_html( $label ); ?> (<?php echo esc_html( $code ); ?>)</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="mp-field">
				<label for="mp-products"><?php esc_html_e( 'Produkty / zakres zainteresowania', 'mp-lead-intake' ); ?></label>
				<textarea id="mp-products" name="products" rows="3"></textarea>
			</div>
			<div class="mp-field">
				<label for="mp-volume"><?php esc_html_e( 'Przewidywany wolumen', 'mp-lead-intake' ); ?></label>
				<input type="text" id="mp-volume" name="est_volume" placeholder="<?php echo esc_attr__( 'np. 100 szt./mies.', 'mp-lead-intake' ); ?>">
			</div>

			<label class="mp-consent">
				<input type="checkbox" name="consent_rodo" value="1" required>
				<span><?php esc_html_e( 'Wyrażam zgodę na przetwarzanie danych (RODO)', 'mp-lead-intake' ); ?> *</span>
			</label>
			<label class="mp-consent">
				<input type="checkbox" name="consent_marketing" value="1">
				<span><?php esc_html_e( 'Zgoda marketingowa (opcjonalnie)', 'mp-lead-intake' ); ?></span>
			</label>

			<!-- Honeypot antyspamowy: pole ukryte, człowiek go nie wypełnia. Ukrycie inline
			(nie tylko przez assets/css/mp-form.css) — bot renderujący HTML bez arkusza
			stylów (np. proste narzędzie do wypełniania formularzy) nadal nie zobaczy pola. -->
			<input type="text" name="mp_hp" class="mp-hp" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">
			<input type="hidden" name="mp_nonce" value="<?php echo esc_attr( $nonce ); ?>">

			<button type="submit"><?php esc_html_e( 'Wyślij zapytanie', 'mp-lead-intake' ); ?></button>
			<div class="mp-form-msg" role="status" aria-live="polite"></div>
		</form>
		<?php
		/**
		 * Hook dla pozostałych wtyczek (plugin 2/3) — mogą dołożyć treść pod formularzem.
		 */
		do_action( 'mp_lead_intake_after_form' );
		?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
