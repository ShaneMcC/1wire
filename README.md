# 1WIRE Poller

This repo contains tools for collecting data from a Raspberry PI with 1-wire (DS18B20) or DHT11 temperature sensors. It is based on https://github.com/ShaneMcC/wemo

The data is collected by probes and then sent to a central server for graphing purposes.

To prevent data-loss, if the central server is unavailable, data will collect on the probes and then be pushed as soon as it becomes available again.

The probes will look for any devices on the 1wire bus and collect data all `hwmon` data from them at the time they run and any iio devices that expose temperature data, and submit the data for any they find.

Currently this only graphs some of the data that is collected even though the probes may collect and submit more.
