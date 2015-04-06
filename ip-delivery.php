<?php
/*
Plugin Name: IP Delivery
Plugin URI: http://github.com/blmd/ip-delivery
Description: IP Delivery
Author: blmd
Author URI: http://github.com/blmd
Version: 0.1
*/

!defined( 'ABSPATH' ) && die;
define( 'IP_DELIVERY_VERSION', '0.1' );
define( 'IP_DELIVERY_URL', plugin_dir_url( __FILE__ ) );
define( 'IP_DELIVERY_DIR', plugin_dir_path( __FILE__ ) );
define( 'IP_DELIVERY_BASENAME', plugin_basename( __FILE__ ) );
define( 'IP_DELIVERY_DB_TABLE', 'ip_delivery' );
define( 'IP_DELIVERY_DB_VERSION', '0.1' );

require_once IP_DELIVERY_DIR . 'includes/class-ipd2.php';

class IP_Delivery {

	protected $ipd2;
	protected $data = array();

	public static function factory() {
		static $instance = null;
		if ( ! ( $instance instanceof self ) ) {
			$instance = new self;
			$instance->setup_actions();
		}
		return $instance;
	}

	protected function setup_actions() {
		add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ) );
		add_action( 'init', array( $this, 'init' ) );
		register_activation_hook( __FILE__, array( $this, 'install' ) );
		register_activation_hook( __FILE__, array( $this, 'install_data' ), 20 );
		add_filter( 'the_content', array( $this, 'the_content' ), 0 );
		add_filter( 'the_excerpt', array( $this, 'the_excerpt' ), 0 );
	}

	public function plugins_loaded() {
		$this->data['ipd2'] = (object) IPD2::defaults();
	}

	public function init() {
		$ac = apply_filters( 'ipd_autocrawler', get_option( 'ipd_autocrawler' ) );
		if ( $ac && IP_Delivery()->type == 'CRAWLER' /*&& is_singular() && is_main_query()*/ ) {
			add_filter( 'wpseo_opengraph_desc', '__return_false' );
		}
	}

	function the_content( $content ) {
		global $post;
		$ac = apply_filters( 'ipd_autocrawler', get_option( 'ipd_autocrawler' ) );
		if ( $ac && IP_Delivery()->type == 'CRAWLER' /*&& is_singular() && is_main_query()*/ ) {
			if ( metadata_exists( 'post', $post->ID, 'crawlercontent' ) ) {
				return get_post_meta( $post->ID, 'crawlercontent', true );
			}
		}
		return $content;
	}

	function the_excerpt( $excerpt ) {
		global $post;
		$ac = apply_filters( 'ipd_autocrawler', get_option( 'ipd_autocrawler' ) );
		if ( $ac && IP_Delivery()->type == 'CRAWLER' /*&& is_singular() && is_main_query()*/ ) {
			if ( metadata_exists( 'post', $post->ID, 'crawlerexcerpt' ) ) {
				return get_post_meta( $post->ID, 'crawlerexcerpt', true );
			}
		}
		return $excerpt;
	}

	public function __get( $field ) {
		switch ( $field ) {
		case 'ip':
		case 'type':
			return apply_filters( 'ipd_type', $this->data['ipd2']->type );
		case 'agenttype':
		case 'name':
		case 'query':
		case 'queryengine':
		case 'warning':
		case 'iscrawleragent':
		case 'isbrowseragent':
			return $this->data['ipd2']->{$field};
		default:
			throw new Exception( 'Invalid ' . __CLASS__ . ' property: ' . $field );
		}
		// if (array_key_exists(strtolower($name), $this->_data)) {
		//     return $this->_data[strtolower($name)];
		// }
	}

	// public static function is_crawler() {
	//   return self::factory()->type == 'CRAWLER';
	// }

	public function install() {
		global $wpdb;
		$table_name = $wpdb->base_prefix . IP_DELIVERY_DB_TABLE;
		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE $table_name (
    		ip int unsigned NOT NULL,
    		name varchar(32) DEFAULT NULL,
    		hostname varchar(128) DEFAULT NULL,
    		type varchar(16) DEFAULT NULL,
    		PRIMARY KEY  (ip)
    	) $charset_collate;";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_site_option( 'ipd_db_version', IP_DELIVERY_DB_VERSION );
		if ( get_option( 'ipd_autocrawler' ) === false ) {
			update_option( 'ipd_autocrawler', true );
		}
	}

	public function install_data() {
		global $wpdb;
		$table_name = $wpdb->base_prefix . IP_DELIVERY_DB_TABLE;
		$exists = $wpdb->get_var( "SELECT ip FROM {$table_name} LIMIT 1" );
		if ( $exists ) return;
		if ( ! ( $url = get_option( 'ipd_csv_url' ) ) ) {
			wp_die( "Please set option 'ipd_csv_url'." );
		}
		// $ret = wp_remote_post( $url, array( 'body' => array( 'api_key' => get_option('blmd_api_key') ) ) );
		$ret = wp_remote_get( $url );
		if ( is_wp_error( $ret ) || wp_remote_retrieve_response_code( $ret ) != 200 ) {
			wp_die( $ret );
		}
		$buf = wp_remote_retrieve_body( $ret );
		$wpdb->query( 'BEGIN' );
		foreach ( preg_split( '/(*BSR_ANYCRLF)\R/', $buf ) as $row ) {
			if ( !( $row = trim( $row ) ) ) continue;
			$rec = array_map( 'trim', explode( ',', $row ) );
			$wpdb->insert( $table_name,
				array(
					'ip'       => sprintf( '%u', ip2long( $rec[0] ) ),
					'name'     => $rec[1],
					'hostname' => $rec[2],
					'type'     => $rec[count( $rec )-1],
				),
				array( '%s', '%s', '%s', '%s' )
			);
		}
		$wpdb->query( 'COMMIT' );
	}

	public function __construct() { }

	public function __clone() {
		_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'core-plugin' ), '0.1' );
	}

	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'core-plugin' ), '0.1' );
	}

};

function IP_Delivery() {
	return IP_Delivery::factory();
}

IP_Delivery();



// function ipd_is_allow( $in_obj ) {
// 	global $IPD2;
// 	// CRAWLER, ADMIN, BADBOT, HUMAN, SERP_REFERER
// 	$arr        = (array)$in_obj;
// 	$denystr    = @$arr['deny'] ?: '';
// 	$allowstr   = @$arr['allow'] ?: '';
// 	$redirectsr = @$arr['redirect'] ?: '';
// 	$deny_arr     = array_filter( array_map( 'trim', preg_split( '/[,| ]/', strtoupper( $denystr ) ) ), 'strlen' );
// 	$allow_arr    = array_filter( array_map( 'trim', preg_split( '/[,| ]/', strtoupper( $allowstr ) ) ), 'strlen' );
// 	$allow = null; $deny = null;
// 	if ( empty( $allow_arr ) ) { $allow = true; }
// 	elseif ( $IPD2->type && in_array( $IPD2->type, $allow_arr, true ) ) { $allow = true; }
// 	else { $allow = false; }
// 	if ( empty( $deny_arr ) ) { $deny = false; }
// 	elseif ( $IPD2->type && in_array( $IPD2->type, $deny_arr, true ) ) { $deny = true; }
// 	else { $deny = false; }
// 	$decision = $allow===true && $deny===false ? 'ALLOW' : 'DENY';
// 	// $str = "crawler: {$IPD2->crawler}, allow:{$allow}, deny:{$deny} ";
// 	// $str .= "allow_a:".print_r($allow_arr, 1)." deny_a:".print_r($deny_arr, 1)." ";
// 	// $str .= "final: *".$decision."* ";
// 	// die($str);
// 	return (bool)( $decision=='ALLOW' );
// }
//
// if (isset($row->options->allow) || isset($row->options->deny)) {
//   $allow = $this->is_allow($row->options);
//   if (!$allow) {
//     $query = new DNSQuery(IPD2::RESOLVER_IP_1, 53, 1);
//     $result = $query->Query($row->hostname, 'A');
//     if ($result === false) {
//       $query = new DNSQuery(IPD2::RESOLVER_IP_2, 53, 2, false); // TCP
//       $result = $query->Query($row->hostname, 'A');
//     }
//     if (preg_match('/^(\d+)\.(\d+)\.(\d+)\.(\d+)$/', @$result->results[0]->data)) {
//       $rd_url = "http://{$result->results[0]->data}/cgi-sys/defaultwebpage.cgi";
//       HttpHeader::halt_302($rd_url);
//     }
//     else {
//       HttpHeader::halt_404($this->http_request_uri_raw);
//     }
//   }
// }
