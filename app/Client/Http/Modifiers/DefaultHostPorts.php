<?php

namespace App\Client\Http\Modifiers;

use App\Client\Configuration;
use Psr\Http\Message\RequestInterface;
use Ratchet\Client\WebSocket;

class DefaultHostPorts
{
    /** @var Configuration */
    protected $configuration;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    public function handle(RequestInterface $request, ?WebSocket $proxyConnection): ?RequestInterface
    {
        $hostHeader  = $request->getHeaderLine('Host');

        if (substr($hostHeader, -4) === ':443') { // Some applications don't like standard ports in their host headers
            $request = $request->withHeader('Host', substr($hostHeader, 0, -4));
        }

        return $request;
    }
}
