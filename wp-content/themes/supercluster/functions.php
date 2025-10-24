<?php
/**
 * Child Theme functions.php
 */

/**
 * Theme setup
 */
if ( ! function_exists( 'blockbase_support' ) ) :
	function blockbase_support() {
		// Make theme available for translation.
		load_theme_textdomain( 'blockbase' );
		if ( 'blockbase' !== wp_get_theme()->get( 'TextDomain' ) ) {
			load_theme_textdomain( wp_get_theme()->get( 'TextDomain' ) );
		}

		// Core supports.
		add_theme_support( 'align-wide' );
		add_theme_support( 'link-color' );
		add_theme_support( 'responsive-embeds' );
		add_theme_support( 'editor-styles' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'block-nav-menus' );

		// Editor styles.
		add_editor_style( [ '/assets/ponyfill.css' ] );

		// Menus when Gutenberg plugin active.
		if ( defined( 'IS_GUTENBERG_PLUGIN' ) ) {
			register_nav_menus(
				[
					'primary' => __( 'Primary Navigation', 'blockbase' ),
					'social'  => __( 'Social Navigation', 'blockbase' ),
				]
			);
		}

		add_filter(
			'block_editor_settings_all',
			function( $settings ) {
				$settings['defaultBlockTemplate'] = '<!-- wp:group {"layout":{"inherit":true}} --><div class="wp-block-group"><!-- wp:post-content /--></div><!-- /wp:group -->';
				return $settings;
			}
		);

		// Custom logo.
		add_theme_support(
			'custom-logo',
			[
				'height'      => 192,
				'width'       => 192,
				'flex-width'  => true,
				'flex-height' => true,
			]
		);
	}
endif;
add_action( 'after_setup_theme', 'blockbase_support', 9 );

/**
 * Editor styles (child theme)
 */
function blockbase_editor_styles() {
	if ( file_exists( get_stylesheet_directory() . '/assets/theme.css' ) ) {
		add_editor_style( '/assets/theme.css' );
	}
}
add_action( 'admin_init', 'blockbase_editor_styles' );

/**
 * Front-end styles
 */
function blockbase_scripts() {
	wp_enqueue_style(
		'blockbase-ponyfill',
		get_template_directory_uri() . '/assets/ponyfill.css',
		[],
		wp_get_theme()->get( 'Version' )
	);

	// Child theme CSS (if present)
	$child_css_rel  = '/assets/theme.css';
	$child_css_path = get_stylesheet_directory() . $child_css_rel;
	if ( file_exists( $child_css_path ) ) {
		wp_enqueue_style(
			'blockbase-child-styles',
			get_stylesheet_directory_uri() . $child_css_rel,
			[ 'blockbase-ponyfill' ],
			filemtime( $child_css_path )
		);
	}
}
add_action( 'wp_enqueue_scripts', 'blockbase_scripts' );

/**
 * Customize Global Styles
 */
if ( class_exists( 'WP_Theme_JSON_Resolver_Gutenberg' ) ) {
	require get_template_directory() . '/inc/customizer/wp-customize-colors.php';
	require get_template_directory() . '/inc/social-navigation.php';
}

require get_template_directory() . '/inc/fonts/custom-fonts.php';
require get_template_directory() . '/inc/rest-api.php';

/**
 * Force menus to reload in Customizer
 */
add_action(
	'customize_controls_enqueue_scripts',
	static function () {
		wp_enqueue_script(
			'wp-customize-nav-menu-refresh',
			get_template_directory_uri() . '/inc/customizer/wp-customize-nav-menu-refresh.js',
			[ 'customize-nav-menus' ],
			wp_get_theme()->get( 'Version' ),
			true
		);
	}
);

/**
 * Block Patterns
 */
require get_template_directory() . '/inc/block-patterns.php';
if ( file_exists( get_stylesheet_directory() . '/inc/block-patterns.php' ) ) {
	require_once get_stylesheet_directory() . '/inc/block-patterns.php';
}

/**
 * Allow SVG uploads (⚠️ consider sanitization if untrusted users can upload)
 */
function enable_svg_support( $mimes ) {
	$mimes['svg'] = 'image/svg+xml';
	return $mimes;
}
add_filter( 'upload_mimes', 'enable_svg_support' );

/**
 * Optional: remove controls attribute from [video] shortcode outputs
 * (Remove this filter if you want the controls shown.)
 */
add_filter( 'wp_video_shortcode', function( $output ) {
	return str_replace( 'controls="controls"', '', $output );
} );

/**
 * Front page: glow borders CSS/JS
 */
add_action( 'wp_enqueue_scripts', function () {
	if ( is_front_page() ) {
		$css_rel  = '/assets/glow-borders.css';
		$css_path = get_stylesheet_directory() . $css_rel;

		$js_rel   = '/assets/javascript/glow-borders.js';
		$js_path  = get_stylesheet_directory() . $js_rel;

		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'sa-glow-borders',
				get_stylesheet_directory_uri() . $css_rel,
				[],
				filemtime( $css_path )
			);
		}

		if ( file_exists( $js_path ) ) {
			wp_enqueue_script(
				'sa-glow-borders',
				get_stylesheet_directory_uri() . $js_rel,
				[],
				filemtime( $js_path ),
				true
			);
		}
	}
}, 20 );

/**
 * Fadeout effects JS (cache-busted)
 */
function fadeout_effects() {
	$rel  = '/assets/javascript/fadeout-effects.js';
	$path = get_stylesheet_directory() . $rel;
	if ( file_exists( $path ) ) {
		wp_enqueue_script(
			'fadeout-effects',
			get_stylesheet_directory_uri() . $rel,
			[],
			filemtime( $path ),
			true
		);
	}
}
add_action( 'wp_enqueue_scripts', 'fadeout_effects' );

/**
 * Pricing slider JS (cache-busted)
 */
function left_right_arrows() {
	$rel  = '/assets/javascript/pricing-slider.js';
	$path = get_stylesheet_directory() . $rel;
	if ( file_exists( $path ) ) {
		wp_enqueue_script(
			'pricing-slider',
			get_stylesheet_directory_uri() . $rel,
			[],
			filemtime( $path ),
			true
		);
	}
}
add_action( 'wp_enqueue_scripts', 'left_right_arrows' );

/* === LUNA: conditionally load, admin-only debug breadcrumbs, localized REST vars === */
add_action('wp_enqueue_scripts', function () {
	if ( is_admin() ) return;

	// Decide if we should load the chat
	$should_load = false;

	// Prefer plugin helper if available
	if ( function_exists('luna_get_chat_mode') ) {
		$mode = luna_get_chat_mode();
		if ( $mode === 'widget' ) {
			$should_load = true; // widget is site-wide
		} else {
			// Load only on pages that actually have the shortcode
			$post_id = get_queried_object_id() ?: 0;
			if ( $post_id && has_shortcode( get_post_field('post_content', $post_id), 'luna_chat' ) ) {
				$should_load = true;
			}
		}
	} else {
		// Plugin not loaded yet? Fallback to shortcode detection only
		$post_id = get_queried_object_id() ?: 0;
		if ( $post_id && has_shortcode( get_post_field('post_content', $post_id), 'luna_chat' ) ) {
			$should_load = true;
		}
	}

        // Always load on the response route (/products/luna/chat/response/*)
        $req_path = trim( $_SERVER['REQUEST_URI'] ?? '', '/' );
        if ( strpos( $req_path, 'products/luna/chat/response' ) !== false ) {
                $should_load = true;
        }

        // The Luna composer page itself does not always use the shortcode, so also
        // enqueue the script whenever the request path points at the Luna product hub.
        if ( strpos( $req_path, 'products/luna' ) === 0 ) {
                $should_load = true;
        }

	if ( ! $should_load ) return;

	$handle      = 'luna-chat';
	$rel_path    = '/assets/javascript/luna-chat.js';
	$script_path = get_stylesheet_directory() . $rel_path;      // child theme path
	$script_url  = get_stylesheet_directory_uri() . $rel_path;  // child theme URL

	// Admin-only debug breadcrumbs
	if ( current_user_can( 'manage_options' ) ) {
		add_action('wp_footer', function() use ($script_path) {
			echo "\n<!-- LUNA DEBUG: target file path {$script_path} -->\n";
			echo "<!-- LUNA DEBUG: current url " . esc_html($_SERVER['REQUEST_URI'] ?? '') . " -->\n";
		}, 997);
	}

	if ( file_exists( $script_path ) ) {
		wp_enqueue_script(
			$handle,
			$script_url,
			[],
			filemtime( $script_path ),
			true
		);

                wp_localize_script($handle, 'lunaVars', [
                        'restUrlChat' => esc_url_raw(rest_url('luna_widget/v1/chat')),
                        'restUrlLive' => esc_url_raw(rest_url('luna/v1/chat-live')),
                        'nonce'       => wp_create_nonce('wp_rest'),
                ]);

		// Admin-only breadcrumb to confirm enqueue
		if ( current_user_can( 'manage_options' ) ) {
			add_action('wp_footer', function() use ($script_url) {
				echo "\n<!-- LUNA DEBUG: enqueued {$script_url} -->\n";
			}, 998);
		}

		// Tiny inline flag so we can tell if it ran
		add_action('wp_footer', function () {
			?>
<script>
window.__LUNA_EXPECTED__ = true;
// If luna-chat.js runs, it should set window.lunaBootstrapped = true
setTimeout(function(){
	if (!window.lunaBootstrapped) {
		console.warn('[LUNA] Script enqueued but not detected as running.');
	}
}, 1200);
</script>
			<?php
		}, 999);

	} else {
		// Admin-only loud breadcrumb if file is missing
		if ( current_user_can( 'manage_options' ) ) {
			add_action('wp_footer', function() use ($script_path) {
				echo "\n<!-- LUNA ERROR: JS file not found at {$script_path} -->\n";
			}, 998);
		}
	}
}, 99);


/**
 * Allow WP to recognize our custom query var (?luna_req_id=...)
 * (Safe to keep; your plugin also registers it.)
 */
add_filter( 'query_vars', function ( $vars ) {
        $vars[] = 'luna_req_id';
        return $vars;
} );

add_action('admin_init', function () {
  if ( current_user_can('manage_options') ) {
    error_log('VL test: debug log is working at '.gmdate('c'));
  }
});

/**
 * Disable VL LAS audit features (v1.1.1) from the child theme.
 * - Hides the Scan button/UI on the settings page
 * - Dequeues the plugin’s admin JS that powers the audit
 * - Unregisters the REST endpoints (/vl-las/v1/audit and /gemini-test)
 */

/* 1) Hide the audit UI on the settings screen */
add_action('admin_head', function () {
    if ( ! current_user_can('manage_options') ) return;
    // Only on the plugin settings page (?page=vl-las)
    if ( isset($_GET['page']) && $_GET['page'] === 'vl-las' ) {
        echo '<style>
            #vl-las-run-audit,
            #vl-las-audit-result,
            .vl-las-audit-row { display:none !important; }
        </style>';
    }
});

/* 2) Dequeue the audit script on that screen (prevents AJAX calls) */
add_action('admin_enqueue_scripts', function () {
    if ( ! current_user_can('manage_options') ) return;
    if ( function_exists('get_current_screen') ) {
        $screen = get_current_screen();
        if ( $screen && $screen->id === 'settings_page_vl-las' ) {
            // Handle recorded handles from the plugin; ignore if not present
            wp_dequeue_script('vl-las-admin');
            wp_deregister_script('vl-las-admin');
        }
    }
}, 20);

/* 3) Unregister REST routes so nothing can call the audit endpoints */
add_filter('rest_endpoints', function ($endpoints) {
    foreach ( ['/vl-las/v1/audit', '/vl-las/v1/gemini-test'] as $route ) {
        if ( isset($endpoints[$route]) ) {
            unset($endpoints[$route]);
        }
    }
    return $endpoints;
});
