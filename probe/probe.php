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

		// 1 Wire.
		foreach (glob('/sys/bus/w1/devices/28-*') as $basedir) {
			$name = trim(file_get_contents($basedir . '/name'));
			$serial = preg_replace('#.*-(.*)$#', '\1', $name);

			$dev = array();
			$dev['name'] = $name;
			$dev['serial'] = $serial;
			$dev['data'] = array();

			echo sprintf('Found: %s [%s]' . "\n", $dev['name'], $dev['serial']);

			if (isset($daemon['cli']['search'])) { continue; }

			foreach (glob($basedir . '/hwmon/hwmon*/*_input') as $sensor) {
				$sensorName = preg_replace('#^(.*)_input$#', '\1', basename($sensor));
				$sensorValue = trim(file_get_contents($sensor));

				echo sprintf("\t" . 'Sensor: %s [%s]' . "\n", $sensorName, $sensorValue);
				$dev['data'][$sensorName] = $sensorValue;
			}

			$devices[] = $dev;
		}

		// DHT11
		foreach (glob('/sys/bus/iio/devices/iio:*/') as $basedir) {
			$name = str_replace('@', '_', trim(file_get_contents($basedir . '/name')));

			// These things don't have a real serial :( They are 1-per-GPIO Pin
			// though, so we can use that as an identifier.
			$gpio = base_convert(unpack('H2', file_get_contents($basedir . '/of_node/gpios'), 7)[1], 16, 10);
			$serial = $name . '-gpio-' . $gpio;

			$dev = array();
			$dev['name'] = $name;
			$dev['serial'] = $serial;
			$dev['data'] = array();

			echo sprintf('Found: %s [%s]' . "\n", $dev['name'], $dev['serial']);

			if (isset($daemon['cli']['search'])) { continue; }

			foreach (glob($basedir . '/*_input') as $sensor) {
				$sensorName = preg_replace('#^in_(.*)_input$#', '\1', basename($sensor));

				$sensorValue = trim(file_get_contents($sensor));

				echo sprintf("\t" . 'Sensor: %s [%s]' . "\n", $sensorName, $sensorValue);
				$dev['data'][$sensorName] = $sensorValue;
			}

			$devices[] = $dev;
		}

		// MiTemperature
		foreach (glob('/run/MiTemp2/*.json') as $dataFile) {
			$mtime = filemtime($dataFile);
			if ($mtime < (time() - 120)) { continue; } // Ignore stale files.
			$data = json_decode(file_get_contents($dataFile), true);

			$dev = [];
			$dev['name'] = $data['name'];
			$dev['serial'] = $data['name'];
			$dev['data'] = [];
			// Convert the data values to the same format as DHT11.
			foreach (['temperature' => 'temp', 'humidity' => 'humidityrelative', 'voltage' => 'voltage'] as $dType => $dName) {
				if (isset($data[$dType])) {
					$dev['data'][$dName] = $data[$dType] * 1000;
				}
			}

			if (!empty($dev['data'])) {
				echo sprintf('Found: %s [%s]' . "\n", $dev['name'], $dev['serial']);

				if (isset($daemon['cli']['search'])) { continue; }

				$devices[] = $dev;
			}
		}

		// Hue Temperature Sensors
		foreach ($hueDevices as $hue => $huedevice) {
			// All devices from either API version
			$possibleDevs = [];

			// Used to convert the data values to the same format as elsewhere.
			$v1DataValues = [];
			$v1DataValues['temperature'] = ['name' => 'temp', 'value' => function($v) { return $v * 10;} ];

			$v2DataValues = [];
			$v2DataValues['battery'] = ['type' => 'device_power', 'value' => function($v) { return $v[0]['power_state']['battery_level']; }];

			$v2DataValues['presence'] = ['type' => 'motion', 'value' => function($v) { return $v[0]['motion']['motion']; }];
			$v2DataValues['lightlevel'] = ['type' => 'light_level', 'value' => function($v) { return $v[0]['light']['light_level']; }];
			$v2DataValues['temp'] = ['type' => 'temperature', 'value' => function($v) { return intval($v[0]['temperature']['temperature'] * 100); }];
			// TODO: These are not yet migrated to the new API I think?
			// $v2DataValues['dark'] = ['type' => 'device_power', 'value' => function($v) { return $v[0]['power_state']['battery_level']; }];
			// $v2DataValues['daylight'] = ['type' => 'device_power', 'value' => function($v) { return $v[0]['power_state']['battery_level']; }];

			$v2DataValues['open'] = ['type' => 'contact', 'value' => function($v) { return $v[0]['contact_report']['state'] == 'no_contact'; }];
			$v2DataValues['tampered'] = ['type' => 'tamper', 'value' => function($v) {
				foreach ($v as $c) {
					foreach ($c['tamper_reports'] as $tr) {
						if ($tr['state'] != 'not_tampered') { return true; }
					}
				}

				return false;
			}];

			$huedata = json_decode(@file_get_contents('http://' . $hue . '/api/' . $huedevice['apikey'] . '/sensors'), true);

			// Each physical device exposes multiple sensors that only contain partial information.
			// Put them all together here.
			foreach ($huedata as $sensor) {
				if ($sensor['type'] == 'CLIPGenericStatus') { continue; }
				if ($sensor['type'] == 'ZLLSwitch') { continue; }
				if (!isset($sensor['uniqueid'])) { continue; }
				if (!preg_match('#:#', $sensor['uniqueid'])) { continue; }


				if (preg_match('#^([0-9A-F:]+)-#i', $sensor['uniqueid'], $m)) {
					$serial = str_replace(':', '', $m[1]);
				}

				if (!isset($hueSensorDevs[$serial])) {
					$hueSensorDevs[$serial] = ['name' => 'Sensor', 'serial' => $serial, 'values' => []];
				}

				foreach ($sensor['state'] as $type => $value) {
					if ($type != 'lastupdated') {
						$hueSensorDevs[$serial]['values'][$type] = $value;
					}
				}

				if (isset($sensor['config']['battery'])) {
					$hueSensorDevs[$serial]['values']['battery'] = $sensor['config']['battery'];
				}

				if (!preg_match('#^Hue .* sensor [0-9]+$#', $sensor['name'])) {
					$hueSensorDevs[$serial]['name'] = $sensor['name'];
				}
			}

			// Now group them
			foreach ($hueSensorDevs as $sensor) {
				$dev = [];
				$dev['name'] = $sensor['name'];
				$dev['serial'] = $sensor['serial'];
				$dev['data'] = [];

				foreach ($sensor['values'] as $dName => $dValue) {
					$thisModifiers = isset($v1DataValues[$dName]) ? $v1DataValues[$dName] : [];
					if (isset($thisModifiers['name'])) { $dName = $thisModifiers['name']; }
					if (isset($thisModifiers['value'])) { $dValue = $thisModifiers['value']($dValue); }

					$dev['data'][$dName] = $dValue;
				}

				$possibleDevs[$dev['serial']] = $dev;
			}


			// Now lets try version 2 for some new things...
			$opts = ["http" => ["method" => "GET", "header" => "hue-application-key: " . $huedevice['apikey']], "ssl" => ["verify_peer" => false, "verify_peer_name" => false]];
			$huedata_v2 = json_decode(@file_get_contents('https://' . $hue . '/clip/v2/resource', false, stream_context_create($opts)), true);

			$hueSensorDevsV2 = [];

			// Find all devices.
			foreach ($huedata_v2['data'] as $sensor) {
				if ($sensor['type'] != 'device') { continue; }

				$hueSensorDevsV2[$sensor['id']] = [];
				$hueSensorDevsV2[$sensor['id']]['name'] = $sensor['metadata']['name'];
				$hueSensorDevsV2[$sensor['id']]['children'] = [];
			}

			// Find all resources that are related to a device.
			foreach ($huedata_v2['data'] as $sensor) {
				if (isset($sensor['owner']['rid']) && isset($hueSensorDevsV2[$sensor['owner']['rid']])) {
					if (!isset($hueSensorDevsV2[$sensor['owner']['rid']]['children'][$sensor['type']])) {
						$hueSensorDevsV2[$sensor['owner']['rid']]['children'][$sensor['type']] = [];
					}
					$hueSensorDevsV2[$sensor['owner']['rid']]['children'][$sensor['type']][] = $sensor;
				}
			}

			// Now extract the required data from each device+resource
			foreach ($hueSensorDevsV2 as $sensor) {
				if (!isset($sensor['children']['zigbee_connectivity'][0]['mac_address'])) { continue; }
				$serial = str_replace(':', '', $sensor['children']['zigbee_connectivity'][0]['mac_address']);

				if (isset($possibleDevs[$serial])) {
					$dev = $possibleDevs[$serial];
				} else {
					$dev = [];
					$dev['name'] = $sensor['name'];
					$dev['serial'] = $serial;
					$dev['serial'] = str_replace(':', '', $dev['serial']);
					$dev['data'] = [];
				}

				foreach ($v2DataValues as $key => $keyInfo) {
					// Don't override v1 data.
					if (isset($dev['data'][$key])) { continue; }

					if (isset($sensor['children'][$keyInfo['type']])) {
						$dev['data'][$key] = call_user_func($keyInfo['value'], $sensor['children'][$keyInfo['type']]);
					}
				}

				 $possibleDevs[$serial] = $dev;
			}


			// Finally, decide which devices we care about.
			foreach ($possibleDevs as $dev) {
				if (!empty($dev['data'])) {
					echo sprintf('Found: %s [%s]' . "\n", $dev['name'], $dev['serial']);

					if (isset($daemon['cli']['search'])) { continue; }

					$devices[] = $dev;
				}
			}
		}

		foreach (array_keys($awairDevices) as $awair) {
			$awairsettings = json_decode(@curl_get_contents('http://' . $awair . '/settings/config/data'), true);
			$awairdata = json_decode(@curl_get_contents('http://' . $awair . '/air-data/latest'), true);

			if (!isset($awairsettings['device_uuid']) || !isset($awairdata['timestamp'])) {
				// Sometimes the awair doesn't respond to both queries.
				// Ignore it for this cycle.
				continue;
			}

			$dev = [];
			$dev['name'] = $awairsettings['device_uuid'];
			$dev['serial'] = strtoupper(str_replace(':', '', $awairsettings['wifi_mac']));
			$dev['data'] = [];

			// Convert temp/humidity to be in line with other sensors.
			$modifiers = ['temp' => ['value' => function($v) { return $v * 1000;}],
			              'humid' => ['name' => 'humidityrelative', 'value' => function($v) { return $v * 1000;}],
			              'timestamp' => ['value' => function ($v) { return null; }]];

			foreach ($awairdata as $dName => $dValue) {
				$thisModifiers = isset($modifiers[$dName]) ? $modifiers[$dName] : [];
				if (isset($thisModifiers['name'])) { $dName = $thisModifiers['name']; }
				if (isset($thisModifiers['value'])) { $dValue = $thisModifiers['value']($dValue); }

				if ($dValue !== null) {
					$dev['data'][$dName] = $dValue;
				}
			}

			if (!empty($dev['data'])) {
				echo sprintf('Found: %s [%s]' . "\n", $dev['name'], $dev['serial']);
				if (isset($daemon['cli']['search'])) { continue; }
				$devices[] = $dev;
			}
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

	function curl_get_contents($url) {
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
		curl_setopt($ch, CURLOPT_TIMEOUT, 5);
		$content = curl_exec($ch);
		curl_close($ch);
		return $content;
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
		echo json_encode($devices, JSON_PRETTY_PRINT), "\n";
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
