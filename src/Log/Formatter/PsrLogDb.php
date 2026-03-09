<?php declare(strict_types=1);

namespace Log\Log\Formatter;

use Common\Log\Formatter\PsrLogAwareTrait;
use DateTime;
use Laminas\Log\Formatter\Base;

class PsrLogDb extends Base
{
    use PsrLogAwareTrait;

    protected $dateTimeFormat = 'Y-m-d H:i:s';

    public function __construct($dateTimeFormat = null)
    {
        // The date time format cannot be changed for this table in database.
        // TODO Fix the construct/method for date time format to log in db.
    }

    public function setDateTimeFormat($dateTimeFormat)
    {
        // The date time format cannot be changed for this table in database.
        return $this;
    }

    /**
     * Formats data to be written by the writer.
     *
     * To be used with the processors UserId, JobId and HttpRequest.
     *
     * @param array $event event data
     * @return array
     */
    public function format($event)
    {
        // Extract HTTP request context set by the HttpRequest
        // processor before PSR-3 normalization, which would treat
        // it as leftover extra data and double-encode it.
        $httpContext = $event['extra']['httpRequest'] ?? [];
        unset($event['extra']['httpRequest']);

        $event = parent::format($event);
        $event = $this->normalizeLogContext($event);
        $event = $this->normalizeLogDateTimeFormat($event);

        if (!empty($event['extra']['context']['extra'])) {
            $event['extra']['context']['extra'] = json_encode($event['extra']['context']['extra'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // Merge HTTP context as top-level keys in context.
        if ($httpContext) {
            $context = $event['extra']['context'] ?? [];
            $event['extra']['context'] = $httpContext + $context;
        }

        if (empty($event['extra']['context'])) {
            $event['extra']['context'] = '[]';
        } else {
            $event['extra']['context'] = json_encode($event['extra']['context'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $event;
    }

    /**
     * Formats the date time for mysql.
     *
     * @see \Laminas\Log\Formatter\Db::format()
     *
     * @param array $event
     * @return array
     */
    protected function normalizeLogDateTimeFormat($event)
    {
        $format = $this->getDateTimeFormat();
        array_walk_recursive($event, function (&$value) use ($format): void {
            if ($value instanceof DateTime) {
                $value = $value->format($format);
            }
        });

        return $event;
    }
}
