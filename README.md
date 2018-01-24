# Audit Server

[![Build Status](https://travis-ci.org/wptide/audit-server.svg?branch=master)](https://travis-ci.org/wptide/audit-server) [![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html)

**NOTE:** Please see documentation for [Tide Local](https://github.com/wptide/tide-local) to start the audit polling process.

The Audit Server is a Symfony commandline app that is responsible for polling a given SQS queue for new audit tasks to perform. These tasks are then run against the given standards (defined in the SQS task). Upon completion the tasks are sent back to the [Tide API](https://github.com/wptide/api).

This app is responsible for performing PHPCS code sniffs against the given standards. By default the standards used in sniffs are the [WPCS WordPress](https://github.com/WordPress-Coding-Standards/WordPress-Coding-Standards/tree/develop/WordPress) and @wimg [PHPCompatibility](https://github.com/wimg/PHPCompatibility) standards. Contribution to these two projects will help improve the results given by the Audit Server.

The PHPCompatibility sniffs have known inconsistency in its messaging. This makes the PHP compatibility logic complex. All best effort have been put into making these checks as reliable as possible. Contribution to this logic is very welcome.     

## About SQS

Audit requests are sent to the Audit Server via an SQS queue (https://aws.amazon.com/sqs/). If your local development environment is not connected to an active SQS queue, the Audit Server will have nothing to poll and have no audit request to process.

It appears that AWS did provide SQS mocking functionality in previous versions of their PHP SDK, but this is not available anymore.

However, with a basic understanding of the individual parts of the system it is possible to manually mock SQS messages for testing purposes.

The basic structure of an SQS message is as follows:

```
{
  "MessageId": "MessageId goes here",
  "ReceiptHandle": "ReceiptHandle goes here",
  "MD5OfBody": "MD5OfBody goes here",
  "Body": "Body goes here"
}
```

The body is in JSON format. For example, the body in a message generated for an Audit Request for the Twenty Seventeen Theme would look like this (formatted for readability):

```
{
  "response_api_endpoint": "http:\\/\\/tide.local\\/api\\/tide\\/v1\\/audit",
  "title": "Twenty Seventeen theme",
  "content": "Description of the twentyseventeen theme",
  "source_url": "https:\\/\\/downloads.wordpress.org\\/theme\\/twentyseventeen.1.4.zip",
  "source_type": "zip",
  "request_client": "wporg",
  "force": false,
  "visibility": "public",
  "standards": ["phpcs_phpcompatibility"],
  "audits": [
    {
      "type": "phpcs",
      "options": {
        "standard": "phpcompatibility",
        "report": "json",
        "encoding": "utf-8",
        "runtime-set": "testVersion 5.2-"
      }
    },
    {
      "type": "phpcs",
      "options": {
        "standard": "wordpress",
        "report": "json",
        "encoding": "utf-8",
        "ignore": "*\\/vendor\\/*,*\\/node_modules\\/*"
      }
    }
  ],
  "slug": "twentyseventeen"
}
```

Both the API and Sync Server can add messages to the SQS queue for the Audit Server to pick up.

The code in the API responsible for creating SQS messages can be found in the `AWS_SQS::add_task()` method : https://github.com/wptide/wp-tide-api/blob/master/php/integration/class-aws-sqs.php#L78

If you’d like to experiment with manually mocking SQS, you could temporarily change the Audit Server’s `AwsSqsManager::getSingleMessage()` method to return a hard-coded message of your choice instead of fetching messages from a queue.

The `AwsSqsManager::getSingleMessage()` method can be found here: https://github.com/wptide/audit-server/blob/master/src/XWP/Bundle/AuditServerBundle/Extensions/AwsSqsManager.php#L78

Replacing the `AwsSqsManager::getSingleMessage()` method with this snippet will result in always returning a message with a body that corresponds to the example above:

```php
public function getSingleMessage($queueUrl = '')
    {
        return array(
                'MessageId' => '',
                'ReceiptHandle' => '',
                'MD5OfBody' => '',
                'Body' => '{
                              "response_api_endpoint": "http:\\/\\/tide.local\\/api\\/tide\\/v1\\/audit",
                              "title": "Twentyseventeen Theme",
                              "content": "Description of the twentyseventeen theme",
                              "source_url": "https:\\/\\/downloads.wordpress.org\\/theme\\/twentyseventeen.1.4.zip",
                              "source_type": "zip",
                              "request_client": "wporg",
                              "force": false,
                              "visibility": "public",
                              "standards": ["phpcs_phpcompatibility"],
                              "audits": [
                                {
                                  "type": "phpcs",
                                  "options": {
                                    "standard": "phpcompatibility",
                                    "report": "json",
                                    "encoding": "utf-8",
                                    "runtime-set": "testVersion 5.2-"
                                  }
                                },
                                {
                                  "type": "phpcs",
                                  "options": {
                                    "standard": "wordpress",
                                    "report": "json",
                                    "encoding": "utf-8",
                                    "ignore": "*\\/vendor\\/*,*\\/node_modules\\/*"
                                  }
                                }
                              ],
                              "slug": "twentyseventeen"
                            }',
            );
    }
```

## Props

[@davidlonjon](https://github.com/davidlonjon), [@miina](https://github.com/miina), [@rheinardkorf](https://github.com/rheinardkorf), [@valendesigns](https://github.com/valendesigns), [@davidcramer](https://github.com/davidcramer), [@danlouw](https://github.com/danlouw), [@sayedtaqui](https://github.com/sayedtaqui)
