<?php

	/** RRD storage directory. */
	$rrdDir = dirname(__FILE__) . '/rrds/';

	/** Path to rrdtool binary */
	$rrdtool = '/usr/bin/rrdtool';

	/** Default Graph Width. */
	$graphWidth = 800;

	/** Default Graph Height. */
	$graphHeight = 500;

	/** Should graph be linear rather than exponential? */
	$linearGraph = false;

	/** Allow showGraph debug outputs to be used. */
 	$allowDebug = true;

	/** Lower Limit for graphs. */
	$graphMin = 12;

	/** Upper Limit for graphs. */
	$graphMax = 30;

	/** Default graph steps (Default: empty, let rrdtool decide) */
	$graphSteps = '';

	/** Default graph start (Default: empty, let rrdtool decide) */
	$graphStart = '';

	/** Show Min/Avg/Max/Latest comments at bottom of graph. */
	$showDataComments = true;

	/** Line colour on top of the gradient */
	$lineColour = '#000000';

	/** Gradient for graphs. */
	$gradients = array();
	$gradients[] = 'ff0000';
	$gradients[] = 'ff0000';
	$gradients[] = 'ff0000';
	$gradients[] = 'ff0000';
	$gradients[] = 'ff1b00';
	$gradients[] = 'ff4100';
	$gradients[] = 'ff6600';
	$gradients[] = 'ff8e00';
	$gradients[] = 'ffb500';
	$gradients[] = 'ffdb00';
	$gradients[] = 'fdff00';
	$gradients[] = 'd7ff00';
	$gradients[] = 'b0ff00';
	$gradients[] = '8aff00';
	$gradients[] = '65ff00';
	$gradients[] = '3eff00';
	$gradients[] = '17ff00';
	$gradients[] = '00ff10';
	$gradients[] = '00ff36';
	$gradients[] = '00ff5c';
	$gradients[] = '00ff83';
	$gradients[] = '00ffa8';
	$gradients[] = '00ffd0';

	/**
	 * Extra options for rrdtool.
	 *
	 * This should be an array of additional options to pass.
	 *
	 * There are 3 places that additional parameters can be passed:
	 *   - flags: This will add the options after the initial flags, before
	 *            the DEF/CDEF/VDEFs.
	 *   - defs: This will add the options after all the DEF/CDEF/VDEFs
	 *   - end: This will add the options after the closing comments.
	 *
	 * Example: $rrdoptions[<graphtype>]['flags'] = array('--slope-mode', '--graph-render-mode mono');
	 */
	$rrdoptions['temp1']['flags'] = array();
	$rrdoptions['temp1']['defs'] = array();
	$rrdoptions['temp1']['end'] = array();

	$rrdoptions['temp'] = $rrdoptions['temp1'];

	/**
	 * Return detailed rrdtool errors to the submitting client?
	 *
	 * Defaults to false (disabled) and will return generic error to the client if
	 * rrdtool errors for some reason.
	 *
	 * Enabling this option will return the error in full to the client
	 * so that it might act accordingly.
	 *
	 * When enabled, this may expose more information than you might like
	 * about your sytem.
	 *
	 * NOTE: If rrdtool errors because you're trying to submit data with an
	 * illegal timestamp then we will tell the client so that it can make a
	 * decision as to how to deal with the data it just gathered.
	 *
	 */
	$rrdDetailedErrors = false;

	/**
	 * Automatically decide limits for graphs?
	 *
	 * If true, then $graphMin and $graphMax are multipliers on the min/max
	 * values to determine the scale.
	 */
	$autoLimit = false;

	// The above options can also be specified per-graph
	// using $graphOpts['<location>']['<serial>']['<option>'] = 'value';
	//
	// Options not set will use the defaults
	$graphOpts['Home']['ABCDEFGH'] = array('graphMin' => 20, 'graphMax' => 35);
	$graphOpts['Home']['ABCDEFGHI'] = array('graphMin' => 0.5, 'graphMax' => 1.75, 'autoLimit' => true);

	// It is also possible to specify additional parameters to pass to RRDTOOL
	// when drawing the graph.
	//
	// This should be an array of additional options to pass.
	//
	// These will be used INSTEAD OF $rrdoptions values where specified.
	$graphOpts['Home']['ABCDEFGH']['rrd_flags_temp1'] = array('--slope-mode', '--graph-render-mode mono');

	// Graphs to display in historical view.
	$historicalOptions = ['1 Day' => ['start' => '-1 days'],
	                      '10 Days' => ['start' => '-10 days'],
	                      'One Month' => ['start' => '-1 month'],
	                      'One Year' => ['start' => '-1 year'],
	                     ];

	$probes = array();
	// $probes['Home'] = 'SomePassword';

	if (file_exists(dirname(__FILE__) . '/config.user.php')) {
		require_once(dirname(__FILE__) . '/config.user.php');
	}

	// =========================================================================
	// The following functions can all be overridden in config.user.php
	//
	// These are the default implementations.
	// =========================================================================
	if (!function_exists('getCustomSettings')) {
		/**
		 * Return array of options to pass to `rrdtool graph` at various points.
		 *
		 * @param $location Graph probe location
		 * @param $serial Device serial number
		 * @param $type Graph Type
		 * @param $position Where in the array are we ("flags", "defs", "end")
		 * @return Array of extra lines to pass to rrdtool.
		 */
		function getCustomSettings($location, $serial, $type, $position) {
			global $graphOpts, $rrdoptions;

			if (getGraphOption($location, $serial, 'rrd_'.$position.'_' . $type, null) !== null) {
				return getGraphOption($location, $serial, 'rrd_'.$position.'_' . $type, null);
			} else if (isset($rrdoptions[$type][$position])) {
				return $rrdoptions[$type][$position];
			}

			return [];
		}
	}

	if (!function_exists('getGraphOption')) {
		/**
		 * Custom setting value.
		 *
		 * @param $location Graph probe location
		 * @param $serial Device serial number
		 * @param $option Option to get value for
		 * @param $fallback [Default: ''] Fallback value to use if there is no
		 *                  custom value.
		 * @return Value to use.
		 */
		function getGraphOption($location, $serial, $option, $fallback = '') {
			global $graphOpts;

			if (isset($graphOpts[$location][$serial][$option])) {
				return $graphOpts[$location][$serial][$option];
			}

			return $fallback;
		}
	}

	if (!function_exists('getLowerLimit')) {
		/**
		 * Function to get lower limit for graph.
		 *
		 * @param $minVal Minimum data point value.
		 * @return Minimum graph value to use.
		 */
		function getLowerLimit($minVal) {
			global $graphMin;
			return floor($minVal) * $graphMin;
		}
	}

	if (!function_exists('getUpperLimit')) {
		/**
		 * Function to get upper limit for graph.
		 *
		 * @param $maxVal Maximum data point value.
		 * @return Maximum graph value to use.
		 */
		function getUpperLimit($maxVal) {
			global $graphMax;
			return ceil($maxVal) * $graphMax;
		}
	}
