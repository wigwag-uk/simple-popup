<?php
/**
 * Plugin Name: Simple Popup
 * Description: Lightweight popups managed from their own "Popups" section in the admin sidebar.
 * Version:     3.5.2
 * Author:      m.n.vougiouka
 * Text Domain: simple-popup
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SP_CPT', 'sp_popup' );
define( 'SP_CAP', 'manage_options' ); // capability needed to manage popups
define( 'SP_FILE', __FILE__ );

/*
 * UPDATES from GitHub: new Releases of this repository show as "Update available" in WordPress.
 * Private repository? Add a read-only token in wp-config.php:  define( 'SP_GITHUB_TOKEN', 'github_pat_...' );
 */
if ( ! defined( 'SP_GITHUB_REPO' ) ) {
    define( 'SP_GITHUB_REPO', 'wigwag-uk/simple-popup' );
}
require_once __DIR__ . '/includes/class-sp-updater.php';
new SP_Updater( SP_FILE, SP_GITHUB_REPO, defined( 'SP_GITHUB_TOKEN' ) ? SP_GITHUB_TOKEN : '' );

/* ==================================================================
 * 1. ADMIN SECTION: "Popups" custom post type
 * ================================================================== */
add_action( 'init', 'sp_register_cpt' );

function sp_register_cpt() {
    register_post_type( SP_CPT, array(
        'labels' => array(
            'name'               => 'Popups',
            'singular_name'      => 'Popup',
            'menu_name'          => 'Popups',
            'all_items'          => 'All Popups',
            'add_new'            => 'Add New',
            'add_new_item'       => 'Add New Popup',
            'edit_item'          => 'Edit Popup',
            'new_item'           => 'New Popup',
            'search_items'       => 'Search Popups',
            'not_found'          => 'No popups found',
            'not_found_in_trash' => 'No popups found in Trash',
        ),
        'public'              => false,
        // Only administrators (manage_options) can see, create, edit, publish or delete popups.
        'capability_type'     => 'post',
        'map_meta_cap'        => true,
        'capabilities'        => array(
            'create_posts'           => SP_CAP,
            'edit_posts'             => SP_CAP,
            'edit_others_posts'      => SP_CAP,
            'edit_private_posts'     => SP_CAP,
            'edit_published_posts'   => SP_CAP,
            'publish_posts'          => SP_CAP,
            'read_private_posts'     => SP_CAP,
            'delete_posts'           => SP_CAP,
            'delete_others_posts'    => SP_CAP,
            'delete_private_posts'   => SP_CAP,
            'delete_published_posts' => SP_CAP,
            'read'                   => SP_CAP,
        ),
        'show_ui'             => true,
        'show_in_menu'        => true,
        'show_in_rest'        => true,   // block editor
        'menu_position'       => 26,     // just below Comments
        'menu_icon'           => 'dashicons-format-gallery',
        'supports'            => array( 'title', 'editor' ),
        'exclude_from_search' => true,
        'publicly_queryable'  => false,
        'has_archive'         => false,
        'rewrite'             => false,
    ) );
}

/* ------------------------------------------------------------------
 * Default settings for a popup
 * ------------------------------------------------------------------ */
function sp_defaults() {
    return array(
        'blank'       => '0',
        'show_title'  => '1',
        'title_tag'   => 'h2',
        'subtitle'    => '',
        'button_text' => '',
        'button_url'  => '',
        'trigger'     => 'delay',
        'delay'       => '3',
        'cookie_days' => '7',
        'show_on'     => 'all',   // all | front | pages
        'pages'       => '',      // comma separated IDs / slugs
        'exclude'     => '',
        'hide_mobile' => '0',
        'width'       => '600px',
        'max_width'   => '90%',
        'max_height'  => '90vh',
        'priority'    => '10',
        'radius'      => '0',
        'border_w'    => '0',
        'border_c'    => '#dddddd',
        'popup_bg'    => '',
        'header_bg'   => '',
        'header_c'    => '',
        'content_bg'  => '',
        'content_c'   => '',
        'header_layout' => 'standard', // standard | grid
        'header_extra'  => '',
        'scroll_content'=> '0',
        'pad'         => '20px 20px 20px 20px',
        'pad_m'       => '20px 12px 20px 12px',
    );
}

// Settings are validated again every time they are read, so tampered database values can't reach the page.
function sp_get_settings( $post_id ) {
    $saved = get_post_meta( $post_id, '_sp_settings', true );
    return sp_sanitize_settings( is_array( $saved ) ? $saved : array() );
}

// Whitelist + validate every setting. Anything unknown is dropped, anything invalid falls back to the default.
function sp_sanitize_settings( $in ) {
    $def = sp_defaults();
    $in  = is_array( $in ) ? $in : array();

    $str = function ( $key, $max = 200 ) use ( $in ) {
        $v = isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ? sanitize_text_field( (string) $in[ $key ] ) : '';
        return mb_substr( $v, 0, $max );
    };
    $int = function ( $key, $min, $max ) use ( $in, $def ) {
        $v = isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) && is_numeric( $in[ $key ] ) ? (int) $in[ $key ] : (int) $def[ $key ];
        return (string) max( $min, min( $max, $v ) );
    };
    $bool = function ( $key ) use ( $in, $def ) {
        if ( ! array_key_exists( $key, $in ) ) {
            return $def[ $key ];
        }
        return ! empty( $in[ $key ] ) && $in[ $key ] !== '0' ? '1' : '0';
    };
    $pick = function ( $key, $allowed ) use ( $in, $def ) {
        $v = isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ? (string) $in[ $key ] : '';
        return in_array( $v, $allowed, true ) ? $v : $def[ $key ];
    };
    $hex = function ( $key, $fallback = '' ) use ( $in ) {
        $v = isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ? sanitize_hex_color( (string) $in[ $key ] ) : '';
        return $v ? $v : $fallback;
    };
    // Page lists: only IDs, slugs and commas
    $list = function ( $key ) use ( $in ) {
        $v = isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ? strtolower( (string) $in[ $key ] ) : '';
        $parts = array_filter( array_map( 'sanitize_title', explode( ',', $v ) ), 'strlen' );
        return mb_substr( implode( ', ', array_slice( $parts, 0, 100 ) ), 0, 2000 );
    };
    $url = isset( $in['button_url'] ) && is_scalar( $in['button_url'] )
        ? esc_url_raw( (string) $in['button_url'], array( 'http', 'https', 'mailto', 'tel' ) )
        : '';
    // Allow site-relative links like /contact/
    if ( $url === '' && isset( $in['button_url'] ) && is_scalar( $in['button_url'] ) && preg_match( '#^/[^/\\]#', (string) $in['button_url'] ) ) {
        $url = esc_url_raw( home_url( (string) $in['button_url'] ) );
    }

    return array(
        'blank'       => $bool( 'blank' ),
        'show_title'  => $bool( 'show_title' ),
        'title_tag'   => $pick( 'title_tag', array_keys( sp_title_tags() ) ),
        'subtitle'    => $str( 'subtitle', 300 ),
        'button_text' => $str( 'button_text', 100 ),
        'button_url'  => $url,
        'trigger'     => $pick( 'trigger', array( 'delay', 'exit', 'click' ) ),
        'delay'       => $int( 'delay', 0, 3600 ),
        'cookie_days' => $int( 'cookie_days', 0, 3650 ),
        'show_on'     => $pick( 'show_on', array( 'all', 'front', 'pages' ) ),
        'pages'       => $list( 'pages' ),
        'exclude'     => $list( 'exclude' ),
        'hide_mobile' => $bool( 'hide_mobile' ),
        'width'       => sp_size( $in['width'] ?? '', $def['width'] ),
        'max_width'   => sp_size( $in['max_width'] ?? '', $def['max_width'] ),
        'max_height'  => sp_size( $in['max_height'] ?? '', $def['max_height'] ),
        'priority'    => $int( 'priority', 0, 9999 ),
        'radius'      => $int( 'radius', 0, 500 ),
        'border_w'    => $int( 'border_w', 0, 50 ),
        'border_c'    => $hex( 'border_c', $def['border_c'] ),
        'popup_bg'    => $hex( 'popup_bg' ),
        'header_bg'   => $hex( 'header_bg' ),
        'header_c'    => $hex( 'header_c' ),
        'content_bg'  => $hex( 'content_bg' ),
        'content_c'   => $hex( 'content_c' ),
        'header_layout' => $pick( 'header_layout', array( 'standard', 'grid' ) ),
        'header_extra'  => isset( $in['header_extra'] ) && is_scalar( $in['header_extra'] ) ? mb_substr( wp_kses_post( (string) $in['header_extra'] ), 0, 5000 ) : '',
        'scroll_content'=> $bool( 'scroll_content' ),
        'pad'         => sp_padding( $in['pad'] ?? '', $def['pad'] ),
        'pad_m'       => sp_padding( $in['pad_m'] ?? '', $def['pad_m'] ),
    );
}

/* ------------------------------------------------------------------
 * Settings box on the edit screen
 * ------------------------------------------------------------------ */
add_action( 'add_meta_boxes', 'sp_add_meta_boxes' );

function sp_add_meta_boxes() {
    add_meta_box( 'sp_settings', 'Popup settings', 'sp_render_meta_box', SP_CPT, 'side', 'high' );
}

function sp_render_meta_box( $post ) {
    $s = sp_get_settings( $post->ID );
    wp_nonce_field( 'sp_save_settings', 'sp_nonce' );
    ?>
    <style>
        .sp-mb p{margin:0 0 12px}
        .sp-mb label.sp-l{display:block;font-weight:600;margin-bottom:3px}
        .sp-mb input[type=text],.sp-mb input[type=number],.sp-mb input[type=url],.sp-mb select{width:100%;max-width:100%;box-sizing:border-box}
        .sp-mb .sp-row > p{min-width:0}
        .sp-mb .sp-row{display:flex;gap:8px}
        .sp-mb .sp-row > p{flex:1}
        .sp-mb .description{font-size:12px;color:#646970;margin-top:3px;display:block}
        .sp-mb hr{margin:14px 0}
        .sp-mb code{font-size:11px}
    </style>
    <div class="sp-mb">

        <p>
            <label><input type="checkbox" name="sp[blank]" value="1" <?php checked( $s['blank'], '1' ); ?>>
            <strong>Blank canvas</strong></label>
            <span class="description">White box with no padding. Only your content and the close button. Title and button below are ignored.</span>
        </p>

        <p>
            <label><input type="checkbox" name="sp[show_title]" value="1" <?php checked( $s['show_title'], '1' ); ?>>
            Show the popup title as a heading</label>
        </p>

        <p>
            <label class="sp-l" for="sp_title_tag">Title style</label>
            <select id="sp_title_tag" name="sp[title_tag]">
                <?php foreach ( sp_title_tags() as $tag => $label ) : ?>
                    <option value="<?php echo esc_attr( $tag ); ?>" <?php selected( $s['title_tag'], $tag ); ?>><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </select>
        </p>

        <p>
            <label class="sp-l" for="sp_subtitle">Subtitle</label>
            <input type="text" id="sp_subtitle" name="sp[subtitle]" value="<?php echo esc_attr( $s['subtitle'] ); ?>" placeholder="Optional line under the title">
        </p>

        <p>
            <label class="sp-l" for="sp_header_layout">Header layout</label>
            <select id="sp_header_layout" name="sp[header_layout]">
                <option value="standard" <?php selected( $s['header_layout'], 'standard' ); ?>>Standard (centred title)</option>
                <option value="grid" <?php selected( $s['header_layout'], 'grid' ); ?>>Blank grid: title left, extra info right</option>
            </select>
            <span class="description">Blank grid uses the theme's <code>wpbf-grid</code> columns (70/30, 60/40 on small screens).</span>
        </p>

        <p>
            <label class="sp-l" for="sp_header_extra">Header extra info (HTML allowed)</label>
            <textarea id="sp_header_extra" name="sp[header_extra]" rows="4" style="width:100%;box-sizing:border-box" placeholder="&lt;strong&gt;From £49&lt;/strong&gt;&lt;br&gt;Call 01234 567890"><?php echo esc_textarea( $s['header_extra'] ); ?></textarea>
            <span class="description">Shown in the right column of the blank grid header.</span>
        </p>

        <p>
            <label><input type="checkbox" name="sp[scroll_content]" value="1" <?php checked( $s['scroll_content'], '1' ); ?>>
            <strong>Scroll only the content</strong></label>
            <span class="description">The header stays fixed at the top and only the content area scrolls.</span>
        </p>

        <div class="sp-row">
            <p>
                <label class="sp-l" for="sp_button_text">Button text</label>
                <input type="text" id="sp_button_text" name="sp[button_text]" value="<?php echo esc_attr( $s['button_text'] ); ?>">
            </p>
            <p>
                <label class="sp-l" for="sp_button_url">Button link</label>
                <input type="text" id="sp_button_url" name="sp[button_url]" value="<?php echo esc_attr( $s['button_url'] ); ?>" placeholder="/contact/">
            </p>
        </div>

        <hr>

        <p>
            <label class="sp-l" for="sp_trigger">Open when</label>
            <select id="sp_trigger" name="sp[trigger]">
                <option value="delay" <?php selected( $s['trigger'], 'delay' ); ?>>After a delay</option>
                <option value="exit" <?php selected( $s['trigger'], 'exit' ); ?>>Visitor is about to leave (exit intent)</option>
                <option value="click" <?php selected( $s['trigger'], 'click' ); ?>>A link or button is clicked</option>
            </select>
        </p>

        <div class="sp-row">
            <p>
                <label class="sp-l" for="sp_delay">Delay (sec)</label>
                <input type="number" min="0" id="sp_delay" name="sp[delay]" value="<?php echo esc_attr( $s['delay'] ); ?>">
            </p>
            <p>
                <label class="sp-l" for="sp_cookie_days">Show again after (days)</label>
                <input type="number" min="0" id="sp_cookie_days" name="sp[cookie_days]" value="<?php echo esc_attr( $s['cookie_days'] ); ?>">
            </p>
        </div>
        <span class="description" style="margin-top:-8px;margin-bottom:12px">0 days = every page load. Exit intent uses the delay on mobile.</span>

        <?php if ( $post->post_status !== 'auto-draft' ) : ?>
        <p>
            <span class="description">Click trigger link for this popup:<br>
            <code>&lt;a href="#sp-<?php echo (int) $post->ID; ?>"&gt;Open&lt;/a&gt;</code></span>
        </p>
        <?php endif; ?>

        <hr>

        <p>
            <label class="sp-l" for="sp_show_on">Show on</label>
            <select id="sp_show_on" name="sp[show_on]">
                <option value="all" <?php selected( $s['show_on'], 'all' ); ?>>Whole site</option>
                <option value="front" <?php selected( $s['show_on'], 'front' ); ?>>Homepage only</option>
                <option value="pages" <?php selected( $s['show_on'], 'pages' ); ?>>Specific pages</option>
            </select>
        </p>
        <p>
            <label class="sp-l" for="sp_pages">Specific pages</label>
            <input type="text" id="sp_pages" name="sp[pages]" value="<?php echo esc_attr( $s['pages'] ); ?>" placeholder="12, about-us, contact">
            <span class="description">Page/post IDs or slugs, comma separated. Use <code>front</code> for the homepage, <code>shop</code> for the WooCommerce shop.</span>
        </p>
        <p>
            <label class="sp-l" for="sp_exclude">Never show on</label>
            <input type="text" id="sp_exclude" name="sp[exclude]" value="<?php echo esc_attr( $s['exclude'] ); ?>" placeholder="checkout, 45">
        </p>
        <p>
            <label><input type="checkbox" name="sp[hide_mobile]" value="1" <?php checked( $s['hide_mobile'], '1' ); ?>>
            Don't open automatically on mobile</label>
        </p>

        <hr>

        <div class="sp-row">
            <p>
                <label class="sp-l" for="sp_width">Width</label>
                <input type="text" id="sp_width" name="sp[width]" value="<?php echo esc_attr( $s['width'] ); ?>" placeholder="600px">
            </p>
            <p>
                <label class="sp-l" for="sp_max_width">Max width</label>
                <input type="text" id="sp_max_width" name="sp[max_width]" value="<?php echo esc_attr( $s['max_width'] ); ?>" placeholder="90%">
            </p>
        </div>
        <p>
            <label class="sp-l" for="sp_max_height">Max height (scrolls after this)</label>
            <input type="text" id="sp_max_height" name="sp[max_height]" value="<?php echo esc_attr( $s['max_height'] ); ?>" placeholder="90vh">
            <span class="description">Use px, %, vw or vh. Under 780px screen width the popup is always 95% wide.</span>
        </p>

        <p>
            <label class="sp-l" for="sp_pad">Content padding (desktop)</label>
            <input type="text" id="sp_pad" name="sp[pad]" value="<?php echo esc_attr( $s['pad'] ); ?>" placeholder="20px 20px 20px 20px">
        </p>
        <p>
            <label class="sp-l" for="sp_pad_m">Content padding (mobile, under 780px)</label>
            <input type="text" id="sp_pad_m" name="sp[pad_m]" value="<?php echo esc_attr( $s['pad_m'] ); ?>" placeholder="20px 12px 20px 12px">
            <span class="description">Top right bottom left, like CSS. Also used for the header.</span>
        </p>

        <hr>

        <div class="sp-row">
            <p>
                <label class="sp-l" for="sp_radius">Corner radius (px)</label>
                <input type="number" min="0" id="sp_radius" name="sp[radius]" value="<?php echo esc_attr( $s['radius'] ); ?>">
            </p>
            <p>
                <label class="sp-l" for="sp_border_w">Border width (px)</label>
                <input type="number" min="0" id="sp_border_w" name="sp[border_w]" value="<?php echo esc_attr( $s['border_w'] ); ?>">
            </p>
        </div>
        <p>
            <label class="sp-l" for="sp_border_c">Border colour</label>
            <input type="text" class="sp-color" id="sp_border_c" name="sp[border_c]" value="<?php echo esc_attr( $s['border_c'] ); ?>" data-default-color="#dddddd">
            <span class="description">0 radius = square corners. 0 border width = no border.</span>
        </p>

        <hr>
        <p><strong>Colours</strong><span class="description">Leave empty (Clear) to use the default.</span></p>
        <?php
        $colour_fields = array(
            'popup_bg'   => 'Popup background',
            'header_bg'  => 'Title area background',
            'header_c'   => 'Title &amp; subtitle text',
            'content_bg' => 'Content area background',
            'content_c'  => 'Content text',
        );
        foreach ( $colour_fields as $key => $label ) :
            ?>
            <p>
                <label class="sp-l" for="sp_<?php echo esc_attr( $key ); ?>"><?php echo $label; // phpcs:ignore ?></label>
                <input type="text" class="sp-color" id="sp_<?php echo esc_attr( $key ); ?>" name="sp[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $s[ $key ] ); ?>">
            </p>
        <?php endforeach; ?>
        <span class="description">Title area colours apply to normal popups. On a blank canvas only the popup background is used.</span>

        <hr>
        <p>
            <label class="sp-l" for="sp_priority">Priority</label>
            <input type="number" min="0" id="sp_priority" name="sp[priority]" value="<?php echo esc_attr( $s['priority'] ); ?>">
            <span class="description">If several automatic popups match the same page, only the one with the lowest number opens.</span>
        </p>
        <span class="description">Only <strong>published</strong> popups show on the site. Save as draft to switch one off.</span>
        <?php if ( $post->post_status !== 'auto-draft' ) : ?>
            <p style="margin-top:12px"><a class="button" href="<?php echo esc_url( sp_duplicate_url( $post->ID ) ); ?>">Duplicate this popup</a></p>
        <?php endif; ?>
    </div>
    <?php
}

/* ------------------------------------------------------------------
 * Colour pickers on the popup edit screen
 * ------------------------------------------------------------------ */
add_action( 'admin_enqueue_scripts', 'sp_admin_assets' );

function sp_admin_assets( $hook ) {
    if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
        return;
    }
    $screen = get_current_screen();
    if ( ! $screen || $screen->post_type !== SP_CPT ) {
        return;
    }
    wp_enqueue_style( 'wp-color-picker' );
    wp_enqueue_script( 'wp-color-picker' );
    wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){ $(".sp-color").wpColorPicker({width:200}); });' );
}

/* ------------------------------------------------------------------
 * Save settings
 * ------------------------------------------------------------------ */
add_action( 'save_post_' . SP_CPT, 'sp_save_settings' );

function sp_save_settings( $post_id ) {
    // Only from our own form, with a valid nonce
    if ( ! isset( $_POST['sp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sp_nonce'] ) ), 'sp_save_settings' ) ) {
        return;
    }
    if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
        return;
    }
    if ( get_post_type( $post_id ) !== SP_CPT || ! current_user_can( SP_CAP ) || ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    // Unchecked checkboxes are not sent by the browser, so default them to off here
    $in = isset( $_POST['sp'] ) && is_array( $_POST['sp'] ) ? wp_unslash( $_POST['sp'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in sp_sanitize_settings()
    foreach ( array( 'blank', 'show_title', 'hide_mobile', 'scroll_content' ) as $cb ) {
        $in[ $cb ] = ! empty( $in[ $cb ] ) ? '1' : '0';
    }

    update_post_meta( $post_id, '_sp_settings', sp_sanitize_settings( $in ) );
}

/* ------------------------------------------------------------------
 * Columns in "All Popups"
 * ------------------------------------------------------------------ */
add_filter( 'manage_' . SP_CPT . '_posts_columns', 'sp_columns' );
add_action( 'manage_' . SP_CPT . '_posts_custom_column', 'sp_column_content', 10, 2 );

function sp_columns( $cols ) {
    $new = array();
    foreach ( $cols as $key => $label ) {
        $new[ $key ] = $label;
        if ( $key === 'title' ) {
            $new['sp_trigger'] = 'Opens';
            $new['sp_where']   = 'Shows on';
            $new['sp_link']    = 'Click link';
            $new['sp_order']   = 'Priority';
        }
    }
    return $new;
}

function sp_column_content( $col, $post_id ) {
    $s = sp_get_settings( $post_id );
    switch ( $col ) {
        case 'sp_trigger':
            $map = array( 'delay' => 'After ' . (int) $s['delay'] . 's', 'exit' => 'Exit intent', 'click' => 'On click' );
            echo esc_html( $map[ $s['trigger'] ] ?? '' );
            if ( $s['blank'] === '1' ) {
                echo ' <span style="color:#646970">(blank)</span>';
            }
            break;
        case 'sp_where':
            if ( $s['show_on'] === 'all' ) {
                echo 'Whole site';
            } elseif ( $s['show_on'] === 'front' ) {
                echo 'Homepage';
            } else {
                echo esc_html( $s['pages'] ?: '(none set)' );
            }
            break;
        case 'sp_link':
            echo '<code>#sp-' . (int) $post_id . '</code>';
            break;
        case 'sp_order':
            echo (int) $s['priority'];
            break;
    }
}

/* ------------------------------------------------------------------
 * REST API: the block editor needs it, but only for administrators.
 * Everyone else gets "not allowed" for any popup endpoint.
 * ------------------------------------------------------------------ */
add_filter( 'rest_pre_dispatch', 'sp_rest_guard', 10, 3 );

function sp_rest_guard( $result, $server, $request ) {
    $route = $request->get_route();
    if ( preg_match( '#^/wp/v2/(sp_popup|types/sp_popup)(/|$)#', $route ) && ! current_user_can( SP_CAP ) ) {
        return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'simple-popup' ), array( 'status' => rest_authorization_required_code() ) );
    }
    return $result;
}

// Keep popups out of search results and sitemaps
add_filter( 'wp_sitemaps_post_types', function ( $types ) {
    unset( $types[ SP_CPT ] );
    return $types;
} );

/* ------------------------------------------------------------------
 * Title tag choices
 * ------------------------------------------------------------------ */
function sp_title_tags() {
    return array(
        'h2' => 'Heading 2 (H2)',
        'h3' => 'Heading 3 (H3)',
        'h4' => 'Heading 4 (H4)',
        'h5' => 'Heading 5 (H5)',
        'p'  => 'Paragraph (bold)',
    );
}

/* ------------------------------------------------------------------
 * Duplicate a popup
 * ------------------------------------------------------------------ */
function sp_duplicate_url( $post_id ) {
    return wp_nonce_url(
        admin_url( 'admin-post.php?action=sp_duplicate&post=' . (int) $post_id ),
        'sp_duplicate_' . (int) $post_id
    );
}

add_filter( 'post_row_actions', 'sp_row_actions', 10, 2 );

function sp_row_actions( $actions, $post ) {
    if ( $post->post_type === SP_CPT && current_user_can( 'edit_post', $post->ID ) ) {
        $actions['sp_duplicate'] = '<a href="' . esc_url( sp_duplicate_url( $post->ID ) ) . '">Duplicate</a>';
    }
    return $actions;
}

add_action( 'admin_post_sp_duplicate', 'sp_handle_duplicate' );

function sp_handle_duplicate() {
    if ( ! current_user_can( SP_CAP ) ) {
        wp_die( esc_html__( 'You are not allowed to do this.', 'simple-popup' ), 403 );
    }

    $id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
    check_admin_referer( 'sp_duplicate_' . $id );

    $orig = $id ? get_post( $id ) : null;
    if ( ! $orig || $orig->post_type !== SP_CPT || ! current_user_can( 'edit_post', $id ) ) {
        wp_die( esc_html__( 'Popup not found or you are not allowed to copy it.', 'simple-popup' ), 404 );
    }

    $new_id = wp_insert_post( wp_slash( array(
        'post_type'    => SP_CPT,
        'post_status'  => 'draft',
        'post_title'   => $orig->post_title . ' (copy)',
        'post_content' => $orig->post_content,
        'post_author'  => get_current_user_id(),
    ) ), true );

    if ( is_wp_error( $new_id ) ) {
        wp_die( esc_html( $new_id->get_error_message() ) );
    }

    // Our settings (re-validated), plus SiteOrigin Page Builder layout if used
    update_post_meta( $new_id, '_sp_settings', sp_get_settings( $id ) );
    $panels = get_post_meta( $id, 'panels_data', true );
    if ( is_array( $panels ) && $panels ) {
        update_post_meta( $new_id, 'panels_data', wp_slash( $panels ) );
    }

    wp_safe_redirect( admin_url( 'post.php?action=edit&post=' . (int) $new_id ) );
    exit;
}

/* ==================================================================
 * 2. HELPERS
 * ================================================================== */

// 600 -> '600px'; keeps '80%', '60vw', '900px'. Falls back to $default if invalid.
// Validates CSS padding: 1 to 4 values, each a number + px/%/em/rem (or 0).
function sp_padding( $value, $default ) {
    if ( ! is_scalar( $value ) ) {
        return $default;
    }
    $parts = preg_split( '/\s+/', strtolower( trim( (string) $value ) ) );
    if ( ! $parts || count( $parts ) > 4 || $parts[0] === '' ) {
        return $default;
    }
    foreach ( $parts as $p ) {
        if ( ! preg_match( '/^(0|\d{1,4}(\.\d{1,2})?(px|%|em|rem))$/', $p ) ) {
            return $default;
        }
    }
    return implode( ' ', $parts );
}

function sp_size( $value, $default ) {
    if ( $value === null || $value === '' ) {
        return $default;
    }
    if ( ! is_scalar( $value ) ) {
        return $default;
    }
    if ( is_numeric( $value ) ) {
        $n = (float) $value;
        return ( $n >= 0 && $n <= 10000 ) ? $n . 'px' : $default;
    }
    $value = strtolower( trim( (string) $value ) );
    // Strict pattern: number + unit only, nothing else can get into the CSS
    if ( preg_match( '/^\d{1,5}(\.\d{1,2})?(px|%|vw|vh|em|rem)$/', $value ) ) {
        return $value;
    }
    return $default;
}

// Does the current page match a comma separated list of IDs / slugs / 'front' / 'shop'?
function sp_matches_list( $list ) {
    $items = array_filter( array_map( 'trim', explode( ',', (string) $list ) ), 'strlen' );
    foreach ( $items as $item ) {
        if ( $item === 'front' ) {
            if ( is_front_page() ) {
                return true;
            }
            continue;
        }
        if ( $item === 'shop' && function_exists( 'is_shop' ) && is_shop() ) {
            return true;
        }
        $key = ctype_digit( $item ) ? (int) $item : $item;
        if ( is_page( $key ) || is_single( $key ) ) {
            return true;
        }
    }
    return false;
}

function sp_should_show( $s ) {
    if ( $s['exclude'] !== '' && sp_matches_list( $s['exclude'] ) ) {
        return false;
    }
    if ( $s['show_on'] === 'front' ) {
        return is_front_page();
    }
    if ( $s['show_on'] === 'pages' ) {
        return sp_matches_list( $s['pages'] );
    }
    return true;
}

/* ==================================================================
 * 3. FRONT END
 * ================================================================== */
add_action( 'wp_footer', 'sp_render_popups' );

function sp_render_popups() {
    if ( is_admin() ) {
        return;
    }

    $posts = get_posts( array(
        'post_type'        => SP_CPT,
        'post_status'      => 'publish',
        'posts_per_page'   => 50,
        'orderby'          => 'date',
        'order'            => 'DESC',
        'suppress_filters' => false,
    ) );
    if ( ! $posts ) {
        return;
    }

    // Lowest priority number first (newest first on a tie)
    $prio = array();
    foreach ( $posts as $i => $popup ) {
        $prio[ $popup->ID ] = array( (int) sp_get_settings( $popup->ID )['priority'], $i );
    }
    usort( $posts, function ( $a, $b ) use ( $prio ) {
        return $prio[ $a->ID ] <=> $prio[ $b->ID ];
    } );

    $rendered = array();

    foreach ( $posts as $popup ) {
        $s = sp_get_settings( $popup->ID );
        if ( ! sp_should_show( $s ) ) {
            continue;
        }

        $id = (string) $popup->ID;
        $rendered[ $id ] = array(
            'trigger'    => $s['trigger'],
            'delay'      => (int) $s['delay'],
            'cookieDays' => (int) $s['cookie_days'],
            'hideMobile' => $s['hide_mobile'] === '1',
        );

        $blank = $s['blank'] === '1';
        $style = sprintf(
            '--sp-w:%s;--sp-mw:%s;--sp-mh:%s;--sp-r:%dpx;--sp-bw:%dpx;--sp-bc:%s',
            $s['width'], $s['max_width'], $s['max_height'],
            (int) $s['radius'], (int) $s['border_w'], $s['border_c']
        );
        $vars = array( 'popup_bg' => '--sp-bg', 'header_bg' => '--sp-hbg', 'header_c' => '--sp-hc', 'content_bg' => '--sp-cbg', 'content_c' => '--sp-cc' );
        foreach ( $vars as $key => $var ) {
            if ( $s[ $key ] !== '' ) {
                $style .= ';' . $var . ':' . $s[ $key ];
            }
        }
        $classes  = $blank ? ' sp-blank' : '';
        $classes .= $s['header_c'] !== '' ? ' sp-has-hc' : '';
        $classes .= $s['content_c'] !== '' ? ' sp-has-cc' : '';
        $classes .= $s['scroll_content'] === '1' && ! $blank ? ' sp-scroll-content' : '';
        $style   .= ';--sp-pad:' . $s['pad'] . ';--sp-pad-m:' . $s['pad_m'];

        // Content goes through the normal WordPress content filters (blocks, shortcodes, embeds)
        $content = apply_filters( 'the_content', $popup->post_content );
        ?>
        <div id="sp-<?php echo esc_attr( $id ); ?>" class="sp-overlay" data-sp-id="<?php echo esc_attr( $id ); ?>" aria-hidden="true">
            <div class="sp-modal<?php echo esc_attr( $classes ); ?>" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr( get_the_title( $popup ) ); ?>" style="<?php echo esc_attr( $style ); ?>">
                <button type="button" class="sp-close" aria-label="Close">&times;</button>
                <div class="sp-body">
                    <?php if ( $blank ) : ?>
                        <?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <?php else : ?>
                        <?php
                        $tag       = array_key_exists( $s['title_tag'], sp_title_tags() ) ? $s['title_tag'] : 'h2';
                        $has_title = $s['show_title'] === '1' && $popup->post_title !== '';
                        $title_html = '';
                        if ( $has_title ) {
                            $title_html .= sprintf( '<%1$s class="sp-title sp-title-%1$s">%2$s</%1$s>', $tag, esc_html( get_the_title( $popup ) ) );
                        }
                        if ( $s['subtitle'] !== '' ) {
                            $title_html .= '<p class="sp-subtitle">' . esc_html( $s['subtitle'] ) . '</p>';
                        }

                        if ( $s['header_layout'] === 'grid' && ( $title_html !== '' || $s['header_extra'] !== '' ) ) {
                            echo '<div class="sp-header sp-header-grid">';
                            echo '<div class="wpbf-grid wpbf-grid-small">';
                            echo '<div class="wpbf-medium-7-10 wpbf-small-6-10">' . $title_html . '</div>'; // phpcs:ignore -- escaped above
                            echo '<div class="wpbf-medium-3-10 wpbf-small-4-10 sp-header-extra">' . wp_kses_post( $s['header_extra'] ) . '</div>';
                            echo '</div>';
                            echo '</div>';
                        } elseif ( $title_html !== '' ) {
                            echo '<div class="sp-header">' . $title_html . '</div>'; // phpcs:ignore -- escaped above
                        }
                        ?>
                        <div class="sp-main">
                            <div class="sp-content"><?php echo $content; // phpcs:ignore ?></div>
                            <?php if ( $s['button_text'] !== '' && $s['button_url'] !== '' ) : ?>
                                <a class="sp-button" href="<?php echo esc_url( $s['button_url'] ); ?>"><?php echo esc_html( $s['button_text'] ); ?></a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    if ( ! $rendered ) {
        return;
    }
    ?>
    <style>
        .sp-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;z-index:999999;opacity:0;visibility:hidden;transition:opacity .3s,visibility .3s;padding:16px;box-sizing:border-box}
        .sp-overlay.sp-open{opacity:1;visibility:visible}

        .sp-modal{position:relative;box-sizing:border-box;width:var(--sp-w,600px);max-width:var(--sp-mw,90%);background:var(--sp-bg,#fff);border-radius:var(--sp-r,0);border:var(--sp-bw,0) solid var(--sp-bc,transparent);box-shadow:0 20px 60px rgba(0,0,0,.3);transform:translateY(20px);transition:transform .3s;font-family:inherit;overflow:hidden}
        .sp-overlay.sp-open .sp-modal{transform:translateY(0)}

        .sp-body{max-height:var(--sp-mh,90vh);overflow-y:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;padding:0;box-sizing:border-box;text-align:center}
        .sp-body img{max-width:100%;height:auto}

        .sp-close{position:absolute;top:10px;right:10px;z-index:5;width:34px;height:34px;padding:0;margin:0;border:0;border-radius:50%;background:#fff;color:#222;font-size:24px;line-height:34px;text-align:center;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.25)}
        .sp-close:hover{background:#f0f0f0;color:#000}

        .sp-header{background:var(--sp-hbg,transparent);color:var(--sp-hc,inherit);padding:var(--sp-pad,20px);padding-right:36px}
        .sp-main{background:var(--sp-cbg,transparent);color:var(--sp-cc,inherit);padding:var(--sp-pad,20px)}
        .sp-body > .sp-main:first-child{padding-top:54px}

        /* Blank grid header: columns come from the theme's own wpbf-grid CSS */
        .sp-header-grid{text-align:left}
        .sp-header-extra > :first-child{margin-top:0}
        .sp-header-extra > :last-child{margin-bottom:0}
        .sp-has-hc .sp-header-extra :is(p,span,strong,em,h1,h2,h3,h4,h5,h6,li){color:inherit}

        /* Scroll only the content: header fixed, content area scrolls */
        .sp-scroll-content .sp-body{display:flex;flex-direction:column;overflow:hidden}
        .sp-scroll-content .sp-header{flex:0 0 auto}
        .sp-scroll-content .sp-main{flex:1 1 auto;min-height:0;overflow-y:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain}
        .sp-has-hc .sp-header .sp-title,.sp-has-hc .sp-header .sp-subtitle{color:inherit}
        .sp-has-cc .sp-content :is(p,li,h1,h2,h3,h4,h5,h6,blockquote,span,strong,em,label){color:inherit}
        .sp-title{margin:0;line-height:1.25}
        .sp-subtitle{margin:6px 0 0;font-size:1.05em;opacity:.8}
        .sp-content{margin-bottom:20px}
        .sp-content:last-child{margin-bottom:0}
        .sp-content > :first-child{margin-top:0}
        .sp-content > :last-child{margin-bottom:0}
        .sp-button{display:inline-block;background:#222;color:#fff !important;padding:12px 24px;border-radius:6px;text-decoration:none !important;font-weight:600}
        .sp-button:hover{background:#444}

        .sp-blank .sp-body{padding:0;text-align:left}
        .sp-blank .sp-body > :first-child{margin-top:0}
        .sp-blank .sp-body > :last-child{margin-bottom:0}

        @media (max-width:780px){
            .sp-overlay{padding:0}
            .sp-modal{width:95%;max-width:95%}
            .sp-body{max-height:90vh}
            .sp-header,.sp-main{padding:var(--sp-pad-m,20px 12px)}
            .sp-header{padding-right:36px}
            .sp-body > .sp-main:first-child{padding-top:54px}
        }

        body.sp-noscroll{overflow:hidden}
    </style>

    <script>
    (function () {
        var popups   = <?php echo wp_json_encode( (object) $rendered ); ?>;
        var isMobile = window.innerWidth <= 780;
        var autoDone = false;

        function seen(id) {
            return document.cookie.indexOf('sp_seen_' + id + '=1') !== -1;
        }
        function setSeen(id) {
            var days = popups[id].cookieDays;
            if (days <= 0) return;
            var d = new Date();
            d.setTime(d.getTime() + days * 86400000);
            document.cookie = 'sp_seen_' + id + '=1; expires=' + d.toUTCString() + '; path=/; SameSite=Lax';
        }
        function el(id) { return document.getElementById('sp-' + id); }

        // Keep the X clear of the scrollbar when the content scrolls
        function scroller(o) {
            var modal = o.querySelector('.sp-modal');
            var main  = o.querySelector('.sp-main');
            return modal.classList.contains('sp-scroll-content') && main ? main : o.querySelector('.sp-body');
        }
        function placeClose(o) {
            var sc  = scroller(o);
            var btn = o.querySelector('.sp-close');
            // Only shift left if the scrollbar runs alongside the X (whole popup scrolling, or no header)
            var shift = (sc.classList.contains('sp-body') || !o.querySelector('.sp-header')) ? sc.offsetWidth - sc.clientWidth : 0;
            btn.style.right = (10 + shift) + 'px';
        }

        function open(id) {
            var o = el(id);
            if (!o) return;
            o.classList.add('sp-open');
            o.setAttribute('aria-hidden', 'false');
            document.body.classList.add('sp-noscroll');
            scroller(o).scrollTop = 0;
            placeClose(o);
        }
        function close(id) {
            var o = el(id);
            if (!o) return;
            o.classList.remove('sp-open');
            o.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('sp-noscroll');
            if (popups[id].trigger !== 'click') setSeen(id);
        }

        window.addEventListener('resize', function () {
            var o = document.querySelector('.sp-overlay.sp-open');
            if (o) placeClose(o);
        });

        Object.keys(popups).forEach(function (id) {
            var o = el(id);
            o.querySelector('.sp-close').addEventListener('click', function () { close(id); });
            o.addEventListener('click', function (e) { if (e.target === o) close(id); });
            var btn = o.querySelector('.sp-button');
            if (btn) btn.addEventListener('click', function () { setSeen(id); });
            o.querySelectorAll('img').forEach(function (img) {
                img.addEventListener('load', function () { if (o.classList.contains('sp-open')) placeClose(o); });
            });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            var openEl = document.querySelector('.sp-overlay.sp-open');
            if (openEl) close(openEl.getAttribute('data-sp-id'));
        });

        // Click triggers: href="#sp-123" or data-sp-popup="123"
        document.addEventListener('click', function (e) {
            var t = e.target.closest('[data-sp-popup], a[href*="#sp-"]');
            if (!t) return;
            var id = t.getAttribute('data-sp-popup');
            if (!id) {
                var m = (t.getAttribute('href') || '').match(/#sp-(\d+)$/);
                id = m ? m[1] : null;
            }
            if (id && popups[id]) { e.preventDefault(); open(id); }
        });

        // Automatic popups: only the first eligible one (lowest priority number) opens
        // (numeric object keys lose their order in JS, so the order comes as its own list)
        var autoIds = <?php echo wp_json_encode( array_keys( $rendered ) ); ?>.map(String);
        autoIds.some(function (id) {
            var p = popups[id];
            if (p.trigger === 'click') return false;
            if (p.hideMobile && isMobile) return false;
            if (p.cookieDays > 0 && seen(id)) return false;

            var fire = function () {
                if (autoDone || document.querySelector('.sp-overlay.sp-open')) return;
                autoDone = true;
                open(id);
            };

            if (p.trigger === 'exit' && !isMobile) {
                document.addEventListener('mouseout', function (e) {
                    if (!e.relatedTarget && e.clientY <= 0) fire();
                });
            } else {
                setTimeout(fire, p.delay * 1000);
            }
            return true;
        });
    })();
    </script>
    <?php
}
