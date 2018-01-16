# Audit Server

[![Build Status](https://travis-ci.org/wptide/audit-server.svg?branch=master)](https://travis-ci.org/wptide/audit-server) [![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html)

**NOTE:** Please see documentation for [Tide Local](https://github.com/wptide/tide-local) to start the audit polling process.

The Audit Server is a Symfony commandline app that is responsible for polling a given SQS queue for new audit tasks to perform. These tasks are then run against the given standards (defined in the SQS task). Upon completion the tasks are sent back to the [Tide API](https://github.com/wptide/api).

This app is responsible for performing PHPCS code sniffs against the given standards. By default the standards used in sniffs are the [WPCS WordPress](https://github.com/WordPress-Coding-Standards/WordPress-Coding-Standards/tree/develop/WordPress) and @wimg [PHPCompatibility](https://github.com/wimg/PHPCompatibility) standards. Contribution to these two projects will help improve the results given by the Audit Server.

The PHPCompatibility sniffs have known inconsistency in its messaging. This makes the PHP compatibility logic complex. All best effort have been put into making these checks as reliable as possible. Contribution to this logic is very welcome.     

## Props

[@davidlonjon](https://github.com/davidlonjon), [@miina](https://github.com/miina), [@rheinardkorf](https://github.com/rheinardkorf), [@valendesigns](https://github.com/valendesigns), [@davidcramer](https://github.com/davidcramer), [@danlouw](https://github.com/danlouw), [@sayedtaqui](https://github.com/sayedtaqui)
