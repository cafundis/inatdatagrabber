<?php
// This script is dual licensed under the MIT License and the CC0 License.
error_reporting( E_ALL );

include 'conf.php';

/* Connect to the database. */
include 'db.conf.php';

$useragent = 'iNatDataGrabber/1.0';
$inatapi = 'https://api.inaturalist.org/v1/';
$token = null;
$jwt = null; // JSON web token
$errors = [];
$observationsinserted = 0;
$maxrecordsperrequest = 100;
$keepprocessing = true;
$totalresults = 0;
$batch = 1;
$offset = 0;

function make_curl_request( $url = null ) {
	global $useragent, $token, $jwt, $errors;
	$curl = curl_init();
	if ( $curl && $url ) {
		$curlheaders = array(
			'Accept: application/json'
		);
		if ( $jwt ) {
			$curlheaders[] = "Authorization: " . $jwt;
		} else if ( $token ) {
			$curlheaders[] = "Authorization: Bearer " . $token;
		}
		curl_setopt( $curl, CURLOPT_HTTPHEADER, $curlheaders );
		curl_setopt( $curl, CURLOPT_URL, $url );
		curl_setopt( $curl, CURLOPT_USERAGENT, $useragent );
		curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
		$out = curl_exec( $curl );
		if ( $out ) {
			$object = json_decode( $out );
			if ( $object ) {
				return json_decode( json_encode( $object ), true );
			} else {
				$errors[] = 'API request failed. ' . curl_error( $curl );
				return null;
			}
		} else {
			$errors[] = 'API request failed. ' . curl_error( $curl );
			return null;
		}
	} else {
		$errors[] = 'Curl initialization failed. ' . curl_error( $curl );
		return null;
	}
}

function iNat_auth_request( $app_id, $app_secret, $username, $password, $url = 'https://www.inaturalist.org/oauth/token' ) {
	global $useragent, $errors;
	$curl = curl_init();
	$payload = array( 'client_id' => $app_id, 'client_secret' => $app_secret, 'grant_type' => "password", 'username' => $username, 'password' => $password );
	if ( $curl ) {
		curl_setopt( $curl, CURLOPT_URL, $url );
		curl_setopt( $curl, CURLOPT_USERAGENT, $useragent );
		curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $curl, CURLOPT_POST, true );
		curl_setopt( $curl, CURLOPT_POSTFIELDS, $payload );
		$out = curl_exec( $curl );
		if ( $out ) {
			$object = json_decode( $out );
			if ( $object ) {
				return json_decode( json_encode( $object ), true );
			} else {
				$errors[] = 'API request failed. ' . curl_error( $curl );
				return null;
			}
		} else {
			$errors[] = 'API request failed. ' . curl_error( $curl );
			return null;
		}
	} else {
		$errors[] = 'Curl initialization failed. ' . curl_error( $curl );
		return null;
	}
}

function get_jwt() {
	global $errors;
	$url = "https://www.inaturalist.org/users/api_token";
	$inatdata = make_curl_request( $url );
	if ( $inatdata && $inatdata['api_token'] ) {
		return $inatdata['api_token'];
	} else {
		$errors[] = 'Failed to retrieve JSON web token.';
		return null;
	}
}

function get_places( $placeids, $observationid ) {
	global $inatapi, $errors;
	$placelist = implode( ',', $placeids );
	$url = $inatapi . 'places/' . $placelist . '?admin_level=0,10,20';
	$inatdata = make_curl_request( $url );
	if ( $inatdata && $inatdata['results'] ) {
		$places = [
			'county' => null,
			'state' => null,
			'country' => null
		];
		foreach ( $inatdata['results'] as $place ) {
			switch ( $place['admin_level'] ) {
				case 0:
					$places['country'] = $place['name'];
					break;
				case 10:
					$places['state'] = $place['name'];
					break;
				case 20:
					// iNat doesn't include 'County', 'Parish', etc. in the place name for
					// US locations, but does include it in the place display name.
					if ( strpos( $place['display_name'], ', US' ) === false ) {
						$places['county'] = $place['name'];
					} else {
						$placenameparts = explode( ',', $place['display_name'], 2 );
						if ( $placenameparts[0] ) {
							$places['county'] = $placenameparts[0];
						} else {
							$places['county'] = $place['name'];
						}
					}
					break;
			}
		}
		return $places;
	} else {
		$errors[] = 'Location not found for observation ' . $observationid . '.';
		return null;
	}
}

function get_taxonomy( $ancestorids, $observationid ) {
	global $inatapi, $errors;
	$ancestorlist = implode( ',', $ancestorids );
	$url = $inatapi . 'taxa/' . $ancestorlist;
	$inatdata = make_curl_request( $url );
	if ( $inatdata && $inatdata['results'] ) {
		$taxonomy = [
			'phylum' => null,
			'class' => null,
			'order' => null,
			'family' => null,
			'tribe' => null,
			'genus' => null,
			'species' => null
		];
		foreach ( $inatdata['results'] as $taxon ) {
			switch ( $taxon['rank'] ) {
				case 'phylum':
					$taxonomy['phylum'] = $taxon['name'];
					break;
				case 'class':
					$taxonomy['class'] = $taxon['name'];
					break;
				case 'order':
					$taxonomy['order'] = $taxon['name'];
					break;
				case 'family':
					$taxonomy['family'] = $taxon['name'];
					break;
				case 'tribe':
					$taxonomy['tribe'] = $taxon['name'];
					break;
				case 'genus':
					$taxonomy['genus'] = $taxon['name'];
					break;
				case 'species':
					$taxonomy['species'] = $taxon['name'];
					break;
			}
		}
		return $taxonomy;
	} else {
		$errors[] = 'Taxonomy not found for observation ' . $observationid . '.';
		return null;
	}
}

// Get observation field value
function get_ofv( $ofvs, $fieldname ) {
	foreach ( $ofvs as $observation_field ) {
		if ( $observation_field['name'] === $fieldname ) {
			return $observation_field['value'];
		}
	}
	return null;
}

function process_batch() {
	global $link, $inatapi, $errors, $observationsinserted, $maxrecordsperrequest, $keepprocessing, $totalresults, $batch, $offset;
	$url = $inatapi . 'observations?per_page=' . $maxrecordsperrequest . '&verifiable=any&place_id=any&field:DNA%20Barcode%20ITS&order_by=id&order=asc&id_above=' . $offset;
	$inatdata = make_curl_request( $url );
	if ( $inatdata && isset($inatdata['results']) && isset($inatdata['results'][0]) ) {
		if ( !$totalresults ) $totalresults = $inatdata['total_results'];
		print( "Processing batch ".$batch." of ".ceil($totalresults/$maxrecordsperrequest)."...\n" );
		$fields = [ 'id', 'date', 'user_name', 'user_login', 'description', 'latitude', 'longitude', 
			'private_latitude', 'private_longitude', 'coordinates_obscured', 'county', 'state', 'country',
			'scientific_name', 'phylum', 'class', 'order', 'family', 'tribe', 'genus', 'species',
			'accession_number', 'fundis_tag_number', 'microscopy_requested', 'mycomap_blast_results',
			'mycoportal_link', 'provisional_species_name', 'voucher_number', 'voucher_numbers', 
			'dna_barcode_its', 'dna_barcode_its_2', 'dna_barcode_lsu' ];
		$quotedfields = [];
		foreach ( $fields as $field ) {
			$quotedfields[] = '`' . $field . '`';
		}
		$quotedfieldsstring = implode( ', ', $quotedfields );
		if ( count( $inatdata['results'] ) < $maxrecordsperrequest ) $keepprocessing = false;
		foreach ( $inatdata['results'] as $result ) {
			$data = [];
			$data['id'] = $result['id'];
			$offset = $result['id'];
			$data['date'] = $result['observed_on_details']['date'];
			$data['user_name'] = $result['user']['name'];
			$data['user_login'] = $result['user']['login'];
			$data['description'] = $result['description'];
			$data['latitude'] = null;
			$data['longitude'] = null;
			if ( $result['location'] ) {
				$location = explode( ',', $result['location'] );
				$data['latitude'] = $location[0];
				$data['longitude'] = $location[1];
			}
			$data['private_latitude'] = null;
			$data['private_longitude'] = null;
			if ( isset( $result['private_location'] ) ) {
				$privatelocation = explode( ',', $result['private_location'] );
				$data['private_latitude'] = $privatelocation[0];
				$data['private_longitude'] = $privatelocation[1];
			}
			$data['coordinates_obscured'] = $result['geoprivacy'] ? 'true' : 'false';
			$data['county'] = null;
			$data['state'] = null;
			$data['country'] = null;
			if ( $result['place_ids'] ) {
				$places = get_places( $result['place_ids'], $result['id'] );
				if ( $places ) {
					$data = array_merge( $data, $places );
				}
			}
			$data['scientific_name'] = $result['taxon']['name'];
			$data['phylum'] = null;
			$data['class'] = null;
			$data['order'] = null;
			$data['family'] = null;
			$data['tribe'] = null;
			$data['genus'] = null;
			$data['species'] = null;
			if ( $result['taxon']['ancestor_ids'] ) {
				$taxonomy = get_taxonomy( $result['taxon']['ancestor_ids'], $result['id'] );
				if ( $taxonomy ) {
					$data = array_merge( $data, $taxonomy );
				}
			}
			if ( isset( $result['ofvs'] ) ) {
				$ofvs = $result['ofvs'];
				$data['accession_number'] = get_ofv( $ofvs, 'Accession Number' );
				$data['fundis_tag_number'] = get_ofv( $ofvs, 'FUNDIS Tag Number' );
				$data['microscopy_requested'] = get_ofv( $ofvs, 'Microscopy Requested' );
				$data['mycomap_blast_results'] = get_ofv( $ofvs, 'MycoMap BLAST Results' );
				$data['mycoportal_link'] = get_ofv( $ofvs, 'MyCoPortal Link' );
				$data['provisional_species_name'] = get_ofv( $ofvs, 'Provisional Species Name' );
				$data['voucher_number'] = get_ofv( $ofvs, 'Voucher Number' );
				$data['voucher_numbers'] = get_ofv( $ofvs, 'Voucher Number(s)' );
				$data['dna_barcode_its'] = get_ofv( $ofvs, 'DNA Barcode ITS' );
				$data['dna_barcode_its_2'] = get_ofv( $ofvs, 'DNA Barcode ITS #2' );
				$data['dna_barcode_lsu'] = get_ofv( $ofvs, 'DNA Barcode LSU' );
			} else {
				$data['accession_number'] = null;
				$data['fundis_tag_number'] = null;
				$data['microscopy_requested'] = null;
				$data['mycomap_blast_results'] = null;
				$data['mycoportal_link'] = null;
				$data['provisional_species_name'] = null;
				$data['voucher_number'] = null;
				$data['voucher_numbers'] = null;
				$data['dna_barcode_its'] = null;
				$data['dna_barcode_its_2'] = null;
				$data['dna_barcode_lsu'] = null;
			}
			$escapedvalues = [];
			foreach ( $fields as $field ) {
				$escapedvalues[] = "'" . mysqli_real_escape_string( $link, $data[$field] ) . "'";
			}
			$escapedvaluesstring = implode( ', ', $escapedvalues );
			$updatevalues = [];
			foreach ( $fields as $field ) {
				$updatevalues[] = "`" . $field . "` = '" . mysqli_real_escape_string( $link, $data[$field] ) . "'";
			}
			$updatevaluesstring = implode( ', ', $updatevalues );
			$query = "INSERT INTO `inatimported` (".$quotedfieldsstring.") VALUES (".$escapedvaluesstring.") " .
			"ON DUPLICATE KEY UPDATE ".$updatevaluesstring.";";
			$result = mysqli_query( $link, $query );
			if ( $result ) {
				$observationsinserted++;
			} else {
				$errors[] = "Inserting record ".$data['id']." failed.";
				$errors[] = mysqli_error( $link );
			}
		}
	} else {
		$errors[] = 'No observations found via iNaturalist API.';
		$keepprocessing = false;
	}
	unset( $inatdata );
	sleep(1);
	return true;
}

print("------------------ SCRIPT STARTED ------------------\n");
$start_time = microtime( true );

// Get iNat auth token
$response = iNat_auth_request( $app_id, $app_secret, $username, $password );
if ( $response && isset( $response['access_token'] ) ) {
	$token = $response['access_token'];
	// Get JSON web token
	$jwt = get_jwt();

	while ( $keepprocessing && $batch < 3 ) {
		process_batch();
		$batch++;
	}
}

print( $observationsinserted . " observations inserted or updated.\n" );

if ( $errors ) {
	print( "Errors:\n" );
	foreach ( $errors as $error ) {
		print( "   " . $error . "\n" );
	}
}

$end_time = microtime( true );
$execution_time = ( $end_time - $start_time );
print( "Execution time: " . $execution_time . " seconds.\n" );
print("------------------ SCRIPT TERMINATED ------------------\n");
