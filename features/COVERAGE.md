# admin-tools-command Behat coverage

Suite for `ee admin-tools` (package `easyengine/admin-tools-command`, based on `upstream/develop` 5005d1c). Run it like the other package suites: copy `features/` into a core checkout's `features/` and run `vendor/bin/behat` from the core root as root (see `tools/run-behat.sh`).

## Inventory

### Commands, arguments and flags

| Item | Covered by |
|---|---|
| `ee admin-tools` (help, subcommand list) | cli: "The admin-tools command lists its subcommands" |
| `ee admin-tools enable [<site-name>] [--force]` synopsis | cli: "Each subcommand documents its synopsis" |
| `ee admin-tools disable [<site-name>] [--force]` synopsis | cli: "Each subcommand documents its synopsis" |
| `install` is private (not a subcommand) | cli: "install is an internal step, not a public subcommand" |
| enable/disable `<site-name>` given | every enable/disable scenario |
| enable/disable `<site-name>` omitted, run inside the site dir (`auto_site_name`) | cli: "The site is detected from the current directory" |
| `<site-name>` omitted outside a site dir | cli: "A site name is required outside a site directory" |
| wrong `<site-name>` inside a site dir (suggestion) | cli: "A wrong site name inside a site directory suggests the right one" |
| `<site-name>` with trailing slash | cli: "A trailing slash on the site name is accepted" |
| `<site-name>` as `http://site/` | cli: "A URL form of the site name is accepted" (`@known-bug` ATC-2) |
| unknown site | cli: "An unknown site is rejected" |
| disabled site (with and without `--force`) | cli: "A disabled site is rejected"; lifecycle: "Disabling and enabling the site re-enables admin-tools" |
| unknown flag / extra positional | cli: "Unknown flags are rejected", "More than one site name is rejected" |
| enable `--force` on an enabled site | enable: "Enabling again with --force re-applies the configuration", "--force restores a site whose docker-compose-admin.yml was removed"; install: "Enabling with everything installed installs nothing" |
| enable without `--force` on an enabled site | enable: "Enabling twice without --force is rejected and changes nothing" |
| disable `--force` on a disabled site | disable: "Force-disabling a site that never had admin-tools succeeds" |
| disable without `--force` on a disabled site | disable: "Disabling twice without --force is rejected"; cli: "Disabling on an html site without --force ..." |

### Behaviour and side effects

| Item | Covered by |
|---|---|
| Site types: php supported | enable, disable, http, auth, install, lifecycle features |
| Site types: wp supported | enable: "Enable admin-tools on a WordPress site" |
| Site types: html rejected (`config --services` lacks php) | cli: "An html site does not support admin-tools" |
| php site with `--public-dir` | enable: "Enable admin-tools on a php site with a public dir" |
| `sites.admin_tools` set to 1 / 0 | enable, disable, cli, install, lifecycle |
| `docker-compose-admin.yml` content (ro tool + DB mounts, REDIS_HOST, DB_HOST) | enable: "Enabling writes docker-compose-admin.yml ...", "The php container gets the site's redis and db hosts" |
| nginx and php containers get read-only mounts of `/opt/easyengine/admin-tools` and the EE DB | enable: "Enabling mounts the tools and the EE database read-only into nginx and php" |
| compose `config_files` label includes the admin file | same outline |
| disable drops the mounts from nginx and php | disable: "Disable admin-tools after enabling them" |
| `init_global_admin_tools_auth()` reuses existing admin-tools auth | enable: "Enable admin-tools on a php site"; auth: "Enabling keeps an existing admin-tools auth" (needs a `default_admin_tools` auth, asserted as a precondition) |
| `init_global_admin_tools_auth()` creates auth `easyengine`, htpasswd file and whitelist (the frontend subnet, `10.1.0.0/16` unless the host routes it) | auth: "Enabling creates the admin-tools auth and whitelist when none exist", "Re-enabling with --force restores the missing admin-tools auth" |
| global `default` auth used instead of admin-tools auth | auth: "A global auth protects /ee-admin/ when there is no admin-tools auth" |
| `ee auth create global` interplay | auth: "Creating a global auth keeps the admin-tools auth" (`@known-bug` AUTH-8) |
| init runs before site validation | auth: "A failed enable on an unknown site leaves the global auth untouched" (`@known-bug` ATC-3) |
| install: tools dir created, "Installing admin-tools..." | install: "A fresh install downloads and configures every tool" (`@network`) |
| install_index (DB path, scan dir) | enable: "The shared tools are installed ..."; install: fresh install, "A missing index is regenerated from the template" |
| install_phpinfo | install: "Only a missing tool is reinstalled" |
| install_pma (download, config.inc.php with 32-char blowfish secret, DB_HOST from env) | enable: "The shared tools are installed ..."; install: fresh install |
| install_pra (GitHub latest release, predis vendor, config.inc.php) | enable: "The shared tools are installed ..."; install: fresh install |
| install_opcache | install: fresh install |
| install skips installed tools (idempotent) | install: "Enabling with everything installed installs nothing", "Only a missing tool is reinstalled" |
| ee-tools.json empty / invalid JSON / no data / unknown tool | install: "An invalid ee-tools.json aborts before the site is changed" |
| ee-tools.json with a non-json extension | not covered: `ADMIN_TOOLS_FILE` is a constant defined before the command loads |
| pra fallback when the GitHub API fails, fixed `version` | not covered: needs a network failure or a code change |
| HTTP: `/ee-admin/`, `index.php`, `phpinfo.php`, `opcache-gui.php`, `pma/`, `nginx_status`, `status`, `ping` with credentials | http: "Each tool is served with the admin-tools credentials" |
| HTTP: `pra/` (listed and served only with fullpage cache) | http: "The index lists phpRedisAdmin on a site with cache and it is served", "The index lists the tools but not phpRedisAdmin on a site without cache" |
| HTTP: 401 without / with wrong credentials, realm "Admin Tools" | http: "Each tool requires the admin-tools credentials from outside the whitelist" |
| HTTP: whitelisted client (127.0.0.1) without credentials | http: "Whitelisted clients reach the tools without credentials"; auth: first scenario |
| HTTP: `/ee-admin/` protected and 404 before enabling; status pages available without enabling | http: "/ee-admin/ is protected even before admin-tools are enabled", "Status pages are served by the site config ..." |
| HTTP: tools 404 after disable | disable: "Tools are no longer served once disabled, status pages still are" |
| HTTP: HTTPS (self-signed) + HTTP redirect | http: "Tools are protected and served over HTTPS" |
| HTTP: site auth does not unlock `/ee-admin/`, admin creds do not unlock the site | http: "Site http auth does not grant access to /ee-admin/" |
| HTTP: tools bypass the redis page cache | http: "Tools on a site with cache bypass the redis page cache" (two phpinfo responses must differ, since phpinfo prints `REQUEST_TIME_FLOAT`) |
| HTTP: index on an alias domain | http: "The index on an alias domain lists the same tools ..." (ATC-5, fixed on `fix/escape-index-sql`) |
| HTTP: crafted Host under a wildcard alias (SQL injection) | http: "A crafted Host header under a wildcard alias does not change the index lookup" (ATC-5, fixed on `fix/escape-index-sql`) |
| HTTP: no global htpasswd at all exposes the tools | auth: "Without any global auth file the tools are served to anyone" (documents behaviour) |
| `ee site info` shows / hides "Access admin-tools" | enable: "Site info shows the admin-tools URL once enabled", "Site info does not show admin-tools when disabled" |
| `ee site disable` + `enable` re-enables admin-tools (class-ee-site `enable()`) | lifecycle: "Disabling and enabling the site re-enables admin-tools", "Enabling a site without admin-tools does not enable them" |
| `ee site refresh` / `restart` keep the mounts | lifecycle: "Refreshing the site keeps admin-tools", "Restarting the site keeps admin-tools" |
| `ee site update --add-alias-domains` / `--ssl=self` keep the mounts | lifecycle: "Updating the site keeps admin-tools" (`@known-bug` ATC-1) |
| `ee site update --delete-alias-domains` / `--ssl=off` | not covered separately: same `docker_compose_up(…, ['nginx'])` path as the rows above (ATC-1) |
| `ee site update --php=<ver>` keeps admin-tools (runs `enable --force`, which re-enables them) | not covered: pulls another php image (~200 MB) |
| site delete keeps the shared tools and auth | lifecycle: "Deleting a site with admin-tools keeps the shared tools for other sites" |
| disable on a site without php | cli: "Force-disabling on an html site fails instead of reporting success" (`@known-bug` ATC-4) |
| enable failure path (`docker-compose up` fails) | not covered: needs a broken compose setup |
| `ee site clone` of a site with admin-tools | not covered: belongs to site-command; the cloner runs the installed `ee` binary, not `bin/ee` |
| mailhog link in the index | http: "The index lists MailHog only while it is enabled on the site" |
| pma login against the site DB | not covered: cookie auth needs a token/session flow |
| Container migrations (update index, config with env, public dir index, pma config) | not covered: migrations only run on upgrades; they need an old-version fixture |

Estimated coverage: about 88% of the inventory (57 of 65 items); the gaps are listed above with reasons.

## Tags

- `@destructive` (14 scenario rows): change server-wide state. `admin-tools-auth.feature` snapshots the global auth rows (`auth_users` for `default`/`default_admin_tools`, `auth_ips` for `default`) and every `htpasswd/*` and `vhost.d/*_acl` file (site files embed the global users), and restores them after each scenario, then regenerates and reloads nginx-proxy. While a scenario runs, other sites on the server may be behind a global auth, lose the global whitelist, or (with no global htpasswd at all) serve `/ee-admin/` without auth on the public IP. `admin-tools-install.feature` moves `/opt/easyengine/admin-tools` (or one file of it) aside, and overwrites the checkout's `ee-tools.json`, putting them back afterwards.
- Failure safety: the auth snapshot is also written to `/var/tmp/behat-admin-tools-command-auth-snapshot.json` (0600, it holds the plain-text auth passwords) before anything is changed, and deleted only after a complete restore. The DB rows, the files and the proxy reload are restored independently, so one failure does not skip the others; a failed restore keeps the file, and the next scenario's snapshot step or the next run's `@BeforeSuite` restores it first (a run killed mid-scenario is recovered the same way). Moved-aside paths use a fixed `<path>.behat-aside` name: a leftover from an interrupted run makes the step fail instead of being buried, and the restore never deletes the new path unless the backup exists. Site creation in the Background runs before the snapshot, so a global admin-tools auth that `ee site create` adds on a server without one is kept.
- `@network` (1): downloads phpMyAdmin, phpRedisAdmin, predis and opcache-gui.
- `@known-bug` (6 scenario rows): ATC-1 (2 rows), ATC-2, ATC-3, ATC-4 and AUTH-8 (all in issues.md). They assert the correct behaviour, so they fail until fixed. The two ATC-5 scenarios are no longer tagged (fixed on `fix/escape-index-sql`, not merged yet), so they fail against a develop without the fix. Exclude them with `--tags='~@known-bug'`.
- No `@le` or `@remote` scenarios: nothing here needs public DNS or backups.

## Product bugs

The suspected bugs found while writing this suite were verified against `upstream/develop` and recorded in `/root/workspace/issues.md` (section "admin-tools-command"): ATC-1 to ATC-4 have `@known-bug` scenarios, ATC-5 has regular ones since its fix; ATC-6 to ATC-9 (leftover `docker-compose-admin.yml`, the constructor `define()`, the `UpdateConfigWithEnv` skip flag, `re_enable_admin_tools` ignoring custom compose files) are not tested.

## Sites and runtime

Sites are `bh-admin-tools-command-<n>.test` (n = 1–21, plus the alias `bh-admin-tools-command-13-alias.test`, `bh-admin-tools-command-17-alias.test` and the wildcard alias `*.bh-admin-tools-command-21-wild.test`). A site is created on first use in a feature (`Given the site … exists, created with …`) and shared by the feature's scenarios; `@AfterFeature` deletes only sites with the `bh-admin-tools-command-` prefix. `admin-tools are enabled/disabled on …` converge the state (DB flag and container mounts) so scenarios stay independent.

Estimated runtime: about 20–25 minutes for the whole suite (19 php sites and 1 WordPress site at ~20–40 s each to create and delete, ~40 enable/disable runs at ~10 s, HTTP polling), plus 1–2 minutes for the `@network` fresh install.

## Changes to the copied site-command context

- `@AfterFeature` deletes only this suite's sites instead of every site on the server.
- Anchored the `I run '…'` and `STDOUT/STDERR …` regex steps (they matched any step containing a double-quoted string); `… of command :index …` used a literal `:index` in a regex and now takes `(\d+)`.
- Fixed the inverted comparison in `There should be :n containers with labels`.
- Guarded the `EE_ROOT_DIR` define (core already defines it, which warned on every run).
- New generic steps are grouped under "Shared steps"; admin-tools specific ones under their own section.
- `the global admin-tools auth should exist` has the same meaning as the step of that name in the mailhog-command suite (a `default_admin_tools` or `default` auth row plus its htpasswd file).
