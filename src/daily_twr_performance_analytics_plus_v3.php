<?php
/**
 * PortfolioSight - Daily Time-Weighted Return Calculator
 *
 * Inputs
 *   1. Current brokerage positions CSV (the ending holdings anchor)
 *   2. Brokerage transaction-history CSV
 *   3. PostgreSQL prices_stocks table containing RAW, unadjusted closes
 *
 * Core methodology
 *   - Reconstructs the opening ledger by reversing every transaction from the
 *     current positions and cash balance.
 *   - Replays the ledger forward on market days.
 *   - Buys, sells, dividends, interest, fees, mergers, reinvestments, and stock
 *     splits are internal portfolio activity.
 *   - Only the configured external-flow actions (default: MoneyLink Transfer)
 *     are removed from return calculations.
 *   - Stock split share changes come from the transaction CSV. Historical
 *     prices are never split-adjusted by this page.
 *
 * Expected default PostgreSQL schema
 *   prices_stocks(date DATE, ticker TEXT, close NUMERIC)
 *
 * Environment variables (recommended)
 *   PORTFOLIOSIGHT_DB_DSN
 *   PORTFOLIOSIGHT_DB_USER
 *   PORTFOLIOSIGHT_DB_PASSWORD
 *   PORTFOLIOSIGHT_DATA_DIR   Folder holding the optional default CSVs. Keep it
 *                             outside the web root; defaults to this file's
 *                             parent directory.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

// -----------------------------------------------------------------------------
// Configuration
// -----------------------------------------------------------------------------

const DEFAULT_POSITIONS_FILE = 'Designated Bene Individual-Positions-2026-08-05.csv';
const DEFAULT_TRANSACTIONS_FILE = 'Designated_Bene_Individual_Transactions_20260805.csv';
const DEFAULT_BENCHMARK = 'SPTM';
const MAX_UPLOAD_BYTES = 15_000_000;
const OPTION_CONTRACT_MULTIPLIER = 100.0;
const EPSILON = 0.000001;

$dbDsn = getenv('PORTFOLIOSIGHT_DB_DSN') ?: 'pgsql:host=localhost;port=5432;dbname=admin_portfolio';
$dsnContainsUser = preg_match('/(?:^|;)user=/i', $dbDsn) === 1;
$dbUserEnv = getenv('PORTFOLIOSIGHT_DB_USER');
$dbPasswordEnv = getenv('PORTFOLIOSIGHT_DB_PASSWORD');
$dbUser = $dbUserEnv !== false ? $dbUserEnv : ($dsnContainsUser ? '' : 'admin_main');
$dbPassword = $dbPasswordEnv !== false ? $dbPasswordEnv : '';

// Brokerage exports contain account data, so the default CSVs must not live in
// a web-served directory where they could be downloaded by URL.
$dataDir = getenv('PORTFOLIOSIGHT_DATA_DIR') ?: dirname(__DIR__);

// Reuse PortfolioSight authentication when this page lives inside the main app.
// The page still works standalone; in that case a persistent browser ID is used.
$authFile = __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';
if (is_file($authFile)) {
    require_once $authFile;
}

// -----------------------------------------------------------------------------
// Small helpers
// -----------------------------------------------------------------------------

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ensureSessionStarted(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        session_start();
    }
}

function currentUserStorageId(): string
{
    // Prefer the site's authenticated Google/user identity when available.
    if (function_exists('getGoogleUserId')) {
        try {
            $id = trim((string)getGoogleUserId());
            if ($id !== '') {
                return 'user:' . $id;
            }
        } catch (Throwable) {
            // Fall through to the browser-scoped identifier below.
        }
    }

    ensureSessionStarted();
    if (isset($_SESSION['user_id']) && trim((string)$_SESSION['user_id']) !== '') {
        return 'session-user:' . trim((string)$_SESSION['user_id']);
    }

    $cookieName = 'ps_saved_portfolio_user';
    $existing = preg_replace('/[^a-f0-9]/i', '', (string)($_COOKIE[$cookieName] ?? '')) ?? '';
    if (strlen($existing) >= 32) {
        return 'browser:' . strtolower($existing);
    }

    $token = bin2hex(random_bytes(24));
    if (!headers_sent()) {
        setcookie($cookieName, $token, [
            'expires' => time() + (86400 * 730),
            'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    return 'browser:' . $token;
}

function csrfToken(): string
{
    ensureSessionStarted();
    if (!isset($_SESSION['ps_twr_csrf']) || !is_string($_SESSION['ps_twr_csrf'])) {
        $_SESSION['ps_twr_csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['ps_twr_csrf'];
}

function userFacingError(Throwable $exception): string
{
    // RuntimeExceptions carry messages written for the user. Anything else
    // (PDO, JSON, ...) may expose connection details or SQL, so log it instead.
    if ($exception instanceof RuntimeException && !$exception instanceof PDOException) {
        return $exception->getMessage();
    }
    error_log('PortfolioSight TWR: ' . $exception);
    return 'An unexpected server error occurred. Details were written to the server log.';
}

function requireValidCsrf(): void
{
    $expected = csrfToken();
    $actual = (string)($_POST['csrf_token'] ?? '');
    if ($actual === '' || !hash_equals($expected, $actual)) {
        throw new RuntimeException('The form expired or could not be verified. Refresh the page and try again.');
    }
}

function normalizeHeader(string $header): string
{
    $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
    return trim($header);
}

function parseNumber(?string $value): float
{
    $value = trim((string)$value);
    if ($value === '' || $value === '--' || strtoupper($value) === 'N/A') {
        return 0.0;
    }

    $negative = str_starts_with($value, '(') && str_ends_with($value, ')');
    $clean = str_replace([',', '$', '%', '(', ')'], '', $value);
    $clean = trim($clean);

    if ($clean === '' || !is_numeric($clean)) {
        return 0.0;
    }

    $number = (float)$clean;
    return $negative ? -$number : $number;
}

function parseBrokerageDate(string $value): ?DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $parseOne = static function (string $candidate): ?DateTimeImmutable {
        $candidate = trim($candidate);
        foreach (['!n/j/y', '!m/d/y', '!n/j/Y', '!m/d/Y', '!Y-m-d'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $candidate);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date;
            }
        }
        return null;
    };

    // Schwab sometimes exports rows like:
    //   03/19/2026 as of 03/18/2026
    // Use the "as of" date because it is the effective transaction date.
    if (preg_match('/^(.+?)\s+as\s+of\s+(.+)$/i', $value, $matches)) {
        $asOfDate = $parseOne($matches[2]);
        if ($asOfDate !== null) {
            return $asOfDate;
        }

        $postedDate = $parseOne($matches[1]);
        if ($postedDate !== null) {
            return $postedDate;
        }
    }

    return $parseOne($value);
}

function readCsvAssociative(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('CSV file is missing or cannot be read: ' . basename($path));
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Unable to open CSV file: ' . basename($path));
    }

    // Schwab's positions export format changed in September 2026 and can now
    // include an account-title line and a blank line before the actual header.
    // Scan until we find either a positions header or a transactions header.
    $headers = null;
    while (($candidate = fgetcsv($handle, null, ',', '"', '')) !== false) {
        if ($candidate === [null] || $candidate === []) {
            continue;
        }

        $candidate = array_map(static fn($header) => normalizeHeader((string)$header), $candidate);
        $isPositionsHeader = in_array('Symbol', $candidate, true)
            && in_array('Qty (Quantity)', $candidate, true)
            && in_array('Mkt Val (Market Value)', $candidate, true);
        $isTransactionsHeader = in_array('Date', $candidate, true)
            && in_array('Action', $candidate, true)
            && in_array('Amount', $candidate, true);

        if ($isPositionsHeader || $isTransactionsHeader) {
            $headers = $candidate;
            break;
        }
    }

    if ($headers === null) {
        fclose($handle);
        throw new RuntimeException('Could not find a recognized CSV header in: ' . basename($path));
    }

    // Schwab's newer positions CSV may include a trailing comma / blank column.
    while ($headers !== [] && end($headers) === '') {
        array_pop($headers);
    }

    if ($headers === []) {
        fclose($handle);
        throw new RuntimeException('CSV header is empty: ' . basename($path));
    }

    $rows = [];

    while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
        if ($values === [null] || $values === []) {
            continue;
        }

        $values = array_pad($values, count($headers), '');
        $values = array_slice($values, 0, count($headers));
        $row = array_combine($headers, $values);
        if ($row !== false) {
            $rows[] = $row;
        }
    }

    fclose($handle);
    return $rows;
}

function inferAsOfDate(string $positionsFilename, string $transactionsFilename): ?string
{
    if (preg_match('/(20\d{2})-(\d{2})-(\d{2})/', $positionsFilename, $matches)) {
        return sprintf('%s-%s-%s', $matches[1], $matches[2], $matches[3]);
    }

    if (preg_match('/(20\d{2})(\d{2})(\d{2})/', $transactionsFilename, $matches)) {
        return sprintf('%s-%s-%s', $matches[1], $matches[2], $matches[3]);
    }

    return null;
}

function uploadedOrDefaultPath(string $fieldName, string $defaultFilename, string $dataDir): ?string
{
    if (isset($_FILES[$fieldName]) && is_array($_FILES[$fieldName])) {
        $upload = $_FILES[$fieldName];
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_OK) {
            $size = (int)($upload['size'] ?? 0);
            if ($size <= 0 || $size > MAX_UPLOAD_BYTES) {
                throw new RuntimeException('Uploaded CSV is empty or exceeds the 15 MB limit.');
            }

            $tmp = (string)($upload['tmp_name'] ?? '');
            if (!is_uploaded_file($tmp)) {
                throw new RuntimeException('The uploaded CSV could not be verified.');
            }

            return $tmp;
        }

        if ($error !== UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('CSV upload failed with error code ' . $error . '.');
        }
    }

    $defaultPath = $dataDir . DIRECTORY_SEPARATOR . $defaultFilename;
    return is_file($defaultPath) ? $defaultPath : null;
}

function uploadedOrDefaultName(string $fieldName, string $defaultFilename): string
{
    if (isset($_FILES[$fieldName]['name']) && trim((string)$_FILES[$fieldName]['name']) !== '') {
        return basename((string)$_FILES[$fieldName]['name']);
    }
    return $defaultFilename;
}

function normalizeAction(string $action): string
{
    return strtolower(trim(preg_replace('/\s+/', ' ', $action) ?? $action));
}

function normalizeTicker(string $ticker): string
{
    return strtoupper(trim($ticker));
}

function looksLikeOptionSymbol(string $symbol): bool
{
    return (bool)preg_match('/\b\d{2}\/\d{2}\/\d{4}\b.*\b[CP]\b/i', $symbol)
        || (bool)preg_match('/\b(CALL|PUT)\b/i', $symbol);
}

function transactionShareDelta(string $action, float $quantity): float
{
    $action = normalizeAction($action);

    if (in_array($action, ['buy', 'reinvest shares', 'buy to open'], true)) {
        return abs($quantity);
    }

    if (in_array($action, ['sell', 'sell to close'], true)) {
        return -abs($quantity);
    }

    // Brokerage exports report stock split additions and merger exchanges as
    // signed share changes. Preserve that sign exactly.
    if (in_array($action, ['stock split', 'stock merger'], true)) {
        return $quantity;
    }

    return 0.0;
}

function quoteIdentifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
        throw new RuntimeException('Unsafe database identifier encountered.');
    }
    return '"' . $identifier . '"';
}

function formatMoney(float $value, int $decimals = 2): string
{
    $prefix = $value < 0 ? '-' : '';
    return $prefix . '$' . number_format(abs($value), $decimals);
}

function formatPercent(?float $value, int $decimals = 2): string
{
    if ($value === null || !is_finite($value)) {
        return '—';
    }
    return number_format($value * 100, $decimals) . '%';
}

function sampleStandardDeviation(array $values): ?float
{
    $values = array_values(array_filter($values, static fn($v) => is_float($v) || is_int($v)));
    $count = count($values);
    if ($count < 2) {
        return null;
    }

    $mean = array_sum($values) / $count;
    $sumSquares = 0.0;
    foreach ($values as $value) {
        $sumSquares += ($value - $mean) ** 2;
    }

    return sqrt($sumSquares / ($count - 1));
}

function chainReturns(array $returns): float
{
    $growth = 1.0;
    foreach ($returns as $return) {
        if ($return !== null && is_finite((float)$return)) {
            $growth *= (1.0 + (float)$return);
        }
    }
    return $growth - 1.0;
}

function arithmeticMean(array $values): ?float
{
    $values = array_values(array_filter($values, static fn($v) => is_float($v) || is_int($v)));
    return $values === [] ? null : array_sum($values) / count($values);
}

function sampleCovariance(array $x, array $y): ?float
{
    $count = min(count($x), count($y));
    if ($count < 2) {
        return null;
    }

    $x = array_slice(array_values($x), 0, $count);
    $y = array_slice(array_values($y), 0, $count);
    $meanX = array_sum($x) / $count;
    $meanY = array_sum($y) / $count;
    $sum = 0.0;
    for ($i = 0; $i < $count; $i++) {
        $sum += ($x[$i] - $meanX) * ($y[$i] - $meanY);
    }
    return $sum / ($count - 1);
}

function percentile(array $values, float $p): ?float
{
    $values = array_values(array_filter($values, static fn($v) => is_float($v) || is_int($v)));
    if ($values === []) {
        return null;
    }
    sort($values, SORT_NUMERIC);
    $p = min(1.0, max(0.0, $p));
    $index = ($p * (count($values) - 1));
    $lower = (int)floor($index);
    $upper = (int)ceil($index);
    if ($lower === $upper) {
        return (float)$values[$lower];
    }
    $weight = $index - $lower;
    return ((float)$values[$lower] * (1.0 - $weight)) + ((float)$values[$upper] * $weight);
}

function annualizedGeometricReturn(?float $cumulativeReturn, DateTimeImmutable $startDate, DateTimeImmutable $endDate): ?float
{
    if ($cumulativeReturn === null || !is_finite($cumulativeReturn) || (1.0 + $cumulativeReturn) <= 0.0) {
        return null;
    }
    $days = max(1, (int)$startDate->diff($endDate)->format('%a'));
    return ((1.0 + $cumulativeReturn) ** (365.25 / $days)) - 1.0;
}

function calculateAdvancedMetrics(array $dailyRows, array $monthlyPeriodReturns, float $riskFreeAnnual): array
{
    $portfolioReturns = [];
    $portfolioPaired = [];
    $benchmarkPaired = [];
    $activePaired = [];

    foreach ($dailyRows as $row) {
        $rp = $row['daily_return'] ?? null;
        if ($rp !== null && is_finite((float)$rp)) {
            $portfolioReturns[] = (float)$rp;
        }
        $rb = $row['benchmark_daily_return'] ?? null;
        if ($rp !== null && $rb !== null && is_finite((float)$rp) && is_finite((float)$rb)) {
            $portfolioPaired[] = (float)$rp;
            $benchmarkPaired[] = (float)$rb;
            $activePaired[] = (float)$rp - (float)$rb;
        }
    }

    $riskFreeDaily = ((1.0 + $riskFreeAnnual) > 0.0)
        ? ((1.0 + $riskFreeAnnual) ** (1.0 / 252.0)) - 1.0
        : 0.0;
    $excess = array_map(static fn($r) => $r - $riskFreeDaily, $portfolioReturns);
    $meanExcess = arithmeticMean($excess);
    $dailyStd = sampleStandardDeviation($portfolioReturns);
    $annualizedVol = $dailyStd !== null ? $dailyStd * sqrt(252.0) : null;
    $sharpe = ($meanExcess !== null && $annualizedVol !== null && $annualizedVol > EPSILON)
        ? ($meanExcess * 252.0) / $annualizedVol
        : null;

    $downsideSquares = [];
    foreach ($excess as $value) {
        $downsideSquares[] = min(0.0, (float)$value) ** 2;
    }
    $downsideDeviation = $downsideSquares !== []
        ? sqrt(array_sum($downsideSquares) / count($downsideSquares)) * sqrt(252.0)
        : null;
    $sortino = ($meanExcess !== null && $downsideDeviation !== null && $downsideDeviation > EPSILON)
        ? ($meanExcess * 252.0) / $downsideDeviation
        : null;

    $firstDate = new DateTimeImmutable($dailyRows[0]['date']);
    $lastDate = new DateTimeImmutable($dailyRows[array_key_last($dailyRows)]['date']);
    $cumulative = chainReturns($portfolioReturns);
    $annualizedReturn = annualizedGeometricReturn($cumulative, $firstDate, $lastDate);
    $maxDrawdown = min(array_column($dailyRows, 'drawdown'));
    $calmar = ($annualizedReturn !== null && abs($maxDrawdown) > EPSILON)
        ? $annualizedReturn / abs($maxDrawdown)
        : null;

    $beta = null;
    $correlation = null;
    $alpha = null;
    $trackingError = null;
    $informationRatio = null;
    if (count($portfolioPaired) >= 2) {
        $covariance = sampleCovariance($portfolioPaired, $benchmarkPaired);
        $benchmarkStd = sampleStandardDeviation($benchmarkPaired);
        $portfolioStd = sampleStandardDeviation($portfolioPaired);
        $benchmarkVariance = $benchmarkStd !== null ? $benchmarkStd ** 2 : null;
        if ($covariance !== null && $benchmarkVariance !== null && $benchmarkVariance > EPSILON) {
            $beta = $covariance / $benchmarkVariance;
        }
        if ($covariance !== null && $benchmarkStd !== null && $portfolioStd !== null && $benchmarkStd > EPSILON && $portfolioStd > EPSILON) {
            $correlation = $covariance / ($benchmarkStd * $portfolioStd);
        }
        if ($beta !== null) {
            $meanPortfolio = arithmeticMean($portfolioPaired);
            $meanBenchmark = arithmeticMean($benchmarkPaired);
            if ($meanPortfolio !== null && $meanBenchmark !== null) {
                $alpha = (($meanPortfolio - $riskFreeDaily) - $beta * ($meanBenchmark - $riskFreeDaily)) * 252.0;
            }
        }
        $activeStd = sampleStandardDeviation($activePaired);
        $trackingError = $activeStd !== null ? $activeStd * sqrt(252.0) : null;
        $meanActive = arithmeticMean($activePaired);
        if ($trackingError !== null && $trackingError > EPSILON && $meanActive !== null) {
            $informationRatio = ($meanActive * 252.0) / $trackingError;
        }
    }

    $q05 = percentile($portfolioReturns, 0.05);
    $var95 = $q05 !== null ? max(0.0, -$q05) : null;
    $tail = $q05 !== null ? array_values(array_filter($portfolioReturns, static fn($r) => $r <= $q05)) : [];
    $tailMean = arithmeticMean($tail);
    $cvar95 = $tailMean !== null ? max(0.0, -$tailMean) : null;

    $longestDrawdownDays = 0;
    $currentDrawdownDays = 0;
    $drawdownStart = null;
    foreach ($dailyRows as $row) {
        $date = new DateTimeImmutable($row['date']);
        if ((float)$row['drawdown'] < -EPSILON) {
            $drawdownStart ??= $date;
            $currentDrawdownDays = (int)$drawdownStart->diff($date)->format('%a');
            $longestDrawdownDays = max($longestDrawdownDays, $currentDrawdownDays);
        } else {
            $drawdownStart = null;
            $currentDrawdownDays = 0;
        }
    }

    $bestMonth = null;
    $worstMonth = null;
    $positiveMonths = 0;
    $beatMonths = 0;
    $benchmarkComparableMonths = 0;
    foreach ($monthlyPeriodReturns as $month => $values) {
        $rp = $values['portfolio'] ?? null;
        if ($rp === null) {
            continue;
        }
        if ($bestMonth === null || $rp > $bestMonth['return']) {
            $bestMonth = ['period' => $month, 'return' => $rp];
        }
        if ($worstMonth === null || $rp < $worstMonth['return']) {
            $worstMonth = ['period' => $month, 'return' => $rp];
        }
        if ($rp > 0) {
            $positiveMonths++;
        }
        if (($values['benchmark'] ?? null) !== null) {
            $benchmarkComparableMonths++;
            if ($rp > $values['benchmark']) {
                $beatMonths++;
            }
        }
    }
    $monthCount = count($monthlyPeriodReturns);

    return [
        'risk_free_rate' => $riskFreeAnnual,
        'sharpe_ratio' => $sharpe,
        'sortino_ratio' => $sortino,
        'calmar_ratio' => $calmar,
        'downside_deviation' => $downsideDeviation,
        'alpha' => $alpha,
        'beta' => $beta,
        'correlation' => $correlation,
        'tracking_error' => $trackingError,
        'information_ratio' => $informationRatio,
        'var_95_daily' => $var95,
        'cvar_95_daily' => $cvar95,
        'longest_drawdown_days' => $longestDrawdownDays,
        'current_drawdown_days' => $currentDrawdownDays,
        'best_month' => $bestMonth,
        'worst_month' => $worstMonth,
        'positive_month_rate' => $monthCount > 0 ? $positiveMonths / $monthCount : null,
        'benchmark_beat_month_rate' => $benchmarkComparableMonths > 0 ? $beatMonths / $benchmarkComparableMonths : null,
    ];
}

/**
 * Linked active contribution for every report period in one backward pass.
 *
 * Every period ends on the last row, so the linked effect of a holding for a
 * window starting on used day m satisfies
 *
 *   E(m) = a(m) * B(after m) + (1 + rp(m)) * E(m + 1)
 *
 * where a is the holding's daily active contribution and B(after m) is the
 * benchmark growth over the used days after m. This is the same geometric
 * linking as walking each window forward (earlier days' effects compound with
 * later benchmark returns, later days' effects scale with earlier portfolio
 * growth), so walking backward once and snapshotting the state as each window
 * opens gives every period without re-scanning the history per period.
 *
 * $periodWindows: label => ['start' => Y-m-d, 'first_included' => Y-m-d,
 *                           'require_full_history' => bool]
 */
function linkedActiveContributionsByPeriod(array $dailyRows, array $periodWindows): array
{
    $results = array_fill_keys(array_keys($periodWindows), null);
    if ($dailyRows === []) {
        return $results;
    }

    // Dates are Y-m-d strings, which compare correctly as plain strings.
    $pending = array_filter(
        $periodWindows,
        static fn($window) => !$window['require_full_history'] || $dailyRows[0]['date'] <= $window['start']
    );
    // Latest window first: it is the first one complete while walking backward.
    uasort($pending, static fn($a, $b) => strcmp($b['first_included'], $a['first_included']));

    $effects = [];
    $detailStats = [];
    $portfolioGrowth = 1.0;
    $benchmarkGrowth = 1.0;
    $used = 0;
    $actualStart = null;
    $actualEnd = null;

    for ($i = count($dailyRows) - 1; $i >= -1; $i--) {
        $row = $i >= 0 ? $dailyRows[$i] : null;

        // Every window opening after this row now has all of its days.
        foreach ($pending as $label => $window) {
            if ($row !== null && $window['first_included'] <= $row['date']) {
                break;
            }
            $results[$label] = finishLinkedActiveContributions(
                $effects, $detailStats, $portfolioGrowth, $benchmarkGrowth, $used, $actualStart, $actualEnd
            );
            unset($pending[$label]);
        }
        if ($row === null || $pending === []) {
            break;
        }

        $rp = $row['daily_return'] ?? null;
        $rb = $row['benchmark_daily_return'] ?? null;
        $activeMap = $row['active_contributions'] ?? null;
        if ($rp === null || $rb === null || !is_array($activeMap)) {
            continue;
        }

        // Geometrically link each holding's daily active effect so the total
        // reconciles to cumulative portfolio return minus cumulative benchmark return.
        foreach ($effects as $bucket => $value) {
            $effects[$bucket] = $value * (1.0 + (float)$rp);
        }
        foreach ($activeMap as $bucket => $dailyActiveContribution) {
            if (!is_finite((float)$dailyActiveContribution)) {
                continue;
            }
            $effects[$bucket] = ($effects[$bucket] ?? 0.0) + $benchmarkGrowth * (float)$dailyActiveContribution;
        }

        // Supporting diagnostics for interpretation.  These do NOT drive the
        // linked active contribution calculation above.  They answer the more
        // intuitive questions: how large was the position, how did the holding
        // perform while it was actually owned, and what did the benchmark do
        // over those same exposed market days?
        $contributions = is_array($row['contributions'] ?? null) ? $row['contributions'] : [];
        $beginningWeights = is_array($row['beginning_weights'] ?? null) ? $row['beginning_weights'] : [];
        $detailBuckets = array_keys($activeMap + $contributions + $beginningWeights);

        foreach ($detailBuckets as $bucket) {
            if (!isset($detailStats[$bucket])) {
                $detailStats[$bucket] = [
                    'weight_sum' => 0.0,
                    'exposure_days' => 0,
                    'holding_growth' => 1.0,
                    'benchmark_held_growth' => 1.0,
                    'first_exposure_date' => null,
                    'last_exposure_date' => null,
                ];
            }

            $beginningWeight = (float)($beginningWeights[$bucket] ?? 0.0);
            $detailStats[$bucket]['weight_sum'] += $beginningWeight;

            // A security-level daily total return is recoverable from its
            // portfolio contribution divided by its beginning-of-day weight.
            // Trades are neutralized inside the contribution calculation, while
            // dividends/interest remain internal performance activity.
            if (abs($beginningWeight) > EPSILON) {
                $portfolioContribution = (float)($contributions[$bucket] ?? 0.0);
                $holdingDailyReturn = $portfolioContribution / $beginningWeight;

                if (is_finite($holdingDailyReturn) && $holdingDailyReturn >= -1.0) {
                    $detailStats[$bucket]['holding_growth'] *= 1.0 + $holdingDailyReturn;
                    $detailStats[$bucket]['benchmark_held_growth'] *= 1.0 + (float)$rb;
                    $detailStats[$bucket]['exposure_days']++;
                    // Walking backward, the last date seen is the earliest.
                    $detailStats[$bucket]['first_exposure_date'] = $row['date'];
                    $detailStats[$bucket]['last_exposure_date'] ??= $row['date'];
                }
            }
        }

        $portfolioGrowth *= 1.0 + (float)$rp;
        $benchmarkGrowth *= 1.0 + (float)$rb;
        $used++;
        $actualStart = $row['date'];
        $actualEnd ??= $row['date'];
    }

    return $results;
}

function finishLinkedActiveContributions(
    array $effects,
    array $detailStats,
    float $portfolioGrowth,
    float $benchmarkGrowth,
    int $used,
    ?string $actualStart,
    ?string $actualEnd
): ?array {
    if ($used === 0) {
        return null;
    }

    $activeCumulative = $portfolioGrowth - $benchmarkGrowth;
    $residual = $activeCumulative - array_sum($effects);
    if (abs($residual) > 0.0000001) {
        $effects['Attribution residual'] = ($effects['Attribution residual'] ?? 0.0) + $residual;
    }

    $details = [];
    foreach ($detailStats as $bucket => $stats) {
        $exposureDays = (int)$stats['exposure_days'];
        $holdingReturn = $exposureDays > 0 ? (float)$stats['holding_growth'] - 1.0 : null;
        $benchmarkWhileHeld = $exposureDays > 0 ? (float)$stats['benchmark_held_growth'] - 1.0 : null;
        $details[$bucket] = [
            // Average beginning-of-day portfolio weight across ALL comparable
            // days in the selected period. Days before/after ownership therefore
            // correctly contribute zero weight.
            'average_weight' => (float)$stats['weight_sum'] / $used,
            'holding_return' => $holdingReturn,
            'benchmark_while_held' => $benchmarkWhileHeld,
            'excess_return_while_held' => ($holdingReturn !== null && $benchmarkWhileHeld !== null)
                ? $holdingReturn - $benchmarkWhileHeld
                : null,
            'active_contribution' => (float)($effects[$bucket] ?? 0.0),
            'exposure_days' => $exposureDays,
            'first_exposure_date' => $stats['first_exposure_date'],
            'last_exposure_date' => $stats['last_exposure_date'],
        ];
    }

    arsort($effects, SORT_NUMERIC);
    $contributors = [];
    $detractors = [];
    foreach ($effects as $bucket => $value) {
        if ($bucket === 'Attribution residual') {
            continue;
        }
        if ($value > 0.0000001) {
            $contributors[$bucket] = $value;
        } elseif ($value < -0.0000001) {
            $detractors[$bucket] = $value;
        }
    }
    asort($detractors, SORT_NUMERIC);

    return [
        'start_date' => $actualStart,
        'end_date' => $actualEnd,
        'portfolio_cumulative' => $portfolioGrowth - 1.0,
        'benchmark_cumulative' => $benchmarkGrowth - 1.0,
        'active_cumulative' => $activeCumulative,
        'effects' => $effects,
        'contributors' => $contributors,
        'detractors' => $detractors,
        'details' => $details,
        'comparable_days' => $used,
    ];
}

/**
 * Cumulative growth after each daily row. Any period ending on the last row is
 * then a ratio of two entries instead of a fresh pass over the whole history.
 */
function buildGrowthIndex(array $dailyRows, string $returnKey): array
{
    $dates = [];
    $returns = [];
    $growth = [];
    $observations = [];
    $runningGrowth = 1.0;
    $runningCount = 0;

    foreach ($dailyRows as $row) {
        $value = $row[$returnKey] ?? null;
        $value = ($value !== null && is_finite((float)$value)) ? (float)$value : null;
        if ($value !== null) {
            $runningGrowth *= 1.0 + $value;
            $runningCount++;
        }
        $dates[] = $row['date'];
        $returns[] = $value;
        $growth[] = $runningGrowth;
        $observations[] = $runningCount;
    }

    return ['dates' => $dates, 'returns' => $returns, 'growth' => $growth, 'observations' => $observations];
}

/**
 * Chained return from $firstIncludedDate (inclusive) through the last row.
 * $startDate is the period's nominal start, used for the full-history check.
 */
function periodReturn(array $growthIndex, string $startDate, string $firstIncludedDate, bool $requireFullHistory = true): ?float
{
    $dates = $growthIndex['dates'];
    if ($dates === []) {
        return null;
    }
    if ($requireFullHistory && $dates[0] > $startDate) {
        return null;
    }

    $first = firstIndexOnOrAfter($dates, $firstIncludedDate);
    if ($first === null) {
        return null;
    }

    $last = count($dates) - 1;
    $baseCount = $first > 0 ? $growthIndex['observations'][$first - 1] : 0;
    if ($growthIndex['observations'][$last] === $baseCount) {
        return null;
    }

    $baseGrowth = $first > 0 ? $growthIndex['growth'][$first - 1] : 1.0;
    if (abs($baseGrowth) < EPSILON) {
        // A -100% day before the window makes the ratio undefined; chain directly.
        return chainReturns(array_slice($growthIndex['returns'], $first));
    }

    return ($growthIndex['growth'][$last] / $baseGrowth) - 1.0;
}

/**
 * Standard performance-presentation convention:
 * - periods shorter than one year: cumulative return
 * - periods equal to or longer than one year: annualized geometric return
 */
function returnForPresentation(?float $cumulativeReturn, DateTimeImmutable $startDate, DateTimeImmutable $endDate): ?float
{
    if ($cumulativeReturn === null || !is_finite($cumulativeReturn)) {
        return null;
    }

    if ($endDate < $startDate->modify('+1 year')) {
        return $cumulativeReturn;
    }

    if ((1.0 + $cumulativeReturn) <= 0.0) {
        return null;
    }

    // Preserve exact calendar-year periods (1Y, 3Y, 5Y, etc.) so a true
    // one-year return is not altered merely because that year had 365/366 days.
    for ($years = 1; $years <= 100; $years++) {
        if ($startDate->modify('+' . $years . ' years')->format('Y-m-d') === $endDate->format('Y-m-d')) {
            return ((1.0 + $cumulativeReturn) ** (1.0 / $years)) - 1.0;
        }
    }

    $days = max(1, (int)$startDate->diff($endDate)->format('%a'));
    return ((1.0 + $cumulativeReturn) ** (365.25 / $days)) - 1.0;
}

function returnConventionLabel(DateTimeImmutable $startDate, DateTimeImmutable $endDate): string
{
    return $endDate >= $startDate->modify('+1 year') ? 'Annualized' : 'Cumulative';
}

function bucketPeriodReturns(array $dailyRows, string $bucketType): array
{
    $portfolioBuckets = [];
    $benchmarkBuckets = [];

    foreach ($dailyRows as $row) {
        if ($bucketType === 'quarter') {
            $quarter = intdiv((int)substr($row['date'], 5, 2) - 1, 3) + 1;
            $bucket = substr($row['date'], 0, 4) . '-Q' . $quarter;
        } else {
            $bucket = substr($row['date'], 0, 7);
        }

        if ($row['daily_return'] !== null && is_finite((float)$row['daily_return'])) {
            $portfolioBuckets[$bucket][] = (float)$row['daily_return'];
        }
        if ($row['benchmark_daily_return'] !== null && is_finite((float)$row['benchmark_daily_return'])) {
            $benchmarkBuckets[$bucket][] = (float)$row['benchmark_daily_return'];
        }
    }

    $result = [];
    foreach ($portfolioBuckets as $bucket => $returns) {
        $portfolioReturn = chainReturns($returns);
        $benchmarkReturn = isset($benchmarkBuckets[$bucket]) && $benchmarkBuckets[$bucket] !== []
            ? chainReturns($benchmarkBuckets[$bucket])
            : null;

        $result[$bucket] = [
            'portfolio' => $portfolioReturn,
            'benchmark' => $benchmarkReturn,
            'active' => $benchmarkReturn !== null ? $portfolioReturn - $benchmarkReturn : null,
        ];
    }

    ksort($result);
    return $result;
}

function firstIndexOnOrAfter(array $sortedDates, string $date): ?int
{
    $low = 0;
    $high = count($sortedDates) - 1;
    $answer = null;

    while ($low <= $high) {
        $mid = intdiv($low + $high, 2);
        if ($sortedDates[$mid] >= $date) {
            $answer = $mid;
            $high = $mid - 1;
        } else {
            $low = $mid + 1;
        }
    }

    return $answer;
}

function nearestTradingDateOnOrAfter(array $calendarDates, string $date): ?string
{
    $index = firstIndexOnOrAfter($calendarDates, $date);
    return $index === null ? null : $calendarDates[$index];
}

// -----------------------------------------------------------------------------
// CSV parsers
// -----------------------------------------------------------------------------

function parsePositions(string $path): array
{
    $rows = readCsvAssociative($path);
    $shares = [];
    $descriptions = [];
    $currentPrices = [];
    $cash = 0.0;
    $reportedTotal = null;

    foreach ($rows as $row) {
        $symbol = trim((string)($row['Symbol'] ?? ''));
        $description = trim((string)($row['Description'] ?? ''));
        $assetType = trim((string)($row['Asset Type'] ?? ''));
        $marketValue = parseNumber($row['Mkt Val (Market Value)'] ?? '');

        if (strcasecmp($symbol, 'Positions Total') === 0) {
            $reportedTotal = $marketValue;
            continue;
        }

        if (stripos($assetType, 'Cash') !== false || stripos($symbol, 'Cash & Cash') !== false) {
            $cash += $marketValue;
            continue;
        }

        if ($symbol === '' || $symbol === '--') {
            continue;
        }

        $ticker = normalizeTicker($symbol);
        $quantity = parseNumber($row['Qty (Quantity)'] ?? '');
        if (abs($quantity) < EPSILON) {
            continue;
        }

        $shares[$ticker] = ($shares[$ticker] ?? 0.0) + $quantity;
        $descriptions[$ticker] = $description;
        $currentPrices[$ticker] = parseNumber($row['Price'] ?? '');
    }

    if ($shares === [] && abs($cash) < EPSILON && $reportedTotal === null) {
        throw new RuntimeException(
            'No usable current positions were parsed from the positions CSV. '
            . 'The brokerage export format may have changed.'
        );
    }

    return [
        'shares' => $shares,
        'cash' => $cash,
        'descriptions' => $descriptions,
        'current_prices' => $currentPrices,
        'reported_total' => $reportedTotal,
        'row_count' => count($rows),
    ];
}

function parseTransactions(string $path): array
{
    $rows = readCsvAssociative($path);
    $transactions = [];
    $actionCounts = [];

    foreach ($rows as $index => $row) {
        $date = parseBrokerageDate((string)($row['Date'] ?? ''));
        $action = trim((string)($row['Action'] ?? ''));
        if ($date === null || $action === '') {
            continue;
        }

        $symbol = normalizeTicker((string)($row['Symbol'] ?? ''));
        $quantity = parseNumber($row['Quantity'] ?? '');
        $price = parseNumber($row['Price'] ?? '');
        $amount = parseNumber($row['Amount'] ?? '');
        $fees = parseNumber($row['Fees & Comm'] ?? '');
        $shareDelta = $symbol !== '' ? transactionShareDelta($action, $quantity) : 0.0;
        $normalizedAction = normalizeAction($action);

        $transactions[] = [
            'source_index' => $index,
            'date' => $date->format('Y-m-d'),
            'action' => $action,
            'normalized_action' => $normalizedAction,
            'symbol' => $symbol,
            'description' => trim((string)($row['Description'] ?? '')),
            'quantity' => $quantity,
            'share_delta' => $shareDelta,
            'price' => $price,
            'fees' => $fees,
            'amount' => $amount,
            'multiplier' => looksLikeOptionSymbol($symbol) ? OPTION_CONTRACT_MULTIPLIER : 1.0,
        ];

        $actionCounts[$action] = ($actionCounts[$action] ?? 0) + 1;
    }

    usort($transactions, static function (array $a, array $b): int {
        $dateCompare = strcmp($a['date'], $b['date']);
        return $dateCompare !== 0 ? $dateCompare : ($a['source_index'] <=> $b['source_index']);
    });

    arsort($actionCounts);

    return [
        'transactions' => $transactions,
        'action_counts' => $actionCounts,
        'row_count' => count($rows),
    ];
}

// -----------------------------------------------------------------------------
// PostgreSQL helpers
// -----------------------------------------------------------------------------

function connectDatabase(string $dsn, string $user, string $password): PDO
{
    return new PDO(
        $dsn,
        $user !== '' ? $user : null,
        $password !== '' ? $password : null,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function ensureUserTransactionsTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_transactions (
            id BIGSERIAL PRIMARY KEY,
            user_id TEXT NOT NULL,
            dataset_name TEXT NOT NULL,
            as_of_date DATE NOT NULL,
            report_start_date DATE NULL,
            benchmark_ticker TEXT NOT NULL DEFAULT 'SPTM',
            risk_free_rate_pct NUMERIC(12,6) NOT NULL DEFAULT 0,
            external_actions TEXT NOT NULL DEFAULT 'MoneyLink Transfer',
            positions_filename TEXT NULL,
            transactions_filename TEXT NULL,
            position_count INTEGER NOT NULL DEFAULT 0,
            transaction_count INTEGER NOT NULL DEFAULT 0,
            positions_json JSONB NOT NULL,
            transactions_json JSONB NOT NULL,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )"
    );

    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_user_transactions_user_date
         ON user_transactions (user_id, as_of_date DESC, created_at DESC)'
    );
}

function saveUserDataset(
    PDO $pdo,
    string $userId,
    string $datasetName,
    string $asOfDate,
    string $reportStartDate,
    string $benchmarkTicker,
    float $riskFreeRatePct,
    string $externalActionsText,
    string $positionsFilename,
    string $transactionsFilename,
    array $positionsSummary,
    array $transactionsSummary
): int {
    $datasetName = trim($datasetName);
    if ($datasetName === '') {
        $datasetName = 'Portfolio snapshot ' . $asOfDate;
    }
    if (strlen($datasetName) > 120) {
        $datasetName = substr($datasetName, 0, 120);
    }

    $positionsJson = json_encode($positionsSummary, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    $transactionsJson = json_encode($transactionsSummary, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

    $statement = $pdo->prepare(
        'INSERT INTO user_transactions (
            user_id, dataset_name, as_of_date, report_start_date, benchmark_ticker,
            risk_free_rate_pct, external_actions, positions_filename, transactions_filename,
            position_count, transaction_count, positions_json, transactions_json
         ) VALUES (
            :user_id, :dataset_name, CAST(:as_of_date AS date),
            CAST(NULLIF(:report_start_date, \'\') AS date), :benchmark_ticker,
            :risk_free_rate_pct, :external_actions, :positions_filename, :transactions_filename,
            :position_count, :transaction_count, CAST(:positions_json AS jsonb), CAST(:transactions_json AS jsonb)
         ) RETURNING id'
    );
    $statement->execute([
        'user_id' => $userId,
        'dataset_name' => $datasetName,
        'as_of_date' => $asOfDate,
        'report_start_date' => $reportStartDate,
        'benchmark_ticker' => $benchmarkTicker,
        'risk_free_rate_pct' => $riskFreeRatePct,
        'external_actions' => $externalActionsText,
        'positions_filename' => $positionsFilename,
        'transactions_filename' => $transactionsFilename,
        'position_count' => count($positionsSummary['shares'] ?? []),
        'transaction_count' => count($transactionsSummary['transactions'] ?? []),
        'positions_json' => $positionsJson,
        'transactions_json' => $transactionsJson,
    ]);

    return (int)$statement->fetchColumn();
}

function listUserDatasets(PDO $pdo, string $userId): array
{
    // The table is created on first save, so a missing table just means
    // nothing has been saved yet.
    $exists = $pdo->query("SELECT to_regclass('user_transactions') IS NOT NULL")->fetchColumn();
    if (!$exists) {
        return [];
    }

    $statement = $pdo->prepare(
        'SELECT id, dataset_name, as_of_date, report_start_date, benchmark_ticker,
                risk_free_rate_pct, positions_filename, transactions_filename,
                position_count, transaction_count, created_at
         FROM user_transactions
         WHERE user_id = :user_id
         ORDER BY as_of_date DESC, created_at DESC, id DESC'
    );
    $statement->execute(['user_id' => $userId]);
    return $statement->fetchAll();
}

function loadUserDataset(PDO $pdo, string $userId, int $datasetId): array
{
    $statement = $pdo->prepare(
        'SELECT * FROM user_transactions WHERE id = :id AND user_id = :user_id'
    );
    $statement->execute(['id' => $datasetId, 'user_id' => $userId]);
    $row = $statement->fetch();
    if ($row === false) {
        throw new RuntimeException('The saved dataset was not found or does not belong to this user.');
    }

    $positions = json_decode((string)$row['positions_json'], true, 512, JSON_THROW_ON_ERROR);
    $transactions = json_decode((string)$row['transactions_json'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($positions) || !is_array($transactions)) {
        throw new RuntimeException('The saved dataset is unreadable.');
    }

    $row['positions_summary'] = $positions;
    $row['transactions_summary'] = $transactions;
    return $row;
}

function deleteUserDataset(PDO $pdo, string $userId, int $datasetId): void
{
    $statement = $pdo->prepare('DELETE FROM user_transactions WHERE id = :id AND user_id = :user_id');
    $statement->execute(['id' => $datasetId, 'user_id' => $userId]);
}

function tableColumns(PDO $pdo, string $table): array
{
    $statement = $pdo->prepare(
        'SELECT column_name
         FROM information_schema.columns
         WHERE table_schema = current_schema()
           AND table_name = :table'
    );
    $statement->execute(['table' => $table]);
    return array_map(static fn($row) => (string)$row['column_name'], $statement->fetchAll());
}

function resolvePriceSchema(PDO $pdo): array
{
    $columns = tableColumns($pdo, 'prices_stocks');
    if ($columns === []) {
        throw new RuntimeException('The prices_stocks table was not found in the active PostgreSQL schema.');
    }

    $dateColumn = in_array('date', $columns, true) ? 'date' : (in_array('price_date', $columns, true) ? 'price_date' : null);
    $tickerColumn = in_array('ticker', $columns, true) ? 'ticker' : null;
    $closeColumn = in_array('close', $columns, true) ? 'close' : (in_array('close_price', $columns, true) ? 'close_price' : null);

    if ($dateColumn === null || $tickerColumn === null || $closeColumn === null) {
        throw new RuntimeException('prices_stocks must contain date/ticker/close (or price_date/ticker/close_price) columns.');
    }

    return [
        'date' => $dateColumn,
        'ticker' => $tickerColumn,
        'close' => $closeColumn,
    ];
}

function fetchCalendarDates(
    PDO $pdo,
    array $schema,
    string $startDate,
    string $endDate,
    string $calendarTicker
): array {
    $date = quoteIdentifier($schema['date']);
    $ticker = quoteIdentifier($schema['ticker']);

    $sql = "SELECT {$date}::date AS price_date
            FROM prices_stocks
            WHERE UPPER({$ticker}) = :ticker
              AND {$date} BETWEEN :start_date AND :end_date
            ORDER BY {$date}";

    $statement = $pdo->prepare($sql);
    $statement->execute([
        'ticker' => normalizeTicker($calendarTicker),
        'start_date' => $startDate,
        'end_date' => $endDate,
    ]);

    $dates = array_map(static fn($row) => (string)$row['price_date'], $statement->fetchAll());

    if ($dates !== []) {
        return array_values(array_unique($dates));
    }

    // Fallback only when the selected calendar security has no data.
    $sql = "SELECT DISTINCT {$date}::date AS price_date
            FROM prices_stocks
            WHERE {$date} BETWEEN :start_date AND :end_date
            ORDER BY price_date";
    $statement = $pdo->prepare($sql);
    $statement->execute(['start_date' => $startDate, 'end_date' => $endDate]);

    return array_map(static fn($row) => (string)$row['price_date'], $statement->fetchAll());
}

function fetchRawPrices(
    PDO $pdo,
    array $schema,
    array $symbols,
    string $startDate,
    string $endDate
): array {
    $symbols = array_values(array_unique(array_filter(array_map('normalizeTicker', $symbols))));
    if ($symbols === []) {
        return [];
    }

    $date = quoteIdentifier($schema['date']);
    $ticker = quoteIdentifier($schema['ticker']);
    $close = quoteIdentifier($schema['close']);

    $placeholders = [];
    $parameters = ['start_date' => $startDate, 'end_date' => $endDate];
    foreach ($symbols as $index => $symbol) {
        $key = 'ticker_' . $index;
        $placeholders[] = ':' . $key;
        $parameters[$key] = $symbol;
    }

    $sql = "SELECT {$date}::date AS price_date,
                   UPPER({$ticker}) AS ticker,
                   {$close}::double precision AS close_value
            FROM prices_stocks
            WHERE {$date} BETWEEN :start_date AND :end_date
              AND UPPER({$ticker}) IN (" . implode(',', $placeholders) . ")
              AND {$close} IS NOT NULL
              AND {$close} > 0
            ORDER BY {$date}, {$ticker}";

    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);

    $prices = [];
    foreach ($statement->fetchAll() as $row) {
        $prices[(string)$row['ticker']][(string)$row['price_date']] = (float)$row['close_value'];
    }

    return $prices;
}

function fetchBenchmarkSplits(PDO $pdo, string $ticker, string $startDate, string $endDate): array
{
    $columns = tableColumns($pdo, 'splits');
    $required = ['ticker', 'ex_date', 'for_factor', 'to_factor'];
    foreach ($required as $column) {
        if (!in_array($column, $columns, true)) {
            return [];
        }
    }

    $statement = $pdo->prepare(
        'SELECT ex_date::date AS ex_date,
                for_factor::double precision AS for_factor,
                to_factor::double precision AS to_factor
         FROM splits
         WHERE UPPER(ticker) = :ticker
           AND ex_date BETWEEN :start_date AND :end_date
         ORDER BY ex_date'
    );
    $statement->execute([
        'ticker' => normalizeTicker($ticker),
        'start_date' => $startDate,
        'end_date' => $endDate,
    ]);

    $splits = [];
    foreach ($statement->fetchAll() as $row) {
        $from = (float)$row['for_factor'];
        $to = (float)$row['to_factor'];
        if ($from > 0 && $to > 0) {
            $date = (string)$row['ex_date'];
            $splits[$date] = ($splits[$date] ?? 1.0) * ($to / $from);
        }
    }

    return $splits;
}

function fetchBenchmarkDividends(PDO $pdo, string $ticker, string $startDate, string $endDate): array
{
    $columns = tableColumns($pdo, 'dividends');
    if (!in_array('ticker', $columns, true) || !in_array('ex_dividend_date', $columns, true)) {
        return [];
    }

    // Raw close prices need the contemporaneous per-share cash amount, not a
    // price series adjusted to today's share basis.
    $amountColumn = in_array('cash_amount', $columns, true)
        ? 'cash_amount'
        : (in_array('split_adjusted_cash_amount', $columns, true) ? 'split_adjusted_cash_amount' : null);

    if ($amountColumn === null) {
        return [];
    }

    $amount = quoteIdentifier($amountColumn);
    $sql = "SELECT ex_dividend_date::date AS ex_date,
                   SUM({$amount})::double precision AS dividend_amount
            FROM dividends
            WHERE UPPER(ticker) = :ticker
              AND ex_dividend_date BETWEEN :start_date AND :end_date
              AND {$amount} IS NOT NULL
            GROUP BY ex_dividend_date
            ORDER BY ex_dividend_date";

    $statement = $pdo->prepare($sql);
    $statement->execute([
        'ticker' => normalizeTicker($ticker),
        'start_date' => $startDate,
        'end_date' => $endDate,
    ]);

    $dividends = [];
    foreach ($statement->fetchAll() as $row) {
        $dividends[(string)$row['ex_date']] = (float)$row['dividend_amount'];
    }

    return $dividends;
}

// -----------------------------------------------------------------------------
// Performance engine
// -----------------------------------------------------------------------------

function calculatePerformance(
    array $positions,
    array $transactionData,
    PDO $pdo,
    array $priceSchema,
    string $asOfDate,
    ?string $requestedStartDate,
    array $externalActions,
    string $benchmarkTicker,
    float $riskFreeAnnual
): array {
    $warnings = [];
    $transactions = array_values(array_filter(
        $transactionData['transactions'],
        static fn($transaction) => $transaction['date'] <= $asOfDate
    ));

    if ($transactions === [] && $positions['shares'] === []) {
        throw new RuntimeException('No usable positions or transactions were found.');
    }

    $earliestTransactionDate = $transactions !== []
        ? min(array_column($transactions, 'date'))
        : ($requestedStartDate ?: $asOfDate);

    $reportStartDate = $requestedStartDate ?: $earliestTransactionDate;
    if ($reportStartDate > $asOfDate) {
        throw new RuntimeException('The report start date cannot be after the as-of date.');
    }

    // Pull a market-date buffer so the day before the first transaction can be
    // used as the opening valuation denominator.
    $queryStart = (new DateTimeImmutable(min($earliestTransactionDate, $reportStartDate)))
        ->modify('-21 days')
        ->format('Y-m-d');

    $calendarTicker = $benchmarkTicker !== ''
        ? $benchmarkTicker
        : (array_key_first($positions['shares']) ?: 'SPTM');

    $calendarDates = fetchCalendarDates($pdo, $priceSchema, $queryStart, $asOfDate, $calendarTicker);
    if ($calendarDates === []) {
        throw new RuntimeException('No market dates were found in prices_stocks for the requested period.');
    }

    $analysisEndDate = end($calendarDates);
    if ($analysisEndDate < $asOfDate) {
        $warnings[] = "The latest market date in prices_stocks is {$analysisEndDate}, earlier than the requested as-of date {$asOfDate}.";
    }

    $baseDate = null;
    foreach ($calendarDates as $calendarDate) {
        if ($calendarDate < $earliestTransactionDate) {
            $baseDate = $calendarDate;
        } else {
            break;
        }
    }
    $baseDate ??= $calendarDates[0];

    $symbols = array_keys($positions['shares']);
    foreach ($transactions as $transaction) {
        if ($transaction['symbol'] !== '') {
            $symbols[] = $transaction['symbol'];
        }
    }
    if ($benchmarkTicker !== '') {
        $symbols[] = $benchmarkTicker;
    }
    $symbols = array_values(array_unique($symbols));

    $rawPrices = fetchRawPrices($pdo, $priceSchema, $symbols, $queryStart, $analysisEndDate);

    // Reconstruct the ledger immediately before the first transaction by
    // reversing every transaction from the current positions/cash anchor.
    $openingShares = $positions['shares'];
    foreach ($transactions as $transaction) {
        if ($transaction['symbol'] !== '' && !array_key_exists($transaction['symbol'], $openingShares)) {
            $openingShares[$transaction['symbol']] = 0.0;
        }
    }
    $openingCash = (float)$positions['cash'];

    foreach (array_reverse($transactions) as $transaction) {
        $openingCash -= (float)$transaction['amount'];
        if ($transaction['symbol'] !== '') {
            $openingShares[$transaction['symbol']] -= (float)$transaction['share_delta'];
        }
    }

    foreach ($openingShares as $symbol => $quantity) {
        if ($quantity < -EPSILON) {
            $warnings[] = sprintf(
                'Reverse reconstruction produced a negative opening position for %s: %.6f shares.',
                $symbol,
                $quantity
            );
        }
        if (abs($quantity) < EPSILON) {
            $openingShares[$symbol] = 0.0;
        }
    }

    $transactionsByDate = [];
    $externalActionsLookup = array_fill_keys(array_map('normalizeAction', $externalActions), true);
    $unmappedTransactions = 0;

    foreach ($transactions as $transaction) {
        $effectiveDate = nearestTradingDateOnOrAfter($calendarDates, $transaction['date']);
        if ($effectiveDate === null || $effectiveDate > $analysisEndDate) {
            $unmappedTransactions++;
            continue;
        }
        $transaction['effective_date'] = $effectiveDate;
        $transactionsByDate[$effectiveDate][] = $transaction;
    }

    if ($unmappedTransactions > 0) {
        $warnings[] = "{$unmappedTransactions} transaction(s) occur after the last available market date and were not replayed.";
    }

    $shares = $openingShares;
    $cash = $openingCash;
    $lastDatabasePrice = [];
    $lastTransactionPrice = [];
    $multipliers = [];
    foreach ($transactions as $transaction) {
        if ($transaction['symbol'] !== '') {
            $multipliers[$transaction['symbol']] = (float)$transaction['multiplier'];
        }
    }

    $missingPriceDays = [];
    $syntheticPriceSymbols = [];
    $allDailyRows = [];
    $previousValue = null;
    $previousPositionValues = [];
    $previousCashForContribution = $openingCash;

    foreach ($calendarDates as $date) {
        if ($date < $baseDate || $date > $analysisEndDate) {
            continue;
        }

        foreach ($rawPrices as $symbol => $series) {
            if (isset($series[$date])) {
                $lastDatabasePrice[$symbol] = (float)$series[$date];
            }
        }

        $externalFlow = 0.0;
        $dayTransactions = $transactionsByDate[$date] ?? [];
        $transactionCashByBucket = [];
        $externalFlowByBucket = [];

        foreach ($dayTransactions as $transaction) {
            $symbol = $transaction['symbol'];
            $amount = (float)$transaction['amount'];
            $cash += $amount;
            $bucket = $symbol !== '' ? $symbol : 'Cash / Other';
            $transactionCashByBucket[$bucket] = ($transactionCashByBucket[$bucket] ?? 0.0) + $amount;

            if ($symbol !== '') {
                $shares[$symbol] = ($shares[$symbol] ?? 0.0) + (float)$transaction['share_delta'];
                if (abs($shares[$symbol]) < EPSILON) {
                    $shares[$symbol] = 0.0;
                }

                if ((float)$transaction['price'] > 0) {
                    $lastTransactionPrice[$symbol] = (float)$transaction['price'];
                }
            }

            if (isset($externalActionsLookup[$transaction['normalized_action']])) {
                $externalFlow += $amount;
                $externalFlowByBucket[$bucket] = ($externalFlowByBucket[$bucket] ?? 0.0) + $amount;
            }
        }

        $marketValue = 0.0;
        $pricedPositions = 0;
        $positionValues = [];
        foreach ($shares as $symbol => $quantity) {
            if (abs($quantity) < EPSILON) {
                continue;
            }

            $price = $lastDatabasePrice[$symbol] ?? null;
            if ($price === null && isset($lastTransactionPrice[$symbol])) {
                $price = $lastTransactionPrice[$symbol];
                $syntheticPriceSymbols[$symbol] = true;
            }

            if ($price === null || $price <= 0) {
                $missingPriceDays[$symbol] = ($missingPriceDays[$symbol] ?? 0) + 1;
                continue;
            }

            $multiplier = $multipliers[$symbol] ?? 1.0;
            $positionValue = $quantity * $price * $multiplier;
            $positionValues[$symbol] = $positionValue;
            $marketValue += $positionValue;
            $pricedPositions++;
        }

        $accountValue = $marketValue + $cash;
        $dailyReturn = null;
        $contributions = null;
        $beginningWeights = null;
        if ($previousValue !== null && abs($previousValue) > EPSILON) {
            $dailyReturn = (($accountValue - $externalFlow) / $previousValue) - 1.0;

            $contributions = [];
            $beginningWeights = [];
            $contributionSymbols = array_unique(array_merge(
                array_keys($previousPositionValues),
                array_keys($positionValues),
                array_filter(array_keys($transactionCashByBucket), static fn($bucket) => $bucket !== 'Cash / Other')
            ));
            foreach ($contributionSymbols as $symbol) {
                $endingPositionValue = (float)($positionValues[$symbol] ?? 0.0);
                $beginningPositionValue = (float)($previousPositionValues[$symbol] ?? 0.0);
                $transactionCash = (float)($transactionCashByBucket[$symbol] ?? 0.0);
                $externalCash = (float)($externalFlowByBucket[$symbol] ?? 0.0);
                $contributions[$symbol] = (($endingPositionValue - $beginningPositionValue) + $transactionCash - $externalCash) / $previousValue;
                $beginningWeights[$symbol] = $beginningPositionValue / $previousValue;
            }
            $cashInternal = (float)($transactionCashByBucket['Cash / Other'] ?? 0.0)
                - (float)($externalFlowByBucket['Cash / Other'] ?? 0.0);
            $contributions['Cash / Other'] = $cashInternal / $previousValue;
            $beginningWeights['Cash / Other'] = $previousCashForContribution / $previousValue;
        }

        $allDailyRows[] = [
            'date' => $date,
            'account_value' => $accountValue,
            'market_value' => $marketValue,
            'cash' => $cash,
            'external_flow' => $externalFlow,
            'daily_return' => $dailyReturn,
            'priced_positions' => $pricedPositions,
            'contributions' => $contributions,
            'beginning_weights' => $beginningWeights,
        ];

        $previousValue = $accountValue;
        $previousPositionValues = $positionValues;
        $previousCashForContribution = $cash;
    }

    foreach ($missingPriceDays as $symbol => $days) {
        $warnings[] = "{$symbol} had no usable raw close or transaction-price fallback on {$days} held market day(s); its value was omitted on those dates.";
    }

    if ($syntheticPriceSymbols !== []) {
        $warnings[] = 'Transaction-price carry-forward was used for symbols not found in prices_stocks: '
            . implode(', ', array_keys($syntheticPriceSymbols))
            . '. This is mainly intended for closed option positions.';
    }

    // Reconciliation: the forward replay should end at the uploaded current
    // share and cash balances.
    $shareReconciliation = [];
    $allReconciliationSymbols = array_unique(array_merge(array_keys($shares), array_keys($positions['shares'])));
    foreach ($allReconciliationSymbols as $symbol) {
        $difference = ($shares[$symbol] ?? 0.0) - ($positions['shares'][$symbol] ?? 0.0);
        if (abs($difference) > 0.0001) {
            $shareReconciliation[$symbol] = $difference;
        }
    }
    $cashDifference = $cash - (float)$positions['cash'];

    if ($shareReconciliation !== []) {
        $warnings[] = 'Ending-share reconciliation differs from the positions CSV for: '
            . implode(', ', array_map(
                static fn($symbol, $difference) => sprintf('%s (%+.6f)', $symbol, $difference),
                array_keys($shareReconciliation),
                array_values($shareReconciliation)
            ));
    }
    if (abs($cashDifference) > 0.01) {
        $warnings[] = sprintf('Ending cash differs from the positions CSV by %+.2f.', $cashDifference);
    }

    $dailyRows = [];
    $growth = 1.0;
    $peak = 1.0;
    foreach ($allDailyRows as $row) {
        if ($row['date'] < $reportStartDate) {
            continue;
        }

        if ($row['daily_return'] !== null) {
            $growth *= 1.0 + $row['daily_return'];
        }
        $peak = max($peak, $growth);
        $drawdown = $peak > 0 ? ($growth / $peak) - 1.0 : 0.0;

        $row['cumulative_return'] = $growth - 1.0;
        $row['growth_of_10000'] = 10000.0 * $growth;
        $row['drawdown'] = $drawdown;
        $dailyRows[] = $row;
    }

    if ($dailyRows === []) {
        throw new RuntimeException('No daily observations fall within the selected report period.');
    }

    $benchmarkRows = [];
    if ($benchmarkTicker !== '' && isset($rawPrices[$benchmarkTicker])) {
        $benchmarkSplits = fetchBenchmarkSplits($pdo, $benchmarkTicker, $reportStartDate, $analysisEndDate);
        $benchmarkDividends = fetchBenchmarkDividends($pdo, $benchmarkTicker, $reportStartDate, $analysisEndDate);
        $previousBenchmarkPrice = null;
        $benchmarkGrowth = 1.0;
        $lastBenchmarkPrice = null;

        foreach ($calendarDates as $date) {
            if (isset($rawPrices[$benchmarkTicker][$date])) {
                $lastBenchmarkPrice = (float)$rawPrices[$benchmarkTicker][$date];
            }
            if ($date < $reportStartDate || $lastBenchmarkPrice === null) {
                if ($lastBenchmarkPrice !== null) {
                    $previousBenchmarkPrice = $lastBenchmarkPrice;
                }
                continue;
            }

            $benchmarkReturn = null;
            if ($previousBenchmarkPrice !== null && $previousBenchmarkPrice > 0) {
                $splitRatio = $benchmarkSplits[$date] ?? 1.0;
                $dividend = $benchmarkDividends[$date] ?? 0.0;
                $benchmarkReturn = (($splitRatio * ($lastBenchmarkPrice + $dividend)) / $previousBenchmarkPrice) - 1.0;
                $benchmarkGrowth *= 1.0 + $benchmarkReturn;
            }

            $benchmarkRows[$date] = [
                'daily_return' => $benchmarkReturn,
                'cumulative_return' => $benchmarkGrowth - 1.0,
                'growth_of_10000' => 10000.0 * $benchmarkGrowth,
            ];
            $previousBenchmarkPrice = $lastBenchmarkPrice;
        }
    } elseif ($benchmarkTicker !== '') {
        $warnings[] = "No raw prices were found for benchmark {$benchmarkTicker}.";
    }

    foreach ($dailyRows as &$row) {
        $benchmark = $benchmarkRows[$row['date']] ?? null;
        $row['benchmark_daily_return'] = $benchmark['daily_return'] ?? null;
        $row['benchmark_cumulative_return'] = $benchmark['cumulative_return'] ?? null;
        $row['benchmark_growth_of_10000'] = $benchmark['growth_of_10000'] ?? null;
        $row['active_contributions'] = null;
        if ($row['benchmark_daily_return'] !== null && is_array($row['contributions']) && is_array($row['beginning_weights'])) {
            $row['active_contributions'] = [];
            $buckets = array_unique(array_merge(array_keys($row['contributions']), array_keys($row['beginning_weights'])));
            foreach ($buckets as $bucket) {
                $portfolioContribution = (float)($row['contributions'][$bucket] ?? 0.0);
                $beginningWeight = (float)($row['beginning_weights'][$bucket] ?? 0.0);
                $row['active_contributions'][$bucket] = $portfolioContribution - $beginningWeight * (float)$row['benchmark_daily_return'];
            }
        }
    }
    unset($row);

    $returns = array_values(array_filter(
        array_column($dailyRows, 'daily_return'),
        static fn($value) => $value !== null && is_finite((float)$value)
    ));
    $totalReturn = chainReturns($returns);
    $firstDate = new DateTimeImmutable($dailyRows[0]['date']);
    $lastDate = new DateTimeImmutable($dailyRows[array_key_last($dailyRows)]['date']);
    $sinceInceptionReturn = returnForPresentation($totalReturn, $firstDate, $lastDate);
    $sinceInceptionConvention = returnConventionLabel($firstDate, $lastDate);
    $dailyStdDev = sampleStandardDeviation($returns);
    $annualizedVolatility = $dailyStdDev !== null ? $dailyStdDev * sqrt(252.0) : null;
    $maxDrawdown = min(array_column($dailyRows, 'drawdown'));
    $endingRow = $dailyRows[array_key_last($dailyRows)];
    $netExternalFlow = array_sum(array_column($dailyRows, 'external_flow'));

    $benchmarkTotalReturn = null;
    $benchmarkReturnValues = array_values(array_filter(
        array_column($dailyRows, 'benchmark_daily_return'),
        static fn($value) => $value !== null && is_finite((float)$value)
    ));
    if ($benchmarkReturnValues !== []) {
        $benchmarkTotalReturn = chainReturns($benchmarkReturnValues);
    }
    $benchmarkSinceInceptionReturn = returnForPresentation($benchmarkTotalReturn, $firstDate, $lastDate);
    $activeSinceInceptionReturn = ($sinceInceptionReturn !== null && $benchmarkSinceInceptionReturn !== null)
        ? $sinceInceptionReturn - $benchmarkSinceInceptionReturn
        : null;

    $periods = [
        'MTD' => new DateTimeImmutable($lastDate->format('Y-m-01')),
        '3 Months' => $lastDate->modify('-3 months'),
        '6 Months' => $lastDate->modify('-6 months'),
        'YTD' => new DateTimeImmutable($lastDate->format('Y-01-01')),
        '1 Year' => $lastDate->modify('-1 year'),
        '3 Year' => $lastDate->modify('-3 years'),
        '5 Year' => $lastDate->modify('-5 years'),
        'Since Inception' => $firstDate,
    ];
    // Trailing windows run from the close of their anchor date, so the anchor
    // day's own return belongs to the previous window. MTD, YTD and since
    // inception start on a calendar boundary whose first day is included.
    $trailingWindowLabels = ['3 Months', '6 Months', '1 Year', '3 Year', '5 Year'];
    $firstIncludedDates = [];
    foreach ($periods as $label => $startDate) {
        $firstIncludedDates[$label] = in_array($label, $trailingWindowLabels, true)
            ? $startDate->modify('+1 day')->format('Y-m-d')
            : $startDate->format('Y-m-d');
    }

    $portfolioIndex = buildGrowthIndex($dailyRows, 'daily_return');
    $benchmarkIndex = buildGrowthIndex($dailyRows, 'benchmark_daily_return');
    $trailingReturns = [];
    foreach ($periods as $label => $startDate) {
        $requireFullHistory = $label !== 'Since Inception';
        $start = $startDate->format('Y-m-d');
        $portfolioCumulative = periodReturn($portfolioIndex, $start, $firstIncludedDates[$label], $requireFullHistory);
        $benchmarkCumulative = periodReturn($benchmarkIndex, $start, $firstIncludedDates[$label], $requireFullHistory);
        $portfolioPresented = returnForPresentation($portfolioCumulative, $startDate, $lastDate);
        $benchmarkPresented = returnForPresentation($benchmarkCumulative, $startDate, $lastDate);

        $trailingReturns[$label] = [
            'portfolio' => $portfolioPresented,
            'benchmark' => $benchmarkPresented,
            'active' => ($portfolioPresented !== null && $benchmarkPresented !== null)
                ? $portfolioPresented - $benchmarkPresented
                : null,
            'convention' => returnConventionLabel($startDate, $lastDate),
        ];
    }

    $monthlyPeriodReturns = bucketPeriodReturns($dailyRows, 'month');
    $quarterlyPeriodReturns = bucketPeriodReturns($dailyRows, 'quarter');
    $advancedMetrics = calculateAdvancedMetrics($dailyRows, $monthlyPeriodReturns, $riskFreeAnnual);

    $periodWindows = [];
    foreach ($periods as $label => $startDate) {
        $periodWindows[$label] = [
            'start' => $startDate->format('Y-m-d'),
            'first_included' => $firstIncludedDates[$label],
            'require_full_history' => $label !== 'Since Inception',
        ];
    }
    $activeContributionPeriods = linkedActiveContributionsByPeriod($dailyRows, $periodWindows);

    $monthlyReturns = [];
    $annual = [];
    $annualBenchmark = [];
    foreach ($dailyRows as $row) {
        if ($row['daily_return'] !== null && is_finite((float)$row['daily_return'])) {
            $month = substr($row['date'], 0, 7);
            $year = substr($row['date'], 0, 4);
            $monthlyReturns[$month][] = (float)$row['daily_return'];
            $annual[$year][] = (float)$row['daily_return'];
        }
        if ($row['benchmark_daily_return'] !== null && is_finite((float)$row['benchmark_daily_return'])) {
            $year = substr($row['date'], 0, 4);
            $annualBenchmark[$year][] = (float)$row['benchmark_daily_return'];
        }
    }
    $monthlyReturns = array_map('chainReturns', $monthlyReturns);
    $annualReturns = array_map('chainReturns', $annual);
    $annualBenchmarkReturns = array_map('chainReturns', $annualBenchmark);
    $annualActiveReturns = [];
    foreach ($annualReturns as $year => $return) {
        $annualActiveReturns[$year] = isset($annualBenchmarkReturns[$year])
            ? $return - $annualBenchmarkReturns[$year]
            : null;
    }
    krsort($monthlyReturns);
    krsort($annualReturns);
    krsort($annualBenchmarkReturns);
    krsort($annualActiveReturns);

    return [
        'daily_rows' => $dailyRows,
        'monthly_returns' => $monthlyReturns,
        'annual_returns' => $annualReturns,
        'annual_benchmark_returns' => $annualBenchmarkReturns,
        'annual_active_returns' => $annualActiveReturns,
        'monthly_period_returns' => $monthlyPeriodReturns,
        'quarterly_period_returns' => $quarterlyPeriodReturns,
        'trailing_returns' => $trailingReturns,
        'advanced_metrics' => $advancedMetrics,
        'active_contribution_periods' => $activeContributionPeriods,
        'metrics' => [
            'ending_value' => (float)$endingRow['account_value'],
            'ending_market_value' => (float)$endingRow['market_value'],
            'ending_cash' => (float)$endingRow['cash'],
            'total_return' => $totalReturn,
            'since_inception_return' => $sinceInceptionReturn,
            'since_inception_convention' => $sinceInceptionConvention,
            'annualized_volatility' => $annualizedVolatility,
            'max_drawdown' => $maxDrawdown,
            'net_external_flow' => $netExternalFlow,
            'benchmark_total_return' => $benchmarkTotalReturn,
            'benchmark_since_inception_return' => $benchmarkSinceInceptionReturn,
            'active_since_inception_return' => $activeSinceInceptionReturn,
        ],
        'reconciliation' => [
            'opening_cash' => $openingCash,
            'opening_positions' => count(array_filter($openingShares, static fn($quantity) => abs($quantity) > EPSILON)),
            'ending_share_differences' => $shareReconciliation,
            'ending_cash_difference' => $cashDifference,
            'passed' => $shareReconciliation === [] && abs($cashDifference) <= 0.01,
        ],
        'warnings' => array_values(array_unique($warnings)),
        'start_date' => $dailyRows[0]['date'],
        'end_date' => $dailyRows[array_key_last($dailyRows)]['date'],
        'benchmark' => $benchmarkTicker,
        'external_actions' => $externalActions,
        'transaction_count' => count($transactions),
        'current_position_count' => count($positions['shares']),
    ];
}

// -----------------------------------------------------------------------------
// Request handling
// -----------------------------------------------------------------------------

$errors = [];
$notices = [];
$result = null;
$positionsSummary = null;
$transactionsSummary = null;
$savedDatasets = [];
$loadedDatasetName = null;
$currentUserId = currentUserStorageId();
$csrf = csrfToken();

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$formAction = (string)($_POST['form_action'] ?? 'calculate');
$defaultFilesExist = is_file($dataDir . DIRECTORY_SEPARATOR . DEFAULT_POSITIONS_FILE)
    && is_file($dataDir . DIRECTORY_SEPARATOR . DEFAULT_TRANSACTIONS_FILE);

$positionsFilename = uploadedOrDefaultName('positions_csv', DEFAULT_POSITIONS_FILE);
$transactionsFilename = uploadedOrDefaultName('transactions_csv', DEFAULT_TRANSACTIONS_FILE);
$inferredAsOf = inferAsOfDate($positionsFilename, $transactionsFilename) ?: date('Y-m-d');

$asOfDate = trim((string)($_POST['as_of_date'] ?? $inferredAsOf));
$reportStartDate = trim((string)($_POST['report_start_date'] ?? ''));
$benchmarkTicker = normalizeTicker((string)($_POST['benchmark_ticker'] ?? DEFAULT_BENCHMARK));
$riskFreeRatePct = (float)($_POST['risk_free_rate'] ?? 0.0);
$riskFreeAnnual = $riskFreeRatePct / 100.0;
$externalActionsText = trim((string)($_POST['external_actions'] ?? 'MoneyLink Transfer'));
$externalActions = array_values(array_filter(array_map('trim', preg_split('/[,\r\n]+/', $externalActionsText) ?: [])));
$saveDatasetName = trim((string)($_POST['save_dataset_name'] ?? ''));

$pdo = null;
$priceSchema = null;
try {
    $pdo = connectDatabase($dbDsn, $dbUser, $dbPassword);
    $savedDatasets = listUserDatasets($pdo, $currentUserId);
} catch (Throwable $exception) {
    error_log('PortfolioSight TWR database setup: ' . $exception);
    $errors[] = 'Could not connect to the database. Details were written to the server log.';
}

if ($isPost && $formAction === 'delete_saved' && $pdo !== null) {
    try {
        requireValidCsrf();
        $savedId = (int)($_POST['saved_dataset_id'] ?? 0);
        if ($savedId <= 0) {
            throw new RuntimeException('Choose a saved dataset to delete.');
        }
        deleteUserDataset($pdo, $currentUserId, $savedId);
        $savedDatasets = listUserDatasets($pdo, $currentUserId);
        $notices[] = 'Saved dataset deleted.';
    } catch (Throwable $exception) {
        $errors[] = userFacingError($exception);
    }
}

$shouldRun = false;
$saveAfterCalculation = false;

if ($isPost && $formAction === 'load_saved' && $pdo !== null) {
    try {
        requireValidCsrf();
        $savedId = (int)($_POST['saved_dataset_id'] ?? 0);
        if ($savedId <= 0) {
            throw new RuntimeException('Choose a saved dataset to load.');
        }

        $saved = loadUserDataset($pdo, $currentUserId, $savedId);
        $positionsSummary = $saved['positions_summary'];
        $transactionsSummary = $saved['transactions_summary'];
        $loadedDatasetName = (string)$saved['dataset_name'];
        $positionsFilename = (string)($saved['positions_filename'] ?: 'Saved positions');
        $transactionsFilename = (string)($saved['transactions_filename'] ?: 'Saved transactions');
        $asOfDate = (string)$saved['as_of_date'];
        $reportStartDate = (string)($saved['report_start_date'] ?? '');
        $benchmarkTicker = normalizeTicker((string)$saved['benchmark_ticker']);
        $riskFreeRatePct = (float)$saved['risk_free_rate_pct'];
        $riskFreeAnnual = $riskFreeRatePct / 100.0;
        $externalActionsText = (string)$saved['external_actions'];
        $externalActions = array_values(array_filter(array_map('trim', preg_split('/[,\r\n]+/', $externalActionsText) ?: [])));
        $shouldRun = true;
        $notices[] = 'Loaded saved dataset: ' . $loadedDatasetName;
    } catch (Throwable $exception) {
        $errors[] = userFacingError($exception);
    }
} elseif ($formAction !== 'delete_saved') {
    $shouldRun = $isPost && in_array($formAction, ['calculate', 'calculate_save'], true);
    $saveAfterCalculation = $isPost && $formAction === 'calculate_save';
}

if ($shouldRun && $pdo !== null) {
    try {
        requireValidCsrf();
        $asOf = DateTimeImmutable::createFromFormat('!Y-m-d', $asOfDate);
        if ($asOf === false) {
            throw new RuntimeException('Enter a valid as-of date.');
        }
        if ($reportStartDate !== '' && DateTimeImmutable::createFromFormat('!Y-m-d', $reportStartDate) === false) {
            throw new RuntimeException('Enter a valid report start date.');
        }

        if ($positionsSummary === null || $transactionsSummary === null) {
            $positionsPath = uploadedOrDefaultPath('positions_csv', DEFAULT_POSITIONS_FILE, $dataDir);
            $transactionsPath = uploadedOrDefaultPath('transactions_csv', DEFAULT_TRANSACTIONS_FILE, $dataDir);

            if ($positionsPath === null || $transactionsPath === null) {
                throw new RuntimeException('Upload both the current positions CSV and the transactions CSV, or load a saved dataset.');
            }

            $positionsSummary = parsePositions($positionsPath);
            $transactionsSummary = parseTransactions($transactionsPath);
        }

        $priceSchema = resolvePriceSchema($pdo);
        $result = calculatePerformance(
            $positionsSummary,
            $transactionsSummary,
            $pdo,
            $priceSchema,
            $asOfDate,
            $reportStartDate !== '' ? $reportStartDate : null,
            $externalActions,
            $benchmarkTicker,
            $riskFreeAnnual
        );

        if ($saveAfterCalculation) {
            ensureUserTransactionsTable($pdo);
            $savedId = saveUserDataset(
                $pdo,
                $currentUserId,
                $saveDatasetName,
                $asOfDate,
                $reportStartDate,
                $benchmarkTicker,
                $riskFreeRatePct,
                $externalActionsText,
                $positionsFilename,
                $transactionsFilename,
                $positionsSummary,
                $transactionsSummary
            );
            $savedDatasets = listUserDatasets($pdo, $currentUserId);
            $notices[] = 'Saved this upload for later as dataset #' . $savedId . '.';
        }
    } catch (Throwable $exception) {
        $errors[] = userFacingError($exception);
    }
}

$chartData = null;
$dailyTableRows = [];
if ($result !== null) {
    $chartData = [
        'dates' => array_column($result['daily_rows'], 'date'),
        'portfolioGrowth' => array_map(static fn($row) => round((float)$row['growth_of_10000'], 2), $result['daily_rows']),
        'benchmarkGrowth' => array_map(static fn($row) => $row['benchmark_growth_of_10000'] !== null ? round((float)$row['benchmark_growth_of_10000'], 2) : null, $result['daily_rows']),
        'accountValues' => array_map(static fn($row) => round((float)$row['account_value'], 2), $result['daily_rows']),
        'drawdowns' => array_map(static fn($row) => round((float)$row['drawdown'] * 100, 4), $result['daily_rows']),
        'monthlyActiveLabels' => array_keys($result['monthly_period_returns']),
        'monthlyActiveReturns' => array_map(
            static fn($row) => $row['active'] !== null ? round((float)$row['active'] * 100, 4) : null,
            array_values($result['monthly_period_returns'])
        ),
        'quarterlyActiveLabels' => array_keys($result['quarterly_period_returns']),
        'quarterlyActiveReturns' => array_map(
            static fn($row) => $row['active'] !== null ? round((float)$row['active'] * 100, 4) : null,
            array_values($result['quarterly_period_returns'])
        ),
    ];
    // Only the columns the CSV download needs; the per-holding daily maps stay server-side.
    $csvRows = array_map(static fn($row) => [
        $row['date'],
        round((float)$row['account_value'], 2),
        round((float)$row['market_value'], 2),
        round((float)$row['cash'], 2),
        round((float)$row['external_flow'], 2),
        $row['daily_return'],
        $row['cumulative_return'],
        $row['drawdown'],
        $row['benchmark_daily_return'],
        $row['benchmark_cumulative_return'],
    ], $result['daily_rows']);
    $dailyTableRows = array_slice(array_reverse($result['daily_rows']), 0, 250);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PortfolioSight Daily TWR</title>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <style>
        :root {
            color-scheme: dark;
            --bg: #090d14;
            --panel: #111824;
            --panel-2: #151f2e;
            --border: #263247;
            --text: #edf3fb;
            --muted: #8fa0b7;
            --accent: #56b6ff;
            --accent-2: #8b7cff;
            --positive: #38d996;
            --negative: #ff6b7a;
            --warning: #f7c45f;
            --shadow: 0 18px 45px rgba(0, 0, 0, .22);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            background:
                radial-gradient(circle at 15% 0%, rgba(86, 182, 255, .10), transparent 28rem),
                radial-gradient(circle at 95% 5%, rgba(139, 124, 255, .08), transparent 30rem),
                var(--bg);
            color: var(--text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        a { color: var(--accent); }
        .shell { min-height: 100vh; }
        .topbar {
            position: sticky;
            top: 0;
            z-index: 20;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            min-height: 66px;
            padding: 0 28px;
            background: rgba(9, 13, 20, .86);
            border-bottom: 1px solid rgba(38, 50, 71, .85);
            backdrop-filter: blur(18px);
        }
        .brand { display: flex; align-items: center; gap: 12px; font-weight: 760; }
        .brand-mark {
            display: grid;
            place-items: center;
            width: 36px;
            height: 36px;
            border-radius: 11px;
            background: linear-gradient(145deg, var(--accent), var(--accent-2));
            color: #07101a;
            font-weight: 900;
            box-shadow: 0 8px 24px rgba(86, 182, 255, .22);
        }
        .top-meta { color: var(--muted); font-size: .84rem; }

        main { width: min(1540px, calc(100% - 36px)); margin: 28px auto 60px; }
        .hero {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 24px;
            margin-bottom: 20px;
        }
        h1 { margin: 0 0 8px; font-size: clamp(1.55rem, 3vw, 2.35rem); letter-spacing: -.035em; }
        .subtitle { margin: 0; color: var(--muted); max-width: 850px; line-height: 1.55; }
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 10px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: rgba(21, 31, 46, .78);
            color: var(--muted);
            font-size: .78rem;
            white-space: nowrap;
        }
        .dot { width: 7px; height: 7px; border-radius: 50%; background: var(--positive); box-shadow: 0 0 0 4px rgba(56, 217, 150, .12); }

        .panel {
            background: linear-gradient(180deg, rgba(21, 31, 46, .96), rgba(15, 22, 33, .96));
            border: 1px solid var(--border);
            border-radius: 17px;
            box-shadow: var(--shadow);
        }
        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 17px 18px;
            border-bottom: 1px solid var(--border);
        }
        .panel-header h2 { margin: 0; font-size: 1rem; letter-spacing: -.01em; }
        .panel-body { padding: 18px; }

        .input-panel { margin-bottom: 20px; }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(130px, 1fr));
            gap: 14px;
            align-items: end;
        }
        .field { display: grid; gap: 7px; }
        .field.span-2, .form-actions.span-2 { grid-column: span 2; }
        label { color: var(--muted); font-size: .78rem; font-weight: 700; letter-spacing: .02em; }
        input[type="text"], input[type="date"], input[type="number"], input[type="file"], select {
            width: 100%;
            min-height: 42px;
            padding: 9px 11px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #0c131e;
            color: var(--text);
            outline: none;
        }
        input:focus, select:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(86, 182, 255, .12); }
        input[type="file"] { padding: 8px; color: var(--muted); }
        input::file-selector-button {
            margin-right: 10px;
            padding: 6px 9px;
            border: 0;
            border-radius: 7px;
            background: #23314a;
            color: var(--text);
            cursor: pointer;
        }
        .button {
            min-height: 42px;
            padding: 0 16px;
            border: 0;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--accent), #6d8dff);
            color: #06111c;
            font-weight: 850;
            cursor: pointer;
            box-shadow: 0 10px 25px rgba(86, 182, 255, .16);
        }
        .button.secondary {
            min-height: 36px;
            background: #23314a;
            color: var(--text);
            box-shadow: none;
        }
        .toggle-group { display: inline-flex; gap: 4px; padding: 3px; border: 1px solid var(--border); border-radius: 9px; background: #0c131e; }
        .toggle-button { min-height: 30px; padding: 0 11px; border: 0; border-radius: 6px; background: transparent; color: var(--muted); font-size: .76rem; font-weight: 800; cursor: pointer; }
        .toggle-button.active { background: #23314a; color: var(--text); }
        .convention-note { color: var(--muted); font-size: .76rem; line-height: 1.5; }
        .form-help { margin: 13px 0 0; color: var(--muted); font-size: .78rem; line-height: 1.55; }
        .form-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; }
        .saved-panel { margin-bottom: 20px; }
        .saved-count { color: var(--muted); font-size: .76rem; }
        .saved-actions { display: flex; gap: 7px; align-items: center; justify-content: flex-end; }
        .saved-actions form { margin: 0; }
        .mini-button { min-height: 32px; padding: 0 11px; border: 1px solid var(--border); border-radius: 8px; background: #172235; color: var(--text); font-weight: 750; cursor: pointer; }
        .mini-button.danger { color: #ffb5be; background: rgba(255,107,122,.07); border-color: rgba(255,107,122,.28); }
        .empty-saved { color: var(--muted); font-size: .84rem; line-height: 1.55; }
        .loaded-note { margin-top: 10px; color: #c7ffe7; font-size: .8rem; }

        .alerts { display: grid; gap: 10px; margin-bottom: 20px; }
        .alert { padding: 13px 15px; border-radius: 12px; border: 1px solid; line-height: 1.45; font-size: .86rem; }
        .alert.error { color: #ffd7dc; background: rgba(255, 107, 122, .09); border-color: rgba(255, 107, 122, .42); }
        .alert.warning { color: #ffe5ad; background: rgba(247, 196, 95, .08); border-color: rgba(247, 196, 95, .35); }
        .alert.success { color: #c7ffe7; background: rgba(56, 217, 150, .08); border-color: rgba(56, 217, 150, .32); }

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(8, minmax(130px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }
        .kpi { padding: 16px; min-height: 112px; }
        .kpi-label { color: var(--muted); font-size: .75rem; font-weight: 750; text-transform: uppercase; letter-spacing: .065em; }
        .kpi-value { margin-top: 11px; font-size: 1.35rem; font-weight: 820; letter-spacing: -.035em; }
        .kpi-note { margin-top: 7px; color: var(--muted); font-size: .74rem; }
        .positive { color: var(--positive); }
        .negative { color: var(--negative); }

        .chart-grid { display: grid; grid-template-columns: 1.5fr 1fr; gap: 16px; margin-bottom: 16px; }
        .chart { min-height: 360px; }
        .chart.small { min-height: 280px; }

        .content-grid { display: grid; grid-template-columns: 1.35fr .65fr; gap: 16px; margin-bottom: 16px; }
        .stack { display: grid; gap: 16px; }

        .table-wrap { overflow: auto; max-height: 560px; }
        table { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
        th, td { padding: 10px 12px; border-bottom: 1px solid rgba(38, 50, 71, .76); text-align: right; white-space: nowrap; }
        th { position: sticky; top: 0; z-index: 2; background: #141d2b; color: var(--muted); font-size: .72rem; text-transform: uppercase; letter-spacing: .055em; }
        th:first-child, td:first-child { text-align: left; }
        td { font-size: .82rem; }
        tr:hover td { background: rgba(86, 182, 255, .035); }

        .metric-grid { display: grid; grid-template-columns: repeat(4, minmax(150px, 1fr)); gap: 10px; }
        .metric-card { padding: 14px; border: 1px solid var(--border); border-radius: 11px; background: rgba(9, 13, 20, .32); }
        .metric-card span { color: var(--muted); font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; font-weight: 750; }
        .metric-card strong { display: block; margin-top: 7px; font-size: 1.12rem; font-variant-numeric: tabular-nums; }
        .metric-card small { display: block; margin-top: 5px; color: var(--muted); line-height: 1.35; }
        .contributor-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .contributor-list { display: grid; gap: 7px; }
        .contributor-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 12px; padding: 10px 11px; border: 1px solid var(--border); border-radius: 9px; background: rgba(9, 13, 20, .25); align-items: center; }
        .contributor-name { min-width: 0; }
        .contributor-name > span { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .contributor-name > small { display: block; margin-top: 3px; color: var(--muted); font-size: .68rem; }
        .contribution-detail { margin-top: 18px; }
        .contribution-detail-heading { display: flex; align-items: end; justify-content: space-between; gap: 12px; margin-bottom: 9px; }
        .contribution-detail-heading h3 { margin: 0; font-size: .92rem; }
        .contribution-detail-heading span { color: var(--muted); font-size: .72rem; }
        .contribution-detail .table-wrap { max-height: 430px; border: 1px solid var(--border); border-radius: 10px; }
        .contribution-detail td:first-child { font-weight: 720; }
        .definition-callout { margin: 0 0 14px; padding: 11px 13px; border: 1px solid rgba(86,182,255,.28); border-radius: 10px; background: rgba(86,182,255,.055); color: #c9d8ea; font-size: .79rem; line-height: 1.5; }
        .definition-callout strong { color: var(--text); }
        .mini-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
        .mini-card { padding: 13px; border: 1px solid var(--border); border-radius: 11px; background: rgba(9, 13, 20, .32); }
        .mini-card strong { display: block; margin-top: 6px; font-size: 1.03rem; }
        .mini-card span { color: var(--muted); font-size: .73rem; }

        details { border-top: 1px solid var(--border); }
        summary { cursor: pointer; padding: 16px 18px; color: var(--text); font-weight: 750; }
        .methodology { padding: 0 18px 18px; color: var(--muted); line-height: 1.65; font-size: .85rem; }
        code { color: #b9dfff; background: #0c131e; border: 1px solid var(--border); padding: 2px 5px; border-radius: 5px; }

        .empty { padding: 42px 18px; text-align: center; color: var(--muted); }

        @media (max-width: 1250px) {
            .form-grid { grid-template-columns: repeat(3, 1fr); }
            .kpi-grid { grid-template-columns: repeat(4, 1fr); }
            .metric-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 900px) {
            main { width: min(100% - 24px, 1540px); }
            .topbar { padding: 0 16px; }
            .hero { flex-direction: column; }
            .chart-grid, .content-grid, .contributor-grid { grid-template-columns: 1fr; }
            .form-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 620px) {
            .form-grid, .kpi-grid, .mini-grid, .metric-grid { grid-template-columns: 1fr; }
            .field.span-2 { grid-column: span 1; }
            .top-meta { display: none; }
        }
    </style>
</head>
<body>
<div class="shell">
    <header class="topbar">
        <div class="brand"><span class="brand-mark">PS</span><span>PortfolioSight</span></div>
        <div class="top-meta">Daily time-weighted portfolio performance</div>
    </header>

    <main>
        <section class="hero">
            <div>
                <h1>Daily Time-Weighted Return</h1>
                <p class="subtitle">Reconstructs the historical portfolio from the current holdings anchor and brokerage transaction ledger, then values each market day with raw closes from PostgreSQL.</p>
            </div>
            <span class="pill"><span class="dot"></span> Raw prices · transaction-driven splits</span>
        </section>

        <section class="panel input-panel">
            <div class="panel-header"><h2>Data and methodology settings</h2></div>
            <div class="panel-body">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <div class="form-grid">
                        <div class="field span-2">
                            <label for="positions_csv">Current positions CSV</label>
                            <input id="positions_csv" name="positions_csv" type="file" accept=".csv,text/csv">
                        </div>
                        <div class="field span-2">
                            <label for="transactions_csv">Transactions CSV</label>
                            <input id="transactions_csv" name="transactions_csv" type="file" accept=".csv,text/csv">
                        </div>
                        <div class="field">
                            <label for="as_of_date">Positions as-of date</label>
                            <input id="as_of_date" name="as_of_date" type="date" value="<?= h($asOfDate) ?>" required>
                        </div>
                        <div class="field">
                            <label for="report_start_date">Report start (optional)</label>
                            <input id="report_start_date" name="report_start_date" type="date" value="<?= h($reportStartDate) ?>">
                        </div>
                        <div class="field">
                            <label for="benchmark_ticker">Benchmark ticker</label>
                            <input id="benchmark_ticker" name="benchmark_ticker" type="text" value="<?= h($benchmarkTicker) ?>" maxlength="16">
                        </div>
                        <div class="field">
                            <label for="risk_free_rate">Risk-free rate (annual %)</label>
                            <input id="risk_free_rate" name="risk_free_rate" type="number" step="0.01" value="<?= h(number_format($riskFreeRatePct, 2, '.', '')) ?>">
                        </div>
                        <div class="field span-2">
                            <label for="external_actions">External cash-flow actions</label>
                            <input id="external_actions" name="external_actions" type="text" value="<?= h($externalActionsText) ?>">
                        </div>
                        <div class="field span-2">
                            <label for="save_dataset_name">Saved dataset name (optional)</label>
                            <input id="save_dataset_name" name="save_dataset_name" type="text" value="<?= h($saveDatasetName) ?>" maxlength="120" placeholder="e.g. Schwab 592 — Sep 10, 2026">
                        </div>
                        <div class="form-actions span-2">
                            <button class="button" type="submit" name="form_action" value="calculate">Calculate returns</button>
                            <button class="button secondary" type="submit" name="form_action" value="calculate_save">Calculate &amp; save</button>
                        </div>
                    </div>
                    <p class="form-help">
                        <strong>Calculate &amp; save</strong> stores the parsed positions, transaction history, and methodology settings in PostgreSQL so the same dataset can be reopened without uploading the CSVs again. Default external-flow treatment is <strong>MoneyLink Transfer only</strong>.
                        <?php if ($defaultFilesExist): ?> The two default CSV files were found in the data directory and are used for any file you don't upload.<?php endif; ?>
                    </p>
                    <?php if ($loadedDatasetName !== null): ?>
                        <div class="loaded-note">Currently viewing saved dataset: <strong><?= h($loadedDatasetName) ?></strong></div>
                    <?php endif; ?>
                </form>
            </div>
        </section>

        <section class="panel saved-panel">
            <div class="panel-header">
                <h2>Saved portfolio datasets</h2>
                <span class="saved-count"><?= h(count($savedDatasets)) ?> saved</span>
            </div>
            <div class="panel-body">
                <?php if ($savedDatasets === []): ?>
                    <div class="empty-saved">No saved datasets yet. Upload the positions and transactions files above, then choose <strong>Calculate &amp; save</strong>.</div>
                <?php else: ?>
                    <div class="table-wrap" style="max-height: 320px;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Dataset</th>
                                    <th>As of</th>
                                    <th>Holdings</th>
                                    <th>Transactions</th>
                                    <th>Benchmark</th>
                                    <th>Saved</th>
                                    <th style="text-align:right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($savedDatasets as $savedRow): ?>
                                <tr>
                                    <td><strong><?= h($savedRow['dataset_name']) ?></strong></td>
                                    <td><?= h($savedRow['as_of_date']) ?></td>
                                    <td><?= h(number_format((int)$savedRow['position_count'])) ?></td>
                                    <td><?= h(number_format((int)$savedRow['transaction_count'])) ?></td>
                                    <td><?= h($savedRow['benchmark_ticker']) ?></td>
                                    <td><?= h((new DateTimeImmutable((string)$savedRow['created_at']))->format('M j, Y g:i A')) ?></td>
                                    <td>
                                        <div class="saved-actions">
                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                                <input type="hidden" name="saved_dataset_id" value="<?= h($savedRow['id']) ?>">
                                                <button class="mini-button" type="submit" name="form_action" value="load_saved">View</button>
                                            </form>
                                            <form method="post" onsubmit="return confirm('Delete this saved dataset?');">
                                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                                <input type="hidden" name="saved_dataset_id" value="<?= h($savedRow['id']) ?>">
                                                <button class="mini-button danger" type="submit" name="form_action" value="delete_saved">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <p class="form-help">Saved rows are scoped to the current PortfolioSight user when site authentication is available. In standalone mode, the page uses a persistent browser identifier.</p>
            </div>
        </section>

        <?php if ($notices !== []): ?>
            <div class="alerts">
                <?php foreach ($notices as $notice): ?>
                    <div class="alert success"><?= h($notice) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="alerts">
                <?php foreach ($errors as $error): ?>
                    <div class="alert error"><?= h($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($result !== null): ?>
            <div class="alerts">
                <?php if ($result['reconciliation']['passed']): ?>
                    <div class="alert success">Ledger reconciliation passed: the forward replay returns to the uploaded ending shares and cash balance.</div>
                <?php endif; ?>
                <?php foreach ($result['warnings'] as $warning): ?>
                    <div class="alert warning"><?= h($warning) ?></div>
                <?php endforeach; ?>
            </div>

            <?php
                $metrics = $result['metrics'];
                $returnClass = static fn(?float $value) => ($value ?? 0) >= 0 ? 'positive' : 'negative';
            ?>
            <section class="kpi-grid">
                <article class="panel kpi">
                    <div class="kpi-label">Ending Value</div>
                    <div class="kpi-value"><?= h(formatMoney($metrics['ending_value'])) ?></div>
                    <div class="kpi-note"><?= h($result['end_date']) ?></div>
                </article>
                <article class="panel kpi">
                    <div class="kpi-label">Portfolio Return</div>
                    <div class="kpi-value <?= $returnClass($metrics['since_inception_return']) ?>"><?= h(formatPercent($metrics['since_inception_return'])) ?></div>
                    <div class="kpi-note"><?= h($metrics['since_inception_convention']) ?> since inception</div>
                </article>
                <article class="panel kpi">
                    <div class="kpi-label"><?= h($result['benchmark'] ?: 'Benchmark') ?> Return</div>
                    <div class="kpi-value <?= $returnClass($metrics['benchmark_since_inception_return']) ?>"><?= h(formatPercent($metrics['benchmark_since_inception_return'])) ?></div>
                    <div class="kpi-note"><?= h($metrics['since_inception_convention']) ?> since inception</div>
                </article>
                <article class="panel kpi">
                    <div class="kpi-label">Active Return</div>
                    <div class="kpi-value <?= $returnClass($metrics['active_since_inception_return']) ?>"><?= h(formatPercent($metrics['active_since_inception_return'])) ?></div>
                    <div class="kpi-note">Portfolio minus benchmark · <?= h(strtolower($metrics['since_inception_convention'])) ?></div>
                </article>
                <article class="panel kpi">
                    <div class="kpi-label">Annualized Volatility</div>
                    <div class="kpi-value"><?= h(formatPercent($metrics['annualized_volatility'])) ?></div>
                    <div class="kpi-note">Daily standard deviation × √252</div>
                </article>
                <article class="panel kpi">
                    <div class="kpi-label">Max Drawdown</div>
                    <div class="kpi-value negative"><?= h(formatPercent($metrics['max_drawdown'])) ?></div>
                    <div class="kpi-note">Peak-to-trough TWR index</div>
                </article>
                <article class="panel kpi">
                    <div class="kpi-label">Net External Flow</div>
                    <div class="kpi-value"><?= h(formatMoney($metrics['net_external_flow'])) ?></div>
                    <div class="kpi-note"><?= h(implode(', ', $result['external_actions'])) ?></div>
                </article>
                <article class="panel kpi">
                    <div class="kpi-label">History</div>
                    <div class="kpi-value"><?= h(number_format((int)(new DateTimeImmutable($result['start_date']))->diff(new DateTimeImmutable($result['end_date']))->format('%a') / 365.25, 1)) ?> yr</div>
                    <div class="kpi-note"><?= h($result['start_date']) ?> to <?= h($result['end_date']) ?></div>
                </article>
            </section>

            <section class="chart-grid">
                <article class="panel">
                    <div class="panel-header"><h2>Growth of $10,000</h2></div>
                    <div class="panel-body"><div id="growthChart" class="chart"></div></div>
                </article>
                <article class="panel">
                    <div class="panel-header"><h2>Account Value</h2></div>
                    <div class="panel-body"><div id="valueChart" class="chart"></div></div>
                </article>
            </section>

            <section class="panel" style="margin-bottom: 16px;">
                <div class="panel-header">
                    <div>
                        <h2>Active Return by Period</h2>
                        <div class="convention-note">Portfolio return minus <?= h($result['benchmark'] ?: 'benchmark') ?> return for each discrete period.</div>
                    </div>
                    <div class="toggle-group" aria-label="Active return period">
                        <button type="button" class="toggle-button active" id="activeMonthly">Monthly</button>
                        <button type="button" class="toggle-button" id="activeQuarterly">Quarterly</button>
                    </div>
                </div>
                <div class="panel-body"><div id="activeReturnChart" class="chart small"></div></div>
            </section>

            <?php $advanced = $result['advanced_metrics']; ?>
            <section class="panel" style="margin-bottom: 16px;">
                <div class="panel-header">
                    <div>
                        <h2>Risk & benchmark analytics</h2>
                        <div class="convention-note">Risk-adjusted metrics use a <?= h(number_format($advanced['risk_free_rate'] * 100, 2)) ?>% annual risk-free rate. VaR/CVaR are historical one-day loss estimates.</div>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="metric-grid">
                        <div class="metric-card"><span>Sharpe Ratio</span><strong><?= $advanced['sharpe_ratio'] !== null ? h(number_format($advanced['sharpe_ratio'], 2)) : '—' ?></strong><small>Excess return per unit of total volatility</small></div>
                        <div class="metric-card"><span>Sortino Ratio</span><strong><?= $advanced['sortino_ratio'] !== null ? h(number_format($advanced['sortino_ratio'], 2)) : '—' ?></strong><small>Excess return per unit of downside risk</small></div>
                        <div class="metric-card"><span>Calmar Ratio</span><strong><?= $advanced['calmar_ratio'] !== null ? h(number_format($advanced['calmar_ratio'], 2)) : '—' ?></strong><small>Annualized return ÷ max drawdown</small></div>
                        <div class="metric-card"><span>Downside Deviation</span><strong><?= h(formatPercent($advanced['downside_deviation'])) ?></strong><small>Annualized downside volatility</small></div>
                        <div class="metric-card"><span>Annualized Alpha</span><strong class="<?= $returnClass($advanced['alpha']) ?>"><?= h(formatPercent($advanced['alpha'])) ?></strong><small>Jensen alpha vs <?= h($result['benchmark'] ?: 'benchmark') ?></small></div>
                        <div class="metric-card"><span>Beta</span><strong><?= $advanced['beta'] !== null ? h(number_format($advanced['beta'], 2)) : '—' ?></strong><small>Sensitivity to benchmark daily returns</small></div>
                        <div class="metric-card"><span>Correlation</span><strong><?= $advanced['correlation'] !== null ? h(number_format($advanced['correlation'], 2)) : '—' ?></strong><small>Daily return correlation with benchmark</small></div>
                        <div class="metric-card"><span>Tracking Error</span><strong><?= h(formatPercent($advanced['tracking_error'])) ?></strong><small>Annualized volatility of active returns</small></div>
                        <div class="metric-card"><span>Information Ratio</span><strong><?= $advanced['information_ratio'] !== null ? h(number_format($advanced['information_ratio'], 2)) : '—' ?></strong><small>Active return per unit of tracking error</small></div>
                        <div class="metric-card"><span>95% Daily VaR</span><strong><?= h(formatPercent($advanced['var_95_daily'])) ?></strong><small>Historical 5th-percentile daily loss</small></div>
                        <div class="metric-card"><span>95% Daily CVaR</span><strong><?= h(formatPercent($advanced['cvar_95_daily'])) ?></strong><small>Average loss in the worst 5% of days</small></div>
                        <div class="metric-card"><span>Longest Drawdown</span><strong><?= h(number_format($advanced['longest_drawdown_days'])) ?> days</strong><small>Calendar days below the prior TWR peak</small></div>
                        <div class="metric-card"><span>Best Month</span><strong class="positive"><?= $advanced['best_month'] ? h(formatPercent($advanced['best_month']['return'])) : '—' ?></strong><small><?= $advanced['best_month'] ? h($advanced['best_month']['period']) : 'No data' ?></small></div>
                        <div class="metric-card"><span>Worst Month</span><strong class="negative"><?= $advanced['worst_month'] ? h(formatPercent($advanced['worst_month']['return'])) : '—' ?></strong><small><?= $advanced['worst_month'] ? h($advanced['worst_month']['period']) : 'No data' ?></small></div>
                        <div class="metric-card"><span>Positive Months</span><strong><?= h(formatPercent($advanced['positive_month_rate'])) ?></strong><small>Share of months with positive portfolio return</small></div>
                        <div class="metric-card"><span>Beat Benchmark</span><strong><?= h(formatPercent($advanced['benchmark_beat_month_rate'])) ?></strong><small>Share of comparable months beating benchmark</small></div>
                    </div>
                </div>
            </section>

            <section class="panel" style="margin-bottom: 16px;">
                <div class="panel-header">
                    <div>
                        <h2>What drove outperformance / underperformance?</h2>
                        <div class="convention-note">Holding-level contribution to portfolio active return versus <?= h($result['benchmark'] ?: 'the benchmark') ?>. <strong>pp = percentage points.</strong></div>
                    </div>
                    <div class="toggle-group" id="contributionPeriodToggle" aria-label="Contribution period">
                        <?php foreach ($result['active_contribution_periods'] as $label => $periodData): ?>
                            <?php if ($periodData !== null): ?>
                                <button type="button" class="toggle-button contribution-period<?= $label === 'YTD' ? ' active' : '' ?>" data-period="<?= h($label) ?>"><?= h($label) ?></button>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="definition-callout">
                        <strong>How to read this:</strong> if AMD shows <strong>+9.61 pp</strong>, that means your ownership of AMD added approximately <strong>9.61 percentage points to the portfolio's return relative to <?= h($result['benchmark'] ?: 'the benchmark') ?></strong> over the selected period. It does <em>not</em> mean AMD itself outperformed the benchmark by 9.61%.
                    </div>
                    <div id="contributionSummary" class="convention-note" style="margin-bottom: 12px;"></div>
                    <div id="activeContributionChart" class="chart small"></div>
                    <div class="contributor-grid" style="margin-top: 14px;">
                        <div>
                            <div class="kpi-label" style="margin-bottom: 8px;">Top active contributors vs <?= h($result['benchmark'] ?: 'benchmark') ?></div>
                            <div id="topContributors" class="contributor-list"></div>
                        </div>
                        <div>
                            <div class="kpi-label" style="margin-bottom: 8px;">Top active detractors vs <?= h($result['benchmark'] ?: 'benchmark') ?></div>
                            <div id="topDetractors" class="contributor-list"></div>
                        </div>
                    </div>

                    <div class="contribution-detail">
                        <div class="contribution-detail-heading">
                            <h3>Why each holding helped or hurt</h3>
                            <span id="contributionDetailPeriod"></span>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                <tr>
                                    <th>Holding</th>
                                    <th>Avg Weight</th>
                                    <th>Holding Return*</th>
                                    <th><?= h($result['benchmark'] ?: 'Benchmark') ?> While Held*</th>
                                    <th>Excess Return*</th>
                                    <th>Days Exposed</th>
                                    <th>Active Contribution</th>
                                </tr>
                                </thead>
                                <tbody id="contributionDetailBody"></tbody>
                            </table>
                        </div>
                        <div class="convention-note" style="margin-top: 9px;">
                            * Holding, benchmark-while-held, and excess returns are <strong>cumulative over market days with beginning-of-day exposure</strong>. Avg Weight is the average beginning-of-day portfolio weight across the full selected period, so time spent not owning the security counts as 0%. Active Contribution is the linked portfolio effect and will generally <strong>not</strong> equal Avg Weight × Excess Return because weights change through time and daily effects compound.
                        </div>
                    </div>
                </div>
            </section>

            <section class="content-grid">
                <div class="stack">
                    <article class="panel">
                        <div class="panel-header">
                            <h2>Daily performance</h2>
                            <button class="button secondary" type="button" id="downloadCsv">Download daily CSV</button>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Account Value</th>
                                    <th>Market Value</th>
                                    <th>Cash</th>
                                    <th>External Flow</th>
                                    <th>Daily TWR</th>
                                    <th>Cumulative</th>
                                    <th>Drawdown</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($dailyTableRows as $row): ?>
                                    <tr>
                                        <td><?= h($row['date']) ?></td>
                                        <td><?= h(formatMoney((float)$row['account_value'])) ?></td>
                                        <td><?= h(formatMoney((float)$row['market_value'])) ?></td>
                                        <td><?= h(formatMoney((float)$row['cash'])) ?></td>
                                        <td><?= h(formatMoney((float)$row['external_flow'])) ?></td>
                                        <td class="<?= $returnClass($row['daily_return']) ?>"><?= h(formatPercent($row['daily_return'], 4)) ?></td>
                                        <td class="<?= $returnClass($row['cumulative_return']) ?>"><?= h(formatPercent($row['cumulative_return'])) ?></td>
                                        <td class="negative"><?= h(formatPercent($row['drawdown'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </article>

                    <article class="panel">
                        <div class="panel-header"><h2>Drawdown</h2></div>
                        <div class="panel-body"><div id="drawdownChart" class="chart small"></div></div>
                    </article>
                </div>

                <aside class="stack">
                    <article class="panel">
                        <div class="panel-header">
                            <div>
                                <h2>Trailing returns</h2>
                                <div class="convention-note">Periods under 1 year are cumulative; periods of 1 year or longer are annualized.</div>
                            </div>
                        </div>
                        <div class="table-wrap" style="max-height: 330px;">
                            <table>
                                <thead><tr><th>Period</th><th>Portfolio</th><th><?= h($result['benchmark'] ?: 'Benchmark') ?></th><th>Active</th><th>Basis</th></tr></thead>
                                <tbody>
                                <?php foreach ($result['trailing_returns'] as $label => $return): ?>
                                    <tr>
                                        <td><?= h($label) ?></td>
                                        <td class="<?= $returnClass($return['portfolio']) ?>"><?= h(formatPercent($return['portfolio'])) ?></td>
                                        <td class="<?= $returnClass($return['benchmark']) ?>"><?= h(formatPercent($return['benchmark'])) ?></td>
                                        <td class="<?= $returnClass($return['active']) ?>"><?= h(formatPercent($return['active'])) ?></td>
                                        <td><?= h($return['convention']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </article>

                    <article class="panel">
                        <div class="panel-header"><h2>Calendar-year returns</h2></div>
                        <div class="table-wrap" style="max-height: 290px;">
                            <table>
                                <thead><tr><th>Year</th><th>Portfolio</th><th><?= h($result['benchmark'] ?: 'Benchmark') ?></th><th>Active</th></tr></thead>
                                <tbody>
                                <?php foreach ($result['annual_returns'] as $year => $return): ?>
                                    <?php $benchmarkYear = $result['annual_benchmark_returns'][$year] ?? null; $activeYear = $result['annual_active_returns'][$year] ?? null; ?>
                                    <tr>
                                        <td><?= h($year) ?></td>
                                        <td class="<?= $returnClass($return) ?>"><?= h(formatPercent($return)) ?></td>
                                        <td class="<?= $returnClass($benchmarkYear) ?>"><?= h(formatPercent($benchmarkYear)) ?></td>
                                        <td class="<?= $returnClass($activeYear) ?>"><?= h(formatPercent($activeYear)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </article>

                    <article class="panel">
                        <div class="panel-header"><h2>Monthly returns</h2></div>
                        <div class="table-wrap" style="max-height: 390px;">
                            <table>
                                <thead><tr><th>Month</th><th>Return</th></tr></thead>
                                <tbody>
                                <?php foreach ($result['monthly_returns'] as $month => $return): ?>
                                    <tr><td><?= h($month) ?></td><td class="<?= $returnClass($return) ?>"><?= h(formatPercent($return)) ?></td></tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </article>

                    <article class="panel">
                        <div class="panel-header"><h2>Reconciliation</h2></div>
                        <div class="panel-body">
                            <div class="mini-grid">
                                <div class="mini-card"><span>Transactions replayed</span><strong><?= h(number_format($result['transaction_count'])) ?></strong></div>
                                <div class="mini-card"><span>Current positions</span><strong><?= h(number_format($result['current_position_count'])) ?></strong></div>
                                <div class="mini-card"><span>Opening positions</span><strong><?= h(number_format($result['reconciliation']['opening_positions'])) ?></strong></div>
                                <div class="mini-card"><span>Opening cash</span><strong><?= h(formatMoney($result['reconciliation']['opening_cash'])) ?></strong></div>
                            </div>
                        </div>
                        <details>
                            <summary>Methodology details</summary>
                            <div class="methodology">
                                <p><strong>Daily TWR:</strong> <code>((ending value − external flow) ÷ prior ending value) − 1</code>. External flows dated on non-market days are assigned to the next available market date.</p>
                                <p><strong>Return presentation:</strong> periods shorter than one year are shown cumulatively. Periods equal to or longer than one year are annualized geometrically. Active return uses the same basis as the portfolio and benchmark for each period.</p>
                                <p><strong>Active contribution:</strong> each holding receives its daily portfolio P&amp;L contribution less its beginning-of-day weight times the benchmark return. Those daily active contributions are geometrically linked through time so the displayed holding contributions sum to cumulative portfolio return minus cumulative benchmark return. Values remain cumulative percentage points even for periods whose headline return is annualized. The detail table separately derives each holding's cumulative total return from security P&amp;L divided by beginning-of-day exposure, and compares it with the benchmark over the same exposed days.</p>
                                <p><strong>Risk metrics:</strong> Sharpe, Sortino, alpha, beta, correlation, tracking error, and information ratio are estimated from daily returns. The risk-free rate is user-supplied. Historical VaR/CVaR use the empirical daily-return distribution.</p>
                                <p><strong>Ledger:</strong> every CSV amount changes cash exactly as shown. Buy and reinvest-share rows add shares; sell rows remove shares; signed merger and split quantities are applied directly. Dividends, interest, fees, and trading are internal activity.</p>
                                <p><strong>Raw prices:</strong> <code>prices_stocks.close</code> is used without historical split adjustment. Stock split continuity comes from the transaction CSV’s added shares. The optional benchmark separately applies the <code>splits</code> table and <code>dividends.cash_amount</code> when those tables are available.</p>
                                <p><strong>Missing prices:</strong> the last raw database close is carried forward. A transaction price is used only when a held symbol has no database close, primarily to avoid treating a closed option purchase as an immediate total loss.</p>
                            </div>
                        </details>
                    </article>
                </aside>
            </section>
        <?php elseif ($errors === []): ?>
            <section class="panel"><div class="empty">Upload both brokerage CSV files to calculate the daily time-weighted return history.</div></section>
        <?php endif; ?>
    </main>
</div>

<?php if ($result !== null && $chartData !== null): ?>
<script>
const chartData = <?= json_encode($chartData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;
const csvRows = <?= json_encode($csvRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;
const benchmarkName = <?= json_encode($result['benchmark'], JSON_THROW_ON_ERROR) ?>;
const activeContributionPeriods = <?= json_encode($result['active_contribution_periods'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;

const baseOptions = {
    chart: {
        background: 'transparent',
        toolbar: { show: false },
        animations: { enabled: false },
        foreColor: '#8fa0b7',
        zoom: { enabled: false }
    },
    grid: { borderColor: '#263247', strokeDashArray: 3 },
    dataLabels: { enabled: false },
    stroke: { width: 2.2, curve: 'straight' },
    xaxis: {
        type: 'datetime',
        categories: chartData.dates,
        labels: { datetimeUTC: false },
        axisBorder: { color: '#263247' },
        axisTicks: { color: '#263247' }
    },
    tooltip: { theme: 'dark', x: { format: 'MMM d, yyyy' } },
    legend: { position: 'top', horizontalAlign: 'left' },
    noData: { text: 'No data' }
};

new ApexCharts(document.querySelector('#growthChart'), {
    ...baseOptions,
    chart: { ...baseOptions.chart, type: 'line', height: 350 },
    series: [
        { name: 'Portfolio', data: chartData.portfolioGrowth },
        ...(benchmarkName ? [{ name: benchmarkName, data: chartData.benchmarkGrowth }] : [])
    ],
    yaxis: { labels: { formatter: value => '$' + value.toLocaleString(undefined, { maximumFractionDigits: 0 }) } }
}).render();

new ApexCharts(document.querySelector('#valueChart'), {
    ...baseOptions,
    chart: { ...baseOptions.chart, type: 'area', height: 350 },
    series: [{ name: 'Account value', data: chartData.accountValues }],
    fill: { type: 'gradient', gradient: { opacityFrom: .30, opacityTo: .02 } },
    yaxis: { labels: { formatter: value => '$' + value.toLocaleString(undefined, { maximumFractionDigits: 0 }) } }
}).render();

new ApexCharts(document.querySelector('#drawdownChart'), {
    ...baseOptions,
    chart: { ...baseOptions.chart, type: 'area', height: 270 },
    series: [{ name: 'Drawdown', data: chartData.drawdowns }],
    fill: { type: 'gradient', gradient: { opacityFrom: .34, opacityTo: .04 } },
    yaxis: { max: 0, labels: { formatter: value => value.toFixed(1) + '%' } },
    tooltip: { ...baseOptions.tooltip, y: { formatter: value => value.toFixed(2) + '%' } }
}).render();

const activeChartEl = document.querySelector('#activeReturnChart');
let activeReturnChart = null;

function renderActiveReturnChart(period = 'monthly') {
    if (!activeChartEl) return;

    const isQuarterly = period === 'quarterly';
    const categories = isQuarterly ? chartData.quarterlyActiveLabels : chartData.monthlyActiveLabels;
    const values = isQuarterly ? chartData.quarterlyActiveReturns : chartData.monthlyActiveReturns;

    const options = {
        chart: {
            type: 'bar',
            height: 300,
            background: 'transparent',
            toolbar: { show: false },
            animations: { enabled: false },
            foreColor: '#8fa0b7'
        },
        series: [{ name: 'Active return', data: values }],
        xaxis: {
            categories,
            labels: { rotate: -45, trim: false, hideOverlappingLabels: true },
            axisBorder: { color: '#263247' },
            axisTicks: { color: '#263247' }
        },
        yaxis: { labels: { formatter: value => value.toFixed(1) + '%' } },
        plotOptions: {
            bar: {
                columnWidth: isQuarterly ? '58%' : '72%',
                colors: {
                    ranges: [
                        { from: -1000, to: -0.000001, color: '#ff6b7a' },
                        { from: 0, to: 1000, color: '#38d996' }
                    ]
                }
            }
        },
        grid: { borderColor: '#263247', strokeDashArray: 3 },
        dataLabels: { enabled: false },
        tooltip: { theme: 'dark', y: { formatter: value => value.toFixed(2) + '%' } },
        legend: { show: false },
        noData: { text: 'No benchmark data' }
    };

    if (activeReturnChart) activeReturnChart.destroy();
    activeReturnChart = new ApexCharts(activeChartEl, options);
    activeReturnChart.render();
}

renderActiveReturnChart('monthly');

document.querySelector('#activeMonthly')?.addEventListener('click', event => {
    document.querySelectorAll('#activeMonthly, #activeQuarterly').forEach(button => button.classList.remove('active'));
    event.currentTarget.classList.add('active');
    renderActiveReturnChart('monthly');
});

document.querySelector('#activeQuarterly')?.addEventListener('click', event => {
    document.querySelectorAll('#activeMonthly, #activeQuarterly').forEach(button => button.classList.remove('active'));
    event.currentTarget.classList.add('active');
    renderActiveReturnChart('quarterly');
});

let activeContributionChart = null;

function formatPctPoints(value, digits = 2) {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) return '—';
    const n = Number(value) * 100;
    return `${n >= 0 ? '+' : ''}${n.toFixed(digits)} pp`;
}

function formatPercentValue(value, digits = 2) {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) return '—';
    const n = Number(value) * 100;
    return `${n >= 0 ? '+' : ''}${n.toFixed(digits)}%`;
}

function formatWeightValue(value, digits = 2) {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) return '—';
    return `${(Number(value) * 100).toFixed(digits)}%`;
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function renderContributorList(elementId, entries, details, positive = true) {
    const el = document.getElementById(elementId);
    if (!el) return;
    const rows = entries.slice(0, 5);
    if (!rows.length) {
        el.innerHTML = '<div class="convention-note">None for this period.</div>';
        return;
    }
    el.innerHTML = rows.map(([name, value]) => {
        const d = details?.[name] || {};
        const diagnostic = d.excess_return_while_held === null || d.excess_return_while_held === undefined
            ? 'Contribution to portfolio active return'
            : `Avg wt ${formatWeightValue(d.average_weight)} · excess return while held ${formatPercentValue(d.excess_return_while_held)}`;
        return `
        <div class="contributor-row">
            <div class="contributor-name">
                <span>${escapeHtml(name)}</span>
                <small>${escapeHtml(diagnostic)}</small>
            </div>
            <strong class="${positive ? 'positive' : 'negative'}">${formatPctPoints(value)}</strong>
        </div>`;
    }).join('');
}

function renderContributionDetail(periodLabel, data, rankedEntries) {
    const body = document.getElementById('contributionDetailBody');
    const periodEl = document.getElementById('contributionDetailPeriod');
    if (periodEl) periodEl.textContent = `${periodLabel} · ranked by absolute active contribution`;
    if (!body) return;

    const details = data.details || {};
    const rows = rankedEntries
        .filter(([name]) => name !== 'Attribution residual' && details[name])
        .slice(0, 12);

    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="7" style="text-align:left;color:#8fa0b7;">No holding-level detail is available for this period.</td></tr>';
        return;
    }

    body.innerHTML = rows.map(([name]) => {
        const d = details[name] || {};
        const active = Number(d.active_contribution);
        const activeClass = Number.isFinite(active) && active < 0 ? 'negative' : 'positive';
        return `
            <tr>
                <td>${escapeHtml(name)}</td>
                <td>${formatWeightValue(d.average_weight)}</td>
                <td>${formatPercentValue(d.holding_return)}</td>
                <td>${formatPercentValue(d.benchmark_while_held)}</td>
                <td class="${Number(d.excess_return_while_held) >= 0 ? 'positive' : 'negative'}">${formatPercentValue(d.excess_return_while_held)}</td>
                <td>${Number.isFinite(Number(d.exposure_days)) ? Number(d.exposure_days).toLocaleString() : '—'}</td>
                <td class="${activeClass}"><strong>${formatPctPoints(d.active_contribution)}</strong></td>
            </tr>`;
    }).join('');
}

function renderContributionPeriod(periodLabel) {
    const data = activeContributionPeriods[periodLabel];
    if (!data) return;

    const effectsEntries = Object.entries(data.effects || {})
        .filter(([name, value]) => name !== 'Attribution residual' && Number.isFinite(Number(value)))
        .sort((a, b) => Math.abs(Number(b[1])) - Math.abs(Number(a[1])));
    const displayEntries = effectsEntries.slice(0, 12).sort((a, b) => Number(a[1]) - Number(b[1]));

    const contributorEntries = Object.entries(data.contributors || {}).sort((a, b) => Number(b[1]) - Number(a[1]));
    const detractorEntries = Object.entries(data.detractors || {}).sort((a, b) => Number(a[1]) - Number(b[1]));
    renderContributorList('topContributors', contributorEntries, data.details || {}, true);
    renderContributorList('topDetractors', detractorEntries, data.details || {}, false);
    renderContributionDetail(periodLabel, data, effectsEntries);

    const summary = document.getElementById('contributionSummary');
    if (summary) {
        summary.textContent = `${periodLabel}: portfolio ${formatPercentValue(data.portfolio_cumulative)}, ${benchmarkName || 'benchmark'} ${formatPercentValue(data.benchmark_cumulative)}, cumulative active return ${formatPctPoints(data.active_cumulative)}. Holding contributions below explain that active-return difference.`;
    }

    if (activeContributionChart) {
        activeContributionChart.destroy();
    }
    const chartEl = document.querySelector('#activeContributionChart');
    if (!chartEl) return;
    activeContributionChart = new ApexCharts(chartEl, {
        chart: { type: 'bar', height: 300, toolbar: { show: false }, background: 'transparent' },
        series: [{ name: 'Contribution to portfolio active return', data: displayEntries.map(([, value]) => Number(value) * 100) }],
        xaxis: {
            categories: displayEntries.map(([name]) => name),
            labels: { style: { colors: '#8fa0b7' }, formatter: value => `${Number(value).toFixed(1)} pp` },
            axisBorder: { color: '#263247' }, axisTicks: { color: '#263247' }
        },
        yaxis: { labels: { style: { colors: '#8fa0b7' } } },
        grid: { borderColor: '#263247', strokeDashArray: 3 },
        plotOptions: { bar: { horizontal: true, borderRadius: 3 } },
        dataLabels: { enabled: false },
        tooltip: {
            theme: 'dark',
            y: { formatter: value => `${value >= 0 ? '+' : ''}${Number(value).toFixed(2)} pp contribution to active return` }
        },
        colors: [({ value }) => Number(value) >= 0 ? '#38d996' : '#ff6b7a'],
        theme: { mode: 'dark' }
    });
    activeContributionChart.render();
}

const contributionButtons = document.querySelectorAll('.contribution-period');
if (contributionButtons.length) {
    contributionButtons.forEach(button => button.addEventListener('click', () => {
        contributionButtons.forEach(b => b.classList.remove('active'));
        button.classList.add('active');
        renderContributionPeriod(button.dataset.period);
    }));
    const initialButton = document.querySelector('.contribution-period.active') || contributionButtons[0];
    if (initialButton) {
        initialButton.classList.add('active');
        renderContributionPeriod(initialButton.dataset.period);
    }
}

function csvCell(value) {
    const text = value === null || value === undefined ? '' : String(value);
    return '"' + text.replaceAll('"', '""') + '"';
}

document.querySelector('#downloadCsv')?.addEventListener('click', () => {
    const headers = [
        'date', 'account_value', 'market_value', 'cash', 'external_flow',
        'daily_twr', 'cumulative_return', 'drawdown',
        'benchmark_daily_return', 'benchmark_cumulative_return'
    ];
    const lines = [headers.map(csvCell).join(',')];

    for (const row of csvRows) {
        lines.push(row.map(csvCell).join(','));
    }

    const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'daily_time_weighted_returns.csv';
    document.body.appendChild(link);
    link.click();
    URL.revokeObjectURL(link.href);
    link.remove();
});
</script>
<?php endif; ?>
</body>
</html>

