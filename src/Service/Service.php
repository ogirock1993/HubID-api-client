<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@hubculture.com>
 * @copyright (c) 2020 by HubCulture Ltd.
 * @since         16-09-2018
 */

namespace Hub\HubAPI\Service;

use Exception;
use Hub\HubAPI\HubClient;
use Hub\HubAPI\Service\Exception\HubIdApiException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Hub\HubAPI\Service\Model\File;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Valitron\Validator;

class Service
{
    const API_BASE_PATH = 'https://id.hubculture.com';

    /**
     * @var array runtime configuration containing the credentials etc.
     */
    protected $config;

    /**
     * @var Client curl client.
     */
    protected $client;

    /**
     * @var mixed for this class.
     */
    protected $logger;

    /**
     * Service constructor.
     *
     * @param array      $config runtime configuration containing the credentials etc. public and private keys are
     *                           mandatory.
     * @param mixed|null $logger Pass a logger instane to collect debug output in to your own logging output.
     *
     * @throws InvalidArgumentException when required config keys are not found.
     */
    public function __construct(array $config, $logger = null)
    {
        $validator = new Validator($config);
        $validator
            ->rule('required', ['private_key', 'public_key'])
            ->message('{field} - is required');
        if (!$validator->validate()) {
            throw new InvalidArgumentException('fields: private_key, public_key are required');
        }

        $this->config = array_merge(
            array(
                'base_path' => self::API_BASE_PATH,
                'verify' => true,

                // this will write any request to a log file (location: /tmp/hubid-api-client.log)
                'debug' => false,
                'log_file' => '/tmp/hubid-api-client.log', // debug output is written to here if enabled above

                // https://hubculture.com/developer/home
                'client_id' => 0,
                'private_key' => '',
                'public_key' => '',
            ),
            $config
        );

        if ($this->config['verify'] === false) {
            $this->client = new Client(array('verify' => false));
        } else {
            $this->client = new Client(array('verify' => true));
        }

        // set the logger if psr logger is available and a valid one passed in
        if (interface_exists('\Psr\Log\LoggerInterface') && $logger instanceof \Psr\Log\LoggerInterface) {
            $this->logger = $logger;
        }
    }

    /**
     * Use this to set an access token.
     *
     * @param string $accessToken
     */
    public function setAccessToken($accessToken)
    {
        $this->config['token'] = $accessToken;
    }

    /**
     * Returns the currently set and used access token.
     *
     * @return string currently set and used access token
     */
    public function getAccessToken()
    {
        return $this->config['token'];
    }

    /**
     * Use this to do a GET request.
     *
     * @param string $api    The API relative url
     * @param array  $params request parameters / payload
     *
     * @return array
     */
    protected function get($api, array $params = array())
    {
        return $this->requestWithForm($api, 'get', $params);
    }

    /**
     * Use this to do a PUT request.
     *
     * @param string $api    The API relative url
     * @param array  $params request parameters / payload
     *
     * @return array
     */
    protected function put($api, array $params = array())
    {
        return $this->requestWithForm($api, 'put', $params);
    }

    /**
     * Use this to do a POST request with JSON request body
     *
     * @param string $api    The API relative url
     * @param array  $params request parameters / payload to be submitted as JSON
     *
     * @return array
     */
    protected function postJson($api, array $params = array())
    {
        return $this->requestWithJson($api, 'post', $params);
    }

    /**
     * Use this to do a POST request.
     *
     * @param string $api    The API relative url
     * @param array  $params request parameters / payload
     *
     * @return array
     */
    protected function postFormData($api, array $params = array())
    {
        return $this->requestWithForm($api, 'post', $params);
    }

    /**
     * Use this to upload a file.
     *
     * @param string $api  The API relative url
     * @param File   $file the file to upload
     *
     * @return array
     */
    protected function uploadFile($api, File $file)
    {
        return $this->rawRequest(
            $api,
            array('multipart' => array($file->toArray())),
            'post'
        );
    }

    /**
     * Use this to do a DELETE request.
     *
     * @param string $api    The API relative url
     * @param array  $params request parameters / payload
     *
     * @return array
     */
    protected function delete($api, array $params = array())
    {
        return $this->requestWithForm($api, 'delete', $params);
    }

    /**
     * Use this to send any HTTP request with JSON request body.
     *
     * @param string $api    The API relative url
     * @param string $method HTTP method to use
     * @param array  $params request parameters / payload
     *
     * @return array
     */
    protected function requestWithJson($api, $method = 'get', array $params = array())
    {
        return $this->rawRequest(
            $api,
            array('body' => json_encode($params)),
            $method
        );
    }

    /**
     * Use this to send any HTTP request with request body as a form submission.
     *
     * @param string $api    The API relative url
     * @param string $method HTTP method to use
     * @param array  $params request parameters / payload
     *
     * @return array
     */
    protected function requestWithForm($api, $method = 'get', array $params = array())
    {
        return $this->rawRequest(
            $api,
            array('form_params' => $params),
            $method
        );
    }

    /**
     * Use this to transform the original response from the API handling errors.
     *
     * @param array $response api response to be transformed
     *
     * @return array
     * @throws HubIdApiException on any API error
     */
    protected function createResponse(array $response)
    {
        if (!empty($response['error'])) {
            throw new HubIdApiException($response['error']);
        }

        $default = array(
            'total' => 0,
            'offset' => 0,
            'limit' => 0,
            'items' => array(),
        );
        if (empty($response['data'])) {
            return $default;
        }

        return $response['data'];
    }

    /**
     * Use this to send a raw request of any type. Any types meant a form submission or a json or anything else
     * supported by the GuzzleHttp library.
     *
     * @param string $api     api endpoint
     * @param array  $payload request parameters / payload
     * @param string $method  HTTP method to use
     *
     * @return array
     */
    private function rawRequest($api, array $payload, $method = 'get')
    {
        $method = strtolower($method);
        $errorResponse = null;

        if (empty($payload['headers'])) {
            $payload['headers'] = $this->getHeaders();
        }

        try {
            $this->debug($api, $method, $payload);
            /** @var ResponseInterface $response */
            $response = $this->client->$method(
                sprintf('%s%s', $this->config['base_path'], $api),
                $payload
            );

            return json_decode($response->getBody()->getContents(), true);
        } catch (ClientException $ex) {
            $errorResponse = $ex->getResponse()->getBody()->getContents();
        } catch (Exception $ex) {
            $errorResponse = $ex->getMessage();
        }

        if (!is_null($errorResponse)) {
            $errorResponseDecoded = json_decode($errorResponse, true);
            if (!empty($errorResponseDecoded['errors'])) {
                if (is_array($errorResponseDecoded['errors'])) {
                    $exception = new HubIdApiException(print_r($errorResponseDecoded['errors'], true));
                    $exception->setCalledApi($api);
                    $exception->setErrors($errorResponseDecoded['errors']);
                    throw $exception;
                } else {
                    $exception = new HubIdApiException($errorResponseDecoded['errors']);
                    $exception->setCalledApi($api);
                    throw $exception;
                }
            }

            $exception = new HubIdApiException($errorResponse);
            $exception->setCalledApi($api);
            throw $exception;
        }

        return [];
    }

    /**
     * Returns headers for the API request with a token if available.
     * @return array
     */
    private function getHeaders()
    {
        $headers = array(
            'Public-Key' => $this->config['public_key'],
            'Private-Key' => $this->config['private_key'],
        );

        // inject authorization token if available
        if (!empty($this->config['token'])) {
            $headers['Authorization'] = sprintf('Bearer %s', $this->config['token']);
        }

        return $headers;
    }

    /**
     * Use this method to debug log the requests going out.
     *
     * @param string $api     called relative api endpoint
     * @param string $method  HTTP method used.
     * @param array  $payload request data used.
     */
    private function debug($api, $method, array $payload)
    {
        if (!$this->config['debug']) {
            return;
        }

        $string = "curl --insecure -X%s '%s' %s %s";
        $dataString = array();
        $headerString = array();

        // headers
        if (!empty($payload['headers']) && is_array($payload['headers'])) {
            foreach ($payload['headers'] as $header => $value) {
                $headerString[] = "-H '{$header}:{$value}'";
            }
        }
        $headerString = implode(' ', $headerString);

        if (!empty($payload['body'])) {
            // if json
            $dataString[] = $payload['body'];
            $dataString = "--data " . (implode(' ', $dataString));
        } else if (!empty($payload['form_params']) && is_array($payload['form_params'])) {
            // if form data
            foreach ($payload['form_params'] as $formParam => $value) {
                if (is_string($value) || is_numeric($value)) {
                    $dataString[] = sprintf("-F '%s=%s'", $formParam, $value);
                }
            }

            $dataString = implode(' ', $dataString);
        } else {
            $dataString = '';
        }

        $string = sprintf($string, strtoupper($method), $this->config['base_path'] . $api, $headerString, $dataString);
        file_put_contents($this->config['log_file'], $string . PHP_EOL, FILE_APPEND);
        if (!is_null($this->logger)) {
            $this->logger->debug(HubClient::COOKIE_TOKEN_NAME . ' : ' . $string);
        }
    }
}
