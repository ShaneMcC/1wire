<?php
	$pageName = 'historicalGraphs';
	$graphPage = $pageName;
	$graphCustom = isset($_REQUEST['graphCustom']) ? $_REQUEST['graphCustom'] : '';
	require_once(dirname(__FILE__) . '/config.php');

	$type = isset($_REQUEST['type']) ? $_REQUEST['type'] : null;
	$location = isset($_REQUEST['location']) ? $_REQUEST['location'] : null;
	$serial = isset($_REQUEST['serial']) ? $_REQUEST['serial'] : null;

	$start = isset($_REQUEST['start']) ? $_REQUEST['start'] : '';
	$end = isset($_REQUEST['end']) ? $_REQUEST['end'] : '';

	if (!isset($historicalOptions)) {
		$historicalOptions = ['1 Day' => ['start' => '-1 days'],
		                      '10 Days' => ['start' => '-10 days'],
		                      'One Month' => ['start' => '-1 month'],
		                      'One Year' => ['start' => '-1 year'],
		                     ];
	}

	// If the params are not passed to us, abort.
	if ($type == null || $location == null || $serial == null) { die('Internal Error.'); }

	// If the params look dodgy, abort.
	if (preg_match('#[^A-Z0-9-_ ]#i', $location) || preg_match('#[^A-Z0-9-_]#i', $serial)) { die('Internal Error.'); }

	$dir = $rrdDir . '/' . $location . '/' . $serial;
	$rrd = $dir . '/' . $type . '.rrd';
	$meta = $dir . '/meta.js';

	$title = $type . ' for ' . $location . ': ' . $serial;
	if (file_exists($meta)) {
		$meta = @json_decode(file_get_contents($meta), true);
		if ($meta !== null) {
			$title = $type . ' for ' . $location . ': ' . $meta['name'];
		}
	}
	$title = getGraphOption($location, $serial, 'title_' . $type, $title);

	if (file_exists(dirname(__FILE__) . '/template/user/header.php')) { require_once(dirname(__FILE__) . '/template/user/header.php'); }
	else {
		echo '<html><head><title>Historical Power Graphs :: ', isset($pageTitle) ? htmlspecialchars($pageTitle) : htmlspecialchars($title), '</title></head>';
		echo '<body>';
		echo '<style>';
		echo 'div.graph { display: inline; } ';
		echo '</style>';
	}

	echo '<div class="historicalHeader">';
	echo '<h1>';
	echo isset($pageTitle) ? htmlspecialchars($pageTitle) : htmlspecialchars($title);
	echo '</h1>';
	echo '<a class="backLink" href="./', (!empty($graphCustom) ? '?graphCustom=' . urlencode($graphCustom) : ''), '">[ Back to all graphs ]</a>';
	echo '<hr>';
	echo '</div>';

	echo '<div class="timeSelector">';
	echo '<form class="selector" method="GET" style="display: inline">';
	foreach ($_REQUEST as $k => $v) {
		if ($k != 'start' && $k != 'end') {
			echo '  <input type="hidden" name="', htmlspecialchars($k), '" value="', htmlspecialchars($v),'">';
		}
	}
	echo '  Start: ';
	echo '  <input type="text" name="start" value="', htmlspecialchars($start),'">';
	echo '  End:';
	echo '  <input type="text" name="end" value="', htmlspecialchars($end),'">';
	echo '  <input type="submit" value="Submit">';
	echo '</form>';

	echo '<form class="resetButton" method="GET" style="display: inline">';
	foreach ($_REQUEST as $k => $v) {
		if ($k != 'start' && $k != 'end') {
			echo '  <input type="hidden" name="', htmlspecialchars($k), '" value="', htmlspecialchars($v),'">';
		}
	}
	echo '  <input type="submit" value="Reset">';
	echo '</form>';
	echo '<hr>';
	echo '</div>';

	$typeClass = 'type_' . preg_replace('#[^a-z0-9]#i', '', $type);
	$serialClass = 'serial_' . preg_replace('#[^a-z0-9]#i', '', $serial);

	if ($start !== '' || $end !== '') {
		$historicalOptions = ['custom' => []];

		if ($start !== '') { $historicalOptions['custom']['start'] = $start; }
		if ($end !== '') { $historicalOptions['custom']['end'] = $end; }
		if (isset($_REQUEST['step'])) { $historicalOptions['custom']['step'] = $_REQUEST['step']; }

		$historicalOptions['custom']['title'] = $start . ' to ' . $end;
	}

	foreach ($historicalOptions as $name => $setting) {
		$options = [];
		$options['type'] = $type;
		$options['location'] = $location;
		$options['serial'] = $serial;
		$options['graphPage'] = $pageName;
		$options['graphCustom'] = $graphCustom;
		if (isset($setting['start'])) { $options['start'] = $setting['start']; }
		if (isset($setting['step'])) { $options['step'] = $setting['step']; }
		if (isset($setting['end'])) { $options['end'] = $setting['end']; }

		$nameClass = 'historical_' . preg_replace('#[^a-z0-9]#i', '', $name);
		echo '<div class="graph historical ', $nameClass, ' ', $typeClass, ' ', $serialClass, '">';
		echo '<h2>', htmlspecialchars(isset($setting['title']) ? $setting['title'] : $name), '</h2>';
		echo '<img src="./showGraph.php?', http_build_query($options), '" alt="', htmlspecialchars($name) , ' - ', htmlspecialchars($type), ' for ', htmlspecialchars($location . ': ' . $serial), '">';
		echo '<hr>';
		echo '</div>';
	}

	if (file_exists(dirname(__FILE__) . '/template/user/footer.php')) { require_once(dirname(__FILE__) . '/template/user/footer.php'); }
	else {
		echo '</body>';
		echo '</html>';
	}
