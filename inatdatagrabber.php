<?php
// This script is dual licensed under the MIT License and the CC0 License.
error_reporting( E_ALL );
ini_set( 'display_errors', 1 );
ini_set( 'max_execution_time', 900 );

include 'conf.php';

$useragent = 'iNatDataGrabber/1.0';
$inatapi = 'https://api.inaturalist.org/v1/';
$token = null;
$errors = [];
$observationlist = [];
$observationdata = [];
$datarequested = [];
//$sleeptime = 1;
$maxrecords = 10000;

function make_curl_request( $url = null, $token = null, $postData = null ) {
	global $useragent, $errors;
	$curl = curl_init();
	if ( $curl && $url ) {
		if ( $postData ) {
			$curlheaders = array(
				'Cache-Control: no-cache',
				'Content-Type: application/json',
				'Content-Length: ' . strlen( $postData ),
				'Accept: application/json'
			);
			if ( $token ) {
				$curlheaders[] = "Authorization: Bearer " . $token;
			}
			curl_setopt( $curl, CURLOPT_HTTPHEADER, $curlheaders );
			curl_setopt( $curl, CURLOPT_CUSTOMREQUEST, 'POST' );
			curl_setopt( $curl, CURLOPT_POSTFIELDS, $postData );
		}
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

function get_places( $placeids, $observationid ) {
	global $inatapi, $token, $errors;
	$placelist = implode( ',', $placeids );
	$url = $inatapi . 'places/' . $placelist . '?admin_level=0,10,20';
	$inatdata = make_curl_request( $url, $token );
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
	global $inatapi, $token, $errors;
	$ancestorlist = implode( ',', $ancestorids );
	$url = $inatapi . 'taxa/' . $ancestorlist;
	$inatdata = make_curl_request( $url, $token );
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
	global $inatapi, $token, $errors;
	$keys = [
		'accession' => 'Accession%20Number',
		'tag' => 'FUNDIS%20Tag%20Number',
		'voucher' => 'Voucher%20Number',
		'vouchers' => 'Voucher%20Number(s)'
	];
	$url = $inatapi . 'observations?field:' . $keys[$keytype] . '=' . $observationkey;
	$inatdata = make_curl_request( $url, $token );
	if ( $inatdata && $inatdata['results'] && $inatdata['results'][0] ) {
		return $inatdata['results'][0]['id'];
	} else {
		return null;
	}
}

function get_observation_data( $observationid ) {
	global $inatapi, $token, $errors;
	if ( $observationid && is_numeric( $observationid ) ) {
		$data['id'] = $observationid;
		$data['url'] = 'https://www.inaturalist.org/observations/' . $observationid;
		$url = $inatapi . 'observations/' . $observationid;
		$inatdata = make_curl_request( $url, $token );
		if ( $inatdata && $inatdata['results'] && $inatdata['results'][0] ) {
			$results = $inatdata['results'][0];
			// Array numbering starts at 0 so the first element is empty.
			$data['date'] = $results['observed_on_details']['year'] . '-' . sprintf('%02d',$results['observed_on_details']['month']) . '-' . sprintf('%02d',$results['observed_on_details']['day']);
			$data['user_name'] = $results['user']['name'];
			$data['user_login'] = $results['user']['login'];
			$data['description'] = $results['description'];
			$location = explode( ',', $results['location'] );
			$data['latitude'] = $location[0];
			$data['longitude'] = $location[1];
			$data['coordinates_obscured'] = $results['geoprivacy'] ? 'true' : 'false';
			$places = get_places( $results['place_ids'], $observationid );
			if ( $places ) {
				$data = array_merge( $data, $places );
			}
			$data['scientific_name'] = $results['taxon']['name'];
			$taxonomy = get_taxonomy( $results['taxon']['ancestor_ids'], $observationid );
			if ( $taxonomy ) {
				$data = array_merge( $data, $taxonomy );
			}
			if ( isset( $results['ofvs'] ) ) {
				$ofvs = $results['ofvs'];
				$data['accession_number'] = get_ofv( $ofvs, 'Accession Number' );
				$data['fundis_tag_number'] = get_ofv( $ofvs, 'FUNDIS Tag Number' );
				$data['microscopy_requested'] = get_ofv( $ofvs, 'Microscopy Requested' );
				$data['mycomap_blast_results'] = get_ofv( $ofvs, 'MycoMap BLAST Results' );
				$data['mycoportal_link'] = get_ofv( $ofvs, 'MyCoPortal Link' );
				$data['provisional_species_name'] = get_ofv( $ofvs, 'Provisional Species Name' );
				$data['voucher_number'] = get_ofv( $ofvs, 'Voucher Number' );
				$data['voucher_numbers'] = get_ofv( $ofvs, 'Voucher Number(s)' );
			}
			return $data;
		} else {
			$errors[] = 'Observation not found for observation ID ' . $observationid;
			return null;
		}
	} else {
		$errors[] = 'No valid observation ID provided.';
		return null;
	}
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
	// Create array of what data was requested
	$datarequested = array_keys( $_POST['options'] );
	$keytype = $_POST['key'];
	if ( isset( $_POST['field'] ) ) {
		$field = $_POST['field'];
	} else {
		$field = 'id';
	}
	// If an observation list was posted, look up the data
	if ( $_FILES && isset( $_FILES['observationsreport'] )
		&& isset( $_FILES['observationsreport']['tmp_name'] )
		&& $_FILES['observationsreport']['tmp_name'] !== '' )
	{
		$tmpName = $_FILES['observationsreport']['tmp_name'];
		// Put all the data into arrays based on the column
		$rows = array_map( 'str_getcsv', file( $tmpName ) );
		$header = array_shift( $rows );
		foreach ( $rows as $row ) {
			$indexed = array_combine( $header, $row );
			// Build an observation list array based on the specified key field
			$observationlist[] = $indexed[$field];
		}
	}
	if ( isset( $_POST['observations'] ) && $_POST['observations'] ) {
		$observationsfromtextarea = explode( "\n", $_POST['observations'] );
		$observationlist = array_merge( $observationlist, $observationsfromtextarea );
	}
	if ( $observationlist ) {
		// Limit to $maxrecords observations.
		$observationlist = array_slice( $observationlist, 0, $maxrecords );
		// Get iNat auth token
		$response = iNat_auth_request( $app_id, $app_secret, $username, $password );
		if ( $response && isset( $response['access_token'] ) ) {
			$token = $response['access_token'];
			foreach ( $observationlist as $observationkey ) {
				switch ( $keytype ) {
					case 'id':
						if ( preg_match( '/\d+/', $observationkey, $matches ) ) {
							$observationid = $matches[0];
							$observationdata[] = get_observation_data( $observationid );
						} else {
							$errors[] = 'Observation ID not valid:' . $observationkey;
							$observationdata[] = null;
						}
						break;
					case 'accession':
						$observationid = get_observation_id( $observationkey, 'accession' );
						if ( $observationid ) {
							$observationdata[] = get_observation_data( $observationid );
						} else {
							$errors[] = 'Observation not found for accession number ' . $observationkey;
							$observationdata[] = null;
						}
						break;
					case 'tag':
						$observationid = get_observation_id( $observationkey, 'tag' );
						if ( $observationid ) {
							$observationdata[] = get_observation_data( $observationid );
						} else {
							$errors[] = 'Observation not found for FUNDIS tag number ' . $observationkey;
							$observationdata[] = null;
						}
						break;
					case 'voucher':
						$observationid = get_observation_id( $observationkey, 'voucher' );
						if ( $observationid ) {
							$observationdata[] = get_observation_data( $observationid );
						} else {
							$observationid = get_observation_id( $observationkey, 'vouchers' );
							if ( $observationid ) {
								$observationdata[] = get_observation_data( $observationid );
							} else {
								$errors[] = 'Observation not found for voucher number ' . $observationkey;
								$observationdata[] = null;
							}
						}
						break;
				}
			}
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
$x = 0;
$y = 0;
if ( $observationdata ) {
	$fh = fopen( './data/inatdata.csv', 'w' );
	if ( $fh ) {
		fputcsv( $fh, $datarequested );
		// Filter observations for only the requested data.
		foreach ( $observationdata as $observation ) {
			if ( $observation && is_array( $observation ) ) {
				$observation = array_intersect_key( $observation, array_fill_keys( $datarequested, null ) );
				if ( $observation && is_array( $observation ) ) {
					fputcsv( $fh, $observation );
					$y++;
				}
			}
			$x++;
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
print( "<p>Observations processed: " . $x . "<br>" );
print( "Observations written to output file: " . $y . "</p>" );
if ( $x > 0 ) {
	print( '<p>Output file: <a href="data/inatdata.csv">inatdata.csv</a></p>' );
}
?>
</div>
</body>
</html>
