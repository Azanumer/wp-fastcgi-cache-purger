<?php
/**
 * Plugin Name:       WP FastCGI Cache Purger
 * Plugin URI:        https://github.com/Azanumer/wp-fastcgi-cache-purger
 * Description:       Purges Nginx FastCGI cache on content changes, from the admin bar, or via WP-CLI. Zero dependencies.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Azan Umer
 * License:           MIT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FCGI_PURGER_VERSION', '1.0.0' );

/* -------------------------------------------------------------------------
 * Core purger
 * ---------------------------------------------------------------------- */

/**
 * Recursively delete cache files inside $cache_path, optionally restricted
 * to a single cache key hash.
 *
 * @param string $cache_path Absolute FastCGI cache path (must match nginx fastcgi_cache_path).
 * @param string $hash       Optional md5 hash substring to purge only.
 * @return int  Number of files removed.
 */
function fcgi_purge_cache_files( $cache_path, $hash = '' ) {
	$count = 0;

	$cache_path = rtrim( $cache_path, '/\\' );
	if ( ! is_dir( $cache_path ) ) {
		return 0;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $cache_path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $iterator as $file ) {
		if ( $file->isDir() ) {
			continue;
		}
		if ( $hash !== '' && stripos( $file->getFilename(), $hash ) === false ) {
			continue;
		}
		if ( @unlink( $file->getPathname() ) ) {
			$count++;
		}
	}

	return $count;
}

/**
 * Compute the nginx FastCGI cache key hash for a URL the same way nginx does
 * when fastcgi_cache_key is "$scheme$request_method$host$request_uri".
 *
 * @param string $url Full URL.
 * @return string md5 hash of the cache key (lowercase).
 */
function fcgi_cache_key_hash( $url ) {
	$parts  = wp_parse_url( $url );
	$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'http';
	$host   = isset( $parts['host'] ) ? $parts['host'] : '';
	$uri    = isset( $parts['path'] ) ? $parts['path'] : '/';
	if ( isset( $parts['query'] ) ) {
		$uri .= '?' . $parts['query'];
	}
	return md5( $scheme . 'GET' . $host . $uri );
}

/**
 * Purge everything.
 *
 * @return int Files removed.
 */
function fcgi_purge_all() {
	$path = get_option( 'fcgi_purger_cache_path', '' );
	if ( $path === '' ) {
		return 0;
	}
	return fcgi_purge_cache_files( $path );
}

/**
 * Purge cache for one URL.
 *
 * @param string $url Full URL.
 * @return int Files removed.
 */
function fcgi_purge_url( $url ) {
	$path = get_option( 'fcgi_purger_cache_path', '' );
	if ( $path === '' ) {
		return 0;
	}
	return fcgi_purge_cache_files( $path, fcgi_cache_key_hash( $url ) );
}

/* -------------------------------------------------------------------------
 * Automatic purging on content changes
 * ---------------------------------------------------------------------- */

function fcgi_purger_settings() {
	return array(
		'path'       => get_option( 'fcgi_purger_cache_path', '' ),
		'post_types' => get_option( 'fcgi_purger_post_types', array( 'post', 'page' ) ),
	);
}

/**
 * Purge on post publish/update/delete.
 */
function fcgi_purge_on_post_change( $post_id ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	$settings = fcgi_purger_settings();
	if ( $settings['path'] === '' ) {
		return;
	}
	$post = get_post( $post_id );
	if ( ! $post || ! in_array( $post->post_type, (array) $settings['post_types'], true ) ) {
		return;
	}

	// Purge the post itself plus the homepage and feeds (they embed the post).
	$urls = array( get_permalink( $post_id ), home_url( '/' ) );
	foreach ( $urls as $url ) {
		if ( $url ) {
			fcgi_purge_url( $url );
		}
	}
}
add_action( 'save_post', 'fcgi_purge_on_post_change' );
add_action( 'deleted_post', 'fcgi_purge_on_post_change' );
add_action( 'trashed_post', 'fcgi_purge_on_post_change' );

// Comments change what visitors see -> purge that post.
function fcgi_purge_on_comment( $comment_id ) {
	$comment = get_comment( $comment_id );
	if ( $comment ) {
		fcgi_purge_on_post_change( $comment->comment_post_ID );
	}
}
add_action( 'comment_post', 'fcgi_purge_on_comment' );
add_action( 'edit_comment', 'fcgi_purge_on_comment' );

// Theme/widget/menu changes can affect every page -> full purge.
add_action( 'switch_theme', 'fcgi_purge_all' );
add_action( 'update_option_sidebars_widgets', 'fcgi_purge_all' );
add_action( 'wp_update_nav_menu', 'fcgi_purge_all' );

/* -------------------------------------------------------------------------
 * Admin bar "Purge Cache" button + AJAX handler
 * ---------------------------------------------------------------------- */

function fcgi_purger_admin_bar( $wp_admin_bar ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$wp_admin_bar->add_node(
		array(
			'id'    => 'fcgi-purge',
			'title' => 'Purge Cache',
			'href'  => wp_nonce_url( admin_url( 'admin-ajax.php?action=fcgi_purge_all' ), 'fcgi_purge_all' ),
		)
	);
}
add_action( 'admin_bar_menu', 'fcgi_purger_admin_bar', 100 );

function fcgi_purge_all_ajax() {
	check_ajax_referer( 'fcgi_purge_all' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Forbidden', 403 );
	}
	$count = fcgi_purge_all();
	wp_send_json_success( array( 'purged_files' => $count ) );
}
add_action( 'wp_ajax_fcgi_purge_all', 'fcgi_purge_all_ajax' );

/* -------------------------------------------------------------------------
 * Settings page
 * ---------------------------------------------------------------------- */

function fcgi_purger_menu() {
	add_options_page(
		'FastCGI Cache Purger',
		'FastCGI Purger',
		'manage_options',
		'fcgi-purger',
		'fcgi_purger_settings_page'
	);
}
add_action( 'admin_menu', 'fcgi_purger_menu' );

function fcgi_purger_register_settings() {
	register_setting( 'fcgi_purger', 'fcgi_purger_cache_path', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	register_setting(
		'fcgi_purger',
		'fcgi_purger_post_types',
		array(
			'sanitize_callback' => function ( $v ) {
				return is_array( $v ) ? array_map( 'sanitize_key', $v ) : array();
			},
		)
	);
}
add_action( 'admin_init', 'fcgi_purger_register_settings' );

function fcgi_purger_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$path       = get_option( 'fcgi_purger_cache_path', '' );
	$post_types = (array) get_option( 'fcgi_purger_post_types', array( 'post', 'page' ) );
	$writable   = ( $path !== '' && is_dir( $path ) && is_writable( $path ) );
	?>
	<div class="wrap">
		<h1>FastCGI Cache Purger</h1>
		<?php if ( $path === '' ) : ?>
			<div class="notice notice-warning"><p>Enter your Nginx <code>fastcgi_cache_path</code> below to enable purging.</p></div>
		<?php elseif ( ! $writable ) : ?>
			<div class="notice notice-error"><p>The cache path is not a writable directory by PHP. Check the path and permissions.</p></div>
		<?php else : ?>
			<div class="notice notice-success"><p>Cache path is writable. Purging is active.</p></div>
		<?php endif; ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'fcgi_purger' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="fcgi_purger_cache_path">FastCGI cache path</label></th>
					<td>
						<input type="text" id="fcgi_purger_cache_path" name="fcgi_purger_cache_path"
							value="<?php echo esc_attr( $path ); ?>" class="regular-text"
							placeholder="/var/cache/nginx/fastcgi">
						<p class="description">Must match <code>fastcgi_cache_path</code> in your nginx config. The web user needs write access.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Auto-purge post types</th>
					<td>
						<?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) : ?>
							<label>
								<input type="checkbox" name="fcgi_purger_post_types[]" value="<?php echo esc_attr( $pt->name ); ?>"
									<?php checked( in_array( $pt->name, $post_types, true ) ); ?>>
								<?php echo esc_html( $pt->label ); ?>
							</label><br>
						<?php endforeach; ?>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<p>See <code>nginx-config-snippet.conf</code> in the plugin folder for the matching nginx configuration.</p>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * WP-CLI commands: wp fcgipurge all / wp fcgipurge url <url>
 * ---------------------------------------------------------------------- */

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command(
		'fcgipurge',
		function ( $args, $assoc ) {
			if ( ( $args[0] ?? '' ) === 'url' && isset( $args[1] ) ) {
				$count = fcgi_purge_url( $args[1] );
				WP_CLI::success( "Purged {$count} cache file(s) for URL." );
			} else {
				$count = fcgi_purge_all();
				WP_CLI::success( "Purged {$count} cache file(s)." );
			}
		}
	);
}
