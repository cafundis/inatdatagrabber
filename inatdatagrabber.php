<?php
// This script is dual licensed under the MIT License and the CC0 License.
error_reporting( E_ALL );
ini_set( 'display_errors', 1 );
ini_set( 'max_execution_time', 900 );

include 'conf.php';

$useragent = 'iNatDataGrabber/1.0';
$inatapi = 'https://api.inaturalist.org/v1/';
$errors = [];
$observationdata = [];
$fileoutput = false;
$sleeptime = 1;

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

function get_places( $placeids, $observationid ) {
	global $inatapi, $errors;
	$placelist = implode( ',', $placeids );
	$url = $inatapi . 'places/' . $placelist . '?admin_level=0,10,20';
	$inatdata = make_curl_request( $url );
	if ( $inatdata && $inatdata['results'] ) {
		$places = [];
		foreach ( $inatdata['results'] as $place ) {
			switch ( $place['admin_level'] ) {
				case 0:
					$places['country'] = $place['name'];
					break;
				case 10:
					$places['state'] = $place['name'];
					break;
				case 20:
					// iNat doesn't include 'County', 'Parish', etc. in the place name for US locations.
					if ( strpos( $place['display_name'], ', US' ) === false ) {
						$places['region'] = $place['name'];
					} else {
						$placenameparts = explode( ',', $place['display_name'], 2 );
						if ( $placenameparts[0] ) {
							$places['region'] = $placenameparts[0];
						} else {
							$places['region'] = $place['name'];
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

function get_observation_data( $observationid ) {
	global $inatapi, $errors;
	// Initialize data
	$data = array(
		'observation_id'=>null,
		'collector'=>null,
		'date'=>null,
		'latitude'=>null,
		'longitude'=>null,
	);
	if ( $observationid && is_numeric( $observationid ) ) {
		$data['observation_id'] = $observationid;
		$url = $inatapi . 'observations/' . $observationid;
		$inatdata = make_curl_request( $url );
		if ( $inatdata && $inatdata['results'] && $inatdata['results'][0] ) {
			$results = $inatdata['results'][0];
			// Array numbering starts at 0 so the first element is empty.
			$data['date'] = $results['observed_on_details']['year'] . '-' . sprintf('%02d',$results['observed_on_details']['month']) . '-' . sprintf('%02d',$results['observed_on_details']['day']);
			$data['collector'] = $results['user']['name'];
			$data['username'] = $results['user']['login'];
			$location = explode( ',', $results['location'] );
			$data['latitude'] = $location[0];
			$data['longitude'] = $location[1];
			$places = get_places( $results['place_ids'], $observationid );
			if ( $places ) {
				$data = array_merge( $data, $places );
			}
			return $data;
		} else {
			$errors[] = 'Observation not found: ' . $observationid;
			return null;
		}
	} else {
		$errors[] = 'No valid observation ID provided.';
		return null;
	}
}

// See if form was submitted.
if ( $_POST ) {
	// If an observation was posted, look up the data.
	if ( isset( $_POST['observations'] ) ) {
		$start_time = microtime( true );
		$fileoutput = isset( $_POST['fileoutput'] ) ? true : false;
		$username = isset( $_POST['username'] ) ? true : false;
		$observationlist = explode( "\n", $_POST['observations'] );
		// Limit to 96 observations.
		$observationlist = array_slice( $observationlist, 0, 96 );
		$a = 0;
		foreach ( $observationlist as $observationid ) {
			if ( preg_match( '/\d+/', $observationid, $matches ) ) {
				$observationid = $matches[0];
				$observationdata[$a] = get_observation_data( $observationid );
			} else {
				$errors[] = 'Invalid observation number: ' . $observationid;
				$observationdata[$a] = null;
			}
			if ( count( $observationlist ) > 1 ) {
				sleep( $sleeptime );
			}
			$a++;
		}
		$end_time = microtime( true );
		$execution_time = ( $end_time - $start_time );
	}
}
?>
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
	<meta http-equiv="Content-Language" content="en-us">
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
.resulttable {
    background-color: #f8f9fa;
    color: #202122;
    margin: 1em 0;
    border: 1px solid #a2a9b1;
    border-collapse: collapse;
}
.resulttable > tr > th, .resulttable > * > tr > th {
    background-color: #eaecf0;
    text-align: center;
    font-weight: bold;
}
.resulttable > tr > th, .resulttable > tr > td, .resulttable > * > tr > th, .resulttable > * > tr > td {
    border: 1px solid #a2a9b1;
    padding: 0.2em 0.4em;
}
td.nowrap {
	white-space: nowrap;
}
p.dataoptions {
	line-height: 1.5;
}
</style>
<script src="./jquery.min.js"></script>
<script type="text/javascript">
$(document).ready(function () {
    $("#lookupform").submit(function () {
        $(".submitbtn").attr("disabled", true);
        return true;
    });
});
</script>
</head>
<body>
<div id="content">
<form id="lookupform" action="inatdatagrabber.php" method="post">
<p>Please provide a list of iNaturalist observation numbers. These can be supplied either by pasting in a list below or uploading a csv file.</p>
<p>
	Observation List (1 per line, max 96):<br/><textarea rows="5" cols="50" name="observations"></textarea>
</p>
<p>… or …</p>
<p><input type="file" id="observationsreport" name="observationsreport" /></p>
<p class="dataoptions">
	<h3>Data Options</h3>
	&nbsp;&nbsp;&nbsp;&nbsp;<input type="checkbox" id="fileoutput" name="fileoutput" <?php if ($fileoutput) echo "checked";?> value="yes">
	<label for="fileoutput">Output data to csv file</label><br/>
	&nbsp;&nbsp;&nbsp;&nbsp;<input type="checkbox" id="username" name="username" checked value="yes">
	<label for="username">User Name</label><br/>
</p>
<input class="submitbtn" type="submit" />
</form>

<?php
if ( $errors ) {
	print( '<p id="errors">' );
	print( 'Errors:<br/>' );
	foreach ( $errors as $error ) {
		print( $error . '<br/>' );
	}
	print( '</p>' );
}
if ( $observationdata ) {
	if ( !$fileoutput ) {
		print( '<table class="resulttable" border="0" cellpadding="5" cellspacing="10">' );
		print( '<tr><th>Observation ID</th><th>Collector</th><th>User Name</th><th>Collection Date</th><th>County</th><th>State</th><th>Latitude</th><th>Longitude</th></tr>' );

		foreach ( $observationdata as $observation ) {
			print( '<tr>' );
				isset( $observation['observation_id'] ) ? print( '<td class="nowrap">'.$observation['observation_id'].'</td>' ) : print( '<td></td>' );
				isset( $observation['collector'] ) ? print( '<td>'.$observation['collector'].'</td>' ) : print( '<td></td>' );
				isset( $observation['username'] ) && $username ? print( '<td>'.$observation['username'].'</td>' ) : print( '<td></td>' );
				isset( $observation['date'] ) ? print( '<td class="nowrap">'.$observation['date'].'</td>' ) : print( '<td></td>' );
				isset( $observation['region'] ) ? print( '<td class="nowrap">'.$observation['region'].'</td>' ) : print( '<td></td>' );
				isset( $observation['state'] ) ? print( '<td class="nowrap">'.$observation['state'].'</td>' ) : print( '<td></td>' );
				isset( $observation['latitude'] ) ? print( '<td>'.$observation['latitude'].'</td>' ) : print( '<td></td>' );
				isset( $observation['longitude'] ) ? print( '<td>'.$observation['longitude'].'</td>' ) : print( '<td></td>' );
			print( '</tr>' );
		}
		print( '</table>' );
	}
}
?>
</body>
</html>
