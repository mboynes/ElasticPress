<?php
/**
 * Test utils functionality
 *
 * @package elasticpress
 */

namespace ElasticPressTest;

use ElasticPress;

/**
 * Dashboard test class
 */
class TestUtils extends BaseTestCase {

	/**
	 * Setup each test.
	 *
	 * @since 3.2
	 */
	public function set_up() {
		global $wpdb;
		parent::set_up();
		$wpdb->suppress_errors();

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin_id );

		wp_set_current_user( $admin_id );

		ElasticPress\Elasticsearch::factory()->delete_all_indices();
		ElasticPress\Indexables::factory()->get( 'post' )->put_mapping();

		ElasticPress\Indexables::factory()->get( 'post' )->sync_manager->sync_queue = [];

		$this->setup_test_post_type();

		$this->current_host = get_option( 'ep_host' );

		global $hook_suffix;
		$hook_suffix = 'sites.php';
		set_current_screen();
	}

	/**
	 * Clean up after each test.
	 *
	 * @since 3.2
	 */
	public function tear_down() {
		parent::tear_down();

		// Update since we are deleting to test notifications
		update_site_option( 'ep_host', $this->current_host );

		ElasticPress\Screen::factory()->set_current_screen( null );
	}

	/**
	 * Check that a site is indexable by default
	 *
	 * @since 3.2
	 * @group utils
	 */
	public function testIsSiteIndexableByDefault() {
		delete_option( 'ep_indexable' );

		$this->assertTrue( ElasticPress\Utils\is_site_indexable() );
	}

	/**
	 * Check that a spam site is NOT indexable by default
	 *
	 * @since 3.2
	 * @group utils
	 */
	public function testIsSiteIndexableByDefaultSpam() {
		delete_option( 'ep_indexable' );

		if ( is_multisite() ) {
			update_blog_status( get_current_blog_id(), 'spam', 1 );

			$this->assertFalse( ElasticPress\Utils\is_site_indexable() );

			update_blog_status( get_current_blog_id(), 'spam', 0 );
		} else {
			$this->assertTrue( ElasticPress\Utils\is_site_indexable() );
		}
	}

	/**
	 * Check that a site is not indexable after being set that way in the admin
	 *
	 * @since 3.2
	 * @group utils
	 */
	public function testIsSiteIndexableDisabled() {
		update_option( 'ep_indexable', 'no' );

		if ( is_multisite() ) {
			$this->assertFalse( ElasticPress\Utils\is_site_indexable() );
		} else {
			$this->assertTrue( ElasticPress\Utils\is_site_indexable() );
		}
	}

	/**
	 * Tests the sanitize_credentials utils function.
	 *
	 * @return void
	 */
	public function testSanitizeCredentials() {

		// First test anything that is not an array.
		$creds = \ElasticPress\Utils\sanitize_credentials( false );
		$this->assertTrue( is_array( $creds ) );

		$this->assertArrayHasKey( 'username', $creds );
		$this->assertArrayHasKey( 'token', $creds );

		$this->assertSame( '', $creds['username'] );
		$this->assertSame( '', $creds['token'] );

		// Then test arrays with invalid data.
		$creds = \ElasticPress\Utils\sanitize_credentials( [] );

		$this->assertTrue( is_array( $creds ) );

		$this->assertArrayHasKey( 'username', $creds );
		$this->assertArrayHasKey( 'token', $creds );

		$this->assertSame( '', $creds['username'] );
		$this->assertSame( '', $creds['token'] );

		$creds = \ElasticPress\Utils\sanitize_credentials(
			[
				'username' => '<strong>hello</strong> world',
				'token' => 'able <script>alert("baker");</script>',
			]
		);

		$this->assertTrue( is_array( $creds ) );

		$this->assertArrayHasKey( 'username', $creds );
		$this->assertArrayHasKey( 'token', $creds );

		$this->assertSame( 'hello world', $creds['username'] );
		$this->assertSame( 'able', $creds['token'] );

		// Finally, test with valid data.
		$creds = \ElasticPress\Utils\sanitize_credentials(
			[
				'username' => 'my-user-name',
				'token' => 'my-token',
			]
		);

		$this->assertTrue( is_array( $creds ) );

		$this->assertArrayHasKey( 'username', $creds );
		$this->assertArrayHasKey( 'token', $creds );

		$this->assertSame( 'my-user-name', $creds['username'] );
		$this->assertSame( 'my-token', $creds['token'] );
	}

	/**
	 * Tests the is_indexing function.
	 *
	 * @return void
	 */
	public function testIsIndexing() {

		if ( is_multisite() ) {
			update_site_option( 'ep_index_meta', [ 'method' => 'test' ] );
		} else {
			update_option( 'ep_index_meta', [ 'method' => 'test' ] );
		}

		$this->assertTrue( ElasticPress\Utils\is_indexing() );

		if ( is_multisite() ) {
			delete_site_option( 'ep_index_meta' );
		} else {
			delete_option( 'ep_index_meta' );
		}

		$this->assertFalse( ElasticPress\Utils\is_indexing() );
	}

	/**
	 * Test the get_sync_url method
	 *
	 * @since 4.4.0
	 */
	public function testGetSyncUrl() {
		/**
		 * Test without the $do_sync parameter
		 */
		$sync_url = ElasticPress\Utils\get_sync_url();
		$this->assertStringNotContainsString( '&do_sync', $sync_url );
		if ( defined( 'EP_IS_NETWORK' ) && EP_IS_NETWORK ) {
			$this->assertStringContainsString( 'wp-admin/network/admin.php?page=elasticpress-sync', $sync_url );
		} else {
			$this->assertStringContainsString( 'wp-admin/admin.php?page=elasticpress-sync', $sync_url );
		}

		/**
		 * Test with the $do_sync parameter
		 */
		$sync_url = ElasticPress\Utils\get_sync_url( true );
		if ( defined( 'EP_IS_NETWORK' ) && EP_IS_NETWORK ) {
			$this->assertStringContainsString( 'wp-admin/network/admin.php?page=elasticpress-sync&do_sync', $sync_url );
		} else {
			$this->assertStringContainsString( 'wp-admin/admin.php?page=elasticpress-sync&do_sync', $sync_url );
		}
	}
}
