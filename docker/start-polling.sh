#!/bin/bash

set -e

php bin/console tide:audit-server --env=$TIDE_ENV
