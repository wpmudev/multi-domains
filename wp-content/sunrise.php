<?php

if ( defined( 'COOKIE_DOMAIN' ) ) {
	die( 'The constant "COOKIE_DOMAIN" is defined (probably in wp-config.php). Please remove or comment out that define() line.' );
}

global $wpdb;

$using_domain = $wpdb->escape( preg_replace( "/^www\./", "", $_SERVER[ 'HTTP_HOST' ] ) );

if ( preg_replace( "/^www\./", "", DOMAIN_CURRENT_SITE ) !== $using_domain ) {
	$md_domains = unserialize( $wpdb->get_var( "SELECT meta_value FROM {$wpdb->sitemeta} WHERE meta_key = 'md_domains' AND site_id = 1" ) );

	if( is_array( $md_domains ) ) {
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
}