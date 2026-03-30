<?php declare(strict_types=1);

namespace Log\Log\Writer;

use Doctrine\DBAL\Connection;
use Laminas\Log\Exception;
use Laminas\Log\Formatter\Db as DbFormatter;
use Laminas\Log\Writer\AbstractWriter;
use Traversable;

/**
 * Doctrine DBAL-based log writer.
 *
 * Replaces Laminas\Log\Writer\Db to avoid laminas-db dependency.
 * Maintains full compatibility with the original Db writer interface.
 */
class Doctrine extends AbstractWriter
{
    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $db;

    /**
     * @var string
     */
    protected $tableName;

    /**
     * @var null|array
     */
    protected $columnMap;

    /**
     * @var string
     */
    protected $separator = '_';

    public function __construct($db, $tableName = null, ?array $columnMap = null, $separator = null)
    {
        if ($db instanceof Traversable) {
            $db = iterator_to_array($db);
        }

        if (is_array($db)) {
            parent::__construct($db);
            $separator = $db['separator'] ?? null;
            $columnMap = $db['column'] ?? null;
            $tableName = $db['table'] ?? null;
            $db = $db['db'] ?? null;
        }

        if (!$db instanceof Connection) {
            throw new Exception\InvalidArgumentException('You must pass a valid Doctrine\DBAL\Connection');
        }

        $tableName = (string) $tableName;
        if ($tableName === '') {
            throw new Exception\InvalidArgumentException(
                'You must specify a table name. Either directly in the constructor, or via options'
            );
        }

        $this->db = $db;
        $this->tableName = $tableName;
        $this->columnMap = $columnMap;

        if (!empty($separator)) {
            $this->separator = $separator;
        }

        if (!$this->hasFormatter()) {
            $this->setFormatter(new DbFormatter());
        }
    }

    /**
     * Set field mapping.
     *
     * Alias for setting columnMap after construction.
     */
    public function setFieldMapping(array $mapping): self
    {
        $this->columnMap = $mapping;
        return $this;
    }

    public function shutdown()
    {
        $this->db = null;
    }

    protected function doWrite(array $event)
    {
        if (null === $this->db) {
            throw new Exception\RuntimeException('Database connection is null');
        }

        $event = $this->formatter->format($event);

        // Transform the event array into fields.
        if (null === $this->columnMap) {
            $dataToInsert = $this->eventIntoColumn($event);
        } else {
            $dataToInsert = $this->mapEventIntoColumn($event, $this->columnMap);
        }

        try {
            $this->db->insert($this->tableName, $dataToInsert);
        } catch (\Throwable $e) {
            throw new Exception\RuntimeException(
                'Unable to insert log entry into database: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Map event into column using the $columnMap array.
     */
    protected function mapEventIntoColumn(array $event, ?array $columnMap = null)
    {
        if (empty($event)) {
            return [];
        }

        $data = [];
        foreach ($event as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $key => $subvalue) {
                    if (isset($columnMap[$name][$key])) {
                        if (is_scalar($subvalue)) {
                            $data[$columnMap[$name][$key]] = $subvalue;
                            continue;
                        }

                        $data[$columnMap[$name][$key]] = var_export($subvalue, true);
                    }
                }
            } elseif (isset($columnMap[$name])) {
                $data[$columnMap[$name]] = $value;
            }
        }
        return $data;
    }

    /**
     * Transform event into column for the db table.
     */
    protected function eventIntoColumn(array $event): array
    {
        if (empty($event)) {
            return [];
        }

        $data = [];
        foreach ($event as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $key => $subvalue) {
                    if (is_scalar($subvalue)) {
                        $data[$name . $this->separator . $key] = $subvalue;
                        continue;
                    }

                    $data[$name . $this->separator . $key] = var_export($subvalue, true);
                }
            } else {
                $data[$name] = $value;
            }
        }
        return $data;
    }
}
