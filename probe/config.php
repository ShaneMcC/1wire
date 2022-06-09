<?php

	/** This location. */
	$location = 'Home';

	/** Submission Key. */
	$submissionKey = 'SomePassword';

	/** Collection URL. */
	$collectionServer = 'http://127.0.0.1/1wire/submit.php';

	/** Phillips Hue Data Collection. */
	/** Need to get an API Key by following https://developers.meethue.com/develop/get-started-2/ */
	$hueDevices = [];
	// $hueDevices['192.168.1.1'] = ['apikey' => ''];

	// Collection Server can also be an array.
	//
	// $collectionServer = array();
	// $collectionServer[] = 'http://127.0.0.1/wemo/submit.php';
	// $collectionServer[] = 'http://Home:SomeOtherPassword@10.0.0.2/wemo/submit.php';
	//
	// If no location/key is specified in the url, then the default values of
	// $location and $submissionKey will be used.

	/** Data storage directory. */
	$dataDir = dirname(__FILE__) . '/data/';

	if (file_exists(dirname(__FILE__) . '/config.user.php')) {
		require_once(dirname(__FILE__) . '/config.user.php');
	}

	if (!function_exists('afterProbeAction')) {
		/**
		 * Function to run after finding all wemo devices to perform
		 * additional tasks.
		 * (Saves modules needing to re-scan every time.)
		 *
		 * @param $devices Devices array
		 */
		function afterProbeAction($devices) { }
	}
