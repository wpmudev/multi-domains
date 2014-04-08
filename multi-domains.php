<?php
/*
Plugin Name: Multi-Domains for Multisite
Plugin URI: http://premium.wpmudev.org/project/multi-domains/
Description: Easily allow users to create new sites (blogs) at multiple different domains - using one install of WordPress Multisite you can support blogs at name.domain1.com, name.domain2.com etc.
Version: 1.3.2
Network: true
Text Domain: multi_domain
Author: Incsub
Author URI: http://premium.wpmudev.org/
WDP ID: 154
*/

// +----------------------------------------------------------------------+
// | Copyright Incsub (http://incsub.com/)                                |
// | Based on an original by Joe Jacobs (http://joejacobs.org/) and       |
// | Donncha (http://ocaoimh.ie/)                                         |
// +----------------------------------------------------------------------+
// | This program is free software; you can redistribute it and/or modify |
// | it under the terms of the GNU General Public License, version 2, as  |
// | published by the Free Software Foundation.                           |
// |                                                                      |
// | This program is distributed in the hope that it will be useful,      |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of       |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the        |
// | GNU General Public License for more details.                         |
// |                                                                      |
// | You should have received a copy of the GNU General Public License    |
// | along with this program; if not, write to the Free Software          |
// | Foundation, Inc., 51 Franklin St, Fifth Floor, Boston,               |
// | MA 02110-1301 USA                                                    |
// +----------------------------------------------------------------------+

if( !is_multisite() ) {
	wp_die( __( 'The Multi-Domains plugin is only compatible with WordPress Multisite.', 'multi_domain' ) );
}

require_once dirname( __FILE__ ) . '/extra/wpmudev-dash-notification.php';

class multi_domain {

	/**
	 * Domain used for localization
	 *
	 * @var string
	 */
	var $textdomain = 'multi_domain';

	/**
	 * Plugin version
	 *
	 * @var string
	 */
	var $version = '1.3.2';

	/**
	 * Sunrise version
	 *
	 * @var string
	 */
	var $sunrise = '1.0.1';

	/**
	 * Database object
	 *
	 * @var wpdb
	 */
	var $db;

	/**
	 * Temporary stores $current_site global
	 *
	 * @var object
	 */
	var $current_site;

	/**
	 * Domains list
	 *
	 * @var array
	 */
	var $domains = array();

	/**
	 * Constructor
	 */
	function __construct() {
		global $wpdb;

		$this->db = $wpdb;
		$this->upgrade_sunrise();

		// Set plugin default options if the plugin was not installed before
		add_action( 'init', array( $this, 'activate_plugin' ) );
		// Enable or disable single signon
		add_action( 'init', array( $this, 'switch_single_signon' ) );
		// Run plugin functions
		add_action( 'init', array( $this, 'setup_plugin' ) );
		// Add in the cross domain logins
		add_action( 'init', array( $this, 'build_stylesheet_for_cookie' ) );

		$skip_site_search = defined( 'MD_SKIP_SITE_SEARCH_QUERY_REWRITE' ) && MD_SKIP_SITE_SEARCH_QUERY_REWRITE;
		if ( is_network_admin() && is_subdomain_install() && !$skip_site_search ) {
			add_filter( 'query', array( $this, 'subdomain_filter_site_search_query' ) );
		}
	}

	function subdomain_filter_site_search_query( $query ) {
		// WP 3.3+ scoping
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( !$screen || 'sites-network' != $screen->id ) {
				return $query; // Not a proper page
			}
		}

		global $current_site, $s;
		if ( !preg_match( '/' . preg_quote( $this->db->blogs . '.domain', '/' ) . '/', $query ) ) {
			return $query;
		}

		$replacement = preg_match( '/\./', $s ) ? '\1%' : '\1.%'; // Rewrite scoping for full sub+domain searches
		$query = preg_replace( '/(\b|%)' . preg_quote( ".{$current_site->domain}", '/' ) . '\b/', $replacement, $query );

		return $query;
	}

	/**
	 * Run on plugin activation.
	 */
	function activate_plugin() {
		$version = get_site_option( 'md_version' );
		if ( empty( $version ) ) {
			$domains = array(
				array(
					'domain_name'   => DOMAIN_CURRENT_SITE,
					'domain_status' => 'public'
				)
			);

			update_site_option( 'md_domains', apply_filters( 'md_default_domains', $domains ) );
			update_site_option( 'md_version', $this->version );
		}

		if ( version_compare( $this->version, $version, '>' ) ) {
			update_site_option( 'md_version', $this->version );
		}
	}

	/**
	 * Run plugin functions.
	 */
	function setup_plugin() {
		$this->domains = get_site_option( 'md_domains' );

		// handle translations
		load_plugin_textdomain( $this->textdomain, false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		// Add the super admin page
		add_action( 'network_admin_menu', array( $this, 'network_admin_page' ) );

		// Enqueue javascript
		$this->enqueue_jquery();
		add_action( 'wp_head', array( $this, 'enqueue_signup_javascript' ) );

		// Empty $current_site global on signup page
		add_action( 'signup_hidden_fields', array( $this, 'modify_current_site' ) );
		// Populate $current_site, $domain and $base globals on signup page with values choosen by the user on signup page
		add_filter( 'newblogname', array( $this, 'set_registration_globals' ) );
		add_action( 'admin_action_add-site', array( $this, 'set_registration_globals' ) ); // For when the site is created from network admin
		add_filter( 'add_signup_meta', array( $this, 'set_other_registration_globals' ) );
		// Restore default $current_site global on signup page
		add_action( 'signup_finished', array( $this, 'restore_current_site' ) );
		add_action( 'wpmu_new_blog', array( $this, 'restore_current_site' ) );

		// Populate $current_site on admin edit page
		add_action( 'wpmuadminedit', array( $this, 'wpmuadminedit' ) );

		// Extends blog registration forms
		add_action( 'signup_blogform', array( $this, 'extend_signup_blogform' ) ); // default and buddypress blog creation forms
		add_action( 'bp_signup_blog_url_errors', array( $this, 'extend_signup_blogform' ) ); // buddypress signup form
		add_action( 'admin_footer', array( $this, 'extend_admin_blogform' ) ); // admin site creation form
		// Cross domain cookies
		if ( get_site_option( 'multi_domains_single_signon' ) == 'enabled' ) {
			add_action( 'wp_loaded', array( $this, 'maybe_logout_user' ) );
            add_action( 'wp_login', array( $this, 'build_cookie' ) );
//			if ( defined( 'BP_VERSION' ) ) {
//				add_action( 'wp_head', array( $this, 'build_cookie' ) );
//			}
            add_action( 'wp_logout', array( $this, 'build_logout_cookie' ) );
		}

		// modify blog columns on Super Admin > Sites page
		add_filter( 'wpmu_blogs_columns', array( $this, 'blogs_columns' ) );
		add_action( 'manage_blogs_custom_column', array( $this, 'manage_blogs_custom_column' ), 10, 2 );

		// Canonicalize main URL
		if ( defined( 'MD_CANONICAL_DOMAIN' ) && MD_CANONICAL_DOMAIN ) {
			// Removing default canonical action - note: custom SEO in themes
			/// or plugins will override this behavior.
			remove_action( 'wp_head', 'rel_canonical' );
			add_action( 'wp_head', array( $this, 'canonicalize_main_domain' ) );
		}
	}

	function canonicalize_main_domain() {
		global $wp;

		if ( !defined( 'MD_CANONICAL_DOMAIN' ) || !MD_CANONICAL_DOMAIN ) {
			return false; // Nothing to do
		}

		$link = site_url( $wp->request );
		foreach ( $this->domains as $data ) {
			if ( preg_match( '/' . preg_quote( $data['domain_name'], '/' ) . '/i', $link ) ) {
				$link = preg_replace( '/' . preg_quote( $data['domain_name'], '/' ) . '/i', MD_CANONICAL_DOMAIN, $link );
				break;
			}
		}

		$link = apply_filters( 'md-canonical_domain-replacement_link', $link );
		if ( !$link ) {
			return false;
		}

		printf( '<link rel="canonical" href="%s"/>', esc_url( $link ) );
	}

	/**
	 * Add network admin page
	 */
	function network_admin_page() {
		global $wpmudev_notices;

		$title = __( 'Multi-Domains', $this->textdomain );
		$page = add_submenu_page( 'settings.php', $title, $title, 'manage_network', 'multi-domains', array( $this, 'management_page' ) );

		$wpmudev_notices[] = array(
			'id'      => 154,
			'name'    => 'Multi-Domains for Multisite',
			'screens' => array( "{$page}-network" ),
		);
	}

	/**
	 * Add domain colum to Super Admin > Sites table.
	 */
	function blogs_columns( $sites_columns ) {

		if( !is_subdomain_install() )
			$sites_columns['domain'] = __( 'Domain', $this->textdomain );

		return $sites_columns;

	}

	/**
	 * Add domain column to Super Admin > Sites table.
	 */
	function manage_blogs_custom_column( $column_name, $blog_id ) {
		if ( !is_subdomain_install() ) {
			$bloginfo = get_blog_details( (int) $blog_id, false );
			echo $bloginfo->domain;
		}
	}

	/**
	 * Update domains in the database.
	 */
	function update_domains_option() {
		return update_site_option( 'md_domains', apply_filters( 'md_update_domains_option', $this->domains_sort() ) );
	}

	/**
	 * Sort domains bidimensional array by domain name.
	 */
	function domains_sort( $orderby = 'domain_name' ) {
		$sort_array = array();
		$domains = $this->domains;

		foreach( $domains as $domain ) {
			foreach( $domain as $key => $value ) {
				if( !isset( $sort_array[$key] ) )
					$sort_array[$key] = array();

				$sort_array[$key][] = $value;
			}
		}

		array_multisort( $sort_array[$orderby], SORT_ASC, $domains );

		return $this->domains = $domains;
	}

	/**
	 * Add domain to the list.
	 */
	function add_domain( $domain = array() ) {

		$domains = $this->domains;

		for( $i = 0; $i < count( $domains ); $i++ ) {
			if( $domains[$i]['domain_name'] == $domain['domain_name'] ) {
				$domain_exists = 1;
				break;
			}
		}

		if( empty( $domain_exists ) ) {
			$domains[] = apply_filters( 'md_add_domain', $domain );
			$this->domains = $domains;
			return true;
		} else {
			return new WP_Error( 'md_domain_exists', sprintf( __( "The domain %s is already in the list.", $this->textdomain ), $domain['domain_name'] ) );
		}

	}

	/**
	 * Modify domain details.
	 */
	function update_domain( $domain = array() ) {

		$domains = $this->domains;

		for( $i = 0; $i < count( $domains ); $i++ ) {
			if( $domains[$i]['domain_name'] == $domain['domain_name'] ) {
				$current_domain = $domains[$i];
				$current_domain['domain_status'] = $domain['domain_status'];
				$domains[$i] = apply_filters( 'md_update_domain', $current_domain, $domain );
				$domain_exists = 1;
				break;
			}
		}

		if( !empty( $domain_exists ) ) {
			$this->domains = $domains;
			return true;
		} else {
			return new WP_Error( 'md_domain_not_found', sprintf( __( "The domain %s was not found in the list.", $this->textdomain ), $domain['domain_name'] ) );
		}

	}

	/**
	 * Get domain attributes.
	 */
	function get_domain( $name_or_id ) {
		$domains = $this->domains;

		if( is_numeric( $name_or_id ) ) {
			return isset( $domains[$name_or_id] ) ? $domains[$name_or_id] : false;
		} else {
			foreach( $domains as $domain ) {
				if( $domain['domain_name'] == $name_or_id )
					return $domain;
			}
		}

		return false;
	}

	/**
	 * Remove one domain from the list.
	 */
	function delete_domain( $domain_name = '' ) {

		if( !empty( $domain_name ) ) {

			$domains = $this->domains;

			for( $i = 0; $i < count( $domains ); $i++ ) {
				if( $domains[$i]['domain_name'] == $domain_name ) {
					unset( $domains[$i] );
					$domain_exists = 1;
					break;
				}
			}

			if( isset( $domain_exists ) ) {
				$this->domains = apply_filters( 'md_delete_domain', array_values( $domains ) );
				return true;
			} else {
				return new WP_Error( 'md_domain_not_found', sprintf( __( "The domain %s was not found in the list.", $this->textdomain ), $domain_name ) );
			}

		}

	}

	/**
	* Return Domains status list
	*/
	function domain_status() {

		if( $this->show_restricted_domains() == true ) {
			$status = array( 'public', 'restricted', 'private' );
		} else {
			$status = array( 'public', 'private' );
		}
		return apply_filters( 'md_domain_status', $status );

	}

	/**
	 * Super Admin page.
	 */
	function management_page() {
		if ( !is_super_admin() ) {
			wp_die( '<p>' . __( 'You do not have permission to access this page.', $this->textdomain ) . '</p>' );
		}

		require_once dirname( __FILE__ ) . '/multi-domains-table.php';

		$table = new Multidomains_Table( array(
			'search_box' => true,
			'actions'    => array(
				'deletedomains' => __( 'Delete', $this->textdomain )
			),
		) );

		$messages = array();
		if ( isset( $_POST['add_domain'] ) && isset( $_POST['domain_name'] ) && isset( $_POST['domain_status'] ) ) {
			if ( ( $message = $this->add_domain_post() ) ) {
				$messages[] = '<div id="message" class="updated fade"><p>' . $message . '</p></div>';
			} else {
				$messages[] = '<div id="message" class="error"><p>' . __( 'Error : the domain was not added', $this->textdomain ) . '</p></div>';
			}
		} elseif ( isset( $_POST['edit_domain'] ) && isset( $_POST['domain_name'] ) && isset( $_POST['domain_status'] ) ) {
			if ( ( $message = $this->edit_domain_post() ) ) {
				$messages[] = '<div id="message" class="updated fade"><p>' . $message . '</p></div>';
			} else {
				$messages[] = '<div id="message" class="error"><p>' . __( 'Error : the domain was not saved', $this->textdomain ) . '</p></div>';
			}
		} elseif ( isset( $_GET['delete'] ) && isset( $_GET['name'] ) ) {
			if ( ( $message = $this->delete_domain_post() ) ) {
				$messages[] = '<div id="message" class="updated fade"><p>' . $message . '</p></div>';
			} else {
				$messages[] = '<div id="message" class="error"><p>' . __( 'Error : the domain was not deleted', $this->textdomain ) . '</p></div>';
			}
		} elseif ( $table->current_action() == 'deletedomains' ) {
			if ( ( $message = $this->delete_domains_post() ) ) {
				$messages[] = '<div id="message" class="updated fade"><p>' . $message . '</p></div>';
			} else {
				$messages[] = '<div id="message" class="error"><p>' . __( 'Error : the domains were not deleted', $this->textdomain ) . '</p></div>';
			}
		} elseif ( !empty( $_GET['single_signon'] ) ) {
			if ( 'enable' == $_GET['single_signon'] ) {
				$messages[] = '<div id="message" class="updated fade"><p>' . __( 'Single Sign-on enabled.', $this->textdomain ) . '</p></div>';
			} elseif ( 'disable' == $_GET['single_signon'] ) {
				$messages[] = '<div id="message" class="updated fade"><p>' . __( 'Single Sign-on disabled.', $this->textdomain ) . '</p></div>';
			}
		}

		$exists = is_readable( WP_CONTENT_DIR . '/sunrise.php' );
		$valid = defined( 'MULTIDOMAINS_SUNRISE_VERSION' ) && version_compare( MULTIDOMAINS_SUNRISE_VERSION, $this->sunrise, '=' );

		?><div class="wrap" style="position: relative">

			<div id="icon-ms-admin" class="icon32"></div>
			<h2><?php _e ( 'Domains', $this->textdomain ) ?></h2>

			<?php echo implode( '', $messages ) ?>

			<?php if ( !$exists ) : ?>
				<div id="message" class="error">
					<p><?php
						printf( __( 'Please copy the %1$s to %2$s/%1$s and uncomment the SUNRISE setting in the %2$s/%3$s file.', $this->textdomain ), 'sunrise.php', WP_CONTENT_DIR, 'wp-config.php' )
					?></p>
				</div>
			<?php elseif ( !$valid ) : ?>
				<div id="message" class="error">
					<p><?php
						printf( __( 'Please copy the content of %1$s into %2$s/%1$s and uncomment the SUNRISE setting in the %2$s/%3$s file.', $this->textdomain ), 'sunrise.php', WP_CONTENT_DIR, 'wp-config.php' )
					?></p>
				</div>
			<?php endif; ?>

			<?php if ( !defined( 'SUNRISE' ) ) : ?>
				<div id="message" class="error">
					<p><?php
						printf( __( 'Please uncomment the line %s in the %s file.', $this->textdomain ), "<em>//define( 'SUNRISE', 'on' );</em>", ABSPATH . 'wp-config.php' )
					?></p>
				</div>
			<?php endif; ?>

			<div id="col-container">
				<div id="col-right">

					<form method="post" action="?page=multi-domains">
						<?php $table->prepare_items() ?>
						<?php $table->search_box( __( 'Search Added Domains', $this->textdomain ), 'multi_domains_search' ) ?>
						<?php $table->display() ?>
					</form>

					<p><?php
						if( is_subdomain_install() ) :
							_e( 'In the DNS records for each domain added here, add a wildcard subdomain that points to this WordPress installation. It should look like:', $this->textdomain );
							echo ' <strong>A *.domain.com</strong><br>';
							_e( 'Pay attention that values of Wildcard DNS Availability column are refreshed each 5 minutes.', $this->textdomain );
						else:
							_e( 'Change the DNS records for each domain to point to this WordPress installation.', $this->textdomain );
						endif;
					?></p>
				</div>

				<div id="col-left">
					<?php if( isset( $_GET['edit'] ) ) : ?>
						<?php $this->edit_domain_form() ?>
					<?php else : ?>
						<?php $this->add_domain_form() ?>

						<h3><?php _e( 'Single Signon', $this->textdomain ) ?></h3>

						<p><?php _e( 'The Single Sign-on feature synchronize login cookies on all the domains.', $this->textdomain ) ?></p>
						<?php if( get_site_option( 'multi_domains_single_signon' ) == 'enabled' ) : ?>
							<p><a href="?page=multi-domains&single_signon=disable" class="button-secondary"><?php _e( 'Disable Single Sign-on', $this->textdomain ) ?></a></p>
						<?php else : ?>
							<p><a href="?page=multi-domains&single_signon=enable" class="button-secondary"><?php _e( 'Enable Single Sign-on', $this->textdomain ) ?></a></p>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</div>
		</div><?php
	}

	/**
	 * Enable or disable single signon
	 **/
	function switch_single_signon() {

		if( isset( $_GET['page'] ) && 'multi-domains' == $_GET['page'] && !empty( $_GET['single_signon'] ) ) {
			if( 'enable' == $_GET['single_signon'] ) {
				update_site_option( 'multi_domains_single_signon', 'enabled' );
			} else {
				update_site_option( 'multi_domains_single_signon', 'disabled' );
			}
		}
	}

	/**
	 * Process domain addition form data.
	 */
	function add_domain_post() {
		if( isset( $_POST['add_domain'] ) && !empty( $_POST['domain_name'] ) && isset( $_POST['domain_status'] ) ) {
			unset( $_POST['add_domain'] );
			$result = $this->add_domain( $_POST );
			if ( is_wp_error( $result ) ) {
				return $result->get_error_message();
			} else {
				$this->update_domains_option();
				return sprintf( __( 'The domain %s has been successfully added.', $this->textdomain ), $_POST['domain_name'] );
			}
		}
	}

	/**
	 * Domain addition form.
	 */
	function add_domain_form() {
		$sites_format = is_subdomain_install()
			? 'blogname.domain1.com, blogname.domain2.com'
			: 'domain1.com/blogname, domain2.com/blogname';

		$submit_url = add_query_arg( array(
			'edit' => false,
			'name' => false,
		) );

		$description = sprintf(
			__( 'This feature allows you to set multiple domains that users can run their sites from, for example you can allow a user to run a site at %s and so on - all on this one Multisite install.', $this->textdomain ),
			$sites_format
		);

		?><h3><?php _e( 'Add Domain', $this->textdomain ) ?></h3>

		<p><?php echo $description ?></p>

		<form id="domain-add" method="post" action="<?php echo $submit_url ?>">
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><label for="domain_name"><?php _e( 'Domain Name', $this->textdomain ) ?>:</label></th>
						<td>http://<input type="text" name="domain_name" id="domain_name" title="<?php _e( 'The domain name', $this->textdomain ) ?>" /> <span class="description">(e.g.: domain.com)</span></td>
					</tr>
					<tr>
						<th scope="row"><label for="domain_status"><?php _e( 'Domain Visibility', $this->textdomain ) ?>:</label></th>
						<td>
							<select id="domain_status" name="domain_status">
								<?php
								foreach( $this->domain_status() as $status_name ) {
									echo '<option value="' . $status_name . '">' . ucfirst( $status_name ) . '</option>';
								}
								?>
							</select><br />
							<span class="description"><?php _e( 'Public: available to all users', $this->textdomain ) ?><br />
							<?php if( $this->show_restricted_domains() == true ) echo __( 'Restricted: available only to users which have special permission', $this->textdomain ) . '<br />'; ?>
							<?php _e( 'Private: available only to Super Admins', $this->textdomain ) ?></span>
						</td>
					</tr>
					<?php do_action( 'add_multi_domain_form_field' ); ?>
				</tbody>
			</table>
			<p class="submit">
				<input type="submit" class="button-primary" name="add_domain" value="<?php _e( 'Add Domain', $this->textdomain ) ?>" />
			</p>
		</form>
<?php
	}

	/**
	 * Process domain edition form data.
	 */
	function edit_domain_post() {
		if( isset( $_POST['edit_domain'] ) && isset( $_POST['domain_name'] ) && isset( $_POST['domain_status'] ) ) {
			$result = $this->update_domain( $_POST );
			if ( is_wp_error( $result ) ) {
				return $result->get_error_message();
			} else {
				$this->update_domains_option();
				return sprintf( __( "The domain %s has been successfully modified.", $this->textdomain ), $_POST['domain_name'] );
			}
		}
	}

	/**
	 * Domain edition form.
	 */
	function edit_domain_form() {
		if( !isset( $_GET['name'] ) ) {
			return;
		}

		$domain = $this->get_domain( $_GET['name'] );
		$submit_url = add_query_arg( array(
			'edit' => false,
			'name' => false,
		) );

		?><h3><?php _e( 'Edit Domain', $this->textdomain ) ?></h3>

		<form id="domain-edit" method="post" action="<?php echo $submit_url ?>">
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><label for="domain_name"><?php _e( 'Domain Name', $this->textdomain ) ?>:</label></th>
						<td>
							http://<span title="<?php _e( 'The domain name', $this->textdomain ) ?>"><?php echo esc_html( $domain['domain_name'] ) ?></span>
							<input type="hidden" name="domain_name" value="<?php echo esc_attr( $domain['domain_name'] ) ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="domain_status"><?php _e( 'Domain Visibility', $this->textdomain ) ?>:</label></th>
						<td>
							<select id="domain_status" name="domain_status">
								<?php
								foreach( $this->domain_status() as $status_name ) {
									echo '<option value="' . $status_name . '" ' . selected( $domain['domain_status'], $status_name ) . '>' . $status_name . '</option>';
								}
								?>
							</select>
						</td>
					</tr>
					<?php do_action( 'edit_multi_domain_form_field', $domain ); ?>
				</tbody>
			</table>
			<p class="submit">
				<input type="submit" class="button-primary" name="edit_domain" value="<?php _e( 'Save Domain', $this->textdomain ) ?>" />
			</p>
		</form><?php
	}

	/**
	 * Process domain deletion form data.
	 */
	function delete_domain_post() {
		if( isset( $_GET['delete'] ) && isset( $_GET['name'] ) ) {
			$result = $this->delete_domain( $_GET['name'] );
			if ( is_wp_error( $result ) ) {
				return $result->get_error_message();
			} else {
				$this->update_domains_option();
				return sprintf( __( "The domain %s has been successfully deleted.", $this->textdomain ), $_GET['name'] );
			}
		}
	}

	/**
	 * Process multiple domains deletion form data.
	 */
	function delete_domains_post() {
		if ( filter_input( INPUT_POST, 'action' ) == 'deletedomains' || filter_input( INPUT_POST, 'action2' ) == 'deletedomains' ) {
			$domains = $_POST['domains'];
			if ( !empty( $domains ) ) {
				foreach ( $domains as $domain ) {
					$this->delete_domain( $domain );
				}
				$this->update_domains_option();
				return sprintf( __( "The domains have been successfully deleted.", $this->textdomain ) );
			}
		}
	}

	/**
	 * Modify $current_site global.
	 */
	function modify_current_site( $value = '' ) {
		global $current_site;
		$this->current_site = $current_site;

		/*
		// Old, unreliable, no idea why it's doing this, don't do this
		if( empty( $value ) ) {
			$value->domain = '';
		}
		$current_site->domain = $value->domain;
		*/

		if (is_object($value) && isset($value->domain)) $current_site->domain = $value->domain;
		else $current_site->domain = '';
	}

	/**
	 * Restore $current_site global.
	 */
	function restore_current_site() {
		global $current_site;
		$current_site = $this->current_site;
		$this->current_site = '';
	}

	/**
	 * Set $current_site and $domain globals with values chosen by the user.
	 */
	function set_registration_globals( $blogname = '' ) {
		global $domain, $current_site;

		if ( isset( $_POST['domain'] ) ) {
			$current_site->domain = $_POST['domain'];
			if ( is_subdomain_install() && is_user_logged_in() ) {
				$blogname = isset( $_POST['blogname'] ) ? $_POST['blogname'] : '';
			}

			$domain = $_POST['domain'];

			//$this->modify_current_site( $current_site );
			// This is just wrong on so many levels... but it seems to get the job done.
			add_action( 'check_admin_referer', create_function(
				'$action,$domain="' . $current_site->domain . '"',
				'global $current_site; if ("add-site" == $action || "add-blog" == $action) $current_site->domain=$domain;'
			), 10, 1 );

			add_filter( 'redirect_network_admin_request', '__return_false' );
		}

		return $blogname;
	}

	/**
	 * Set $current_site and $domain globals with values chosen by the user. Used when the user has already a blog.
	 */
	function set_other_registration_globals( $meta = '' ) {
		$this->set_registration_globals();

		global $domain;

		if( isset( $_POST['domain'] ) && is_subdomain_install() && is_user_logged_in() ) {
			$blogname = isset( $_POST['blogname'] ) ? $_POST['blogname'] : '';
			$domain = $blogname . '.' . $_POST['domain'];
		}

		return $meta;
	}

	/**
	 * Set $current_site and $domain globals with values chosen by the user.
	 */
	function wpmuadminedit() {
		global $current_site;

		if( isset( $_POST['domain'] ) && $_GET['action'] == 'addblog' )
			$current_site->domain = $_POST['domain'];
	}

	/**
	 * Add domain choice to signup form.
	 */
	function extend_signup_blogform() {
		wp_enqueue_script( 'jquery' );
		if ( !defined( 'MD_DEQUEUE_PLACEMENT' ) || !MD_DEQUEUE_PLACEMENT ) {
			wp_enqueue_script( 'md-placement', plugins_url( '/js/placement.js', __FILE__ ), array( 'jquery' ) );
			wp_localize_script( 'md-placement', 'l10nMd', array( 'your_address' => __( 'Your address will be', $this->textdomain ) ) );
		}

		if ( count( $domains = $this->domains ) > 1 ) {
			$primary = wp_list_filter( $domains, array( 'domain_name' => DOMAIN_CURRENT_SITE ) );
			$else = wp_list_filter( $domains, array( 'domain_name' => DOMAIN_CURRENT_SITE ), 'NOT' );

			$domains = array_merge( $primary, $else );

			$super_admin = is_super_admin();
			$show_restricted_domains = $this->show_restricted_domains();
			$posted_domain = isset( $_POST['domain'] ) ? $_POST['domain'] : '';

			echo '<select id="domain" name="domain">';
			foreach ( $domains as $domain ) {
				if ( $super_admin || ( $domain['domain_status'] == 'restricted' && $show_restricted_domains ) || $domain['domain_status'] != 'private' ) {
					echo '<option value="', $domain['domain_name'], '"', selected( $domain['domain_name'], $posted_domain, false ), '>', $domain['domain_name'], '</option>';
				}
			}
			echo '</select>';
		} else {
			echo '<span id="domain">', $domains[0]['domain_name'], '<input type="hidden" name="domain" value="', $domains[0]['domain_name'], '"></span>';
		}
	}

	/**
	* Adds domain choice to the Super Admin New Site form
	*/
	function extend_admin_blogform() {
		global $pagenow, $wp_version;

		$sites_page = version_compare( $wp_version, '3.0.9', '>' ) ? 'site-new.php' : 'ms-sites.php';
		if( $sites_page !== $pagenow || ( isset( $_GET['action'] ) && 'editblog' == $_GET['action'] ) ) {
			return;
		}

		if( is_subdomain_install() ) {
?>
			<script type="text/javascript">
			jQuery(document).ready(function() {
				domain_input = jQuery('.form-table:last td:first input');
				jQuery('.form-table:last td:first')
					.text('')
					.append(domain_input)
					.append('.<?php $this->extend_signup_blogform(); ?>' + '<p><?php _e( 'Only the characters a-z and 0-9 recommended.', $this->textdomain ) ?></p>');
			});
			</script>
		<?php
		} else {
?>
			<script type="text/javascript">
			jQuery(document).ready(function() {
				domain_input = jQuery('.form-table:last td:first input');
				jQuery('.form-table:last td:first')
					.text('')
					.append('<?php $this->extend_signup_blogform(); ?>/')
					.append(domain_input)
					.append('<p><?php _e( 'Only the characters a-z and 0-9 recommended.', $this->textdomain ) ?></p>');
			});
			</script>
		<?php
		}

	}

	/**
	 * Show restricted domains.
	 */
	function show_restricted_domains() {

		return apply_filters( 'md_show_restricted_domains', 0 );

	}

	/**
	 * Enqueue jquery file.
	 */
	function enqueue_jquery() {
		$bp_blogs_slug = defined( 'BP_BLOGS_SLUG' ) && $_SERVER['REQUEST_URI'] == '/' . BP_BLOGS_SLUG . '/create/';
		$bp_register_slug = defined( 'BP_REGISTER_SLUG' ) && $_SERVER['REQUEST_URI'] == '/' . BP_REGISTER_SLUG . '/';

		if ( $bp_blogs_slug || $bp_register_slug || function_exists( 'signuppageheaders' ) ) {
			wp_enqueue_script('jquery');
		}
	}

	/**
	 * Enqueue signup javascript file.
	 */
	function enqueue_signup_javascript() {

		if( defined( 'BP_BLOGS_SLUG' ) && $_SERVER['REQUEST_URI'] == '/' . BP_BLOGS_SLUG . '/create/' ) {

			wp_enqueue_script('jquery');
?>
			<script type="text/javascript">
			jQuery(document).ready(function($) {
				if( $('.suffix_address').length ) {
					$('.suffix_address')
						.html('.')
						.append($('#domain'))
						.append('/');
				} else if( $('.prefix_address').length ) {
					$('.prefix_address')
						.html('')
						.append($('#domain'))
						.append('/');
				}
			});
			</script>
<?php
		} elseif( defined( 'BP_REGISTER_SLUG' ) && $_SERVER['REQUEST_URI'] == '/' . BP_REGISTER_SLUG . '/' ) {

			wp_enqueue_script('jquery');

			if( is_subdomain_install() ) {
?>
				<script type="text/javascript">
				jQuery(document).ready(function($) {
						$('#blog-details').contents().filter(function(){ return this.nodeType == 3 || this.nodeName == "BR" ; }).remove();
						$('#signup_blog_url').before('http://');
						$('#signup_blog_url').after($('#domain'));
						$('#signup_blog_url').after('.');
				});
				</script>
<?php
			} else {
?>
				<script type="text/javascript">
				jQuery(document).ready(function($) {
						$('#blog-details').contents().filter(function(){ return this.nodeType == 3 || this.nodeName == "BR" ; }).remove();
						$('#signup_blog_url').before('http://');
						$('#signup_blog_url').before($('#domain'));
						$('#signup_blog_url').before('/');
				});
				</script>
<?php
			}

		} elseif( function_exists( 'signuppageheaders' ) ) {

			wp_enqueue_script('jquery');
?>
			<script type="text/javascript">
			jQuery(document).ready(function($) {
				if( $('.suffix_address').length ) {
					$('.suffix_address')
						.html('.')
						.append($('#domain'))
						.append('/');
				} else if( $('.prefix_address').length ) {
					$('.prefix_address')
						.html('')
						.append($('#domain'))
						.append('/');
				}
				$('#blogname').css('width', '66%');
			});
			</script>
<?php
		}
	}

	/**
	 * Log user out if value set in database.
	 */
	function maybe_logout_user() {
		$dom = str_replace( '.', '', $_SERVER['HTTP_HOST'] );
		$key = (array)get_site_option( "multi_domains_cross_domain_$dom" );

		if ( is_user_logged_in() ) {
			$user_id = get_current_user_id();
			if ( array_key_exists( $user_id, $key ) && $key[$user_id]['action'] == 'logout' ) {
				wp_clear_auth_cookie();

				delete_transient( "multi_domains_{$dom}_{$user_id}" );

				unset( $key[$user_id] );
				update_site_option( "multi_domains_cross_domain_$dom", $key );

				$referer = wp_get_referer();
				$proto = is_ssl() ? 'https://' : 'http://';
				$redirect = ( strpos( $_SERVER['REQUEST_URI'], '/options.php' ) && $referer ) ? $referer : $proto . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

				wp_redirect( wp_login_url( $redirect, true ) );
				exit();
			}
		}
	}

	/**
	 * Build logout cookie.
	 */
	function build_logout_cookie( $action ) {

		$dom = str_replace( '.', '', $_SERVER[ 'HTTP_HOST' ] );
		$key = (array)get_site_option( "multi_domains_cross_domain_$dom" );

		if ( 'log-out' == $action ) {
			$this->build_cookie( 'logout' );

			$user_id = get_current_user_id();
			unset( $key[$user_id] );
			update_site_option( "multi_domains_cross_domain_$dom", $key );
		}
	}

	/**
	 * Build login cookie.
	 */
	function build_cookie( $action = 'login' ) {
		$blogs = get_blogs_of_user( get_current_user_id() );
		if ( is_array( $blogs ) ) {
			foreach ( $blogs as $val ) {
				$this->build_blog_cookie( $action, $val->userblog_id );
			}
		}

	}

	/**
	 * Build login cookie.
	 */
	function build_blog_cookie( $action = 'login', $userblog_id = ''  ) {

		global $blog_id;

		if( $action == '' ) $action = 'login';

		$url = false;

		if ( class_exists( 'domain_map' ) && defined( 'DOMAIN_MAPPING' ) ) {
			$domain = $this->db->get_var( "SELECT domain FROM {$this->db->dmtable} WHERE blog_id = '{$userblog_id}' ORDER BY id LIMIT 1" );
			if($domain) {
				$dom = str_replace( '.', '', $domain );
				$url = 'http://' . $domain . '/';
			}
		} else {
			$domains = $this->db->get_row( "SELECT domain, path FROM {$this->db->blogs} WHERE blog_id = '{$userblog_id}' LIMIT 1" );
			$dom = str_replace( '.', '', $domains->domain );
			$url = 'http://' . $domains->domain . $domains->path;
		}

		if( $url ) {
			$key = get_site_option( "multi_domains_cross_domain_$dom", array() );

			$user_id = get_current_user_id();

			if( ! isset( $key[$user_id]['action'] ) || ( isset( $key[$user_id]['action'] ) && $key[$user_id]['action'] !== $action ) ) {
				$key[$user_id] = array (
					'domain'	=> $url,
					'action'	=> $action
				);

				update_site_option( "multi_domains_cross_domain_$dom", $key );
			}

			$hash = md5( AUTH_KEY . 'multi_domains' );

			if ( $blog_id !== $userblog_id && 'login' == $action /*&& get_transient( "multi_domains_{$dom}_{$user_id}" ) !== 'add'*/ ) { // Removing transient check
				echo '<link rel="stylesheet" href="' . $url . $hash . '.css?build=' . date( "Ymd", strtotime( '-24 days' ) ) . '&id=' . $user_id .'" type="text/css" media="screen" />';

				set_transient( "multi_domains_{$dom}_{$user_id}", 'add', 60 * 15 );
			}
		}


	}

	/**
	 * Build stylesheet for cookie.
	 */
	function build_stylesheet_for_cookie() {
		if( isset( $_GET['build'] ) && addslashes( $_GET['build'] ) == date( "Ymd", strtotime( '-24 days' ) ) ) {
			header("Content-type: text/css");
			//echo "/* Sometimes me think what is love, and then me think love is what last cookie is for. Me give up the last cookie for you. */";
			define( 'DONOTCACHEPAGE', 1 ); // don't let wp-super-cache cache this page.

			// We have a stylesheet with a build and a matching date - so grab the hash
			$url = parse_url( $_SERVER[ 'REQUEST_URI' ], PHP_URL_PATH );
			$dom = str_replace( '.', '', $_SERVER[ 'HTTP_HOST' ] );
			$hash_sent = str_replace( '.css', '', basename( $url ) );
			$hash = md5( AUTH_KEY . 'multi_domains' );

			$user_id = $_GET['id'];

			$key = get_site_option( "multi_domains_cross_domain_$dom" );

			if( array_key_exists( $user_id, (array) $key ) && !is_user_logged_in() && $hash_sent == $hash ) {
				// Set the cookies
				switch( $key[$user_id]['action'] ) {
					case 'login':
						wp_set_auth_cookie( $user_id );
						break;
					default:
						break;
				}
			}

			die();
		}
	}

	/**
	 * Upgrades sunrise.php file if need be.
	 */
	function upgrade_sunrise() {
		// if sunrise.php has the latest version, then return
		$defined = defined( 'MULTIDOMAINS_SUNRISE_VERSION' );
		if ( $defined && version_compare( MULTIDOMAINS_SUNRISE_VERSION, $this->sunrise, '=' ) ) {
			return;
		}

		$global_sunrise = WP_CONTENT_DIR . '/sunrise.php';
		$local_sunrise = dirname( __FILE__ ) . '/sunrise.php';

		// return if local sunrise.php file is not readable
		if ( !is_readable( $local_sunrise ) ) {
			return;
		}

		// copy new sunrise.php file or upgrade existing one
		if ( file_exists( $global_sunrise ) ) {
			// return if we can't write into sunrise.php file
			if ( !is_writable( $global_sunrise ) ) {
				return;
			}

			$global_content = file_get_contents( $global_sunrise );
			$local_content = file_get_contents( $local_sunrise );
			$pattern = sprintf( '/%s.*?%s/is', preg_quote( 'function multi_domains_sunrise()', '/' ), preg_quote( 'multi_domains_sunrise();', '/' ) );

			// files is already exists, update it
			if ( $defined ) {
				// if version was defined but is deprecated, then replace old content on new content
				if ( preg_match( $pattern, $local_content, $matches ) ) {
					$global_content = preg_replace( $pattern, $matches[0], $global_content );
				}
			} else {
				// version wasn't defined, so check if domain mapping was defined
				if ( preg_match( "/\'DOMAIN_MAPPING\'/m", $global_content ) ) {
					// check if it is old version of dm_sunrise.php file
					if ( preg_match( "/\'md_domains\'/m", $global_content ) ) {
						$global_content = $local_content;
					} elseif ( preg_match( $pattern, $local_content, $matches ) ) {
						$global_content .= PHP_EOL . PHP_EOL . $matches[0];
					}
				} else {
					$global_content = $local_content;
				}
			}

			file_put_contents( $global_sunrise, $global_content );

		// copy new sunrise.php if we can write into wp-content directory
		} elseif ( is_writable( WP_CONTENT_DIR ) ) {
			@copy( $local_sunrise, $global_sunrise );
		}
	}

}


$multi_dm = new multi_domain();