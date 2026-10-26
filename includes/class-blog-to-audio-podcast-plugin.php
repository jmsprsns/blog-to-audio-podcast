<?php
/**
 * Main plugin orchestrator: owns the settings and boots every part.
 *
 * @package Blog_To_Audio_Podcast
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton.
 */
final class Blog_To_Audio_Podcast_Plugin {

	/**
	 * Option key holding the plugin's settings array.
	 */
	const OPTION = 'blog_to_audio_podcast_settings';

	/**
	 * Option holding the ElevenLabs API key, encrypted. Kept apart from the
	 * settings so it never travels with them (exports, REST, debug output).
	 */
	const KEY_OPTION = 'blog_to_audio_podcast_api_key';

	/**
	 * Define this in wp-config.php to keep the key out of the database.
	 */
	const KEY_CONSTANT = 'BLOG_TO_AUDIO_PODCAST_API_KEY';

	/**
	 * Prefix marking an encrypted key.
	 */
	const KEY_PREFIX = 'btap1:';

	/**
	 * Option holding what past audio really cost, per model, for estimates.
	 */
	const RATES_OPTION = 'blog_to_audio_podcast_rates';

	/**
	 * Default voice: George, one of ElevenLabs' built-in voices, which every
	 * account can use.
	 */
	const DEFAULT_VOICE = 'JBFqnCBsd6RMkjVDRZzb';

	/**
	 * Default model.
	 */
	const DEFAULT_MODEL = 'eleven_v4';

	/**
	 * Single shared instance.
	 *
	 * @var Blog_To_Audio_Podcast_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings cache for this request.
	 *
	 * @var array|null
	 */
	private $settings = null;

	/**
	 * Get (and lazily create) the shared instance.
	 *
	 * @return Blog_To_Audio_Podcast_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	private function __construct() {
		add_action( 'update_option_' . self::OPTION, array( $this, 'flush_settings_cache' ) );
		add_action( 'add_option_' . self::OPTION, array( $this, 'flush_settings_cache' ) );

		Blog_To_Audio_Podcast_Audio::init();
		Blog_To_Audio_Podcast_Queue::init();
		Blog_To_Audio_Podcast_Player::init( $this );
		Blog_To_Audio_Podcast_Feed::init();
		Blog_To_Audio_Podcast_Editor::init( $this );

		if ( is_admin() ) {
			new Blog_To_Audio_Podcast_Admin( $this );
		}

		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
	}

	/**
	 * Default value for every setting.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			// Voice.
			'model_id'            => self::DEFAULT_MODEL,
			'voice_id'            => self::DEFAULT_VOICE,
			'voice_name'          => 'George',
			'stability'           => 0.5,
			'similarity'          => 0.75,
			'style'               => 0.0,
			'speaker_boost'       => 1,
			'speed'               => 1.0,

			// What gets read.
			'post_types'          => array( 'post' ),
			'auto_create'         => 0,
			/* translators: Keep the {title} and {site} placeholders as they are. */
			'intro'               => __( 'You\'re listening to {title}, from {site}. This audio version is read by an AI voice.', 'blog-to-audio-podcast' ),
			'outro'               => '',
			'pronunciations'      => '',

			// Player.
			'player'              => 'before',
			'player_label'        => __( 'Listen to this article', 'blog-to-audio-podcast' ),
			'player_duration'     => 1,
			'player_disclosure'   => 1,
			'player_download'     => 0,
			'attribution'         => 'auto',
			'schema'              => 1,

			// Podcast feed.
			'feed'                => 1,
			'feed_slug'           => 'podcast',
			'podcast_title'       => '',
			'podcast_description' => '',
			'podcast_author'      => '',
			'podcast_owner_name'  => '',
			'podcast_owner_email' => '',
			'podcast_image'       => 0,
			'podcast_category'    => '',
			'podcast_explicit'    => 0,
			'podcast_language'    => '',
			'podcast_copyright'   => '',
			'podcast_limit'       => 100,
			'podcast_disclosure'  => 1,
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		if ( null === $this->settings ) {
			$saved          = get_option( self::OPTION, array() );
			$this->settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}

		return $this->settings;
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key from {@see defaults()}.
	 * @return mixed Null for an unknown key.
	 */
	public function get_setting( $key ) {
		$settings = $this->get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Forget the cached settings after they're saved.
	 *
	 * @return void
	 */
	public function flush_settings_cache() {
		$this->settings = null;
	}

	/**
	 * Post types audio can be made for.
	 *
	 * @return string[]
	 */
	public function post_types() {
		// Public types only, whatever was saved: audio and the feed are public.
		$types = array_filter(
			(array) $this->get_setting( 'post_types' ),
			function ( $type ) {
				$object = is_string( $type ) ? get_post_type_object( $type ) : null;

				return $object && $object->public && 'attachment' !== $type;
			}
		);

		/**
		 * Filters the post types that get audio.
		 *
		 * @param string[] $types Post type names.
		 */
		return array_values( (array) apply_filters( 'blog_to_audio_podcast_post_types', $types ) );
	}

	/**
	 * Whether a post's type gets audio.
	 *
	 * @param WP_Post|int $post Post.
	 * @return bool
	 */
	public function supports( $post ) {
		$post = get_post( $post );

		return $post instanceof WP_Post && in_array( $post->post_type, $this->post_types(), true );
	}

	// API key ----------------------------------------------------------------

	/**
	 * The ElevenLabs API key, from wp-config.php or the database.
	 *
	 * @return string Empty when there isn't one (or it can't be decrypted).
	 */
	public static function api_key() {
		if ( self::key_in_config() ) {
			return trim( (string) constant( self::KEY_CONSTANT ) );
		}

		return self::decrypt( (string) get_option( self::KEY_OPTION, '' ) );
	}

	/**
	 * Whether the key is set in wp-config.php.
	 *
	 * @return bool
	 */
	public static function key_in_config() {
		return defined( self::KEY_CONSTANT ) && '' !== trim( (string) constant( self::KEY_CONSTANT ) );
	}

	/**
	 * Whether a key is stored but can no longer be read (the site's salts changed).
	 *
	 * @return bool
	 */
	public static function key_unreadable() {
		$stored = (string) get_option( self::KEY_OPTION, '' );

		return ! self::key_in_config() && '' !== $stored && '' === self::decrypt( $stored );
	}

	/**
	 * Save (or, with an empty string, remove) the API key.
	 *
	 * @param string $key Plain key.
	 * @return void
	 */
	public static function save_api_key( $key ) {
		$key = trim( (string) $key );

		if ( '' === $key ) {
			delete_option( self::KEY_OPTION );
			return;
		}

		update_option( self::KEY_OPTION, self::encrypt( $key ), false );
	}

	/**
	 * The last four characters of the key, to show which one is saved.
	 *
	 * @return string
	 */
	public static function key_hint() {
		$key = self::api_key();

		return strlen( $key ) > 8 ? substr( $key, -4 ) : '';
	}

	/**
	 * Load sodium (WordPress bundles a pure-PHP copy for hosts without the extension).
	 *
	 * @return bool
	 */
	private static function have_sodium() {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) && is_readable( ABSPATH . WPINC . '/sodium_compat/autoload.php' ) ) {
			require_once ABSPATH . WPINC . '/sodium_compat/autoload.php';
		}

		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'sodium_crypto_secretbox_open' );
	}

	/**
	 * Encryption key, derived from the site's own secret salts in wp-config.php,
	 * so a copy of the database alone can't reveal the API key.
	 *
	 * @return string 32 bytes.
	 */
	private static function secret() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|blog-to-audio-podcast', true );
	}

	/**
	 * Encrypt the key for storage.
	 *
	 * @param string $plain Plain key.
	 * @return string
	 */
	private static function encrypt( $plain ) {
		if ( ! self::have_sodium() ) {
			return 'plain:' . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Storage encoding only.
		}

		try {
			$nonce  = random_bytes( 24 );
			$cipher = sodium_crypto_secretbox( $plain, $nonce, self::secret() );
		} catch ( Exception $e ) {
			return 'plain:' . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Storage encoding only.
		}

		return self::KEY_PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Ciphertext encoding.
	}

	/**
	 * Decrypt a stored key.
	 *
	 * @param string $stored Stored value.
	 * @return string Empty if it can't be decrypted.
	 */
	private static function decrypt( $stored ) {
		if ( '' === $stored ) {
			return '';
		}

		if ( 0 === strpos( $stored, 'plain:' ) ) {
			return (string) base64_decode( substr( $stored, 6 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Storage encoding only.
		}

		if ( 0 !== strpos( $stored, self::KEY_PREFIX ) || ! self::have_sodium() ) {
			return '';
		}

		$raw = base64_decode( substr( $stored, strlen( self::KEY_PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Ciphertext encoding.

		if ( false === $raw || strlen( $raw ) <= 24 ) {
			return '';
		}

		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, 24 ), substr( $raw, 0, 24 ), self::secret() );
		} catch ( Exception $e ) {
			return '';
		}

		return false === $plain ? '' : (string) $plain;
	}

	/**
	 * An API client with the saved key.
	 *
	 * @return Blog_To_Audio_Podcast_API
	 */
	public static function api() {
		return new Blog_To_Audio_Podcast_API( self::api_key() );
	}

	// Models -----------------------------------------------------------------

	/**
	 * Text-to-speech models: what the account's API reports, or a built-in
	 * list when it can't be reached.
	 *
	 * @param bool $refresh Ignore the cache.
	 * @return array<string,array> model_id => name, description, max, cost, style, boost, languages.
	 */
	public static function models( $refresh = false ) {
		$models = $refresh ? false : get_transient( 'blog_to_audio_podcast_models' );

		if ( ! is_array( $models ) || ! $models ) {
			$models = '' !== self::api_key() ? self::api()->models() : new WP_Error( 'no_key', '' );

			if ( is_wp_error( $models ) || ! $models ) {
				return self::fallback_models();
			}

			set_transient( 'blog_to_audio_podcast_models', $models, DAY_IN_SECONDS );
		}

		return $models;
	}

	/**
	 * Built-in model list (October 2026).
	 *
	 * @return array<string,array>
	 */
	public static function fallback_models() {
		$list = array(
			'eleven_v4'              => array( 'Eleven v4', 10000, 1.0 ),
			'eleven_v4_turbo'        => array( 'Eleven v4 Turbo', 10000, 0.5 ),
			'eleven_multilingual_v2' => array( 'Eleven Multilingual v2', 10000, 1.0 ),
			'eleven_flash_v2_5'      => array( 'Eleven Flash v2.5', 40000, 0.5 ),
			'eleven_flash_v2'        => array( 'Eleven Flash v2', 30000, 0.5 ),
			'eleven_v3'              => array( 'Eleven v3', 5000, 1.0 ),
		);

		$models = array();

		foreach ( $list as $id => $model ) {
			$models[ $id ] = array(
				'name'        => $model[0],
				'description' => '',
				'max'         => $model[1],
				'cost'        => $model[2],
				'style'       => 'eleven_multilingual_v2' === $id,
				'boost'       => 'eleven_multilingual_v2' === $id,
				'fast'        => 0 === strpos( $id, 'eleven_flash' ),
				'languages'   => 0,
			);
		}

		return $models;
	}

	/**
	 * One model's details, with safe defaults for an unknown one.
	 *
	 * @param string $model_id Model ID.
	 * @return array
	 */
	public static function model( $model_id ) {
		$models = self::models();

		if ( isset( $models[ $model_id ] ) ) {
			return $models[ $model_id ];
		}

		return array(
			'name'        => $model_id,
			'description' => '',
			'max'         => 5000,
			'cost'        => 1.0,
			'style'       => false,
			'boost'       => false,
			'fast'        => false,
			'languages'   => 0,
		);
	}

	/**
	 * Whether a model supports request stitching (each chunk carrying on in
	 * the same tone as the last). ElevenLabs doesn't offer it for v3.
	 *
	 * @param string $model_id Model ID.
	 * @return bool
	 */
	public static function stitches( $model_id ) {
		$supported = 0 !== strpos( $model_id, 'eleven_v3' );

		/**
		 * Filters whether chunks are stitched together with previous request IDs.
		 *
		 * @param bool   $supported Whether the model supports it.
		 * @param string $model_id  Model ID.
		 */
		return (bool) apply_filters( 'blog_to_audio_podcast_stitching', $supported, $model_id );
	}

	/**
	 * Characters per request. Each request has to finish well inside a typical
	 * 30-60 second PHP time limit: the standard models take about a second
	 * per 90 characters, Flash about a second per 500.
	 *
	 * @param string $model_id Model ID.
	 * @return int
	 */
	public static function chunk_size( $model_id ) {
		$model = self::model( $model_id );
		$size  = empty( $model['fast'] ) ? 1500 : 5000;

		/**
		 * Filters how many characters are sent to ElevenLabs per request.
		 * Never more than the model accepts.
		 *
		 * @param int    $size     Characters.
		 * @param string $model_id Model ID.
		 */
		$size = (int) apply_filters( 'blog_to_audio_podcast_chunk_size', $size, $model_id );

		return max( 300, min( $size, (int) $model['max'] ) );
	}

	/**
	 * Credits per character for a model: what recent audio really cost when
	 * there's enough history, else the list rate.
	 *
	 * @param string $model_id Model ID.
	 * @return array{rate:float,measured:bool}
	 */
	public static function credit_rate( $model_id ) {
		$rates = get_option( self::RATES_OPTION, array() );

		if ( isset( $rates[ $model_id ]['chars'], $rates[ $model_id ]['credits'] ) && $rates[ $model_id ]['chars'] >= 500 ) {
			return array(
				'rate'     => max( 0, (float) $rates[ $model_id ]['credits'] / (float) $rates[ $model_id ]['chars'] ),
				'measured' => true,
			);
		}

		$model = self::model( $model_id );

		return array(
			'rate'     => (float) $model['cost'],
			'measured' => false,
		);
	}

	/**
	 * Remember what a request cost, for future estimates.
	 *
	 * @param string $model_id Model ID.
	 * @param int    $chars    Characters sent.
	 * @param int    $credits  Credits charged (from the response).
	 * @return void
	 */
	public static function record_rate( $model_id, $chars, $credits ) {
		if ( $chars < 1 || $credits < 0 ) {
			return;
		}

		$rates = get_option( self::RATES_OPTION, array() );
		$rates = is_array( $rates ) ? $rates : array();
		$entry = isset( $rates[ $model_id ] ) && is_array( $rates[ $model_id ] ) ? $rates[ $model_id ] : array(
			'chars'   => 0,
			'credits' => 0,
		);

		// Weight recent requests: once there's plenty of history, halve it.
		if ( $entry['chars'] > 200000 ) {
			$entry['chars']   = (int) ( $entry['chars'] / 2 );
			$entry['credits'] = (int) ( $entry['credits'] / 2 );
		}

		$entry['chars']     += (int) $chars;
		$entry['credits']   += (int) $credits;
		$rates[ $model_id ]  = $entry;

		update_option( self::RATES_OPTION, $rates, false );
	}

	// Voices -----------------------------------------------------------------

	/**
	 * Voices to choose from: the account's own and saved voices plus
	 * ElevenLabs' built-in ones, or the built-in list alone without access.
	 *
	 * @param bool $refresh Ignore the cache.
	 * @return array{voices:array,error:string}
	 */
	public static function voices( $refresh = false ) {
		$voices = $refresh ? false : get_transient( 'blog_to_audio_podcast_voices' );

		if ( is_array( $voices ) && $voices ) {
			return array(
				'voices' => $voices,
				'error'  => '',
			);
		}

		if ( '' === self::api_key() ) {
			return array(
				'voices' => self::fallback_voices(),
				'error'  => '',
			);
		}

		$voices = self::api()->voices();

		if ( is_wp_error( $voices ) || ! $voices ) {
			return array(
				'voices' => self::fallback_voices(),
				'error'  => is_wp_error( $voices ) ? $voices->get_error_message() : '',
			);
		}

		set_transient( 'blog_to_audio_podcast_voices', $voices, 12 * HOUR_IN_SECONDS );

		return array(
			'voices' => $voices,
			'error'  => '',
		);
	}

	/**
	 * ElevenLabs' built-in voices, available on every plan.
	 *
	 * @return array<string,array> voice_id => name, description, category, preview.
	 */
	public static function fallback_voices() {
		$list = array(
			'JBFqnCBsd6RMkjVDRZzb' => array( 'George', 'Warm, captivating storyteller (British, male)' ),
			'EXAVITQu4vr4xnSDxMaL' => array( 'Sarah', 'Mature, reassuring, confident (American, female)' ),
			'Xb7hH8MSUJpSbSDYk0k2' => array( 'Alice', 'Clear, engaging educator (British, female)' ),
			'onwK4e9ZLuTAKqWW03F9' => array( 'Daniel', 'Steady broadcaster (British, male)' ),
			'XrExE9yKIg1WjnnlVkGX' => array( 'Matilda', 'Knowledgeable, professional (American, female)' ),
			'nPczCjzI2devNBz1zQrb' => array( 'Brian', 'Deep, resonant and comforting (American, male)' ),
			'pFZP5JQG7iQjIQuC4Bku' => array( 'Lily', 'Velvety actress (British, female)' ),
			'cjVigY5qzO86Huf0OWal' => array( 'Eric', 'Smooth, trustworthy (American, male)' ),
			'hpp4J3VqNfWAUOO0d1Us' => array( 'Bella', 'Professional, bright, warm (American, female)' ),
			'CwhRBWXzGAHq8TQ4Fs17' => array( 'Roger', 'Laid-back, casual, resonant (American, male)' ),
			'SAz9YHcvj6GT2YYXdXww' => array( 'River', 'Relaxed, neutral, informative (American)' ),
			'iP95p4xoKVk53GoZ742B' => array( 'Chris', 'Charming, down-to-earth (American, male)' ),
			'cgSgspJ2msm6clMCkdW9' => array( 'Jessica', 'Playful, bright, warm (American, female)' ),
			'pqHfZKP75CvOlQylNhV4' => array( 'Bill', 'Wise, mature, balanced (American, male)' ),
			'TX3LPaxmHKxFdv7VOQHJ' => array( 'Liam', 'Energetic social media creator (American, male)' ),
			'bIHbv24MWmeRgasZH58o' => array( 'Will', 'Relaxed optimist (American, male)' ),
			'FGY2WhTYpPnrIDTdsKH5' => array( 'Laura', 'Enthusiast, quirky attitude (American, female)' ),
			'IKne3meq5aSn9XLyUdCD' => array( 'Charlie', 'Deep, confident, energetic (Australian, male)' ),
			'pNInz6obpgDQGcFmaJgB' => array( 'Adam', 'Dominant, firm (American, male)' ),
		);

		$voices = array();

		foreach ( $list as $id => $voice ) {
			$voices[ $id ] = array(
				'name'        => $voice[0],
				'description' => $voice[1],
				'category'    => 'premade',
				'preview'     => '',
			);
		}

		return $voices;
	}

	/**
	 * Voice settings to send with each request, trimmed to what the model uses.
	 *
	 * @param array  $settings Plugin settings (or a subset with the voice keys).
	 * @param string $model_id Model ID.
	 * @return array
	 */
	public static function voice_settings( array $settings, $model_id ) {
		$model     = self::model( $model_id );
		$stability = (float) $settings['stability'];

		// v3 only takes three stability steps: creative, natural and robust.
		if ( 0 === strpos( $model_id, 'eleven_v3' ) ) {
			$stability = $stability < 0.25 ? 0.0 : ( $stability < 0.75 ? 0.5 : 1.0 );
		}

		$voice = array(
			'stability'        => $stability,
			'similarity_boost' => (float) $settings['similarity'],
			'speed'            => (float) $settings['speed'],
		);

		if ( ! empty( $model['style'] ) ) {
			$voice['style'] = (float) $settings['style'];
		}

		if ( ! empty( $model['boost'] ) ) {
			$voice['use_speaker_boost'] = ! empty( $settings['speaker_boost'] );
		}

		return $voice;
	}

	// Privacy ----------------------------------------------------------------

	/**
	 * Suggested text for the site's privacy policy.
	 *
	 * @return void
	 */
	public function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>' . esc_html__( 'Audio versions of our articles are created with ElevenLabs, an AI voice service. When audio is made, the article\'s text is sent to ElevenLabs. No information about our visitors is sent: the audio files are stored on and played from this website.', 'blog-to-audio-podcast' ) . '</p>';

		wp_add_privacy_policy_content( __( 'Blog Post to Audio Podcast', 'blog-to-audio-podcast' ), wp_kses_post( $content ) );
	}
}
