#!/bin/bash

logfile="/var/log/coh/sensor.collect.log"

if [ ! -f "$logfile" ]; then
  printf "0"
  exit 0
fi

result=$(grep -m 3 'Error' "$logfile")

if [ -z "$result" ]; then
  printf "0"
else
  echo "$result"
fi
