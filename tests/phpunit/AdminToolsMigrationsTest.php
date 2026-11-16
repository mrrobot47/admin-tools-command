<?php

use EE\Migration\UpdateConfigWithEnv;
use EE\Migration\UpdateIndex;
use EE\Migration\UpdateIndexForPublicDir;
use EE\Migration\UpdatePmaConfig;
use EE\Model\Option;
use Symfony\Component\Yaml\Yaml;

/**
 * The container migrations in migrations/container/: when they skip, and what they write and run.
 */
class AdminToolsMigrationsTest extends AdminToolsTestCase {

	public static function setUpBeforeClass(): void {
		foreach ( glob( dirname( __DIR__, 2 ) . '/migrations/container/*.php' ) as $file ) {
			require_once $file;
		}
	}

	private function upgrade() {
		Option::set( 'version', '4.12.0' );
	}

	private function skip( $migration ) {
		return self::get_property( $migration, 'skip_this_migration' );
	}

	/**
	 * @dataProvider index_migrations
	 */
	public function test_index_migrations_run_only_on_upgrades_with_an_admin_tools_site( $class ) {
		$this->assertTrue( $this->skip( new $class() ), 'first execution, no sites' );

		$this->upgrade();
		$this->assertTrue( $this->skip( new $class() ), 'no sites' );

		$this->insert_site( [ 'site_url' => 'plain.test' ] );
		$this->assertTrue( $this->skip( new $class() ), 'no admin-tools site' );

		$this->insert_site( [ 'site_url' => 'tools.test', 'admin_tools' => 1 ] );
		$this->assertFalse( $this->skip( new $class() ) );

		Option::set( 'version', '' );
		$this->assertTrue( $this->skip( new $class() ), 'first execution' );
	}

	/**
	 * @dataProvider index_migrations
	 */
	public function test_index_migrations_replace_the_index_and_keep_a_backup( $class ) {
		$this->upgrade();
		$this->insert_site( [ 'site_url' => 'tools.test', 'admin_tools' => 1 ] );
		$this->preinstall( [ 'index.php' ] );

		( new $class() )->up();

		$this->assertStringEqualsFile( EE_BACKUP_DIR . '/admin-tools/index.php', "<?php // installed\n" );
		$this->assertStringEqualsFile( ADMIN_TOOL_DIR . '/index.php', $this->rendered_index() );
		$this->assertSame( [], $this->runner->commands() );
	}

	public function index_migrations() {
		return [
			'UpdateIndex'             => [ UpdateIndex::class ],
			'UpdateIndexForPublicDir' => [ UpdateIndexForPublicDir::class ],
		];
	}

	public function test_update_pma_config_runs_only_when_pma_is_installed() {
		$this->upgrade();
		$this->insert_site( [ 'site_url' => 'tools.test' ] );
		$this->assertTrue( $this->skip( new UpdatePmaConfig() ) );

		$this->preinstall( [ 'pma' ] );
		touch( ADMIN_TOOL_DIR . '/pma/config.inc.php' );
		$this->assertFalse( $this->skip( new UpdatePmaConfig() ) );

		Option::set( 'version', '' );
		$this->assertTrue( $this->skip( new UpdatePmaConfig() ), 'first execution' );
	}

	public function test_update_pma_config_sets_a_random_blowfish_secret() {
		$this->upgrade();
		$this->insert_site( [ 'site_url' => 'tools.test' ] );
		$this->preinstall( [ 'pma' ] );
		file_put_contents( ADMIN_TOOL_DIR . '/pma/config.inc.php', "<?php // old\n" );

		( new UpdatePmaConfig() )->up();

		$this->assertStringEqualsFile( EE_BACKUP_DIR . '/admin-tools/pma/config.inc.php', "<?php // old\n" );
		$config = file_get_contents( ADMIN_TOOL_DIR . '/pma/config.inc.php' );
		$this->assertMatchesRegularExpression( "/^\\\$cfg\\['blowfish_secret'\\] = '[A-Za-z0-9]{32}';/m", $config );
		$this->assertStringContainsString( "\$cfg['Servers'][\$i]['host'] = getenv('DB_HOST');", $config );
	}

	public function test_update_config_with_env_runs_when_the_index_is_installed() {
		$this->upgrade();
		$this->assertTrue( $this->skip( new UpdateConfigWithEnv() ) );

		$this->preinstall( [ 'index.php' ] );
		$this->assertFalse( $this->skip( new UpdateConfigWithEnv() ) );
	}

	/**
	 * ATC-8: the first-execution skip is overwritten by the index.php check on the next line.
	 *
	 * @group known-bug
	 * @group ATC-8
	 */
	public function test_update_config_with_env_skips_the_first_execution() {
		$this->preinstall( [ 'index.php' ] );
		$this->assertTrue( $this->skip( new UpdateConfigWithEnv() ), 'Runs on a first execution.' );
	}

	public function test_update_config_with_env_rewrites_configs_and_re_enables_admin_tools_sites() {
		$this->upgrade();
		$this->preinstall( [ 'index.php', 'pma', 'pra' ] );
		mkdir( ADMIN_TOOL_DIR . '/pra/includes' );
		file_put_contents( ADMIN_TOOL_DIR . '/pma/config.inc.php', "<?php // old pma\n" );
		file_put_contents( ADMIN_TOOL_DIR . '/pra/includes/config.inc.php', "<?php // old pra\n" );
		$tools_dir = $this->site( [ 'site_url' => 'tools.test', 'admin_tools' => 1, 'cache_host' => 'toolstest_redis' ] );
		$plain_dir = $this->site( [ 'site_url' => 'plain.test' ] );

		( new UpdateConfigWithEnv() )->up();

		$this->assertStringEqualsFile( EE_BACKUP_DIR . '/admin-tools/pma/config.inc.php.bak', "<?php // old pma\n" );
		$this->assertStringEqualsFile( EE_BACKUP_DIR . '/admin-tools/pra/includes/config.inc.php.bak', "<?php // old pra\n" );
		$this->assertStringEqualsFile( ADMIN_TEMPLATE_ROOT . '/pra.config.mustache', file_get_contents( ADMIN_TOOL_DIR . '/pra/includes/config.inc.php' ) );
		$this->assertStringEqualsFile( ADMIN_TOOL_DIR . '/index.php', $this->rendered_index() );

		$this->assertSame( [ 'docker-compose -f docker-compose.yml -f docker-compose-admin.yml up -d nginx' ], $this->runner->commands() );
		$this->assertSame( $tools_dir, getcwd() );
		$compose = Yaml::parse( file_get_contents( "$tools_dir/docker-compose-admin.yml" ) );
		$this->assertSame( [ 'REDIS_HOST=toolstest_redis', 'DB_HOST=global-db' ], $compose['services']['php']['environment'] );
		$this->assertFileDoesNotExist( "$plain_dir/docker-compose-admin.yml" );
	}

	/**
	 * ATC-9: re_enable_admin_tools() hard-codes the compose files, so the site's custom compose files are dropped.
	 *
	 * @group known-bug
	 * @group ATC-9
	 */
	public function test_re_enable_admin_tools_keeps_custom_compose_files() {
		$dir = $this->site( [ 'site_url' => 'tools.test', 'admin_tools' => 1 ] );
		mkdir( "$dir/" . SITE_CUSTOM_DOCKER_COMPOSE_DIR );
		touch( "$dir/" . SITE_CUSTOM_DOCKER_COMPOSE_DIR . '/extra.yml' );

		UpdateConfigWithEnv::re_enable_admin_tools( $this->site_row( 'tools.test' ) );

		$this->assertCount( 1, $this->runner->matching( '#-f docker-compose-admin.yml .*-f user-docker-compose/extra.yml .*up -d nginx$#' ), implode( "\n", $this->runner->commands() ) );
	}
}
