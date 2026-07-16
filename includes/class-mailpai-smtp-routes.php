<?php
/**
 * Mail routes storage.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Routes
 */
class Mailpai_Smtp_Routes {

	const OPTION = 'mailpai_smtp_routes';

	/** @var array|null */
	private static $cache = null;

	/**
	 * @return array
	 */
	public static function defaults() {
		return array(
			'transactional' => array(
				'wordpress'   => '',
				'woocommerce' => '',
				'default'     => '',
			),
			'marketing'     => array(
				'newsletter' => '',
				'outreach'   => '',
			),
			'use_one'       => false,
		);
	}

	/**
	 * @return array
	 */
	public static function get_all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		self::$cache = wp_parse_args( $stored, self::defaults() );
		return self::$cache;
	}

	/**
	 * @param array $routes Routes data.
	 */
	public static function save( array $routes ) {
		$clean = self::defaults();
		foreach ( array( 'wordpress', 'woocommerce' ) as $key ) {
			$clean['transactional'][ $key ] = sanitize_key( (string) ( $routes['transactional'][ $key ] ?? '' ) );
		}
		$clean['transactional']['default'] = '';
		foreach ( array( 'newsletter', 'outreach' ) as $key ) {
			$clean['marketing'][ $key ] = sanitize_key( (string) ( $routes['marketing'][ $key ] ?? '' ) );
		}
		$clean['use_one'] = ! empty( $routes['use_one'] );
		if ( $clean['use_one'] ) {
			$one = sanitize_key( (string) ( $routes['use_one_connection'] ?? $routes['transactional']['wordpress'] ?? '' ) );
			foreach ( array_keys( $clean['transactional'] ) as $key ) {
				$clean['transactional'][ $key ] = $one;
			}
			foreach ( array_keys( $clean['marketing'] ) as $key ) {
				$clean['marketing'][ $key ] = $one;
			}
		}
		update_option( self::OPTION, $clean, false );
		self::$cache = $clean;
	}

	/**
	 * Whether Specify Connections has any route assignment saved.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$all = self::get_all();

		if ( ! empty( $all['use_one'] ) ) {
			return '' !== self::use_one_connection_id();
		}

		$transactional = is_array( $all['transactional'] ?? null ) ? $all['transactional'] : array();
		foreach ( array( 'wordpress', 'woocommerce' ) as $key ) {
			if ( '' !== sanitize_key( (string) ( $transactional[ $key ] ?? '' ) ) ) {
				return true;
			}
		}

		$marketing = is_array( $all['marketing'] ?? null ) ? $all['marketing'] : array();
		foreach ( array( 'newsletter', 'outreach' ) as $key ) {
			if ( '' !== sanitize_key( (string) ( $marketing[ $key ] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Assign One for Everything to a ready connection.
	 *
	 * @param string $connection_id Connection id.
	 * @return bool
	 */
	public static function assign_use_one( $connection_id ) {
		$connection_id = sanitize_key( (string) $connection_id );
		if ( '' === $connection_id ) {
			return false;
		}

		$rec = Mailpai_Smtp_Connection_Store::get( $connection_id );
		if ( ! is_array( $rec ) || empty( $rec['enabled'] ) ) {
			return false;
		}

		if ( Mailpai_Smtp_Connection_Store::needs_oauth_signin( $rec ) ) {
			return false;
		}

		self::save(
			array(
				'use_one'            => true,
				'use_one_connection' => $connection_id,
				'transactional'      => array(
					'wordpress'   => $connection_id,
					'woocommerce' => $connection_id,
				),
				'marketing'          => array(
					'newsletter' => $connection_id,
					'outreach'   => $connection_id,
				),
			)
		);

		/**
		 * Fires when SMTPPai auto-assigns the first connection to One for Everything.
		 *
		 * @param string $connection_id Connection id.
		 */
		do_action( 'mailpai_smtp_routes_auto_assigned', $connection_id );

		return true;
	}

	/**
	 * Auto-assign One for Everything when routes are still empty.
	 *
	 * @param string $connection_id Connection id.
	 * @return bool
	 */
	public static function maybe_auto_assign_use_one( $connection_id ) {
		if ( self::is_configured() ) {
			return false;
		}

		return self::assign_use_one( $connection_id );
	}

	/**
	 * Backfill One for Everything for sites that have a ready connection but no routes.
	 *
	 * @return string Assigned connection id, or empty string.
	 */
	public static function maybe_auto_assign_first_ready_connection() {
		if ( self::is_configured() ) {
			return '';
		}

		foreach ( Mailpai_Smtp_Connection_Store::get_ordered() as $rec ) {
			$id = sanitize_key( (string) ( $rec['id'] ?? '' ) );
			if ( '' === $id ) {
				continue;
			}

			if ( self::assign_use_one( $id ) ) {
				return $id;
			}
		}

		return '';
	}

	/**
	 * @param string $route Route slug.
	 * @return string Connection id.
	 */
	public static function get_connection_id( $route ) {
		$route = sanitize_key( (string) $route );
		$all   = self::get_all();

		if ( ! empty( $all['use_one'] ) ) {
			$one = self::use_one_connection_id();
			if ( '' !== $one ) {
				return $one;
			}
		}

		$map = array(
			'wordpress'   => $all['transactional']['wordpress'] ?? '',
			'woocommerce' => $all['transactional']['woocommerce'] ?? '',
			'newsletter'  => $all['marketing']['newsletter'] ?? '',
			'outreach'    => $all['marketing']['outreach'] ?? '',
		);

		if ( isset( $map[ $route ] ) && '' !== $map[ $route ] ) {
			return (string) $map[ $route ];
		}

		if ( in_array( $route, array( 'woocommerce' ), true ) && '' !== ( $map['wordpress'] ?? '' ) ) {
			return (string) $map['wordpress'];
		}

		return '';
	}

	/**
	 * Transactional route labels for UI.
	 *
	 * @return array<string,string>
	 */
	public static function transactional_labels() {
		$routes = array(
			'wordpress' => __( 'WordPress emails', 'smtp-pai' ),
		);
		if ( class_exists( 'WooCommerce' ) ) {
			$routes['woocommerce'] = __( 'WooCommerce emails', 'smtp-pai' );
		}
		return apply_filters( 'mailpai_smtp_transactional_routes', $routes );
	}

	/**
	 * Connection id when "one for everything" is enabled.
	 *
	 * @return string
	 */
	public static function use_one_connection_id() {
		$all = self::get_all();
		if ( empty( $all['use_one'] ) ) {
			return '';
		}

		return sanitize_key( (string) ( $all['transactional']['wordpress'] ?? '' ) );
	}

	/**
	 * Whether marketing routes (newsletter, outreach) apply.
	 *
	 * @return bool
	 */
	public static function marketing_available() {
		/**
		 * Show newsletter and outreach routes when MailPai is active.
		 *
		 * @param bool $available Default true when MailPai is active.
		 */
		return (bool) apply_filters( 'mailpai_smtp_marketing_routes_available', defined( 'MAILPAI_VERSION' ) );
	}

	/**
	 * @return array<string,string>
	 */
	public static function marketing_labels() {
		if ( ! self::marketing_available() ) {
			return array();
		}

		return apply_filters(
			'mailpai_smtp_marketing_routes',
			array(
				'newsletter' => __( 'Newsletter', 'smtp-pai' ),
				'outreach'   => __( 'Outreach', 'smtp-pai' ),
			)
		);
	}

	/**
	 * @param string $slug Route slug.
	 * @return string
	 */
	public static function label( $slug ) {
		$slug = sanitize_key( (string) $slug );
		$all  = self::transactional_labels() + self::marketing_labels();
		return isset( $all[ $slug ] ) ? $all[ $slug ] : ucfirst( $slug );
	}

	/**
	 * Route icon URL.
	 *
	 * @param string $file Filename under assets/img/routes/.
	 * @return string
	 */
	public static function route_icon_url( $file ) {
		return MAILPAI_SMTP_PLUGIN_URL . 'assets/img/routes/' . ltrim( (string) $file, '/' );
	}

	/**
	 * Title, description, and icon for a route row on Specify Connections.
	 *
	 * @param string $slug Route slug.
	 * @return array{title:string,description:string,icon:string}
	 */
	public static function route_meta( $slug ) {
		$slug = sanitize_key( (string) $slug );
		$all  = array(
			'wordpress'   => array(
				'title'       => __( 'WordPress mail', 'smtp-pai' ),
				'description' => __( 'Core WordPress emails like password reset, admin notifications, etc.', 'smtp-pai' ),
				'icon'        => self::route_icon_url( 'wordpress.png' ),
			),
			'woocommerce' => array(
				'title'       => __( 'WooCommerce mail', 'smtp-pai' ),
				'description' => __( 'Order confirmations, shipping updates, and other store notifications.', 'smtp-pai' ),
				'icon'        => self::route_icon_url( 'woocommerce.png' ),
			),
			'newsletter'  => array(
				'title'       => __( 'Newsletter', 'smtp-pai' ),
				'description' => __( 'Broadcast campaigns and subscriber newsletters via MailPai.', 'smtp-pai' ),
				'icon'        => self::route_icon_url( 'newsletter.png' ),
			),
			'outreach'    => array(
				'title'       => __( 'Outreach', 'smtp-pai' ),
				'description' => __( 'One-to-one outreach and follow-up campaigns via MailPai.', 'smtp-pai' ),
				'icon'        => self::route_icon_url( 'outreach.png' ),
			),
			'all'         => array(
				'title'       => __( 'All email', 'smtp-pai' ),
				'description' => __( 'Use one connection for transactional and marketing email.', 'smtp-pai' ),
				'icon'        => self::route_icon_url( 'all-mail.png' ),
			),
		);

		if ( isset( $all[ $slug ] ) ) {
			return $all[ $slug ];
		}

		$label = self::label( $slug );
		return array(
			'title'       => $label,
			'description' => '',
			'icon'        => self::route_icon_url( 'all-mail.png' ),
		);
	}

	/**
	 * Route labels assigned to a connection on Specify Connections.
	 *
	 * @param string $connection_id Connection id.
	 * @return string[]
	 */
	public static function route_labels_for_connection( $connection_id ) {
		$connection_id = sanitize_key( (string) $connection_id );
		if ( '' === $connection_id ) {
			return array();
		}

		$routes = self::get_all();
		$labels = self::transactional_labels() + self::marketing_labels();
		$out    = array();

		$transactional = is_array( $routes['transactional'] ?? null ) ? $routes['transactional'] : array();
		$marketing     = is_array( $routes['marketing'] ?? null ) ? $routes['marketing'] : array();

		if ( ! empty( $routes['use_one'] ) && ! empty( $transactional['wordpress'] ) && $connection_id === sanitize_key( (string) $transactional['wordpress'] ) ) {
			return array( __( 'For everything', 'smtp-pai' ) );
		}

		foreach ( $transactional as $slug => $assigned ) {
			$slug = sanitize_key( (string) $slug );
			if ( 'default' === $slug ) {
				continue;
			}
			if ( $connection_id === sanitize_key( (string) $assigned ) && isset( $labels[ $slug ] ) ) {
				$out[] = $labels[ $slug ];
			}
		}

		foreach ( $marketing as $slug => $assigned ) {
			$slug = sanitize_key( (string) $slug );
			if ( $connection_id === sanitize_key( (string) $assigned ) && isset( $labels[ $slug ] ) ) {
				$out[] = $labels[ $slug ];
			}
		}

		return array_values( array_unique( $out ) );
	}
}
