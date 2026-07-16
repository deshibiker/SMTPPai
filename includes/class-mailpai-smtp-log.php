<?php
/**
 * Email log repository.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Log
 */
class Mailpai_Smtp_Log {

	/** @var array|null */
	private static $settings_cache = null;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'mailpai_smtp_log_cleanup', array( __CLASS__, 'cleanup' ) );
	}

	/**
	 * Schedule daily log retention cleanup.
	 */
	public static function schedule_cleanup() {
		if ( ! wp_next_scheduled( 'mailpai_smtp_log_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'mailpai_smtp_log_cleanup' );
		}
	}

	/**
	 * @param array $row Log row.
	 * @return int Insert id.
	 */
	public static function insert( array $row ) {
		global $wpdb;

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-controlled table name.
		$table = Mailpai_Smtp_Schema::log_table();
		$settings = self::settings();

		$data = array(
			'connection_id'         => sanitize_key( (string) ( $row['connection_id'] ?? '' ) ),
			'primary_connection_id' => sanitize_key( (string) ( $row['primary_connection_id'] ?? '' ) ),
			'provider'              => sanitize_key( (string) ( $row['provider'] ?? '' ) ),
			'route'                 => sanitize_key( (string) ( $row['route'] ?? '' ) ),
			'recipient'             => sanitize_text_field( (string) ( $row['recipient'] ?? '' ) ),
			'subject'               => sanitize_text_field( (string) ( $row['subject'] ?? '' ) ),
			'status'                => in_array( $row['status'] ?? '', array( 'sent', 'failed', 'pending' ), true ) ? $row['status'] : 'sent',
			'failover'              => ! empty( $row['failover'] ) ? 1 : 0,
			'error_message'         => isset( $row['error_message'] ) ? sanitize_text_field( (string) $row['error_message'] ) : '',
			'headers'               => self::encode_headers( $row['headers'] ?? null ),
			'body'                  => ( ! empty( $settings['log_body'] ) && isset( $row['body'] ) ) ? (string) $row['body'] : null,
			'created_at'            => current_time( 'mysql', true ),
		);

		$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		self::bump_stats_cache( $data['status'], ! empty( $row['failover'] ) );

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array $args Query args.
	 * @return array{items:array,total:int,page:int,per_page:int,pages:int}
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-controlled table name.
		$table  = Mailpai_Smtp_Schema::log_table();
		$page   = max( 1, absint( $args['page'] ?? 1 ) );
		$per    = max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) );
		$offset = ( $page - 1 ) * $per;

		list( $where_sql, $values ) = self::build_where( $args );

		if ( $values ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dynamic WHERE uses only prepared placeholders.
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", ...$values ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is plugin-controlled.
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );
		}

		$pages = max( 1, (int) ceil( $total / $per ) );
		if ( $page > $pages ) {
			$page   = $pages;
			$offset = ( $page - 1 ) * $per;
		}

		$list_sql = "SELECT id, connection_id, route, recipient, subject, status, error_message, failover, created_at FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";

		if ( $values ) {
			$list_args = array_merge( $values, array( $per, $offset ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dynamic WHERE; table name is plugin-controlled.
			$items = $wpdb->get_results( call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $list_sql ), $list_args ) ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name and WHERE fragments are plugin-controlled.
			$items = $wpdb->get_results( $wpdb->prepare( $list_sql, $per, $offset ), ARRAY_A );
		}

		return array(
			'items'    => is_array( $items ) ? $items : array(),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per,
			'pages'    => $pages,
		);
	}

	/**
	 * Adjacent log ids for modal prev/next within the current filtered list (newest first).
	 *
	 * @param int   $id   Current log id.
	 * @param array $args Filter args (status, search, date_from, date_to, etc.).
	 * @return array{prev_id:int,next_id:int}
	 */
	public static function adjacent_ids( $id, array $args = array() ) {
		global $wpdb;

		$id = absint( $id );
		if ( $id <= 0 ) {
			return array(
				'prev_id' => 0,
				'next_id' => 0,
			);
		}

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-controlled table name.
		$table = Mailpai_Smtp_Schema::log_table();
		list( $where_sql, $values ) = self::build_where( $args );

		$prev_sql = "SELECT id FROM {$table} WHERE {$where_sql} AND id > %d ORDER BY id ASC LIMIT 1";
		$next_sql = "SELECT id FROM {$table} WHERE {$where_sql} AND id < %d ORDER BY id DESC LIMIT 1";

		if ( $values ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dynamic WHERE; table name is plugin-controlled.
			$prev_id = (int) $wpdb->get_var( $wpdb->prepare( $prev_sql, ...array_merge( $values, array( $id ) ) ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dynamic WHERE; table name is plugin-controlled.
			$next_id = (int) $wpdb->get_var( $wpdb->prepare( $next_sql, ...array_merge( $values, array( $id ) ) ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name and WHERE fragments are plugin-controlled.
			$prev_id = (int) $wpdb->get_var( $wpdb->prepare( $prev_sql, $id ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name and WHERE fragments are plugin-controlled.
			$next_id = (int) $wpdb->get_var( $wpdb->prepare( $next_sql, $id ) );
		}

		return array(
			'prev_id' => max( 0, $prev_id ),
			'next_id' => max( 0, $next_id ),
		);
	}

	/**
	 * @param array $args Query args.
	 * @return array{0:string,1:array}
	 */
	private static function build_where( array $args ) {
		global $wpdb;

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $args['status'] ) && in_array( $args['status'], array( 'sent', 'failed' ), true ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( ! empty( $args['route'] ) ) {
			$where[]  = 'route = %s';
			$values[] = sanitize_key( (string) $args['route'] );
		}

		if ( ! empty( $args['connection_id'] ) ) {
			$where[]  = 'connection_id = %s';
			$values[] = sanitize_key( (string) $args['connection_id'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( sanitize_text_field( (string) $args['search'] ) ) . '%';
			$where[]  = '(subject LIKE %s OR recipient LIKE %s OR error_message LIKE %s)';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$values[] = sanitize_text_field( (string) $args['date_from'] ) . ' 00:00:00';
		}

		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$values[] = sanitize_text_field( (string) $args['date_to'] ) . ' 23:59:59';
		}

		return array( implode( ' AND ', $where ), $values );
	}

	/**
	 * @param int $id Log id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-controlled table name.
		$table = Mailpai_Smtp_Schema::log_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Structured log detail for the admin drawer.
	 *
	 * @param array $row Raw log row.
	 * @return array|null
	 */
	public static function detail_for_view( array $row ) {
		$settings   = self::settings();
		$headers    = self::decode_headers( $row['headers'] ?? '' );
		$parsed     = self::parse_header_map( $headers['list'] );
		$meta       = $headers['meta'];
		$connection = Mailpai_Smtp_Connection_Store::get( $row['connection_id'] ?? '' );
		$primary    = ! empty( $row['primary_connection_id'] ) ? Mailpai_Smtp_Connection_Store::get( $row['primary_connection_id'] ) : null;
		$provider   = Mailpai_Smtp_Provider_Registry::get( $row['provider'] ?? '' );
		$from_name  = (string) ( $meta['from_name'] ?? '' );
		$from_email = sanitize_email( (string) ( $meta['from_email'] ?? '' ) );

		if ( ! is_email( $from_email ) && is_array( $connection ) ) {
			$from_email = sanitize_email( $connection['from_email'] ?? '' );
		}
		if ( '' === $from_name && is_array( $connection ) ) {
			$from_name = sanitize_text_field( $connection['from_name'] ?? '' );
		}

		$from_display = self::format_address( $from_name, $from_email );
		if ( '' === $from_display && ! empty( $parsed['from'] ) ) {
			$from_display = sanitize_text_field( $parsed['from'] );
		}

		$transport      = sanitize_key( (string) ( $meta['transport'] ?? ( $provider['transport'] ?? 'smtp' ) ) );
		$provider_label = ! empty( $provider['label'] ) ? (string) $provider['label'] : ucfirst( (string) ( $row['provider'] ?? '' ) );
		$transport_line = self::transport_label( $transport, $meta, $provider_label );
		$date_format    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$created_local  = get_date_from_gmt( $row['created_at'] ?? '', $date_format );
		$created_utc    = gmdate( 'M j, Y H:i', strtotime( (string) ( $row['created_at'] ?? '' ) . ' +0000' ) ) . ' UTC';
		$body_stored    = ! empty( $settings['log_body'] ) && ! empty( $row['body'] );
		$header_rows    = array();

		foreach ( $headers['list'] as $header_line ) {
			$header_line = trim( (string) $header_line );
			if ( '' === $header_line || false === strpos( $header_line, ':' ) ) {
				continue;
			}
			list( $name, $value ) = array_map( 'trim', explode( ':', $header_line, 2 ) );
			$header_rows[]        = array(
				'name'  => sanitize_text_field( $name ),
				'value' => sanitize_text_field( $value ),
			);
		}

		$status = sanitize_key( (string) ( $row['status'] ?? '' ) );
		if ( 'sent' === $status ) {
			$status_label = __( 'Sent', 'smtp-pai' );
		} elseif ( 'failed' === $status ) {
			$status_label = __( 'Failed', 'smtp-pai' );
		} else {
			$status_label = ucfirst( $status );
		}

		$server_status = sanitize_textarea_field( (string) ( $meta['server_status'] ?? '' ) );
		if ( '' === $server_status && '' !== (string) ( $row['error_message'] ?? '' ) ) {
			$server_status = sanitize_textarea_field( (string) $row['error_message'] );
		}

		return array(
			'id'                    => (int) ( $row['id'] ?? 0 ),
			'subject'               => sanitize_text_field( (string) ( $row['subject'] ?? '' ) ),
			'status'                => $status,
			'status_label'          => $status_label,
			'recipient'             => sanitize_text_field( (string) ( $row['recipient'] ?? '' ) ),
			'from'                  => $from_display,
			'return_path'           => is_email( (string) ( $meta['return_path'] ?? '' ) ) ? sanitize_email( (string) $meta['return_path'] ) : $from_email,
			'reply_to'              => sanitize_text_field( (string) ( $parsed['reply-to'] ?? '' ) ),
			'cc'                    => sanitize_text_field( (string) ( $parsed['cc'] ?? '' ) ),
			'bcc'                   => sanitize_text_field( (string) ( $parsed['bcc'] ?? '' ) ),
			'message_id'            => sanitize_text_field( (string) ( $parsed['message-id'] ?? '' ) ),
			'content_type'          => sanitize_text_field( (string) ( $parsed['content-type'] ?? '' ) ),
			'route'                 => sanitize_key( (string) ( $row['route'] ?? '' ) ),
			'route_label'           => Mailpai_Smtp_Routes::label( $row['route'] ?? '' ),
			'connection_id'         => sanitize_key( (string) ( $row['connection_id'] ?? '' ) ),
			'connection_label'      => is_array( $connection ) ? Mailpai_Smtp_Connection_Store::title( $connection ) : (string) ( $row['connection_id'] ?? '' ),
			'primary_connection_id' => sanitize_key( (string) ( $row['primary_connection_id'] ?? '' ) ),
			'primary_connection_label' => is_array( $primary ) ? Mailpai_Smtp_Connection_Store::title( $primary ) : (string) ( $row['primary_connection_id'] ?? '' ),
			'provider'              => sanitize_key( (string) ( $row['provider'] ?? '' ) ),
			'provider_label'        => $provider_label,
			'transport'             => $transport,
			'transport_label'       => $transport_line,
			'failover'              => ! empty( $row['failover'] ),
			'created_at'            => $created_local,
			'created_at_utc'        => $created_utc,
			'error_message'         => sanitize_text_field( (string) ( $row['error_message'] ?? '' ) ),
			'server_status'         => $server_status,
			'server_status_summary' => self::server_status_summary( $server_status, $status ),
			'headers'               => $header_rows,
			'body'                  => $body_stored ? (string) $row['body'] : '',
			'body_stored'           => $body_stored,
			'body_logging_enabled'  => ! empty( $settings['log_body'] ),
		);
	}

	/**
	 * @param string $server_status Stored server response.
	 * @param string $log_status    Log status slug.
	 * @return string
	 */
	private static function server_status_summary( $server_status, $log_status ) {
		$server_status = trim( (string) $server_status );
		if ( '' !== $server_status ) {
			$first = strtok( $server_status, "\n" );
			return sanitize_text_field( (string) $first );
		}
		if ( 'failed' === $log_status ) {
			return __( 'Delivery failed', 'smtp-pai' );
		}
		return __( 'Not recorded', 'smtp-pai' );
	}

	/**
	 * @param mixed $headers Header payload.
	 * @return string|null
	 */
	private static function encode_headers( $headers ) {
		if ( null === $headers ) {
			return null;
		}

		if ( is_string( $headers ) ) {
			return $headers;
		}

		if ( ! is_array( $headers ) ) {
			return null;
		}

		if ( isset( $headers['list'] ) || isset( $headers['meta'] ) ) {
			return wp_json_encode(
				array(
					'list' => is_array( $headers['list'] ?? null ) ? $headers['list'] : array(),
					'meta' => is_array( $headers['meta'] ?? null ) ? $headers['meta'] : array(),
				)
			);
		}

		$list = array();
		foreach ( $headers as $header ) {
			if ( is_string( $header ) && '' !== trim( $header ) ) {
				$list[] = $header;
			}
		}

		return wp_json_encode(
			array(
				'list' => $list,
				'meta' => array(),
			)
		);
	}

	/**
	 * @param string $raw Stored headers JSON.
	 * @return array{list:array,meta:array}
	 */
	private static function decode_headers( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array(
				'list' => array(),
				'meta' => array(),
			);
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array(
				'list' => array(),
				'meta' => array(),
			);
		}

		if ( isset( $decoded['list'] ) || isset( $decoded['meta'] ) ) {
			return array(
				'list' => is_array( $decoded['list'] ?? null ) ? $decoded['list'] : array(),
				'meta' => is_array( $decoded['meta'] ?? null ) ? $decoded['meta'] : array(),
			);
		}

		$list = array();
		foreach ( $decoded as $item ) {
			if ( is_string( $item ) && '' !== trim( $item ) ) {
				$list[] = $item;
			}
		}

		return array(
			'list' => $list,
			'meta' => array(),
		);
	}

	/**
	 * @param array $headers Header lines.
	 * @return array<string,string>
	 */
	private static function parse_header_map( array $headers ) {
		$map = array();
		foreach ( $headers as $header ) {
			$header = trim( (string) $header );
			if ( '' === $header || false === strpos( $header, ':' ) ) {
				continue;
			}
			list( $name, $value ) = array_map( 'trim', explode( ':', $header, 2 ) );
			$map[ strtolower( $name ) ] = $value;
		}
		return $map;
	}

	/**
	 * @param string $name  Sender name.
	 * @param string $email Sender email.
	 * @return string
	 */
	private static function format_address( $name, $email ) {
		if ( ! is_email( $email ) ) {
			return '';
		}
		if ( '' !== $name ) {
			return sprintf( '%s <%s>', $name, $email );
		}
		return $email;
	}

	/**
	 * @param string $transport      Transport slug.
	 * @param array  $meta           Stored delivery meta.
	 * @param string $provider_label Provider label.
	 * @return string
	 */
	private static function transport_label( $transport, array $meta, $provider_label ) {
		if ( 'api' === $transport ) {
			/* translators: %s: provider name */
			return sprintf( __( 'API (%s)', 'smtp-pai' ), $provider_label );
		}

		$host = sanitize_text_field( (string) ( $meta['host'] ?? '' ) );
		$port = absint( $meta['port'] ?? 0 );
		if ( '' !== $host && $port > 0 ) {
			return sprintf( 'SMTP · %s:%d', $host, $port );
		}
		if ( '' !== $host ) {
			return sprintf( 'SMTP · %s', $host );
		}

		return 'SMTP';
	}

	/**
	 * @param int $id Log id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-controlled table name.
		$table = Mailpai_Smtp_Schema::log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (bool) $wpdb->delete( $table, array( 'id' => absint( $id ) ), array( '%d' ) );
	}

	/**
	 * @param int[] $ids Log ids.
	 */
	public static function delete_many( array $ids ) {
		foreach ( $ids as $id ) {
			self::delete( absint( $id ) );
		}
	}

	/**
	 * Delete all logs.
	 */
	public static function delete_all() {
		global $wpdb;
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-controlled table name.
		$table = Mailpai_Smtp_Schema::log_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		delete_transient( 'mailpai_smtp_log_stats_today' );
	}

	/**
	 * @return array{sent:int,failed:int,failover:int}
	 */
	public static function stats_today() {
		$cached = get_transient( 'mailpai_smtp_log_stats_today' );
		if ( is_array( $cached ) && ( $cached['date'] ?? '' ) === gmdate( 'Y-m-d' ) ) {
			return array(
				'sent'     => (int) ( $cached['sent'] ?? 0 ),
				'failed'   => (int) ( $cached['failed'] ?? 0 ),
				'failover' => (int) ( $cached['failover'] ?? 0 ),
			);
		}

		global $wpdb;
		$table = Mailpai_Smtp_Schema::log_table();
		$start = gmdate( 'Y-m-d' ) . ' 00:00:00';
		$stats_sql = "SELECT
			SUM(status = 'sent') AS sent,
			SUM(status = 'failed') AS failed,
			SUM(failover = 1) AS failover
		FROM {$table}
		WHERE created_at >= %s";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is plugin-controlled.
		$row = $wpdb->get_row( $wpdb->prepare( $stats_sql, $start ), ARRAY_A );

		$stats = array(
			'date'     => gmdate( 'Y-m-d' ),
			'sent'     => (int) ( $row['sent'] ?? 0 ),
			'failed'   => (int) ( $row['failed'] ?? 0 ),
			'failover' => (int) ( $row['failover'] ?? 0 ),
		);
		set_transient( 'mailpai_smtp_log_stats_today', $stats, DAY_IN_SECONDS );
		return array(
			'sent'     => $stats['sent'],
			'failed'   => $stats['failed'],
			'failover' => $stats['failover'],
		);
	}

	/**
	 * @param string $status   Log status.
	 * @param bool   $failover Whether failover was used.
	 */
	private static function bump_stats_cache( $status, $failover ) {
		$cached = get_transient( 'mailpai_smtp_log_stats_today' );
		if ( ! is_array( $cached ) || ( $cached['date'] ?? '' ) !== gmdate( 'Y-m-d' ) ) {
			return;
		}

		if ( 'sent' === $status ) {
			++$cached['sent'];
		} elseif ( 'failed' === $status ) {
			++$cached['failed'];
		}
		if ( $failover ) {
			++$cached['failover'];
		}

		set_transient( 'mailpai_smtp_log_stats_today', $cached, DAY_IN_SECONDS );
	}

	/**
	 * @return array
	 */
	public static function settings() {
		if ( null !== self::$settings_cache ) {
			return self::$settings_cache;
		}

		$s = get_option( 'mailpai_smtp_settings', array() );
		self::$settings_cache = is_array( $s ) ? $s : array();
		return self::$settings_cache;
	}

	/**
	 * Purge old rows.
	 */
	public static function cleanup() {
		global $wpdb;
		$settings = self::settings();
		$days     = max( 1, absint( $settings['log_retention_days'] ?? 14 ) );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-controlled table name.
		$table    = Mailpai_Smtp_Schema::log_table();
		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is plugin-controlled.
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s LIMIT 1000", $cutoff ) );
		} while ( $deleted > 0 );
	}
}
