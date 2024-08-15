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
			$data['date'] = $results['observed_on_details']['year'] . '-' . $results['observed_on_details']['month'] . '-' . $results['observed_on_details']['day'];
			$data['collector'] = $results['user']['name'];
			$location = explode( ',', $results['location'] );
			$data['latitude'] = $location[0];
			$data['longitude'] = $location[1];
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
		$collector = isset( $_POST['collector'] ) ? true : false;
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
<p>
	Observation List (1 per line, max 96):<br/><textarea rows="5" cols="50" name="observations"></textarea>
</p>
<p class="dataoptions">
	<input type="checkbox" id="fileoutput" name="fileoutput" <?php if ($fileoutput) echo "checked";?> value="yes">
	<label for="fileoutput">Output data to csv file</label><br/>
	<input type="checkbox" id="collector" name="collector" checked value="yes">
	<label for="collector">Collector</label><br/>
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
		print( '<tr><th>Observation ID</th><th>Collector</th><th>Collection Date</th><th>Latitude</th><th>Longitude</th></tr>' );

		foreach ( $observationdata as $observation ) {
			print( '<tr>' );
				isset( $observation['observation_id'] ) ? print( '<td class="nowrap">'.$observation['observation_id'].'</td>' ) : print( '<td></td>' );
				isset( $observation['collector'] ) && $collector ? print( '<td>'.$observation['collector'].'</td>' ) : print( '<td></td>' );
				isset( $observation['date'] ) ? print( '<td class="nowrap">'.$observation['date'].'</td>' ) : print( '<td></td>' );
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
