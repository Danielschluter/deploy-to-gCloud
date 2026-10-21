<?php

$url = "https://www.sec.gov/files/company_tickers_exchange.json";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_USERAGENT, "MyDomainName.com info@MyDomainName.com");
$response = curl_exec($ch);
curl_close($ch);


$json = json_decode($response, true);

$company_tickers = $json['data'] ?? null;

// Stop before anything is saved: without the list every ticker would be
// stored as N_A, overwriting good tickers already in the table.
if (!is_array($company_tickers)) {
    http_response_code(502);
    exit('Could not load the SEC ticker list (company_tickers_exchange.json)');
}
