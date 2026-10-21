<?php
// Fetches one us-gaap XBRL frame from the SEC API, prints it as table rows and
// upserts it into the VM's PostgreSQL database (earnings_data table).
//   insertedgar.php?q=Revenues&r=2023     (r: 2023, 2023Q1, 2023Q4I, ...)
require __DIR__ . '/includes/auth.php';   // PGClient login required: this page writes to the database
require __DIR__ . '/ciklookup.php';       // defines $company_tickers: [cik, name, ticker, ...] rows

$df = [];

$q = $_GET['q'] ?? '';
$r = $_GET['r'] ?? '';
if (!preg_match('/^[A-Za-z0-9]+$/', $q) || !preg_match('/^\d{4}(Q[1-4]I?)?$/', $r)) {
    http_response_code(400);
    exit('Invalid parameters: q must be a us-gaap tag (e.g. Revenues), r a period (e.g. 2023, 2023Q1, 2023Q4I)');
}

$urlBeg = "https://data.sec.gov/api/xbrl/frames/us-gaap/";
        $urlEnd = "/USD/CY".$r.".json";

        if ($q == "EarningsPerShareDiluted" || $q == "EarningsPerShareBasic") {
            $url = $urlBeg . $q . "/USD-per-shares/CY" . $r . ".json";

            $ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_USERAGENT, "MyDomainName.com info@MyDomainName.com");
$response = curl_exec($ch);
curl_close($ch);

$json = json_decode($response, true);
$data = $json['data'] ?? null;

//echo json_encode($data, JSON_NUMERIC_CHECK);


        } else {

            $url = $urlBeg . $q . $urlEnd;



        $ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_USERAGENT, "MyDomainName.com info@MyDomainName.com");
$response = curl_exec($ch);
curl_close($ch);

$json = json_decode($response, true);
$data = $json['data'] ?? null;
$tag = $json['tag'] ?? $q;
$frame = $json['ccp'] ?? null;

echo $tag;

//echo json_encode($data, JSON_NUMERIC_CHECK);


        }

        if (!is_array($data)) {
            http_response_code(502);
            exit("No data returned by the SEC for $q CY$r");
        }

        $tickerCiks = array_column($company_tickers, 0);

        for ($i = 0; $i < count($data); $i++) {
          $dataRow = $data[$i];
            $row = $data[$i];
            //echo json_encode($row);
            $cik = $row['cik'];
            $index = array_search($cik, $tickerCiks);
            //echo $index . "<br>";

            echo "<tr>";
            if ($index !== false) {
                echo "<td>" . $company_tickers[$index][2] . "</td>";
                $dataRow['ticker'] = $company_tickers[$index][2];
            } else {
                echo "<td>N_A</td>";
                $dataRow['ticker'] = "N_A";
            }
            $dataRow['frame'] = "CY" . $r;
            $dataRow['tag'] = $q;
            $df[] = $dataRow;

            foreach ($row as $col) {
                echo "<td>" . $col . "</td>";
            }
            echo "<td>" . $q . "</td>";
            echo "<td>" . $r . "</td>";
            echo "</tr>";

        }

        echo "</table>";

// Database connection: the VM's PostgreSQL, with the credentials docker-compose
// passes to the php container (DB_HOST, POSTGRES_DB, POSTGRES_USER, POSTGRES_PASSWORD).
$db = pgclient_make_pdo(pgclient_env_connection());

// Create table if it doesn't exist. "end" is reserved in PostgreSQL, so the
// dates are start_date / end_date; NUMERIC keeps values exact.
$db->exec("CREATE TABLE IF NOT EXISTS earnings_data (
    accn TEXT,
    cik INTEGER,
    entityname TEXT,
    loc TEXT,
    start_date DATE,
    end_date DATE,
    val NUMERIC,
    ticker TEXT,
    frame TEXT,
    tag TEXT,
    PRIMARY KEY (cik, frame, tag)
)");

// Insert, or update the existing row for the same (cik, frame, tag)
$stmt = $db->prepare("INSERT INTO earnings_data (accn, cik, entityname, loc, start_date, end_date, val, ticker, frame, tag)
    VALUES (:accn, :cik, :entityname, :loc, :start_date, :end_date, :val, :ticker, :frame, :tag)
    ON CONFLICT (cik, frame, tag) DO UPDATE SET
        accn = EXCLUDED.accn, entityname = EXCLUDED.entityname, loc = EXCLUDED.loc,
        start_date = EXCLUDED.start_date, end_date = EXCLUDED.end_date,
        val = EXCLUDED.val, ticker = EXCLUDED.ticker");

// One transaction for the whole frame: much faster than committing each row
$db->beginTransaction();
foreach ($df as $row) {
    $stmt->execute([
        ':accn'       => $row['accn'] ?? null,
        ':cik'        => $row['cik'],
        ':entityname' => $row['entityName'] ?? null,
        ':loc'        => $row['loc'] ?? null,
        ':start_date' => $row['start'] ?? null,   // instant frames (e.g. CY2023Q4I) have no start
        ':end_date'   => $row['end'] ?? null,
        ':val'        => $row['val'] ?? null,
        ':ticker'     => $row['ticker'],
        ':frame'      => $row['frame'],
        ':tag'        => $row['tag'],
    ]);
}
$db->commit();
?>
