<?php

namespace XWP\Bundle\AuditServerBundle\Extensions;

/**
 * AWS SQS manager.
 *
 * @since  v0.1
 */
class AwsSqsManager extends BaseManager
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
	 * Queue URL.
	 *
	 * @var string
	 */
	private $queueUrl;

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

		$this->client = new \Aws\Sqs\SqsClient([
			'version' => $this->settings['version'],
			'region'  => $this->settings['region'],
			'credentials' => array(
				'key'    => $this->settings['key'],
				'secret' => $this->settings['secret'],
			),
		]);

		$this->queueUrl = $this->getQueueUrl($this->settings['queue_name']);
	}

	/**
	 * Get Queue URL from the queue name.
	 *
	 * @param  string $queueName Queue Name.
	 *
	 * @return string Queue URL.
	 */
	private function getQueueUrl($queueName = '')
	{
		$queueName = empty($queueName) ? $this->settings['queue_name'] : $queueName;
		$result = $this->client->getQueueUrl(array('QueueName' => $queueName));

		return $result->get('QueueUrl');
	}

	/**
	 * Get single message from a queue.
	 *
	 * @param  string $queueUrl Queue URL.
	 *
	 * @return array           Message.
	 */
	public function getSingleMessage($queueUrl = '')
	{
		$queueUrl = empty($queueUrl) ? $this->queueUrl : $queueUrl;
		$message = array();

		$data = array(
			'QueueUrl' => $queueUrl,
		);
		if ( preg_match( '/.fifo$/', $this->settings['queue_name'] ) ) {
			$data['MessageGroupId'] = $this->settings['queue_name'];
		}
		$result = $this->client->receiveMessage( $data );

		if (null !== $result['Messages']) {
			$message = array_shift($result['Messages']);
		}

		return $message;
	}

	/**
	 * Send a new Audit Task to the queue.
	 *
	 * @param array $auditTask The task to send to queue.
	 *
	 * @return bool|\Exception
	 */
	public function createAuditTask( $auditTask ) {
		try {

			// Send the message.
			$data = array(
				'QueueUrl'    => $this->queueUrl,
				'MessageBody' => json_encode( $auditTask ),
			);

			// Generate MessageGroupId for FIFO queues.
			$requestClient = $auditTask['request_client'];
			$slug          = $auditTask['slug'] ?? '';
			if ( empty( $slug ) ) {
				$slug = str_replace( ' ', '', strtolower( $auditTask['title'] ) );
			}
			$MessageGroupId = sprintf( '%s-%s', $requestClient, $slug );

			if ( preg_match( '/.fifo$/', $this->settings['queue_name'] ) ) {
				$data['MessageGroupId'] = $MessageGroupId;
			}

			$this->client->sendMessage( $data );

			return true;
		} catch ( \Exception $e ) {

			return $e;
		} // End try().
	}

	/**
	 * Delete message from a queue.
	 *
	 * @param  array $message  Message.
	 * @param  string $queueUrl Queue URL.
	 *
	 * @return void
	 */
	public function deleteMessage($message, $queueUrl = '')
	{
		$queueHandle = isset( $message['ReceiptHandle'] ) ? $message['ReceiptHandle'] : '';
		$queueUrl = empty( $queueUrl ) ? $this->queueUrl : $queueUrl;
		$this->client->deleteMessage( array(
			'QueueUrl' => $queueUrl,
			'ReceiptHandle' => $queueHandle
		) );
	}

	/**
	 * Get settings from the SQS manager.
	 *
	 * @param string $key     The key.
	 * @param bool   $default A fallback value.
	 *
	 * @return bool|mixed
	 */
	public function getSetting( $key, $default = false ) {
		if ( array_key_exists( $key, $this->settings ) ) {
			return $this->settings[ $key ];
		} else {
			return $default;
		}
	}
}
