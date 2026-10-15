<?php
/**
 * DomainRouter: heuristic + analyzer-aware classifier that maps a
 * free-form user query to one of the canonical domain preset slugs.
 *
 * The classifier is intentionally conservative — it only flips the
 * preset away from `default` when there is unambiguous evidence the
 * query is mathematical or physical. The signals are:
 *
 *   * LaTeX-style math delimiters (`\(…\)`, `\[…\]`, `$…$`, `$$…$$`).
 *   * Greek letters (commonly used in physics/math).
 *   * Domain-specific keywords (English + Russian, multilingual user base).
 *   * Operator/symbol density (`∫ ∑ ∇ ∂` and friends).
 *   * Explicit unit tokens (`m/s²`, `kg`, `Hz`) — strong physics signal.
 *
 * Returns a structured classification object the pipeline persists
 * into the session metadata and emits over SSE so the chat UI can
 * render the "auto-routed to Mathematics" banner.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-13
 */
declare(strict_types=1);

namespace App\DomainPresets;

final class DomainRouter
{
    private const MATH_KEYWORDS = [
        // EN
        'integral','derivative','derivate','equation','equations','solve','prove','theorem','lemma',
        'matrix','matrices','vector','polynomial','factorial','binomial','probability','combinatorics',
        'algebra','geometry','trigonometry','calculus','limit','series','convergence','divergence',
        'eigen','eigenvalue','eigenvector','isomorphism','homomorphism','differentiate','integrate',
        'gcd','lcm','prime','primes','modulo','congruence','symmetric','antisymmetric','tensor',
        'gradient','divergence','curl','laplacian','exponent','logarithm','factor','factorize',
        // RU
        'интеграл','производная','уравнение','уравнения','решить','доказать','теорема','лемма',
        'матрица','матрицы','вектор','многочлен','факториал','биномиальный','вероятность',
        'комбинаторика','алгебра','геометрия','тригонометрия','исчисление','предел','ряд',
        'сходимость','расходимость','собственное','собственный','градиент','дивергенция','ротор',
        'логарифм','экспонента','модуль','степень','неравенство','координаты',
    ];

    private const PHYSICS_KEYWORDS = [
        // EN
        'force','acceleration','velocity','momentum','energy','potential','kinetic','newton',
        'gravity','gravitational','mass','weight','friction','torque','inertia','spring','pendulum',
        'oscillation','harmonic','wave','frequency','amplitude','wavelength','phase','resonance',
        'thermodynamics','entropy','enthalpy','isothermal','adiabatic','carnot','heat','temperature',
        'electric','electron','proton','neutron','charge','voltage','current','resistance','capacitance',
        'inductance','magnetic','flux','field','quantum','wavefunction','schrodinger','hamiltonian',
        'lagrangian','relativity','lorentz','tensor','spacetime','photon','planck','boltzmann',
        // RU
        'сила','ускорение','скорость','импульс','энергия','потенциал','кинетическая','ньютон',
        'гравитация','масса','вес','трение','момент','инерция','пружина','маятник','колебание',
        'гармонический','волна','частота','амплитуда','длина волны','фаза','резонанс',
        'термодинамика','энтропия','адиабатический','изотермический','карно','тепло','температура',
        'электрический','электрон','протон','нейтрон','заряд','напряжение','ток','сопротивление',
        'квантовая','волновая функция','шрёдингер','гамильтониан','лагранжиан','теория относительности',
        'фотон','планк','больцман',
    ];

    private const MATH_SYMBOLS  = ['∫','∑','∏','∇','∂','√','≤','≥','≠','≈','∞','⊕','⊗','⊆','⊇','∈','∉','∀','∃'];
    private const PHYS_UNIT_RE  = '/\b(?:m\/s\^?2|m\/s|km\/h|kg|kHz|MHz|GHz|Hz|J|kJ|W|kW|MW|N|Pa|kPa|MPa|V|kV|A|mA|Ω|ohm|Ohm|T|Wb|eV|MeV|GeV|TeV)\b/u';
    private const LATEX_RE      = '/(\\\\\(|\\\\\[|\$\$|\\\\frac|\\\\sum|\\\\int|\\\\sqrt|\\\\alpha|\\\\beta|\\\\gamma|\\\\delta|\\\\theta|\\\\lambda|\\\\mu|\\\\pi|\\\\sigma|\\\\omega|\\\\phi|\\\\nabla|\\\\partial|\\\\infty|\\\\cdot)/u';
    private const GREEK_RE      = '/[\x{0370}-\x{03FF}]/u';

    /**
     * Classify the query. Returns a structured envelope so callers can
     * surface the analyzer's reasoning to the user verbatim.
     *
     * @return array{
     *     domain: 'default'|'mathematics'|'physics',
     *     confidence: float,
     *     scores: array<string,float>,
     *     signals: array<int,string>,
     *     formula_expected: bool,
     * }
     */
    public function classify(string $query): array
    {
        $signals = [];
        $mathScore = 0.0;
        $physicsScore = 0.0;

        $lower = mb_strtolower($query);
        $latex = preg_match(self::LATEX_RE, $query) === 1;
        if ($latex) {
            $mathScore += 0.45;
            $physicsScore += 0.30;
            $signals[] = 'latex_delimiters_or_macros';
        }

        if (preg_match(self::GREEK_RE, $query) === 1) {
            $mathScore += 0.15;
            $physicsScore += 0.20;
            $signals[] = 'greek_letters';
        }

        $symbolHits = 0;
        foreach (self::MATH_SYMBOLS as $sym) {
            if (mb_strpos($query, $sym) !== false) {
                $symbolHits++;
            }
        }
        if ($symbolHits > 0) {
            $mathScore += min(0.40, 0.12 * $symbolHits);
            $signals[] = 'math_symbols:' . $symbolHits;
        }

        $unitMatches = 0;
        if (preg_match_all(self::PHYS_UNIT_RE, $query, $m) > 0) {
            $unitMatches = count($m[0]);
            $physicsScore += min(0.40, 0.15 * $unitMatches);
            $signals[] = 'physics_units:' . $unitMatches;
        }

        $mathHits = 0;
        foreach (self::MATH_KEYWORDS as $kw) {
            if (mb_strpos($lower, $kw) !== false) {
                $mathHits++;
            }
        }
        if ($mathHits > 0) {
            // First keyword carries a higher weight (sufficient signal on
            // its own when paired with formula context), additional
            // keywords add a smaller marginal contribution. Caps at 0.60.
            $mathScore += min(0.60, 0.40 + 0.08 * max(0, $mathHits - 1));
            $signals[] = 'math_keywords:' . $mathHits;
        }

        $physHits = 0;
        foreach (self::PHYSICS_KEYWORDS as $kw) {
            if (mb_strpos($lower, $kw) !== false) {
                $physHits++;
            }
        }
        if ($physHits > 0) {
            $physicsScore += min(0.65, 0.40 + 0.08 * max(0, $physHits - 1));
            $signals[] = 'physics_keywords:' . $physHits;
        }

        // Numerical density — many queries with a lot of digits and `=`
        // tend to be math/physics word problems.
        $digits = preg_match_all('/\d+(?:\.\d+)?/', $query) ?? 0;
        if ($digits >= 3 && preg_match('/=/', $query) === 1) {
            $mathScore += 0.10;
            $signals[] = 'numeric_with_equals';
        }

        $mathScore    = (float)min(1.0, $mathScore);
        $physicsScore = (float)min(1.0, $physicsScore);
        $defaultScore = max(0.0, 1.0 - max($mathScore, $physicsScore));

        $domain = 'default';
        $confidence = $defaultScore;
        $threshold = 0.35;
        if ($physicsScore >= $threshold && $physicsScore >= $mathScore) {
            $domain = 'physics';
            $confidence = $physicsScore;
        } elseif ($mathScore >= $threshold) {
            $domain = 'mathematics';
            $confidence = $mathScore;
        }

        return [
            'domain'           => $domain,
            'confidence'       => $confidence,
            'scores'           => [
                'default'     => round($defaultScore, 3),
                'mathematics' => round($mathScore, 3),
                'physics'     => round($physicsScore, 3),
            ],
            'signals'          => $signals,
            'formula_expected' => $latex || $symbolHits > 0 || $mathHits > 0 || $physHits > 0,
        ];
    }
}
