<?php
declare(strict_types=1);

/**
 * Saves values into the measurements table.
 * Uses an "upsert": running an import twice updates rows instead of duplicating them.
 */
final class MeasurementWriter
{
    private array $paramIds = [];
    private array $sourceIds = [];
    private PDOStatement $upsert;

    public const DATA_TYPES = ['measured', 'satellite', 'modelled', 'calculated', 'interpretation'];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        foreach ($pdo->query('SELECT id, code FROM parameters') as $r)   { $this->paramIds[$r['code']] = (int)$r['id']; }
        foreach ($pdo->query('SELECT id, code FROM data_sources') as $r) { $this->sourceIds[$r['code']] = (int)$r['id']; }
        $this->upsert = $pdo->prepare(
            'INSERT INTO measurements (location_id, parameter_id, source_id, month, value, data_type, period, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), data_type = VALUES(data_type),
                                     period = VALUES(period), note = VALUES(note), retrieved_at = CURRENT_TIMESTAMP'
        );
    }

    public function hasParameter(string $code): bool { return isset($this->paramIds[$code]); }
    public function hasSource(string $code): bool    { return isset($this->sourceIds[$code]); }

    public function save(int $locationId, string $param, string $source, int $month, ?float $value,
                         string $dataType, ?string $period = null, ?string $note = null): void
    {
        if (!isset($this->paramIds[$param]))   { throw new InvalidArgumentException("Unknown parameter '$param'"); }
        if (!isset($this->sourceIds[$source])) { throw new InvalidArgumentException("Unknown source '$source'"); }
        if (!in_array($dataType, self::DATA_TYPES, true)) { throw new InvalidArgumentException("Unknown data type '$dataType'"); }
        if ($month < 0 || $month > 12) { throw new InvalidArgumentException('Month must be 0-12'); }
        $this->upsert->execute([
            $locationId, $this->paramIds[$param], $this->sourceIds[$source], $month, $value, $dataType,
            $period === null ? null : mb_substr($period, 0, 120),
            $note === null ? null : mb_substr($note, 0, 255),
        ]);
    }

    public function log(string $source, string $status, int $rows, string $message): void
    {
        $st = $this->pdo->prepare('INSERT INTO import_logs (source_id, status, rows_saved, message) VALUES (?, ?, ?, ?)');
        $st->execute([$this->sourceIds[$source], $status, $rows, $message]);
    }
}
