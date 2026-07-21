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
	 * @param array $atts Atrybuty shortcode.
	 * @return string HTML.
	 */
	public static function render( $atts ) {
		unset( $atts );

		wp_enqueue_style( 'mp-lead-form' );
		wp_enqueue_script( 'mp-lead-form' );

		$nonce = wp_create_nonce( 'mp_lead_intake' );

		ob_start();
		?>
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

			<label class="mp-consent">
				<input type="checkbox" name="consent_rodo" value="1" required>
				<span><?php esc_html_e( 'Wyrażam zgodę na przetwarzanie danych (RODO)', 'mp-lead-intake' ); ?> *</span>
			</label>
			<label class="mp-consent">
				<input type="checkbox" name="consent_marketing" value="1">
				<span><?php esc_html_e( 'Zgoda marketingowa (opcjonalnie)', 'mp-lead-intake' ); ?></span>
			</label>

			<!-- Honeypot antyspamowy: pole ukryte, człowiek go nie wypełnia. -->
			<input type="text" name="mp_hp" class="mp-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
			<input type="hidden" name="mp_nonce" value="<?php echo esc_attr( $nonce ); ?>">

			<button type="submit"><?php esc_html_e( 'Wyślij zapytanie', 'mp-lead-intake' ); ?></button>
			<div class="mp-form-msg" role="status" aria-live="polite"></div>
		</form>
		<?php
		/**
		 * Hook dla pozostałych wtyczek (plugin 2/3) — mogą dołożyć treść pod formularzem.
		 */
		do_action( 'mp_lead_intake_after_form' );

		return (string) ob_get_clean();
	}
}
