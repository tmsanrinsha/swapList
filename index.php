<?php
require 'vendor/autoload.php';
use Goutte\Client;

$client = new Client();

// 各スワップの値を取得
$crawler = $client->request('GET', "http://fx.hikaku-memo.com/swap/");
$swaps = array();
foreach (array('ask', 'bid') as $orderType) {
    $currencyPairs = array();
    $crawler->filter('#'.$orderType.' thead img')->each(function($crawlerImg) use (&$currencyPairs) {
        $currencyPairs[] = $crawlerImg->attr('alt');
    });
    $crawler->filter('#'.$orderType. ' tbody tr')->each(function($crawlerTr) use (&$swaps, $currencyPairs, $orderType) {
        $trader = '';
        $crawlerTr->filter('td')->each(function($crawlerTd, $i) use (&$swaps, $currencyPairs, &$trader, $orderType) {
            if ($i === 0) {
                $trader = $crawlerTd->text();
            } elseif ($i <= 11) {
                $swap = $crawlerTd->text();
                if ($currencyPairs[$i - 1] === 'ZAR/JPY' && $swap !== '') {
                    $swap /= 10;
                }
                $swaps[$currencyPairs[$i - 1]][$orderType][$trader] = $swap;
            }
        });
    });
}

// 各スプレットの値を取得
$crawler = $client->request('GET', "http://fx.hikaku-memo.com/spread/");
$spreads = array();
$currencyPairs = array();
$crawler->filter('#main > table thead img')->each(function($crawlerImg) use (&$currencyPairs) {
    $currencyPairs[] = $crawlerImg->attr('alt');
});
$crawler->filter('#main > table tbody tr')->each(function($crawlerTr) use (&$spreads, &$currencyPairs) {
    $trader = '';
    $crawlerTr->filter('td')->each(function($crawlerTd, $i) use (&$spreads, &$currencyPairs, &$trader) {
        if ($i === 0) {
            $trader = $crawlerTd->text();
        } elseif ($i <= 11) {
            // スプレットの下限を取り出す
            $spreads[$currencyPairs[$i - 1]][$trader] = current(explode('～', $crawlerTd->text()));
        }
    });
});

// 各通貨ペアのレート(2015-02-21時点)
$rates['JPY/JPY'] =  1;
$rates['USD/JPY'] =  119.071;
$rates['EUR/USD'] =  1.13791;
$rates['GBP/USD'] =  1.53932;
$rates['EUR/JPY'] =  135.492;
$rates['GBP/JPY'] =  183.313;
$rates['AUD/JPY'] =  93.39;
$rates['NZD/JPY'] =  89.582;
$rates['ZAR/JPY'] =  10.23;
$rates['CHF/JPY'] =  126.823;
$rates['CAD/JPY'] =  94.98;
$rates['AUD/USD'] =  0.78428;

foreach ($swaps as $currencyPair => $swaps2) {
    foreach ($swaps2['ask'] as $askTrader => $askSwap) {
        if ($askSwap === '' || $spreads[$currencyPair][$askTrader] === '') {
            continue;
        }
        foreach ($swaps2['bid'] as $bidTrader => $bidSwap) {
            if ($bidSwap === '' || $spreads[$currencyPair][$bidTrader] === '') {
                continue;
            }
            $swapSum = $askSwap + $bidSwap;
            if ($swapSum <= 0) {
                continue;
            }

            // GFTの値は怪しい
            // if ($askTrader === 'GFT' || $bidTrader === 'GFT') {
            //     continue;
            // }

            list($keyCurrency, $settleCurreny) = explode('/', $currencyPair);


            $spreadSum = $spreads[$currencyPair][$askTrader] + $spreads[$currencyPair][$bidTrader];
            // スプレットを円単位に変換
            if ($settleCurreny === 'JPY') {
                $spreadSum = $spreadSum / 100;
            } elseif ($settleCurreny === 'USD') {
                $spreadSum = $spreadSum / 10000 * $rates['USD/JPY'];
            } else {
                error_log("Rate conversion failed!");
            }

            // 1万通貨のスプレット(円)を1万通貨あたりのスワップポイント(円)で割って、何日でペイできるか計算
            $payOffDays = $spreadSum * 10000 / $swapSum;
            if ($payOffDays < 120) {
                // 100万円あたりのスワップポイント = 1通貨あたりのスワップポイント * 100万円で買える通貨数
                $swapDiffPerMillionYen = $swapSum / 10000 * (1000000 / $rates["$keyCurrency/JPY"]);
                $swapTable[] = array($currencyPair, $askTrader, $bidTrader, $askSwap, $bidSwap, $swapSum, $swapDiffPerMillionYen, $payOffDays);
            }
        }
    }
}

usort($swapTable, function($a, $b) {
    return $a[6] < $b[6];
});

echo "通貨ペア\t買い業者\t売り業者\t買いswap\t売りswap\t買い+売りswap\t100万・100万で両建てした時のswap\tspread分を取り戻すまでの日数\n";
foreach ($swapTable as $raw) {
    foreach ($raw as $col) {
        echo "$col\t";
    }
    echo "\n";
}
