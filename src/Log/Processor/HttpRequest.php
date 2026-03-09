<?php declare(strict_types=1);

namespace Log\Log\Processor;

use Laminas\Http\PhpEnvironment\Request;
use Laminas\Log\Processor\ProcessorInterface;

/**
 * Add HTTP request context (URL, IP, referer, user agent) to log
 * entries. Skipped automatically in CLI context (jobs, scripts).
 */
class HttpRequest implements ProcessorInterface
{
    /**
     * @var ?array
     */
    protected $httpContext;

    public function __construct(?Request $request)
    {
        if ($request) {
            $referer = $request->getHeader('Referer');
            $userAgent = $request->getHeader('User-Agent');
            $url = $request->getRequestUri();
            $this->httpContext = array_filter([
                'url' => $url && $url !== '/' ? $url : null,
                'ip' => $request->getServer('REMOTE_ADDR'),
                'referer' => $referer
                    ? $referer->getFieldValue() : null,
                'userAgent' => $userAgent
                    ? $userAgent->getFieldValue() : null,
            ]);
        }
    }

    public function process(array $event)
    {
        if (!$this->httpContext) {
            return $event;
        }

        if (!isset($event['extra'])) {
            $event['extra'] = [];
        }

        $event['extra']['httpRequest'] = $this->httpContext;

        return $event;
    }
}
