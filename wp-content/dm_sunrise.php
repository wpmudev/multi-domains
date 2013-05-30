<?php

if ( defined( 'COOKIE_DOMAIN' ) )
	die( 'The constant "COOKIE_DOMAIN" is defined (probably in wp-config.php). Please remove or comment out that define() line.' );

// Compatibility mode
define('DM_COMPATIBILITY', 'yes');

// domain mapping plugin to handle VHOST and non VHOST installation
global $wpdb;

// No if statement needed as the code was the same for both VHOST and non VHOST installations
if(defined('DM_COMPATIBILITY')) {
	$wpdb->dmtable = $wpdb->base_prefix . 'domain_mapping';
} else {
	$wpdb->dmtable = $wpdb->base_prefix . 'domain_map';
}


$wpdb->suppress_errors();

$using_domain = $wpdb->escape( preg_replace( "/^www\./", "", $_SERVER[ 'HTTP_HOST' ] ) );

// Check for the domain with and without the www. prefix
$mapped_id = $wpdb->get_var( $wpdb->prepare( "SELECT blog_id FROM {$wpdb->dmtable} WHERE domain = %s OR domain = %s LIMIT 1 /* domain mapping */", $using_domain, 'www.' . $using_domain ) );

$wpdb->suppress_errors( false );

if ( !empty($mapped_id) && ( preg_replace( "/^www\./", "", DOMAIN_CURRENT_SITE ) !== $using_domain ) ) {
	$md_domains = unserialize( $wpdb->get_var( "SELECT meta_value FROM {$wpdb->sitemeta} WHERE meta_key = 'md_domains' AND site_id = 1" ) );

	if( $_SERVER['REQUEST_URI'] == '/' ) {
		foreach ( $md_domains as $domain ) {

			if( $_SERVER['HTTP_HOST'] == strtolower( $domain['domain_name'] ) ) {
				$location = 'http://' . DOMAIN_CURRENT_SITE;

				if ( strpos( $_SERVER['SERVER_SOFTWARE'], 'Microsoft-IIS' ) == true || strpos( $_SERVER['SERVER_SOFTWARE'], 'ExpressionDevServer' ) == true ) {
					header( "Refresh: 0;url=$location ");
				} else {
					if ( php_sapi_name() != 'cgi-fcgi' )
						status_header($status); // This causes problems on IIS and some FastCGI setups
					header( "Location: $location", true, 301 );
				}

				die;
			}

		}
	}

	foreach( $md_domains as $domain ) {
		if ( preg_match( '|' . strtolower( $domain['domain_name'] ) . '$|', $using_domain ) ) {
			define( 'COOKIE_DOMAIN', '.' . strtolower( $domain['domain_name'] ) );
			break;
		}
	}
}

if( !empty($mapped_id) ) {
	$current_blog = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->blogs WHERE blog_id = %d LIMIT 1 /* domain mapping */", $mapped_id ) );
	$current_blog->domain = $_SERVER[ 'HTTP_HOST' ];

	$blog_id = $mapped_id;
	$site_id = $current_blog->site_id;

	define( 'COOKIE_DOMAIN', $_SERVER[ 'HTTP_HOST' ] );

	$current_site = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->site WHERE id = %d /* domain mapping */", $current_blog->site_id ) );

	$current_site->blog_id = $wpdb->get_var( $wpdb->prepare( "SELECT blog_id FROM $wpdb->blogs WHERE domain = %s AND path = %s /* domain mapping */", $current_site->domain, $current_site->path ) );

	$current_site = get_current_site_name( $current_site );

	$current_blog->path = $current_site->path;

	define( 'DOMAIN_MAPPING', 1 );

	// Added for belt and braces
	if ( !defined('WP_CONTENT_URL') ) {
		$protocol = ( isset( $_SERVER['HTTPS' ] ) && 'on' == strtolower( $_SERVER['HTTPS' ] ) ) ? 'https://' : 'http://';
		define( 'WP_CONTENT_URL', $protocol . $current_blog->domain . $current_blog->path . 'wp-content'); // full url - WP_CONTENT_DIR is defined further up
	}

}
