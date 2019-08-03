<?php
	/**
	 * Add a potential CLI Param.
	 *
	 * @param $short Short code for this param. ('' for no short param, must be a
	 *        single characater, 'h' is not permitted)
	 * @param $long Long code for this param. (required, 'help' is
	 *        not permitted)
	 * @param $description Description for this param
	 * @param $takesValue (Default = false) Does this param require a value?
	 */
	function addCLIParam($short, $long, $description, $takesValue = false) {
		global $__daemontools;

		if ($short == null) { $short = ''; }
		if (strlen($short) > 1) { $short = ''; }
		if ($long == null) { return; }
		if ($short == 'h' || $long == 'help') { return; }
		if (empty($short) && empty($long)) { return; }
		if (isset($__daemontools['cli']['params'][$long])) { return; }
		if ($short != '' && isset($__daemontools['cli']['shortmap'][$short])) { return; }

		$long = strtolower($long);
		$short = strtolower($short);
		$__daemontools['cli']['params'][$long] = array('short' => $short,
							  'long' => $long,
							  'description' => $description,
							  'takesValue' => $takesValue
							 );
		if ($short != '') { $__daemontools['cli']['shortmap'][$short] = $long; }

		if (isset($__daemontools['cli']['longest'])) {
			if (strlen($long) > $__daemontools['cli']['longest']) {
				$__daemontools['cli']['longest'] = strlen($long);
			}
		} else {
			$__daemontools['cli']['longest'] = strlen($long);
		}
	}

	/**
	 * Return a string that can be printed as help text.
	 *
	 * @return String to use as help text.
	 */
	function showCLIParams() {
		global $__daemontools;

		$result = '';

		$length = $__daemontools['cli']['longest'] + 5;

		foreach ($__daemontools['cli']['params'] as $param) {
			$short = (empty($param['short'])) ? '' : '-'.$param['short'].',';
			$long = '--'.$param['long'];
			$result .= sprintf('%-4s %-'.$length.'s  %s', $short, $long, $param['description']);
			if ($param['takesValue']) {
				$result .= ' [Requires value]';
			}
			$result .= "\n";
		}

		$result .= sprintf('%-4s %-'.$length.'s  %s', '-h,', '--help', 'Show help');
		$result .= "\n";

		return $result;
	}

	/**
	 * Parse the given string as CLI Params.
	 *
	 * @param $bits (Default = empty array) Args to parse.
	 * @param $ignorefirst (Default = true) Should the first value passwd in the
	 *        array be ignored? (usually the app name)
	 * @return Array representing what was passed to the application.
	 */
	function parseCLIParams($bits = array(), $ignorefirst = true) {
		global $__daemontools;

		// Temporarily add help entries
		$__daemontools['cli']['params']['help'] = array('short' => 'h', 'long' => 'help', 'description' => 'help', 'takesValue' => false);
		$__daemontools['cli']['shortmap']['h'] = 'help';

		$start = ($ignorefirst) ? 1 : 0;
		$parsed = array();
		// Loop through each of the bits
		for ($i = $start; $i < count($bits); $i++) {
			// Get the current bit.
			$bit = $bits[$i];

			// This list will contain all params found in this bit.
			$params = array();
			if (strpos($bit, '--') !== false && strpos($bit, '--') == 0) {
				// Long parameter.
				// Get the parameter
				$full_param_bit = substr($bit, 2);
				// Only get the bit before the = if there is one
				$param_bits = preg_split('@[=:]@', $full_param_bit);
				$param_bit = strtolower($param_bits[0]);
				// Check that it is valid,
				if (isset($__daemontools['cli']['params'][$param_bit])) {
					// If so, add it to the list of params we found in this "bit"
					// For long params this will be the only one.
					$params[] = $full_param_bit;
				} else {
					// If not, add it to the rejected list.
					$parsed['rejected params'][] = '--'.$param_bit;
				}
			} elseif (strpos($bit, '-') !== false && strpos($bit, '-') == 0) {
				// Short parameter(s)
				// Get the parameter(s)
				$param_bits = str_split(substr(strtolower($bit), 1));
				// Loop through each parameter passed here
				foreach ($param_bits as $param_bit) {
					// Check if we have the required short->long mapping.
					if (isset($__daemontools['cli']['shortmap'][$param_bit])) {
						// If so, add it to the list of params we found in this "bit"
						$params[] = $__daemontools['cli']['shortmap'][$param_bit];
					} else {
						// If not, add it to the rejected list.
						$parsed['rejected params'][] = '-'.$param_bit;
					}
				}
			} else {
				// Not a long parameter, or accpted as a value, add as rejected.
				$parsed['rejected params'][] = $bit;
			}

			// Loop though each param we found in this bit
			foreach ($params as $param) {
				// Split on = incase a value was given aswell.
				$param_bits = preg_split('@[=:]@', $param, 2);
				$value = (count($param_bits) > 1) ? $param_bits[1] : null;
				$param = strtolower($param_bits[0]);

				// Make sure an array in the $parsed array exists for this param
				if (!isset($parsed[$param])) { $parsed[$param] = array('count' => 0, 'values' => array()); }
				// Increase the count
				$parsed[$param]['count']++;
				// Check if the param takes a value.
				if ($__daemontools['cli']['params'][$param]['takesValue']) {
					// If it does, get the value.
					// First check if it was passed with an =
					if ($value != null) {
						$parsed[$param]['values'][] = $value;
					} else {
						// else get it from the bits passed to us, and increment the counter
						// so that the outer loop doesn't try to parse the value as a param
						$i++;
						$parsed[$param]['values'][] = $bits[$i];
					}
				} else {
					// Remove the value entry from the array if this param doesn't take
					// any parameters
					unset($parsed[$param]['values']);
				}
			}
		}

		// Remove help entries
		unset($__daemontools['cli']['params']['help']);
		unset($__daemontools['cli']['shortmap']['h']);

		// Store the parsed array so that it can be accessed by getParsedCLIParams()
		$__daemontools['cli']['parsed'] = $parsed;
		return $parsed;
	}

	/**
	 * Get the Parseed CLI Params.
	 *
	 * @return Array representing previously-parsed CLI Params.
	 */
	function getParsedCLIParams() {
		global $__daemontools;

		return (isset($__daemontools['cli']['parsed'])) ? $__daemontools['cli']['parsed'] : array();
	}
?>
