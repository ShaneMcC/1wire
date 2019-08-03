# 1WIRE Poller

This repo contains tools for collecting data from a Raspberry PI with 1wire temperature sensors. It is based on https://github.com/ShaneMcC/wemo

The data is collected by probes and then sent to a central server for graphing purposes.

To prevent data-loss, if the central server is unavailable, data will collect on the probes and then be pushed as soon as it becomes available again.

The probes will look for any devices on the 1 wire bus and collect data all `hwmon` data from them at the time they run, and submit the data for any they find.

Currently this only graphs for "temp1_input" value, even though the probes may collect more than that.
