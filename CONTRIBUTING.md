# Contributing

## Setup

```bash
composer install
composer test
```

`composer test` runs PHPUnit (webhook, client and spec suites, including the check that the generated types are current) and PHPStan at level max over `src/` and the usage examples in `tests/Typecheck/`.

## Generated files

- `openapi/openapi.yaml` is copied from the platform. Don't edit it here.
- `src/Generated/Types.php` is generated from it: one PHPStan array shape per schema, plus a `<Operation>Response` and `<Operation>Query` type per operation. Don't edit it either.

When the API changes:

```bash
composer spec:sync   # copies ../inbound-app/openapi/openapi.yaml, or $INBOUNDR_SPEC, and regenerates
composer test
```

`tests/SpecTest.php` fails if an operation in the spec has no client method, so a new API route shows up as a failing test until the client covers it.

## Webhook signatures

The signing scheme isn't part of the OpenAPI spec. It is written out at the top of `tests/WebhookTest.php`, and those tests sign independently of the SDK's code. Changing the scheme is a breaking change.

## Releasing

1. Bump `Inboundr::VERSION` in `src/Inboundr.php`.
2. Merge to `main`.
3. Tag `v<version>` and push the tag.

Packagist reads versions from the repository's tags, so the tag push is the release. CI runs the tests on PHP 8.2 to 8.5 and with the lowest allowed dependencies, and checks that the tag matches `Inboundr::VERSION`.
