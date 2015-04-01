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

require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

/**
 * Domains table class.
 *
 * @category Multidomains
 * @package Table
 *
 * @since 1.3.1
 */
class Multidomains_Table extends WP_List_Table {

	/**
	 * Constructor.
	 *
	 * @since 1.3.1
	 *
	 * @access public
	 * @param array $args The array of arguments.
	 */
	public function __construct( $args = array() ) {
		parent::__construct( array_merge( array(
			'search_box_label' => __( 'Search' ),
			'single'           => 'domain',
			'plural'           => 'domains',
			'ajax'             => false,
			'autoescape'       => true,
		), $args ) );
	}

	/**
	 * Displays table navigation section.
	 *
	 * @since 1.3.1
	 *
	 * @access public
	 * @param string $which The section where table navigation will be displayed.
	 */
	public function display_tablenav( $which ) {
		echo '<div class="tablenav ', esc_attr( $which ), '">';
			echo '<div class="alignleft actions">';
				$this->bulk_actions( $which );
			echo '</div>';
			$this->extra_tablenav( $which );
			$this->pagination( $which );
			echo '<br class="clear">';
		echo '</div>';
	}

	/**
	 * Returns checkbox column value.
	 *
	 * @since 1.3.1
	 *
	 * @access public
	 * @param array $item The table row to display.
	 * @return string The value to display.
	 */
	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" class="cb" name="%1$s[]" value="%2$s">', $this->_args['plural'], $item['domain_name'] );
	}

	/**
	 * Displays the search box if it was enabled in table arguments.
	 *
	 * @since 1.3.1
	 *
	 * @access public
	 * @param string $text The search button text.
	 * @param string $input_id The search input id.
	 */
	public function search_box( $text, $input_id ) {
		if ( isset( $this->_args['search_box'] ) && $this->_args['search_box'] ) {
			parent::search_box( $text, $input_id );
		}
	}

	/**
	 * Auto escapes all values and displays the table.
	 *
	 * @since 1.3.1
	 *
	 * @access public
	 */
	public function display() {
		if ( is_array( $this->items ) && $this->_args['autoescape'] ) {
			foreach ( $this->items as &$item ) {
				foreach ( $item as &$value ) {
					$value = esc_html( $value );
				}
			}
		}

		parent::display();
	}

	/**
	 * Returns associative array with the list of bulk actions available on this table.
	 *
	 * @since 1.3.1
	 *
	 * @access protected
	 * @return array The associative array of bulk actions.
	 */
	public function get_bulk_actions() {
		return isset( $this->_args['actions'] )
			? $this->_args['actions']
			: array();
	}

	/**
	 * Returns the domain name.
	 *
	 * @since 1.3.1
	 *
	 * @access public
	 * @param array $item Array of domain data.
	 * @return string Domain name.
	 */
	public function column_domain( $item ) {
		$title = !empty( $item['domain_name'] ) ? $item['domain_name'] : esc_html__( 'unknown', 'multi_domain' );

		$actions = array(
			'edit'   => sprintf(
				'<a href="%s">%s</a>',
				add_query_arg( array( 'edit' => '1', 'name' => $item['domain_name'] ) ),
				__( 'Edit', 'multi_domain' )
			),
			'delete' => sprintf(
				'<a href="%s" onclick="return showNotice.warn();">%s</a>',
				add_query_arg( array( 'delete' => '1', 'name' => $item['domain_name'] ) ),
				__( 'Delete', 'multi_domain' )
			),
		);

		return sprintf( '<a href="%s"><b>%s</b></a> %s', '#', $title, $this->row_actions( $actions ) );
	}

	/**
	 * Returns domain visibility.
	 *
	 * @since 1.3.1
	 *
	 * @access public
	 * @param array $item Array of domain data.
	 * @return string Domain visibility.
	 */
	public function column_visibility( $item ) {
		return !empty( $item['domain_status'] )
			? $item['domain_status']
			: esc_html__( 'unknown', 'multi_domain' );
	}

	/**
	 * Returns wildcard DNS availability status.
	 *
	 * @since 1.3.1
	 *
	 * @access public
	 * @param array $item Array of domain data.
	 * @return string Wildcard DNS availability status.
	 */
	public function column_availability( $item ) {
		$true = '<b style="color:green">' . __( 'available', 'multi_domain' ) . '</b>';
		$false = '<b style="color:red">' . __( 'unavailable', 'multi_domain' ) . '</b>';

		if ( $item['domain_name'] == DOMAIN_CURRENT_SITE ) {
			return $true;
		}

		$transient = 'multi_domain_availability-' . $item['domain_name'];
		$available = get_site_transient( $transient );
		if ( $available == false ) {
			$response = wp_remote_get( 'http://' . substr( md5( time() ), 0, 6 ) . '.' . $item['domain_name'], array(
				'timeout'     => 5,
				'httpversion' => '1.1',
			) );

			$available = !is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) == 200;
			set_site_transient( $transient, $available ? 1 : 0, 5 * MINUTE_IN_SECONDS );
		}

		return $available ? $true : $false;
	}

	/**
	 * Returns tabel columns.
	 *
	 * @since 1.3.1
	 *
	 * @access public
	 * @return array The array of table columns to display.
	 */
	public function get_columns() {
		$columns = array(
			'cb'         => '<input type="checkbox" class="cb_all">',
			'domain'     => __( 'Domain', 'multi_domain' ),
			'visibility' => __( 'Visibility', 'multi_domain' ),
		);

		if ( is_subdomain_install() ) {
			$columns['availability'] = __( 'Wildcard DNS Availability', 'multi_domain' );
		}

		return $columns;
	}

	/**
	 * Fetches domains from database.
	 *
	 * @since 1.3.1
	 *
	 * @access public
	 */
	public function prepare_items() {
		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns()
		);

		$per_page = 10;
		$offset = ( $this->get_pagenum() - 1 ) * $per_page;

		$items = get_site_option( 'md_domains' );
		$primary = wp_list_filter( $items, array( 'domain_name' => DOMAIN_CURRENT_SITE ) );
		$else = wp_list_filter( $items, array( 'domain_name' => DOMAIN_CURRENT_SITE ), 'NOT' );

		$items = array_merge( $primary, $else );

		$search = isset( $_REQUEST['s'] ) ? trim( $_REQUEST['s'] ) : '';
		if ( !empty( $search ) ) {
			$tmp = array();
			foreach ( $items as $item ) {
				if ( stripos( $item['domain_name'], $search ) !== false ) {
					$tmp[] = $item;
				}
			}
			$items = $tmp;
		}

		$this->items = array_slice( $items, $offset, $per_page );;

		$total_items = count( $items );
		$this->set_pagination_args( array(
			'total_items' => $total_items,
			'per_page' => $per_page,
			'total_pages' => ceil( $total_items / $per_page )
		) );
	}

}
