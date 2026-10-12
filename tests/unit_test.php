<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Signer.php';
require dirname(__DIR__) . '/src/Pricing.php';
require dirname(__DIR__) . '/src/Vault.php';
require dirname(__DIR__) . '/src/KalshiClient.php';
require dirname(__DIR__) . '/src/Trading.php';
require dirname(__DIR__) . '/src/functions.php';
require dirname(__DIR__) . '/src/Scorecard.php';
require dirname(__DIR__) . '/src/LiveMarket.php';

$failed = 0;

function check(bool $ok, string $label): void
{
    global $failed;
    if ($ok) {
        echo "ok  {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL {$label}\n";
}

$rsaPem = (string) shell_exec('openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 2>/dev/null');
$edPem = (string) shell_exec('openssl genpkey -algorithm ED25519 2>/dev/null');
check(str_contains($rsaPem, 'PRIVATE KEY'), 'generated an RSA key');
check(str_contains($edPem, 'PRIVATE KEY'), 'generated an Ed25519 key');
check(Signer::detectType($rsaPem) === 'rsa', 'detects RSA');
check(Signer::detectType($edPem) === 'ed25519', 'detects Ed25519');

$message = '1703123456789GET/trade-api/v2/portfolio/balance';

$rsaSig = Signer::signMessage($rsaPem, $message);
$rsaKeyPath = tempnam(sys_get_temp_dir(), 'rsa');
$rsaMsgPath = tempnam(sys_get_temp_dir(), 'msg');
$rsaSigPath = tempnam(sys_get_temp_dir(), 'sig');
file_put_contents($rsaKeyPath, $rsaPem);
file_put_contents($rsaMsgPath, $message);
file_put_contents($rsaSigPath, $rsaSig);
$hashPath = tempnam(sys_get_temp_dir(), 'hash');
shell_exec('openssl dgst -sha256 -binary -out ' . escapeshellarg((string) $hashPath) . ' ' . escapeshellarg((string) $rsaMsgPath));
$verify = shell_exec(
    'openssl pkeyutl -verify -inkey ' . escapeshellarg((string) $rsaKeyPath)
    . ' -in ' . escapeshellarg((string) $hashPath)
    . ' -sigfile ' . escapeshellarg((string) $rsaSigPath)
    . ' -pkeyopt rsa_padding_mode:pss -pkeyopt rsa_pss_saltlen:32 -pkeyopt digest:sha256 -pkeyopt rsa_mgf1_md:sha256 2>&1'
);
check(is_string($verify) && str_contains($verify, 'Signature Verified'), 'RSA-PSS signature verifies');

$edSig = Signer::signMessage($edPem, $message);
$edDer = base64_decode((string) preg_replace('/-----BEGIN [^-]+-----|-----END [^-]+-----|\s+/', '', trim($edPem)));
$marker = "\x04\x20";
$pos = strrpos($edDer, $marker);
$seed = substr($edDer, (int) $pos + 2, 32);
$pair = sodium_crypto_sign_seed_keypair($seed);
$public = sodium_crypto_sign_publickey($pair);
check(sodium_crypto_sign_verify_detached($edSig, $message, $public), 'Ed25519 signature verifies');

$withQuery = Signer::sign($edPem, '1703123456789', 'get', '/trade-api/v2/portfolio/orders?limit=5');
$withoutQuery = Signer::sign($edPem, '1703123456789', 'get', '/trade-api/v2/portfolio/orders');
check($withQuery === $withoutQuery, 'query string is excluded from the signed path');

$centMarket = ['price_ranges' => [['start' => '0.0000', 'end' => '1.0000', 'step' => '0.0100']]];
$yes = Pricing::order('yes', '0.55', '2', $centMarket);
check($yes['book_side'] === 'bid' && $yes['api_price'] === '0.5500' && $yes['count'] === '2.00', 'buy YES at 0.55 stays a bid at 0.5500');
$no = Pricing::order('NO', '0.40', '1.5', $centMarket);
check($no['book_side'] === 'ask' && $no['api_price'] === '0.6000' && $no['pay_price'] === '0.4000' && $no['count'] === '1.50', 'buy NO at 0.40 is sent as a YES ask at 0.6000');

$rejected = false;
try {
    Pricing::order('yes', '0.555', '1', $centMarket);
} catch (InvalidArgumentException) {
    $rejected = true;
}
check($rejected, 'off-tick price is rejected');

$appKey = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
[$cipher, $nonce] = Vault::seal($rsaPem, $appKey);
check(Vault::open($cipher, $nonce, $appKey) === $rsaPem, 'private key round-trips through the vault');
$tampered = false;
try {
    Vault::open($cipher, $nonce, base64_encode(random_bytes(32)));
} catch (RuntimeException) {
    $tampered = true;
}
check($tampered, 'a different app key cannot open the vault');

$locked = false;
try {
    new KalshiClient('https://external-api.kalshi.com/trade-api/v2');
} catch (RuntimeException) {
    $locked = true;
}
check($locked, 'production host is rejected');
$demo = new KalshiClient('https://external-api.demo.kalshi.co/trade-api/v2');
check($demo instanceof KalshiClient, 'demo host is accepted');
$withUser = false;
try {
    new KalshiClient('https://user:secret@external-api.demo.kalshi.co/trade-api/v2');
} catch (RuntimeException) {
    $withUser = true;
}
check($withUser, 'credentials in the demo URL are rejected');

$payload = Trading::payload(
    ['book_side' => 'ask', 'outcome' => 'no', 'api_price' => '0.6000', 'pay_price' => '0.4000', 'count' => '1.00'],
    'KXDEMO-TEST',
    'good_till_canceled',
    false,
    '8c35ecb3-328f-4f52-8c7c-0f4b9862f8d1'
);
check($payload['side'] === 'ask' && $payload['price'] === '0.6000' && $payload['post_only'] === false, 'order payload quotes the yes book');

$entry = Scorecard::entry('kxnfltd-26oct11balatl-atlbrobinson7-1', 'YES', '0.40', '10');
check(
    $entry['ticker'] === 'KXNFLTD-26OCT11BALATL-ATLBROBINSON7-1'
        && $entry['outcome'] === 'yes'
        && $entry['pay_price'] === '0.4000'
        && $entry['count'] === '10.00',
    'scorecard entry normalizes a live ticker'
);
$win = Scorecard::settle('yes', '0.4000', '10.00', 'yes');
check($win['won'] && $win['cost'] === '4.0000' && $win['payout'] === '10.0000' && $win['profit'] === '6.0000', 'yes at 0.40 pays 6 when the result is yes');
$loss = Scorecard::settle('yes', '0.4000', '10.00', 'no');
check(!$loss['won'] && $loss['payout'] === '0.0000' && $loss['profit'] === '-4.0000', 'yes at 0.40 loses the cost when the result is no');
$noWin = Scorecard::settle('no', '0.4000', '10.00', 'no');
check($noWin['won'] && $noWin['profit'] === '6.0000', 'no at 0.40 wins when the result is no');
check(Scorecard::marketResult(['result' => '']) === null, 'an empty result stays open');
check(Scorecard::marketResult(['result' => 'YES']) === 'yes', 'a yes result settles');
check(Scorecard::marketResult([]) === null, 'a missing result stays open');
$totals = Scorecard::totals([
    ['outcome' => 'yes', 'pay_price' => '0.4000', 'contract_count' => '10.00', 'market_result' => 'yes'],
    ['outcome' => 'yes', 'pay_price' => '0.4000', 'contract_count' => '10.00', 'market_result' => 'no'],
    ['outcome' => 'yes', 'pay_price' => '0.5000', 'contract_count' => '2.00', 'market_result' => null],
]);
check(
    $totals['count'] === 3
        && $totals['open'] === 1
        && $totals['wins'] === 1
        && $totals['losses'] === 1
        && $totals['success_rate'] === '50.0'
        && $totals['placed'] === '9.0000'
        && $totals['open_stake'] === '1.0000'
        && $totals['payout'] === '10.0000'
        && $totals['profit'] === '2.0000'
        && $totals['average_return'] === '25.0',
    'scorecard totals ignore open stake in success and return'
);
$leadingDot = Scorecard::entry('KXNFLTD-26OCT11BALATL-ATLBROBINSON7-1', 'yes', '.62', '1');
check($leadingDot['pay_price'] === '0.6200' && $leadingDot['count'] === '1.00', 'a price typed as .62 is 0.6200');
$priceRejected = false;
try {
    Scorecard::entry('KXNFLTD-26OCT11BALATL-ATLBROBINSON7-1', 'yes', '1', '1');
} catch (InvalidArgumentException) {
    $priceRejected = true;
}
check($priceRejected, 'a price of one dollar is rejected');

$liveUrl = LiveMarket::marketUrl('KXNFLTD-26OCT11BALATL-ATLBROBINSON7-1');
check(
    $liveUrl === 'https://external-api.kalshi.com/trade-api/v2/markets/KXNFLTD-26OCT11BALATL-ATLBROBINSON7-1',
    'live lookup is one public market URL'
);
$liveSource = (string) file_get_contents(dirname(__DIR__) . '/src/LiveMarket.php');
check(!str_contains($liveSource, 'KALSHI-ACCESS') && !str_contains($liveSource, 'privateKey') && !str_contains($liveSource, 'POST'), 'live lookup sends no key and has no POST');
$badTicker = false;
try {
    LiveMarket::marketUrl('../portfolio/orders');
} catch (InvalidArgumentException) {
    $badTicker = true;
}
check($badTicker, 'live lookup rejects a path that is not a ticker');

$selector = str_repeat('ab', 16);
$verifier = random_bytes(32);
$cookie = $selector . '.' . bin2hex($verifier);
$parsed = parse_remember_token($cookie);
check(
    is_array($parsed) && $parsed['selector'] === $selector && $parsed['verifier'] === $verifier,
    'remember token splits into a selector and verifier'
);
check(parse_remember_token($selector . '.abcd') === null, 'a short remember token is rejected');
check(parse_remember_token($selector . '.' . bin2hex($verifier) . 'ff') === null, 'a long remember token is rejected');
check(iso_time(0) === 'Jan 1, 1970 00:00 UTC', 'unix time stays UTC unless asked');
check(iso_time(0, 'America/New_York') === 'Dec 31, 1969 19:00 ET', 'Eastern time follows New York, including the date');
check(remember_lifetime() === 400 * 24 * 60 * 60, 'remember cookie lasts 400 days');
check(hash_equals(remember_token_hash($verifier), hash('sha256', $verifier)), 'remember token is stored as a hash');
check(!hash_equals(remember_token_hash($verifier), remember_token_hash(random_bytes(32))), 'a different remember verifier does not match');

@unlink((string) $rsaKeyPath);
@unlink((string) $rsaMsgPath);
@unlink((string) $rsaSigPath);
@unlink((string) $hashPath);

if ($failed > 0) {
    fwrite(STDERR, "{$failed} failed\n");
    exit(1);
}
echo "all unit checks passed\n";
