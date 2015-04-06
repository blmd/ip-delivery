<?php
// function dbg($msg, $var='') {
//   return;
//   $msg = trim($msg);
//   $ip = @$_SERVER['HTTP_CF_CONNECTING_IP'] ?: $_SERVER['REMOTE_ADDR'];
//   $line = sprintf("[%s %s]: %s ", gmdate('Y-m-d H:i:s'), $ip, $msg);
//   if (is_array($var)) {
//     $line .= print_r($var, 1);
//   }
//   elseif (is_object($var)) {
//     // $line .= "Object: ".get_class($msg);
//     $line .= print_r($var, 1);
//   }
//   else {
//     $line .= $var;
//   }
//   $line = trim($line)."\n";
//   file_put_contents('/srv/www/ipd2.log', $line, FILE_APPEND);
// }

require_once dirname( __FILE__ ).'/vendor/dns.inc.php';

class IPD2 {

	const RESOLVER_IP_1 = '74.82.42.42'; // hurricane electric
	const RESOLVER_IP_2 = '209.244.0.3'; // level 3

	public $ip, $host, $referer, $agent;
	public $bad_referers, $bad_agents;
	public $result = array();
	
	public static function is_wpdb() {
		static $is_wpdb;
		if ( !isset( $is_wpdb ) ) {
			$is_wpdb = class_exists( 'wpdb' ) && !empty( $GLOBALS['wpdb'] ) && is_a( $GLOBALS['wpdb'], 'wpdb' );
		}
		return $is_wpdb;
	}

	public function __construct( $ip=null, $agent=null, $referer=null, $file='ipd.csv' ) {
		$this->dbfile                   = null;
		$this->ip                       = trim( $ip );
		$this->referer                  = trim( $referer );
		$this->agent                    = trim( $agent );

		$this->result = array();
		$this->result['ip']             = $this->ip; // 192.168.1.1
		$this->result['type']           = null; // CRAWLER, ADMIN
		$this->result['agenttype']      = null; // webpreview, tablet, smartphone
		$this->result['name']           = null; // google, msn, bing, yahoo
		$this->result['query']         = null; // dog food
		$this->result['queryengine']    = null; // google, yahoo, msn, aol
		$this->result['warning']        = null; // badreferer,badagent,badquery
		$this->result['iscrawleragent'] = null; // 1, 0
		$this->result['isbrowseragent'] = null; // 1, 0

		$this->bad_referers = array(
			// "/\?.*?(&|)(q)=.*?" . preg_quote($this->host) . ".*?($|&)/",
			// "/\?.*?(&|)(q)=.*?" . preg_quote(urlencode($this->host)) . ".*?($|&)/",
			// "/\?.*?(&|)(q)=.*?\.(com|net|org|info|us|ca|uk|au|de|nl|nz|edu|gov|cc|cn|ru).*?(&|$)/",
			"/translate\.google\./i",
			"/babelfish\./i",
			"/__jc_unknown_var_cloak\./i",
		);

		$this->bad_agents = array(
			'yandex'      => '/Yandex/',
			'exabot'      => '/exabot\.com/',
			'curl'        => '/curl\//',
			'naver'       => '/naver\.com/',
			'baidu'       => '/Baiduspider/',
			'surveybot'   => '/(SurveyBot\/|DomainTools)/',
			'ia_archiver' => '/ia_archiver/',
			'ahrefs'      => '/AhrefsBot/i',
			'dotbot'      => '/\bdotbot/',
			'sosospider'  => '/Sosospider/',
			'rogerbot'    => '/rogerbot/',
			'majestic'    => '/(MJ12bot|majestic12)/i',
		);

		$this->bad_queries = array(
			'/\.(com|net|org|info|us|ca|uk|au|de|nl|nz|edu|gov|cc|cn|ru)/i',
			'/(site|link|linkdomain|cache|info)(\:|%3A)/i',
		);

		// not running under wordpress
		if ( ! self::is_wpdb() ) {
			$docroot                = $_SERVER['DOCUMENT_ROOT'];
			if ( !$docroot ) { $docroot = dirname( $_SERVER['SCRIPT_FILENAME'] ); }

			$arr = array( 1, 0, 3, 2, 4 );
			// if (dirname(__FILE__) == 'plugins') { $arr = array(0,)}
			foreach ( $arr as $parents ) {
				$dots = str_repeat( '../', $parents );
				$this->dbfile = "{$docroot}/{$dots}{$file}";
				// print $this->dbfile."<br>";
				if ( file_exists( $this->dbfile ) ) { break; }
				$this->dbfile = null;
			}
			if ( !$this->dbfile ) {
				while ( @ob_end_clean() );
				if ( function_exists( 'header_remove' ) ) { header_remove( 'X-Powered-By' ); }
				header( 'HTTP/1.x 503 Service Temporarily Unavailable' );
				// header('Connection: close');
				$msg = '';
				$msg .= "<!DOCTYPE HTML PUBLIC \"-//IETF//DTD HTML 2.0//EN\">\n";
				$msg .= "<html><head>\n";
				$msg .= "<title>503 Service Temporarily Unavailable</title>\n";
				$msg .= "</head><body>\n";
				$msg .= "<h1>Service Temporarily Unavailable</h1>\n";
				$msg .= "The server is temporarily unable to service your\n";
				$msg .= "request due to maintenance downtime or capacity\n";
				$msg .= "problems. Please try again later.\n";
				$msg .= "</body></html>\n";
				echo $msg;
				exit;
			}
		}
		// dbg("dbfile: ".$this->dbfile);
	}

	public static function ip_in_range( $ip, $range ) {
		list( $range, $netmask ) = explode( '/', $range, 2 );
		$x = explode( '.', $range );
		while ( count( $x )<4 ) $x[] = '0';
		list( $a, $b, $c, $d ) = $x;
		$range = sprintf( "%u.%u.%u.%u", empty( $a )?'0':$a, empty( $b )?'0':$b, empty( $c )?'0':$c, empty( $d )?'0':$d );
		$range_dec = ip2long( $range );
		$ip_dec = ip2long( $ip );
		$wildcard_dec = pow( 2, ( 32-$netmask ) ) - 1;
		$netmask_dec = ~ $wildcard_dec;
		return ( $ip_dec & $netmask_dec ) == ( $range_dec & $netmask_dec );
	}


	public static function cloudflare() {
		if ( !empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && $_SERVER['HTTP_CF_CONNECTING_IP'] != $_SERVER['REMOTE_ADDR'] ) {
			$cf_connecting_ip = trim( array_shift( explode( ',', $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) );
			$is_valid_cf_ip = false;
			$ips = "199.27.128.0/21
							173.245.48.0/20
							103.21.244.0/22
							103.22.200.0/22
							103.31.4.0/22
							141.101.64.0/18
							108.162.192.0/18
							190.93.240.0/20
							188.114.96.0/20
							197.234.240.0/22
							198.41.128.0/17
							162.158.0.0/15
							104.16.0.0/12
							172.64.0.0/13";
			$arr = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $ips ) ), 'strlen' );
			foreach ( $arr as $range ) {
				if ( self::ip_in_range( $_SERVER['REMOTE_ADDR'], $range ) ) {
					$is_valid_cf_ip = true;
					$_SERVER['REMOTE_ADDR'] = $cf_connecting_ip;
					break;
				}
			}
			// if ( self::is_wpdb() && function_exists( 'remove_action' ) ) {
			// 	remove_action( 'plugins_loaded', 'prefix_cloudflare_fix_remote_addr', 99 );
			// }
		}
		if ( !empty( $_SERVER['HTTP_INCAP_CLIENT_IP'] ) && $_SERVER['HTTP_INCAP_CLIENT_IP'] != $_SERVER['REMOTE_ADDR'] ) {
			$ic_connecting_ip = trim( array_shift( explode( ',', $_SERVER['HTTP_INCAP_CLIENT_IP'] ) ) );
			$is_valid_cf_ip = false;
			$ips = "199.83.128.0/21
							198.143.32.0/19
							149.126.72.0/21
							103.28.248.0/22
							185.11.124.0/22
							192.230.64.0/18
							45.64.64.0/22";
			$arr = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $ips ) ), 'strlen' );
			foreach ( $arr as $range ) {
				if ( self::ip_in_range( $_SERVER['REMOTE_ADDR'], $range ) ) {
					$is_valid_cf_ip = true;
					$_SERVER['REMOTE_ADDR'] = $ic_connecting_ip;
					break;
				}
			}
			// if ( self::is_wpdb() && function_exists( 'remove_action' ) ) {
			// 	remove_action( 'plugins_loaded', 'prefix_cloudflare_fix_remote_addr', 99 );
			// }
		}
		// if (!empty($_SERVER["HTTP_CF_IPCOUNTRY"])) {
		//
		// }
	}

	public static function defaults() {
		self::cloudflare();
		$ip      = isset($_SERVER['REMOTE_ADDR'])     ? $_SERVER['REMOTE_ADDR']     : '';
		$agent   = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
		$referer = isset($_SERVER['HTTP_REFERER'])    ? $_SERVER['HTTP_REFERER']    : '';
		$ip      = trim( self::is_wpdb() ? stripslashes( $ip ) : $ip );
		$referer = trim( self::is_wpdb() ? stripslashes( $referer ) : $referer );
		$agent   = trim( self::is_wpdb() ? stripslashes( $agent ) : $agent );
		$t = new self( $ip, $agent, $referer );
		return $t->run();
	}

	public function run() {
		$this->result['iscrawleragent'] = (int)(bool)$this->is_crawler_agent();
		$this->result['isbrowseragent'] = (int)(bool)$this->is_browser_agent();
		if ( $this->is_bad_agent() ) { return $this->result; }
		if ( $this->is_bad_referer() ) { return $this->result; }
		$this->query_ip();
		if ( $this->is_browser_agent() ) {
			list( $query, $queryengine ) = $this->parse_referer( $this->referer );
			$this->result['queryengine'] = $queryengine;

			if ( $query !== false && strlen( $query ) > 2 ) {
				$this->result['query'] = $query;
			}
		}
		else {
			// if (!$this->is_crawler_agent()) {
			//  $this->result['warnings'] .= trim(",suspiciousagent:{$this->agent}",',');
			// }
		}

		// if (strpos($this->agent,'Google Web Preview')!==false) {
		if ( strpos( $this->agent, 'Google Web Preview' )!==false || stripos( $this->agent, 'BingPreview' )!==false ) {
			$this->result['agenttype'] = 'webpreview';
		}
		elseif ( $smartphone = $this->is_tablet() ) {
			$this->result['agenttype'] = 'tablet';
		}
		elseif ( $smartphone = $this->is_smartphone() ) {
			$this->result['agenttype'] = 'smartphone';
		}
		return $this->result;
	}

	public function query_ip() {
		$found = false;
		if ( self::is_wpdb() ) {
			global $wpdb;
			$table_name = $wpdb->base_prefix . IP_DELIVERY_DB_TABLE;
			if ( $row = $wpdb->get_row( $wpdb->prepare( "SELECT name,type from {$table_name} where ip=inet_aton(%s)", $this->ip ) ) ) {
				$found = true;
				$this->result['name'] = $row->name;
				$this->result['type'] = $row->type;
			}
		}
		else {
			$rec = array();
			$row = array();
			$fh = @fopen( "{$this->dbfile}", 'rb' );
			$found = false;
			while ( !feof( $fh ) && $row = fgets( $fh ) ) {
				// if (strpos(substr($row,0,15),$ip)===0) {
				if ( strpos( $row, $this->ip )===0 ) {
					$found = true;
					$row = trim( $row );
					break;
				}
			}
			@fclose( $fh );
			if ( $found ) {
				$rec = explode( ',', $row );
				$this->result['name'] = $rec[1];
				$this->result['type'] = $rec[count( $rec )-1];
			}
		}
		if ( !$found && $this->is_crawler_agent() ) {
			// dbg("looking up ip");
			$this->lookup_ip();
		}
		return $found;
	}

	function is_crawler_agent() {
		if ( empty( $this->agent ) ) return false;
		$pat = '/(msnbot|bingbot|googlebot|slurp)/i';
		if ( preg_match( $pat, $this->agent, $matches ) ) {
			// dbg("Crawler: ".$matches[1]);
			return $matches[1];
		}
		return false;
	}

	function is_smartphone() {
		if ( empty( $this->agent ) ) return false;
		$pat = '/(iphone|ipod|android|blackberry|mini|windows ce|windows phone|palm)/i';
		if ( preg_match( $pat, $this->agent, $matches ) ) {
			if ( stripos( $this->agent, 'android' )!==false ) {
				if ( stripos( $this->agent, 'mobile' )!==false ) {
					return $matches[1];
				}
			}
			else {
				return $matches[1];
			}
		}
		return false;
	}

	function is_tablet() {
		if ( empty( $this->agent ) ) return false;
		$pat = '/(android|ipad)/i';
		if ( preg_match( $pat, $this->agent, $matches ) ) {
			if ( stripos( $this->agent, 'android' )!==false ) {
				if ( stripos( $this->agent, 'mobile' )!==false ) {
					return false;
				}
			}
			else {
				return $matches[1];
			}
		}
		return false;
	}

	function is_browser_agent() {
		if ( empty( $this->agent ) ) return false;
		$agent = $this->agent;
		if ( $agent == '' || $agent == '-' || ( ( strpos( $agent, 'http' ) !== false ) && ( strpos( $agent, 'Embedded' ) === false ) ) || ( strpos( $agent, '@' ) !== false ) ) return false;
		$pat = '/(Blackberry|BlackBerry|MSIE|Gecko|Firefox|KHTML|Opera|Safari|AppleWebKit|Google Desktop)/';
		if ( preg_match( $pat, $agent, $matches ) ) {
			return $matches[1];
		}
		return false;
	}

	function is_bad_referer() {
		$referer = $this->referer;
		if ( !preg_match( '/^http/i', $referer ) ) {
			return false;
		}
		foreach ( $this->bad_referers as $regex ) {
			if ( preg_match( $regex, $referer, $matches ) ) {
				$this->result['warning'] = "badreferer:{$matches[0]}";
				return $regex;
			}
		}
		return false;
	}

	function is_bad_agent() {
		foreach ( $this->bad_agents as $nicename=>$regex ) {
			if ( empty( $this->agent ) || preg_match( $regex, $this->agent, $matches ) ) {
				$this->result['warning'] = "badagent:{$nicename}";
				return $regex;
			}
		}
		return false;
	}


	public function lookup_ip() {
		$hostname = '';
		$type = 'CRAWLER';
		$crawler_hosnames = array( 'google' => '/googlebot\.com\.$/',
			'yahoo' => '/crawl\.yahoo\.net\.$/',
			'msn' => '/search\.msn\.com\.$/',
		);
		// dbg("query: {$this->ip}@".self::RESOLVER_IP_1);
		$query = new DNSQuery( self::RESOLVER_IP_1, 53, 1 );
		$result = $query->Query( $this->ip, 'PTR' );
		// dbg("result:", $result);
		if ( $result === false ) {
			$query = new DNSQuery( self::RESOLVER_IP_2, 53, 2, false ); // TCP
			$result = $query->Query( $this->ip, 'PTR' );
			// dbg("result2:", $result2);
		}
		if ( !empty( $result->results[0]->data ) && ( strlen( rtrim( @$result->results[0]->data, ' .' ) ) >2 ) ) {
			$hostname = rtrim( $result->results[0]->data, ' .' ).'.';
			// dbg("hostname: ".$hostname);
			foreach ( $crawler_hosnames as $name => $regex ) {
				if ( preg_match( $regex, $hostname ) ) {
					// dbg("regex_match: $regex == $hostname");
					$result = $query->Query( $hostname, 'A' );
					if ( $result === false ) {
						$result = $query->Query( $hostname, 'A' );
					}
					// dbg("result:", $result);
					if ( !empty( $result->results[0]->data ) && $result->results[0]->data==$this->ip ) {
						$this->result['name'] = $name;
						$this->result['type'] = $type;
						// append to csv file
						// dbg("reverse IP validated: name:$name, type:$type");
						if ( self::is_wpdb() ) {
							global $wpdb;
							$wpdb->insert( $wpdb->base_prefix . IP_DELIVERY_DB_TABLE,
								array(
									'ip'       => sprintf( '%u', ip2long( $this->ip ) ),
									'name'     => $name,
									'hostname' => $hostname,
									'type'     => $type,
								),
								array( '%s', '%s', '%s', '%s' )
							);
						}
						else {
							$tmpf = tempnam( dirname( $this->dbfile ), 'IP_' );
							if ( ( $fh = @fopen( "$tmpf", 'wb' ) ) && ( $fh1 = @fopen( "{$this->dbfile}", 'rb' ) ) ) {
								while ( !feof( $fh1 ) && $row = fgets( $fh1 ) ) {
									$row = trim( $row );
									if ( !$row ) continue;
									$row .= "\n";
									fwrite( $fh, $row, strlen( $row ) );
								}
								@fclose( $fh1 );
								// 207.46.204.235,msn,msnbot-207-46-204-235.search.msn.com.,CRAWLER
								$str = "{$this->ip},{$name},{$hostname},{$type}\n";
								// dbg("writing $str to $tmpf");
								fwrite( $fh, $str, strlen( $str ) );
								fflush( $fh );
								@fclose( $fh );
								@rename( $tmpf, $this->dbfile );
								@chmod( $this->dbfile, 0644 );
								@unlink( $tmpf );
							}
						}
						break;
					}
				}
			}
		}
	}

	function parse_referer( $r, $hint=null ) {
		$qs = array();
		$query = '';
		$queryengine = null;
		$parts = @parse_url( $r );
		if ( empty( $parts['host'] ) ) return array( false, null );
		if ( !empty( $parts['query'] ) ) {
			@parse_str( $parts['query'], $qs );
		}
		elseif ( preg_match( '%google\.([^/]+)/#%', $r, $rparts ) ) {
			@parse_str( $parts['fragment'], $qs );
		}
		if ( get_magic_quotes_gpc() && is_array( $qs ) ) { $qs = array_map( 'stripslashes', $qs ); }

		if ( preg_match( '/\.(google)\./', $parts['host'] ) ) {
			// if ($up['path'] != '/m' && (strpos($ref, '?') === false || strpos($ref, '&') === false)) return ''; // mobile or
			if ( strpos( $r, '&' )!==false || strpos( $r, '?' )!==false ) {
				$queryengine = 'google';
			}
			elseif ( preg_match( '%^https://(.+)\.google\.([^/]+)/$%', $r ) ) {
				$queryengine = 'google';
			}
			if ( preg_match( '/imgres/', $r ) ) // google image
				{
				if ( empty( $qs['prev'] ) && !empty( $qs['q'] ) ) { // mobile image
					$query = $qs['q'];
				}
				elseif ( !empty( $qs['prev'] ) ) {
					$qs['prev'] = str_replace( array( ':', ',' ), array( '%3A', '%2C' ), $qs['prev'] );
					$iparts = parse_url( $qs['prev'] );
					@parse_str( $iparts['query'], $iqs );
					if ( get_magic_quotes_gpc() && is_array( $iqs ) ) { $iqs = array_map( 'stripslashes', $iqs ); }
					if ( !empty( $iqs['q'] ) ) {
						// $query = "I: ".$iqs['q'];
						$query = $iqs['q'];
					}
				}
			}
			else // normal google
				{
				// google ssl
				if ( isset( $qs['q'] ) && empty( $qs['q'] ) ) {
					return array( false, $queryengine );
				}
				if ( !empty( $qs['q'] ) ) {
					$query = trim( $qs['q'] );
				}
				if ( !empty( $qs['as_q'] ) ) {
					$query .= sprintf( ' %s ', trim( $qs['as_q'] ) );
				}
				if ( !empty( $qs['as_epq'] ) ) {
					$query .= sprintf( ' "%s" ', trim( $qs['as_epq'] ) );
				}
				if ( !empty( $qs['as_oq'] ) ) {
					$or = ' +'.join( ' OR +', explode( '+', str_replace( ' ', '+', stripcslashes( $qs['as_oq'] ) ) ) );
					$query .= sprintf( ' %s ', $or );
				}
				if ( !empty( $qs['as_eq'] ) ) {
					$neg = ' -'.join( ' -', explode( '+', trim( str_replace( ' ', '+', $qs['as_eq'] ) ) ) );
					$query .= sprintf( ' %s ', $neg );
				}
			}
			$query = trim( preg_replace( '/\s+/', ' ', $query ) );
			// return array($query, $queryengine);
		}
		elseif ( preg_match( '/\.(yahoo)\./', $parts['host'] ) ) {
			if ( strpos( $r, '&' )!==false || strpos( $r, '?' )!==false ) {
				$queryengine = 'yahoo';
			}
			if ( !empty( $qs['p'] ) ) {
				$query = trim( $qs['p'] );
			}
			elseif ( !empty( $qs['q'] ) ) {
				$query = trim( $qs['q'] );
			}
			// return array($query, $queryengine);
		}
		elseif ( preg_match( '/\.(bing|live|msn)\./', $parts['host'] ) ) {
			if ( strpos( $r, '&' )!==false || strpos( $r, '?' )!==false ) {
				$queryengine = 'msn';
			}
			if ( !empty( $qs['q'] ) ) {
				$query = trim( $qs['q'] );
			}
			elseif ( !empty( $qs['Q'] ) ) {
				$query = trim( $qs['Q'] );
			}
			// return array($query, $queryengine);
		}
		elseif ( preg_match( '/\.(aol)\./', $parts['host'] ) ) {
			if ( strpos( $r, '&' )!==false || strpos( $r, '?' )!==false ) {
				$queryengine = 'aol';
			}
			if ( !empty( $qs['q'] ) ) {
				$query = trim( $qs['q'] );
			}
			elseif ( !empty( $qs['query'] ) ) {
				$query = trim( $qs['query'] );
			}
			elseif ( !empty( $qs['as_q'] ) ) {
				$query = trim( $qs['as_q'] );
			}
			elseif ( !empty( $qs['as_epq'] ) ) {
				$query = trim( $qs['as_epq'] );
			}
			// return array($query, $queryengine);
		}
		if ( $query ) {
			foreach ( $this->bad_queries as $bq ) {
				if ( preg_match( $bq, $query, $bqmatches ) ) {
					$this->result['warning'] = "badquery:{$bqmatches[0]}";
					return array( false, null );
				}
			}
		}
		return array( $query, $queryengine );
	}

};
// IPD2::cloudflare();
