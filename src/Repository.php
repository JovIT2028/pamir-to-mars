<?php
declare(strict_types=1);

/**
 * All SQL lives here. API files never write SQL themselves.
 */
final class Repository
{
    /** When two sources give the same parameter, the more direct one wins. */
    private const TYPE_PRIORITY = [
        'measured' => 1, 'satellite' => 2, 'modelled' => 3, 'calculated' => 4, 'interpretation' => 5,
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function locations(?string $kind = null, ?string $body = null): array
    {
        $sql = 'SELECT id, slug, name, body, kind, latitude, longitude, elevation_m, elevation_ref, is_verified, description
                FROM locations WHERE 1=1';
        $args = [];
        if ($kind !== null) { $sql .= ' AND kind = ?'; $args[] = $kind; }
        if ($body !== null) { $sql .= ' AND body = ?'; $args[] = $body; }
        $sql .= ' ORDER BY kind, id';
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return array_map([$this, 'castLocation'], $st->fetchAll());
    }

    public function location(string $slug): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT id, slug, name, body, kind, latitude, longitude, elevation_m, elevation_ref, is_verified, description
             FROM locations WHERE slug = ?'
        );
        $st->execute([$slug]);
        $row = $st->fetch();
        return $row ? $this->castLocation($row) : null;
    }

    public function parameters(): array
    {
        $rows = $this->pdo->query(
            'SELECT id, code, name, unit, esi_method, default_weight, explanation FROM parameters ORDER BY sort_order, id'
        )->fetchAll();
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['default_weight'] = (int)$r['default_weight'];
        }
        return $rows;
    }

    public function sources(): array
    {
        return $this->pdo->query(
            'SELECT s.code, s.name, s.provider, s.url, s.access_method, s.resolution, s.limitations,
                    (SELECT COUNT(*) FROM measurements m WHERE m.source_id = s.id AND m.value IS NOT NULL) AS values_stored
             FROM data_sources s ORDER BY s.id'
        )->fetchAll();
    }

    /**
     * Best available value of every parameter for one location.
     * $month 0 = annual. For a month 1-12 the monthly value is preferred;
     * if there is none, the annual value is used and marked annual_fallback.
     *
     * @return array<string,array> keyed by parameter code
     */
    public function bestValues(int $locationId, int $month = 0): array
    {
        $st = $this->pdo->prepare(
            'SELECT p.code, m.month, m.value, m.data_type, m.period, m.note,
                    s.code AS source_code, s.name AS source_name, s.url AS source_url
             FROM measurements m
             JOIN parameters p   ON p.id = m.parameter_id
             JOIN data_sources s ON s.id = m.source_id
             WHERE m.location_id = ? AND m.value IS NOT NULL AND m.month IN (0, ?)'
        );
        $st->execute([$locationId, $month]);

        $best = [];
        foreach ($st->fetchAll() as $row) {
            $row['month'] = (int)$row['month'];
            $row['value'] = (float)$row['value'];
            // Lower rank = better. Exact month beats annual; then the more direct data type.
            $rank = ($row['month'] === $month ? 0 : 10) + (self::TYPE_PRIORITY[$row['data_type']] ?? 9);
            if (!isset($best[$row['code']]) || $rank < $best[$row['code']]['_rank']) {
                $row['_rank'] = $rank;
                $row['annual_fallback'] = ($month !== 0 && $row['month'] === 0);
                $best[$row['code']] = $row;
            }
        }
        foreach ($best as &$b) {
            unset($b['_rank']);
        }
        return $best;
    }

    private function castLocation(array $r): array
    {
        $r['id'] = (int)$r['id'];
        $r['latitude'] = (float)$r['latitude'];
        $r['longitude'] = (float)$r['longitude'];
        $r['elevation_m'] = $r['elevation_m'] === null ? null : (float)$r['elevation_m'];
        $r['is_verified'] = (bool)$r['is_verified'];
        return $r;
    }
}
