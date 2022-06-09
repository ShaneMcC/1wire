#!/bin/bash

MYDIR=$(dirname "$0")

cd ${MYDIR}

(
	flock -x -n 200

	if [ ${?} -eq 0 ]; then
		${MYDIR}/probe.php "$@"
		exit 0;
	fi;
	exit 42;

) 200>${MYDIR}/.runlock

exit ${?}
