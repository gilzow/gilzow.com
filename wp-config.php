<?php

use Platformsh\ConfigReader\Config;

require __DIR__.'/vendor/autoload.php';

// Create a new config object to ease reading the Platform.sh environment variables.
// You can alternatively use getenv() yourself.
$config = new Config();

// Default PHP settings.
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 100);
ini_set('session.gc_maxlifetime', 200000);
ini_set('session.cookie_lifetime', 2000000);
ini_set('pcre.backtrack_limit', 200000);
ini_set('pcre.recursion_limit', 200000);

// Set default scheme and hostname.
$site_scheme = 'http';
$site_host = 'localhost';

// Update scheme and hostname for the requested page.
if (isset($_SERVER['HTTP_HOST'])) {
    $site_host = $_SERVER['HTTP_HOST'];
    $site_scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
}

if ($config->isValidPlatform()) {
    if ($config->hasRelationship('database')) {
        // This is where we get the relationships of our application dynamically
        // from Platform.sh.

        // Avoid PHP notices on CLI requests.
        if (php_sapi_name() === 'cli') {
            session_save_path("/tmp");
        }

        // Get the database credentials
        $credentials = $config->credentials('database');

        // We are using the first relationship called "database" found in your
        // relationships. Note that you can call this relationship as you wish
        // in your `.platform.app.yaml` file, but 'database' is a good name.
        define( 'DB_NAME', $credentials['path']);
        define( 'DB_USER', $credentials['username']);
        define( 'DB_PASSWORD', $credentials['password']);
        define( 'DB_HOST', $credentials['host']);
        define( 'DB_CHARSET', 'utf8' );
        define( 'DB_COLLATE', '' );

        // Check whether a route is defined for this application in the Platform.sh
        // routes. Use it as the site hostname if so (it is not ideal to trust HTTP_HOST).
        if ($config->routes()) {

            $routes = $config->routes();
            $appName = $config->applicationName;
            //get all the valid routes that map to this app
            $aryUpstreamRoutes = array_filter($config->routes(), function ($route) use ($appName) {
                return isset($route['upstream']) && $appName === $route['upstream'];
            });

            //now get a list of all the domains generated from the routes
            $aryValidDomains = array_map(function ($url) {
                return parse_url($url,PHP_URL_HOST);
            }, array_keys($aryUpstreamRoutes));


            //we're on platform.sh, so we _should_ have a site_host, and it _Should_ be one in routes
            if (!in_array($site_host, $aryValidDomains, true)) {
                //since site_host isn't one of our valid domains, let's set it to the primary route's domain
                $site_host = parse_url(array_key_first(array_filter($aryUpstreamRoutes, static function ($route) {
                    return $route['primary'];
                })),PHP_URL_HOST);

                $site_scheme = 'https'; //@todo can/should we assume we're always https?
            }

            //we're on a multisite, so we need to make sure we set DOMAIN_CURRENT_SITE to the *default* domain
            if (filter_var(getenv('MULTISITE'),FILTER_VALIDATE_BOOLEAN)) {
                //if we're in a multisite, then we only want the primary
                $primaryRoute = array_key_first(array_filter($aryUpstreamRoutes, function ($route) {
                    return $route['primary'];
                }));
                $domainCurrentSite = parse_url($primaryRoute,PHP_URL_HOST);
            }
        }

        // Debug mode should be disabled on Platform.sh. Set this constant to true
        // in a wp-config-local.php file to skip this setting on local development.
        if (!defined( 'WP_DEBUG' )) {
            define( 'WP_DEBUG', false );
        }

        // Set all of the necessary keys to unique values, based on the Platform.sh
        // entropy value.
        if ($config->projectEntropy) {
            $keys = [
                'AUTH_KEY',
                'SECURE_AUTH_KEY',
                'LOGGED_IN_KEY',
                'NONCE_KEY',
                'AUTH_SALT',
                'SECURE_AUTH_SALT',
                'LOGGED_IN_SALT',
                'NONCE_SALT',
            ];
            $entropy = $config->projectEntropy;
            foreach ($keys as $key) {
                if (!defined($key)) {
                    define( $key, $entropy . $key );
                }
            }
        }
    }
}
else {
    // Local configuration file should be in project root.
    if (file_exists(dirname(__FILE__, 2) . '/wp-config-local.php')) {
        include(dirname(__FILE__, 2) . '/wp-config-local.php');
    }
}

// Do not put a slash "/" at the end.
// https://codex.wordpress.org/Editing_wp-config.php#WP_HOME
define( 'WP_HOME', $site_scheme . '://' . $site_host );
// Do not put a slash "/" at the end.
// https://codex.wordpress.org/Editing_wp-config.php#WP_SITEURL
define('WP_SITEURL', WP_HOME . '/wp');

define( 'WP_CONTENT_URL', WP_HOME . '/wp-content' );
define( 'WP_CONTENT_DIR', dirname( __FILE__ ) . '/web/wp-content' );
// Disable WordPress from running automatic updates
define( 'WP_AUTO_UPDATE_CORE', false );

// Since you can have multiple installations in one database, you need a unique
// prefix.
$table_prefix  = 'wp_';

/**
 * Multisite support
 */
if(
    filter_var(getenv('MULTISITE'),FILTER_VALIDATE_BOOLEAN)
    && filter_var(getenv('MULTISITEINSTALLED'),FILTER_VALIDATE_BOOLEAN)
) {
    define('WP_ALLOW_MULTISITE', true); //enables the Network setup panel in Tools
    define('MULTISITE', true); //instructs WordPress to run in multisite mode
    #getenv will return false if it isn't set.
    define('SUBDOMAIN_INSTALL', filter_var(getenv('SUBDOMAIN_INSTALL'),FILTER_VALIDATE_BOOLEAN)); // does the instance contain subdirectory sites (false) or subdomain/multiple domain sites (true)
    define('DOMAIN_CURRENT_SITE', $domainCurrentSite); //the current domain being requested
    define('PATH_CURRENT_SITE', '/'); //path to the WordPress site if it isn't the root of the site (e.g. https://foo.com/blog/)
    define('SITE_ID_CURRENT_SITE', 1); //main/primary site ID
    define('BLOG_ID_CURRENT_SITE', 1); //main/primary/parent blog ID

    /**
     * we have a sub/multidomain multisite, and the site currently being requested is not the default domain, so we'll
     * need to set COOKIE_DOMAIN to the domain being requested
     */
    if (SUBDOMAIN_INSTALL && $site_host !== $domainCurrentSite) {
        define('COOKIE_DOMAIN',$site_host);
    }
}

define('DISALLOW_FILE_MODS',false);

if ($config->hasRelationship('redis') && extension_loaded('redis')) {
    $credentials = $config->credentials('redis');

    define('WP_REDIS_CLIENT', 'phpredis');
    define('WP_REDIS_HOST', $credentials['host']);
    define('WP_REDIS_PORT', $credentials['port']);
}

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __FILE__ ) . '/' );
}

/** Sets up WordPress vars and included files. */
//require_once(ABSPATH . 'wp-settings.php');
