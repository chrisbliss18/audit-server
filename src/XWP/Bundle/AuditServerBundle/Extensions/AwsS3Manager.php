<?php

namespace XWP\Bundle\AuditServerBundle\Extensions;

/**
 * AWS S3 manager.
 *
 * @since  v0.1
 */
class AwsS3Manager extends BaseManager
{
    /**
     * Settings.
     *
     * @var array
     */
    private $settings;

    /**
     * AWS SQS client.
     *
     * @var \Aws\Sqs\SqsClient
     */
    private $client;

    /**
     * Constructor.
     *
     * @param  array $settings Settings.
     *
     * @return void
     */
    public function __construct($settings)
    {
        $this->settings = $settings;

        $this->client = new \Aws\S3\S3Client([
            'version' => $this->settings['version'],
            'region'  => $this->settings['region'],
            'credentials' => array(
                'key'    => $this->settings['key'],
                'secret' => $this->settings['secret'],
            ),
        ]);
    }

    /**
     * Get S3 client.
     *
     * @return \Aws\S3\S3Client S3 client.
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Get bucket name.
     *
     * @return string Bucket name.
     */
    public function getBucketName()
    {
        return $this->settings['bucket_name'];
    }
}
