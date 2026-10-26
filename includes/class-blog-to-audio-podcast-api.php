<?php
/**
 * ElevenLabs API client: text to speech, voices, models and the account's credits.
 *
 * Every request goes to one fixed host over HTTPS with the key in a header.
 * Errors come back as WP_Error objects whose data says what to do next:
 *
 * - retry: a temporary problem (rate limit, too many requests at once, server
 *   busy, network). Try the same request again later.
 * - pause: a problem with the account or settings (bad key, out of credits,
 *   missing permission, unknown voice). Every request would fail the same
 *   way, so the whole queue stops until someone fixes it.
 * - post: a problem with this post's text only. Skip it and carry on.
 *
 * @package Blog_To_Audio_Podcast
 */

defined( 'ABSPATH' ) || exit;

/**
 * ElevenLabs client.
 */
final class Blog_To_Audio_Podcast_API {

	/**
	 * API host.
	 */
	const BASE = 'https://api.elevenlabs.io';

	/**
	 * Audio format for every request: 44.1 kHz MP3 at a constant 128 kbps.
	 * One fixed format means the chunks join cleanly, the duration follows
	 * from the file size, and it's within what Apple and Spotify accept.
	 * Every plan, including the free one, can use it.
	 */
	const FORMAT = 'mp3_44100_128';

	/**
	 * Error codes that mean the account or the settings need fixing.
	 * Includes the older "status" values ElevenLabs used to send.
	 */
	const PAUSE_CODES = array(
		'invalid_api_key',
		'missing_api_key',
		'invalid_authorization_header',
		'unauthorized',
		'sign_in_required',
		'needs_authorization',
		'quota_exceeded',
		'insufficient_credits',
		'payment_required',
		'forbidden',
		'insufficient_permissions',
		'missing_permissions',
		'workspace_access_denied',
		'feature_not_available',
		'subscription_required',
		'voice_access_denied',
		'model_access_denied',
		'voice_not_found',
		'model_not_found',
		'invalid_voice_id',
		'invalid_voice_settings',
		'unsupported_model',
		'invalid_output_format',
		'detected_unusual_activity',
		'free_users_not_allowed',
		'voice_limit_reached',
	);

	/**
	 * Error codes worth retrying after a wait.
	 */
	const RETRY_CODES = array(
		'rate_limit_exceeded',
		'concurrent_limit_exceeded',
		'too_many_concurrent_requests',
		'system_busy',
		'service_unavailable',
		'concurrent_modification',
		'internal_error',
	);

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Constructor.
	 *
	 * @param string $key ElevenLabs API key.
	 */
	public function __construct( $key ) {
		$this->key = (string) $key;
	}

	/**
	 * Turn text into speech.
	 *
	 * @param string $text     Text, within the model's per-request limit.
	 * @param string $voice_id Voice ID.
	 * @param string $model_id Model ID.
	 * @param array  $voice    Voice settings (stability, similarity_boost, ...).
	 * @param array  $context  Optional: previous_request_ids, previous_text, next_text.
	 * @param int    $timeout  Seconds to wait for the audio.
	 * @return array{audio:string,request_id:string,cost:int}|WP_Error
	 */
	public function tts( $text, $voice_id, $model_id, array $voice, array $context = array(), $timeout = 120 ) {
		$voice_id = preg_replace( '/[^A-Za-z0-9]/', '', (string) $voice_id );

		if ( '' === $voice_id ) {
			return $this->error( 'invalid_voice_id', __( 'Choose a voice first.', 'blog-to-audio-podcast' ), 'pause' );
		}

		$body = array(
			'text'           => (string) $text,
			'model_id'       => (string) $model_id,
			'voice_settings' => $voice,
		);

		foreach ( array( 'previous_request_ids', 'previous_text', 'next_text' ) as $field ) {
			if ( ! empty( $context[ $field ] ) ) {
				$body[ $field ] = $context[ $field ];
			}
		}

		$response = $this->request(
			'POST',
			'/v1/text-to-speech/' . rawurlencode( $voice_id ) . '?output_format=' . self::FORMAT,
			array(
				'body'    => wp_json_encode( $body ),
				'timeout' => $timeout,
				'accept'  => 'audio/mpeg',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		$data = (string) wp_remote_retrieve_body( $response );

		if ( '' === $data || false === strpos( $type, 'audio' ) ) {
			return $this->error( 'invalid_audio', __( 'ElevenLabs sent back something that isn\'t audio.', 'blog-to-audio-podcast' ), 'retry' );
		}

		return array(
			'audio'      => $data,
			'request_id' => preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) wp_remote_retrieve_header( $response, 'request-id' ) ),
			'cost'       => (int) wp_remote_retrieve_header( $response, 'character-cost' ),
		);
	}

	/**
	 * Every voice the account can use, its own first.
	 *
	 * @return array<string,array>|WP_Error voice_id => name, description, category, preview.
	 */
	public function voices() {
		$voices = array();
		$token  = '';

		// Five pages of 100 is far more than any account has.
		for ( $page = 0; $page < 5; $page++ ) {
			$query = array( 'page_size' => 100 );

			if ( '' !== $token ) {
				$query['next_page_token'] = $token;
			}

			$data = $this->get_json( '/v2/voices?' . http_build_query( $query, '', '&' ) );

			if ( is_wp_error( $data ) ) {
				return $voices ? $voices : $data;
			}

			foreach ( isset( $data['voices'] ) && is_array( $data['voices'] ) ? $data['voices'] : array() as $voice ) {
				if ( empty( $voice['voice_id'] ) || ! is_string( $voice['voice_id'] ) ) {
					continue;
				}

				$id = preg_replace( '/[^A-Za-z0-9]/', '', $voice['voice_id'] );

				$voices[ $id ] = array(
					'name'        => isset( $voice['name'] ) ? sanitize_text_field( (string) $voice['name'] ) : $id,
					'description' => self::describe_voice( $voice ),
					'category'    => isset( $voice['category'] ) ? sanitize_key( (string) $voice['category'] ) : '',
					'preview'     => isset( $voice['preview_url'] ) ? self::safe_preview_url( (string) $voice['preview_url'] ) : '',
				);
			}

			if ( empty( $data['has_more'] ) || empty( $data['next_page_token'] ) ) {
				break;
			}

			$token = (string) $data['next_page_token'];
		}

		// Own voices (cloned, designed, professional) before the built-in ones.
		uasort(
			$voices,
			function ( $a, $b ) {
				$a_own = 'premade' !== $a['category'];
				$b_own = 'premade' !== $b['category'];

				if ( $a_own !== $b_own ) {
					return $a_own ? -1 : 1;
				}

				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return $voices;
	}

	/**
	 * One voice, to check a pasted voice ID and find its name.
	 *
	 * @param string $voice_id Voice ID.
	 * @return array{name:string,description:string,category:string,preview:string}|WP_Error
	 */
	public function voice( $voice_id ) {
		$voice_id = preg_replace( '/[^A-Za-z0-9]/', '', (string) $voice_id );
		$data     = '' !== $voice_id ? $this->get_json( '/v1/voices/' . rawurlencode( $voice_id ) ) : $this->error( 'invalid_voice_id', __( 'That isn\'t a voice ID.', 'blog-to-audio-podcast' ), 'post' );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return array(
			'name'        => isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : $voice_id,
			'description' => self::describe_voice( $data ),
			'category'    => isset( $data['category'] ) ? sanitize_key( (string) $data['category'] ) : '',
			'preview'     => isset( $data['preview_url'] ) ? self::safe_preview_url( (string) $data['preview_url'] ) : '',
		);
	}

	/**
	 * A short description from a voice's labels: "British, male, narration".
	 *
	 * @param array $voice Voice from the API.
	 * @return string
	 */
	private static function describe_voice( array $voice ) {
		$labels = isset( $voice['labels'] ) && is_array( $voice['labels'] ) ? $voice['labels'] : array();
		$parts  = array();

		foreach ( array( 'accent', 'gender', 'age', 'use_case' ) as $key ) {
			if ( ! empty( $labels[ $key ] ) && is_string( $labels[ $key ] ) ) {
				$parts[] = str_replace( '_', ' ', sanitize_text_field( $labels[ $key ] ) );
			}
		}

		return ucfirst( implode( ', ', $parts ) );
	}

	/**
	 * Keep a voice preview only if it's an HTTPS link on ElevenLabs' own
	 * hosts, since the settings page plays it in the browser.
	 *
	 * @param string $url Preview URL.
	 * @return string
	 */
	private static function safe_preview_url( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return '';
		}

		$path    = (string) wp_parse_url( $url, PHP_URL_PATH );
		$allowed = ( 'storage.googleapis.com' === $host && 0 === strpos( $path, '/eleven-public-prod/' ) )
			|| 'elevenlabs.io' === $host || '.elevenlabs.io' === substr( $host, -14 );

		return $allowed ? esc_url_raw( $url ) : '';
	}

	/**
	 * Text-to-speech models the account can use.
	 *
	 * @return array<string,array>|WP_Error model_id => details.
	 */
	public function models() {
		$data = $this->get_json( '/v1/models' );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$models = array();

		foreach ( is_array( $data ) ? $data : array() as $model ) {
			if ( empty( $model['model_id'] ) || empty( $model['can_do_text_to_speech'] ) || ! empty( $model['requires_alpha_access'] ) ) {
				continue;
			}

			$id   = sanitize_key( (string) $model['model_id'] );
			$cost = isset( $model['model_rates']['character_cost_multiplier'] ) ? (float) $model['model_rates']['character_cost_multiplier'] : 1.0;

			if ( isset( $model['model_rates']['cost_discount_multiplier'] ) ) {
				$cost *= (float) $model['model_rates']['cost_discount_multiplier'];
			}

			$models[ $id ] = array(
				'name'        => isset( $model['name'] ) ? sanitize_text_field( (string) $model['name'] ) : $id,
				'description' => isset( $model['description'] ) ? sanitize_text_field( (string) $model['description'] ) : '',
				'max'         => ! empty( $model['maximum_text_length_per_request'] ) ? (int) $model['maximum_text_length_per_request'] : 5000,
				'cost'        => $cost,
				'style'       => ! empty( $model['can_use_style'] ),
				'boost'       => ! empty( $model['can_use_speaker_boost'] ),
				'fast'        => isset( $model['concurrency_group'] ) && 'turbo' === $model['concurrency_group'],
				'languages'   => isset( $model['languages'] ) && is_array( $model['languages'] ) ? count( $model['languages'] ) : 0,
			);
		}

		return $models;
	}

	/**
	 * The account's plan and credits.
	 *
	 * @return array{tier:string,used:int,limit:int,reset:int}|WP_Error
	 */
	public function subscription() {
		$data = $this->get_json( '/v1/user/subscription' );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return array(
			'tier'  => isset( $data['tier'] ) ? sanitize_key( (string) $data['tier'] ) : '',
			'used'  => isset( $data['character_count'] ) ? (int) $data['character_count'] : 0,
			'limit' => isset( $data['character_limit'] ) ? (int) $data['character_limit'] : 0,
			'reset' => isset( $data['next_character_count_reset_unix'] ) ? (int) $data['next_character_count_reset_unix'] : 0,
		);
	}

	/**
	 * GET a JSON endpoint.
	 *
	 * @param string $path Path and query.
	 * @return array|WP_Error
	 */
	private function get_json( $path ) {
		$response = $this->request( 'GET', $path, array( 'timeout' => 20 ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			return $this->error( 'invalid_response', __( 'ElevenLabs sent back a response that couldn\'t be read.', 'blog-to-audio-podcast' ), 'retry' );
		}

		return $data;
	}

	/**
	 * Send a request.
	 *
	 * @param string $method GET or POST.
	 * @param string $path   Path and query.
	 * @param array  $args   body, timeout, accept.
	 * @return array|WP_Error The response, or a classified error.
	 */
	private function request( $method, $path, array $args ) {
		if ( '' === $this->key ) {
			return $this->error( 'missing_api_key', __( 'Add your ElevenLabs API key first.', 'blog-to-audio-podcast' ), 'pause' );
		}

		$response = wp_remote_request(
			self::BASE . $path,
			array(
				'method'      => $method,
				'timeout'     => isset( $args['timeout'] ) ? (int) $args['timeout'] : 20,
				'redirection' => 0,
				'sslverify'   => true,
				'user-agent'  => 'BlogPostToAudioPodcast/' . BLOG_TO_AUDIO_PODCAST_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
				'headers'     => array(
					'xi-api-key'   => $this->key,
					'Accept'       => isset( $args['accept'] ) ? $args['accept'] : 'application/json',
					'Content-Type' => 'application/json',
				),
				'body'        => isset( $args['body'] ) ? $args['body'] : null,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->error(
				'http_request_failed',
				/* translators: %s: error from the server's HTTP library. */
				sprintf( __( 'Couldn\'t reach ElevenLabs: %s', 'blog-to-audio-podcast' ), $response->get_error_message() ),
				'retry'
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status >= 200 && $status < 300 ) {
			return $response;
		}

		return $this->http_error( $status, (string) wp_remote_retrieve_body( $response ), $response );
	}

	/**
	 * Turn an error response into a classified WP_Error.
	 *
	 * @param int    $status   HTTP status.
	 * @param string $body     Response body.
	 * @param array  $response Full response.
	 * @return WP_Error
	 */
	private function http_error( $status, $body, $response ) {
		$data    = json_decode( $body, true );
		$detail  = is_array( $data ) && isset( $data['detail'] ) ? $data['detail'] : null;
		$code    = '';
		$message = '';

		if ( is_array( $detail ) && isset( $detail['message'] ) ) {
			$code    = ! empty( $detail['code'] ) ? (string) $detail['code'] : ( ! empty( $detail['status'] ) ? (string) $detail['status'] : '' );
			$message = (string) $detail['message'];
		} elseif ( is_array( $detail ) ) {
			// Validation errors: a list of {loc, msg, type}.
			$messages = array();

			foreach ( $detail as $item ) {
				if ( is_array( $item ) && isset( $item['msg'] ) ) {
					$messages[] = (string) $item['msg'];
				}
			}

			$code    = 'validation_error';
			$message = implode( ' ', $messages );
		} elseif ( is_string( $detail ) ) {
			$message = $detail;
		}

		$code    = sanitize_key( $code );
		$message = sanitize_text_field( $message );

		if ( '' === $message ) {
			/* translators: %d: HTTP status code. */
			$message = sprintf( __( 'ElevenLabs returned an error (HTTP %d).', 'blog-to-audio-podcast' ), $status );
		}

		if ( 429 === $status || 408 === $status || $status >= 500 || in_array( $code, self::RETRY_CODES, true ) ) {
			$kind = 'retry';
		} elseif ( in_array( $status, array( 401, 402, 403 ), true ) || in_array( $code, self::PAUSE_CODES, true ) ) {
			$kind = 'pause';
		} else {
			$kind = 'post';
		}

		$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );

		return $this->error( '' !== $code ? $code : 'http_' . $status, $message, $kind, $status, $retry_after );
	}

	/**
	 * Build a classified error.
	 *
	 * @param string $code        Error code.
	 * @param string $message     Message for people.
	 * @param string $kind        retry, pause or post.
	 * @param int    $status      HTTP status, if any.
	 * @param int    $retry_after Seconds the server asked us to wait.
	 * @return WP_Error
	 */
	private function error( $code, $message, $kind, $status = 0, $retry_after = 0 ) {
		return new WP_Error(
			$code,
			$message,
			array(
				'kind'        => $kind,
				'status'      => (int) $status,
				'retry_after' => max( 0, (int) $retry_after ),
			)
		);
	}

	/**
	 * What to do about an error: retry, pause or post.
	 *
	 * @param WP_Error $error Error.
	 * @return string
	 */
	public static function kind( WP_Error $error ) {
		$data = $error->get_error_data();

		return is_array( $data ) && isset( $data['kind'] ) ? (string) $data['kind'] : 'post';
	}
}
