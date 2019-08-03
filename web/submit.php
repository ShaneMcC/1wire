<?php
	$pageName = 'submit';
	$graphPage = $pageName;
	$graphCustom = '';
	require_once(dirname(__FILE__) . '/config.php');
	require_once(dirname(__FILE__) . '/functions.php');

	if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
		$location = $_SERVER['PHP_AUTH_USER'];
		$key = $_SERVER['PHP_AUTH_PW'];

		if (!isset($probes[$location]) || $probes[$location] != $key) {
			unset($_SERVER['PHP_AUTH_USER']);
			unset($_SERVER['PHP_AUTH_PW']);
		}
	}

	if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
		header('WWW-Authenticate: Basic realm="WEMO Probe Data"');
		header('HTTP/1.0 401 Unauthorized');

		die(json_encode(array('error' => 'Unauthorized')));
	}

	if (!file_exists($rrdtool)) { die(json_encode(array('error' => 'Internal Error'))); }

	$postdata = file_get_contents("php://input");
	$data = @json_decode($postdata, true);
	if ($data === null) { die(json_encode(array('error' => 'Invalid Data'))); }
	$data['location'] = preg_replace('#[^a-z0-9-_ ]#', '', strtolower($location));

	foreach ($data['devices'] as $dev) {
		$dev['serial'] = preg_replace('#[^A-Z0-9-_ ]#', '', strtoupper($dev['serial']));
		$dir = $rrdDir . '/' . $data['location'] . '/' . $dev['serial'];
		if (!file_exists($dir)) { mkdir($dir, 0755, true); }
		if (!file_exists($dir)) { die(json_encode(array('error' => 'Internal Error'))); }

		$meta = $dev;
		unset($meta['data']);
		@file_put_contents($dir . '/meta.js', json_encode($meta));

		foreach ($dev['data'] as $dataPoint => $dataValue) {
			list($dsname, $dstype) = [$dataPoint, 'GAUGE'];
			$storeValue = $dataValue;

			$rrdDataFile = $dir . '/'.$dsname.'.rrd';
			if (!file_exists($rrdDataFile)) { createRRD($rrdDataFile, $dsname, $dstype, $data['time']); }
			if (!file_exists($rrdDataFile)) { die(json_encode(array('error' => 'Internal Error'))); }

			$result = updateRRD($rrdDataFile, $dsname, $data['time'], $storeValue);
			if (startsWith($result['stdout'], 'ERROR:')) {
				// Strip path from the error along with new line
				$errorNoPath = substr($result['stdout'],strrpos($result['stdout'],":")+2,-1);

				// Check if the error is to do with illegal timestamp
				if ($rrdDetailedErrors || startsWith($errorNoPath, "illegal attempt to update using time")) {
					die(json_encode(array('error' => $errorNoPath)));
				} else {
					die(json_encode(array('error' => 'Internal Error')));
				}
			}
		}
	}

	function createRRD($filename, $dsname, $dstype, $startTime) {
		// Based on https://www.chameth.com/2016/05/02/monitoring-power-with-wemo.html
		$rrdData = array();
		$rrdData[] = 'create "' . $filename . '"';
		$rrdData[] = '--start ' . $startTime;
		$rrdData[] = '--step 60';
		$rrdData[] = 'DS:' . $dsname . ':' . $dstype . ':120:U:U';
		$rrdData[] = 'RRA:AVERAGE:0.5:1:1440';
		$rrdData[] = 'RRA:AVERAGE:0.5:10:1008';
		$rrdData[] = 'RRA:AVERAGE:0.5:30:1488';
		$rrdData[] = 'RRA:AVERAGE:0.5:120:1488';
		$rrdData[] = 'RRA:AVERAGE:0.5:360:1488';
		$rrdData[] = 'RRA:AVERAGE:0.5:1440:36500';

		return execRRDTool($rrdData);
	}

	function updateRRD($filename, $dsname, $time, $value) {
		$rrdData = array();
		$rrdData[] = 'update "' . $filename . '"';
		// $rrdData[] = '--skip-past-updates';
		$rrdData[] = '--template ' . $dsname;
		$rrdData[] = $time . ':' . $value;
		return execRRDTool($rrdData);
	}

	die(json_encode(array('success' => 'ok')));
