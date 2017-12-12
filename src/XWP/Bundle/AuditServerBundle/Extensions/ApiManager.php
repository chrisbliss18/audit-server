<?php

namespace XWP\Bundle\AuditServerBundle\Extensions;

use GuzzleHttp\Client as guzzleClient;
use GuzzleHttp\Exception\ClientException as guzzleClientException;

/**
 * Api manager.
 *
 * @since  v0.1
 */
class ApiManager extends BaseManager
{
    /**
     * Settings.
     *
     * @var array
     */
    private $settings;

    /**
     * Api auth token.
     *
     * @var string
     */
    protected $authToken;

    /**
     * Http client.
     *
     * @var \GuzzleHttp\Client
     */
    private $HttpClient;

    /**
     * Constructor.
     *
     * @param  array $settings Settings.
     * @param  object $logger  Logger.
     *
     * @return void
     */
    public function __construct($settings)
    {
        $this->settings = $settings;

        $this->connectTimeout = $this->settings['connect_timeout'] ?? 5;
        $this->authToken = '';
        $this->HttpClient = new guzzleClient();
    }

    /**
     * Get API authorization token.
     *
     * @param  array $apiSettings Api settings.
     *
     * @return bool
     */
    public function getAuthToken($apiSettings)
    {
        $apiAuthUrl = $apiSettings['auth_url'] ?? '';
        $apiKey = $apiSettings['key'] ?? '';
        $apiSecret = $apiSettings['secret'] ?? '';

        if (empty($apiAuthUrl) || empty($apiKey) || empty($apiSecret)) {
            return false;
        }

        try {
            $apiResponse = $this->HttpClient->request('POST', $apiAuthUrl, [
               'form_params' => array(
                   'api_key' => $apiKey,
                   'api_secret' => $apiSecret,
               ),
               'connect_timeout' => $this->connectTimeout
            ]);
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }

        if (200 === $apiResponse->getStatusCode()) {
            $content = json_decode($apiResponse->getBody()->getContents());
            if (isset($content->access_token)) {
                $this->authToken = $content->access_token;
            } else {
                throw new \Exception("Can't get API authorization token");
            }
        }
    }

    /**
     * Create payload object to send back to API.
     *
     * @param  array $auditsRequest        Audits request.
     * @param  array $auditsRequestReports Audits request report.
     * @param  bool $auditsRequestStatus  Audits request status.
     *
     * @return array
     */
    public function createPayload($auditsRequest, $auditsRequestReports, $auditsRequestStatus)
    {
        $payload = [
            'codeInfo' => [],
            'revisionMeta' => [],
            // 'status' => false,
        ];

        $payload['revisionMeta'] = $auditsRequest['revisionMeta'] ?? [];
        $payload['codeInfo'] = $auditsRequest['codeInfo'] ?? [];
        $payload['checksum'] = $auditsRequest['auditsFilesChecksum'] ?? '';

        // $payload['status'] = 'success';
        // if (false === $auditsRequestStatus) {
        //     $payload['status'] = 'error';
        // }

        $payload = array_merge($payload, $auditsRequestReports);

        $codeInfo = CodeIdentityManager::transformCodeInfo($auditsRequest['codeInfo']);

        // Ensure the payload includes basic plugin information.
        $payload['title']      = ! empty($codeInfo['details']['name']) ? $codeInfo['details']['name'] : $auditsRequest['title'];
        $payload['content']    = ! empty($codeInfo['details']['description']) ? $codeInfo['details']['description'] : $auditsRequest['content'];
        $payload['version']    = ! empty($codeInfo['details']['version']) ? $codeInfo['details']['version'] : '';
        $payload['standards']  = ! empty($auditsRequest['standards']) ? $auditsRequest['standards'] : '';
        $payload['visibility'] = ! empty($auditsRequest['visibility']) ? $auditsRequest['visibility'] : 'private';
        $payload['sourceUrl']  = $auditsRequest['sourceUrl'];
        $payload['sourceType'] = $auditsRequest['sourceType'];

        // We're setting the author to the client that requested the audit.
        // @todo As future requirements re projects and ownership arise this needs to be refactored.
        $payload['requestClient'] = ! empty($auditsRequest['requestClient']) ? $auditsRequest['requestClient'] : 'audit-server';

        // Assign the project identifier to the audit_project taxonomy.
        // This will come from codeInfo::textdomain usually, but may come from the original payload too.
        if (empty($auditsRequest['audit_project'])) {
            // Note, we're using a separate `project` rest field so that we can set terms by name and not be restricted to ID.
            $slug = ! empty($auditsRequest['slug']) ? $auditsRequest['slug'] : $codeInfo['details']['textdomain'];
            $payload['project'] = array(
                $slug,
            );
        }

        // Payload should not include slug.
        unset($payload['slug']);

        $payload = $this->helpers['array']->snakeCaseKeys($payload, [], ['files']);

        return $payload;
    }

    /**
     * Send payload to api response endpoint.
     *
     * @param  string $apiResponseEndpoint Api response endpoint.
     * @param  array $payload             Payload.
     * @param  string $method             Method.
     *
     * @return array Json response.
     * @throws \Exception Throw exceptions on invalid API requests.
     */
    public function sendPayload($apiResponseEndpoint, $payload, $method = 'POST')
    {
        try {
            $request_body = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->authToken,
                ],
                'json' => $payload,
                'connect_timeout' => $this->connectTimeout
            ];

            // Sending empty payloads return invalid results, so strip them out.
            if (empty($payload)) {
                unset($request_body['json']);
            }

            $apiResponse = $this->HttpClient->request($method, $apiResponseEndpoint, $request_body);
        } catch (guzzleClientException $e) {
            throw new \Exception($e->getMessage());
        }

        if (200 === $apiResponse->getStatusCode() || 201 === $apiResponse->getStatusCode()) {
            return json_decode($apiResponse->getBody(), true);
        }

        throw new \Exception("Invalid response from Tide API on POST '$apiResponseEndpoint': " . $apiResponse->getStatusCode());
    }

    /**
     * Check for existing audits report.
     *
     * @param  array $auditsRequest Audits request.
     *
     * @return string|bool Existing audit reports.
     */
    public function checkForExistingAuditReports($auditsRequest)
    {

        if (! self::is_collection_endpoint($auditsRequest['responseApiEndpoint'])) {
            // Lookup by checksum not id.
            $apiResponseEndpoint = preg_replace('/([\d]+)$/', $auditsRequest['auditsFilesChecksum'], $auditsRequest['responseApiEndpoint']);
        } else {
            // Look for existing result by adding checksum.
            $apiResponseEndpoint = sprintf('%s/%s', $auditsRequest['responseApiEndpoint'], $auditsRequest['auditsFilesChecksum']);
        }

        try {
            $apiResponse = $this->sendPayload($apiResponseEndpoint, false, 'GET');
            $auditReports = array_key_exists('results', $apiResponse) ? $apiResponse['results'] : array();
        } catch (\Exception $e) {
            $auditReports = array();
        }

        return $auditReports;
    }

    /**
     * Determine if an endpoint is a collection endpoint.
     *
     * If it does not end with a post ID or a SHA256 checksum its a collection.
     *
     * @param string $endpoint The endpoint to check.
     *
     * @return bool
     */
    public static function is_collection_endpoint($endpoint)
    {
        return ! preg_match('/[a-fA-F\d]{64}/', $endpoint) && ! preg_match('/([\d]+)$/', $endpoint);
    }
}
