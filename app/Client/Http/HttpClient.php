<?php

namespace App\Client\Http;

use App\Client\Configuration;
use App\Client\Http\Modifiers\CheckBasicAuthentication;
use App\Logger\RequestLogger;
use GuzzleHttp\Psr7\Message;
use function GuzzleHttp\Psr7\parse_request;
use Laminas\Http\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\Frame;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Socket\Connector;

class HttpClient
{
    /** @var LoopInterface */
    protected $loop;

    /** @var RequestLogger */
    protected $logger;

    /** @var Request */
    protected $request;

    protected $connectionData;

    /** @var array */
    protected $modifiers = [
        CheckBasicAuthentication::class,
    ];
    /** @var Configuration */
    protected $configuration;

    public function __construct(LoopInterface $loop, RequestLogger $logger, Configuration $configuration)
    {
        $this->loop = $loop;
        $this->logger = $logger;
        $this->configuration = $configuration;
    }

    public function performRequest(string $requestData, WebSocket $proxyConnection = null, $connectionData = null)
    {
        $this->connectionData = $connectionData;

        $this->request = $this->parseRequest($requestData);

        $this->logger->logRequest($requestData, $this->request);

        $request = $this->passRequestThroughModifiers(parse_request($requestData), $proxyConnection);

        transform($request, function ($request) use ($proxyConnection) {
            $this->sendRequestToApplication($request, $proxyConnection);
        });
    }

    protected function passRequestThroughModifiers(RequestInterface $request, ?WebSocket $proxyConnection = null): ?RequestInterface
    {
        foreach ($this->modifiers as $modifier) {
            $request = app($modifier)->handle($request, $proxyConnection);

            if (is_null($request)) {
                break;
            }
        }

        return $request;
    }

    protected function createConnector(): Connector
    {
        return new Connector($this->loop, [
            'dns' => config('expose.dns', '127.0.0.1'),
            'tls' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
    }

    protected function sendRequestToApplication(RequestInterface $request, $proxyConnection = null)
    {
        $uri = $request->getUri();

        if ($this->configuration->isSecureSharedUrl()) {
            $uri = $uri->withScheme('https');
        }

        (new Browser($this->loop, $this->createConnector()))
            ->withFollowRedirects(false)
            ->withRejectErrorResponse(false)
            ->requestStreaming(
                $request->getMethod(),
                $uri,
                $this->rewriteRequestHeaders($request->getHeaders()),
                $request->getBody()
            )
            ->then(function (ResponseInterface $response) use ($proxyConnection) {
                $response = $this->rewriteResponseHeaders($response);

                $response = $response->withoutHeader('Transfer-Encoding');

                $responseBuffer = Message::toString($response);

                $this->sendChunkToServer($responseBuffer, $proxyConnection);

                /* @var $body \React\Stream\ReadableStreamInterface */
                $body = $response->getBody();

                $this->logResponse(Message::toString($response));

                $body->on('data', function ($chunk) use ($proxyConnection, &$responseBuffer) {
                    $responseBuffer .= $chunk;

                    $this->sendChunkToServer($chunk, $proxyConnection);
                });

                $body->on('close', function () use ($proxyConnection, &$responseBuffer) {
                    $this->logResponse($responseBuffer);

                    optional($proxyConnection)->close();
                });
            });
    }

    protected function sendChunkToServer(string $chunk, ?WebSocket $proxyConnection = null)
    {
        transform($proxyConnection, function ($proxyConnection) use ($chunk) {
            $binaryMsg = new Frame($this->rewriteResponseBody($chunk), true, Frame::OP_BINARY);
            $proxyConnection->send($binaryMsg);
        });
    }

    protected function logResponse(string $rawResponse)
    {
        $this->logger->logResponse($this->request, $rawResponse);
    }

    protected function parseRequest($data): Request
    {
        return Request::fromString($data);
    }

    protected function rewriteResponseHeaders(ResponseInterface $response)
    {
        if (! $response->hasHeader('Location')) {
            return $response;
        }

        $location = $response->getHeaderLine('Location');

        if (! strstr($location, $this->connectionData->host)) {
            return $response;
        }

        $location = str_replace(
            $this->connectionData->host,
            $this->configuration->getUrl($this->connectionData->subdomain),
            $location
        );

        return $response->withHeader('Location', $location);
    }

    protected function rewriteResponseBody($chunk)
    {
        if (! is_string($chunk)) {
            return $chunk;
        }

        $httpProtocol = $this->configuration->port() === 443 ? 'https' : 'http';

        $rewrittenHost = "{$httpProtocol}://{$this->configuration->getUrl($this->connectionData->subdomain)}";

        return str_replace(
            [
                $this->getOriginalHost(),
                trim(json_encode($this->getOriginalHost()), '"'),
            ],
            [
                $rewrittenHost,
                trim(json_encode($rewrittenHost), '"'),
            ],
            $chunk
        );
    }

    protected function getOriginalHost()
    {
        $host = $this->connectionData->host;
        $port = parse_url($host, \PHP_URL_PORT);
        $protocol = 'http';

        if ($port === 443) {
            $protocol = 'https';
        }

        $host = parse_url($host, \PHP_URL_HOST);

        if ($port === 80 || $port === 443) {
            return "{$protocol}://{$host}";
        }

        return "{$protocol}://{$host}:{$port}";
    }

    protected function rewriteRequestHeaders($headers)
    {
        // Remove response compression to allow for response body rewriting
        unset($headers['accept-encoding']);

        return $headers;
    }
}
