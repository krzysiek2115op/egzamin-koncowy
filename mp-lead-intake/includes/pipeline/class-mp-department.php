<?php
/**
 * Dział (Dzial) pipeline — jeden z 11 etapów.
 *
 * Dział zawiera uporządkowaną listę par Agent+Krytyk (wieloagentowo) oraz
 * jedną Bramkę Jakości uruchamianą PO przejściu wszystkich agentów.
 * Przebieg: dla każdej pary Agent->run, potem Krytyk->review; jeśli Krytyk
 * odrzuci — STOP. Na końcu Bramka Jakości.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pojedynczy dział pipeline.
 */
class MP_Department {

	/** @var int Numer działu (1..11). */
	protected $number;

	/** @var string Klucz/slug działu. */
	protected $key;

	/** @var string Nazwa działu. */
	protected $label;

	/** @var string Opis działu. */
	protected $description;

	/** @var array Lista par: ['agent' => MP_Agent_Interface, 'critic' => MP_Critic_Interface]. */
	protected $pairs;

	/** @var MP_Quality_Gate Bramka jakości po dziale. */
	protected $gate;

	/**
	 * @param int             $number      Numer działu.
	 * @param string          $key         Slug.
	 * @param string          $label       Nazwa.
	 * @param string          $description Opis.
	 * @param array           $pairs       Pary Agent+Krytyk.
	 * @param MP_Quality_Gate $gate        Bramka jakości.
	 */
	public function __construct( $number, $key, $label, $description, array $pairs, MP_Quality_Gate $gate ) {
		$this->number      = (int) $number;
		$this->key         = $key;
		$this->label       = $label;
		$this->description = $description;
		$this->pairs       = $pairs;
		$this->gate        = $gate;
	}

	/**
	 * Przetwarza dział: agenci + krytycy, następnie bramka jakości.
	 *
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function process( MP_Context $context ) {
		foreach ( $this->pairs as $pair ) {
			/** @var MP_Agent_Interface $agent */
			$agent = $pair['agent'];
			/** @var MP_Critic_Interface $critic */
			$critic = $pair['critic'];

			$agent_result = $agent->run( $context );
			$review       = $critic->review( $agent_result, $context );

			if ( ! $review->is_ok() ) {
				// Krytyk wykrył błąd -> STOP (zasada procesu).
				return MP_Result::fail(
					$review->get_errors(),
					array(
						'agent'  => $agent->get_id(),
						'critic' => $critic->get_id(),
					),
					'critic_failed'
				);
			}

			// Dane agenta wpływają do wspólnego kontekstu (JSON).
			$context->merge( $agent_result->get_data() );
		}

		// Bramka jakości PO dziale.
		$gate_result = $this->gate->evaluate( $context );
		if ( ! $gate_result->is_ok() ) {
			return MP_Result::fail( $gate_result->get_errors(), array( 'gate' => $this->key ), 'gate_failed' );
		}

		return MP_Result::ok( $context->all() );
	}

	/** @return int */
	public function get_number() {
		return $this->number;
	}

	/** @return string */
	public function get_key() {
		return $this->key;
	}

	/** @return string */
	public function get_label() {
		return $this->label;
	}

	/** @return string */
	public function get_description() {
		return $this->description;
	}

	/** @return array */
	public function get_pairs() {
		return $this->pairs;
	}

	/** @return MP_Quality_Gate */
	public function get_gate() {
		return $this->gate;
	}
}
