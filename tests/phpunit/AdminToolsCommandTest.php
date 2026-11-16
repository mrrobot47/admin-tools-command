<?php

use Symfony\Component\Yaml\Yaml;

/**
 * Admin_Tools_Command: site checks, the admin-tools compose override, the `admin_tools` flag and the exec results.
 */
class AdminToolsCommandTest extends AdminToolsTestCase {

	private function services( $list = "nginx\nphp\ndb\n" ) {
		$this->runner->on( '/ config --services$/', $list );
	}

	public function test_enable_writes_the_compose_override_starts_nginx_and_sets_the_flag() {
		$dir = $this->site();
		$this->global_auth();
		$this->preinstall();
		$this->services();

		$this->run_ok(
			function () {
				$this->command()->enable( [ 'example.test' ], [] );
			}
		);

		$this->assertSame(
			[ 'docker-compose -f docker-compose.yml  config --services', 'docker-compose -f docker-compose.yml  -f docker-compose-admin.yml up -d nginx' ],
			$this->runner->commands()
		);
		$this->assertSame( $dir, getcwd() );
		$this->assertSame( '1', (string) $this->site_row()['admin_tools'] );
		$this->assertStringContainsString( 'admin-tools enabled for example.test site.', $this->stdout() );

		$compose = Yaml::parse( file_get_contents( "$dir/docker-compose-admin.yml" ) );
		$this->assertSame( [ 'REDIS_HOST=global-redis', 'DB_HOST=global-db' ], $compose['services']['php']['environment'] );
		$tools_mount = EE_ROOT_DIR . '/admin-tools:/var/www/htdocs/ee-admin:ro';
		$this->assertContains( $tools_mount, $compose['services']['php']['volumes'] );
		$this->assertContains( $tools_mount, $compose['services']['nginx']['volumes'] );
	}

	public function test_enable_uses_the_site_cache_and_db_hosts() {
		$dir = $this->site( [ 'cache_host' => 'examplecom_redis', 'db_host' => 'db.internal' ] );
		$this->global_auth();
		$this->preinstall();
		$this->services();

		$this->run_ok(
			function () {
				$this->command()->enable( [ 'example.test' ], [] );
			}
		);

		$compose = Yaml::parse( file_get_contents( "$dir/docker-compose-admin.yml" ) );
		$this->assertSame( [ 'REDIS_HOST=examplecom_redis', 'DB_HOST=db.internal' ], $compose['services']['php']['environment'] );
	}

	public function test_enable_accepts_a_trailing_slash() {
		$this->site();
		$this->global_auth();
		$this->preinstall();
		$this->services();

		$this->run_ok(
			function () {
				$this->command()->enable( [ 'example.test/' ], [] );
			}
		);
		$this->assertSame( '1', (string) $this->site_row()['admin_tools'] );
	}

	public function test_enable_fails_and_keeps_the_flag_when_compose_up_fails() {
		$this->site();
		$this->global_auth();
		$this->preinstall();
		$this->services();
		$this->runner->on( '/ up -d nginx$/', '', 1 );

		$this->assert_ee_error(
			function () {
				$this->command()->enable( [ 'example.test' ], [] );
			},
			'Error in enabling admin-tools for example.test site. Check logs.'
		);
		$this->assertSame( '0', (string) $this->site_row()['admin_tools'] );
	}

	public function test_enable_refuses_a_disabled_site() {
		$this->site( [ 'site_enabled' => 0 ] );
		$this->global_auth();

		$this->assert_ee_error(
			function () {
				$this->command()->enable( [ 'example.test' ], [] );
			},
			'Site example.test does not exist / is not enabled.'
		);
		$this->assertSame( [], $this->runner->matching( '/docker-compose/' ) );
	}

	public function test_enable_refuses_when_already_enabled_unless_forced() {
		$this->site( [ 'admin_tools' => 1 ] );
		$this->global_auth();
		$this->preinstall();
		$this->services();

		$this->assert_ee_error(
			function () {
				$this->command()->enable( [ 'example.test' ], [] );
			},
			'admin-tools already seem to be enabled for example.test'
		);
		$this->assertSame( [], $this->runner->commands() );

		$this->run_ok(
			function () {
				$this->command()->enable( [ 'example.test' ], [ 'force' => true ] );
			}
		);
		$this->assertCount( 1, $this->runner->matching( '/ up -d nginx$/' ) );
	}

	/**
	 * Sites without both nginx and php (html) can't serve the PHP tools.
	 *
	 * @dataProvider unsupported_services
	 */
	public function test_enable_requires_nginx_and_php_services( $services ) {
		$dir = $this->site( [ 'site_type' => 'html' ] );
		$this->global_auth();
		$this->services( $services );

		$this->assert_ee_error(
			function () {
				$this->command()->enable( [ 'example.test' ], [] );
			},
			'Site of type html does not support admin-tools.'
		);
		$this->assertFileDoesNotExist( "$dir/docker-compose-admin.yml" );
		$this->assertDirectoryDoesNotExist( ADMIN_TOOL_DIR );
	}

	public function unsupported_services() {
		return [
			'html site'  => [ "nginx\n" ],
			'php only'   => [ "php\ndb\n" ],
			'no service' => [ '' ],
		];
	}

	public function test_disable_recreates_nginx_and_php_without_the_override_and_clears_the_flag() {
		$dir = $this->site( [ 'admin_tools' => 1 ] );

		$this->run_ok(
			function () {
				$this->command()->disable( [ 'example.test' ], [] );
			}
		);

		$this->assertSame( [ 'docker-compose -f docker-compose.yml  up -d nginx php' ], $this->runner->commands() );
		$this->assertSame( $dir, getcwd() );
		$this->assertSame( '0', (string) $this->site_row()['admin_tools'] );
		$this->assertStringContainsString( 'admin-tools disabled for example.test site.', $this->stdout() );
	}

	public function test_disable_refuses_when_already_disabled_unless_forced() {
		$this->site();

		$this->assert_ee_error(
			function () {
				$this->command()->disable( [ 'example.test' ], [] );
			},
			'admin-tools already seem to be disabled for example.test'
		);
		$this->assertSame( [], $this->runner->commands() );

		$this->run_ok(
			function () {
				$this->command()->disable( [ 'example.test' ], [ 'force' => true ] );
			}
		);
		$this->assertCount( 1, $this->runner->matching( '/ up -d nginx php$/' ) );
	}

	public function test_disable_refuses_a_disabled_site() {
		$this->site( [ 'site_enabled' => 0, 'admin_tools' => 1 ] );

		$this->assert_ee_error(
			function () {
				$this->command()->disable( [ 'example.test' ], [] );
			},
			'Site example.test does not exist / is not enabled.'
		);
	}

	/**
	 * ATC-4: disable() ignores a failed `docker-compose up`, reports success and records admin-tools as off.
	 *
	 * @group known-bug
	 * @group ATC-4
	 */
	public function test_disable_fails_when_compose_up_fails() {
		$this->site( [ 'admin_tools' => 1 ] );
		$this->runner->on( '/ up -d nginx php$/', '', 1, 'no such service: php' );

		$this->assert_ee_error(
			function () {
				$this->command()->disable( [ 'example.test' ], [] );
			}
		);
		$this->assertSame( '1', (string) $this->site_row()['admin_tools'] );
	}

	/**
	 * ATC-2: `http://<site>/` passes auto_site_name() but is then looked up verbatim, so the site "does not exist".
	 *
	 * @group known-bug
	 * @group ATC-2
	 */
	public function test_disable_accepts_a_site_url_with_scheme() {
		$this->site( [ 'admin_tools' => 1 ] );

		$this->run_ok(
			function () {
				$this->command()->disable( [ 'http://example.test/' ], [] );
			}
		);
		$this->assertSame( '0', (string) $this->site_row()['admin_tools'] );
	}

	/**
	 * ATC-3: enable() initialises the global admin-tools auth (proxy check, htpasswd, auth rows) before validating the site.
	 *
	 * @group known-bug
	 * @group ATC-3
	 */
	public function test_enable_validates_the_site_before_touching_global_auth() {
		$this->site( [ 'site_enabled' => 0 ] );
		// A running proxy with matching ports; the auth init then stops at the htpasswd check (past it, it spawns `ee auth update`).
		$this->docker_on_path();
		$this->runner->on( "/^docker inspect -f '\\{\\{\\.State\\.Running\\}\\}' services_global-nginx-proxy_1$/", "true\n" );
		$this->runner->on( '/^docker inspect --format .*"80\\/tcp"/', "80\n" );
		$this->runner->on( '/^docker inspect --format .*"443\\/tcp"/', "443\n" );
		$this->runner->on( '/^docker exec services_global-nginx-proxy_1 /', '', 1 );

		$this->assert_ee_error(
			function () {
				$this->command()->enable( [ 'example.test' ], [] );
			},
			'Site example.test does not exist / is not enabled.'
		);
		$this->assertSame( [], $this->runner->commands() );
		$this->assertSame( [], EE\Tests\TestDB::rows( 'auth_users' ) );
	}

	/**
	 * ATC-10: the override mounts the whole EE database (every site's credentials) into the site's php and nginx.
	 *
	 * @group known-bug
	 * @group ATC-10
	 */
	public function test_enable_does_not_mount_the_ee_database_into_the_site() {
		$dir = $this->site();
		$this->global_auth();
		$this->preinstall();
		$this->services();

		$this->run_ok(
			function () {
				$this->command()->enable( [ 'example.test' ], [] );
			}
		);

		$compose = Yaml::parse( file_get_contents( "$dir/docker-compose-admin.yml" ) );
		foreach ( [ 'php', 'nginx' ] as $service ) {
			foreach ( $compose['services'][ $service ]['volumes'] as $volume ) {
				$this->assertStringNotContainsString( DB, $volume, "$service mounts the EE database" );
			}
		}
	}

	/**
	 * ATC-7: the constructor define()s ADMIN_TOOL_DIR unconditionally, so a second instance warns.
	 *
	 * @group known-bug
	 * @group ATC-7
	 */
	public function test_constructor_can_run_twice() {
		new Admin_Tools_Command();
		new Admin_Tools_Command();
		$this->assertSame( EE_ROOT_DIR . '/admin-tools', ADMIN_TOOL_DIR );
	}
}
