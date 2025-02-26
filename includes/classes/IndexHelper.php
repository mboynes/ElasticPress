<?php
/**
 * Index Helper
 *
 * @since 4.0.0
 * @see docs/indexing-process.md
 * @see https://10up.github.io/ElasticPress/tutorial-indexing-process.html
 * @package elasticpress
 */

namespace ElasticPress;

use ElasticPress\Utils as Utils;

/**
 * Index Helper Class.
 *
 * @since 4.0.0
 */
class IndexHelper {
	/**
	 * Array to hold all the index sync information.
	 *
	 * @since 4.0.0
	 * @var array|bool
	 */
	protected $index_meta = false;

	/**
	 * Arguments to be used during the index process.
	 *
	 * @var array
	 */
	protected $args = [];

	/**
	 * Queried objects of the current sync item in the stack.
	 *
	 * @since 4.0.0
	 * @var array
	 */
	protected $current_query = [];

	/**
	 * Holds temporary wp_actions when indexing with pagination
	 *
	 * @since 4.0.0
	 * @var  array
	 */
	private $temporary_wp_actions = [];

	/**
	 * Initialize class.
	 *
	 * @since 4.0.0
	 */
	public function setup() {
		$this->index_meta = Utils\get_indexing_status();
	}

	/**
	 * Method to index everything.
	 *
	 * @since 4.0.0
	 * @param array $args Arguments.
	 */
	public function full_index( $args ) {
		register_shutdown_function( [ $this, 'handle_index_error' ] );
		add_filter( 'wp_php_error_message', [ $this, 'wp_handle_index_error' ], 10, 2 );

		$this->index_meta = Utils\get_indexing_status();
		$this->args       = $args;

		if ( false === $this->index_meta ) {
			$this->build_index_meta();
		}

		while ( $this->has_items_to_be_processed() ) {
			$this->process_sync_item();
		}

		while ( $this->has_network_alias_to_be_created() ) {
			$this->create_network_alias();
		}

		$this->full_index_complete();
	}

	/**
	 * Method to stack everything that needs to be indexed.
	 *
	 * @since 4.0.0
	 */
	protected function build_index_meta() {
		Utils\update_option( 'ep_last_sync', time() );
		Utils\delete_option( 'ep_need_upgrade_sync' );
		Utils\delete_option( 'ep_feature_auto_activated_sync' );
		delete_transient( 'ep_sync_interrupted' );

		$start_date_time = date_create( 'now', wp_timezone() );

		/**
		 * There are two ways to control pagination of things that need to be indexed:
		 * - offset:   The number of items to skip on each iteration
		 * - id range: Given an ID range, process a batch and set the upper limit as the last processed ID -1
		 *
		 * Although in the first case offset is updated to really control the flow, in the
		 * second it is updated to simply output the number of items processed.
		 */
		$pagination_method = ( ! empty( $this->args['offset'] ) || ! empty( $this->args['post-ids'] ) || ! empty( $this->args['include'] ) ) ?
			'offset' :
			'id_range';

		$this->index_meta = [
			'method'            => ! empty( $this->args['method'] ) ? $this->args['method'] : 'web',
			'put_mapping'       => ! empty( $this->args['put_mapping'] ),
			'offset'            => ! empty( $this->args['offset'] ) ? absint( $this->args['offset'] ) : 0,
			'pagination_method' => $pagination_method,
			'start'             => true,
			'sync_stack'        => [],
			'network_alias'     => [],
			'start_time'        => microtime( true ),
			'start_date_time'   => $start_date_time ? $start_date_time->format( DATE_ATOM ) : false,
			'totals'            => [
				'total'      => 0,
				'synced'     => 0,
				'skipped'    => 0,
				'failed'     => 0,
				'total_time' => 0,
				'errors'     => [],
			],
		];

		$global_indexables     = $this->filter_indexables( Indexables::factory()->get_all( true, true ) );
		$non_global_indexables = $this->filter_indexables( Indexables::factory()->get_all( false, true ) );

		$is_network_wide = isset( $this->args['network_wide'] ) && ! is_null( $this->args['network_wide'] );

		if ( defined( 'EP_IS_NETWORK' ) && EP_IS_NETWORK && $is_network_wide ) {
			if ( ! is_numeric( $this->args['network_wide'] ) ) {
				$this->args['network_wide'] = 0;
			}

			$sites = Utils\get_sites( $this->args['network_wide'] );

			foreach ( $sites as $site ) {
				if ( ! Utils\is_site_indexable( $site['blog_id'] ) ) {
					continue;
				}

				switch_to_blog( $site['blog_id'] );

				foreach ( $non_global_indexables as $indexable ) {
					$sync_stack_item = [
						'url'         => untrailingslashit( $site['domain'] . $site['path'] ),
						'blog_id'     => (int) $site['blog_id'],
						'indexable'   => $indexable,
						'put_mapping' => ! empty( $this->args['put_mapping'] ),
					];

					$this->index_meta['current_sync_item'] = $sync_stack_item;

					$objects_to_index = $this->get_objects_to_index();

					$sync_stack_item['found_items'] = $objects_to_index['total_objects'] ?? 0;

					$this->index_meta['sync_stack'][] = $sync_stack_item;

					if ( ! in_array( $indexable, $this->index_meta['network_alias'], true ) ) {
						$this->index_meta['network_alias'][] = $indexable;
					}
				}
			}

			restore_current_blog();
		} else {
			foreach ( $non_global_indexables as $indexable ) {
				$sync_stack_item = [
					'url'         => untrailingslashit( home_url() ),
					'blog_id'     => (int) get_current_blog_id(),
					'indexable'   => $indexable,
					'put_mapping' => ! empty( $this->args['put_mapping'] ),
				];

				$this->index_meta['current_sync_item'] = $sync_stack_item;

				$objects_to_index = $this->get_objects_to_index();

				$sync_stack_item['found_items'] = $objects_to_index['total_objects'] ?? 0;

				$this->index_meta['sync_stack'][] = $sync_stack_item;
			}
		}

		foreach ( $global_indexables as $indexable ) {
			$sync_stack_item = [
				'indexable'   => $indexable,
				'put_mapping' => ! empty( $this->args['put_mapping'] ),
			];

			$this->index_meta['current_sync_item'] = $sync_stack_item;

			$objects_to_index = $this->get_objects_to_index();

			$sync_stack_item['found_items'] = $objects_to_index['total_objects'] ?? 0;

			$this->index_meta['sync_stack'][] = $sync_stack_item;
		}

		$this->index_meta['current_sync_item'] = false;
		/**
		 * Fires at start of new index
		 *
		 * @since 4.0.0
		 *
		 * @hook ep_sync_start_index
		 * @param  {array} $index_meta Index meta information
		 */
		do_action( 'ep_sync_start_index', $this->index_meta );

		/**
		 * Fires at start of new index
		 *
		 * @since 2.1 Previously called only as 'ep_dashboard_start_index'
		 * @since 4.0.0 Made available for all methods
		 *
		 * @hook ep_{$index_method}_start_index
		 * @param  {array} $index_meta Index meta information
		 */
		do_action( "ep_{$this->args['method']}_start_index", $this->index_meta );

		/**
		 * Filter index meta during dashboard sync
		 *
		 * @since  3.0
		 * @hook ep_index_meta
		 * @param  {array} $index_meta Current index meta
		 * @return  {array} New index meta
		 */
		$this->index_meta = apply_filters( 'ep_index_meta', $this->index_meta );
	}

	/**
	 * Given an array of indexables, check if they are part of the indexable args or not.
	 *
	 * @since 4.0.0
	 * @param array $indexables Indexable slugs.
	 * @return array
	 */
	protected function filter_indexables( $indexables ) {
		return array_filter(
			$indexables,
			function( $indexable ) {
				return empty( $this->args['indexables'] ) || in_array( $indexable, $this->args['indexables'], true );
			}
		);
	}

	/**
	 * Check if there are still items to be processed in the stack.
	 *
	 * @since 4.0.0
	 * @return boolean
	 */
	protected function has_items_to_be_processed() {
		return ! empty( $this->index_meta['current_sync_item'] ) || count( $this->index_meta['sync_stack'] ) > 0;
	}

	/**
	 * Method to process the next item in the stack.
	 *
	 * @since 4.0.0
	 */
	protected function process_sync_item() {
		if ( empty( $this->index_meta['current_sync_item'] ) ) {
			$this->index_meta['current_sync_item'] = array_merge(
				array_shift( $this->index_meta['sync_stack'] ),
				[
					'total'   => 0,
					'synced'  => 0,
					'skipped' => 0,
					'failed'  => 0,
					'errors'  => [],
				]
			);

			$indexable = Indexables::factory()->get( $this->index_meta['current_sync_item']['indexable'] );

			if ( ! empty( $this->index_meta['current_sync_item']['blog_id'] ) && defined( 'EP_IS_NETWORK' ) && EP_IS_NETWORK ) {
				$this->output_success(
					sprintf(
						/* translators: 1: Indexable name, 2: Site ID */
						esc_html__( 'Indexing %1$s on site %2$d…', 'elasticpress' ),
						esc_html( strtolower( $indexable->labels['plural'] ) ),
						$this->index_meta['current_sync_item']['blog_id']
					)
				);
			} else {
				$message_string = ( $indexable->global ) ?
					/* translators: 1: Indexable name */
					esc_html__( 'Indexing %1$s (globally)…', 'elasticpress' ) :
					/* translators: 1: Indexable name */
					esc_html__( 'Indexing %1$s…', 'elasticpress' );

				$this->output_success(
					sprintf(
						/* translators: 1: Indexable name */
						$message_string,
						esc_html( strtolower( $indexable->labels['plural'] ) )
					)
				);
			}
		}

		if ( defined( 'EP_IS_NETWORK' ) && EP_IS_NETWORK && ! empty( $this->index_meta['current_sync_item']['blog_id'] ) ) {
			switch_to_blog( $this->index_meta['current_sync_item']['blog_id'] );
		}

		if ( $this->index_meta['current_sync_item']['put_mapping'] ) {
			$this->put_mapping();
		}

		$this->index_objects();

		if ( defined( 'EP_IS_NETWORK' ) && EP_IS_NETWORK && ! empty( $this->index_meta['current_sync_item']['blog_id'] ) ) {
			restore_current_blog();
		}
	}

	/**
	 * Delete an index and recreate it sending the mapping.
	 *
	 * @since 4.0.0
	 */
	protected function put_mapping() {
		$this->index_meta['current_sync_item']['put_mapping'] = false;

		/**
		 * Filter whether we should delete index and send new mapping at the start of the sync
		 *
		 * @since  2.1
		 * @hook ep_skip_index_reset
		 * @param  {bool} $skip True means skip
		 * @param  {array} $index_meta Current index meta
		 * @return  {bool} New skip value
		 */
		if ( apply_filters( 'ep_skip_index_reset', false, $this->index_meta ) ) {
			return;
		}

		$indexable = Indexables::factory()->get( $this->index_meta['current_sync_item']['indexable'] );

		$indexable->delete_index();
		$result = $indexable->put_mapping();

		/**
		 * Fires after sync put mapping is completed
		 *
		 * @since 4.0.0
		 *
		 * @hook ep_sync_put_mapping
		 * @param  {array} $index_meta Index meta information
		 * @param  {Indexable} $indexable Indexable object
		 * @param  {bool} $result Whether the request was successful or not
		 */
		do_action( 'ep_sync_put_mapping', $this->index_meta, $indexable, $result );

		/**
		 * Fires after dashboard put mapping is completed
		 *
		 * In this particular case, developer aiming a specific method should rely on
		 * `$index_meta['method']`, as historically `ep_dashboard_put_mapping` and
		 * `ep_cli_put_mapping` receive different parameters.
		 *
		 * @see Command::call_ep_cli_put_mapping()
		 *
		 * @since  2.1
		 * @hook ep_dashboard_put_mapping
		 * @param  {array} $index_meta Index meta information
		 * @param  {string} $status Current indexing status
		 */
		do_action( 'ep_dashboard_put_mapping', $this->index_meta, 'start' );

		if ( $result ) {
			$this->output_success( esc_html__( 'Mapping sent', 'elasticpress' ) );
		} else {
			$this->output_error( esc_html__( 'Mapping failed', 'elasticpress' ) );
		}
	}

	/**
	 * Index documents of an index.
	 *
	 * @since 4.0.0
	 */
	protected function index_objects() {
		global $wp_actions;
		// Hold original wp_actions.
		$this->temporary_wp_actions = $wp_actions;

		$this->current_query = $this->get_objects_to_index();

		$this->index_meta['from']                       = $this->index_meta['offset'];
		$this->index_meta['found_items']                = (int) $this->current_query['total_objects'];
		$this->index_meta['current_sync_item']['total'] = (int) $this->index_meta['current_sync_item']['found_items'];

		if ( 'offset' === $this->index_meta['pagination_method'] ) {
			$indexable = Indexables::factory()->get( $this->index_meta['current_sync_item']['indexable'] );

			if ( empty( $this->index_meta['current_sync_item']['shown_skip_message'] ) ) {
				$this->index_meta['current_sync_item']['shown_skip_message'] = true;

				$this->output(
					sprintf(
						/* translators: 1. Number of objects skipped 2. Indexable type */
						esc_html__( 'Skipping %1$d %2$s…', 'elasticpress' ),
						$this->index_meta['from'],
						esc_html( strtolower( $indexable->labels['plural'] ) )
					),
					'info',
					'index_objects'
				);
			}
		}

		if ( $this->index_meta['found_items'] && $this->index_meta['offset'] < $this->index_meta['found_items'] ) {
			$this->index_next_batch();
		} else {
			$this->index_cleanup();
		}

		usleep( 500 );

		// Avoid running out of memory.
		$this->stop_the_insanity();
	}

	/**
	 * Query the next objects to be indexed.
	 *
	 * @since 4.0.0
	 * @return array
	 */
	protected function get_objects_to_index() {
		$indexable = Indexables::factory()->get( $this->index_meta['current_sync_item']['indexable'] );

		/**
		 * Fires right before entries are about to be indexed.
		 *
		 * @since 4.0.0
		 *
		 * @hook ep_pre_sync_index
		 * @param  {array} $args Args to query content with
		 */
		do_action( 'ep_pre_sync_index', $this->index_meta, ( $this->index_meta['start'] ? 'start' : false ), $indexable );

		/**
		 * Fires right before entries are about to be indexed.
		 *
		 * @since 2.1 Previously called only as 'ep_pre_dashboard_index'
		 * @since 4.0.0 Made available for all methods
		 *
		 * @hook ep_pre_{$index_method}_index
		 * @param  {array} $args Args to query content with
		 */
		do_action( "ep_pre_{$this->args['method']}_index", $this->index_meta, ( $this->index_meta['start'] ? 'start' : false ), $indexable );

		$per_page = $this->get_index_default_per_page();

		if ( ! empty( $this->args['per_page'] ) ) {
			$per_page = $this->args['per_page'];
		}

		if ( ! empty( $this->args['nobulk'] ) ) {
			$per_page = 1;
		}

		$args = [
			'per_page' => absint( $per_page ),
		];

		if ( ! $indexable->support_indexing_advanced_pagination || 'offset' === $this->index_meta['pagination_method'] ) {
			$args['offset'] = $this->index_meta['offset'];
		}

		if ( ! empty( $this->args['post-ids'] ) ) {
			$args['include'] = $this->args['post-ids'];
		}

		if ( ! empty( $this->args['include'] ) ) {
			$include          = ( is_array( $this->args['include'] ) ) ? $this->args['include'] : explode( ',', str_replace( ' ', '', $this->args['include'] ) );
			$args['include']  = array_map( 'absint', $include );
			$args['per_page'] = count( $args['include'] );
		}

		if ( ! empty( $this->args['post_type'] ) ) {
			$args['post_type'] = ( is_array( $this->args['post_type'] ) ) ? $this->args['post_type'] : explode( ',', $this->args['post_type'] );
			$args['post_type'] = array_map( 'trim', $args['post_type'] );
		}

		// Start of advanced pagination arguments.
		if ( ! empty( $this->args['upper_limit_object_id'] ) && is_numeric( $this->args['upper_limit_object_id'] ) ) {
			$args['ep_indexing_upper_limit_object_id'] = $this->args['upper_limit_object_id'];
		}

		if ( ! empty( $this->args['lower_limit_object_id'] ) && is_numeric( $this->args['lower_limit_object_id'] ) ) {
			$args['ep_indexing_lower_limit_object_id'] = $this->args['lower_limit_object_id'];
		}

		if ( ! empty( $this->index_meta['current_sync_item']['last_processed_object_id'] ) &&
			is_numeric( $this->index_meta['current_sync_item']['last_processed_object_id'] )
		) {
			$args['ep_indexing_last_processed_object_id'] = $this->index_meta['current_sync_item']['last_processed_object_id'];
		}
		// End of advanced pagination arguments.

		/**
		 * Filters arguments used to query for content for each indexable
		 *
		 * @since 4.0.0
		 *
		 * @hook ep_sync_index_args
		 * @param  {array} $args Args to query content with
		 * @return  {array} New query args
		 */
		$args = apply_filters( 'ep_sync_index_args', $args );

		/**
		 * Filters arguments used to query for content for each indexable
		 *
		 * @since  3.0 Previously called only as 'ep_dashboard_index_args'
		 *
		 * @hook ep_{$index_method}_index_args
		 * @param  {array} $args Args to query content with
		 * @return  {array} New query args
		 */
		$args = apply_filters( "ep_{$this->args['method']}_index_args", $args );

		return $indexable->query_db( $args );
	}

	/**
	 * Index the next batch of documents.
	 *
	 * @since 4.0.0
	 */
	protected function index_next_batch() {
		$indexable = Indexables::factory()->get( $this->index_meta['current_sync_item']['indexable'] );

		/**
		 * Fires right before entries are about to be indexed in a dashboard sync
		 *
		 * @since  4.0.0
		 * @hook ep_pre_index_batch
		 * @param  {array} $index_meta Index meta
		 */
		do_action( 'ep_pre_index_batch', $this->index_meta );

		$queued_items = [];

		foreach ( $this->current_query['objects'] as $object ) {
			if ( $this->should_skip_object_index( $object, $indexable ) ) {
				$this->index_meta['current_sync_item']['skipped']++;
			} else {
				$queued_items[ $object->ID ] = true;
			}
		}

		$this->index_meta['offset'] = absint( $this->index_meta['offset'] + count( $this->current_query['objects'] ) );

		if ( ! empty( $queued_items ) ) {
			$total_attempts   = ( ! empty( $this->args['total_attempts'] ) ) ? absint( $this->args['total_attempts'] ) : 1;
			$queued_items_ids = array_keys( $queued_items );

			/**
			 * Filters the number of times the index will try before failing.
			 *
			 * @since  3.0
			 * @hook ep_index_batch_attempts_number
			 * @param  {int} $total_attempts Number of attempts
			 * @return  {int} New number of attempts
			 */
			$total_attempts = apply_filters( 'ep_index_batch_attempts_number', $total_attempts );

			for ( $attempts = 1; $attempts <= $total_attempts; $attempts++ ) {
				$nobulk         = ! empty( $this->args['nobulk'] );
				$failed_objects = [];

				/**
				 * Fires before each attempt of indexing objects
				 *
				 * @hook ep_index_batch_new_attempt
				 * @param {int} $attempts Current attempt
				 * @param {int} $total_attempts Total number of attempts
				 */
				do_action( 'ep_index_batch_new_attempt', $attempts, $total_attempts );

				$should_retry = false;

				if ( $nobulk ) {
					$object_id = reset( $queued_items_ids );
					$return    = $indexable->index( $object_id, true );

					/**
					 * Fires after one by one indexing an object
					 *
					 * @since 4.0.0
					 *
					 * @hook ep_sync_object_index
					 * @param  {int} $object_id Object to index
					 * @param {Indexable} $indexable Current indexable
					 * @param {mixed} $return Return of the index() call
					 */
					do_action( 'ep_sync_object_index', $object_id, $indexable, $return );

					/**
					 * Fires after one by one indexing an object
					 *
					 * @since 3.0 Previously called only as 'ep_cli_object_index'
					 * @since 4.0.0 Made available for all methods
					 *
					 * @hook ep_{$index_method}_object_index
					 * @param  {int} $object_id Object to index
					 * @param {Indexable} $indexable Current indexable
					 * @param {mixed} $return Return of the index() call
					 */
					do_action( "ep_{$this->args['method']}_object_index", $object_id, $indexable, $return );

					if ( is_object( $return ) && ! empty( $return->error ) ) {
						if ( ! empty( $return->error->reason ) ) {
							$failed_objects[ $object->ID ] = (array) $return->error;
						} else {
							$failed_objects[ $object->ID ] = null;
						}
					}

					if ( is_wp_error( $return ) ) {
						$should_retry = true;
					}
				} else {
					if ( ! empty( $this->args['static_bulk'] ) ) {
						$bulk_requests = [ $indexable->bulk_index( $queued_items_ids ) ];
					} else {
						$bulk_requests = $indexable->bulk_index_dynamically( $queued_items_ids );
					}

					$failed_objects = [];
					foreach ( $bulk_requests as $return ) {
						/**
						 * Fires after bulk indexing
						 *
						 * @hook ep_cli_{indexable_slug}_bulk_index
						 * @param  {array} $objects Objects being indexed
						 * @param  {array} response Elasticsearch bulk index response
						 */
						do_action( "ep_cli_{$indexable->slug}_bulk_index", $queued_items, $return );

						if ( is_wp_error( $return ) ) {
							$should_retry = true;
						}
						if ( is_array( $return ) && isset( $return['errors'] ) && true === $return['errors'] ) {
							$failed_objects = array_merge(
								$failed_objects,
								array_filter(
									$return['items'],
									function( $item ) {
										return ! empty( $item['index']['error'] );
									}
								)
							);
						}
					}
				}

				// Things worked, we don't need to try again.
				if ( ! $should_retry && ! count( $failed_objects ) ) {
					break;
				}
			}

			if ( is_wp_error( $return ) ) {
				$this->index_meta['current_sync_item']['failed'] += count( $queued_items );
				$this->index_meta['current_sync_item']['errors']  = array_merge( $this->index_meta['current_sync_item']['errors'], $return->get_error_messages() );

				$this->output( implode( "\n", $return->get_error_messages() ), 'warning' );
			} elseif ( count( $failed_objects ) ) {
				$errors_output = $this->output_index_errors( $failed_objects );

				$this->index_meta['current_sync_item']['synced'] += count( $queued_items ) - count( $failed_objects );
				$this->index_meta['current_sync_item']['failed'] += count( $failed_objects );
				$this->index_meta['current_sync_item']['errors']  = array_merge( $this->index_meta['current_sync_item']['errors'], $errors_output );

				$this->output( $errors_output, 'warning' );
			} else {
				$this->index_meta['current_sync_item']['synced'] += count( $queued_items );
			}
		}

		$this->index_meta['current_sync_item']['last_processed_object_id'] = end( $this->current_query['objects'] )->ID;

		$this->output(
			sprintf(
				/* translators: 1. Indexable type 2. Offset start, 3. Offset end, 4. Found items 5. Last object ID */
				esc_html__( 'Processed %1$s %2$d - %3$d of %4$d. Last Object ID: %5$d', 'elasticpress' ),
				esc_html( strtolower( $indexable->labels['plural'] ) ),
				$this->index_meta['from'],
				$this->index_meta['offset'],
				$this->index_meta['found_items'],
				$this->index_meta['current_sync_item']['last_processed_object_id']
			),
			'info',
			'index_next_batch'
		);
	}

	/**
	 * Update the sync info with the totals from the last sync item.
	 *
	 * @since 4.2.0
	 */
	protected function update_totals_from_current_sync_item() {
		$current_sync_item = $this->index_meta['current_sync_item'];

		$errors = array_merge(
			$this->index_meta['totals']['errors'],
			$current_sync_item['errors']
		);

		/**
		 * Filter the number of errors of a sync that should be stored.
		 *
		 * @since  4.2.0
		 * @hook ep_sync_number_of_errors_stored
		 * @param  {int} $number Number of errors to be logged.
		 * @return {int} New value
		 */
		$logged_errors = (int) apply_filters( 'ep_sync_number_of_errors_stored', 50 );

		$this->index_meta['totals']['total']   += $current_sync_item['total'];
		$this->index_meta['totals']['synced']  += $current_sync_item['synced'];
		$this->index_meta['totals']['skipped'] += $current_sync_item['skipped'];
		$this->index_meta['totals']['failed']  += $current_sync_item['failed'];
		$this->index_meta['totals']['errors']   = array_slice( $errors, $logged_errors * -1 );
	}

	/**
	 * Make the necessary clean up after a sync item of the stack was completely done.
	 *
	 * @since 4.0.0
	 * @return void
	 */
	protected function index_cleanup() {
		wp_reset_postdata();

		$this->update_totals_from_current_sync_item();

		$indexable = Indexables::factory()->get( $this->index_meta['current_sync_item']['indexable'] );

		$current_sync_item = $this->index_meta['current_sync_item'];

		$this->index_meta['current_sync_item'] = null;

		if ( $current_sync_item['failed'] ) {
			if ( ! empty( $current_sync_item['blog_id'] ) && defined( 'EP_IS_NETWORK' ) && EP_IS_NETWORK ) {
				$message = sprintf(
					/* translators: 1: indexable (plural), 2: Blog ID, 3: number of failed objects */
					esc_html__( 'Number of %1$s index errors on site %2$d: %3$d', 'elasticpress' ),
					esc_html( strtolower( $indexable->labels['plural'] ) ),
					$current_sync_item['blog_id'],
					$current_sync_item['failed']
				);
			} else {
				$message = sprintf(
					/* translators: 1: indexable (plural), 2: number of failed objects */
					esc_html__( 'Number of %1$s index errors: %2$d', 'elasticpress' ),
					esc_html( strtolower( $indexable->labels['plural'] ) ),
					$current_sync_item['failed']
				);
			}

			$this->output( $message, 'warning' );
		}

		$this->index_meta['offset'] = 0;

		if ( ! empty( $current_sync_item['blog_id'] ) && defined( 'EP_IS_NETWORK' ) && EP_IS_NETWORK ) {
			$message = sprintf(
				/* translators: 1: indexable (plural), 2: Blog ID, 3: number of synced objects */
				esc_html__( 'Number of %1$s indexed on site %2$d: %3$d', 'elasticpress' ),
				esc_html( strtolower( $indexable->labels['plural'] ) ),
				$current_sync_item['blog_id'],
				$current_sync_item['synced']
			);
		} else {
			$message = sprintf(
				/* translators: 1: indexable (plural), 2: number of synced objects */
				esc_html__( 'Number of %1$s indexed: %2$d', 'elasticpress' ),
				esc_html( strtolower( $indexable->labels['plural'] ) ),
				$current_sync_item['synced']
			);
		}

		$this->output_success( $message );
	}

	/**
	 * Update last sync info.
	 *
	 * @since 4.2.0
	 */
	protected function update_last_index() {
		$start_time = $this->index_meta['start_time'];
		$totals     = $this->index_meta['totals'];
		$method     = $this->index_meta['method'];

		$this->index_meta = null;

		$end_date_time  = date_create( 'now', wp_timezone() );
		$start_time_sec = (int) $start_time;

		$totals['end_date_time']   = $end_date_time ? $end_date_time->format( DATE_ATOM ) : false;
		$totals['start_date_time'] = $start_time ? wp_date( DATE_ATOM, $start_time_sec ) : false;
		$totals['end_time_gmt']    = time();
		$totals['total_time']      = microtime( true ) - $start_time;
		$totals['method']          = $method;
		Utils\update_option( 'ep_last_cli_index', $totals, false );
		Utils\update_option( 'ep_last_index', $totals, false );
	}

	/**
	 * Make the necessary clean up after everything was sync'd.
	 *
	 * @since 4.0.0
	 */
	protected function full_index_complete() {
		$this->update_last_index();

		/**
		 * Fires after executing a reindex
		 *
		 * @since 4.0.0
		 * @hook ep_after_sync_index
		 */
		do_action( 'ep_after_sync_index' );

		/**
		 * Fires after executing a reindex
		 *
		 * @since 3.5.5 Previously called only as 'ep_after_dashboard_index'
		 * @since 4.0.0 Made available for all methods
		 * @hook ep_after_{$index_method}_index
		 */
		do_action( "ep_after_{$this->args['method']}_index" );

		$this->output_success( esc_html__( 'Sync complete', 'elasticpress' ) );
	}

	/**
	 * Check if network aliases need to be created.
	 *
	 * @since 4.0.0
	 * @return boolean
	 */
	protected function has_network_alias_to_be_created() {
		return count( $this->index_meta['network_alias'] ) > 0;
	}

	/**
	 * Create the next network alias.
	 *
	 * @since 4.0.0
	 */
	protected function create_network_alias() {
		$indexes   = [];
		$indexable = Indexables::factory()->get( array_shift( $this->index_meta['network_alias'] ) );

		$sites = Utils\get_sites();

		foreach ( $sites as $site ) {

			if ( ! Utils\is_site_indexable( $site['blog_id'] ) ) {
				continue;
			}

			switch_to_blog( $site['blog_id'] );
			$indexes[] = $indexable->get_index_name();
			restore_current_blog();
		}

		$result = $indexable->create_network_alias( $indexes );

		if ( $result ) {
			$this->output_success(
				sprintf(
					/* translators: 1: Indexable name */
					esc_html__( 'Network alias created for %1$s', 'elasticpress' ),
					esc_html( strtolower( $indexable->labels['plural'] ) )
				)
			);
		} else {
			$this->output_error(
				sprintf(
					/* translators: 1: Indexable name */
					esc_html__( 'Network alias creation failed for %1$s', 'elasticpress' ),
					esc_html( strtolower( $indexable->labels['plural'] ) )
				)
			);
		}
	}

	/**
	 * Output a message.
	 *
	 * @since 4.0.0
	 * @param string|array $message_text Message to be outputted
	 * @param string       $type         Type of message
	 * @param string       $context      Context of the output
	 * @return void
	 */
	protected function output( $message_text, $type = 'info', $context = '' ) {
		if ( $this->index_meta ) {
			Utils\update_option( 'ep_index_meta', $this->index_meta );
		} else {
			Utils\delete_option( 'ep_index_meta' );
			$totals = $this->get_last_index();
		}

		$message = [
			'message'    => ( is_array( $message_text ) ) ? implode( "\n", $message_text ) : $message_text,
			'index_meta' => $this->index_meta,
			'totals'     => $totals ?? [],
			'status'     => $type,
		];

		if ( is_callable( $this->args['output_method'] ) ) {
			call_user_func( $this->args['output_method'], $message, $this->args, $this->index_meta, $context );
		}
	}

	/**
	 * Wrapper to the `output` method with a success message.
	 *
	 * @since 4.0.0
	 * @param string $message Message string.
	 * @param string $context Context of the output.
	 */
	protected function output_success( $message, $context = '' ) {
		$this->output( $message, 'success', $context );
	}

	/**
	 * Wrapper to the `output` method with an error message.
	 *
	 * @since 4.0.0
	 * @param string $message Message string.
	 * @param string $context Context of the output.
	 */
	protected function output_error( $message, $context = '' ) {
		$this->output( $message, 'error', $context );
	}

	/**
	 * Output index errors of failed objects.
	 *
	 * @since 4.0.0
	 * @param array $failed_objects Failed objects
	 */
	protected function output_index_errors( $failed_objects ) {
		$indexable = Indexables::factory()->get( $this->index_meta['current_sync_item']['indexable'] );

		$error_text = [];

		foreach ( $failed_objects as $object ) {
			$error_text[] = $object['index']['_id'] . ' (' . $indexable->labels['singular'] . '): [' . $object['index']['error']['type'] . '] ' . $object['index']['error']['reason'];
		}

		return $error_text;
	}

	/**
	 * Utilitary function to check if the indexable is being fully reindexed, i.e.,
	 * the index was deleted, a new mapping was sent and content is being reindexed.
	 *
	 * @param string   $indexable_slug Indexable slug.
	 * @param int|null $blog_id        Blog ID
	 * @return boolean
	 */
	public function is_full_reindexing( $indexable_slug, $blog_id = null ) {
		if ( empty( $this->index_meta ) || empty( $this->index_meta['put_mapping'] ) ) {
			/**
			 * Filter if a fully reindex is being done to an indexable
			 *
			 * @since  4.0.0
			 * @hook ep_is_full_reindexing_{$indexable_slug}
			 * @param  {bool} $is_full_reindexing If is fully reindexing
			 * @return  {bool} New value
			 */
			return apply_filters( "ep_is_full_reindexing_{$indexable_slug}", false );
		}

		$sync_stack        = ( ! empty( $this->index_meta['sync_stack'] ) ) ? $this->index_meta['sync_stack'] : [];
		$current_sync_item = ( ! empty( $this->index_meta['current_sync_item'] ) ) ? $this->index_meta['current_sync_item'] : [];

		$is_full_reindexing = false;

		$all_items = $sync_stack;
		if ( ! empty( $current_sync_item ) ) {
			$all_items += [ $current_sync_item ];
		}

		foreach ( $all_items as $sync_item ) {
			if ( $sync_item['indexable'] !== $indexable_slug ) {
				continue;
			}

			if (
				( empty( $sync_item['blog_id'] ) && ! $blog_id ) ||
				(int) $sync_item['blog_id'] === $blog_id
			) {
				$is_full_reindexing = true;
			}
		}

		/* this filter is documented above */
		return apply_filters( "ep_is_full_reindexing_{$indexable_slug}", $is_full_reindexing );
	}

	/**
	 * Get the last index/sync meta information.
	 *
	 * @since 4.2.0
	 * @return array
	 */
	public function get_last_index() {
		return Utils\get_option( 'ep_last_index', [] );
	}

	/**
	 * Check if an object should be indexed or skipped.
	 *
	 * We used to have two different filters for this (one for the dashboard, another for CLI),
	 * this method combines both.
	 *
	 * @param {stdClass}  $object Object to be checked
	 * @param {Indexable} $indexable Indexable
	 * @return boolean
	 */
	protected function should_skip_object_index( $object, $indexable ) {
		/**
		 * Filter whether to not sync specific item in dashboard or not
		 *
		 * @since  2.1
		 * @hook ep_item_sync_kill
		 * @param  {boolean} $kill False means dont sync
		 * @param  {array} $object Object to sync
		 * @return {Indexable} Indexable that object belongs to
		 */
		$ep_item_sync_kill = apply_filters( 'ep_item_sync_kill', false, $object, $indexable );

		/**
		 * Conditionally kill indexing for a post
		 *
		 * @hook ep_{indexable_slug}_index_kill
		 * @param  {bool} $index True means dont index
		 * @param  {int} $object_id Object ID
		 * @return {bool} New value
		 */
		$ep_indexable_sync_kill = apply_filters( 'ep_' . $indexable->slug . '_index_kill', false, $object->ID );

		return $ep_item_sync_kill || $ep_indexable_sync_kill;
	}

	/**
	 * Resets some values to reduce memory footprint.
	 */
	protected function stop_the_insanity() {
		global $wpdb, $wp_object_cache, $wp_actions;

		$wpdb->queries = [];

		/*
		 * Runtime flushing was introduced in WordPress 6.0 and will flush only the
		 * in-memory cache for persistent object caches
		 */
		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		} else {
			/*
			 * In the case where we're not using an external object cache, we need to call flush on the default
			 * WordPress object cache class to clear the values from the cache property
			 */
			if ( ! wp_using_ext_object_cache() ) {
				wp_cache_flush();
			}
		}

		if ( is_object( $wp_object_cache ) ) {
			$wp_object_cache->group_ops      = [];
			$wp_object_cache->stats          = [];
			$wp_object_cache->memcache_debug = [];

			// Make sure this is a public property, before trying to clear it.
			try {
				$cache_property = new \ReflectionProperty( $wp_object_cache, 'cache' );
				if ( $cache_property->isPublic() ) {
					$wp_object_cache->cache = [];
				}
				unset( $cache_property );
			} catch ( \ReflectionException $e ) {
				// No need to catch.
			}

			if ( is_callable( $wp_object_cache, '__remoteset' ) ) {
				call_user_func( [ $wp_object_cache, '__remoteset' ] );
			}
		}

		// Prevent wp_actions from growing out of control.
		// phpcs:disable
		$wp_actions = $this->temporary_wp_actions;
		// phpcs:enable

		// It's high memory consuming as WP_Query instance holds all query results inside itself
		// and in theory $wp_filter will not stop growing until Out Of Memory exception occurs.
		remove_filter( 'get_term_metadata', [ wp_metadata_lazyloader(), 'lazyload_term_meta' ] );

		/**
		 * Fires after reducing the memory footprint
		 *
		 * @since 4.3.0
		 * @hook ep_stop_the_insanity
		 */
		do_action( 'ep_stop_the_insanity' );
	}

	/**
	 * Utilitary function to delete the index meta option.
	 *
	 * @since 4.0.0
	 */
	public function clear_index_meta() {
		$this->index_meta = false;
		Utils\delete_option( 'ep_index_meta', false );
	}

	/**
	 * Utilitary function to get the index meta option.
	 *
	 * @return array
	 * @since 4.0.0
	 */
	public function get_index_meta() {
		return Utils\get_option( 'ep_index_meta', [] );
	}

	/**
	 * Handle fatal errors during syncs.
	 *
	 * Added by register_shutdown_function. It will not be called if `WP_DISABLE_FATAL_ERROR_HANDLER` is false (default.)
	 *
	 * @since 4.2.0
	 */
	public function handle_index_error() {
		$error = error_get_last();
		if ( empty( $error['type'] ) || E_ERROR !== $error['type'] ) {
			return;
		}

		$this->on_error_update_and_clean( $error );
	}

	/**
	 * Handle fatal errors during syncs.
	 *
	 * Added via the `wp_php_error_message` filter. It will be called only if `WP_DISABLE_FATAL_ERROR_HANDLER` is false (default.)
	 *
	 * @since 4.2.0
	 * @param bool  $message HTML error message to display.
	 * @param array $error   Error information retrieved from error_get_last().
	 * @return bool
	 */
	public function wp_handle_index_error( $message, $error ) {
		$this->on_error_update_and_clean( $error );
		return $message;
	}

	/**
	 * Logs the error and clears the sync status, preventing the sync status from being stuck.
	 *
	 * @since 4.2.0
	 * @param array $error Error information retrieved from error_get_last().
	 */
	protected function on_error_update_and_clean( $error ) {
		$this->update_totals_from_current_sync_item();

		$totals = $this->index_meta['totals'];

		$this->index_meta['totals']['errors'][] = $error['message'];
		$this->index_meta['totals']['failed']   = $totals['total'] - ( $totals['synced'] + $totals['skipped'] );
		$this->update_last_index();

		/**
		 * Fires after a sync failed due to a PHP fatal error.
		 *
		 * @since 4.2.0
		 * @hook ep_after_sync_error
		 * @param {array} $error The error
		 */
		do_action( 'ep_after_sync_error', $error );

		$this->output_error(
			sprintf(
				/* translators: Error message */
				esc_html__( 'Index failed: %s', 'elasticpress' ),
				$error['message']
			)
		);
	}

	/**
	 * Return the default number of documents to be sent to Elasticsearch on each batch.
	 *
	 * @since 4.4.0
	 * @return integer
	 */
	public function get_index_default_per_page() : int {
		/**
		 * Filter number of items to index per cycle in the dashboard
		 *
		 * @since  2.1
		 * @hook ep_index_default_per_page
		 * @param  {int} Entries per cycle
		 * @return  {int} New number of entries
		 */
		return (int) apply_filters( 'ep_index_default_per_page', Utils\get_option( 'ep_bulk_setting', 350 ) );
	}

	/**
	 * Return singleton instance of class.
	 *
	 * @return self
	 * @since 4.0.0
	 */
	public static function factory() {
		static $instance = false;

		if ( ! $instance ) {
			$instance = new self();
			$instance->setup();
		}

		return $instance;
	}
}
