<?php
/**
 * POST /rentadriver/api/index — the signed endpoint RentADriver calls (see Lib/Core.php). JSON in, JSON out.
 *
 * @license GPL-2.0-or-later
 */
namespace RentADriver\Delivery\Controller\Api;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use RentADriver\Delivery\Model\Bridge;

class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var JsonFactory
     */
    private $json;

    /**
     * @var Bridge
     */
    private $bridge;

    /**
     * @param RequestInterface $request
     * @param JsonFactory $json
     * @param Bridge $bridge
     */
    public function __construct(RequestInterface $request, JsonFactory $json, Bridge $bridge)
    {
        $this->request = $request;
        $this->json = $json;
        $this->bridge = $bridge;
    }

    /**
     * Verify the signed request and answer it.
     *
     * @return Json
     */
    public function execute()
    {
        $request = $this->request;
        $header = function ($name) use ($request) {
            $v = $request->getHeader($name);
            return $v === false ? null : (string) $v;
        };
        list($status, $body) = $this->bridge->core()->handle((string) $request->getContent(), $header);
        return $this->json->create()
            ->setHttpResponseCode($status)
            ->setHeader('Cache-Control', 'no-store', true)
            ->setData($body);
    }

    /**
     * Server-to-server and signed with the shared secret instead of a form key.
     *
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * The signature replaces the form key check.
     *
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
