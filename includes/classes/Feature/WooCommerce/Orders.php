<?php
/**
 * WooCommerce Orders Feature
 *
 * @package elasticpress
 */

namespace ElasticPress\Feature\WooCommerce;

use ElasticPress\Elasticsearch;
use ElasticPress\Indexables;
use ElasticPress\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * WooCommerce Orders Feature
 *
 * @since 4.5.0
 * @package ElasticPress\Feature\WooCommerce
 */
class Orders {
	/**
	 * Initialize feature.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->index = Indexables::factory()->get( 'post' )->get_index_name();
	}

	/**
	 * Setup feature functionality.
	 *
	 * @return void
	 */
	public function setup() {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_filter( 'ep_after_update_feature', [ $this, 'after_update_feature' ], 10, 3 );
		add_filter( 'ep_after_sync_index', [ $this, 'epio_save_search_template' ] );
		add_filter( 'ep_saved_weighting_configuration', [ $this, 'epio_save_search_template' ] );
	}

	/**
	 * Get the endpoint for WooCommerce Orders search.
	 *
	 * @return string WooCommerce orders search endpoint.
	 */
	public function get_search_endpoint() {
		/**
		 * Filters the WooCommerce Orders search endpoint.
		 *
		 * @since 4.5.0
		 * @hook ep_woocommerce_orders_search_endpoint
		 * @param {string} $endpoint Endpoint path.
		 * @param {string} $index Elasticsearch index.
		 */
		return apply_filters( 'ep_woocommerce_orders_search_endpoint', "api/v1/search/orders/{$this->index}", $this->index );
	}

	/**
	 * Get the endpoint for the WooCommerce Orders search template.
	 *
	 * @return string WooCommerce Orders search template endpoint.
	 */
	public function get_template_endpoint() {
		/**
		 * Filters the WooCommerce Orders search template API endpoint.
		 *
		 * @since 4.5.0
		 * @hook ep_woocommerce_orders_template_endpoint
		 * @param {string} $endpoint Endpoint path.
		 * @param {string} $index Elasticsearch index.
		 * @returns {string} Search template API endpoint.
		 */
		return apply_filters( 'ep_woocommerce_orders_template_endpoint', "api/v1/search/orders/{$this->index}/template", $this->index );
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		if ( 'edit.php' !== $hook_suffix ) {
			return;
		}

		if ( 'shop_order' !== $_GET['post_type'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		wp_enqueue_script(
			'elasticpress-woocommerce-orders-admin',
			EP_URL . 'dist/js/woocommerce-orders-admin-script.js',
			Utils\get_asset_info( 'woocommerce-orders-admin-script', 'dependencies' ),
			Utils\get_asset_info( 'woocommerce-orders-admin-script', 'version' ),
			true
		);

		wp_set_script_translations( 'elasticpress-woocommerce-orders-admin', 'elasticpress' );

		$api_endpoint = $this->get_search_endpoint();
		$api_host     = Utils\get_host();

		wp_localize_script(
			'elasticpress-woocommerce-orders-admin',
			'epWooCommerceOrdersAdmin',
			array(
				'apiEndpoint' => $api_endpoint,
				'apiHost'     => ( 0 !== strpos( $api_endpoint, 'http' ) ) ? esc_url_raw( $api_host ) : '',
				'argsSchema'  => $this->get_args_schema(),
			)
		);
	}

	/**
	 * Save or delete the search template on ElasticPress.io based on whether
	 * the WooCommerce feature is being activated or deactivated.
	 *
	 * @param string $feature  Feature slug
	 * @param array  $settings Feature settings
	 * @param array  $data     Feature activation data
	 *
	 * @return void
	 */
	public function after_update_feature( $feature, $settings, $data ) {
		if ( 'woocommerce' !== $featured ) {
			return;
		}

		if ( true === $data['active'] ) {
			$this->epio_save_search_template();
		} else {
			$this->epio_delete_search_template();
		}
	}

	/**
	 * Save the search template to ElasticPress.io.
	 *
	 * @return void
	 */
	public function epio_save_search_template() {
		$endpoint = $this->get_template_endpoint();
		$template = $this->get_search_template();

		$response = Elasticsearch::factory()->remote_request(
			$endpoint,
			[
				'blocking' => false,
				'body'     => $template,
				'method'   => 'PUT',
			]
		);

		/**
		 * Fires after the request is sent the search template API endpoint.
		 *
		 * @since 4.5.0
		 * @hook ep_woocommerce_orders_template_saved
		 * @param {string} $template The search template (JSON).
		 * @param {string} $index Index name.
		 */
		do_action( 'ep_woocommerce_orders_template_saved', $template, $this->index );
	}

	/**
	 * Delete the search template from ElasticPress.io.
	 *
	 * @return void
	 */
	public function epio_delete_search_template() {
		$endpoint = $this->get_template_endpoint();

		Elasticsearch::factory()->remote_request(
			$endpoint,
			[
				'blocking' => false,
				'method'   => 'DELETE',
			]
		);

		/**
		 * Fires after the request is sent the search template API endpoint.
		 *
		 * @since 4.5.0
		 * @hook ep_woocommerce_orders_template_deleted
		 * @param {string} $index Index name.
		 */
		do_action( 'ep_woocommerce_orders_template_deleted', $this->index );
	}

	/**
	 * Get the saved search template from ElasticPress.io.
	 *
	 * @return string|WP_Error Search template if found, WP_Error on error.
	 */
	public function epio_get_search_template() {
		$endpoint = $this->get_template_endpoint();
		$request  = Elasticsearch::factory()->remote_request( $endpoint );

		if ( is_wp_error( $request ) ) {
			return $request;
		}

		$response = wp_remote_retrieve_body( $request );

		return $response;
	}

	/**
	 * Generate a search template.
	 *
	 * A search template is the JSON for an Elasticsearch query with a
	 * placeholder search term. The template is sent to ElasticPress.io where
	 * it's used to make Elasticsearch queries using search terms sent from
	 * the front end.
	 *
	 * @return string The search template as JSON.
	 */
	public function get_search_template() {
		$order_statuses = wc_get_order_statuses();

		add_filter( 'ep_bypass_exclusion_from_search', '__return_true', 10 );
		add_filter( 'ep_intercept_remote_request', '__return_true' );
		add_filter( 'ep_do_intercept_request', [ $this, 'intercept_search_request' ], 10, 4 );
		add_filter( 'ep_is_integrated_request', [ $this, 'is_integrated_request' ], 10, 2 );

		$query = new \WP_Query(
			array(
				'ep_integrate'             => true,
				'ep_order_search_template' => true,
				'post_status'              => array_keys( $order_statuses ),
				'post_type'                => 'shop_order',
				's'                        => '{{ep_placeholder}}',
			)
		);

		remove_filter( 'ep_bypass_exclusion_from_search', '__return_true', 10 );
		remove_filter( 'ep_intercept_remote_request', '__return_true' );
		remove_filter( 'ep_do_intercept_request', [ $this, 'intercept_search_request' ], 10 );
		remove_filter( 'ep_is_integrated_request', [ $this, 'is_integrated_request' ], 10 );

		return $this->search_template;
	}

	/**
	 * Return true if a given feature is supported by WooCommerce Orders.
	 *
	 * Applied as a filter on Utils\is_integrated_request() so that features
	 * are enabled for the query that is used to generate the search template,
	 * regardless of the request type. This avoids the need to send a request
	 * to the front end.
	 *
	 * @param bool   $is_integrated Whether queries for the request will be
	 *                              integrated.
	 * @param string $context       Context for the original check. Usually the
	 *                              slug of the feature doing the check.
	 * @return bool True if the check is for a feature supported by WooCommerce
	 *              Order search.
	 */
	public function is_integrated_request( $is_integrated, $context ) {
		$supported_contexts = [
			'search',
			'woocommerce',
		];

		return in_array( $context, $supported_contexts, true );
	}

	/**
	 * Store intercepted request body and return request result.
	 *
	 * @param object $response Response
	 * @param array  $query Query
	 * @param array  $args WP_Query argument array
	 * @param int    $failures Count of failures in request loop
	 * @return object $response Response
	 */
	public function intercept_search_request( $response, $query = [], $args = [], $failures = 0 ) {
		$this->search_template = $query['args']['body'];

		return wp_remote_request( $query['url'], $args );
	}

	/**
	 * Get schema for search args.
	 *
	 * @return array Search args schema.
	 */
	public function get_args_schema() {
		$args = array(
			'customer' => array(
				'type' => 'number',
			),
			'm'        => array(
				'type' => 'string',
			),
			'offset'   => array(
				'type'    => 'number',
				'default' => 0,
			),
			'per_page' => array(
				'type'    => 'number',
				'default' => 6,
			),
			'search'   => array(
				'type'    => 'string',
				'default' => '',
			),
		);

		return $args;
	}
}
