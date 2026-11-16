<?php

/**
 * Admin_Tools_Command::install() and the per-tool installers: ee-tools.json handling, install detection and the
 * phpRedisAdmin download URL. Downloads are blocked (block_downloads()), so the chosen URL shows up in the error.
 */
class AdminToolsInstallTest extends AdminToolsTestCase {

	protected $reset_db = false;

	public function test_every_tool_in_ee_tools_json_has_an_installer() {
		$tools = json_decode( file_get_contents( ADMIN_TOOLS_FILE ), true );
		$this->assertSame( JSON_ERROR_NONE, json_last_error() );
		$this->assertSame( [ 'index', 'phpinfo', 'pma', 'pra', 'opcache' ], array_keys( $tools ) );
		foreach ( array_keys( $tools ) as $tool ) {
			$this->assertTrue( method_exists( Admin_Tools_Command::class, "install_$tool" ), "install_$tool" );
		}
		$this->assertSame( 'latest', $tools['pra']['version'] );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $tools['pra']['fallback_version'] );
	}

	/**
	 * @dataProvider installed_paths
	 */
	public function test_is_installed_maps_tools_to_their_paths( $tool, $path ) {
		$command = $this->command();
		$this->assertFalse( self::call_private( $command, 'is_installed', $tool ) );
		@mkdir( ADMIN_TOOL_DIR, 0755, true );
		if ( '' !== $path ) {
			touch( ADMIN_TOOL_DIR . "/$path" );
		}
		$this->assertTrue( self::call_private( $command, 'is_installed', $tool ) );
	}

	public function installed_paths() {
		return [
			'admin-tools dir' => [ '', '' ],
			'index'           => [ 'index', 'index.php' ],
			'phpinfo'         => [ 'phpinfo', 'phpinfo.php' ],
			'opcache'         => [ 'opcache', 'opcache-gui.php' ],
			'pma'             => [ 'pma', 'pma' ],
			'pra'             => [ 'pra', 'pra' ],
		];
	}

	public function test_install_adds_only_the_missing_tools() {
		$this->preinstall( [ 'pma', 'pra', 'opcache-gui.php' ] );

		$this->run_ok(
			function () {
				self::call_private( $this->command(), 'install' );
			}
		);

		$this->assertStringEqualsFile( ADMIN_TOOL_DIR . '/index.php', $this->rendered_index() );
		$this->assertStringEqualsFile( ADMIN_TEMPLATE_ROOT . '/phpinfo.mustache', file_get_contents( ADMIN_TOOL_DIR . '/phpinfo.php' ) );
		$this->assertSame( [ '.', '..' ], scandir( ADMIN_TOOL_DIR . '/pma' ) );
		$this->assertSame(
			"Installing index\nSuccess: Installed index successfully.\nInstalling phpinfo\nSuccess: Installed phpinfo successfully.\n",
			$this->stdout()
		);
		$this->assertSame( [], $this->runner->commands() );
	}

	public function test_first_install_creates_the_dir_and_installs_in_file_order() {
		$this->block_downloads();

		$this->assert_ee_error(
			function () {
				self::call_private( $this->command(), 'install' );
			},
			"Failed to get url 'https://www.phpmyadmin.net/downloads/phpMyAdmin-latest-all-languages.zip'"
		);
		$this->assertStringStartsWith( "Installing admin-tools. This may take some time.\nInstalling index\n", $this->stdout() );
		$this->assertStringContainsString( "Installing pma\n", $this->stdout() );
		$this->assertFileExists( ADMIN_TOOL_DIR . '/index.php' );
		$this->assertFileExists( ADMIN_TOOL_DIR . '/phpinfo.php' );
	}

	/**
	 * @dataProvider pra_downloads
	 */
	public function test_install_pra_picks_the_download_url( $data, $release_info, $url, $warning ) {
		$this->block_downloads();
		if ( null !== $release_info ) {
			$data['url'] = $this->sandbox() . '/release.json';
			file_put_contents( $data['url'], $release_info );
		}

		$stderr = $this->assert_ee_error(
			function () use ( $data ) {
				self::call_private( $this->command(), 'install_pra', $data, ADMIN_TOOL_DIR . '/pra' );
			},
			"Failed to get url '$url'"
		);
		if ( $warning ) {
			$this->assertStringContainsString( 'Warning: Unable to fetch latest phpRedisAdmin release info from GitHub. Falling back to version 1.26.0.', $stderr );
		} else {
			$this->assertStringNotContainsString( 'Falling back', $stderr );
		}
	}

	public function pra_downloads() {
		$latest = [ 'version' => 'latest', 'fallback_version' => '1.26.0' ];

		return [
			'latest release'     => [ $latest, '{"tag_name":"v1.27.0","zipball_url":"https://api.github.com/repos/erikdubbelboer/phpRedisAdmin/zipball/v1.27.0"}', 'https://api.github.com/repos/erikdubbelboer/phpRedisAdmin/zipball/v1.27.0', false ],
			'invalid json'       => [ $latest, '{"message":', 'https://github.com/erikdubbelboer/phpRedisAdmin/archive/v1.26.0.zip', true ],
			'empty response'     => [ $latest, '', 'https://github.com/erikdubbelboer/phpRedisAdmin/archive/v1.26.0.zip', true ],
			'rate limited'       => [ $latest, '{"message":"API rate limit exceeded"}', 'https://github.com/erikdubbelboer/phpRedisAdmin/archive/v1.26.0.zip', true ],
			'pinned version'     => [ [ 'version' => '1.20.0', 'url' => '/nonexistent' ], null, 'https://github.com/erikdubbelboer/phpRedisAdmin/archive/v1.20.0.zip', false ],
		];
	}

	public function test_install_pra_without_fallback_fails_when_the_release_is_unknown() {
		$data = [ 'version' => 'latest', 'url' => $this->sandbox() . '/release.json' ];
		file_put_contents( $data['url'], '{}' );

		$this->assert_ee_error(
			function () use ( $data ) {
				self::call_private( $this->command(), 'install_pra', $data, ADMIN_TOOL_DIR . '/pra' );
			},
			'Unable to fetch latest phpRedisAdmin release info from GitHub and no fallback version configured.'
		);
	}
}
