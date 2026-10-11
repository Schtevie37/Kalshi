# Paper desk

A small PHP and MySQL desk for [Kalshi's demo environment](https://demo.kalshi.co/). Orders use mock funds on the demo exchange. The app refuses every host except the official demo trade API.

You need your own demo account. Create an API key while logged into [demo.kalshi.co](https://demo.kalshi.co/) (Account & security → API Keys). Keys from the live site will not work here, and this desk will not send a key to the live site.

## What it does

- Local accounts, stored in MySQL, with passwords hashed by PHP
- Demo API key id and private key saved encrypted (the key is not shown again)
- Open demo markets, with combo markets left out of the default list
- Order book, yes/no quotes, and market rules
- Limit orders: buy YES, or buy NO (sent on the YES book at `$1` minus your price)
- Cancel resting orders
- Portfolio balance, positions, orders, and fills
- A local journal of orders placed from this desk

## Run it

Install PHP 8.3+ with `curl`, `mbstring`, `sodium`, and `pdo_mysql`, plus MariaDB or MySQL.

```bash
bin/setup.sh
php -S localhost:8080 -t public public/router.php
```

Open http://localhost:8080 and create an account. Then open Demo keys and paste the key id and PEM from the demo site.

`bin/setup.sh` creates the `kalshi_paper` database, a `kalshi` MySQL user, and `config/config.php`. That config file holds the database password and the encryption key, and it is not meant to be committed.

## Checks

```bash
php tests/unit_test.php
```

That checks RSA-PSS and Ed25519 signatures, NO-order price conversion, encryption, and the demo-host lock.

## Demo API

Authenticated requests are signed the way Kalshi documents: `timestamp + METHOD + path`, with the query string left out of the signature. Ed25519 keys sign the message. RSA keys use RSA-PSS with SHA-256. The base URL is `https://external-api.demo.kalshi.co/trade-api/v2`.

Orders use `POST /portfolio/events/orders`. The price on that call is always a YES price. A NO purchase at `$0.40` is sent as an ask at `$0.60`.
