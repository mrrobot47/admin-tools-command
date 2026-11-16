<?php

use EE\ExitException;
use EE\Tests\TestCase;

/**
 * Base for admin-tools tests: a clean ADMIN_TOOL_DIR, a site dir per test and a global auth, so enable() doesn't
 * try to create one (that path starts the proxy and spawns `ee auth update`).
 */
abstract class AdminToolsTestCase extends TestCase {

	private $env_backup = [];

	protected function setUp(): void {
		parent::setUp();
		// The constructor define()s ADMIN_TOOL_DIR, so only the first instance may run it (ATC-7).
		if ( ! defined( 'ADMIN_TOOL_DIR' ) ) {
			new Admin_Tools_Command();
		}
		$this->clean_admin_dirs();
		$this->set_config( [ 'custom-compose' => null, 'skip-tty' => true ] );
	}

	protected function tearDown(): void {
		foreach ( $this->env_backup as $name => $value ) {
			putenv( false === $value ? $name : "$name=$value" );
		}
		$this->env_backup = [];
		$this->clean_admin_dirs();
		parent::tearDown();
	}

	private function clean_admin_dirs() {
		exec( 'rm -rf ' . escapeshellarg( ADMIN_TOOL_DIR ) . ' ' . escapeshellarg( EE_BACKUP_DIR . '/admin-tools' ) );
	}

	/** Set an environment variable for this test only (false unsets it). */
	protected function set_env( $name, $value ) {
		if ( ! array_key_exists( $name, $this->env_backup ) ) {
			$this->env_backup[ $name ] = getenv( $name );
		}
		putenv( false === $value ? $name : "$name=$value" );
	}

	/**
	 * EE_DOCKER::container_status() first runs a real `which docker`; put a dummy docker on PATH so it passes.
	 * The dummy is never run: EE::exec()/launch() go to the fake runner.
	 */
	protected function docker_on_path() {
		$bin = $this->sandbox() . '/bin';
		mkdir( $bin );
		file_put_contents( "$bin/docker", "#!/bin/sh\nexit 1\n" );
		chmod( "$bin/docker", 0755 );
		$this->set_env( 'PATH', "$bin:" . getenv( 'PATH' ) );
	}

	/** Make every https download fail fast, so the URL a tool installer picked shows up in the error. */
	protected function block_downloads() {
		$this->set_env( 'https_proxy', 'http://127.0.0.1:9' );
		$this->set_env( 'no_proxy', false );
		$this->set_env( 'NO_PROXY', false );
	}

	/** @return Admin_Tools_Command An instance that skips the constructor's define(). */
	protected function command() {
		$command = ( new ReflectionClass( Admin_Tools_Command::class ) )->newInstanceWithoutConstructor();
		self::set_property( $command, 'fs', new Symfony\Component\Filesystem\Filesystem() );

		return $command;
	}

	/**
	 * Insert a WordPress site with its own directory.
	 *
	 * @return string The site directory.
	 */
	protected function site( array $fields = [] ) {
		$fields += [
			'site_url'   => 'example.test',
			'site_type'  => 'wp',
			'cache_host' => 'global-redis',
			'db_host'    => 'global-db',
		];
		$dir = $this->sandbox() . '/' . $fields['site_url'];
		mkdir( $dir );
		$fields += [ 'site_fs_path' => $dir ];
		$this->insert_site( $fields );

		return $fields['site_fs_path'];
	}

	/** The admin-tools auth init_global_admin_tools_auth() looks for; with it, the init only logs. */
	protected function global_auth() {
		EE::db()->table( 'auth_users' )->insert( [ 'site_url' => 'default_admin_tools', 'username' => 'easyengine', 'password' => 'secret' ] );
	}

	/** Mark tools as installed so install() doesn't download them. */
	protected function preinstall( array $tools = [ 'index.php', 'phpinfo.php', 'pma', 'pra', 'opcache-gui.php' ] ) {
		@mkdir( ADMIN_TOOL_DIR, 0755, true );
		foreach ( $tools as $tool ) {
			if ( '.php' === substr( $tool, -4 ) ) {
				file_put_contents( ADMIN_TOOL_DIR . "/$tool", "<?php // installed\n" );
			} else {
				mkdir( ADMIN_TOOL_DIR . "/$tool" );
			}
		}
	}

	/** @return string index.mustache rendered with the data install_index() passes. */
	protected function rendered_index() {
		$index = EE\Utils\mustache_render( ADMIN_TEMPLATE_ROOT . '/index.mustache', [ 'db_path' => DB, 'ee_admin_path' => '/var/www/htdocs/ee-admin' ] );
		$this->assertStringNotContainsString( '{{', $index );

		return $index;
	}

	/** @return array|null The `sites` row of $url. */
	protected function site_row( $url = 'example.test' ) {
		foreach ( EE\Tests\TestDB::rows( 'sites' ) as $row ) {
			if ( $url === $row['site_url'] ) {
				return $row;
			}
		}

		return null;
	}

	/** Run $fn and fail with the EE error message if it exits. */
	protected function run_ok( callable $fn ) {
		try {
			$fn();
		} catch ( ExitException $e ) {
			$this->fail( 'Unexpected EE::error(): ' . trim( $this->stderr() ) );
		}
	}
}
