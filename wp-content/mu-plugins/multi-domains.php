<?php
/*
Plugin Name: Multi-Domains for Multisite
Plugin URI: http://premium.wpmudev.org/project/multi-domains/
Description: Easily allow users to create new sites (blogs) at multiple different domains - using one install of WordPress Multisite you can support blogs at name.domain1.com, name.domain2.com etc.
Version: 1.0.9
Network: true
Text Domain: multi_domain
Author: Ulrich SOSSOU (Incsub)
Author URI: http://ulrichsossou.com
WDP ID: 154
*/

/*  Copyright 2010 Incsub (http://incsub.com/)
    Based on work by Joe Jacobs (http://joejacobs.org/), Barry Getty (http://caffeinatedb.com/) and Donncha (http://ocaoimh.ie/)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

class multi_domain {

  /**
  * @var string $textdomain Domain used for localization
  */
  var $textdomain = "multi_domain";

  /**
  * @var string $version Plugin version
  */
	var $version = '1.0.9';

  /**
  * @var string $pluginpath Path to plugin files
  */
	var $pluginpath;

  /**
  * @var string $pluginurl Plugin directory url
  */
	var $pluginurl;

  /**
  * @var object $db Database object
  */
	var $db;

  /**
  * @var object $current_site Temporary stores $current_site global
  */
	var $current_site;

  /**
  * @var array $domains Domains list
  */
	var $domains = array();

  /**
  * PHP5 constructor
  */
	function __construct() {

		global $wpdb;

		$this->db =& $wpdb;

		// Set plugin default options if the plugin was not installed before
		add_action( 'init', array( &$this, 'activate_plugin' ) );

		// Redirect from custom domain to network homepage
		add_action( 'init', array( &$this, 'domain_redirect' ) );

		// Run plugin functions
		add_action( 'init', array( &$this, 'setup_plugin' ) );

		// Add in the cross domain logins
		add_action( 'init', array( &$this, 'build_stylesheet_for_cookie' ) );

		// Setup plugin path and url
		if ( defined( 'WPMU_PLUGIN_DIR' ) && file_exists( WPMU_PLUGIN_DIR . '/multi-domains.php' ) ) {
			$this->pluginpath = WPMU_PLUGIN_DIR . '/multi-domains-files/';
			$this->pluginurl = WPMU_PLUGIN_URL . '/multi-domains-files/';
		} else {
			$this->pluginpath = WP_PLUGIN_DIR . '/multi-domains/multi-domains-files/';
			$this->pluginurl = WP_PLUGIN_URL . '/multi-domains/multi-domains-files/';
		}

	}

  /**
  * PHP4 constructor
  */
	function multi_domain() {
		$this->__construct();
	}


	/**
	 * Run on plugin activation.
	 */
	function activate_plugin() {

		$version = get_site_option( 'md_version' );

		if( empty( $version ) ) {
			$domains = array(
				array(
					'domain_name'   => DOMAIN_CURRENT_SITE,
					'domain_status' => 'public'
				)
			);

			update_site_option( 'md_domains', apply_filters( 'md_default_domains', $domains ) );
			update_site_option( 'md_version', $this->version );
		}

	}


	/**
	 * Run plugin functions.
	 */
	function setup_plugin() {

		$this->handle_translation();

		$this->domains = get_site_option( 'md_domains' );

		// Add the super admin page
		add_action( 'admin_menu', array( &$this, 'add_page' ) );

		// Enqueue javascript
		$this->enqueue_jquery();
		add_action( 'wp_head', array( &$this, 'enqueue_signup_javascript' ) );

		// Empty $current_site global on signup page
		add_action( 'signup_hidden_fields', array( &$this, 'modify_current_site' ) );
		// Populate $current_site, $domain and $base globals on signup page with values choosen by the user on signup page
		add_filter( 'newblogname', array( &$this, 'set_registration_globals' ) );
		add_filter( 'add_signup_meta', array( &$this, 'set_other_registration_globals' ) );
		// Restore default $current_site global on signup page
		add_action( 'signup_finished', array( &$this, 'restore_current_site' ) );

		// Populate $current_site on admin edit page
		add_action( 'wpmuadminedit', array( &$this, 'wpmuadminedit' ) );

		// Extends blog registration forms
		add_action( 'signup_blogform', array( &$this, 'extend_signup_blogform' ) ); // default and buddypress blog creation forms
		add_action( 'bp_signup_blog_url_errors', array( &$this, 'extend_signup_blogform' ) ); // buddypress signup form
		add_action( 'admin_footer', array( &$this, 'extend_admin_blogform' ) ); // admin site creation form

		// Cross domain cookies
		add_action( 'admin_head', array( &$this, 'build_cookie' ) );
		add_action( 'login_head', array( &$this, 'build_logout_cookie' ) );

		// modify blog columns on Super Admin > Sites page
		add_filter( 'wpmu_blogs_columns', array( &$this, 'blogs_columns' ) );
		add_action( 'manage_blogs_custom_column', array( &$this, 'manage_blogs_custom_column' ), 10, 2 );

	}


	/**
	 * Load the translated strings from the languages directory.
	 */
	function handle_translation() {

		if ( defined( 'WPMU_PLUGIN_DIR' ) && file_exists( WPMU_PLUGIN_DIR . '/multi-domains.php' ) ) {
			load_muplugin_textdomain( $this->textdomain, 'multi-domains-files/languages' );
		} else {
			load_plugin_textdomain( $this->textdomain, false, dirname( plugin_basename( __FILE__ ) ) . '/multi-domains-files/languages' );
		}

	}


	/**
	 * Add super admin page.
	 */
	function add_page() {

		add_submenu_page( 'ms-admin.php', __( 'Multi-Domains', $this->textdomain ), __( 'Multi-Domains', $this->textdomain ), 'manage_network', 'multi-domains', array( &$this, 'management_page' ) );

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

		if( !is_subdomain_install() ) {
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
			foreach( $domain as $key=>$value ) {
				if( !isset( $sort_array[$key] ) ) {
					$sort_array[$key] = array();
				}
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
			$domains[] = $domain;
			$this->domains = apply_filters( 'md_add_domain', $domains );
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
				$domains[$i]['domain_status'] = $domain['domain_status'];
				$domain_exists = 1;
				break;
			}
		}

		if( !empty( $domain_exists ) ) {
			$this->domains = apply_filters( 'md_update_domain', $domains );
			return true;
		} else {
			return new WP_Error( 'md_domain_not_found', sprintf( __( "The domain %s was not found in the list.", $this->textdomain ), $domain['domain_name'] ) );
		}

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

		if ( !function_exists( 'is_super_admin' ) || !is_super_admin() )
			wp_die( '<p>' . __( 'You do not have permission to access this page.', $this->textdomain ) . '</p>' );

			if ( isset( $_POST['add_domain'] ) && isset( $_POST['domain_name'] ) && isset( $_POST['domain_status'] ) ):
				if( $message = $this->add_domain_post() )
					echo '<div id="message" class="updated fade"><p>' . $message . '</p></div>';
				else
					echo '<div id="message" class="error"><p>' . __( 'Error : the domain was not added', $this->textdomain ) . '</p></div>';

			elseif ( isset( $_POST['edit_domain'] ) && isset( $_POST['domain_name'] ) && isset( $_POST['domain_status'] ) ):
				if( $message = $this->edit_domain_post() )
					echo '<div id="message" class="updated fade"><p>' . $message . '</p></div>';
				else
					echo '<div id="message" class="error"><p>' . __( 'Error : the domain was not saved', $this->textdomain ) . '</p></div>';

			elseif ( isset( $_GET['delete'] ) && isset( $_GET['name'] ) ):
				if( $message = $this->delete_domain_post() )
					echo '<div id="message" class="updated fade"><p>' . $message . '</p></div>';
				else
					echo '<div id="message" class="error"><p>' . __( 'Error : the domain was not deleted', $this->textdomain ) . '</p></div>';

			elseif ( isset( $_POST['action'] ) && $_POST['action'] == 'deletedomains' ):
				if( $message = $this->delete_domains_post() )
					echo '<div id="message" class="updated fade"><p>' . $message . '</p></div>';
				else
					echo '<div id="message" class="error"><p>' . __( 'Error : the domains were not deleted', $this->textdomain ) . '</p></div>';

			endif;
?>

<div class="wrap" style="position: relative">

<?php
			$action = isset( $_GET['action'] ) ? $_GET['action'] : '';
			switch( $action ) {
				default:
?>

	<div id="icon-ms-admin" class="icon32"></div>
	<h2><?php _e ( 'Domains', $this->textdomain ) ?></h2>
	<?php
	if ( !file_exists( ABSPATH . '/wp-content/sunrise.php' ) ) {
		echo '<div id="message" class="error"><p>' . sprintf( __( "Please copy the sunrise.php to %swp-content/sunrise.php and uncomment the SUNRISE setting in the %swp-config.php file.", $this->textdomain ), ABSPATH, ABSPATH ) . '</p></div>';
	}

	if ( !defined( 'SUNRISE' ) ) {
		echo '<div id="message" class="error"><p>' . sprintf( __( "Please uncomment the line <em>//define( 'SUNRISE', 'on' );</em> in the %swp-config.php file.", $this->textdomain ), ABSPATH ) . '</p></div>';
	}
	?>

	<div id="col-container">

		<div id="col-right">

			<form method="post" action="ms-admin.php?page=multi-domains" name="formlist">
				<input type="hidden" name="action" value="deletedomains" />
				<div class="tablenav">
					<p class="alignleft">
						<input type="submit" value="<?php _e( 'Delete', $this->textdomain ) ?>" name="delete_domains" class="button-secondary delete">
					</p>
				</div>
				<br class="clear">
				<table cellspacing="3" cellpadding="3" width="100%" class="widefat">
					<thead>
						<tr>
							<th class="column-cb check-column" scope="col"><input type="checkbox" id="select_all"></th>
							<th scope="col"><?php _e ( 'Domain', $this->textdomain ) ?></th>
							<th scope="col"><?php _e ( 'Status', $this->textdomain ) ?></th>
						</tr>
					</thead>
					<tfoot>
						<tr>
							<th class="column-cb check-column" scope="col"><input type="checkbox" id="select_all"></th>
							<th scope="col"><?php _e ( 'Domain', $this->textdomain ) ?></th>
							<th scope="col"><?php _e ( 'Status', $this->textdomain ) ?></th>
						</tr>
					</tfoot>
					<tbody>
<?php if( !empty( $this->domains ) ): foreach( $this->domains as $domain ): ?>
						<tr>
							<th style="width: auto;" class="check-column" scope="row"><input type="checkbox" value="<?php echo $domain['domain_name'] ?>" name="domains[]" id="2"><label for="2"></label></th>
							<td><?php echo $domain['domain_name'] ?>
								<div class="row-actions">
									<a title="<?php _e ( 'Edit this domain', $this->textdomain ) ?>" href="ms-admin.php?page=multi-domains&edit=1&name=<?php echo $domain['domain_name'] ?>&status=<?php echo $domain['domain_status'] ?>" class="edit">Edit</a> | <a title="<?php _e ( 'Delete this domain', $this->textdomain ) ?>" href="ms-admin.php?page=multi-domains&delete=1&name=<?php echo $domain['domain_name'] ?>" class="delete">Delete</a>
								</div>
							</td>
							<td><?php echo $domain['domain_status'] ?></td>
						</tr>
<?php endforeach; endif; ?>
					</tbody>
				</table>
				<div class="tablenav">
					<div class="alignleft">
						<input type="submit" value="<?php _e ( 'Delete', $this->textdomain ) ?>" name="domain-delete" class="button-secondary delete">
					</div>
				</div>
				<?php
				if( is_subdomain_install() ):
				echo '<p>In the DNS records for each domain added here, add a wildcard subdomain that points to this WordPress installation.<br />It should look like: <strong>A *.domain.com</strong></p>';

				if( !empty( $this->domains ) ): foreach( $this->domains as $domain ): if( $domain['domain_name'] !== DOMAIN_CURRENT_SITE ):

				$result = '';
				if (false === ( $result = get_transient( 'wp_hostname_' . $domain['domain_name'] ) ) ) {
					$host_ok = false;
					$hostname = substr( md5( time() ), 0, 6 ) . '.' . $domain['domain_name']; // Very random hostname!
					$page = wp_remote_get( 'http://' . $hostname, array( 'timeout' => 5, 'httpversion' => '1.1' ) );
					if ( is_wp_error( $page ) )
						$errstr = $page->get_error_message();
					else
						$host_ok = true;

					if ( $host_ok == false ) {
						$result = '<div class="error"><p><strong>' . sprintf( __( 'Warning! Wildcard DNS for %s may not be configured correctly!', $this->textdomain ), $domain['domain_name'] ) . '</strong></p>';
						$result .= '<p>' . sprintf( __( 'Unable to contact the random hostname (<code>%s</code>).', $this->textdomain ), $hostname );
						if ( ! empty ( $errstr ) )
							echo ' ' . sprintf( __( 'This resulted in an error message: %s', $this->textdomain ), '<code>' . $errstr . '</code>' );
						$result .= '</p></div>';
					}
					set_transient( 'wp_hostname_' . $domain['domain_name'] , $result);
				}
				echo $result;

				endif; endforeach; endif;

				else:
				echo '<p>Change the DNS records for each domain to point to this WordPress installation.</p>';
				endif;
				?>
			</form>

		</div><!-- #col-right -->

		<div id="col-left">

		<?php
		if( isset( $_GET['edit'] ) ) {
			$this->edit_domain_form();
		} else {
			$this->add_domain_form();
		}
		?>

		</div><!-- #col-left -->

	</div><!-- #col-container -->

<?php
			break;
		}
?>

</div><!-- .wrap -->

<?php
	}


	/**
	 * Process domain addition form data.
	 */
	function add_domain_post() {
		if( isset( $_POST['add_domain'] ) && isset( $_POST['domain_name'] ) && isset( $_POST['domain_status'] ) ) {
			unset( $_POST['add_domain'] );
			$result = $this->add_domain( $_POST );
			if ( is_wp_error( $result ) ) {
				return $result->get_error_message();
			} else {
				$this->update_domains_option();
				return sprintf( __( "The domain %s has been successfully added.", $this->textdomain ), $_POST['domain_name'] );
			}
		}
	}


	/**
	 * Domain addition form.
	 */
	function add_domain_form() {
		if( is_subdomain_install() ) {
			$sites_format = 'blogname.domain1.com, blogname.domain2.com';
		} else {
			$sites_format = 'domain1.com/blogname, domain2.com/blogname';
		}
		echo '<p>' . sprintf( __( 'This feature allows you to set multiple domains that users can run their sites from, for example you can allow a user to run a site at %s and so on - all on this one Multisite install.', $this->textdomain ), $sites_format ) . '</p>';
?>

		<h3><?php _e( 'Add Domain', $this->textdomain ) ?></h3>

		<form id="domain-add" method="post" action="ms-admin.php?page=multi-domains&action=adddomain">
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><label for="domain_name"><?php _e( 'Domain Name', $this->textdomain ) ?>:</label></th>
						<td>http://<input type="text" name="domain_name" id="domain_name" title="<?php _e( 'The domain name', $this->textdomain ) ?>" /> <span class="description">(e.g.: domain.com)</span></td>
					</tr>
					<tr>
						<th scope="row"><label for="domain_status"><?php _e( 'Domain Status', $this->textdomain ) ?>:</label></th>
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
				</tbody>
			</table>
			<p class="submit">
				<input type="submit" class="button" name="add_domain" value="<?php _e( 'Add Domain', $this->textdomain ) ?>" />
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
?>
		<h3><?php _e( 'Edit Domain', $this->textdomain ) ?></h3>

		<form id="domain-edit" method="post" action="ms-admin.php?page=multi-domains&action=editdomain">
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><label for="domain_name"><?php _e( 'Domain Name', $this->textdomain ) ?>:</label></th>
						<td>http://<input type="text" name="domain_name" id="domain_name" title="<?php _e( 'The domain name', $this->textdomain ) ?>" value="<?php echo isset($_GET['name']) ? $_GET['name'] : ''  ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="domain_status"><?php _e( 'Domain Status', $this->textdomain ) ?>:</label></th>
						<td>
							<select id="domain_status" name="domain_status">
								<?php
								$status = isset($_GET['status']) ? $_GET['status'] : '';
								foreach( $this->domain_status() as $status_name ) {
									echo '<option value="' . $status_name . '" ' . selected( $status, $status_name ) . '>' . $status_name . '</option>';
								}
								?>
							</select>
						</td>
					</tr>
				</tbody>
			</table>
			<p class="submit">
				<input type="submit" class="button" name="edit_domain" value="<?php _e( 'Save Domain', $this->textdomain ) ?>" />
			</p>
		</form>
<?php
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
		if( isset( $_POST['action'] ) && $_POST['action'] == 'deletedomains' ) {
			$domains = $_POST['domains'];
			if( !empty( $domains ) ) {
				foreach( $domains as $domain ) {
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
		if( empty( $value ) ) {
			$value->domain = '';
		}
		$current_site->domain = $value->domain;
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
		global $domain;

		if( isset( $_POST['domain'] ) ) {

			$current_site->domain = $_POST['domain'];

			if( is_subdomain_install() && is_user_logged_in() ) {

					$blogname = isset( $_POST['blogname'] ) ? $_POST['blogname'] : '';

			}

			$domain = $_POST['domain'];

			$this->modify_current_site( $current_site );

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
		if( count( $domains = $this->domains ) > 1 ) {
			echo '<select id="domain" name="domain">';
				foreach( $domains as $domain ) {
					if( is_super_admin() || ( $domain['domain_status'] == 'restricted'  && $this->show_restricted_domains() == true ) || $domain['domain_status'] !== 'private' ) {
						$selected = ( isset( $_POST['domain'] ) && ( $_POST['domain'] == $domain['domain_name'] ) ) ? ' selected="selected"' : '';
						echo '<option value="' . $domain['domain_name'] . '"' . $selected . '>' . $domain['domain_name'] . '</option>';
					}
				}
			echo '</select>';
		} else {
			echo '<span id="domain">' . $domains[0]['domain_name'] .'<input type="hidden" name="domain" value="' . $domains[0]['domain_name'] .'" /></span>';
		}
	}

	/**
	* Adds domain choice to the Super Admin New Site form
	*/
	function extend_admin_blogform() {
		global $pagenow;

		if( $pagenow !== 'ms-sites.php' || isset( $_GET['action'] ) && $_GET['action'] !== 'list' )
			return;

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

		if( defined( 'BP_BLOGS_SLUG' ) && $_SERVER['REQUEST_URI'] == '/' . BP_BLOGS_SLUG . '/create/' ||
		 ( defined( 'BP_REGISTER_SLUG' ) && $_SERVER['REQUEST_URI'] == '/' . BP_REGISTER_SLUG . '/' ) ||
		  function_exists( 'signuppageheaders' ) ) {

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
	 * 301 redirect from custom domain to network homepage.
	 */
	function domain_redirect() {

		global $current_site;
		if( $_SERVER['REQUEST_URI'] == '/' && $_SERVER['HTTP_HOST'] !== $current_site->domain ) {
			foreach ( $this->domains as $domain ) {
				if( $_SERVER['HTTP_HOST'] == $domain['domain_name'] )
					wp_redirect( apply_filters( 'md_domain_redirect', network_home_url() ), 301 );
			}
		}

	}


	/**
	 * Build logout cookie.
	 */
	function build_logout_cookie() {

		if( isset( $_GET['loggedout'] ) ) {
			// Log out CSS
			$this->build_cookie( 'logout' );
		}

	}


	/**
	 * Build login cookie.
	 */
	function build_cookie( $action = 'login' ) {

		$user = wp_get_current_user();
		$hash = md5( AUTH_KEY . time() . 'COOKIEMONSTER' );

		$blogs = get_blogs_of_user( $user->ID );
		if ( is_array( $blogs ) ) {
			foreach ( (array) $blogs as $key => $val ) {
				$this->build_blog_cookie( $action, $hash, $val->userblog_id );
			}
		}

	}


	/**
	 * Build login cookie.
	 */
	function build_blog_cookie( $action = 'login', $hash = '', $userblog_id = ''  ) {

		global $blog_id;

		if( $action == '' ) $action = 'login';

		$url = false;

		if ( class_exists( 'domain_map' ) && defined( 'DOMAIN_MAPPING' ) ) {
			$domain = $this->db->get_var( "SELECT domain FROM {$this->db->dmtable} WHERE blog_id = '{$userblog_id}' ORDER BY id LIMIT 1" );
			if($domain) {
				$dom = $domain;
				$url = 'http://' . $domain . '/';
			}
		} else {
			$domains = $this->db->get_row( "SELECT domain, path FROM {$this->db->blogs} WHERE blog_id = '{$userblog_id}' LIMIT 1" );
			$dom = $domains->domain;
			$url = 'http://' . $domains->domain . $domains->path;
		}

		if($url) {
			$key = get_blog_option( $userblog_id, 'cross_domain', 'none' );
			if( $key == 'none' ) $key = array();

			$user = wp_get_current_user();

			if( !array_key_exists( $hash, (array) $key ) ) {
				$key[$hash.$dom] = array (
					"domain"	=> $url,
					"hash"		=> $hash,
					"user_id"	=> $user->ID,
					"action"	=> $action
				);
			}

			update_blog_option( $userblog_id, 'cross_domain', $key );

			if( $blog_id !== $userblog_id )
				echo '<link rel="stylesheet" href="' . $url . $hash . '.css?build=' . date( "Ymd", strtotime( '-24 days' ) ) . '" type="text/css" media="screen" />';
		}


	}


	/**
	 * Build stylesheet for cookie.
	 */
	function build_stylesheet_for_cookie() {

		if( isset( $_GET['build'] ) && addslashes( $_GET['build'] ) == date( "Ymd", strtotime( '-24 days' ) ) ) {
			header("Content-type: text/css");
			echo "/* Sometimes me think what is love, and then me think love is what last cookie is for. Me give up the last cookie for you. */";
			define( 'DONOTCACHEPAGE', 1 ); // don't let wp-super-cache cache this page.

			// We have a stylesheet with a build and a matching date - so grab the hash
			$url = parse_url( $_SERVER[ 'REQUEST_URI' ], PHP_URL_PATH );
			$hash = str_replace( '.css', '', basename( $url ) ) . $_SERVER[ 'HTTP_HOST' ];

			$key = get_option( 'cross_domain' );

			if( array_key_exists( $hash, (array) $key ) ) {

				if( !is_user_logged_in() ) {
					// Set the cookies
					switch( $key[$hash]['action'] ) {
						case 'login':
							wp_set_auth_cookie($key[$hash]['user_id']);
							break;
						default:
							break;
					}

				} else {
					// Set the cookies
					switch($key[$hash]['action']) {
						case 'logout':
							wp_clear_auth_cookie();
							break;
						default:
							break;
					}
				}
				$url = parse_url( $_SERVER[ 'REQUEST_URI' ], PHP_URL_HOST );
				unset( $key[$hash.$url] );
				update_option( 'cross_domain', (array) $key );
				die();
			}
			die();
		}
	}

}

$multi_dm =& new multi_domain();

?>
