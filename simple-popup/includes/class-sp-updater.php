<?php
/**
 * GitHub updater for Simple Popup.
 *
 * Looks at the latest GitHub Release of the plugin's repository. When its version is newer than the
 * installed one, WordPress shows the normal "Update available" notice, and "Update now" / auto-updates
 * download the release zip straight from GitHub and install it.
 *
 * Releasing a new version on GitHub:
 *   1. Bump "Version:" in simple-popup.php (e.g. 3.5.1).
 *   2. Create a Release with tag v3.5.1 (or 3.5.1).
 *   3. Attach simple-popup.zip (containing the simple-popup/ folder) as a release asset.
 *   The release notes become the changelog in "View details".
 *
 * Security:
 *  - Talks only to api.github.com over https, only for the configured repository.
 *  - Only a release asset named simple-popup.zip from that repository is accepted.
 *  - The downloaded zip is checked against GitHub's SHA-256 digest of the asset before install.
 *  - Private repositories: define SP_GITHUB_TOKEN (a fine-grained, read-only token for this repo only).
 *
 * @author m.n.vougiouka
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SP_Updater {

    const ASSET_NAME = 'simple-popup.zip';

    private $file;
    private $basename;   // simple-popup/simple-popup.php
    private $slug;       // simple-popup
    private $version;    // installed version
    private $repo;       // owner/name
    private $token;
    private $api;        // https://api.github.com
    private $cache_key = 'sp_update_info';

    public function __construct( $file, $repo, $token = '' ) {
        $this->file     = $file;
        $this->basename = plugin_basename( $file );
        $this->slug     = dirname( $this->basename );
        $this->repo     = trim( (string) $repo, '/' );
        $this->token    = (string) $token;
        // SP_GITHUB_API exists only so the updater can be tested against a local copy of the API.
        $this->api      = defined( 'SP_GITHUB_API' ) ? untrailingslashit( SP_GITHUB_API ) : 'https://api.github.com';

        $data          = get_file_data( $file, array( 'Version' => 'Version' ) );
        $this->version = $data['Version'];

        if ( ! preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $this->repo ) ) {
            return; // not configured
        }

        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
        add_filter( 'upgrader_pre_download', array( $this, 'download' ), 10, 4 );
        add_filter( 'upgrader_source_selection', array( $this, 'fix_folder' ), 10, 4 );
        add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 0 );
        add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
        add_action( 'admin_init', array( $this, 'maybe_force_check' ) );
    }

    /* ---------------------------------------------------------------
     * GitHub requests
     * ------------------------------------------------------------- */
    private function headers( $accept = 'application/vnd.github+json' ) {
        $h = array(
            'Accept'               => $accept,
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent'           => 'SimplePopup-Updater/' . $this->version . '; ' . home_url( '/' ),
        );
        if ( $this->token !== '' ) {
            $h['Authorization'] = 'Bearer ' . $this->token;
        }
        return $h;
    }

    private function is_local_test() {
        return in_array( wp_parse_url( $this->api, PHP_URL_HOST ), array( '127.0.0.1', 'localhost' ), true );
    }

    private function get_info( $force = false ) {
        if ( ! $force ) {
            $cached = get_site_transient( $this->cache_key );
            if ( is_array( $cached ) ) {
                return $cached ? $cached : null;
            }
        }

        $args = array( 'timeout' => 10, 'headers' => $this->headers() );
        $url  = $this->api . '/repos/' . $this->repo . '/releases/latest';
        $res  = $this->is_local_test() ? wp_remote_get( $url, $args ) : wp_safe_remote_get( $url, $args );

        $info = null;
        if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
            $info = $this->parse_release( json_decode( wp_remote_retrieve_body( $res ), true ) );
        }

        // Cache success 12h, failure 1h (GitHub allows 60 unauthenticated requests per hour per server IP)
        set_site_transient( $this->cache_key, $info ? $info : array(), $info ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
        return $info;
    }

    private function parse_release( $r ) {
        if ( ! is_array( $r ) || ! empty( $r['draft'] ) || ! empty( $r['prerelease'] ) ) {
            return null;
        }
        $version = ltrim( (string) ( $r['tag_name'] ?? '' ), 'vV' );
        if ( ! preg_match( '/^\d+(\.\d+){0,3}$/', $version ) ) {
            return null;
        }

        // Find the simple-popup.zip asset
        $asset = null;
        foreach ( (array) ( $r['assets'] ?? array() ) as $a ) {
            if ( is_array( $a ) && ( $a['name'] ?? '' ) === self::ASSET_NAME ) {
                $asset = $a;
                break;
            }
        }
        if ( ! $asset ) {
            return null;
        }

        // Asset API URL must belong to this repository on the GitHub API
        $asset_url = (string) ( $asset['url'] ?? '' );
        $prefix    = $this->api . '/repos/' . $this->repo . '/releases/assets/';
        if ( strpos( $asset_url, $prefix ) !== 0 || ! ctype_digit( substr( $asset_url, strlen( $prefix ) ) ) ) {
            return null;
        }

        $sha = '';
        if ( isset( $asset['digest'] ) && preg_match( '/^sha256:([a-f0-9]{64})$/i', (string) $asset['digest'], $m ) ) {
            $sha = strtolower( $m[1] );
        }

        return array(
            'version'      => $version,
            'asset_url'    => $asset_url,
            'sha256'       => $sha,
            'last_updated' => sanitize_text_field( (string) ( $r['published_at'] ?? '' ) ),
            'changelog'    => (string) ( $r['body'] ?? '' ),
            'html_url'     => esc_url_raw( (string) ( $r['html_url'] ?? '' ) ),
        );
    }

    /* ---------------------------------------------------------------
     * Tell WordPress about the update
     * ------------------------------------------------------------- */
    public function check_update( $transient ) {
        if ( ! is_object( $transient ) ) {
            $transient = new stdClass();
        }
        $info = $this->get_info();
        if ( ! $info ) {
            return $transient;
        }

        $item = (object) array(
            'id'          => $this->basename,
            'slug'        => $this->slug,
            'plugin'      => $this->basename,
            'new_version' => $info['version'],
            'url'         => $info['html_url'],
            'package'     => $info['asset_url'],
            'icons'       => array(),
            'banners'     => array(),
        );

        if ( version_compare( $info['version'], $this->version, '>' ) ) {
            $transient->response[ $this->basename ] = $item;
            unset( $transient->no_update[ $this->basename ] );
        } else {
            $item->new_version = $this->version;
            $transient->no_update[ $this->basename ] = $item;
            unset( $transient->response[ $this->basename ] );
        }
        return $transient;
    }

    public function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' || empty( $args->slug ) || $args->slug !== $this->slug ) {
            return $result;
        }
        $info = $this->get_info();
        if ( ! $info ) {
            return $result;
        }
        $changelog = $info['changelog'] !== ''
            ? wpautop( esc_html( $info['changelog'] ) )
            : 'No release notes.';

        return (object) array(
            'name'          => 'Simple Popup',
            'slug'          => $this->slug,
            'version'       => $info['version'],
            'author'        => 'm.n.vougiouka',
            'homepage'      => $info['html_url'],
            'last_updated'  => $info['last_updated'],
            'download_link' => $info['asset_url'],
            'sections'      => array(
                'description' => 'Lightweight popups managed from their own "Popups" section in the admin sidebar.',
                'changelog'   => $changelog,
            ),
        );
    }

    /* ---------------------------------------------------------------
     * Download from GitHub + SHA-256 check, before WordPress installs anything
     * ------------------------------------------------------------- */
    public function download( $reply, $package, $upgrader, $hook_extra = array() ) {
        $info = $this->get_info();
        if ( ! $info || $package !== $info['asset_url'] ) {
            return $reply; // not our plugin
        }

        // Step 1: ask the GitHub API for the asset. It answers with a redirect to a signed download URL.
        $first = wp_remote_get( $info['asset_url'], array(
            'timeout'     => 30,
            'redirection' => 0,
            'headers'     => $this->headers( 'application/octet-stream' ),
        ) );
        if ( is_wp_error( $first ) ) {
            return $first;
        }
        $code = wp_remote_retrieve_response_code( $first );
        $tmp  = wp_tempnam( self::ASSET_NAME );

        if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
            $location = wp_remote_retrieve_header( $first, 'location' );
            if ( ! $location || ( ! $this->is_local_test() && wp_parse_url( $location, PHP_URL_SCHEME ) !== 'https' ) ) {
                wp_delete_file( $tmp );
                return new WP_Error( 'sp_download', 'Simple Popup update: unexpected download location from GitHub.' );
            }
            // Step 2: download the file itself (no token is sent to the storage server)
            $get  = $this->is_local_test() ? 'wp_remote_get' : 'wp_safe_remote_get';
            $file = $get( $location, array( 'timeout' => 300, 'stream' => true, 'filename' => $tmp ) );
        } elseif ( $code === 200 ) {
            file_put_contents( $tmp, wp_remote_retrieve_body( $first ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
            $file = $first;
        } else {
            wp_delete_file( $tmp );
            return new WP_Error( 'sp_download', 'Simple Popup update: GitHub returned HTTP ' . (int) $code . '.' );
        }

        if ( is_wp_error( $file ) || wp_remote_retrieve_response_code( $file ) !== 200 || ! filesize( $tmp ) ) {
            wp_delete_file( $tmp );
            return is_wp_error( $file ) ? $file : new WP_Error( 'sp_download', 'Simple Popup update: download failed.' );
        }

        // Step 3: fingerprint check against GitHub's own digest of the asset
        if ( $info['sha256'] !== '' && ! hash_equals( $info['sha256'], hash_file( 'sha256', $tmp ) ) ) {
            wp_delete_file( $tmp );
            $this->clear_cache();
            return new WP_Error( 'sp_bad_checksum', 'Simple Popup update refused: the downloaded file does not match GitHub\'s fingerprint.' );
        }
        return $tmp;
    }

    // Make sure the unzipped folder is called simple-popup/ whatever the zip used
    public function fix_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
        global $wp_filesystem;
        if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
            return $source;
        }
        $wanted = trailingslashit( $remote_source ) . $this->slug . '/';
        if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) ) {
            return $source;
        }
        if ( $wp_filesystem && $wp_filesystem->move( $source, $wanted, true ) ) {
            return $wanted;
        }
        return new WP_Error( 'sp_folder', 'Simple Popup update: could not prepare the plugin folder.' );
    }

    public function clear_cache() {
        delete_site_transient( $this->cache_key );
    }

    public function maybe_force_check() {
        if ( ! current_user_can( 'update_plugins' ) ) {
            return;
        }
        if ( isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- core's own param, read-only
            $this->clear_cache();
        }
        if ( isset( $_GET['sp-check-update'], $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'sp-check-update' ) ) {
            $this->clear_cache();
            delete_site_transient( 'update_plugins' );
            wp_update_plugins();
            wp_safe_redirect( admin_url( 'plugins.php' ) );
            exit;
        }
    }

    public function row_meta( $links, $file ) {
        if ( $file === $this->basename && current_user_can( 'update_plugins' ) ) {
            $url     = wp_nonce_url( admin_url( 'plugins.php?sp-check-update=1' ), 'sp-check-update' );
            $links[] = '<a href="' . esc_url( $url ) . '">Check for updates</a>';
        }
        return $links;
    }
}
