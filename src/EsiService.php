<?php
declare(strict_types=1);

/**
 * Joins the database (Repository) with the maths (Esi).
 */
final class EsiService
{
    public const DISCLAIMER =
        'Environmental Similarity Index for specific measurable parameters. '
        . 'It is not a validated habitat suitability score.';

    private Repository $repo;

    public function __construct(Repository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * @param array<string,int> $weights  overrides of the default weights (0-5)
     * @param int $month 0 = annual, 1-12 = Earth monthly climatology (the reference stays annual)
     */
    public function compute(array $earth, array $ref, array $weights = [], int $month = 0, ?array $params = null): array
    {
        return $this->computeFromValues($earth['slug'], $this->repo->bestValues($earth['id'], $month), $ref, $weights, $month, $params);
    }

    /**
     * Same as compute(), for an Earth place whose values are not in the database
     * (a point the visitor clicked). $eValues has the shape of Repository::bestValues().
     */
    public function computeFromValues(string $earthSlug, array $eValues, array $ref, array $weights = [], int $month = 0, ?array $params = null): array
    {
        $params  = $params ?? $this->repo->parameters();
        $rValues = $this->repo->bestValues($ref['id'], 0);

        $items = [];
        foreach ($params as $p) {
            if ($p['esi_method'] === 'none') {
                continue;
            }
            $e = $eValues[$p['code']] ?? null;
            $r = $rValues[$p['code']] ?? null;
            $s = Esi::similarity($p['esi_method'], $e['value'] ?? null, $r['value'] ?? null);
            $items[] = [
                'code'       => $p['code'],
                'name'       => $p['name'],
                'unit'       => $p['unit'],
                'method'     => $p['esi_method'],
                'weight'     => $weights[$p['code']] ?? $p['default_weight'],
                'similarity' => $s === null ? null : round($s, 4),
                'status'     => $s === null ? 'excluded: missing value' : 'included',
                'earth'      => $this->publicValue($e),
                'reference'  => $this->publicValue($r),
            ];
        }
        $total = Esi::combine($items);

        return [
            'earth'        => $earthSlug,
            'reference'    => $ref['slug'],
            'month'        => $month,
            'esi'          => $total['esi'],
            'completeness' => ['available' => $total['available'], 'total' => $total['total']],
            // An index built from one parameter (or under half of them) must not look like a result.
            'reliable'     => $total['available'] >= 2 && $total['available'] * 2 >= $total['total'],
            'parameters'   => $items,
            'disclaimer'   => self::DISCLAIMER,
        ];
    }

    public function publicValue(?array $v): ?array
    {
        if ($v === null) {
            return null;
        }
        return [
            'value'           => $v['value'],
            'data_type'       => $v['data_type'],
            'source_code'     => $v['source_code'],
            'source_name'     => $v['source_name'],
            'source_url'      => $v['source_url'],
            'period'          => $v['period'],
            'note'            => $v['note'],
            'annual_fallback' => $v['annual_fallback'],
        ];
    }
}
