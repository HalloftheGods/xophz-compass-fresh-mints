<?php
/**
 * Reads a practice website (home page plus a few contact pages) for published emails, phones,
 * schema.org data and social links. Used by skip-trace enrichment and the website check.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once __DIR__ . '/class-freshmints-api-email-domain.php';

class Freshmints_Api_Website_Scraper {

	/**
	 * Scrape website and contact pages for authentic email addresses, phone numbers, JSON-LD, and social links.
	 *
	 * @param string $url Target website URL
	 * @param string $lead_name Optional lead name for permutation generation
	 * @return array Scraped contact data
	 */
	public static function scrape_website_contact_info( $url, $lead_name = '' ) {
		$result = array(
			'websiteUrl'          => $url,
			'emails'              => array(),
			'phones'              => array(),
			'primaryEmail'        => '',
			'primaryPhone'        => '',
			'pagesScanned'        => array(),
			'socialProfilesFound' => array(),
			'emailPermutations'   => array(),
			'mxValid'             => false,
			'mxRecords'           => array(),
			'schemaOrgData'       => null,
		);

		if ( empty( $url ) ) {
			return $result;
		}

		$clean_url = trim( $url );
		if ( ! preg_match( '~^https?://~i', $clean_url ) ) {
			$clean_url = 'https://' . $clean_url;
		}

		if ( ! filter_var( $clean_url, FILTER_VALIDATE_URL ) ) {
			return $result;
		}

		$result['websiteUrl'] = $clean_url;
		$parsed_base = parse_url( $clean_url );
		$base_host   = $parsed_base['host'] ?? '';
		$base_scheme = $parsed_base['scheme'] ?? 'https';

		if ( empty( $base_host ) ) {
			return $result;
		}

		// Disallowed domain check for scraping
		$ignore_hosts = array( 'facebook.com', 'instagram.com', 'yelp.com', 'linkedin.com', 'twitter.com', 'x.com', 'google.com', 'healthgrades.com', 'zocdoc.com' );
		foreach ( $ignore_hosts as $ih ) {
			if ( strpos( strtolower( $base_host ), $ih ) !== false ) {
				return $result;
			}
		}

		// Decode Cloudflare email protection data-cfemail
		$decode_cf_email = function( $cf_hex ) {
			if ( empty( $cf_hex ) || strlen( $cf_hex ) < 4 ) {
				return '';
			}
			$k = hexdec( substr( $cf_hex, 0, 2 ) );
			$email = '';
			for ( $i = 2; $i < strlen( $cf_hex ); $i += 2 ) {
				$email .= chr( hexdec( substr( $cf_hex, $i, 2 ) ) ^ $k );
			}
			return $email;
		};

		$fetch_and_extract = function( $target_url ) use ( &$result, $decode_cf_email ) {
			$res = wp_remote_get( $target_url, array(
				'timeout'     => 7,
				'redirection' => 3,
				'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
				'headers'     => array(
					'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				),
				'sslverify'   => false,
			) );

			if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) !== 200 ) {
				return array( 'html' => '', 'emails' => array(), 'phones' => array(), 'contact_links' => array() );
			}

			$html = wp_remote_retrieve_body( $res );
			if ( ! in_array( $target_url, $result['pagesScanned'], true ) ) {
				$result['pagesScanned'][] = $target_url;
			}

			$found_emails  = array();
			$found_phones  = array();
			$contact_links = array();

			// 1. Extract mailto: links
			if ( preg_match_all( '/mailto:([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/i', $html, $m_matches ) ) {
				foreach ( $m_matches[1] as $em ) {
					$clean_em = strtolower( trim( $em ) );
					if ( ! in_array( $clean_em, $found_emails, true ) ) {
						$found_emails[] = $clean_em;
					}
				}
			}

			// 2. Extract Cloudflare protected emails (data-cfemail="...")
			if ( preg_match_all( '/data-cfemail=[\'"]([a-f0-9]+)[\'"]/i', $html, $cf_matches ) ) {
				foreach ( $cf_matches[1] as $cf_hex ) {
					$decoded_em = strtolower( trim( $decode_cf_email( $cf_hex ) ) );
					if ( ! empty( $decoded_em ) && filter_var( $decoded_em, FILTER_VALIDATE_EMAIL ) && ! in_array( $decoded_em, $found_emails, true ) ) {
						$found_emails[] = $decoded_em;
					}
				}
			}

			// 3. Extract Cloudflare links (/cdn-cgi/l/email-protection#...)
			if ( preg_match_all( '/\/cdn-cgi\/l\/email-protection#([a-f0-9]+)/i', $html, $cf_link_matches ) ) {
				foreach ( $cf_link_matches[1] as $cf_hex ) {
					$decoded_em = strtolower( trim( $decode_cf_email( $cf_hex ) ) );
					if ( ! empty( $decoded_em ) && filter_var( $decoded_em, FILTER_VALIDATE_EMAIL ) && ! in_array( $decoded_em, $found_emails, true ) ) {
						$found_emails[] = $decoded_em;
					}
				}
			}

			// 4. Extract standard regex emails
			if ( preg_match_all( '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/', $html, $reg_matches ) ) {
				foreach ( $reg_matches[0] as $em ) {
					$clean_em = strtolower( trim( $em ) );
					if ( ! in_array( $clean_em, $found_emails, true ) ) {
						$found_emails[] = $clean_em;
					}
				}
			}

			// 5. Extract text-obfuscated emails (e.g. user [at] domain [dot] com)
			if ( preg_match_all( '/([a-zA-Z0-9._%+-]+)\s*(?:\[at\]|\(at\))\s*([a-zA-Z0-9.-]+)\s*(?:\[dot\]|\(dot\)|\.)\s*([a-zA-Z]{2,})/i', $html, $obf_matches, PREG_SET_ORDER ) ) {
				foreach ( $obf_matches as $om ) {
					$reconstructed = strtolower( trim( "{$om[1]}@{$om[2]}.{$om[3]}" ) );
					if ( filter_var( $reconstructed, FILTER_VALIDATE_EMAIL ) && ! in_array( $reconstructed, $found_emails, true ) ) {
						$found_emails[] = $reconstructed;
					}
				}
			}

			// 6. Extract Schema.org JSON-LD structured data
			if ( preg_match_all( '/<script[^>]*type=[\'"]application\/ld\+json[\'"][^>]*>([\s\S]*?)<\/script>/i', $html, $jsonld_matches ) ) {
				foreach ( $jsonld_matches[1] as $json_str ) {
					$decoded_ld = json_decode( trim( $json_str ), true );
					if ( $decoded_ld ) {
						$extract_from_ld = function( $node ) use ( &$extract_from_ld, &$found_emails, &$found_phones, &$result ) {
							if ( ! is_array( $node ) ) return;
							if ( isset( $node['@graph'] ) && is_array( $node['@graph'] ) ) {
								foreach ( $node['@graph'] as $sub_node ) $extract_from_ld( $sub_node );
								return;
							}
							if ( ! empty( $node['email'] ) ) {
								$em = strtolower( trim( is_string( $node['email'] ) ? $node['email'] : '' ) );
								if ( filter_var( $em, FILTER_VALIDATE_EMAIL ) && ! in_array( $em, $found_emails, true ) ) {
									$found_emails[] = $em;
								}
							}
							if ( ! empty( $node['telephone'] ) ) {
								$ph = trim( preg_replace( '/[^\d\+\(\)\-\.\s]/', '', (string)$node['telephone'] ) );
								if ( strlen( preg_replace( '/\D/', '', $ph ) ) >= 10 && ! in_array( $ph, $found_phones, true ) ) {
									$found_phones[] = $ph;
								}
							}
							if ( ! empty( $node['contactPoint'] ) ) {
								$extract_from_ld( $node['contactPoint'] );
							}
							if ( ! empty( $node['founder'] ) ) {
								$extract_from_ld( $node['founder'] );
							}
							if ( ! empty( $node['employee'] ) ) {
								$extract_from_ld( $node['employee'] );
							}
							if ( empty( $result['schemaOrgData'] ) && ! empty( $node['@type'] ) ) {
								$result['schemaOrgData'] = array(
									'type'    => (string)$node['@type'],
									'name'    => (string)( $node['name'] ?? '' ),
									'email'   => (string)( $node['email'] ?? '' ),
									'phone'   => (string)( $node['telephone'] ?? '' ),
									'address' => is_array( $node['address'] ?? null ) ? ( $node['address']['streetAddress'] ?? '' ) : (string)( $node['address'] ?? '' ),
								);
							}
						};
						$extract_from_ld( $decoded_ld );
					}
				}
			}

			// 7. Extract Social Profile links
			if ( preg_match_all( '/href=[\'"](https?:\/\/(?:www\.)?(?:facebook\.com|instagram\.com|linkedin\.com|twitter\.com|x\.com|yelp\.com|youtube\.com)\/[^\'"#\s>]+)[\'"]/i', $html, $soc_matches ) ) {
				foreach ( $soc_matches[1] as $soc_url ) {
					$clean_soc = trim( $soc_url );
					if ( ! in_array( $clean_soc, $result['socialProfilesFound'], true ) ) {
						if ( ! preg_match( '/(sharer|share|intent|login|signup|policies)$/i', $clean_soc ) ) {
							$result['socialProfilesFound'][] = $clean_soc;
						}
					}
				}
			}

			// 8. Extract tel: links
			if ( preg_match_all( '/href=[\'"]tel:([^\'"\s>]+)[\'"]/i', $html, $tel_matches ) ) {
				foreach ( $tel_matches[1] as $ph ) {
					$clean_ph = trim( preg_replace( '/[^\d\+\(\)\-\.\s]/', '', $ph ) );
					if ( strlen( preg_replace( '/\D/', '', $clean_ph ) ) >= 10 && ! in_array( $clean_ph, $found_phones, true ) ) {
						$found_phones[] = $clean_ph;
					}
				}
			}

			// 9. Extract possible internal contact/about subpage links
			if ( preg_match_all( '/href=[\'"]([^\'"#\s>]+)[\'"]/i', $html, $href_matches ) ) {
				foreach ( $href_matches[1] as $href ) {
					if ( preg_match( '/(contact|about|team|staff|reach|connect|doctors|practitioners|location|locations|hours|office|touch)/i', $href ) ) {
						if ( ! preg_match( '/\.(jpg|jpeg|png|gif|svg|css|js|pdf|webp|ico|xml|json)$/i', $href ) ) {
							$contact_links[] = $href;
						}
					}
				}
			}

			return array(
				'html'          => $html,
				'emails'        => $found_emails,
				'phones'        => $found_phones,
				'contact_links' => array_unique( $contact_links ),
			);
		};

		// Helper to filter out system / garbage emails
		$filter_clean_emails = function( array $raw_emails ) {
			$invalid_needles = array(
				'example.com', 'domain.com', 'sentry.io', 'wixpress.com', 'wordpress.org', 'wp.com',
				'cloudflare.com', 'google.com', 'schema.org', 'w3.org', 'github.com', 'fontawesome',
				'bootstrap', 'jquery', 'npm', 'user@', 'email@', 'test@', 'yourname@', 'name@',
				'.png', '.jpg', '.jpeg', '.gif', '.svg', '.webp', '.css', '.js'
			);
			$clean = array();
			foreach ( $raw_emails as $em ) {
				$em_lower = strtolower( trim( $em ) );
				if ( ! filter_var( $em_lower, FILTER_VALIDATE_EMAIL ) ) {
					continue;
				}
				$skip = false;
				foreach ( $invalid_needles as $inv ) {
					if ( strpos( $em_lower, $inv ) !== false ) {
						$skip = true;
						break;
					}
				}
				if ( ! $skip && ! in_array( $em_lower, $clean, true ) ) {
					$clean[] = $em_lower;
				}
			}
			return $clean;
		};

		// 1. Fetch Homepage
		$homepage_data = $fetch_and_extract( $clean_url );
		$all_emails    = $filter_clean_emails( $homepage_data['emails'] );
		$all_phones    = $homepage_data['phones'];

		// 2. Assemble candidate contact subpages
		$subpage_targets = array();

		if ( ! empty( $homepage_data['contact_links'] ) ) {
			foreach ( $homepage_data['contact_links'] as $link ) {
				$sub_url = $link;
				if ( strpos( $link, 'http' ) !== 0 ) {
					$sub_url = rtrim( "{$base_scheme}://{$base_host}", '/' ) . '/' . ltrim( $link, '/' );
				}
				$sub_host = parse_url( $sub_url, PHP_URL_HOST );
				if ( $sub_host && strpos( strtolower( $sub_host ), strtolower( $base_host ) ) !== false ) {
					if ( ! in_array( $sub_url, $subpage_targets, true ) && $sub_url !== $clean_url ) {
						$subpage_targets[] = $sub_url;
					}
				}
			}
		}

		$candidate_paths = array(
			'/contact',
			'/contact-us',
			'/contactus',
			'/about',
			'/about-us',
			'/our-team',
			'/team',
			'/locations',
			'/location',
		);

		foreach ( $candidate_paths as $c_path ) {
			$candidate_url = rtrim( "{$base_scheme}://{$base_host}", '/' ) . $c_path;
			if ( ! in_array( $candidate_url, $subpage_targets, true ) && $candidate_url !== $clean_url ) {
				$subpage_targets[] = $candidate_url;
			}
		}

		// 3. Crawl top candidate subpages (up to 4 pages max) if more emails or phones needed
		if ( count( $all_emails ) < 3 && ! empty( $subpage_targets ) ) {
			foreach ( array_slice( $subpage_targets, 0, 4 ) as $target_sub_url ) {
				$sub_data   = $fetch_and_extract( $target_sub_url );
				$sub_emails = $filter_clean_emails( $sub_data['emails'] );
				foreach ( $sub_emails as $se ) {
					if ( ! in_array( $se, $all_emails, true ) ) {
						$all_emails[] = $se;
					}
				}
				foreach ( $sub_data['phones'] as $sp ) {
					if ( ! in_array( $sp, $all_phones, true ) ) {
						$all_phones[] = $sp;
					}
				}
				if ( count( $all_emails ) >= 3 ) {
					break;
				}
			}
		}

		// 4. DNS MX Check & Domain Email Permutations
		$mx_info = Freshmints_Api_Email_Domain::check_domain_mx_records( $base_host );
		$result['mxValid']   = $mx_info['valid'];
		$result['mxRecords'] = $mx_info['records'];
		$result['emailPermutations'] = Freshmints_Api_Email_Domain::generate_domain_email_permutations( $lead_name, $base_host );

		$result['emails']       = $all_emails;
		$result['phones']       = $all_phones;
		$result['primaryEmail'] = ! empty( $all_emails ) ? $all_emails[0] : '';
		$result['primaryPhone'] = ! empty( $all_phones ) ? $all_phones[0] : '';

		return $result;
	}
}
