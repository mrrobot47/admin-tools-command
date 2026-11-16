<?php

/**
 * templates/index.mustache, the /ee-admin/ page: rendered as install_index() does and run in a child `php` against
 * the test's EE database, with the Host header and the php container's VIRTUAL_HOST.
 */
class AdminToolsIndexTest extends AdminToolsTestCase {

	/** @var string Stand-in for /var/www/htdocs/ee-admin, the tools dir the page lists. */
	private $tools_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->tools_dir = $this->sandbox() . '/ee-admin';
		mkdir( $this->tools_dir );
		foreach ( [ 'index.php', 'phpinfo.php', 'opcache-gui.php' ] as $file ) {
			touch( "$this->tools_dir/$file" );
		}
		mkdir( "$this->tools_dir/pma" );
		mkdir( "$this->tools_dir/pra" );
	}

	/**
	 * Serve the index for $host.
	 *
	 * @return array [ links, stderr ]
	 */
	private function request( $host, $virtual_host = 'example.test' ) {
		$dir = $this->sandbox();
		self::call_private( $this->command(), 'install_index', [], "$dir/index" );
		// The page scans a fixed container path; point it at the fixture dir.
		$index = str_replace( "'/var/www/htdocs/ee-admin'", var_export( $this->tools_dir, true ), file_get_contents( "$dir/index.php" ), $count );
		$this->assertSame( 1, $count, 'tools dir literal in index.mustache' );
		file_put_contents( "$dir/index.php", $index );
		file_put_contents( "$dir/server.php", '<?php $_SERVER["HTTP_HOST"] = ' . var_export( $host, true ) . ';' );

		$proc = proc_open(
			[ PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', '-d', "auto_prepend_file=$dir/server.php", "$dir/index.php" ],
			[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
			$pipes,
			$dir,
			array_filter( [ 'PATH' => getenv( 'PATH' ), 'VIRTUAL_HOST' => $virtual_host ] )
		);
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $proc );
		preg_match_all( '#<a href="([^"]*)">\1</a><br/>#', $stdout, $links );

		return [ $links[1], trim( $stderr ) ];
	}

	private function example_site( array $fields = [] ) {
		$this->insert_site( $fields + [ 'site_url' => 'example.test', 'site_type' => 'wp', 'admin_tools' => 1, 'mailhog_enabled' => 1, 'cache_nginx_fullpage' => 1, 'alias_domains' => 'example.test,www.example.test' ] );
	}

	public function test_install_index_renders_the_database_path() {
		$dir = $this->sandbox();
		self::call_private( $this->command(), 'install_index', [], "$dir/index" );
		$index = file_get_contents( "$dir/index.php" );
		$this->assertSame( 1, substr_count( $index, "\$db_path = '" . DB . "';" ) );
		$this->assertStringNotContainsString( '{{', $index );
	}

	/**
	 * @dataProvider site_flags
	 */
	public function test_links_follow_the_site_flags( $mailhog, $page_cache, $links ) {
		$this->example_site( [ 'mailhog_enabled' => $mailhog, 'cache_nginx_fullpage' => $page_cache ] );
		$this->insert_site( [ 'site_url' => 'other.test', 'mailhog_enabled' => 1 - $mailhog, 'cache_nginx_fullpage' => 1 - $page_cache ] );

		list( $got, $stderr ) = $this->request( 'example.test' );

		$this->assertSame( $links, $got );
		$this->assertSame( '', $stderr );
	}

	public function site_flags() {
		return [
			'mailhog and page cache' => [ 1, 1, [ 'mailhog', 'nginx_status', 'opcache-gui.php', 'phpinfo.php', 'ping', 'pma', 'pra', 'status' ] ],
			'page cache only'        => [ 0, 1, [ 'nginx_status', 'opcache-gui.php', 'phpinfo.php', 'ping', 'pma', 'pra', 'status' ] ],
			'mailhog only'           => [ 1, 0, [ 'mailhog', 'nginx_status', 'opcache-gui.php', 'phpinfo.php', 'ping', 'pma', 'status' ] ],
			'neither'                => [ 0, 0, [ 'nginx_status', 'opcache-gui.php', 'phpinfo.php', 'ping', 'pma', 'status' ] ],
		];
	}

	/**
	 * ATC-5: an alias domain or a Host with a port finds no `sites` row, so PHP warns and the mailhog/pra links vanish.
	 *
	 * @group known-bug
	 * @group ATC-5
	 * @dataProvider other_hosts
	 */
	public function test_alias_and_port_hosts_show_the_site_tools( $host ) {
		$this->example_site();

		list( $got, $stderr ) = $this->request( $host );

		$this->assertSame( '', $stderr );
		$this->assertSame( [ 'mailhog', 'nginx_status', 'opcache-gui.php', 'phpinfo.php', 'ping', 'pma', 'pra', 'status' ], $got );
	}

	public function other_hosts() {
		return [
			'alias domain'   => [ 'www.example.test' ],
			'host with port' => [ 'example.test:8443' ],
		];
	}

	/**
	 * ATC-5: HTTP_HOST is concatenated into the SQL, so a crafted Host is a boolean oracle over the whole EE database.
	 *
	 * @group known-bug
	 * @group ATC-5
	 * @dataProvider virtual_hosts
	 */
	public function test_host_header_cannot_query_other_tables( $virtual_host ) {
		$this->example_site();
		EE::db()->table( 'auth_users' )->insert( [ 'site_url' => 'default', 'username' => 'easyengine', 'password' => 'secret' ] );

		$guesses = [];
		foreach ( [ 's', 'z' ] as $letter ) {
			list( $guesses[ $letter ] ) = $this->request( "x'OR(SELECT(substr(password,1,1))FROM(auth_users))='$letter", $virtual_host );
		}

		$this->assertSame( $guesses['z'], $guesses['s'], 'The page differs when the guessed first letter of an auth password is right.' );
	}

	public function virtual_hosts() {
		return [
			'php container with VIRTUAL_HOST' => [ 'example.test' ],
			'no VIRTUAL_HOST'                 => [ null ],
		];
	}
}
