#!/usr/bin/php
<?php
	// Designed to be used as a callback for https://github.com/JsBergbau/MiTemperature2
	// with devices flashed with https://github.com/pvvx/ATC_MiThermometer

	if (!isset($argv[2])) { die(1); }

	$keys = explode(',', $argv[1]);
	$values = array_splice($argv, 2);
	$data = array_combine($keys, $values);

	$name = 'Mi_' . str_replace(':', '', $data['sensorname']);
	$data['name'] = $name;
	$data['serial'] = $data['sensorname'];
	unset($data['sensorname']);

	@mkdir('/run/MiTemp2/');
	@file_put_contents('/run/MiTemp2/' . $name . '.json', json_encode($data));
