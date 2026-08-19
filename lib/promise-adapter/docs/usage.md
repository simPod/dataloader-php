# Promise adapter usage

## Optional requirements

Optional to use Guzzle:

```sh
composer require "guzzlehttp/promises"
```

Optional to use ReactPhp:

```sh
composer require "react/promise"
```

Optional to use Amp v3:

```sh
composer require "amphp/amp"
```

## Supported Adapter

*Guzzle*: `Overblog\PromiseAdapter\Adapter\GuzzleHttpPromiseAdapter`

*ReactPhp*: `Overblog\PromiseAdapter\Adapter\ReactPromiseAdapter`

*Amp v3*: `Overblog\PromiseAdapter\Adapter\AmpFutureAdapter`

To use a custom Promise lib you can implement `Overblog\PromiseAdapter\PromiseAdapterInterface`

Adapters for promises that require event-loop scheduling and do not expose a
`then()` method can implement `Overblog\PromiseAdapter\AsyncPromiseAdapterInterface`.
