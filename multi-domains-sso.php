<?php

// +----------------------------------------------------------------------+
// | Copyright Incsub (http://incsub.com/)                                |
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

/**
 * The module responsible for cross domain single sign on.
 *
 * @category Multidomain
 * @package SSO
 *
 * @since 1.3.3
 */
if( !class_exists("Multidomains_Sso") ):
class Multidomains_Sso extends multi_domain{

	const NAME = __CLASS__;

	const ACTION_KEY = '_multidomain_action';

	const ACTION_SETUP_CDSSO    = 'multidomain-setup-cdsso';
	const ACTION_AUTHORIZE_USER = 'multidomain-authorize-user';
	const ACTION_PROPAGATE_USER = 'multidomain-propagate-user';
	const ACTION_LOGOUT_USER    = 'multidomain-logout-user';

	/**
	 * Determines whether we need to propagate user to the original blog or not.
	 *
	 * @since 1.3.3
	 *
	 * @access private
	 * @var boolean
	 */
	private $_do_propagation = false;

	/**
	 * Determines whether we do logout process or not.
	 *
	 * @since 1.3.3
	 *
	 * @access private
	 * @var boolean
	 */
	private $_do_logout = false;

	/**
	 * Constructor.
	 *
	 * @since 1.3.3
	 *
	 * @access public
	 */
	public function __construct( ) {
		$this->_add_filter( 'wp_redirect', 'add_logout_marker' );
		$this->_add_filter( 'login_redirect', 'set_interim_login', 10, 3 );
		$this->_add_filter( 'login_message', 'get_login_message' );
		$this->_add_filter( 'login_url', 'update_login_url', 10, 2 );

		$this->_add_action( 'wp_head', 'add_auth_script', 0 );
		$this->_add_action( 'login_form_login', 'set_auth_script_for_login' );
		$this->_add_action( 'wp_head', 'add_logout_propagation_script', 0 );
		$this->_add_action( 'login_head', 'add_logout_propagation_script', 0 );
		$this->_add_action( 'login_footer', 'add_propagation_script' );
		$this->_add_action( 'wp_logout', 'set_logout_var' );
		$this->_add_action( 'plugins_loaded', 'authorize_user' );

		$this->_add_ajax_action( self::ACTION_SETUP_CDSSO, 'setup_cdsso', true, true );
		$this->_add_ajax_action( self::ACTION_PROPAGATE_USER, 'propagate_user', true, true );
		$this->_add_ajax_action( self::ACTION_LOGOUT_USER, 'logout_user', true, true );
	}

	/**
	 * Adds hook for login_head action if user tries to login.
	 *
	 * @since 1.3.3
	 * @action login_form_login
	 *
	 * @access public
	 */
	public function set_auth_script_for_login() {
		$this->_add_action( 'login_head', 'add_auth_script', 0 );
	}

	/**
	 * Equalizes redirect_to domain name with login URL domain.
	 *
	 * @since 1.3.3
	 * @filter login_url 10 2
	 *
	 * @param string $login_url The login URL.
	 * @param string $redirect_to The redirect URL.
	 * @return string Updated login URL.
	 */
	public function update_login_url( $login_url, $redirect_to ) {
        if( empty( $redirect_to ) )
            return $login_url;
        
		$login_domain = parse_url( $login_url, PHP_URL_HOST );
		$redirect_domain = parse_url( $redirect_to, PHP_URL_HOST );
		if ( $login_domain != $redirect_domain ) {
			$redirect_to = str_replace( "://{$redirect_domain}", "://{$login_domain}", $redirect_to );
			$login_url = add_query_arg( 'redirect_to', urlencode( $redirect_to ), $login_url );
		}

		return $login_url;
	}

	/**
	 * Sets logout var to determine logout process.
	 *
	 * @since 1.3.3
	 * @access wp_logout
	 *
	 * @access public
	 */
	public function set_logout_var() {
		$this->_do_logout = true;
	}

	/**
	 * Adds logout marker if need be.
	 *
	 * @since 1.3.3
	 * @filter wp_redirect
	 *
	 * @access public
	 * @param string $redirect_to The initial redirect URL.
	 * @return string Updated redirect URL.
	 */
	public function add_logout_marker( $redirect_to ) {
		if ( $this->_do_logout ) {
			$redirect_to = add_query_arg( self::ACTION_KEY, self::ACTION_LOGOUT_USER, $redirect_to );
		}

		return $redirect_to;
	}

	/**
	 * Adds logout propagation script if need be.
	 *
	 * @since 1.3.3
	 * @action wp_head 0
	 * @action login_head 0
	 *
	 * @access public
	 */
	public function add_logout_propagation_script() {
		if ( is_user_logged_in() || get_current_blog_id() == 1 || filter_input( INPUT_GET, self::ACTION_KEY ) != self::ACTION_LOGOUT_USER ) {
			return;
		}

		switch_to_blog( 1 );
		$url = add_query_arg( 'action', self::ACTION_LOGOUT_USER, admin_url( 'admin-ajax.php' ) );
		echo '<script type="text/javascript" src="', $url, '"></script>';
		restore_current_blog();
	}

	/**
	 * Do logout from the main blog.
	 *
	 * @since 1.3.3
	 * @action wp_ajax_multidomain-logout-user
	 * @action wp_ajax_no_priv_multidomain-logout-user
	 *
	 * @access public
	 */
	public function logout_user() {
		header( "Content-Type: text/javascript; charset=" . get_bloginfo( 'charset' ) );

		if ( !is_user_logged_in() || empty( $_SERVER['HTTP_REFERER'] ) ) {
			header( "Vary: Accept-Encoding" ); // Handle proxies
			header( "Expires: " . gmdate( "D, d M Y H:i:s", time() + MINUTE_IN_SECONDS ) . " GMT" );
			exit;
		}

		wp_clear_auth_cookie();
		$url = add_query_arg( self::ACTION_KEY, false, $_SERVER['HTTP_REFERER'] );

		echo 'window.location = "', $url, '";';
		exit;
	}

	/**
	 * Sets internim login mode.
	 *
	 * @since 1.3.3
	 * @filter login_redirect 10 3
	 *
	 * @access public
	 * @global boolean $interim_login Determines whether to show interim login page or not.
	 * @param string $redirect_to The redirection URL.
	 * @param string $requested_redirect_to The initial redirection URL.
	 * @param WP_User|WP_Error $user The user or error object.
	 * @return string The income redirection URL.
	 */
	public function set_interim_login( $redirect_to, $requested_redirect_to, $user ) {
		global $interim_login;
		if ( is_a( $user, 'WP_User' ) && get_current_blog_id() != 1 ) {
			$home = home_url( '/' );
			$current_domain = parse_url( $home, PHP_URL_HOST );
			$original_domain = parse_url( apply_filters( 'unswap_url', $home ), PHP_URL_HOST );

			if ( $current_domain != $original_domain || !in_array( $current_domain, $this->get_original_domains() ) ) {
				$interim_login = $this->_do_propagation = true;
			}
		}
		return $redirect_to;
	}

	/**
	 * Updates login message for interim login page.
	 *
	 * @since 1.3.3
	 * @filter login_message
	 *
	 * @access public
	 * @param string $message The original message.
	 * @return string The new extended login message.
	 */
	public function get_login_message( $message ) {
		return $this->_do_propagation
			? '<p class="message">' . esc_html__( 'You have logged in successfully. You will be redirected to desired page during next 5 seconds.', $this->textdomain ) . '</p>'
			: $message;
	}

	/**
	 * Adds propagation scripts at interim login page after successfull login.
	 *
	 * @since 1.3.3
	 * @access login_footer
	 *
	 * @access public
	 * @global string $redirect_to The redirection URL.
	 * @global WP_User $user Current user.
	 */
	public function add_propagation_script() {
		global $redirect_to, $user;

		if ( !$this->_do_propagation ) {
			return;
		}

		if ( ( empty( $redirect_to ) || $redirect_to == 'wp-admin/' || $redirect_to == admin_url() ) ) {
			// If the user doesn't belong to a blog, send them to user admin. If the user can't edit posts, send them to their profile.
			if ( is_multisite() && !get_active_blog_for_user( $user->ID ) && !is_super_admin( $user->ID ) ) {
				$redirect_to = user_admin_url();
			} elseif ( is_multisite() && !$user->has_cap( 'read' ) ) {
				$redirect_to = get_dashboard_url( $user->ID );
			} elseif ( !$user->has_cap( 'edit_posts' ) ) {
				$redirect_to = admin_url( 'profile.php' );
			}
		}

		echo '<script type="text/javascript">';
			echo 'function multidomain_do_redirect() { window.location = "', $redirect_to, '"; };';
			echo 'setTimeout(multidomain_do_redirect, 5000);';
		echo '</script>';


		switch_to_blog( 1 );
		$url = add_query_arg( array(
			'action' => self::ACTION_PROPAGATE_USER,
			'auth'   => wp_generate_auth_cookie( $user->ID, time() + MINUTE_IN_SECONDS ),
		), admin_url( 'admin-ajax.php' ) );
		restore_current_blog();

		echo '<script type="text/javascript" src="', $url, '"></script>';
	}

	/**
	 * Adds authorization script to the current page header.
	 *
	 * @since 1.3.3
	 * @action wp_head 0
	 * @action login_head 0
	 *
	 * @access public
	 */
	public function add_auth_script() {
		if ( is_user_logged_in() || get_current_blog_id() == 1 || filter_input( INPUT_GET, self::ACTION_KEY ) == self::ACTION_AUTHORIZE_USER ) {
			return;
		}

		switch_to_blog( 1 );
		$url = add_query_arg( 'action', self::ACTION_SETUP_CDSSO, admin_url( 'admin-ajax.php' ) );
		echo '<script type="text/javascript" src="', $url, '"></script>';
		restore_current_blog();
	}

	/**
	 * Setups CDSSO for logged in user.
	 *
	 * @since 1.3.3
	 * @action wp_ajax_multidomain-setup-cdsso
	 * @action wp_ajax_nopriv_multidomain-setup-cdsso
	 *
	 * @access public
	 */
	public function setup_cdsso() {
		header( "Content-Type: text/javascript; charset=" . get_bloginfo( 'charset' ) );

		if ( !is_user_logged_in() || empty( $_SERVER['HTTP_REFERER'] ) ) {
			header( "Vary: Accept-Encoding" ); // Handle proxies
			header( "Expires: " . gmdate( "D, d M Y H:i:s", time() + MINUTE_IN_SECONDS ) . " GMT" );
			exit;
		}

		$url = add_query_arg( array(
			self::ACTION_KEY => self::ACTION_AUTHORIZE_USER,
			'auth'           => wp_generate_auth_cookie( get_current_user_id(), time() + MINUTE_IN_SECONDS ),
		), $_SERVER['HTTP_REFERER'] );

		echo 'window.location = "', $url, '";';
		exit;
	}

	/**
	 * Authorizes current user and redirects back to the original page.
	 *
	 * @since 1.3.3
	 * @action plugins_loaded
	 *
	 * @access public
	 */
	public function authorize_user() {
		if ( filter_input( INPUT_GET, self::ACTION_KEY ) == self::ACTION_AUTHORIZE_USER ) {
			$user_id = wp_validate_auth_cookie( filter_input( INPUT_GET, 'auth' ), 'auth' );
			if ( $user_id ) {
				wp_set_auth_cookie( $user_id );

				$redirect_to = in_array( $GLOBALS['pagenow'], array( 'wp-login.php' ) ) && filter_input( INPUT_GET, 'redirect_to', FILTER_VALIDATE_URL )
					? $_GET['redirect_to']
					: add_query_arg( array( self::ACTION_KEY => false, 'auth' => false ) );

				wp_redirect( $redirect_to );
				exit;
			} else {
				wp_die( __( "Incorrect or out of date login key", $this->textdomain ) );
			}
		}
	}

	/**
	 * Propagates user to the network root block.
	 *
	 * @since 1.3.3
	 * @action wp_ajax_multidomain-propagate-user
	 * @action wp_ajax_nopriv_multidomain-propagate-user
	 *
	 * @access public
	 */
	public function propagate_user() {
		header( "Content-Type: text/javascript; charset=" . get_bloginfo( 'charset' ) );

		if ( get_current_blog_id() == 1 ) {
			$user_id = wp_validate_auth_cookie( filter_input( INPUT_GET, 'auth' ), 'auth' );
			if ( $user_id ) {
				wp_set_auth_cookie( $user_id );
				echo 'if (typeof multidomain_do_redirect === "function") multidomain_do_redirect();';
				exit;
			}
		}

		exit;
	}


}
endif;