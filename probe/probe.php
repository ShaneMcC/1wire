#!/usr/bin/php
<?php

	require_once(dirname(__FILE__) . '/config.php');
	require_once(dirname(__FILE__) . '/cliparams.php');

	addCLIParam('s', 'search', 'Just search for devices, don\'t collect or post any data.');
	addCLIParam('p', 'post', 'Just post stored data to collector, don\'t collect any new data.');
	addCLIParam('d', 'debug', 'Don\'t save data or attempt to post to collector, just dump to CLI instead.');
	addCLIParam('', 'key', 'Submission to key use rather than config value', true);
	addCLIParam('', 'location', 'Submission location to use rather than config value', true);
	addCLIParam('', 'server', 'Submission server to use rather than config value', true);

	$daemon['cli'] = parseCLIParams($_SERVER['argv']);
	if (isset($daemon['cli']['help'])) {
		echo 'Usage: ', $_SERVER['argv'][0], ' [options]', "\n\n";
		echo 'Options:', "\n\n";
		echo showCLIParams(), "\n";
		die(0);
	}

	if (isset($daemon['cli']['key'])) { $submissionKey = end($daemon['cli']['key']['values']); }
	if (isset($daemon['cli']['location'])) { $location = end($daemon['cli']['location']['values']); }
	if (isset($daemon['cli']['server'])) { $collectionServer = $daemon['cli']['server']['values']; }

	if (!is_array($collectionServer)) { $collectionServer = array($collectionServer); }

	$time = time();

	$devices = array();

	if (!isset($daemon['cli']['post'])) {
		foreach (glob('/sys/bus/w1/devices/28-*') as $basedir) {
			$name = trim(file_get_contents($basedir . '/name'));
			$serial = preg_replace('#.*-(.*)$#', '\1', $name);

			$dev = array();
			$dev['name'] = $name;
			$dev['serial'] = $serial;
			$dev['data'] = array();

			echo sprintf('Found: %s' . "\n", $dev['name']);

			if (isset($daemon['cli']['search'])) { continue; }

			foreach (glob($basedir . '/hwmon/hwmon*/*_input') as $sensor) {
				$sensorName = basename($sensor);
				$sensorValue = trim(file_get_contents($sensor));

				echo sprintf("\t" . 'Sensor: %s [%s]' . "\n", $sensorName, $sensorValue);
				$dev['data'][$sensorName] = $sensorValue;
			}

			$devices[] = $dev;
		}

		if (count($devices) > 0 && !isset($daemon['cli']['debug'])) {
			$data = json_encode(array('time' => $time, 'devices' => $devices));

			foreach ($collectionServer as $url) {
				$serverDataDir = $dataDir . '/' . parse_url($url, PHP_URL_HOST) . '-' . crc32($url) . '/';
				if (!file_exists($serverDataDir)) { @mkdir($serverDataDir, 0755, true); }
				if (file_exists($serverDataDir) && is_dir($dataDir)) {
					file_put_contents($serverDataDir . '/' . $time . '.js', $data);
				}
			}
		}
	}

	function unparse_url($parsed_url) {
		$scheme = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
		$host = isset($parsed_url['host']) ? $parsed_url['host'] : '';
		$port = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
		$user = isset($parsed_url['user']) ? $parsed_url['user'] : '';
		$pass = isset($parsed_url['pass']) ? ':' . $parsed_url['pass']  : '';
		$pass = ($user || $pass) ? "$pass@" : '';
		$path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
		$query = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
		$fragment = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';
		return "$scheme$user$pass$host$port$path$query$fragment";
	}

	function submitData($data, $url) {
		global $location, $submissionKey;

		$url = parse_url($url);
		$thisUser = isset($url['user']) ? $url['user'] : $location;
		$thisPass = isset($url['pass']) ? $url['pass'] : $submissionKey;
		unset($url['user']);
		unset($url['pass']);
		$url = unparse_url($url);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_USERPWD, $thisUser . ':' . $thisPass);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);

		$result = curl_exec($ch);
		curl_close($ch);

		$result = @json_decode($result, true);
		return $result;
	}

	/**
	 * Check is a string stats with another.
	 *
	 * @param $haystack Where to look
	 * @param $needle What to look for
	 * @return True if $haystack starts with $needle
	 */
	function startsWith($haystack, $needle) {
		$length = strlen($needle);
		return (substr($haystack, 0, $length) === $needle);
	}


	if (isset($daemon['cli']['search'])) { die(0); }
	if (isset($daemon['cli']['debug'])) {
		print_r($devices);
		die(0);
	}

	// Submit Data.
	foreach ($collectionServer as $url) {
		$serverDataDir = $dataDir . '/' . parse_url($url, PHP_URL_HOST) . '-' . crc32($url) . '/';

		if (file_exists($serverDataDir) && is_dir($serverDataDir)) {
			foreach (glob($serverDataDir . '/*.js') as $dataFile) {
				$data = file_get_contents($dataFile);
				$test = json_decode($data, true);
				if (isset($test['time']) && isset($test['devices'])) {
					$submitted = submitData($data, $url);
					if (isset($submitted['success'])) {
						echo 'Submitted data for: ', $test['time'], ' to ', $url, "\n";
						unlink($dataFile);
					} else {
						if (startsWith($submitted['error'], "illegal attempt to update using time")) {
							echo 'Data for ', $test['time'], ' to ', $url, ' is illegal - discarding.', "\n";
							unlink($dataFile);
						} else {
							echo 'Unable to submit data for: ', $test['time'], ' to ', $url, "\n";
						}
					}
				}
			}
		}
	}

	if (count($devices) > 0) { afterProbeAction($devices); }
