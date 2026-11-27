### Added

- The README: install through the GitHub `vcs` repository, search, the integration API, the bulk loader, "Connect to Instafind", transports, errors, testing, and the stability rules.
- A contract test against a copy of the backend's OpenAPI document (`openapi/instafind-v1.json`, refreshed with `scripts/update-openapi.sh`). It checks that every request the SDK sends is a documented operation with declared parameters, that every operation is covered, and that the response fields the typed objects read are in the schemas.
