<?php
// This script is dual licensed under the MIT License and the CC0 License.
error_reporting( E_ALL );
ini_set( 'display_errors', 1 );
ini_set( 'max_execution_time', 900 );

include 'conf.php';

$useragent = 'iNatDataGrabber/1.0';
$inatapi = 'https://api.inaturalist.org/v1/';
$token = null;
$jwt = null; // JSON web token
$errors = [];
$observationlist = []; // Array of submitted observation keys
$observationlistclean = []; // Array of iNaturalist observation IDs
$observationdata = [];
$datarequested = [];
$maxrecords = 10000; // Per https://www.inaturalist.org/pages/api+recommended+practices
$maxrecordsperrequest = 200; // Per v1 API limit

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

function get_observation_id( $observationkey, $keytype ) {
	global $inatapi, $errors;
	$keys = [
		'accession' => 'Accession%20Number',
		'tag' => 'FUNDIS%20Tag%20Number',
		'voucher' => 'Voucher%20Number',
		'vouchers' => 'Voucher%20Number(s)'
	];
	$url = $inatapi . 'observations?field:' . $keys[$keytype] . '=' . $observationkey;
	$inatdata = make_curl_request( $url );
	if ( $inatdata && $inatdata['results'] && $inatdata['results'][0] ) {
		return $inatdata['results'][0]['id'];
	} else {
		return null;
	}
}

function get_observation_data( $observationlist ) {
	global $inatapi, $jwt, $errors;
	if ( $observationlist ) {
		$allobservationdata = [];
		$observationliststring = implode( ",", $observationlist );
		$url = $inatapi . 'observations?per_page=200&id=' . $observationliststring;
		$inatdata = make_curl_request( $url );
		if ( $inatdata && isset($inatdata['results']) && isset($inatdata['results'][0]) ) {
			foreach ( $inatdata['results'] as $result ) {
				$data = [];
				$data['id'] = $result['id'];
				$data['url'] = 'https://www.inaturalist.org/observations/' . $result['id'];
				$data['date'] = $result['observed_on_details']['date'];
				$data['user_name'] = $result['user']['name'];
				$data['user_login'] = $result['user']['login'];
				$data['description'] = $result['description'];
				$location = explode( ',', $result['location'] );
				$data['latitude'] = $location[0];
				$data['longitude'] = $location[1];
				if ( isset( $result['private_location'] ) ) {
					$privatelocation = explode( ',', $result['private_location'] );
					$data['private_latitude'] = $privatelocation[0];
					$data['private_longitude'] = $privatelocation[1];
				} else {
					$data['private_latitude'] = null;
					$data['private_longitude'] = null;
				}
				$data['coordinates_obscured'] = $result['geoprivacy'] ? 'true' : 'false';
				$places = get_places( $result['place_ids'], $result['id'] );
				if ( $places ) {
					$data = array_merge( $data, $places );
				}
				$data['scientific_name'] = $result['taxon']['name'];
				$taxonomy = get_taxonomy( $result['taxon']['ancestor_ids'], $result['id'] );
				if ( $taxonomy ) {
					$data = array_merge( $data, $taxonomy );
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
				$allobservationdata[] = $data;
			}
			return $allobservationdata;
		} else {
			$errors[] = 'No observations found via iNaturalist API.';
			if ( $inatdata ) var_dump( $inatdata );
			return [];
		}
	}
}

function get_observation_list( $filename='', $field ) {
	if( !file_exists($filename) || !is_readable($filename) ) return false;
	$header = null;
	$indexeddata = [];
	$observationlist = [];
	if ( ( $handle = fopen( $filename, 'r' ) ) !== false ) {
		while ( ( $row = fgetcsv( $handle, 0, ',' ) ) !== false ) {
			if( !$header ) {
				$header = $row;
			} else {
				$indexeddata = array_combine( $header, $row );
				$observationlist[] = $indexeddata[$field];
			}
		}
		fclose($handle);
	}
	return $observationlist;
}

// See if form was submitted.
if ( $_POST ) {
	// Replace 'latlon' with 'latitude' and 'longitude'
	if ( isset( $_POST['options']['latlon'] ) ) {
		$pos = array_search( 'latlon', array_keys( $_POST['options'] ) );
		unset( $_POST['options']['latlon'] );
		$temparrray = [
			'latitude' => '1',
			'longitude' => '1'
		];
		$_POST['options'] = array_merge( array_slice( $_POST['options'], 0, $pos ), $temparrray, array_slice( $_POST['options'], $pos ) );
	}
	// Replace 'privatelatlon' with 'private_latitude' and 'private_longitude'
	if ( isset( $_POST['options']['private_latlon'] ) ) {
		$pos = array_search( 'private_latlon', array_keys( $_POST['options'] ) );
		unset( $_POST['options']['private_latlon'] );
		$temparrray = [
			'private_latitude' => '1',
			'private_longitude' => '1'
		];
		$_POST['options'] = array_merge( array_slice( $_POST['options'], 0, $pos ), $temparrray, array_slice( $_POST['options'], $pos ) );
	}
	// Create array of what data was requested
	$datarequested = array_keys( $_POST['options'] );
	$keytype = $_POST['key'];
	if ( $_POST['field'] ) {
		$field = $_POST['field'];
	} else {
		$field = 'id';
	}
	if ( $_POST['username'] ) $username = $_POST['username'];
	if ( $_POST['password'] ) $password = $_POST['password'];
	// If an observation list was posted, look up the data
	if ( $_FILES && isset( $_FILES['observationsreport'] )
		&& isset( $_FILES['observationsreport']['tmp_name'] )
		&& $_FILES['observationsreport']['tmp_name'] !== '' )
	{
		$tmpName = $_FILES['observationsreport']['tmp_name'];
		$observationlist = get_observation_list( $tmpName, $field );
	}
	if ( isset( $_POST['observations'] ) && $_POST['observations'] ) {
		$observationsfromtextarea = explode( "\n", $_POST['observations'] );
		$observationlist = array_merge( $observationlist, $observationsfromtextarea );
	}
	if ( $observationlist ) {
		// Limit to $maxrecords observations.
		$observationlist = array_slice( $observationlist, 0, $maxrecords );
		// Trim whitespace from all observation keys.
		$observationlist = array_map( 'trim', $observationlist );
		// Remove any empty keys.
		$observationlist = array_filter($observationlist);
		// Get iNat auth token
		$response = iNat_auth_request( $app_id, $app_secret, $username, $password );
		if ( $response && isset( $response['access_token'] ) ) {
			$token = $response['access_token'];
			// Get JSON web token
			$jwt = get_jwt();
			// Convert the observation list into a list of iNaturalist observation IDs
			foreach ( $observationlist as $observationkey ) {
				switch ( $keytype ) {
					case 'id':
						if ( preg_match( '/\d+/', $observationkey, $matches ) ) {
							$observationlistclean[] = $matches[0];
						} else {
							$errors[] = 'Observation ID not valid: ' . $observationkey;
						}
						break;
					case 'accession':
						$observationid = get_observation_id( $observationkey, 'accession' );
						if ( $observationid ) {
							$observationlistclean[] = $observationid;
						} else {
							$errors[] = 'Observation not found for accession number ' . $observationkey;
						}
						break;
					case 'tag':
						$observationid = get_observation_id( $observationkey, 'tag' );
						if ( $observationid ) {
							$observationlistclean[] = $observationid;
						} else {
							$errors[] = 'Observation not found for FUNDIS tag number ' . $observationkey;
						}
						break;
					case 'voucher':
						$observationid = get_observation_id( $observationkey, 'voucher' );
						if ( $observationid ) {
							$observationlistclean[] = $observationid;
						} else {
							$observationid = get_observation_id( $observationkey, 'vouchers' );
							if ( $observationid ) {
								$observationlistclean[] = $observationid;
							} else {
								$errors[] = 'Observation not found for voucher number ' . $observationkey;
							}
						}
						break;
				}
			}
			if ( $observationlistclean ) {
				// Split into chunks for batched requests
				$chunkedobservationlist = array_chunk( $observationlistclean, $maxrecordsperrequest );
				foreach ( $chunkedobservationlist as $observationlistchunk ) {
					$observationdata[] = get_observation_data( $observationlistchunk );
				}
				// Merge the chunks back together
				$observationdata = array_merge( ...$observationdata );
			}
		} else {
			$errors[] = 'iNaturalist authorization request failed.';
		}
	}
}
?>
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
	<meta http-equiv="Content-Language" content="en-us">
	<meta charset="UTF-8">
	<title>iNatDataGrabber</title>

<style type="text/css">
body {
	font-family: "Trebuchet MS", Verdana, sans-serif;
	color:#777777;
	background: #FFFFFF;
	}
#content {
	margin: 2em;
	}
#errors {
	margin: 1em;
	color: #FF6666;
	font-weight: bold;
	}
</style>
</head>
<body>
<div id="content">
<?php
$observationsfound = [];
if ( $observationdata ) {
	$fh = fopen( './data/inatdata.csv', 'w' );
	if ( $fh ) {
		// Write csv headers
		fputcsv( $fh, $datarequested );
		foreach ( $observationdata as $observation ) {
			if ( $observation && is_array( $observation ) ) {
				// Filter observations for only the requested data.
				$observationfiltered = array_intersect_key( $observation, array_fill_keys( $datarequested, null ) );
				if ( $observationfiltered && is_array( $observationfiltered ) ) {
					fputcsv( $fh, $observationfiltered );
					$observationsfound[] = $observation['id'];
				}
			}
		}
	} else {
		$errors[] = 'File is not writable. Please check permissions.';
	}
}
if ( $errors ) {
	print( '<p id="errors">' );
	print( 'Errors:<br/>' );
	foreach ( $errors as $error ) {
		print( $error . '<br/>' );
	}
	print( '</p>' );
}
print( "<p>Observations processed: " . count($observationlist) . "<br>" );
print( "Observations written to output file: " . count($observationsfound) . "</p>" );
if ( count($observationlistclean) !== count($observationsfound) ) {
	$observationsnotfound = array_diff( $observationlistclean, $observationsfound );
	print( '<p>These observation IDs returned no data: ' . implode( ', ', $observationsnotfound ) . '</p>' );
}
if ( count($observationsfound) > 0 ) {
	print( '<p>Output file: <a href="data/inatdata.csv">inatdata.csv</a></p>' );
}
print( '<p>&nbsp;</p><p><a href="index.html">New Request</a></p>' );
?>
</div>
</body>
</html>
