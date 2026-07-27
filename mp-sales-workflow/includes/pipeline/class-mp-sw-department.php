<?php
/**
 * Dział pipeline — jeden z 9 etapów LP.3.
 *
 * Dział zawiera uporządkowaną listę par Agent+Krytyk (1:1) oraz jedną Bramkę
 * Jakości uruchamianą PO przejściu wszystkich par. Przebieg: Agent->run, potem
 * jego Krytyk->review; odrzucenie przez krytyka = STOP całego pipeline'u.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pojedynczy dział pipeline.
 */
class MP_SW_Department {

	/** @var int Numer działu (1..9). */
	protected $number;

	/** @var string Klucz/slug działu. */
	protected $key;

	/** @var string Nazwa działu. */
	protected $label;

	/** @var string Zakres działu wg diagramu. */
	protected $description;

	/** @var array Lista par: ['agent' => MP_SW_Agent_Interface, 'critic' => MP_SW_Critic_Interface]. */
	protected $pairs;

	/** @var MP_SW_Quality_Gate Bramka jakości po dziale. */
	protected $gate;

	/**
	 * @param int                $number      Numer działu.
	 * @param string             $key         Slug.
	 * @param string             $label       Nazwa.
	 * @param string             $description Zakres.
	 * @param array              $pairs       Pary Agent+Krytyk.
	 * @param MP_SW_Quality_Gate $gate        Bramka jakości.
	 */
	public function __construct( $number, $key, $label, $description, array $pairs, MP_SW_Quality_Gate $gate ) {
		$this->number      = (int) $number;
		$this->key         = $key;
		$this->label       = $label;
		$this->description = $description;
		$this->pairs       = $pairs;
		$this->gate        = $gate;
	}

	/**
	 * Przetwarza dział: pary agent+krytyk, następnie bramka jakości.
	 *
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function process( MP_SW_Context $context ) {
		foreach ( $this->pairs as $pair ) {
			/** @var MP_SW_Agent_Interface $agent */
			$agent = $pair['agent'];
			/** @var MP_SW_Critic_Interface $critic */
			$critic = $pair['critic'];

			$agent_result = $agent->run( $context );

			// Twardy błąd agenta zatrzymuje dział jeszcze przed krytykiem —
			// nie polegamy wyłącznie na tym, że krytyk go wychwyci.
			if ( ! $agent_result->is_ok() ) {
				$agent_data = $agent_result->get_data();

				return MP_SW_Result::fail(
					$agent_result->get_errors(),
					array(
						'agent'  => $agent->get_id(),
						'errors' => isset( $agent_data['errors'] ) ? $agent_data['errors'] : array(),
					),
					$agent_result->get_code() ? $agent_result->get_code() : 'agent_failed'
				);
			}

			$review = $critic->review( $agent_result, $context );

			if ( ! $review->is_ok() ) {
				$review_data = $review->get_data();

				return MP_SW_Result::fail(
					$review->get_errors(),
					array(
						'agent'  => $agent->get_id(),
						'critic' => $critic->get_id(),
						'errors' => isset( $review_data['errors'] ) ? $review_data['errors'] : array(),
					),
					'critic_failed'
				);
			}

			// Dane agenta wpływają do wspólnej koperty (JSON).
			$context->merge( $agent_result->get_data() );
		}

		$gate_result = $this->gate->evaluate( $context );

		if ( ! $gate_result->is_ok() ) {
			$gate_data = $gate_result->get_data();

			return MP_SW_Result::fail(
				$gate_result->get_errors(),
				array(
					'gate'   => $this->key,
					'errors' => isset( $gate_data['errors'] ) ? $gate_data['errors'] : array(),
				),
				'gate_failed'
			);
		}

		return MP_SW_Result::ok( $context->all() );
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

	/** @return MP_SW_Quality_Gate */
	public function get_gate() {
		return $this->gate;
	}
}
