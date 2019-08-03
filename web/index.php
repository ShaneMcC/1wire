<?php
	$pageName = 'index';
	$graphPage = $pageName;
	$graphCustom = isset($_REQUEST['graphCustom']) ? $_REQUEST['graphCustom'] : '';
	require_once(dirname(__FILE__) . '/config.php');

	if (file_exists(dirname(__FILE__) . '/template/user/header.php')) { require_once(dirname(__FILE__) . '/template/user/header.php'); }
	else {
		echo '<html><head><title>1wire graphs</title></head>';
		echo '<body>';
		echo '<style>';
		echo 'div.graph { display: inline; } ';
		echo '</style>';
	}

	// Basic Graphing to start with.
	$types = ['temp1', 'temp', 'humidityrelative'];

	// Submit Data.
	if (file_exists($rrdDir) && is_dir($rrdDir)) {
		foreach ($types as $type) {
			foreach (glob($rrdDir . '/*/*/' . $type . '.rrd') as $rrd) {
				if (preg_match('#/([^/]+)/([^/]+)/' . $type . '.rrd#', $rrd, $m)) {
					$location = $m[1];
					$serial = $m[2];

					$options = [];
					$options['type'] = $type;
					$options['location'] = $location;
					$options['serial'] = $serial;
					$options['graphPage'] = $pageName;
					$options['graphCustom'] = $graphCustom;

					$typeClass = 'type_' . preg_replace('#[^a-z0-9]#i', '', $type);
					$serialClass = 'serial_' . preg_replace('#[^a-z0-9]#i', '', $serial);

					echo '<div class="graph index ', $typeClass, ' ', $serialClass, '">';
					echo '<a href="./historicalGraphs.php?', http_build_query($options), '">';
					echo '<img class="graph index ', $typeClass, ' ', $serialClass, '" src="./showGraph.php?', http_build_query($options), '" alt="', $type, ' for ', htmlspecialchars($location . ': ' . $serial), '">';
					echo '</a>';
					echo '</div>';
				}
			}
		}
	}

	if (file_exists(dirname(__FILE__) . '/template/user/footer.php')) { require_once(dirname(__FILE__) . '/template/user/footer.php'); }
	else {
		echo '</body>';
		echo '</html>';
	}
