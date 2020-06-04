<?php
/**
 * REST API: REST_Nav_Menu_Items_Controller_Test class
 *
 * @package    WordPress
 * @subpackage REST_API
 */

/**
 * Tests for the REST API for Plugins.
 */
class WP_REST_Plugins_Controller_Test extends WP_Test_REST_Controller_Testcase {

	const BASE        = '/__experimental/plugins';
	const PLUGIN      = 'test-plugin/test-plugin';
	const PLUGIN_FILE = self::PLUGIN . '.php';

	/**
	 * Subscriber user ID.
	 *
	 * @since 5.5.0
	 *
	 * @var int $subscriber_id
	 */
	private static $subscriber_id;

	/**
	 * Administrator user ID.
	 *
	 * @since 5.5.0
	 *
	 * @var int $administrator_id
	 */
	private static $administrator_id;

	/**
	 * Set up class test fixtures.
	 *
	 * @since 5.5.0
	 *
	 * @param WP_UnitTest_Factory $factory WordPress unit test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$subscriber_id    = $factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);
		self::$administrator_id = $factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		if ( is_multisite() ) {
			grant_super_admin( self::$administrator_id );
		}

		if ( ! defined( 'FS_METHOD' ) ) {
			define( 'FS_METHOD', 'direct' );
		}
	}

	/**
	 * Clean up test fixtures.
	 *
	 * @since 5.5.0
	 */
	public static function wpTearDownAfterClass() {
		self::delete_user( self::$subscriber_id );
		self::delete_user( self::$administrator_id );
	}

	public function tearDown() {
		parent::tearDown();

		if ( file_exists( WP_PLUGIN_DIR . '/test-plugin/test-plugin.php' ) ) {
			$this->rmdir( WP_PLUGIN_DIR . '/test-plugin' );
		}
	}

	public function test_register_routes() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( self::BASE, $routes );
		$this->assertArrayHasKey( self::BASE . '/(?P<plugin>[^.\/]+(?:\/[^.\/]+)?)', $routes );
	}

	public function test_context_param() {
		// Collection.
		$request  = new WP_REST_Request( 'OPTIONS', self::BASE );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		$this->assertEquals( 'view', $data['endpoints'][0]['args']['context']['default'] );
		$this->assertEquals( array( 'view', 'embed', 'edit' ), $data['endpoints'][0]['args']['context']['enum'] );
		// Single.
		$request  = new WP_REST_Request( 'OPTIONS', self::BASE . '/' . self::PLUGIN );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		$this->assertEquals( 'view', $data['endpoints'][0]['args']['context']['default'] );
		$this->assertEquals( array( 'view', 'embed', 'edit' ), $data['endpoints'][0]['args']['context']['enum'] );
	}

	public function test_get_items() {
		$this->create_test_plugin();
		wp_set_current_user( self::$administrator_id );

		$response = rest_do_request( self::BASE );
		$this->assertEquals( 200, $response->get_status() );

		$items = wp_list_filter( $response->get_data(), array( 'plugin' => self::PLUGIN_FILE ) );

		$this->assertCount( 1, $items );
		$this->check_get_plugin_data( array_shift( $items ) );
	}

	public function test_get_items_logged_out() {
		$response = rest_do_request( self::BASE );
		$this->assertEquals( 401, $response->get_status() );
	}

	public function test_get_items_insufficient_permissions() {
		wp_set_current_user( self::$subscriber_id );
		$response = rest_do_request( self::BASE );
		$this->assertequals( 403, $response->get_status() );
	}

	public function test_get_item() {
		$this->create_test_plugin();
		wp_set_current_user( self::$administrator_id );

		$response = rest_do_request( self::BASE . '/' . self::PLUGIN );
		$this->assertEquals( 200, $response->get_status() );
		$this->check_get_plugin_data( $response->get_data() );
	}

	public function test_get_item_logged_out() {
		$response = rest_do_request( self::BASE . '/' . self::PLUGIN );
		$this->assertEquals( 401, $response->get_status() );
	}

	public function test_get_item_insufficient_permissions() {
		wp_set_current_user( self::$subscriber_id );
		$response = rest_do_request( self::BASE . '/' . self::PLUGIN );
		$this->assertEquals( 403, $response->get_status() );
	}

	public function test_get_item_invalid_plugin() {
		wp_set_current_user( self::$administrator_id );
		$response = rest_do_request( self::BASE . '/' . self::PLUGIN );
		$this->assertEquals( 404, $response->get_status() );
	}

	public function test_create_item() {
		if ( isset( get_plugins()['hello-dolly/hello.php'] ) ) {
			delete_plugins( array( 'hello-dolly/hello.php' ) );
		}

		wp_set_current_user( self::$administrator_id );

		$request = new WP_REST_Request( 'POST', self::BASE );
		$request->set_body_params( array( 'slug' => 'hello-dolly' ) );

		$response = rest_do_request( $request );
		$this->skip_on_filesystem_error( $response );
		$this->assertNotWPError( $response->as_error() );
		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( 'Hello Dolly', $response->get_data()['name'] );
	}

	public function test_create_item_and_activate() {
		if ( isset( get_plugins()['hello-dolly/hello.php'] ) ) {
			delete_plugins( array( 'hello-dolly/hello.php' ) );
		}

		wp_set_current_user( self::$administrator_id );

		$request = new WP_REST_Request( 'POST', self::BASE );
		$request->set_body_params(
			array(
				'slug'   => 'hello-dolly',
				'status' => 'active',
			)
		);

		$response = rest_do_request( $request );
		$this->skip_on_filesystem_error( $response );
		$this->assertNotWPError( $response->as_error() );
		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( 'Hello Dolly', $response->get_data()['name'] );
		$this->assertTrue( is_plugin_active( 'hello-dolly/hello.php' ) );
	}

	public function test_create_item_and_activate_errors_if_no_permission_to_activate_plugin() {
		if ( isset( get_plugins()['hello-dolly/hello.php'] ) ) {
			delete_plugins( array( 'hello-dolly/hello.php' ) );
		}

		wp_set_current_user( self::$administrator_id );
		$this->disable_activate_permission( 'hello-dolly/hello.php' );

		$request = new WP_REST_Request( 'POST', self::BASE );
		$request->set_body_params(
			array(
				'slug'   => 'hello-dolly',
				'status' => 'active',
			)
		);

		$response = rest_do_request( $request );
		$this->skip_on_filesystem_error( $response );
		$this->assertErrorResponse( 'rest_cannot_activate_plugin', $response );
		$this->assertFalse( is_plugin_active( 'hello-dolly/hello.php' ) );
	}

	/**
	 * @group ms-excluded
	 */
	public function test_create_item_and_network_activate_rejected_if_not_multisite() {
		if ( isset( get_plugins()['hello-dolly/hello.php'] ) ) {
			delete_plugins( array( 'hello-dolly/hello.php' ) );
		}

		wp_set_current_user( self::$administrator_id );

		$request = new WP_REST_Request( 'POST', self::BASE );
		$request->set_body_params(
			array(
				'slug'   => 'hello-dolly',
				'status' => 'network-active',
			)
		);

		$response = rest_do_request( $request );
		$this->skip_on_filesystem_error( $response );
		$this->assertErrorResponse( 'rest_invalid_param', $response );
	}

	/**
	 * @group ms-required
	 */
	public function test_create_item_and_network_activate() {
		if ( isset( get_plugins()['hello-dolly/hello.php'] ) ) {
			delete_plugins( array( 'hello-dolly/hello.php' ) );
		}

		wp_set_current_user( self::$administrator_id );

		$request = new WP_REST_Request( 'POST', self::BASE );
		$request->set_body_params(
			array(
				'slug'   => 'hello-dolly',
				'status' => 'network-active',
			)
		);

		$response = rest_do_request( $request );
		$this->skip_on_filesystem_error( $response );
		$this->assertNotWPError( $response->as_error() );
		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( 'Hello Dolly', $response->get_data()['name'] );
		$this->assertTrue( is_plugin_active_for_network( 'hello-dolly/hello.php' ) );
	}

	public function test_create_item_logged_out() {
		$request = new WP_REST_Request( 'POST', self::BASE );
		$request->set_body_params( array( 'slug' => 'hello-dolly' ) );

		$response = rest_do_request( $request );
		$this->assertEquals( 401, $response->get_status() );
	}

	public function test_create_item_insufficient_permissions() {
		wp_set_current_user( self::$subscriber_id );
		$request = new WP_REST_Request( 'POST', self::BASE );
		$request->set_body_params( array( 'slug' => 'hello-dolly' ) );

		$response = rest_do_request( $request );
		$this->assertEquals( 403, $response->get_status() );
	}

	public function test_create_item_wdotorg_unreachable() {
		wp_set_current_user( self::$administrator_id );

		$request = new WP_REST_Request( 'POST', self::BASE );
		$request->set_body_params( array( 'slug' => 'foo' ) );

		$this->prevent_requests_to_host( 'api.wordpress.org' );

		$this->expectException( 'PHPUnit_Framework_Error_Warning' );
		$response = rest_do_request( $request );
		$this->skip_on_filesystem_error( $response );
		$this->assertErrorResponse( 'plugins_api_failed', $response, 500 );
	}

	public function test_create_item_unknown_plugin() {
		wp_set_current_user( self::$administrator_id );

		// This will hit the live API.
		$request = new WP_REST_Request( 'POST', self::BASE );
		$request->set_body_params( array( 'slug' => 'alex-says-this-block-definitely-doesnt-exist' ) );
		$response = rest_do_request( $request );

		$this->skip_on_filesystem_error( $response );
		// Is this an appropriate status?
		$this->assertErrorResponse( 'plugins_api_failed', $response, 404 );
	}

	public function test_update_item() {
		$this->create_test_plugin();
		wp_set_current_user( self::$administrator_id );

		$request  = new WP_REST_Request( 'PUT', self::BASE . '/' . self::PLUGIN );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	public function test_update_item_logged_out() {
		$request  = new WP_REST_Request( 'PUT', self::BASE . '/' . self::PLUGIN );
		$response = rest_do_request( $request );

		$this->assertEquals( 401, $response->get_status() );
	}

	public function test_update_item_insufficient_permissions() {
		wp_set_current_user( self::$subscriber_id );

		$request  = new WP_REST_Request( 'PUT', self::BASE . '/' . self::PLUGIN );
		$response = rest_do_request( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	public function test_update_item_activate_plugin() {
		$this->create_test_plugin();
		wp_set_current_user( self::$administrator_id );

		$request = new WP_REST_Request( 'PUT', self::BASE . '/' . self::PLUGIN );
		$request->set_body_params( array( 'status' => 'active' ) );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( is_plugin_active( self::PLUGIN_FILE ) );
	}

	public function test_update_item_activate_plugin_fails_if_no_activate_cap() {
		$this->create_test_plugin();
		wp_set_current_user( self::$administrator_id );
		$this->disable_activate_permission( self::PLUGIN_FILE );

		$request = new WP_REST_Request( 'PUT', self::BASE . '/' . self::PLUGIN );
		$request->set_body_params( array( 'status' => 'active' ) );
		$response = rest_do_request( $request );

		$this->assertErrorResponse( 'rest_cannot_activate_plugin', $response, 403 );
	}

	/**
	 * @group ms-excluded
	 */
	public function test_update_item_network_activate_plugin_rejected_if_not_multisite() {
		$this->create_test_plugin();
		wp_set_current_user( self::$administrator_id );

		$request = new WP_REST_Request( 'PUT', self::BASE . '/' . self::PLUGIN );
		$request->set_body_params( array( 'status' => 'network-active' ) );
		$response = rest_do_request( $request );

		$this->assertErrorResponse( 'rest_invalid_param', $response );
	}

	/**
	 * @group ms-required
	 */
	public function test_update_item_network_activate_plugin() {
		$this->create_test_plugin();
		wp_set_current_user( self::$administrator_id );

		$request = new WP_REST_Request( 'PUT', self::BASE . '/' . self::PLUGIN );
		$request->set_body_params( array( 'status' => 'network-active' ) );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( is_plugin_active_for_network( self::PLUGIN_FILE ) );
	}

	/**
	 * @group ms-required
	 */
	public function test_update_item_network_activate_plugin_that_was_active_on_single_site() {
		$this->create_test_plugin();
		activate_plugin( self::PLUGIN_FILE );
		wp_set_current_user( self::$administrator_id );

		$request = new WP_REST_Request( 'PUT', self::BASE . '/' . self::PLUGIN );
		$request->set_body_params( array( 'status' => 'network-active' ) );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( is_plugin_active_for_network( self::PLUGIN_FILE ) );
	}

	public function test_update_item_deactivate_plugin() {
		$this->create_test_plugin();
		activate_plugin( self::PLUGIN_FILE );
		wp_set_current_user( self::$administrator_id );

		$request = new WP_REST_Request( 'PUT', self::BASE . '/' . self::PLUGIN );
		$request->set_body_params( array( 'status' => 'inactive' ) );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( is_plugin_inactive( self::PLUGIN_FILE ) );
	}

	public function test_update_item_deactivate_plugin_fails_if_no_deactivate_cap() {
		$this->create_test_plugin();
		activate_plugin( self::PLUGIN_FILE );
		wp_set_current_user( self::$administrator_id );
		$this->disable_deactivate_permission( self::PLUGIN_FILE );

		$request = new WP_REST_Request( 'PUT', self::BASE . '/' . self::PLUGIN );
		$request->set_body_params( array( 'status' => 'inactive' ) );
		$response = rest_do_request( $request );

		$this->assertErrorResponse( 'rest_cannot_deactivate_plugin', $response, 403 );
	}

	/**
	 * @group ms-required
	 */
	public function test_update_item_deactivate_network_active_plugin() {
		$this->create_test_plugin();
		activate_plugin( self::PLUGIN_FILE, '', true );
		wp_set_current_user( self::$administrator_id );

		$request = new WP_REST_Request( 'PUT', self::BASE . '/' . self::PLUGIN );
		$request->set_body_params( array( 'status' => 'inactive' ) );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( is_plugin_inactive( self::PLUGIN_FILE ) );
	}

	public function test_delete_item() {
		$this->create_test_plugin();
		wp_set_current_user( self::$administrator_id );

		$request  = new WP_REST_Request( 'DELETE', self::BASE . '/' . self::PLUGIN );
		$response = rest_do_request( $request );

		$this->skip_on_filesystem_error( $response );
		$this->assertNotWPError( $response->as_error() );
		$this->assertEquals( 204, $response->get_status() );
		$this->assertFileNotExists( WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE );
	}

	public function test_delete_item_logged_out() {
		$request  = new WP_REST_Request( 'DELETE', self::BASE . '/' . self::PLUGIN );
		$response = rest_do_request( $request );

		$this->assertEquals( 401, $response->get_status() );
	}

	public function test_delete_item_insufficient_permissions() {
		wp_set_current_user( self::$subscriber_id );

		$request  = new WP_REST_Request( 'DELETE', self::BASE . '/' . self::PLUGIN );
		$response = rest_do_request( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	public function test_delete_item_active_plugin() {
		$this->create_test_plugin();
		activate_plugin( self::PLUGIN_FILE );
		wp_set_current_user( self::$administrator_id );

		$request  = new WP_REST_Request( 'DELETE', self::BASE . '/' . self::PLUGIN );
		$response = rest_do_request( $request );

		$this->skip_on_filesystem_error( $response );
		$this->assertErrorResponse( 'rest_cannot_delete_active_plugin', $response );
	}

	public function test_prepare_item() {
		$this->create_test_plugin();

		$item          = get_plugins()[ self::PLUGIN_FILE ];
		$item['_file'] = self::PLUGIN_FILE;

		$endpoint = new WP_REST_Plugins_Controller();
		$response = $endpoint->prepare_item_for_response( $item, new WP_REST_Request( 'GET', self::BASE . '/' . self::PLUGIN ) );

		$this->check_get_plugin_data( $response->get_data() );
	}

	/**
	 * @group ms-required
	 */
	public function test_prepare_item_network_active() {
		$this->create_test_plugin();
		activate_plugin( self::PLUGIN_FILE, '', true );

		$item          = get_plugins()[ self::PLUGIN_FILE ];
		$item['_file'] = self::PLUGIN_FILE;

		$endpoint = new WP_REST_Plugins_Controller();
		$response = $endpoint->prepare_item_for_response( $item, new WP_REST_Request( 'GET', self::BASE . '/' . self::PLUGIN ) );

		$this->assertEquals( 'network-active', $response->get_data()['status'] );
	}

	public function test_get_item_schema() {
		$request    = new WP_REST_Request( 'OPTIONS', self::BASE );
		$response   = rest_get_server()->dispatch( $request );
		$data       = $response->get_data();
		$properties = $data['schema']['properties'];

		$this->assertCount( 11, $properties );
		$this->assertArrayHasKey( 'plugin', $properties );
		$this->assertArrayHasKey( 'status', $properties );
		$this->assertArrayHasKey( 'name', $properties );
		$this->assertArrayHasKey( 'plugin_uri', $properties );
		$this->assertArrayHasKey( 'description', $properties );
		$this->assertArrayHasKey( 'author', $properties );
		$this->assertArrayHasKey( 'author_uri', $properties );
		$this->assertArrayHasKey( 'version', $properties );
		$this->assertArrayHasKey( 'network_only', $properties );
		$this->assertArrayHasKey( 'requires_wp', $properties );
		$this->assertArrayHasKey( 'requires_php', $properties );
	}

	/**
	 * Checks the response data.
	 *
	 * @since 5.5.0
	 *
	 * @param array $data Prepared plugin data.
	 */
	protected function check_get_plugin_data( $data ) {
		$this->assertEquals( 'test-plugin/test-plugin.php', $data['plugin'] );
		$this->assertEquals( '1.5.4', $data['version'] );
		$this->assertEquals( 'inactive', $data['status'] );
		$this->assertEquals( 'Test Plugin', $data['name'] );
		$this->assertEquals( 'https://wordpress.org/plugins/test-plugin/', $data['plugin_uri'] );
		$this->assertEquals( 'WordPress.org', $data['author'] );
		$this->assertEquals( 'https://wordpress.org/', $data['author_uri'] );
		$this->assertEquals( "My 'Cool' Plugin", $data['description']['raw'] );
		$this->assertEquals( 'My &#8216;Cool&#8217; Plugin <cite>By <a href="https://wordpress.org/">WordPress.org</a>.</cite>', $data['description']['rendered'] );
		$this->assertEquals( false, $data['network_only'] );
		$this->assertEquals( '5.6.0', $data['requires_php'] );
		$this->assertEquals( '5.4.0', $data['requires_wp'] );
	}

	/**
	 * Skips the test if the response is an error due to the filesystem being unavailable.
	 *
	 * @since 5.5.0
	 *
	 * @param WP_REST_Response $response The response object to inspect.
	 */
	protected function skip_on_filesystem_error( WP_REST_Response $response ) {
		if ( ! $response->is_error() ) {
			return;
		}

		$code = $response->as_error()->get_error_code();

		if ( 'fs_unavailable' === $code || false !== strpos( $code, 'mkdir_failed' ) ) {
			$this->markTestSkipped( 'Filesystem is unavailable.' );
		}
	}

	/**
	 * Disables permission for activating a specific plugin.
	 *
	 * @param string $plugin The plugin file to disable.
	 */
	protected function disable_activate_permission( $plugin ) {
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap, $user, $args ) use ( $plugin ) {
				if ( 'activate_plugin' === $cap && $plugin === $args[0] ) {
					$caps = array( 'do_not_allow' );
				}

				return $caps;
			},
			10,
			4
		);
	}

	/**
	 * Disables permission for deactivating a specific plugin.
	 *
	 * @param string $plugin The plugin file to disable.
	 */
	protected function disable_deactivate_permission( $plugin ) {
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap, $user, $args ) use ( $plugin ) {
				if ( 'deactivate_plugin' === $cap && $plugin === $args[0] ) {
					$caps = array( 'do_not_allow' );
				}

				return $caps;
			},
			10,
			4
		);
	}

	/**
	 * Creates a test plugin.
	 *
	 * @since 5.5.0
	 */
	private function create_test_plugin() {
		$php = <<<'PHP'
<?php
/*
 * Plugin Name: Test Plugin
 * Plugin URI: https://wordpress.org/plugins/test-plugin/
 * Description: My 'Cool' Plugin
 * Version: 1.5.4
 * Author: WordPress.org
 * Author URI: https://wordpress.org/
 * Requires PHP: 5.6.0
 * Requires at least: 5.4.0
 */
PHP;
		if ( false === wp_mkdir_p( WP_PLUGIN_DIR . '/test-plugin' ) ) {
			$this->markTestSkipped( 'Filesystem is unavailable.' );
		}

		file_put_contents( WP_PLUGIN_DIR . '/test-plugin/test-plugin.php', $php );
	}

	/**
	 * Simulate a network failure on outbound http requests to a given hostname.
	 *
	 * @param string $blocked_host The host to block connections to.
	 */
	private function prevent_requests_to_host( $blocked_host = 'api.wordpress.org' ) {
		add_filter(
			'pre_http_request',
			static function ( $return, $args, $url ) use ( $blocked_host ) {
				if ( @parse_url( $url, PHP_URL_HOST ) === $blocked_host ) {
					return new WP_Error( 'plugins_api_failed', "An expected error occurred connecting to $blocked_host because of a unit test", "cURL error 7: Failed to connect to $blocked_host port 80: Connection refused" );

				}

				return $return;
			},
			10,
			3
		);
	}
}
