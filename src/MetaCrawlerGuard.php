<?php
/**
 * @package Daan\Mods
 * @author  Daan van den Bergh
 * @url     https://daan.dev
 * @license MIT
 */

namespace Daan\Mods;

/**
 * Keeps Meta's crawlers (link previews, Meta AI) from using up server resources on affiliate links.
 *
 * Meta re-crawls shared affiliate links (e.g. /wordpress/omgf-pro/ref/divimundo/) up to once per second, and runs the
 * page's JavaScript, which logs an AffiliateWP visit and a Plausible pageview each time. Affiliate links aren't cached,
 * so every hit runs PHP three times. For Meta only:
 *
 * - affiliate links redirect to the same page without the affiliate part (cached, and link previews keep working),
 * - AJAX/REST POST requests are refused before the rest of WordPress loads.
 *
 * This runs as soon as this plugin is included, i.e. before most plugins are loaded.
 */
class MetaCrawlerGuard {
	/**
	 * Meta's crawler user agents (facebookexternalhit = link previews, meta-external* = Meta AI).
	 */
	const USER_AGENTS = '~facebookexternalhit|facebookcatalog|meta-externalagent|meta-externalfetcher|meta-webindexer~i';

	/**
	 * Meta's IPv6 range (AS32934).
	 */
	const IPV6_RANGE = '2a03:2880::/32';

	/**
	 * AffiliateWP's referral variable, as in /ref/{affiliate}/ or ?ref={affiliate}.
	 */
	const REFERRAL_VAR = 'ref';

	/**
	 * Build class.
	 */
	public function __construct() {
		if ( ! $this->is_meta_crawler() ) {
			return;
		}

		$method = $_SERVER[ 'REQUEST_METHOD' ] ?? 'GET';
		$uri    = $_SERVER[ 'REQUEST_URI' ] ?? '/';

		if ( 'POST' === $method && ( wp_doing_ajax() || $this->is_rest_request( $uri ) ) ) {
			$this->refuse();
		}

		if ( in_array( $method, [ 'GET', 'HEAD' ], true ) && ( $target = $this->strip_referral( $uri ) ) !== $uri ) {
			header( 'Location: ' . $target, true, 301 );
			header( 'Cache-Control: public, max-age=86400' );
			exit;
		}
	}

	/**
	 * @return bool
	 */
	private function is_meta_crawler() {
		$user_agent = $_SERVER[ 'HTTP_USER_AGENT' ] ?? '';

		if ( $user_agent && preg_match( self::USER_AGENTS, $user_agent ) ) {
			return true;
		}

		return $this->ip_in_range( $_SERVER[ 'REMOTE_ADDR' ] ?? '', self::IPV6_RANGE );
	}

	/**
	 * @param string $uri
	 *
	 * @return bool
	 */
	private function is_rest_request( $uri ) {
		return strpos( $uri, '/' . rest_get_url_prefix() . '/' ) !== false || isset( $_GET[ 'rest_route' ] );
	}

	/**
	 * Remove the affiliate part (and anything after it) from the path, and the referral variable from the query string.
	 *
	 * @param string $uri
	 *
	 * @return string
	 */
	private function strip_referral( $uri ) {
		$path  = (string) parse_url( $uri, PHP_URL_PATH );
		$query = (string) parse_url( $uri, PHP_URL_QUERY );
		$path  = preg_replace( '~/' . self::REFERRAL_VAR . '/[^/]+(/.*)?$~', '/', $path );

		if ( $query ) {
			parse_str( $query, $args );
			unset( $args[ self::REFERRAL_VAR ] );
			$query = http_build_query( $args );
		}

		$target   = $path . ( $query ? '?' . $query : '' );
		$original = (string) parse_url( $uri, PHP_URL_PATH ) . ( parse_url( $uri, PHP_URL_QUERY ) ? '?' . parse_url( $uri, PHP_URL_QUERY ) : '' );

		// Unchanged: return the URI as is, so the caller can compare.
		return $target === $original ? $uri : $target;
	}

	/**
	 * @param string $ip
	 * @param string $range CIDR notation.
	 *
	 * @return bool
	 */
	private function ip_in_range( $ip, $range ) {
		[ $subnet, $bits ] = explode( '/', $range );
		$ip                = @inet_pton( $ip );
		$subnet            = @inet_pton( $subnet );

		if ( ! $ip || ! $subnet || strlen( $ip ) !== strlen( $subnet ) ) {
			return false;
		}

		$bytes = intdiv( (int) $bits, 8 );
		$rest  = (int) $bits % 8;

		if ( substr( $ip, 0, $bytes ) !== substr( $subnet, 0, $bytes ) ) {
			return false;
		}

		if ( ! $rest ) {
			return true;
		}

		$mask = chr( ( 0xff << ( 8 - $rest ) ) & 0xff );

		return ( $ip[ $bytes ] & $mask ) === ( $subnet[ $bytes ] & $mask );
	}

	/**
	 * @return void
	 */
	private function refuse() {
		status_header( 403 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		echo 'Forbidden';
		exit;
	}
}
