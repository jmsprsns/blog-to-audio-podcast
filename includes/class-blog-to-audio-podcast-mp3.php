<?php
/**
 * Joins MP3 chunks in plain PHP, with no ffmpeg needed on the server.
 *
 * Each response from ElevenLabs is a complete MP3 file: an ID3 tag, then an
 * encoder "Info" frame holding that file's length, then the audio frames.
 * Gluing files together as they are would leave tags in the middle and make
 * players believe the whole episode is as long as the first chunk. So every
 * chunk is reduced to its bare audio frames, which can be joined end to end
 * because every chunk uses the same format.
 *
 * The frames are also counted, which gives the exact duration, and checked,
 * so nothing that isn't MP3 audio is ever saved as an .mp3 file.
 *
 * @package Blog_To_Audio_Podcast
 */

defined( 'ABSPATH' ) || exit;

/**
 * MP3 frame handling.
 */
final class Blog_To_Audio_Podcast_MP3 {

	/**
	 * Layer III bitrates in kbps, by version (MPEG-1, MPEG-2/2.5) and index.
	 */
	const BITRATES = array(
		1 => array( 0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320 ),
		2 => array( 0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160 ),
	);

	/**
	 * Sample rates by version bits (0 = 2.5, 2 = 2, 3 = 1) and index.
	 */
	const SAMPLE_RATES = array(
		0 => array( 11025, 12000, 8000 ),
		2 => array( 22050, 24000, 16000 ),
		3 => array( 44100, 48000, 32000 ),
	);

	/**
	 * Reduce one MP3 file to its audio frames.
	 *
	 * @param string $data Raw MP3 bytes.
	 * @return array{audio:string,frames:int,seconds:float}|WP_Error
	 */
	public static function clean( $data ) {
		$data = (string) $data;
		$len  = strlen( $data );
		$pos  = 0;

		// ID3v2 tags at the start (there can be more than one).
		while ( $len - $pos >= 10 && 'ID3' === substr( $data, $pos, 3 ) ) {
			$flags = ord( $data[ $pos + 5 ] );
			$size  = ( ( ord( $data[ $pos + 6 ] ) & 0x7f ) << 21 )
				| ( ( ord( $data[ $pos + 7 ] ) & 0x7f ) << 14 )
				| ( ( ord( $data[ $pos + 8 ] ) & 0x7f ) << 7 )
				| ( ord( $data[ $pos + 9 ] ) & 0x7f );
			$pos  += 10 + $size + ( ( $flags & 0x10 ) ? 10 : 0 );
		}

		// An ID3v1 tag at the end.
		$end = $len;

		if ( $end - $pos >= 128 && 'TAG' === substr( $data, $end - 128, 3 ) ) {
			$end -= 128;
		}

		$out     = array();
		$frames  = 0;
		$seconds = 0.0;
		$first   = true;

		while ( $pos + 4 <= $end ) {
			$frame = self::frame_info( substr( $data, $pos, 4 ) );

			if ( null === $frame ) {
				++$pos; // Not a frame header: look further on.
				continue;
			}

			if ( $pos + $frame['length'] > $end ) {
				break; // A cut-off frame at the end.
			}

			$bytes = substr( $data, $pos, $frame['length'] );
			$pos  += $frame['length'];

			// The first frame may be the encoder's Info/Xing/VBRI header,
			// which only describes this one file. Drop it.
			if ( $first && self::is_info_frame( $bytes, $frame ) ) {
				$first = false;
				continue;
			}

			$first    = false;
			$out[]    = $bytes;
			$seconds += $frame['samples'] / $frame['rate'];
			++$frames;
		}

		if ( ! $frames ) {
			return new WP_Error(
				'invalid_audio',
				__( 'ElevenLabs sent back a file that isn\'t valid MP3 audio.', 'blog-to-audio-podcast' ),
				array( 'kind' => 'retry' )
			);
		}

		return array(
			'audio'   => implode( '', $out ),
			'frames'  => $frames,
			'seconds' => $seconds,
		);
	}

	/**
	 * Parse a Layer III frame header.
	 *
	 * @param string $header Four bytes.
	 * @return array{length:int,samples:int,rate:int,version:int,mono:bool}|null
	 */
	private static function frame_info( $header ) {
		if ( 4 !== strlen( $header ) ) {
			return null;
		}

		$b1 = ord( $header[0] );
		$b2 = ord( $header[1] );
		$b3 = ord( $header[2] );
		$b4 = ord( $header[3] );

		// Sync bits, then Layer III only (layer bits 01).
		if ( 0xFF !== $b1 || 0xE0 !== ( $b2 & 0xE0 ) || 0x01 !== ( ( $b2 >> 1 ) & 0x03 ) ) {
			return null;
		}

		$version_bits = ( $b2 >> 3 ) & 0x03; // 0 = 2.5, 1 = reserved, 2 = 2, 3 = 1.
		$bitrate_idx  = ( $b3 >> 4 ) & 0x0F;
		$rate_idx     = ( $b3 >> 2 ) & 0x03;
		$padding      = ( $b3 >> 1 ) & 0x01;

		if ( 1 === $version_bits || 0 === $bitrate_idx || 15 === $bitrate_idx || 3 === $rate_idx ) {
			return null;
		}

		$mpeg1   = 3 === $version_bits;
		$bitrate = self::BITRATES[ $mpeg1 ? 1 : 2 ][ $bitrate_idx ] * 1000;
		$rate    = self::SAMPLE_RATES[ $version_bits ][ $rate_idx ];
		$samples = $mpeg1 ? 1152 : 576;
		$length  = (int) floor( ( $samples / 8 ) * $bitrate / $rate ) + $padding;

		if ( $length < 24 ) {
			return null;
		}

		return array(
			'length'  => $length,
			'samples' => $samples,
			'rate'    => $rate,
			'version' => $mpeg1 ? 1 : 2,
			'mono'    => 3 === ( ( $b4 >> 6 ) & 0x03 ),
		);
	}

	/**
	 * Whether a frame is an encoder info frame rather than audio.
	 *
	 * @param string $bytes Frame bytes.
	 * @param array  $frame Frame info.
	 * @return bool
	 */
	private static function is_info_frame( $bytes, array $frame ) {
		// The Xing/Info tag sits right after the side information.
		if ( 1 === $frame['version'] ) {
			$offset = $frame['mono'] ? 21 : 36;
		} else {
			$offset = $frame['mono'] ? 13 : 21;
		}

		$tag = substr( $bytes, $offset, 4 );

		return 'Xing' === $tag || 'Info' === $tag || 'VBRI' === substr( $bytes, 36, 4 );
	}

	/**
	 * Duration from size, for audio at the fixed 128 kbps.
	 *
	 * @param int $bytes File size.
	 * @return float Seconds.
	 */
	public static function seconds_from_size( $bytes ) {
		return max( 0, (int) $bytes ) * 8 / 128000;
	}
}
