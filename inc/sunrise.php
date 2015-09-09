<?php
function multi_domains_is_md_active(){
    global $wpdb;
    $dmmd_active_plugins = wp_cache_get("dmmd_active_plugins");
    if( !$dmmd_active_plugins ){
        $dmmd_active_plugins = unserialize( $wpdb->get_var("SELECT `meta_value` FROM " . $wpdb->sitemeta ." WHERE `meta_key`='active_sitewide_plugins'") );
        wp_cache_set("dm_active_plugins", $dmmd_active_plugins);
    }
    return is_array($dmmd_active_plugins) ? in_array("multi-domains/multi-domains.php", array_keys( $dmmd_active_plugins )) : false;
}

function multi_domains_sunrise() {
	global $wpdb;

	// set multi-domains sunrise version
	define( 'MULTIDOMAINS_SUNRISE_VERSION', '1.0.1' );

	if ( defined( 'COOKIE_DOMAIN' ) ) {
		if ( defined( 'DOMAIN_MAPPING' ) || defined( 'DOMAINMAPPING_SUNRISE_VERSION' ) ) {
			// don't do anything if domain mapping has already processed the domain
			return;
		} else {
			// die if cookie domain has been set
			wp_die( 'The constant "COOKIE_DOMAIN" is defined (probably in wp-config.php). Please remove or comment out that define() line.' );
		}
	}

	// setup current domain
	$using_domain = preg_replace( "/^www\./", "", $_SERVER['HTTP_HOST'] );
	if ( preg_replace( '/^www\./', '', DOMAIN_CURRENT_SITE ) !== $using_domain ) {
		$md_domains = @unserialize( $wpdb->get_var( "SELECT meta_value FROM {$wpdb->sitemeta} WHERE meta_key = 'md_domains' AND site_id = 1" ) );
		if ( is_array( $md_domains ) ) {
			if ( $_SERVER['REQUEST_URI'] == '/' ) {
				foreach ( $md_domains as $domain ) {
					if ( $_SERVER['HTTP_HOST'] == strtolower( $domain['domain_name'] ) ) {
						$location = 'http://' . DOMAIN_CURRENT_SITE;
						$m_iis = strpos( $_SERVER['SERVER_SOFTWARE'], 'Microsoft-IIS' ) !== false;
						$dev_server = strpos( $_SERVER['SERVER_SOFTWARE'], 'ExpressionDevServer' ) !== false;
						if ( $m_iis || $dev_server ) {
							header( "Refresh: 0; url=$location" );
						} else {
							header( "Location: $location", true, 301 );
						}

						exit;
					}
				}
			}

			foreach ( $md_domains as $domain ) {
				if ( preg_match( '|' . strtolower( $domain['domain_name'] ) . '$|', $using_domain ) ) {
					define( 'COOKIE_DOMAIN', '.' . strtolower( $domain['domain_name'] ) );
					break;
				}
			}
		}
	}
}

// run sunrise
if( multi_domains_is_md_active() )
    multi_domains_sunrise();